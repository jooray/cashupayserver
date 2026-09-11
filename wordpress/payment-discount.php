<?php
/**
 * BareBits plugin — the Bitcoin checkout discount.
 *
 * Replaces the third-party ELEX "Discount Per Payment Method" plugin with
 * first-party code, closing the gap that motivated the swap: ELEX only
 * refreshed totals on the classic (shortcode) checkout, so the blocks-based
 * checkout never showed the discount. This module applies a percentage
 * discount (a negative, non-taxable cart fee — the same mechanism ELEX used)
 * whenever the customer pays through the BareBits gateway, on both checkouts:
 *
 *   - Classic: a small script triggers update_checkout when the payment
 *     method changes (WooCommerce core only recalculates on address edits);
 *     the fee hook reads the session's chosen_payment_method as before.
 *   - Blocks: a script watches the wc/store/payment data store and pushes the
 *     active method into the session through the Store API's
 *     cart/extensions endpoint (extensionCartUpdate), which recalculates the
 *     cart server-side and live-updates the totals panel. A place-order guard
 *     on the checkout route re-syncs the fee from the submitted payment
 *     method, so a lost or racing extension call can never produce an order
 *     whose total disagrees with how it is actually being paid.
 *
 * The merchant's percentage lives in the barebits_discount_percent option
 * (0–100, up to two decimals, stored as a normalized string). It is editable
 * from the BareBits → Connection page and from the gateway's own WooCommerce
 * settings form (one shared option, injected as an extra field there), and it
 * is advertised in the gateway title as a runtime suffix — the stored title
 * is never rewritten, so a merchant's custom title survives every percent
 * change and always shows the current number. License: GPLv2 or later.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** The WooCommerce gateway the discount applies to (see gateway-guard.php on
 *  why the BareBits checkout keeps the BTCPay plugin's stock gateway id). */
const BAREBITS_DISCOUNT_GATEWAY_ID = 'btcpaygf_default';

/** Store API namespace the blocks checkout script syncs through. */
const BAREBITS_DISCOUNT_STORE_API_NAMESPACE = 'barebits-discount';

/** Base name of the discount's cart-fee row (the percent is appended). */
const BAREBITS_DISCOUNT_FEE_NAME = 'Bitcoin discount';

// ---------------------------------------------------------------------------
// Pure helpers (no WordPress calls, unit-tested by tests/php)
// ---------------------------------------------------------------------------

/**
 * Parse the merchant's discount answer: percent, 0-100, up to two decimal
 * places. Null means the value is unusable and the form should re-render
 * with an error rather than saving anything. An empty submit means "no
 * discount", not an error.
 *
 * Historical note: this used to accept whole numbers only, because the ELEX
 * plugin that applied the discount rendered a step-1 number input a
 * fractional value would have trapped the merchant in. The discount is
 * first-party now, so fractions are allowed.
 */
function barebits_parse_discount_percent(string $raw): ?float {
    $raw = trim($raw);
    if ($raw === '') {
        return 0.0;
    }
    if (!preg_match('/^[0-9]{1,3}(\.[0-9]{1,2})?$/', $raw)) {
        return null;
    }
    $value = (float)$raw;
    return $value <= 100 ? $value : null;
}

/**
 * Canonical string form of a parsed percent: trailing zeros trimmed, so
 * titles and labels read "5% " and "2.5%", never "5.00%".
 */
function barebits_format_discount_percent(float $percent): string {
    $formatted = number_format($percent, 2, '.', '');
    return rtrim(rtrim($formatted, '0'), '.');
}

/**
 * The discount amount for a given base (sum of the cart items' pre-coupon,
 * tax-exclusive line subtotals — the same base ELEX computed from price ×
 * quantity), rounded to the shop's price precision.
 */
function barebits_discount_amount(float $itemsSubtotal, float $percent, int $decimals): float {
    if ($itemsSubtotal <= 0 || $percent <= 0) {
        return 0.0;
    }
    return round($itemsSubtotal * $percent / 100, $decimals);
}

/** The fee row's display name, e.g. "Bitcoin discount (2.5%)". */
function barebits_discount_fee_label(float $percent): string {
    return BAREBITS_DISCOUNT_FEE_NAME . ' (' . barebits_format_discount_percent($percent) . '%)';
}

