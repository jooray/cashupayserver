"""Real WooCommerce → BareBits checkout, end to end (URL mode).

Every other WordPress test drives the plugin's own admin surface. This one
puts the *actual* third-party BTCPay Greenfield WooCommerce plugin in front of
a standalone, already-configured BareBits payserver and plays the whole
customer journey:

    wire the plugin to the payserver (the exact call barebits_handle_finish
    makes: barebits_ensure_woocommerce_integration)
      -> the webhook is registered on the PAYSERVER over its Greenfield API
      -> a guest places a real order through the WooCommerce Store API, paying
         with the BTCPay gateway
      -> the gateway calls the payserver's Greenfield API and creates a
         Lightning invoice
      -> we pay the bolt11 from a second LND node
      -> the payserver settles and its cron drains the signed InvoiceSettled
         webhook to WooCommerce's wc-api endpoint
      -> the order flips to a paid state.

Nothing here is simulated: the invoice is created by the real plugin, the
webhook signature is produced by BareBits and verified by the real plugin, and
the order status transition is WooCommerce's own `payment_complete()`. The
onboarding UI path that performs the same wiring is covered by the
onboarding-mode modules; this module drives the wiring function directly so
the checkout seam stays testable on its own.
"""
from __future__ import annotations

import json
import time

import pytest
import requests

import uuid

from conftest import SESSION_TMP, _configure
from fixtures.lnd import LndHandle
from fixtures.payserver import start_payserver, stop_payserver
from fixtures.wordpress import WordPressHandle

pytestmark = pytest.mark.wordpress

GATEWAY_ID = "btcpaygf_default"


def _store_api(wp: WordPressHandle, path: str) -> str:
    """A WooCommerce Store API endpoint in the ?rest_route= query form — the
    one URL shape every host serves. The pretty /wp-json route needs working
    rewrites: on the apache hostile shape (AllowOverride None, no .htaccess)
    it plain 404s, which is exactly how WordPress behaves on such hosts —
    they run plain permalinks and WordPress itself emits ?rest_route= URLs.
    The buyer browser journey still exercises whatever URL shape the checkout
    page's own JS uses on a friendly host."""
    return f"{wp.url}/?rest_route=/wc/store/v1{path}"


def _flush_rewrites(wp: WordPressHandle) -> None:
    """WooCommerce's Store API needs pretty permalinks active."""
    wp.wp_cli("rewrite", "structure", "/%postname%/", "--hard")
    wp.wp_cli("rewrite", "flush", "--hard")


def _connect_plugin(wp: WordPressHandle, configured) -> None:
    """Store the connection state URL-mode onboarding would leave behind: the
    plugin knows the payserver's URL, store, and API key."""
    for name, value in {
        "barebits_mode": "url",
        "barebits_server_url": configured.handle.url,
        "barebits_store_id": configured.store_id,
        "barebits_api_key": configured.api_token,
    }.items():
        wp.wp_cli("option", "update", name, value, check=False)


def _ensure_integration(
    wp: WordPressHandle, store_id: str, api_key: str, percent: str = "0"
) -> dict:
    """Drive the one entry point barebits_handle_finish calls: save the
    discount option, then install-if-needed + configure + webhook + enable +
    branding, all unattended. Returns the function's status plus the
    resulting gateway-enabled flag and the BTCPay URL the plugin ended up
    pointed at. The reported title is the FILTERED read (wp-cli eval is not
    wp-admin), i.e. the customer-facing one with the discount suffix."""
    wp.wp_cli("option", "update", "barebits_discount_percent", percent, check=False)
    snippet = f"""
$res = barebits_ensure_woocommerce_integration({store_id!r}, {api_key!r});
$gw = get_option('woocommerce_{GATEWAY_ID}_settings', []);
echo json_encode([
    'res' => $res,
    'enabled' => $gw['enabled'] ?? null,
    'title' => $gw['title'] ?? null,
    'url' => get_option('btcpay_gf_url', ''),
]);
"""
    out = wp.wp_cli("eval", snippet).stdout.strip().splitlines()[-1]
    return json.loads(out)


def _wire(wp: WordPressHandle, configured, percent: str = "0") -> dict:
    """Connect + wire the plugin against the live payserver and require the
    'ready' outcome every test below builds on."""
    _connect_plugin(wp, configured)
    data = _ensure_integration(wp, configured.store_id, configured.api_token, percent)
    assert data["res"]["status"] == "ready", data
    return data


