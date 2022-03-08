<?php

namespace WooCommerceCustobar;

defined( 'ABSPATH' ) || exit;

use WC_Settings_Page;
use WC_Admin_Settings;

/**
 * Class Settings
 *
 * @package WooCommerceCustobar
 */
class WC_Settings_Custobar extends WC_Settings_Page {

	/**
	 * Product fields setting id
	 */
	const PRODUCT_FIELDS = 'custobar_product_fields';

	/**
	 * Customer fields setting id
	 */
	const CUSTOMER_FIELDS = 'custobar_customer_fields';

	/**
	 * Sale fields setting id
	 */
	const SALE_FIELDS = 'custobar_sale_fields';

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 */
	public function __construct() {

		add_action( 'admin_enqueue_scripts', array( $this, 'scripts' ) );

		$this->id    = 'custobar';
		$this->label = __( 'Custobar', 'woocommerce-custobar' );

		parent::__construct();

	}

	public function get_sections() {

		return array(
			''       => __( 'Data Syncronization', 'woocommerce-custobar' ),
			'fields' => __( 'Field Settings', 'woocommerce-custobar' ),
			'api'    => __( 'API Settings', 'woocommerce-custobar' ),
		);

	}

	/**
	 * Uses the WooCommerce options API to save settings via the @see woocommerce_update_options() function.
	 *
	 * @uses woocommerce_update_options()
	 * @uses self::get_settings_api()
	 */
	public function save() {
		// _$POST object is used directly by WC_Admin_Settings::save_fields()
		$data = $_POST; //phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( empty( $data ) ) {
			return false;
		}
		// Regenerate custobar_wc_rest_api_secret if it does not exist or if reset requested by user.
		if ( isset( $data['custobar_wc_rest_api_secret'] ) && ( ! $data['custobar_wc_rest_api_secret'] || ! empty( $data['custobar_wc_rest_api_secret_reset'] ) ) ) {
			$data['custobar_wc_rest_api_secret'] = $this->generate_secret_key();
			// Unset reset checkbox. We don't need to (and should not) save it.
			unset( $data['custobar_wc_rest_api_secret_reset'] );
		}

		global $current_section;

