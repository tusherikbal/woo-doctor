<?php
/**
 * Rule: Basic Stock Mismatch.
 *
 * @package Order_Health_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Order_Health_Doctor_Rule_Stock_Mismatch
 *
 * Finds products with stock management enabled and a negative stock quantity
 * (oversold). Uses WooCommerce product data methods, not raw meta.
 */
class Order_Health_Doctor_Rule_Stock_Mismatch extends Order_Health_Doctor_Rule {

	/**
	 * {@inheritDoc}
	 */
	public function get_id() {
		return 'stock_mismatch';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label() {
		return __( 'Stock Mismatch', 'order-health-doctor' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Stock-managed products that went below zero (oversold).', 'order-health-doctor' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_group() {
		return 'stock';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_object_type() {
		return 'product';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_default_severity() {
		return 'high';
	}

	/**
	 * Run this detection rule.
	 *
	 * @param Order_Health_Doctor_Scan_Context $ctx Shared scan context.
	 * @return array[] Detected issue data.
	 */
	public function run( Order_Health_Doctor_Scan_Context $ctx ) {
		$issues = array();

		// wc_get_products with manage_stock is reliable; we additionally confirm
		// managing_stock() and a negative quantity on the product object.
		$products = wc_get_products(
			array(
				'limit'        => 200,
				'manage_stock' => true,
				'orderby'      => 'ID',
				'order'        => 'ASC',
				'return'       => 'objects',
			)
		);

		foreach ( $products as $product ) {
			if ( ! $product->managing_stock() ) {
				continue;
			}

			$stock = $product->get_stock_quantity();
			if ( null === $stock || $stock >= 0 ) {
				continue;
			}

			$issues[] = array(
				'issue_type'       => $this->get_id(),
				'object_type'      => 'product',
				'object_id'        => $product->get_id(),
				'title'            => __( 'Negative stock detected', 'order-health-doctor' ),
				'message'          => sprintf(
					/* translators: 1: product name, 2: stock quantity */
					__( 'Product "%1$s" has a negative stock quantity (%2$s), which usually means it was oversold.', 'order-health-doctor' ),
					$product->get_name(),
					$stock
				),
				'suggested_action' => __( 'Edit the product and correct the stock quantity, then review recent orders for this item.', 'order-health-doctor' ),
				'metadata'         => array( 'stock_quantity' => $stock ),
			);
		}

		return $issues;
	}
}
