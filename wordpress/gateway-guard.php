<?php
/**
 * Barebits BTCPay gateway guard
 *
 * The upstream BTCPay Greenfield gateway's process_payment() implicitly
 * returns null when invoice creation fails (AbstractGateway::createInvoice
 * catches the API exception, logs it, and returns null). WooCommerce's block
 * checkout then fatals: StoreApi\Legacy::process_legacy_payment() feeds the
 * gateway result straight into array_merge() with no null check, so the
 * shopper gets an HTTP 500 instead of a payment error.
 *
 * This guard swaps the registered DefaultGateway class for a subclass whose
 * process_payment() converts that null into a regular \Exception. Both
 * checkout flavors catch exceptions and render the message as a normal
 * payment error (Store API: CheckoutTrait::process_payment; classic and the
 * plugin's modal ajax path: WC_Checkout::process_checkout). The underlying
 * cause was already force-logged by the plugin to WooCommerce → Status →
 * Logs before it returned null.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Declare (once) and name the guarded subclass.
 *
 * Declared here — not at file scope — because this file loads at plugin
 * boot, before the Greenfield plugin's autoloader is guaranteed to exist; by
 * the time WooCommerce collects its gateways the parent class is loadable,
 * or absent, in which case there is nothing to guard and this returns null.
 *
 * The subclass keeps the parent's gateway id (btcpaygf_default), so the
 * stored settings, the blocks checkout integration, and the plugin's
 * webhook endpoint all keep working unchanged.
 */
function barebits_guarded_btcpay_gateway_class(): ?string {
    if (!class_exists('BTCPayServer\\WC\\Gateway\\DefaultGateway')) {
        return null;
    }

    if (!class_exists('Barebits_Guarded_BTCPay_Gateway', false)) {
        class Barebits_Guarded_BTCPay_Gateway extends \BTCPayServer\WC\Gateway\DefaultGateway {
            public function process_payment($orderId) {
                $result = parent::process_payment($orderId);
                if (!is_array($result)) {
                    throw new \Exception(
                        'The payment could not be started. Please try again '
                        . 'in a moment, or choose a different payment method.'
                    );
                }
                return $result;
            }
        }
    }

    return 'Barebits_Guarded_BTCPay_Gateway';
}

/**
 * Replace the Greenfield DefaultGateway registration with the guarded
 * subclass. Priority 20 runs after the Greenfield plugin's own registration
 * (default priority 10).
 *
 * Only the exact stock class name is replaced: if another plugin already
 * substituted its own subclass, that customization wins over the guard.
 */
function barebits_guard_btcpay_gateways($gateways) {
    if (!is_array($gateways)) {
        return $gateways;
    }

    foreach ($gateways as $i => $gateway) {
        if (!is_string($gateway)
                || ltrim($gateway, '\\') !== 'BTCPayServer\\WC\\Gateway\\DefaultGateway') {
            continue;
        }
        $guarded = barebits_guarded_btcpay_gateway_class();
        if ($guarded === null) {
            break; // Registered but not loadable: leave the list untouched.
        }
        $gateways[$i] = $guarded;
    }

    return $gateways;
}
add_filter('woocommerce_payment_gateways', 'barebits_guard_btcpay_gateways', 20);
