<?php

namespace WooCommerceCustobar\Synchronization;

defined( 'ABSPATH' ) || exit;

use WooCommerceCustobar\Data_Upload;
use WooCommerceCustobar\DataSource\Custobar_Data_Source;

/**
 * Class Data_Sync
 *
 * @package WooCommerceCustobar\Synchronization
 */
abstract class Data_Sync {

	abstract public static function schedule_single_update( $item_id, $force );
	abstract public static function single_update( $item_id );
	abstract protected static function format_single_item( $item );
	abstract protected static function upload_data_type_data( $data );

	public static function add_hooks() {
		// Hook export related actions
		add_action( 'admin_init', array( __CLASS__, 'maybe_launch_export' ), 10 );
	}

	public static function maybe_launch_export() {
		if ( isset( $_GET['page'] ) && 'wc-settings' === $_GET['page'] && ! empty( $_GET['launch_custobar_export'] ) ) { // WPCS: input var ok.
			$data_type = $_GET['launch_custobar_export'] ?? '';
			$id        = $_GET['woocommerce_custobar_export_id'] ?? '';

			// Check nonce
			check_admin_referer( 'woocommerce_custobar_' . $data_type . '_export', 'woocommerce_custobar_' . $data_type . '_export_nonce' );

			/*
			* Check that we haven't initiated an export with the same id. This prevents a new export starting if the user simply keeps reloading the page.
			* Might need to consider a more elegant solution later.
			*/
			if ( $data_type && $id != get_option( 'woocommerce_custobar_export_' . $data_type . '_id' ) ) {
				// Check if we already have an export queued
				if ( ! as_next_scheduled_action( 'woocommerce_custobar_' . $data_type . '_export' ) ) {
					\WooCommerceCustobar\Admin\Notes\Export_In_progress::possibly_add_note();
					as_schedule_single_action( time(), 'woocommerce_custobar_' . $data_type . '_export', array( 'offset' => 0 ), 'custobar' );
					self::reset_export_data( $data_type, $id );
				}
			}
		}
	}

	/**
	 * Handle single update
	 *
	 * @return void
	 */
	public static function do_single_update( $id ) {
		// The child class that called this method
		$child = static::$child;

		// Do the update
		$response = $child::single_update( $id );

		if ( false === $response ) {
			// Return silently, the data was not meant to be uploaded in the first place
			return null;
		}

		if ( is_wp_error( $response ) ) {
			// Request was invalid, and has been logged already
			return null;
		}

		if ( ! in_array( $response->code, array( 200, 201 ) ) ) {
			// Unexpected response code, fail the action.
			wc_get_logger()->warning(
				"#{$id} $child upload, unexpected response code {$response->code}, FAILING",
				array( 'source' => 'custobar' )
			);

			// Throw an exception to tell action scheduler to mark action as failed.
			throw new \Exception( "Custobar upload failed: Unexpected response code '{$response->code}'" );

		} else {
			wc_get_logger()->info(
				"#{$id} $child succesful upload",
				array( 'source' => 'custobar' )
			);
		}
	}

	protected static function upload_custobar_data( $data ) {
		$endpoint       = static::$endpoint;
		// $cds            = new Custobar_Data_Source();
		// $integration_id = $cds->get_integration_id();

		// if ( ! $integration_id ) {
		// 	$integration_id = $cds->create_integration();
		// }

		// if ( $integration_id ) {

		// 	switch ( $endpoint ) {
		// 		case '/customers/upload/':
		// 			$data_source_id = $cds->get_customer_data_source_id();
		// 			if ( ! $data_source_id ) {
		// 				$data_source_id = $cds->create_data_source( 'WooCommerce customers', 'customers' );
		// 			}
		// 			break;
		// 		case '/products/upload/':
		// 			$data_source_id = $cds->get_product_data_source_id();
		// 			if ( ! $data_source_id ) {
		// 				$data_source_id = $cds->create_data_source( 'WooCommerce products', 'products' );
		// 			}
		// 			break;
		// 		case '/sales/upload/':
		// 			$data_source_id = $cds->get_sale_data_source_id();
		// 			if ( ! $data_source_id ) {
		// 				$data_source_id = $cds->create_data_source( 'WooCommerce sales', 'sales' );
		// 			}
		// 			break;
		// 	}

		// 	if ( $data_source_id ) {
		// 		$endpoint = '/datasources/' . $data_source_id . '/import/';
		// 	}
		// }

		return Data_Upload::upload_custobar_data( $endpoint, $data );
	}

