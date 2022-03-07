<?php

namespace WooCommerceCustobar\Synchronization;

defined( 'ABSPATH' ) || exit;

use WooCommerceCustobar\DataType\Custobar_Product;

/**
 * Class Product_Sync
 *
 * @package WooCommerceCustobar\Synchronization
 */
class Product_Sync extends Data_Sync {


	protected static $endpoint = '/products/';
	protected static $child    = __CLASS__;

	public static function add_hooks() {
		// Schedule actions
		add_action( 'woocommerce_new_product', array( __CLASS__, 'schedule_single_update' ), 10, 1 );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'schedule_single_update' ), 10, 1 );

		// Hook into scheduled actions
		// Call parent method
		add_action( 'woocommerce_custobar_product_sync', array( __CLASS__, 'do_single_update' ), 10, 1 );

		// Hook export related actions
		add_action( 'admin_init', array( __CLASS__, 'maybe_launch_export' ), 10 );
		add_action( 'woocommerce_custobar_product_export', array( __CLASS__, 'export_batch' ), 10, 1 );

	}

	public static function maybe_launch_export() {
		if ( isset( $_GET['page'] ) && 'wc-settings' === $_GET['page'] && ! empty( $_GET['launch_custobar_product_export'] ) ) { // WPCS: input var ok.
			// Todo: check admin referer. Use wc_db_update as an example
			// Check that we don't have an export in progress
			// Reset export options

			self::reset_export_data( 'product' );

			as_schedule_single_action( time(), 'woocommerce_custobar_product_export', array( 'offset' => 0 ), 'custobar' );
		}
	}

	public static function export_batch( $offset ) {
		$response = new \stdClass();
		$limit    = 100;

		/*
		* Use normal WP_Query to get products.
		* This allows us to query for parent products and product variations in a single query.
		*/
		$query = new \WP_Query(
			array(
				'post_type'      => array( 'product_variation', 'product' ),
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'posts_per_page' => $limit,
				'offset'         => $offset,
			)
		);

		$product_ids = $query->get_posts();

		foreach ( $product_ids as $product_id ) {
			$product_object = wc_get_product( $product_id );
			if ( $product_object->get_parent_id() ) {
				$product_list[] = self::format_single_variant( $product_object );
			} else {
				$product_list[] = self::format_single_item( $product_object );
			}
		}

		$current_batch_count = count( $product_list );
		$total_count         = $query->found_posts;

		// Upload data
		$api_response = self::upload_data_type_data( $product_list );

		// Handle response and possibly schedule next round
		self::handle_export_response( 'product', $offset, $limit, $current_batch_count, $total_count, $api_response );
	}

	public static function schedule_single_update( $product_id, $force = false ) {
		// Allow 3rd parties to decide if product should be synced
		if ( ! apply_filters( 'woocommerce_custobar_product_should_sync', true, $product_id ) ) {
			return;
		}

		$hook  = 'woocommerce_custobar_product_sync';
		$args  = array( 'product_id' => $product_id );
		$group = 'custobar';

		// Force schedule
		// For example reschedule when action still in progress
		if ( $force ) {
			as_schedule_single_action( time(), $hook, $args, $group );

			wc_get_logger()->info(
				'#' . $product_id . ' NEW/UPDATE PRODUCT, SYNC SCHEDULED (FORCE)',
				array( 'source' => 'custobar' )
			);
		} else {
			// We need only one action scheduled
			as_unschedule_action( $hook, $args, $group );
			as_schedule_single_action( time(), $hook, $args, $group );
	
			wc_get_logger()->info(
				'#' . $product_id . ' NEW/UPDATE PRODUCT, SYNC SCHEDULED',
				array( 'source' => 'custobar' )
			);
		}
	}

	public static function single_update( $product_id ) {
		wc_get_logger()->info(
			'#' . $product_id . ' PRODUCT SYNC, UPLOADING TO CUSTOBAR',
			array( 'source' => 'custobar' )
		);

		$product = wc_get_product( $product_id );

		if ( $product ) {

			$properties = self::format_single_item( $product );
			return self::upload_data_type_data( $properties, true );

		} else {

			wc_get_logger()->warning(
				'#' . $product_id . ' tried to sync product, but product was not found',
				array( 'source' => 'custobar' )
			);

		}

		return false;
	}

	protected static function format_single_item( $product ) {
		$custobar_product = new Custobar_Product( $product );
		$properties       = $custobar_product->get_assigned_properties();
		return apply_filters( 'woocommerce_custobar_product_properties', $properties, $product );
	}

	protected static function format_single_variant( $variant ) {
		$custobar_product               = new Custobar_Product( $variant );
		$properties                     = $custobar_product->get_assigned_properties();
		$properties['main_product_ids'] = array( $variant->get_parent_id() );
		return apply_filters( 'woocommerce_custobar_product_properties', $properties, $variant );
	}


	protected static function upload_data_type_data( $data, $single = false ) {
		$formatted_data = array(
			'products' => array(),
		);
		if ( $single ) {
			$formatted_data['products'][] = $data;
		} else {
			$formatted_data['products'] = $data;
		}
		return self::upload_custobar_data( $formatted_data );
	}
}