/**
 * The gateway title as customers should see it: the stored title plus a
 * "(X% discount)" suffix reflecting the CURRENT percent. Pure on purpose —
 * the option filter below is the live wrapper.
 *
 * A title that already talks about a discount is left alone: it means the
 * merchant wrote their own advertisement (or a pre-split install baked one
 * in), and "5% discount (3% discount)" must never render.
 */
function barebits_discount_title(string $title, float $percent): string {
    $title = trim($title);
    if ($title === '' || $percent <= 0) {
        return $title;
    }
    if (stripos($title, 'discount') !== false) {
        return $title;
    }
    return $title . ' (' . barebits_format_discount_percent($percent) . '% discount)';
}

/**
 * Undo barebits_discount_title: drop a trailing "(X% discount)" suffix,
 * whatever number it advertises (the percent may have changed between the
 * read that produced it and the write). Pure; the write-side option filter
 * below is the live wrapper.
 */
function barebits_strip_discount_title_suffix(string $title): string {
    return preg_replace('/\s*\([0-9]+(?:\.[0-9]+)?% discount\)$/', '', trim($title));
}

// ---------------------------------------------------------------------------
// Option access
// ---------------------------------------------------------------------------

/** The merchant's configured discount percent (0 when unset/invalid). */
function barebits_discount_percent(): float {
    $stored = get_option('barebits_discount_percent', 0);
    $parsed = barebits_parse_discount_percent((string)$stored);
    return $parsed === null ? 0.0 : $parsed;
}

/** Persist a validated percent in its canonical string form. */
function barebits_save_discount_percent(float $percent): void {
    update_option('barebits_discount_percent', barebits_format_discount_percent($percent));
}

// ---------------------------------------------------------------------------
// The gateway title suffix
// ---------------------------------------------------------------------------

/**
 * Append the current discount to the gateway title at read time. One filter
 * covers every customer-facing surface: the classic checkout's get_title()
 * and the blocks integration's get_payment_method_data() both load the
 * gateway's settings from this option.
 *
 * Skipped for non-AJAX admin reads so the suffix can never leak into a
 * stored value: the gateway's own settings form renders the title field from
 * this option and writes it straight back on save. Classic checkout's totals
 * refresh rides admin-ajax.php (is_admin() is true there), hence the
 * wp_doing_ajax() carve-out.
 */
function barebits_filter_gateway_settings($settings) {
    if (!is_array($settings) || empty($settings['title'])) {
        return $settings;
    }
    if (is_admin() && !wp_doing_ajax()) {
        return $settings;
    }
    $settings['title'] = barebits_discount_title((string)$settings['title'], barebits_discount_percent());
    return $settings;
}
add_filter('option_woocommerce_' . BAREBITS_DISCOUNT_GATEWAY_ID . '_settings', 'barebits_filter_gateway_settings');

/**
 * Write-side safety net: strip the runtime suffix from the title before the
 * option is persisted. WooCommerce itself read-modify-writes this option in
 * contexts where the read filter is active — WC_Settings_API::update_option
 * behind the Payments-list enable toggle (admin-AJAX) and the REST
 * payment_gateways controller both load the FILTERED settings and save the
 * whole array back — and without this, any such save would bake the
 * then-current percent into the stored title.
 */
function barebits_strip_gateway_settings_on_write($settings) {
    if (is_array($settings) && !empty($settings['title'])) {
        $settings['title'] = barebits_strip_discount_title_suffix((string)$settings['title']);
    }
    return $settings;
}
add_filter('pre_update_option_woocommerce_' . BAREBITS_DISCOUNT_GATEWAY_ID . '_settings', 'barebits_strip_gateway_settings_on_write');

/**
 * The gateway's settings as stored, bypassing the title suffix. Every
 * read-modify-write of the option (the branding writer first among them)
 * must go through this, or a read in a filtered context (WP-CLI, cron)
 * would bake the runtime suffix into the stored title.
 */
function barebits_gateway_stored_settings(): array {
    $optionKey = 'woocommerce_' . BAREBITS_DISCOUNT_GATEWAY_ID . '_settings';
    remove_filter('option_' . $optionKey, 'barebits_filter_gateway_settings');
    $settings = get_option($optionKey, []);
    add_filter('option_' . $optionKey, 'barebits_filter_gateway_settings');
    return is_array($settings) ? $settings : [];
}

