<?php

namespace WooCommerceCustobar;

defined( 'ABSPATH' ) || exit;

use WooCommerceCustobar\Synchronization\Product_Sync;
use WooCommerceCustobar\Synchronization\Customer_Sync;
use WooCommerceCustobar\Synchronization\Sale_Sync;

/**
 * Class Data_Upload
 *
 * @package WooCommerceCustobar
 */
class Data_Upload {


	/**
	 * Do the request to Custobar API
	 *
	 * @param string $endpoint
	 * @param array $data
	 * @return mixed response object or WP_Error
	 */
	public static function upload_custobar_data( $endpoint, $data ) {

		$body           = wp_json_encode( $data );
		$api_token      = \WC_Admin_Settings::get_option( 'custobar_api_setting_token', false );
		$company_domain = \WC_Admin_Settings::get_option( 'custobar_api_setting_company', false );
		//$url            = sprintf( 'https://%s.custobar.com/api', $company_domain ) . $endpoint;
		$url = $company_domain . $endpoint;

		$response = wp_remote_request(
			$url,
			array(
				'method'  => 'POST',
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Token ' . $api_token,
				),
				'body'    => $body,
			)
		);

		// Failed request, do logging and return error object
		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();

			wc_get_logger()->warning(
				"Custobar data upload failed: $message",
				array( 'source' => 'custobar' )
			);