	/**
	 * A helper method to handle Custobar response to a mass export request.
	 *
	 * Updates export related options
	 *
	 * @param string $data_type       Data type
	 * @param int    $offset          Current offset
	 * @param int    $limit           Query limit
	 * @param int    $batch_count     Current batch count
	 * @param int    $total_count     Total count
	 * @param object $api_response    Response from Custobar API
	 * @return void
	 */
	public static function handle_export_response( $data_type, $offset, $limit, $batch_count, $total_count, $api_response ) {
		wc_get_logger()->notice(
			'Handling response for ' . $data_type . '. With total count: ' . $total_count . '. Offset: ' . $offset . '. Limit: ' . $limit . '. Batch count: ' . $batch_count,
			array( 'source' => 'custobar' )
		);
		if ( is_object( $api_response ) && property_exists( $api_response, 'code' ) ) {
			switch ( $api_response->code ) :
				case 200:
					// Consider scheduling new action
					if ( ( $offset + $limit ) < $total_count ) {
						as_schedule_single_action( time(), 'woocommerce_custobar_' . $data_type . '_export', array( 'offset' => $offset + $limit ), 'custobar' );
						update_option( 'woocommerce_custobar_export_' . $data_type . '_exported_count', $offset + $batch_count );
					} else {
						wc_get_logger()->notice(
							'Handling response for ' . $data_type . ' and concluding that we are done!',
							array( 'source' => 'custobar' )
						);
						update_option( 'woocommerce_custobar_export_' . $data_type . '_status', 'completed' );
						update_option( 'woocommerce_custobar_export_' . $data_type . '_completed_time', time() );
						update_option( 'woocommerce_custobar_export_' . $data_type . '_exported_count', $offset + $batch_count );
						// Check if we have any other exports in progress. If not, show "Competed" note
						if ( ! self::is_export_in_progress() ) {
							\WooCommerceCustobar\Admin\Notes\Export_In_progress::possibly_delete_note();
							\WooCommerceCustobar\Admin\Notes\Export_Completed::possibly_add_note();
						}
					}
					break;
				case 429:
					// Retry after 60 seconds
					as_schedule_single_action( time() + 60, 'woocommerce_custobar_' . $data_type . '_export', array( 'offset' => $offset ), 'custobar' );
					break;
				case 404:
					update_option( 'woocommerce_custobar_export_' . $data_type . '_status', 'failed' );
					if ( ! self::is_export_in_progress() ) {
						\WooCommerceCustobar\Admin\Notes\Export_In_progress::possibly_delete_note();
					}
					\WooCommerceCustobar\Admin\Notes\Export_Failed::possibly_add_note();
					break;
				case 400:
					update_option( 'woocommerce_custobar_export_' . $data_type . '_status', 'failed: ' . $api_response->body );
					if ( ! self::is_export_in_progress() ) {
						\WooCommerceCustobar\Admin\Notes\Export_In_progress::possibly_delete_note();
					}
					\WooCommerceCustobar\Admin\Notes\Export_Failed::possibly_add_note();
					break;
				default:
					update_option( 'woocommerce_custobar_export_' . $data_type . '_status', 'Unknown error' );
					if ( ! self::is_export_in_progress() ) {
						\WooCommerceCustobar\Admin\Notes\Export_In_progress::possibly_delete_note();
					}
					\WooCommerceCustobar\Admin\Notes\Export_Failed::possibly_add_note();
			endswitch;
		} else {
			update_option( 'woocommerce_custobar_export_' . $data_type . '_status', 'failed' );
			if ( ! self::is_export_in_progress() ) {
				\WooCommerceCustobar\Admin\Notes\Export_In_progress::possibly_delete_note();
			}
			\WooCommerceCustobar\Admin\Notes\Export_Failed::possibly_add_note();
		}
	}

	public static function get_export_data_option_keys( $data_type ) {
		$data_preposition = 'woocommerce_custobar_export_';
		$data_keys        = array(
			'status'         => $data_preposition . $data_type . '_status',
			'completed_time' => $data_preposition . $data_type . '_completed_time',
			'exported_count' => $data_preposition . $data_type . '_exported_count',
			'start_time'     => $data_preposition . $data_type . '_start_time',
		);
		return $data_keys;
	}

	public static function get_data_type_export_data( $data_type ) {
		$option_keys = self::get_export_data_option_keys( $data_type );
		$export_data = array();
		foreach ( $option_keys as $name => $option_key ) {
			$value                = get_option( $option_key );
			$export_data[ $name ] = $value;
		}
		return $export_data;
	}

	public static function get_data_types() {
		return array(
			'customer',
			'product',
			'sale',
		);
	}

	public static function reset_export_data( $data_type, $id ) {
		update_option( 'woocommerce_custobar_export_' . $data_type . '_id', $id );
		update_option( 'woocommerce_custobar_export_' . $data_type . '_status', 'in_progress' );
		update_option( 'woocommerce_custobar_export_' . $data_type . '_start_time', time() );
		update_option( 'woocommerce_custobar_export_' . $data_type . '_exported_count', '' );
		update_option( 'woocommerce_custobar_export_' . $data_type . '_completed_time', '' );
	}

	/**
	 * Check if we have an export in progress.
	 */
	public static function is_export_in_progress( $data_types = array() ) {
		if ( ! $data_types ) {
			$data_types = self::get_data_types();
		}
		foreach ( $data_types as $data_type ) {
			$status = get_option( 'woocommerce_custobar_export_' . $data_type . '_status' );
			if ( "in_progress" === $status ) {
				return true;
			}
		}
		return false;
	}



}