def _place_order(wp: WordPressHandle, product_id: int) -> dict:
    """Drive a real guest checkout through the WooCommerce Store API, selecting
    the BTCPay gateway. Returns the checkout response JSON (order_id, status,
    payment_result)."""
    r = _checkout_response(wp, product_id)
    assert r.status_code in (200, 201), f"checkout failed: {r.status_code} {r.text}"
    return r.json()


BILLING_ADDRESS = {
    "first_name": "Sat", "last_name": "Oshi",
    "address_1": "1 Genesis Block", "city": "Cypherpunk",
    "state": "CA", "postcode": "94016", "country": "US",
    "email": "buyer@example.test", "phone": "5551234567",
}


class _StoreApiSession:
    """A guest Store API session: one requests.Session plus the Nonce +
    Cart-Token header dance that carries the cart across the stateless
    requests. The priming GET issues both; every later response may rotate
    them."""

    def __init__(self, wp: WordPressHandle):
        self.wp = wp
        self.s = requests.Session()
        r = self.s.get(_store_api(wp, "/cart"), timeout=30)
        r.raise_for_status()
        self.nonce = r.headers.get("Nonce") or r.headers.get("X-WC-Store-API-Nonce")
        self.cart_token = r.headers.get("Cart-Token")

    def _headers(self) -> dict:
        h = {}
        if self.nonce:
            h["Nonce"] = self.nonce
        if self.cart_token:
            h["Cart-Token"] = self.cart_token
        return h

    def post(self, path: str, payload: dict, timeout: int = 30) -> requests.Response:
        r = self.s.post(
            _store_api(self.wp, path), json=payload, headers=self._headers(),
            timeout=timeout,
        )
        self.nonce = r.headers.get("Nonce", self.nonce)
        self.cart_token = r.headers.get("Cart-Token", self.cart_token)
        return r

    def add_item(self, product_id: int, quantity: int = 1) -> None:
        r = self.post("/cart/add-item", {"id": product_id, "quantity": quantity})
        assert r.status_code in (200, 201), f"add-item failed: {r.status_code} {r.text}"

    def select_payment_method(self, method: str) -> dict:
        """What the blocks checkout's discount script does on a payment-method
        change: POST the selection through the cart/extensions endpoint, which
        runs the plugin's update callback and returns the recalculated cart."""
        r = self.post(
            "/cart/extensions",
            {"namespace": "barebits-discount", "data": {"payment_method": method}},
        )
        assert r.status_code == 200, f"cart/extensions failed: {r.status_code} {r.text}"
        return r.json()

    def checkout(self, payment_method: str) -> requests.Response:
        return self.post(
            "/checkout",
            {"billing_address": dict(BILLING_ADDRESS), "payment_method": payment_method},
            timeout=60,
        )


def _checkout_response(wp: WordPressHandle, product_id: int) -> requests.Response:
    """The raw Store API checkout exchange behind _place_order, for tests that
    expect the payment step to fail."""
    session = _StoreApiSession(wp)
    session.add_item(product_id)
    return session.checkout(GATEWAY_ID)


def _order_field(wp: WordPressHandle, order_id: int, method: str) -> str:
    """Read a single field off a WooCommerce order via wp-cli."""
    snippet = f"$o = wc_get_order({order_id}); echo $o ? (string)$o->{method} : 'NO_ORDER';"
    return wp.wp_cli("eval", snippet).stdout.strip().splitlines()[-1].strip()


def _await_settled(configured, invoice_id: str) -> None:
    """Poll the invoice on the payserver until it is Settled (the GET drives
    Invoice::pollSingleQuote, which is what flips the state)."""
    url = f"{configured.handle.url}/api/v1/stores/{configured.store_id}/invoices/{invoice_id}"
    auth = {"Authorization": f"token {configured.api_token}"}
    deadline = time.monotonic() + 30
    last = None
    while time.monotonic() < deadline:
        r = requests.get(url, headers=auth, timeout=10)
        r.raise_for_status()
        last = r.json()
        if last.get("status") == "Settled":
            return
        time.sleep(0.5)
    raise AssertionError(
        f"BareBits invoice {invoice_id} never settled; last status "
        f"{(last or {}).get('status')!r}"
    )


