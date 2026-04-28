<?php
/**
 * Plugin Name:       WC Auto-Complete Virtual Orders
 * Plugin URI:        https://example.com/
 * Description:       Automatically completes WooCommerce orders that contain only virtual or downloadable products. HPOS compatible and extensible via filters.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Dhiren Patel
 * Author URI:        https://www.dhirenpatel.me
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wc-auto-complete-virtual-orders
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Auto-complete WooCommerce orders containing only virtual/downloadable products.
 *
 * Fires on the `woocommerce_order_status_changed` hook.
 *
 * Extensible via the following filters:
 *  - wc_acvo_allowed_statuses      — which order statuses trigger the check.
 *  - wc_acvo_is_virtual_product    — override virtual logic per product.
 *  - wc_acvo_is_virtual_order      — final override for the whole order.
 *  - wc_acvo_target_status         — the status to transition the order into.
 *
 * @param int       $order_id   Order ID.
 * @param string    $old_status Previous order status (without 'wc-' prefix).
 * @param string    $new_status New order status (without 'wc-' prefix).
 * @param \WC_Order $order      Order object.
 */
function wc_acvo_auto_complete_order( $order_id, $old_status, $new_status, $order ) {

	// Bail early if no order ID.
	if ( empty( $order_id ) ) {
		return;
	}

	// Ensure we have a valid order object (HPOS-safe fallback).
	if ( ! $order instanceof \WC_Order ) {
		$order = wc_get_order( $order_id );
	}

	if ( ! $order instanceof \WC_Order ) {
		return;
	}

	/**
	 * Filters which order statuses trigger the auto-complete check.
	 *
	 * @param string[]  $allowed_statuses Statuses that trigger the check (without 'wc-' prefix).
	 * @param \WC_Order $order            Current order object.
	 */
	$allowed_statuses = apply_filters(
		'wc_acvo_allowed_statuses',
		array( 'processing' ),
		$order
	);

	// Only proceed when the order is in an allowed status.
	if ( ! in_array( $order->get_status(), $allowed_statuses, true ) ) {
		return;
	}

	// Assume virtual until a physical product is found.
	$is_virtual_order = true;

	foreach ( $order->get_items() as $item ) {

		// Skip line items with no associated product (e.g. deleted products).
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			continue;
		}

		$product = $item->get_product();

		if ( ! $product instanceof \WC_Product ) {
			continue;
		}

		// Default: treat virtual or downloadable products as "virtual".
		$is_virtual_product = $product->is_virtual() || $product->is_downloadable();

		/**
		 * Filters whether an individual product counts as virtual.
		 *
		 * @param bool                  $is_virtual_product Whether the product is considered virtual.
		 * @param \WC_Product           $product            Product object.
		 * @param \WC_Order_Item_Product $item              Order line-item.
		 * @param \WC_Order             $order              Order object.
		 */
		$is_virtual_product = (bool) apply_filters(
			'wc_acvo_is_virtual_product',
			$is_virtual_product,
			$product,
			$item,
			$order
		);

		if ( ! $is_virtual_product ) {
			$is_virtual_order = false;
			break;
		}
	}

	/*
	 * Subscription renewal override.
	 * Renewal orders should always auto-complete regardless of product type
	 * because payment has already been captured by the gateway.
	 */
	if ( function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order ) ) {
		$is_virtual_order = true;
	}

	/**
	 * Filters the final decision on whether an order should be auto-completed.
	 *
	 * @param bool      $is_virtual_order Whether the order qualifies for auto-completion.
	 * @param \WC_Order $order            Order object.
	 */
	$is_virtual_order = (bool) apply_filters(
		'wc_acvo_is_virtual_order',
		$is_virtual_order,
		$order
	);

	if ( ! $is_virtual_order ) {
		return;
	}

	/**
	 * Filters the target status the order is transitioned into.
	 *
	 * @param string    $target_status Desired status slug (without 'wc-' prefix). Default 'completed'.
	 * @param \WC_Order $order         Order object.
	 */
	$target_status = (string) apply_filters(
		'wc_acvo_target_status',
		'completed',
		$order
	);

	// Avoid a redundant status update if already at the target status.
	if ( $order->get_status() === $target_status ) {
		return;
	}

	$order->update_status(
		$target_status,
		esc_html__( 'Order auto-completed because it contains only virtual or downloadable products.', 'wc-auto-complete-virtual-orders' )
	);
}
add_action( 'woocommerce_order_status_changed', 'wc_acvo_auto_complete_order', 10, 4 );