		if ( 'api' == $current_section ) {
			woocommerce_update_options( $this->get_settings_api(), $data );
			woocommerce_update_options( $this->get_settings_marketing(), $data );
			return;
		}
		if ( 'fields' == $current_section ) {
			woocommerce_update_options( $this->get_settings_fields(), $data );
			return;
		} else {
			woocommerce_update_options( $this->get_settings_export(), $data );
		}
	}

	/**
	 * Get API settings
	 *
	 * @see woocommerce_admin_fields() function.
	 * @return array Array of settings
	 */
	public function get_settings_api() {

		$settings = array(
			'custobar_api_settings'          => array(
				'name' => __( 'Custobar API Settings', 'woocommerce-custobar' ),
				'type' => 'title',
				'desc' => '',
				'id'   => 'custobar_api_settings',
			),
			'custobar_api_token'             => array(
				'name' => __( 'API Token', 'woocommerce-custobar' ),
				'type' => 'password',
				'desc' => __( 'Enter your API token.', 'woocommerce-custobar' ),
				'id'   => 'custobar_api_setting_token',
			),
			'custobar_api_company'           => array(
				'name' => __( 'API url', 'woocommerce-custobar' ),
				'type' => 'text',
				'desc' => __( 'Enter the full API url, eg. "https://COMPANY.custobar.com/api" ', 'woocommerce-custobar' ),
				'id'   => 'custobar_api_setting_company',
			),
			'custobar_rest_api_secret'       => array(
				'name'              => __( 'Webhook Secret Key', 'woocommerce-custobar' ),
				'type'              => 'text',
				'desc'              => __( 'Use this value for the Authorization header when configuring webhooks in Custobar.', 'woocommerce-custobar' ),
				'id'                => 'custobar_wc_rest_api_secret',
				'custom_attributes' => array(
					'readonly' => 'readonly',
				),
			),
			'custobar_rest_api_secret_reset' => array(
				'name' => __( 'Reset Secret Key', 'woocommerce-custobar' ),
				'type' => 'checkbox',
				'desc' => __( 'Check this box to reset webhook secret key.', 'woocommerce-custobar' ),
				'id'   => 'custobar_wc_rest_api_secret_reset',
			),
			'custobar_api_extended_logging' => array(
				'name' => __( 'Enable extended logging', 'woocommerce-custobar' ),
				'type' => 'checkbox',
				'desc' => __( 'Check this box to enable extended logging. The extended logging should be only enabled for debugging purposes.', 'woocommerce-custobar' ),
				'id'   => 'custobar_wc_api_extended_logging',
			),
			'section_end'                    => array(
				'type' => 'sectionend',
				'id'   => 'custobar_section_end',
			),
		);

		return $settings;

	}

	/**
	 * Get field map settings
	 *
	 * @see woocommerce_admin_fields() function.
	 * @return array Array of settings
	 */
	public function get_settings_fields() {

		$settings = array(
			'custobar_field_map_title' => array(
				'name' => __( 'Field Mapping', 'woocommerce-custobar' ),
				'type' => 'title',
			),
			self::CUSTOMER_FIELDS      => array(
				'name'              => __( 'Customer Field Map', 'woocommerce-custobar' ),
				'type'              => 'textarea',
				'desc'              => '',
				'custom_attributes' => array(
					'rows'     => 8,
					'readonly' => 'readonly',
				),
				'class'             => 'input-text wide-input',
				'id'                => self::CUSTOMER_FIELDS,
			),
			self::PRODUCT_FIELDS       => array(
				'name'              => __( 'Product Field Map', 'woocommerce-custobar' ),
				'type'              => 'textarea',
				'desc'              => '',
				'custom_attributes' => array(
					'rows'     => 8,
					'readonly' => 'readonly',
				),
				'class'             => 'input-text wide-input',
				'id'                => self::PRODUCT_FIELDS,
			),
			self::SALE_FIELDS          => array(
				'name'              => __( 'Sale Field Map', 'woocommerce-custobar' ),
				'type'              => 'textarea',
				'desc'              => '',
				'custom_attributes' => array(
					'rows'     => 8,
					'readonly' => 'readonly',
				),
				'class'             => 'input-text wide-input',
				'id'                => self::SALE_FIELDS,
			),
			'section_end'              => array(
				'type' => 'sectionend',
				'id'   => 'custobar_section_end',
			),
		);

		return $settings;

	}

	/**
	 * Get marketing settings
	 *
	 * @see woocommerce_admin_fields() function.
	 * @return array Array of settings
	 */
	public function get_settings_marketing() {

		$settings = array(
			'custobar_marketing_settings' => array(
				'name' => __( 'Marketing Settings', 'woocommerce-custobar' ),
				'type' => 'title',
				'desc' => '',
				'id'   => 'custobar_marketing_settings',
			),
			'section_end'                 => array(
				'type' => 'sectionend',
				'id'   => 'custobar_section_end',
			),
		);

		return $settings;

	}

	/**
	 * Get export settings
	 *
	 * @see woocommerce_admin_fields() function.
	 * @return array Array of settings
	 */
	public function get_settings_export() {

		$settings = array(
			'custobar_export_settings'        => array(
				'name' => __( 'Export Settings', 'woocommerce-custobar' ),
				'type' => 'title',
				'desc' => '',
				'id'   => 'custobar_export_settings',
			),
			'custobar_export_force_can_sms'   => array(
				'name' => __( 'Allow sms marketing for all customers.', 'woocommerce-custobar' ),
				'type' => 'checkbox',
				'desc' => __( 'By checking this box sms marketing will be allowed for all customers exported via the export tool above.', 'woocommerce-custobar' ),
				'id'   => 'custobar_export_force_can_sms',
			),
			'custobar_export_force_can_email' => array(
				'name' => __( 'Allow email marketing for all customers.', 'woocommerce-custobar' ),
				'type' => 'checkbox',
				'desc' => __( 'By checking this box email marketing will be allowed for all customers exported via the export tool above.', 'woocommerce-custobar' ),
				'id'   => 'custobar_export_force_can_email',
			),
			'section_end'                     => array(
				'type' => 'sectionend',
				'id'   => 'custobar_section_end',
			),
		);

		return $settings;

	}

	public function output() {

		global $current_section, $hide_save_button;

		print '<div id="custobar-settings">';

		if ( '' === $current_section ) {

			$data_upload = new Data_Upload();
			$template    = new Template();

			$template->name = 'sync-report';
			print $template->get();  // @codingStandardsIgnoreLine

			WC_Admin_Settings::output_fields( $this->get_settings_export() );

		} elseif ( 'api' === $current_section ) {

			$template       = new Template();
			$template->name = 'api-test';
			$template->data = array();
			print $template->get();  // @codingStandardsIgnoreLine

			WC_Admin_Settings::output_fields( $this->get_settings_api() );

		} else {

			WC_Admin_Settings::output_fields( $this->get_settings_marketing() );

			WC_Admin_Settings::output_fields( $this->get_settings_fields() );

			$this->output_action_buttons();
		}

		print '</div>'; // close settings wrap

	}

	protected function output_action_buttons() {
		?>
	<div id="fields-action">
		<button type="button" class="button button-lock" data-tip="<?php esc_attr_e( 'Click here to edit fields map', 'woocommerce-custobar' ); ?>"><span class="dashicons dashicons-lock"></span></button>
		<button type="button" class="button button-restore" data-tip="<?php esc_attr_e( 'Restore to default fields map', 'woocommerce-custobar' ); ?>"><span class="dashicons dashicons-undo"></span></button>
	</div>
		<?php
	}

	public function scripts() {

		wp_enqueue_script(
			'custobar-admin-js',
			WOOCOMMERCE_CUSTOBAR_URL . 'assets/custobar.admin.js',
			array( 'jquery' ),
			WOOCOMMERCE_CUSTOBAR_VERSION,
			true
		);

		wp_localize_script(
			'custobar-admin-js',
			'Custobar',
			array(
				'fields_map' => Fields_Map::get_fields_map_for_front(),
			)
		);

		wp_enqueue_style(
			'custobar-admin-style',
			WOOCOMMERCE_CUSTOBAR_URL . 'assets/custobar.admin.css',
			array(),
			WOOCOMMERCE_CUSTOBAR_VERSION
		);

	}

	/**
	 * Generates a Random String used as a secret key for inbound REST Api requests
	 *
	 * @return string
	 */
	protected function generate_secret_key() {
		$length            = 32;
		$characters        = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$characters_length = strlen( $characters );
		$random_string     = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$random_string .= $characters[ wp_rand( 0, $characters_length - 1 ) ];
		}
		return $random_string;
	}
}