def test_woocommerce_stack_installs(woocommerce) -> None:
    """Smoke test: WooCommerce + the BTCPay gateway activate under the SQLite
    fixture and the fixture product exists. Guards the heavier flow below from
    failing for mundane install reasons."""
    wp, info = woocommerce
    active = wp.wp_cli("plugin", "list", "--field=name", "--status=active").stdout.split()
    assert "woocommerce" in active, active
    assert "btcpay-greenfield-for-woocommerce" in active, active
    assert "barebits" in active, active

    product_type = wp.wp_cli(
        "eval", f"echo wc_get_product({info['product_id']})->get_type();"
    ).stdout.strip().splitlines()[-1]
    assert product_type == "simple", product_type


def test_wiring_readies_gateway_and_registers_webhook(woocommerce, configured) -> None:
    """barebits_ensure_woocommerce_integration wires WooCommerce with no manual
    steps: it points the BTCPay gateway at the payserver, registers the webhook
    on the PAYSERVER over its Greenfield API, and flips the gateway to enabled
    so it shows at checkout. The gateway plugin is already active in this
    fixture, so nothing should report as auto-installed."""
    wp, _info = woocommerce

    data = _wire(wp, configured, percent="2")
    assert data["res"]["auto_installed"] is False, (
        "an already-active plugin must not report as auto-installed"
    )
    assert data["enabled"] == "yes", "the BTCPay gateway must be enabled at checkout"
    assert data["url"] == configured.handle.url, data["url"]
    assert "2% discount" in (data["title"] or ""), data["title"]

    # The webhook option holds the shared secret; the payserver lists the same
    # webhook, enabled, registered against WooCommerce's wc-api endpoint.
    webhook_opt = json.loads(
        wp.wp_cli("option", "get", "btcpay_gf_webhook", "--format=json").stdout.strip()
    )
    assert webhook_opt["url"] == f"{wp.url}/?wc-api={GATEWAY_ID}"
    assert webhook_opt.get("secret"), webhook_opt
    listed = requests.get(
        f"{configured.handle.url}/api/v1/stores/{configured.store_id}/webhooks",
        headers={"Authorization": f"token {configured.api_token}"},
        timeout=30,
    ).json()
    ours = [h for h in listed if h["id"] == webhook_opt["id"]]
    assert ours and ours[0]["enabled"] is True, listed


def test_discount_fee_follows_payment_method_selection(woocommerce, configured) -> None:
    """The Bitcoin discount, headlessly, exactly as the blocks checkout drives
    it: selecting the BareBits gateway through the cart/extensions endpoint
    adds the negative fee to the recalculated cart, and selecting any other
    method removes it. Store API money fields are minor-unit strings — with
    the shop's 8-decimal BTC config a 0.00003000 cart reads as \"3000\"."""
    wp, info = woocommerce
    _wire(wp, configured, percent="2.5")
    wp.wp_cli("option", "update", "woocommerce_cod_settings", '{"enabled":"yes","enable_for_virtual":"yes"}',
              "--format=json", check=False)

    session = _StoreApiSession(wp)
    session.add_item(info["product_id"], quantity=2)

    cart = session.select_payment_method(GATEWAY_ID)
    fees = cart.get("fees", [])
    assert [f["name"] for f in fees] == ["Bitcoin discount (2.5%)"], fees
    assert fees[0]["totals"]["total"] == "-75", fees[0]["totals"]
    assert cart["totals"]["total_price"] == "2925", cart["totals"]

    # Switching away removes the fee on the very next recalculation.
    cart = session.select_payment_method("cod")
    assert cart.get("fees", []) == [], cart.get("fees")
    assert cart["totals"]["total_price"] == "3000", cart["totals"]


