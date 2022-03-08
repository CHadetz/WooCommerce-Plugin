<?php

namespace WooCommerceCustobar\Synchronization;

defined( 'ABSPATH' ) || exit;

use WooCommerceCustobar\DataType\Custobar_Customer;

/**
 * Class Customer_Sync
 *
 * @package WooCommerceCustobar\Synchronization
 */
class Customer_Sync extends Data_Sync {


	protected static $endpoint  = '/customers/upload/';
	protected static $child     = __CLASS__;
	protected static $data_type = 'customer';

	public static function add_hooks() {
		// Schedule actions
		add_action( 'user_register', array( __CLASS__, 'schedule_single_update' ), 10, 1 );
		add_action( 'profile_update', array( __CLASS__, 'schedule_single_update' ), 10, 1 );
		add_action( 'woocommerce_new_customer', array( __CLASS__, 'schedule_single_update', 10, 1 ) );
		add_action( 'woocommerce_update_customer', array( __CLASS__, 'schedule_single_update' ), 10, 1 );

		// Hook into scheduled actions
		// Call parent method
		add_action( 'woocommerce_custobar_customer_sync', array( __CLASS__, 'do_single_update' ), 10, 1 );

		// Hook export related actions
		add_action( 'woocommerce_custobar_customer_export', array( __CLASS__, 'export_batch' ), 10, 1 );
	}


	public static function export_batch( $offset ) {
		$response = new \stdClass();
		$limit    = 100;

		/*
		* Fetch users
		*/
		$query = new \WP_User_Query(
			array(
				'role__in' => self::get_allowed_roles(),
				'fields'   => 'ID',
				'orderby'  => 'ID',
				'order'    => 'ASC',
				'number'   => $limit,
				'offset'   => $offset,
			)
		);

		// Todo: send these as arguments
		$can_email = get_option( 'custobar_export_force_can_email' );
		$can_sms   = get_option( 'custobar_export_force_can_sms' );

		$users = $query->get_results();

		$customers = array();

		foreach ( $users as $user_id ) {
			$customer   = new \WC_Customer( $user_id );
			$properties = self::format_single_item( $customer );

			// Check settings for exporting marketing permissions
			if ( 'yes' === $can_email ) {
				$properties['can_email'] = true;
			}

			if ( 'yes' === $can_sms ) {
				$properties['can_sms'] = true;
			}

			$customers[] = $properties;
		}

		$processed_count = count( $customers );
		$total_count     = $query->get_total();

		// Upload data
		$api_response = self::upload_data_type_data( $customers );

		// Handle response and possibly schedule next round
		self::handle_export_response( 'customer', $offset, $limit, $processed_count, $total_count, $api_response );
	}

	public static function schedule_single_update( $user_id, $force = false ) {
		// Allow 3rd parties to decide if customer should be synced
		if ( ! apply_filters( 'woocommerce_custobar_customer_should_sync', true, $user_id ) ) {
			return;
		}

		$hook  = 'woocommerce_custobar_customer_sync';
		$args  = array( 'user_id' => $user_id );
		$group = 'custobar';

		// Force schedule
		// For example reschedule when action still in progress
		if ( $force ) {
			as_schedule_single_action( time(), $hook, $args, $group );

			wc_get_logger()->info(
				'#' . $user_id . ' NEW/UPDATE CUSTOMER, SYNC SCHEDULED (FORCE)',
				array( 'source' => 'custobar' )
			);
		} else {
			// We need only one action scheduled
			as_unschedule_action( $hook, $args, $group );
			as_schedule_single_action( time(), $hook, $args, $group );
	
			wc_get_logger()->info(
				'#' . $user_id . ' NEW/UPDATE CUSTOMER, SYNC SCHEDULED',
				array( 'source' => 'custobar' )
			);
		}
	}

	/**
	 * Customer sync
	 *
	 * @param int $user_id
	 * @return void
	 */
	public static function single_update( $user_id ) {

		wc_get_logger()->info(
			'#' . $user_id . ' CUSTOMER SYNC, UPLOADING TO CUSTOBAR',
			array( 'source' => 'custobar' )
		);

		$customer = new \WC_Customer( $user_id );

		if ( in_array( $customer->get_role(), self::get_allowed_roles() ) ) {

			$properties     = self::format_single_item( $customer );
			$initial_export = false;

			$response = self::upload_data_type_data( $properties, true );

			return $response;

		} else {

			wc_get_logger()->warning(
				'#' . $user_id . ' tried to sync customer, but user role was not allowed',
				array( 'source' => 'custobar' )
			);

		}

		return false;
	}

	/**
	 * Get allowed user roles that will be synced
	 * By default only users with the role 'customer' are synced
	 *
	 * @return array Allowed user roles
	 */
	public static function get_allowed_roles() {
		// Allow 3rd parties filter roles to be synced
		$roles = apply_filters( 'woocommerce_custobar_customer_sync_roles', array( 'customer' ) );
		return array_filter( array_unique( $roles ) );
	}

	/**
	 * Format single item
	 *
	 * @param \WC_Customer $customer
	 * @return array filtered properties
	 */
	protected static function format_single_item( $customer ) {
		$custobar_customer = new Custobar_Customer( $customer );
		$properties        = $custobar_customer->get_assigned_properties();
		return apply_filters( 'woocommerce_custobar_customer_properties', $properties, $customer );
	}

	protected static function upload_data_type_data( $data, $single = false ) {
		$formatted_data = array(
			'customers' => array(),
		);

		if ( $single ) {
			$formatted_data['customers'][] = $data;
		} else {
			$formatted_data['customers'] = $data;
		}

		return self::upload_custobar_data( $formatted_data );
	}
}