// ---------------------------------------------------------------------------
// The discount itself: a negative cart fee
// ---------------------------------------------------------------------------

/**
 * Add the discount as a negative, non-taxable fee when the session's chosen
 * payment method is the BareBits gateway — the exact mechanism (hook,
 * session key, fee shape, admin/cart-page guards) the ELEX plugin used, so
 * checkout behaviour is unchanged for classic-checkout shops.
 */
function barebits_add_discount_cart_fee($cart): void {
    if (is_admin() && !wp_doing_ajax()) {
        return;
    }
    if (!$cart instanceof WC_Cart || $cart->is_empty()) {
        return;
    }
    if (function_exists('is_cart') && is_cart()) {
        return;
    }
    $percent = barebits_discount_percent();
    if ($percent <= 0) {
        return;
    }
    $session = function_exists('WC') && WC()->session ? WC()->session : null;
    if (!$session || (string)$session->get('chosen_payment_method') !== BAREBITS_DISCOUNT_GATEWAY_ID) {
        return;
    }

    $base = 0.0;
    foreach ($cart->get_cart() as $item) {
        $base += (float)($item['line_subtotal'] ?? 0);
    }
    $amount = barebits_discount_amount($base, $percent, wc_get_price_decimals());
    if ($amount <= 0) {
        return;
    }

    $label = barebits_discount_fee_label($percent);
    foreach ($cart->get_fees() as $fee) {
        if ($fee->name === $label) {
            return;
        }
    }
    $cart->add_fee($label, -$amount, false);
}
add_action('woocommerce_cart_calculate_fees', 'barebits_add_discount_cart_fee', 20);

// ---------------------------------------------------------------------------
// Blocks checkout: session sync + place-order guard
// ---------------------------------------------------------------------------

/**
 * Store API update callback: the blocks checkout script calls
 * extensionCartUpdate with the active payment method whenever selecting or
 * leaving the BareBits gateway. Recording it as chosen_payment_method makes
 * the fee hook above apply on the recalculation the extensions endpoint runs
 * right after this callback — the same session contract classic checkout's
 * update_order_review AJAX has always maintained.
 */
function barebits_register_store_api_callback(): void {
    if (!function_exists('woocommerce_store_api_register_update_callback')) {
        return;
    }
    woocommerce_store_api_register_update_callback([
        'namespace' => BAREBITS_DISCOUNT_STORE_API_NAMESPACE,
        'callback' => function ($data) {
            $method = is_array($data) ? (string)($data['payment_method'] ?? '') : '';
            $method = substr(sanitize_text_field($method), 0, 100);
            if (function_exists('WC') && WC()->session) {
                WC()->session->set('chosen_payment_method', $method);
            }
        },
    ]);
}
add_action('woocommerce_blocks_loaded', 'barebits_register_store_api_callback');

/**
 * Place-order guard for the blocks checkout: make the order's discount fee
 * agree with the payment method actually submitted, whatever the session
 * said. The extension sync above is a browser-side courtesy — it can be
 * lost (blocked script, dropped request) or still in flight when the
 * customer hits Place Order, and either way the money must be right: no
 * discount on a card order, the full discount on a BareBits order.
 *
 * Runs on woocommerce_store_api_checkout_update_order_from_request, after
 * the draft order was rebuilt from the cart and before payment (the BTCPay
 * gateway creates its invoice from $order->get_total() during payment, so a
 * correction here flows into the invoice amount). Classic checkout needs no
 * counterpart: WC_Checkout::process_checkout() writes the posted method into
 * the session and recalculates the cart before creating the order.
 */
