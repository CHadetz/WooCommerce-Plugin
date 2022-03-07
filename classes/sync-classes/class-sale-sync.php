<?php

namespace WooCommerceCustobar\Synchronization;

defined( 'ABSPATH' ) || exit;

use WooCommerceCustobar\DataType\Custobar_Sale;
use WooCommerceCustobar\DataType\Utilities;

/**
 * Class Sale_Sync
 *
 * @package WooCommerceCustobar\Synchronization
 */
class Sale_Sync extends Data_Sync {


	protected static $endpoint = '/sales/';
	protected static $child    = __CLASS__;

	public static function add_hooks() {
		// Schedule actions
		add_action( 'woocommerce_new_order', array( __CLASS__, 'schedule_single_update' ), 10, 1 );
		add_action( 'woocommerce_update_order', array( __CLASS__, 'schedule_single_update' ), 10, 1 );
		add_action( 'woocommerce_subscription_status_updated', array( __CLASS__, 'schedule_subscription_status_updated' ), 10, 3 );
		add_action( 'woocommerce_subscription_renewal_payment_complete', array( __CLASS__, 'schedule_subscription_renewal_payment_complete' ), 10, 2 );

		// Hook into scheduled actions
		// Call parent method
		add_action( 'woocommerce_custobar_sale_sync', array( __CLASS__, 'do_single_update' ), 10, 1 );

		add_filter( 'woocommerce_custobar_sale_properties', array( __CLASS__, 'add_subscription_fields' ), 10, 3 );
		add_action( 'woocommerce_custobar_sale_export', array( __CLASS__, 'export_batch' ), 10, 1 );
	}


	public static function export_batch( $offset ) {
		$response = new \stdClass();
		$limit    = 100;

		// Get orders by offset and limit
		$args = array(
			'type'     => 'shop_order', // skip shop_order_refund
			'limit'    => $limit,
			'offset'   => $offset,
			'orderby'  => 'ID',
			'order'    => 'ASC',
			'paginate' => true,
		);

		// Allow 3rd parties to modify args
		$args = apply_filters( 'woocommerce_custobar_batch_update_orders_args', $args );

		$results = wc_get_orders( $args );

		$orders = $results->orders;

		foreach ( $orders as $order ) {

			foreach ( $order->get_items() as $order_item ) {

				$order_rows[] = self::format_single_item(
					array(
						'order'      => $order,
						'order_item' => $order_item,
					)
				);

			}
		}

		$current_batch_count = count( $orders );
		$total_count         = $results->total;

		// Upload data
		$api_response = self::upload_data_type_data( $order_rows );

		// Handle response and possibly schedule next round
		self::handle_export_response( 'sale', $offset, $limit, $current_batch_count, $total_count, $api_response );
	}

	/**
	 * New order / Order updated
	 *
	 * @param int/string $order_id
	 * @return void
	 */
	public static function schedule_single_update( $order_id, $force = false ) {
		if ( ! $order_id ) {
			return;
		}

		// Allow 3rd parties to decide if order should be synced
		if ( ! apply_filters( 'woocommerce_custobar_order_should_sync', true, $order_id ) ) {
			return;
		}

		// Skip subscriptions
		if ( 'shop_subscription' === get_post_type( $order_id ) ) {
			return;
		}

		$hook  = 'woocommerce_custobar_sale_sync';
		$args  = array( 'order_id' => $order_id );
		$group = 'custobar';

		// Force schedule
		// For example reschedule when action still in progress
		if ( $force ) {
			as_schedule_single_action( time(), $hook, $args, $group );

			wc_get_logger()->info(
				'#' . $order_id . ' NEW/UPDATE ORDER, SYNC SCHEDULED (FORCE)',
				array( 'source' => 'custobar' )
			);
		} else {
			// We need only one action scheduled
			as_unschedule_action( $hook, $args, $group );
			as_schedule_single_action( time(), $hook, $args, $group );

			wc_get_logger()->info(
				'#' . $order_id . ' NEW/UPDATE ORDER, SYNC SCHEDULED',
				array( 'source' => 'custobar' )
			);
		}
	}

	/**
	 * Subscription renewal payment complete
	 * We want to sync subscription's parent order, not the subscription itself.
	 * Subscriptions are not orders themselves, they are promises of future orders.
	 *
	 * @param \WC_Subscription $subscription
	 * @param \WC_Order $order
	 * @return void
	 */
	public static function schedule_subscription_renewal_payment_complete( $subscription, $order ) {
		// Allow 3rd parties to decide if order should be synced
		if ( ! apply_filters( 'woocommerce_custobar_subscription_renewal_should_sync', true, $subscription, $order ) ) {
			return;
		}

		$hook  = 'woocommerce_custobar_sale_sync';
		$args  = array( 'order_id' => $order->get_id() );
		$group = 'custobar';

		// We need only one action scheduled
		if ( ! as_next_scheduled_action( $hook, $args, $group ) ) {
			as_schedule_single_action( time(), $hook, $args, $group );

			wc_get_logger()->info(
				'#' . $order->get_id() . ' RENEWAL PAYMENT COMPLETE, SYNC SCHEDULED',
				array( 'source' => 'custobar' )
			);
		}
	}