			return $response;
		}

		// Create a simpler response object
		$response_data       = new \stdClass();
		$response_data->code = wp_remote_retrieve_response_code( $response );
		$response_data->body = wp_remote_retrieve_body( $response );

		// Unexpected response code, do logging
		if ( ! in_array( $response_data->code, array( 200, 201 ) ) ) {

			wc_get_logger()->warning(
				"Custobar data upload: Unexpected response code: {$response_data->code}",
				array( 'source' => 'custobar' )
			);

		} else {
			if ( get_option( 'custobar_wc_api_extended_logging' ) ) {
				wc_get_logger()->notice(
					'Successful request to endpoint: ' . $endpoint . "\nRequest body:\n" . wp_json_encode( json_decode( $body ), JSON_PRETTY_PRINT ) . "\nResponse body:\n" . json_encode( json_decode( $response_data->body ), JSON_PRETTY_PRINT ),
					array( 'source' => 'custobar-requests' )
				);
			}
		}

		return $response_data;
	}

	public static function add_hooks() {
		add_action( 'wp_ajax_custobar_export', __CLASS__ . '::jx_export' );
		add_action( 'wp_ajax_custobar_api_test', __CLASS__ . '::api_test' );
	}

	/**
	 * Historical data sync callback
	 *
	 * @return void
	 */
	public static function jx_export() {
		// environment checks
		$plugin = new Plugin();
		if ( $plugin::is_woocommerce_activated() && $plugin::has_all_settings_defined() ) {

			if ( ! isset( $_POST['recordType'] ) ) {
				wp_die( 'No recordType specified.' );
			}

			$record_type  = sanitize_text_field( wp_unslash( $_POST['recordType'] ) );
			$reset_offset = ! empty( $_POST['reset'] );

			switch ( $record_type ) {
				case 'customer':
					if ( $reset_offset ) {
						// Pass false as total to trigger total count update
						Customer_Sync::tracker_save( 0, false );
					}
					$api_response        = Customer_Sync::batch_update();
					$api_response->stats = self::fetch_sync_stat_customers();
					break;
				case 'sale':
					if ( $reset_offset ) {
						// Pass false as total to trigger total count update
						Sale_Sync::tracker_save( 0, false );
					}
					$api_response        = Sale_Sync::batch_update();
					$api_response->stats = self::fetch_sync_stat_sales();
					break;
				case 'product':
					if ( $reset_offset ) {
						// Pass false as totals to trigger total count update
						Product_Sync::tracker_save( 0, 0, false, false );
					}
					$api_response        = Product_Sync::batch_update();
					$api_response->stats = self::fetch_sync_stat_products();
					break;
			}

			$api_response->recordType = $record_type;

		} else {
			$response = array(
				'code' => 420,
			);
			wp_send_json_success( $response );
		}

		if ( $api_response ) {
			wp_send_json_success( $api_response );
		} else {
			$response = array(
				'code' => 440,
			);
			wp_send_json_success( $response );
		}
	}

	public static function fetch_sync_stat_products() {
		$stat    = new \stdClass();
		$tracker = Product_Sync::tracker_fetch();

		// get total product count
		if ( isset( $tracker['total'], $tracker['variant_total'] ) && is_int( $tracker['total'] ) && is_int( $tracker['variant_total'] ) ) {
			$stat->total         = $tracker['total'];
			$stat->variant_total = $tracker['variant_total'];
		} else {

			$product_count = 0;
			foreach ( wp_count_posts( 'product' ) as $state => $count ) {
				$product_count += $count;
			}

			$stat->total = $product_count;

			$variant_count = 0;
			foreach ( wp_count_posts( 'product_variation' ) as $state => $count ) {
				$variant_count += $count;
			}

			$stat->variant_total = $variant_count;
			Product_Sync::tracker_save( null, null, $stat->total, $stat->variant_total );
		}

		$stat->synced = $tracker['offset'];
		if ( $stat->total > 0 ) {
			$stat->synced_percent = number_format( ( $stat->synced / $stat->total ) * 100 ) . '%';
		} else {
			$stat->synced_percent = '-';
		}

		$stat->variant_synced = $tracker['variant_offset'];
		if ( $stat->variant_synced > 0 ) {
			$stat->synced_percent .= ' / ' . number_format( ( $stat->variant_synced / $stat->variant_total ) * 100 ) . '%';
		} else {
			$stat->synced_percent .= ' / -';
		}

		if ( is_int( $tracker['updated'] ) && $tracker['updated'] ) {
			$stat->last_updated = gmdate( wc_date_format(), $tracker['updated'] ) . ' ' . gmdate( wc_time_format(), $tracker['updated'] );
		} else {
			$stat->last_updated = '';
		}

		return $stat;

	}

	public static function fetch_sync_stat_sales() {
		$stat    = new \stdClass();
		$tracker = Sale_Sync::tracker_fetch();

		// Cache total count
		if ( isset( $tracker['total'] ) && is_int( $tracker['total'] ) ) {
			$stat->total = $tracker['total'];
		} else {
			// Get orders
			$args = array(
				'type'   => 'shop_order', // skip shop_order_refund
				'limit'  => -1,
				'offset' => 0,
				'return' => 'ids',
			);

			// Allow 3rd parties to modify args
			$args = apply_filters( 'woocommerce_custobar_batch_update_orders_args', $args );

			$orders = wc_get_orders( $args );

			$stat->total = count( $orders );
			Sale_Sync::tracker_save( null, $stat->total );
		}

		$stat->synced = $tracker['offset'];

		if ( $stat->total > 0 ) {
			$stat->synced_percent = number_format( ( $stat->synced / $stat->total ) * 100 ) . '%';
		} else {
			$stat->synced_percent = '-';
		}

		if ( is_int( $tracker['updated'] ) && $tracker['updated'] ) {
			$stat->last_updated = gmdate( wc_date_format(), $tracker['updated'] ) . ' ' . gmdate( wc_time_format(), $tracker['updated'] );
		} else {
			$stat->last_updated = '';
		}

		return $stat;

	}

	public static function fetch_sync_stat_customers() {
		$stat    = new \stdClass();
		$tracker = Customer_Sync::tracker_fetch();

		// Cache total count
		if ( isset( $tracker['total'] ) && is_int( $tracker['total'] ) ) {
			$stat->total = $tracker['total'];
		} else {
			$query = new \WP_User_Query(
				array(
					'role__in' => Customer_Sync::get_allowed_roles(),
					'fields'   => 'ID',
				)
			);

			$stat->total = $query->get_total();
			Customer_Sync::tracker_save( null, $stat->total );
		}

		$stat->synced = $tracker['offset'];
		if ( $stat->total > 0 ) {
			$stat->synced_percent = number_format( ( $stat->synced / $stat->total ) * 100 ) . '%';
		} else {
			$stat->synced_percent = '-';
		}

		if ( is_int( $tracker['updated'] ) && $tracker['updated'] ) {
			$stat->last_updated = gmdate( wc_date_format(), $tracker['updated'] ) . ' ' . gmdate( wc_time_format(), $tracker['updated'] );
		} else {
			$stat->last_updated = '';
		}

		return $stat;

	}

	public static function api_test() {
		$api_token      = \WC_Admin_Settings::get_option( 'custobar_api_setting_token', false );
		$company_domain = \WC_Admin_Settings::get_option( 'custobar_api_setting_company', false );
		$url            = $company_domain . '/data/customers/';

		$response = wp_remote_request(
			$url,
			array(
				'method'  => 'GET',
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Token ' . $api_token,
				),
			)
		);

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		if ( 200 === $response_code ) {
			$message = 'Successful test, your site is connected to Custobar.';
		} else {
			$message = 'Sorry the test failed, please check your API token and domain and try again. If the problems persists please contact Custobar support.';
		}

		$response = array(
			'url'     => $url,
			'code'    => $response_code,
			'body'    => $response_body,
			'message' => $message,
		);

		wp_send_json_success( $response );
	}

}