def test_checkout_totals_match_submitted_payment_method(woocommerce, configured) -> None:
    """The money is right at place-order time in both directions, whatever
    state the extension sync left the session in:

    - paying with BareBits WITHOUT any prior selection sync (a blocked or
      racing script) still yields a discounted order, and the invoice the
      gateway creates on the payserver carries the discounted amount;
    - paying with another method AFTER the cart was discounted strips the
      fee, so no card/COD order ever leaves with the Bitcoin discount."""
    wp, info = woocommerce
    _wire(wp, configured, percent="2.5")
    wp.wp_cli("option", "update", "woocommerce_cod_settings", '{"enabled":"yes","enable_for_virtual":"yes"}',
              "--format=json", check=False)

    # --- BareBits order, session never told about the selection ---
    session = _StoreApiSession(wp)
    session.add_item(info["product_id"], quantity=2)
    r = session.checkout(GATEWAY_ID)
    assert r.status_code in (200, 201), f"checkout failed: {r.status_code} {r.text}"
    checkout = r.json()

    # The checkout response carries no totals; the order and the invoice are
    # the authoritative record of what was charged.
    order_id = checkout["order_id"]
    snippet = (
        f"$o = wc_get_order({order_id});"
        "echo json_encode(['total' => $o->get_total(),"
        " 'fees' => array_values(array_map(fn($f) => ['name' => $f->get_name(),"
        " 'total' => $f->get_total()], $o->get_fees()))]);"
    )
    order = json.loads(wp.wp_cli("eval", snippet).stdout.strip().splitlines()[-1])
    assert float(order["total"]) == pytest.approx(0.00002925), order
    assert [f["name"] for f in order["fees"]] == ["Bitcoin discount (2.5%)"], order
    assert float(order["fees"][0]["total"]) == pytest.approx(-0.00000075), order

    # The gateway invoiced the payserver for the DISCOUNTED total.
    invoice_id = _order_field(wp, order_id, "get_meta('BTCPay_id')")
    assert invoice_id and invoice_id != "NO_ORDER", checkout
    inv = requests.get(
        f"{configured.handle.url}/api/v1/stores/{configured.store_id}/invoices/{invoice_id}",
        headers={"Authorization": f"token {configured.api_token}"},
        timeout=15,
    )
    inv.raise_for_status()
    assert float(inv.json()["amount"]) == pytest.approx(0.00002925), inv.json()

    # --- COD order placed from a cart the sync had already discounted ---
    session = _StoreApiSession(wp)
    session.add_item(info["product_id"], quantity=2)
    cart = session.select_payment_method(GATEWAY_ID)
    assert cart["totals"]["total_price"] == "2925", cart["totals"]
    r = session.checkout("cod")
    assert r.status_code in (200, 201), f"checkout failed: {r.status_code} {r.text}"
    checkout = r.json()
    snippet = (
        f"$o = wc_get_order({checkout['order_id']});"
        "echo json_encode(['total' => $o->get_total(), 'fees' => count($o->get_fees())]);"
    )
    order = json.loads(wp.wp_cli("eval", snippet).stdout.strip().splitlines()[-1])
    assert float(order["total"]) == pytest.approx(0.00003000), (
        f"a non-BareBits order must never keep the Bitcoin discount: {order}"
    )
    assert order["fees"] == 0, order


def test_wiring_never_clobbers_a_real_btcpay_server(woocommerce) -> None:
    """If a merchant already points the BTCPay plugin at a real BTCPay Server,
    the wiring must refuse to overwrite it rather than silently hijack their
    payments — and a consent recorded for a *different* server must not unlock
    this one either. The refusal happens before any server contact, so a dead
    barebits_server_url suffices."""
    wp, _info = woocommerce
    # Verified writes (read-back with retry): a lock-lost option write here is
    # exactly what used to make this test flake — the guard under test would
    # see an empty btcpay_gf_url, hijack, and fail webhook registration.
    wp.set_option("barebits_server_url", "http://127.0.0.1:1/nonexistent")

    real = "https://btcpay.example.com"
    wp.set_option("btcpay_gf_url", real)

    data = _ensure_integration(wp, "store_x", "key_x")
    assert data["res"]["status"] == "existing_btcpay", data
    assert data["url"] == real, "the real BTCPay URL must be left untouched"

    wp.set_option("barebits_btcpay_override_consent", "https://other.example.com")
    data = _ensure_integration(wp, "store_x", "key_x")
    assert data["res"]["status"] == "existing_btcpay", data
    assert data["url"] == real, "consent for another server must not authorize this takeover"