	/**
	 * Subscription status update
	 * We want to sync subscription's parent order, not the subscription itself.
	 * Subscriptions are not orders themselves, they are promises of future orders.
	 *
	 * @param \WC_Subscription $subscription
	 * @param string $new_status
	 * @param string $old_status
	 * @return void
	 */
	public static function schedule_subscription_status_updated( $subscription, $new_status, $old_status ) {
		// Allow 3rd parties to decide if order should be synced
		if ( ! apply_filters( 'woocommerce_custobar_subscription_status_parent_should_sync', true, $subscription, $new_status, $old_status ) ) {
			return;
		}

		$hook  = 'woocommerce_custobar_sale_sync';
		$args  = array( 'order_id' => $subscription->get_parent_id() );
		$group = 'custobar';

		// We need only one action scheduled
		if ( ! as_next_scheduled_action( $hook, $args, $group ) ) {
			as_schedule_single_action( time(), $hook, $args, $group );

			wc_get_logger()->info(
				'#' . $subscription->get_id() . " SUBSCRIPTION STATUS UPDATE, $old_status -> $new_status, PARENT ORDER SYNC SCHEDULED (#{$args['order_id']})",
				array( 'source' => 'custobar' )
			);
		}
	}

	public static function single_update( $order_id ) {

		$order = wc_get_order( $order_id );

		if ( $order ) {

			wc_get_logger()->info(
				'#' . $order_id . ' ORDER SYNC, UPLOADING TO CUSTOBAR',
				array( 'source' => 'custobar' )
			);

			$data = array();
			foreach ( $order->get_items() as $order_item ) {
				$data[] = self::format_single_item(
					array(
						'order'      => $order,
						'order_item' => $order_item,
					)
				);
			}

			return self::upload_data_type_data( $data );

		} else {

			wc_get_logger()->warning(
				'#' . $order_id . ' tried to sync order, but order was not found',
				array( 'source' => 'custobar' )
			);

		}

		return false;
	}

	/**
	 * Format single item
	 * Single argument required according to inherited abstract
	 *
	 * @param array $args containing order and order_item
	 * @return array
	 */
	protected static function format_single_item( $args ) {
		$order         = $args['order'];
		$order_item    = $args['order_item'];
		$custobar_sale = new Custobar_Sale( $order, $order_item );
		$properties    = $custobar_sale->get_assigned_properties();

		return apply_filters( 'woocommerce_custobar_sale_properties', $properties, $order, $order_item );
	}

	protected static function upload_data_type_data( $data ) {
		$formatted_data = array(
			'sales' => $data,
		);
		return self::upload_custobar_data( $formatted_data );
	}

	/**
	 * Modify basic properties by adding WooCommerce Subscriptions related
	 * fields.
	 */
	public static function add_subscription_fields( $properties, $order, $order_item ) {
		if ( function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order ) ) {
			$product_id = $order_item->get_product_id();
			$prefix     = apply_filters( 'woocommerce_custobar_company_field_prefix', get_option( 'custobar_api_setting_company' ) );

			foreach ( wcs_get_subscriptions_for_order( $order ) as $subscription ) {
				foreach ( $subscription->get_items() as $line_item ) {
					if ( $line_item->get_product_id() === $product_id ) {
						$properties[ $prefix . '__subscription_status' ] = $subscription->get_status();
						if ( $subscription->get_date( 'date_created' ) ) {
							$properties[ $prefix . '__subscription_date_created' ] = Utilities::format_datetime( new \DateTime( $subscription->get_date( 'date_created' ) ) );
						}
						if ( $subscription->get_date( 'trial_end' ) ) {
							$properties[ $prefix . '__subscription_trial_end' ] = Utilities::format_datetime( new \DateTime( $subscription->get_date( 'trial_end' ) ) );
						}
						if ( $subscription->get_date( 'next_payment' ) ) {
							$properties[ $prefix . '__subscription_next_payment' ] = Utilities::format_datetime( new \DateTime( $subscription->get_date( 'next_payment' ) ) );
						}
						if ( $subscription->get_date( 'last_order_date_paid' ) ) {
							$properties[ $prefix . '__subscription_last_order_date_paid' ] = Utilities::format_datetime( new \DateTime( $subscription->get_date( 'last_order_date_paid' ) ) );
						}
						if ( $subscription->get_date( 'cancelled' ) ) {
							$properties[ $prefix . '__subscription_cancelled' ] = Utilities::format_datetime( new \DateTime( $subscription->get_date( 'cancelled' ) ) );
						}
						if ( $subscription->get_date( 'end' ) ) {
							$properties[ $prefix . '__subscription_end' ] = Utilities::format_datetime( new \DateTime( $subscription->get_date( 'end' ) ) );
						}
					}
				}
			}
		}
		return $properties;
	}
}
