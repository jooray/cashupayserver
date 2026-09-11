/**
 * BareBits plugin — blocks checkout helper.
 *
 * The blocks checkout keeps its selected payment method in the
 * wc/store/payment data store and never tells the server about a selection
 * until Place Order — so a payment-method-driven fee would not show up in
 * the totals panel. Watch the store and, whenever the BareBits gateway is
 * selected or left, push the active method into the WooCommerce session
 * through the Store API's cart/extensions endpoint (extensionCartUpdate),
 * which re-runs the cart calculation and returns the updated totals to the
 * checkout UI.
 *
 * Only ours/not-ours transitions are synced (switching between two other
 * gateways changes nothing about the discount), and a failed sync is left
 * to the server-side place-order guard, which corrects the order's fee from
 * the submitted payment method regardless of what this script managed to do.
 * License: GPLv2 or later.
 */
(function () {
    var config = window.barebitsDiscountConfig || {};
    var gatewayId = config.gatewayId || 'btcpaygf_default';
    var namespace = config.namespace || 'barebits-discount';

    if (!window.wp || !window.wp.data || !window.wc || !window.wc.blocksCheckout
            || typeof window.wc.blocksCheckout.extensionCartUpdate !== 'function') {
        return;
    }

    var serverThinksSelected = !!config.serverThinksSelected;
    var syncing = false;

    window.wp.data.subscribe(function () {
        if (syncing) {
            return;
        }
        var store = window.wp.data.select('wc/store/payment');
        if (!store || typeof store.getActivePaymentMethod !== 'function') {
            return;
        }
        var active = store.getActivePaymentMethod();
        if (!active) {
            return;
        }
        var selected = active === gatewayId;
        if (selected === serverThinksSelected) {
            return;
        }
        serverThinksSelected = selected;
        syncing = true;
        window.wc.blocksCheckout.extensionCartUpdate({
            namespace: namespace,
            data: { payment_method: active }
        }).catch(function () {
            // The place-order guard settles any divergence; allow a retry on
            // the next selection change.
            serverThinksSelected = !selected;
        }).then(function () {
            syncing = false;
        });
    });
})();