def test_consented_override_replaces_existing_btcpay_config(woocommerce, configured) -> None:
    """The consented takeover the onboarding flow promises: with consent
    recorded for exactly the configured server, the wiring replaces the whole
    BTCPay plugin configuration — connection, gateway settings, order states,
    and unmanaged globals alike — with BareBits defaults, then consumes the
    consent so a later reconnection to a real server warns again."""
    wp, _info = woocommerce
    _connect_plugin(wp, configured)

    real = "https://btcpay.example.com"
    seed = f"""
update_option('btcpay_gf_url', {real!r});
update_option('btcpay_gf_api_key', 'old-server-key');
update_option('btcpay_gf_store_id', 'old-store');
update_option('btcpay_gf_transaction_speed', 'high');
update_option('btcpay_gf_order_states', ['Expired' => 'wc-on-hold']);
update_option('woocommerce_{GATEWAY_ID}_settings',
    ['enabled' => 'no', 'title' => 'My custom BTCPay title']);
// The merchant ticks the consent box; barebits_handle_finish records it for
// exactly the URL being replaced.
barebits_record_btcpay_override_consent();
"""
    wp.wp_cli("eval", seed)

    data = _ensure_integration(wp, configured.store_id, configured.api_token)
    assert data["res"]["status"] == "ready", data
    assert data["res"]["replaced_url"] == real, data
    assert data["url"] == configured.handle.url, data["url"]
    assert data["enabled"] == "yes", "the gateway must come out enabled at checkout"

    # This eval is itself part of the test: it boots WordPress (and the BTCPay
    # gateway plugin) again after the takeover. When the reset wiped the
    # plugin's internal btcpay_gf_version marker, this boot re-ran the plugin's
    # version migrations, whose webhook migration makes a blocking API call to
    # the configured server — and the recursive self-requests deadlocked the
    # whole install. So merely returning here proves the wipe spared the
    # plugin's bookkeeping.
    after_snippet = f"""
echo json_encode([
    'title' => get_option('woocommerce_{GATEWAY_ID}_settings')['title'] ?? null,
    'expired' => get_option('btcpay_gf_order_states')['Expired'] ?? null,
    'speed' => get_option('btcpay_gf_transaction_speed', 'GONE'),
    'api_key' => get_option('btcpay_gf_api_key', ''),
    'consent' => get_option('barebits_btcpay_override_consent', 'GONE'),
    'version' => get_option('btcpay_gf_version', 'GONE'),
]);
"""
    after = json.loads(wp.wp_cli("eval", after_snippet).stdout.strip().splitlines()[-1])
    assert after["version"] != "GONE", (
        "the plugin's internal version marker must survive the wipe — deleting "
        "it re-runs boot-time migrations that self-request this site until the "
        "PHP workers deadlock"
    )
    assert after["title"].startswith("BareBits"), (
        f"the custom gateway title must be reset to the BareBits default, got {after['title']!r}"
    )
    assert after["expired"] == "wc-failed", (
        "the custom order-state mapping must be reset to the BareBits default"
    )
    assert after["speed"] == "GONE", (
        "unmanaged btcpay_gf_* globals from the old server must be wiped"
    )
    assert after["api_key"] == configured.api_token, (
        "the API key must be ours, not the old server's"
    )
    assert after["consent"] == "GONE", "consent is single-use and must be consumed"


@pytest.fixture
def configured_multiworker(mint, backup_mint):
    """A configured payserver whose php -S runs multiple workers, like the
    php-fpm pool any real host provides. Required for the settlement test:
    the payserver's cron POSTs the webhook to WooCommerce, whose BTCPay
    handler synchronously calls BACK into the payserver API — on the default
    single-worker fixture that nests into a deadlock until the webhook
    sender's 10s timeout, recording the delivery as failed."""
    workdir = SESSION_TMP / f"payserver-mw-{uuid.uuid4().hex[:8]}"
    handle = start_payserver(workdir, extra_env={"PHP_CLI_SERVER_WORKERS": "6"})
    try:
        yield _configure(handle, mint, backup_mint)
    finally:
        stop_payserver(handle)


