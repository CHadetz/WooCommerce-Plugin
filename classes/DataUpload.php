<?php

namespace WooCommerceCustobar;

defined('ABSPATH') or exit;

use WooCommerceCustobar\Synchronization\ProductSync;
use WooCommerceCustobar\Synchronization\CustomerSync;
use WooCommerceCustobar\Synchronization\SaleSync;

/**
 * Class DataUpload
 *
 * @package WooCommerceCustobar
 */
class DataUpload {

  public static function uploadCustobarData($endpoint, $data) {

    $responseData = new \stdClass;

    $body = json_encode($data);
    $apiToken = \WC_Admin_Settings::get_option( 'custobar_api_setting_token', false );
    $companyDomain = \WC_Admin_Settings::get_option( 'custobar_api_setting_company', false );
    $url = sprintf('https://%s.custobar.com/api', $companyDomain) . $endpoint;

    $response = wp_remote_request($url, array(
      'method' => 'PUT',
      'headers' => array(
        'Content-Type'  => 'application/json',
        'Authorization' => 'Token ' . $apiToken
      ),
      'body' => $body
    ));

    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);

    // form response data
    $responseData->code = $response_code;
    $responseData->body = $response_body;

    // do wc logging
    if (!in_array($response_code, array(200, 201)) || is_wp_error($response_body)) {
      wc_get_logger()->warning('Custobar data upload failed', array(
        'source'        => 'woocommerce-custobar',
        'response_code' => $response_code,
        'response_body' => $response_body,
      ));
    } else {
      wc_get_logger()->info('Sent request to Custobar API', array(
        'source'        => 'woocommerce-custobar',
        'response_code' => $response_code,
        'response_body' => $response_body,
      ));
    }

    // return response
    return $responseData;

  }

  public static function addHooks() {
    add_action( 'wp_ajax_custobar_export', __CLASS__ . '::jxExport' );
  }

  public static function jxExport() {

    // environment checks
    $plugin = new Plugin();
    if ($plugin::isWooCommerceActived() && $plugin::hasAllSettingsDefined()) {

      $statProducts = self::fetchSyncStatProducts();
      $statSales = self::fetchSyncStatSales();

      if( $statProducts->total != $statProducts->synced ) {
        $apiResponse = CustomerSync::batchUpdate();
      } elseif( $statSales->total != $statSales->synced ) {
        $apiResponse = SaleSync::batchUpdate();
      } else {
        $apiResponse = ProductSync::batchUpdate();
      }

    }

    if( $apiResponse->code == 200 ) {
      $message = "Export to Custobar successful.";
    } else {
      $message = "No WooCommerce records available to export.";
    }

    $response = array(
      'code'    => $apiResponse->code,
      'body'    => $apiResponse->body,
      'message' => $message
    );
    print json_encode( $response );

    wp_die();

  }

  public function fetchSyncStatProducts() {

    $stat = new \stdClass;

    $products = wc_get_products(array('limit' => -1));
    $stat->total = count( $products );

    $productSyncTracker = ProductSync::trackerFetch();
    $stat->synced = count( $productSyncTracker );

    return $stat;

  }

  public function fetchSyncStatSales() {

    $stat = new \stdClass;

    $orders = \wc_get_orders(array(
      'posts_per_page' => -1,
      'orderby'        => 'date',
      'order'          => 'ASC',
    ));
    $salesCount = 0;
    foreach ($orders as $order) {
      foreach ($order->get_items() as $order_item) {
        $salesCount++;
      }
    }
    $stat->total = $salesCount;

    $tracker = SaleSync::trackerFetch();
    $stat->synced = count( $tracker );

    return $stat;

  }

  public function fetchSyncStatCustomers() {

    $stat = new \stdClass;
    $tracker = CustomerSync::trackerFetch();

    $orders = \wc_get_orders(array(
      'posts_per_page' => -1,
      'orderby'        => 'date',
      'order'          => 'ASC',
    ));
    $customerIds = [];
    foreach ($orders as $order) {
      $customerIds[] = $order->get_user_id();
    }
    $customerIds = array_unique( $customerIds );

    $stat->total = count( $customerIds );
    $stat->synced = count( $tracker['data'] );
    $stat->updated = $tracker['updated'];

    return $stat;

  }

}