function barebits_blocks_sync_order_discount($order, $request): void {
    if (!$order instanceof WC_Abstract_Order) {
        return;
    }
    $method = (string)($request['payment_method'] ?? '');
    if (function_exists('WC') && WC()->session
            && (string)WC()->session->get('chosen_payment_method') !== $method) {
        WC()->session->set('chosen_payment_method', $method);
    }

    $decimals = wc_get_price_decimals();
    $percent = barebits_discount_percent();
    $expected = 0.0;
    if ($percent > 0 && $method === BAREBITS_DISCOUNT_GATEWAY_ID) {
        $base = 0.0;
        foreach ($order->get_items() as $item) {
            $base += (float)$item->get_subtotal();
        }
        $expected = barebits_discount_amount($base, $percent, $decimals);
    }

    $present = 0.0;
    $ours = [];
    foreach ($order->get_items('fee') as $itemId => $fee) {
        if (strpos($fee->get_name(), BAREBITS_DISCOUNT_FEE_NAME) === 0) {
            $ours[$itemId] = $fee;
            $present += -(float)$fee->get_total();
        }
    }
    // Compare at the shop's own precision; both sides are already rounded.
    if (count($ours) <= 1 && abs($present - $expected) < 0.5 * pow(10, -$decimals)) {
        return;
    }

    foreach (array_keys($ours) as $itemId) {
        $order->remove_item($itemId);
    }
    if ($expected > 0) {
        // Plain-decimal string on purpose: a bare (string) cast renders
        // BTC-scale floats in scientific notation ("-7.5E-7"), and
        // wc_format_decimal strips the exponent marker during hydration —
        // the same mangle the HPOS/SQLite pin guards against.
        $amount = number_format(-$expected, $decimals, '.', '');
        $fee = new WC_Order_Item_Fee();
        $fee->set_name(barebits_discount_fee_label($percent));
        $fee->set_tax_status('none');
        $fee->set_amount($amount);
        $fee->set_total($amount);
        $order->add_item($fee);
    }
    $order->calculate_totals();
}
add_action('woocommerce_store_api_checkout_update_order_from_request', 'barebits_blocks_sync_order_discount', 10, 2);

// ---------------------------------------------------------------------------
// Checkout scripts
// ---------------------------------------------------------------------------

/**
 * Ship the per-checkout helper script. Classic gets the update_checkout
 * trigger ELEX used to ship; blocks gets the payment-store watcher. Neither
 * is needed when no discount is configured, and the order-pay / thank-you
 * endpoints (is_checkout() is true there too) have no payment-method-driven
 * totals to keep fresh.
 */
function barebits_enqueue_discount_scripts(): void {
    if (!function_exists('is_checkout') || !is_checkout()) {
        return;
    }
    if (function_exists('is_wc_endpoint_url')
            && (is_wc_endpoint_url('order-pay') || is_wc_endpoint_url('order-received'))) {
        return;
    }
    if (barebits_discount_percent() <= 0) {
        return;
    }

    $baseUrl = plugin_dir_url(BAREBITS_PLUGIN_FILE) . 'assets/js/';
    if (has_block('woocommerce/checkout')) {
        $file = BAREBITS_PLUGIN_DIR . '/assets/js/discount-blocks.js';
        wp_enqueue_script(
            'barebits-discount-blocks',
            $baseUrl . 'discount-blocks.js',
            ['wp-data', 'wc-blocks-checkout'],
            (string)filemtime($file),
            true
        );
        $chosen = function_exists('WC') && WC()->session
            ? (string)WC()->session->get('chosen_payment_method')
            : '';
        wp_add_inline_script(
            'barebits-discount-blocks',
            'window.barebitsDiscountConfig = ' . wp_json_encode([
                'gatewayId' => BAREBITS_DISCOUNT_GATEWAY_ID,
                'namespace' => BAREBITS_DISCOUNT_STORE_API_NAMESPACE,
                // Whether the server-side session already reflects the
                // gateway being selected — the script only syncs on change.
                'serverThinksSelected' => $chosen === BAREBITS_DISCOUNT_GATEWAY_ID,
            ]) . ';',
            'before'
        );
    } else {
        $file = BAREBITS_PLUGIN_DIR . '/assets/js/discount-classic.js';
        wp_enqueue_script(
            'barebits-discount-classic',
            $baseUrl . 'discount-classic.js',
            ['jquery'],
            (string)filemtime($file),
            true
        );
    }
}
add_action('wp_enqueue_scripts', 'barebits_enqueue_discount_scripts');

// ---------------------------------------------------------------------------
// Settings: Connection page section + gateway settings form field
// ---------------------------------------------------------------------------