def test_woocommerce_order_settles_via_btcpay_gateway(
    woocommerce,
    configured_multiworker,
    lnd_payer: LndHandle,
) -> None:
    configured = configured_multiworker
    wp, info = woocommerce
    _flush_rewrites(wp)
    _wire(wp, configured)

    # --- customer places the order; the gateway creates a payserver invoice ---
    checkout = _place_order(wp, info["product_id"])
    order_id = checkout["order_id"]
    assert checkout.get("payment_result", {}).get("payment_status") == "success", checkout

    invoice_id = _order_field(wp, order_id, "get_meta('BTCPay_id')")
    assert invoice_id and invoice_id != "NO_ORDER", (
        f"gateway did not store a BareBits invoice id: {checkout}"
    )

    # --- fetch the bolt11 the gateway had the payserver generate, and pay it ---
    api = f"{configured.handle.url}/api/v1"
    auth = {"Authorization": f"token {configured.api_token}"}
    inv = requests.get(
        f"{api}/stores/{configured.store_id}/invoices/{invoice_id}",
        headers=auth,
        timeout=15,
    )
    inv.raise_for_status()
    bolt11 = inv.json()["checkout"]["paymentMethods"]["BTC-LightningNetwork"]["destination"]
    assert bolt11.lower().startswith("lnbcrt"), bolt11

    pay = lnd_payer.pay_invoice_sync(bolt11, timeout=30)
    assert not pay.get("payment_error"), pay

    # --- let the payserver notice settlement, then drain its webhook outbox so
    #     the signed InvoiceSettled reaches WooCommerce's wc-api endpoint ---
    _await_settled(configured, invoice_id)

    # Delivery is an async outbox: the payserver's own cron endpoint drains it
    # (the same trigger_cron the standalone webhook tests use). The payserver
    # fixture allows private endpoints, so the POST back to the loopback
    # WooCommerce goes through.
    deadline = time.monotonic() + 30
    status = None
    while time.monotonic() < deadline:
        # Draining is idempotent; run it each tick until the order flips.
        r = configured.handle.trigger_cron()
        assert r.status_code == 200, f"cron drain refused: {r.status_code} {r.text[:200]}"
        status = _order_field(wp, order_id, "get_status()")
        if status in ("processing", "completed"):
            break
        time.sleep(1)

    assert status in ("processing", "completed"), (
        f"order never reached a paid state (last status {status!r}); "
        f"the BTCPay plugin should have run payment_complete() on the signed InvoiceSettled webhook"
    )

    # Corroborate from the payserver's side: the InvoiceSettled delivery is
    # recorded as accepted (HTTP 2xx). The plugin wp_die()s on a bad signature
    # *before* touching the order, so a 200 here is also proof the HMAC
    # verified.
    with configured.handle.db() as db:
        row = db.execute(
            "SELECT status, status_code FROM webhook_deliveries "
            "WHERE invoice_id = ? AND event_type = 'InvoiceSettled'",
            (invoice_id,),
        ).fetchone()
    assert row is not None, "no InvoiceSettled delivery was recorded"
    assert row["status"] == "delivered" and 200 <= row["status_code"] < 300, dict(row)


def test_failed_invoice_creation_returns_clean_error_not_500(woocommerce) -> None:
    """When invoice creation on the payserver fails, the stock Greenfield
    gateway's process_payment() implicitly returns null and WooCommerce's Store
    API checkout fatals with an HTTP 500 (array_merge(..., null) in
    StoreApi\\Legacy). The barebits plugin swaps in a guarded gateway subclass
    (wordpress/gateway-guard.php) that turns the null into a catchable
    exception, so the shopper sees a clean payment error instead.

    The failure is provoked by pointing the gateway at an unreachable server —
    the same null-returning shape as any server-side reject (401 after a
    re-pairing, validation error, payserver 5xx) — so no live payserver is
    needed here."""
    wp, info = woocommerce
    _flush_rewrites(wp)

    # Wire the gateway options directly at a dead address and enable it (the
    # webhook option is irrelevant to process_payment).
    wp.wp_cli(
        "eval",
        "update_option('btcpay_gf_url', 'http://127.0.0.1:1');"
        "update_option('btcpay_gf_api_key', 'broken-key');"
        "update_option('btcpay_gf_store_id', 'store_broken');"
        "barebits_enable_btcpay_gateway();",
    )

    # The guard subclass must be the gateway WooCommerce actually registered.
    snippet = """
foreach (WC()->payment_gateways()->payment_gateways() as $gw) {
    if ($gw->id === 'btcpaygf_default') { echo get_class($gw); }
}
"""
    cls = wp.wp_cli("eval", snippet).stdout.strip().splitlines()[-1].strip()
    assert cls == "Barebits_Guarded_BTCPay_Gateway", (
        f"the guarded subclass must replace the stock gateway, got {cls!r}"
    )

    r = _checkout_response(wp, info["product_id"])
    assert r.status_code == 400, (
        f"expected a clean payment error, got {r.status_code}: {r.text[:300]}"
    )
    body = r.json()
    assert body.get("code") == "woocommerce_rest_checkout_process_payment_error", body
    assert "payment could not be started" in body.get("message", "").lower(), body