/**
 * The discount form on the BareBits → Connection page (rendered by
 * admin-menu.php). Same option, same validation as everywhere else.
 */
function barebits_render_discount_settings(): void {
    $current = barebits_format_discount_percent(barebits_discount_percent());
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="barebits-section">
        <?php wp_nonce_field('barebits_save_discount'); ?>
        <input type="hidden" name="action" value="barebits_save_discount">
        <h2 class="barebits-subheading">Bitcoin discount</h2>
        <p>
            <label for="barebits-discount-setting">Discount for paying with Bitcoin:</label>
            <input type="number" min="0" max="100" step="0.01" id="barebits-discount-setting"
                   name="barebits_discount_percent" value="<?php echo esc_attr($current); ?>" class="barebits-percent-field"> %
        </p>
        <p class="description">0 = no discount. Applied automatically at checkout when the customer pays
            with BareBits, and advertised in the payment method's title. Also editable under
            WooCommerce &rarr; Settings &rarr; Payments.</p>
        <?php submit_button('Save discount', 'secondary'); ?>
    </form>
    <?php
}

/** Save handler for the Connection page form. */
function barebits_handle_save_discount(): void {
    if (!current_user_can('manage_options')) {
        barebits_admin_post_denied();
    }
    check_admin_referer('barebits_save_discount');

    $parsed = barebits_parse_discount_percent(sanitize_text_field(wp_unslash((string) ($_POST['barebits_discount_percent'] ?? ''))));
    if ($parsed === null) {
        barebits_flash('error', 'The discount must be a number between 0 and 100 (up to two decimal places).');
    } else {
        barebits_save_discount_percent($parsed);
        barebits_flash('success', $parsed > 0
            ? 'Discount saved — customers paying with BareBits now get ' . barebits_format_discount_percent($parsed) . '% off, and the checkout title advertises it.'
            : 'Discount saved — no discount is applied at checkout.');
    }
    wp_safe_redirect(admin_url('admin.php?page=barebits-connection'));
    exit;
}
add_action('admin_post_barebits_save_discount', 'barebits_handle_save_discount');

/**
 * Surface the same setting on the gateway's own WooCommerce settings form
 * (WooCommerce → Settings → Payments → BareBits), where a merchant tweaking
 * checkout options would look for it. The field's value is bridged to
 * barebits_discount_percent on save and stripped from the gateway's stored
 * settings (see the sanitized_fields filter below), so there is exactly one
 * source of truth.
 */
function barebits_inject_gateway_discount_field(array $fields): array {
    $fields['barebits_discount_percent'] = [
        'title' => 'Bitcoin discount (%)',
        'type' => 'decimal',
        'description' => 'Automatic percentage discount (0–100) for paying through this gateway. '
            . 'The payment method title advertises the current value automatically. '
            . 'Managed by the BareBits plugin; also editable on its Connection page.',
        'default' => barebits_format_discount_percent(barebits_discount_percent()),
        'desc_tip' => false,
    ];
    return $fields;
}
add_filter('woocommerce_settings_api_form_fields_' . BAREBITS_DISCOUNT_GATEWAY_ID, 'barebits_inject_gateway_discount_field');

/**
 * On gateway-settings save: pull our field out of the about-to-be-persisted
 * settings array into the shared option. Runs for every save of the form,
 * whether or not the merchant touched the field — writing the unchanged
 * value back is harmless and keeps the logic stateless.
 */
function barebits_extract_gateway_discount_field(array $settings): array {
    if (!array_key_exists('barebits_discount_percent', $settings)) {
        return $settings;
    }
    $parsed = barebits_parse_discount_percent((string)$settings['barebits_discount_percent']);
    if ($parsed === null) {
        if (class_exists('WC_Admin_Settings')) {
            WC_Admin_Settings::add_error('The Bitcoin discount must be a number between 0 and 100 (up to two decimal places). The previous value was kept.');
        }
    } else {
        barebits_save_discount_percent($parsed);
    }
    unset($settings['barebits_discount_percent']);
    return $settings;
}
add_filter('woocommerce_settings_api_sanitized_fields_' . BAREBITS_DISCOUNT_GATEWAY_ID, 'barebits_extract_gateway_discount_field');
