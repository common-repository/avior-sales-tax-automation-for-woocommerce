<?php
/**
 * Plugin Name: Avior - Sales Tax Automation for WooCommerce
 * Description: Avior SutTax offers sales tax determination web service to retailers. With SutTax Woocommerce Plugin, retailers are able to add accurate sales tax to their invoices.
 * Author: Avior
 * Author URI: https://suttax.avior.tax
 * Version: 1.0.0
 *
 */

/**
 * Prevent direct access to script
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_Avior' ) ) :

	class WC_Avior {
		/**
		 * Construct the plugin.
		 */
		public function __construct() {
			add_action( 'plugins_loaded', array( $this, 'init' ) );

			register_activation_hook( __FILE__, array( 'WC_Avior', 'plugin_registration_hook' ) );
		}

		/**
		 * Initialize the plugin.
		 */
		public function init() {
			global $woocommerce;

			// Checks if WooCommerce is installed.
			if ( class_exists( 'WC_Integration' ) ) {
				// Include our integration class and WP_User for wp_delete_user()
				include_once ABSPATH . 'wp-admin/includes/user.php';
				include_once 'includes/class-wc-avior-integration.php';

				// Register the integration.
				add_filter( 'woocommerce_integrations', array( $this, 'add_integration' ), 20 );

			}
		}

		/**
		 * Add a new integration to WooCommerce.
		 */
		public function add_integration( $integrations ) {
			$integrations[] = 'WC_Avior_Integration';

			return $integrations;
		}

		/**
		 * Run on plugin activation
		 */
		public static function plugin_registration_hook() {
			// Avior requires at least version 5.3 of PHP
			if ( version_compare( PHP_VERSION, '5.3', '<' ) ) {
				exit( sprintf( '<strong>Avior requires PHP 5.3 or higher. You are currently using %s.</strong>', PHP_VERSION ) );
			}

			// WooCommerce must be activated for Avior to activate
			if ( ! class_exists( 'Woocommerce' ) ) {
				exit( '<strong>Please activate WooCommerce before activating Avior.</strong>' );
			}

			global $wpdb;

			// Clear all transients
			wc_delete_product_transients();
			wc_delete_shop_order_transients();
			WC_Cache_Helper::get_transient_version( 'shipping', true );

			// Clear all expired transients
			/*
			 * Deletes all expired transients. The multi-table delete syntax is used
			 * to delete the transient record from table a, and the corresponding
			 * transient_timeout record from table b.
			 *
			 * Based on code inside core's upgrade_network() function.
			 */
			$wpdb->query( $wpdb->prepare( "DELETE a, b FROM $wpdb->options a, $wpdb->options b
    	WHERE a.option_name LIKE %s
    	AND a.option_name NOT LIKE %s
    	AND b.option_name = CONCAT( '_transient_timeout_', SUBSTRING( a.option_name, 12 ) )
    	AND b.option_value < %d", $wpdb->esc_like( '_transient_' ) . '%', $wpdb->esc_like( '_transient_timeout_' ) . '%', time() ) );

			$wpdb->query( $wpdb->prepare( "DELETE a, b FROM $wpdb->options a, $wpdb->options b
    	WHERE a.option_name LIKE %s
    	AND a.option_name NOT LIKE %s
    	AND b.option_name = CONCAT( '_site_transient_timeout_', SUBSTRING( a.option_name, 17 ) )
    	AND b.option_value < %d", $wpdb->esc_like( '_site_transient_' ) . '%', $wpdb->esc_like( '_site_transient_timeout_' ) . '%', time() ) );

			// Export Tax Rates
			$rates = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates
        ORDER BY tax_rate_order
        LIMIT %d, %d
        ",
				0,
				10000
			) );

			ob_start();
			$header =
				__( 'Country Code', 'woocommerce' ) . ',' .
				__( 'State Code', 'woocommerce' ) . ',' .
				__( 'ZIP/Postcode', 'woocommerce' ) . ',' .
				__( 'City', 'woocommerce' ) . ',' .
				__( 'Rate %', 'woocommerce' ) . ',' .
				__( 'Tax Name', 'woocommerce' ) . ',' .
				__( 'Priority', 'woocommerce' ) . ',' .
				__( 'Compound', 'woocommerce' ) . ',' .
				__( 'Shipping', 'woocommerce' ) . ',' .
				__( 'Tax Class', 'woocommerce' ) . "\n";

			echo esc_html( $header );

			foreach ( $rates as $rate ) {
				if ( $rate->tax_rate_country ) {
					echo esc_attr( $rate->tax_rate_country );
				} else {
					echo '*';
				}

				echo ',';

				if ( $rate->tax_rate_country ) {
					echo esc_attr( $rate->tax_rate_state );
				} else {
					echo '*';
				}

				echo ',';

				$locations = $wpdb->get_col( $wpdb->prepare( "SELECT location_code FROM {$wpdb->prefix}woocommerce_tax_rate_locations WHERE location_type='postcode' AND tax_rate_id = %d ORDER BY location_code", $rate->tax_rate_id ) );

				if ( $locations ) {
					echo esc_attr( implode( '; ', $locations ) );
				} else {
					echo '*';
				}

				echo ',';

				$locations = $wpdb->get_col( $wpdb->prepare( "SELECT location_code FROM {$wpdb->prefix}woocommerce_tax_rate_locations WHERE location_type='city' AND tax_rate_id = %d ORDER BY location_code", $rate->tax_rate_id ) );
				if ( $locations ) {
					echo esc_attr( implode( '; ', $locations ) );
				} else {
					echo '*';
				}

				echo ',';

				if ( $rate->tax_rate ) {
					echo esc_attr( $rate->tax_rate );
				} else {
					echo '0';
				}

				echo ',';

				if ( $rate->tax_rate_name ) {
					echo esc_attr( $rate->tax_rate_name );
				} else {
					echo '*';
				}

				echo ',';

				if ( $rate->tax_rate_priority ) {
					echo esc_attr( $rate->tax_rate_priority );
				} else {
					echo '1';
				}

				echo ',';

				if ( $rate->tax_rate_compound ) {
					echo esc_attr( $rate->tax_rate_compound );
				} else {
					echo '0';
				}

				echo ',';

				if ( $rate->tax_rate_shipping ) {
					echo esc_attr( $rate->tax_rate_shipping );
				} else {
					echo '0';
				}

				echo ',';

				echo "\n";

			}

			$csv = ob_get_contents();
			ob_end_clean();
			$upload_dir = wp_upload_dir();
			file_put_contents( $upload_dir['basedir'] . '/avior-wc_tax_rates-' . gmdate( 'm-d-Y' ) . '-' . time() . '.csv', $csv );

			// Delete All tax rates
			$wpdb->query( 'TRUNCATE ' . $wpdb->prefix . 'woocommerce_tax_rates' );
			$wpdb->query( 'TRUNCATE ' . $wpdb->prefix . 'woocommerce_tax_rate_locations' );

		}
	}

	/**
	 * Adds settings link to the plugins page
	 */
	function plugin_settings_link( $links ) {
		$settings_link = '<a href="admin.php?page=wc-settings&tab=integration&section=avior-integration">Settings</a>';
		array_unshift( $links, $settings_link );

		return $links;
	}

	add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'plugin_settings_link' );

	add_filter( 'woocommerce_billing_fields', 'county_woocommerce_address_fields' );
	add_filter( 'woocommerce_shipping_fields', 'county_woocommerce_address_fields' );
	add_filter( 'woocommerce_admin_billing_fields', 'county_woocommerce_admin_billing_fields' );
	add_filter( 'woocommerce_admin_shipping_fields', 'county_woocommerce_admin_shipping_fields' );

	function county_woocommerce_address_fields( $fields ) {
		$splitIndex = array_search( 'billing_state', array_keys( $fields ) );
		if ( false == $splitIndex ) {
			$splitIndex = array_search( 'shipping_state', array_keys( $fields ) );

			$fields = array_merge(
				array_slice( $fields, 0, $splitIndex ),
				[
					'shipping_county' => [
						'label'    => __( 'County', 'woocommerce' ),
						'required' => false,
						'type'     => 'text',
						'class'    => [
							'form-row-wide',
							'address-field'
						]
					]
				],
				array_slice( $fields, $splitIndex )
			);
		} else {
			$fields = array_merge(
				array_slice( $fields, 0, $splitIndex ),
				[
					'billing_county' => [
						'label'    => __( 'County', 'woocommerce' ),
						'required' => false,
						'type'     => 'text',
						'class'    => [
							'form-row-wide',
							'address-field'
						]
					]
				],
				array_slice( $fields, $splitIndex )
			);
		}

		return $fields;
	}

	function county_woocommerce_admin_billing_fields( $fields ) {
		$splitIndex = array_search( 'state', array_keys( $fields ) );

		$fields = array_merge(
			array_slice( $fields, 0, $splitIndex ),
			[
				'county' => [
					'label' => __( 'County', 'woocommerce' )
				]
			],
			array_slice( $fields, $splitIndex )
		);

		return $fields;
	}

	function county_woocommerce_admin_shipping_fields( $fields ) {
		$splitIndex = array_search( 'state', array_keys( $fields ) );
		$fields     = array_merge(
			array_slice( $fields, 0, $splitIndex ),
			[
				'county' => [
					'label' => __( 'County', 'woocommerce' )
				]
			],
			array_slice( $fields, $splitIndex )
		);

		return $fields;
	}

	add_filter( 'woocommerce_order_get_billing', 'county_woocommerce_get_billing_address_fields' );
	function county_woocommerce_get_billing_address_fields( $fields ) {
		if ( isset( $_GET['order-received'] ) ) {
			$order      = wc_get_order( sanitize_text_field( $_GET['order-received'] ) );
			$splitIndex = array_search( 'state', array_keys( $fields ) );

			$fields = array_merge(
				array_slice( $fields, 0, $splitIndex ),
				[
					'county' => $order->get_meta( '_billing_county' )
				],
				array_slice( $fields, $splitIndex )
			);
		}
		$_SERVER['addr_type_tmp'] = 'billing';

		return $fields;
	}

	add_filter( 'woocommerce_order_get_shipping', 'county_woocommerce_get_shipping_address_fields' );
	function county_woocommerce_get_shipping_address_fields( $fields ) {
		if ( isset( $_GET['order-received'] ) ) {
			$order      = wc_get_order( sanitize_text_field( $_GET['order-received'] ) );
			$splitIndex = array_search( 'state', array_keys( $fields ) );

			$fields = array_merge(
				array_slice( $fields, 0, $splitIndex ),
				[
					'county' => $order->get_meta( '_shipping_county' )
				],
				array_slice( $fields, $splitIndex )
			);
		}
		$_SERVER['addr_type_tmp'] = 'shipping';

		return $fields;
	}

	add_filter( 'woocommerce_localisation_address_formats', 'woocommerce_custom_address_format', 20 );

	function woocommerce_custom_address_format( $formats ) {
		$formats['US']      = "{name}\n{company}\n{address_1}\n{address_2}\n{city}, {county}, {state_code} {postcode}\n{country}";
		$formats['default'] = "{name}\n{company}\n{address_1}\n{address_2}\n{city}, {county}, {state_code} {postcode}\n{country}";

		return $formats;
	}

	add_filter( 'woocommerce_formatted_address_replacements', 'avior_woocommerce_formatted_address_replacements', 20 );
//	add_filter( 'woocommerce_formatted_address_replacements', 'avior_woocommerce_formatted_address_replacements', 20 );
	add_filter( 'woocommerce_my_account_my_address_formatted_address', function ( $args, $customer_id, $name ) {
		if ( 'shipping' == $name ) {
			$_SERVER['addr_type_tmp'] = 'shipping';
		} else {
			$_SERVER['addr_type_tmp'] = 'billing';
		}

		return $args;
	}, 10, 3 );
	function avior_woocommerce_formatted_address_replacements( $formats ) {
		if ( isset( $_GET['order-received'] ) ) {
			$order = wc_get_order( sanitize_text_field( $_GET['order-received'] ) );
			if ( isset( $_SERVER['addr_type_tmp'] ) && 'billing' == $_SERVER['addr_type_tmp'] ) {
				$formats['{county}'] = $order->get_meta( '_billing_county' );
			} else {
				$formats['{county}'] = $order->get_meta( '_shipping_county' );
			}
		} elseif ( isset( $_GET['view-order'] ) ) {
			$order = wc_get_order( sanitize_text_field( $_GET['view-order'] ) );
			if ( isset( $_SERVER['addr_type_tmp'] ) && 'billing' == $_SERVER['addr_type_tmp'] ) {
				$formats['{county}'] = $order->get_meta( '_billing_county' );
			} else {
				$formats['{county}'] = $order->get_meta( '_shipping_county' );
			}
		} elseif ( isset( $_GET['edit-address'] ) ) {
			$customer_id = get_current_user_id();
			if ( isset( $_SERVER['addr_type_tmp'] ) && 'billing' == $_SERVER['addr_type_tmp'] ) {
				$formats['{county}'] = @get_user_meta( $customer_id, 'billing_county' )[0];
			} else {
				$formats['{county}'] = @get_user_meta( $customer_id, 'shipping_county' )[0];
			}
		} elseif ( isset( $_SERVER['tmp_order_id_billing'] ) ) {
			$order               = wc_get_order( sanitize_text_field( $_SERVER['tmp_order_id_billing'] ) );
			$formats['{county}'] = $order->get_meta( '_billing_county' );
			unset( $_SERVER['tmp_order_id_billing'] );
		} elseif ( isset( $_SERVER['tmp_order_id_shipping'] ) ) {
			$order               = wc_get_order( sanitize_text_field( $_SERVER['tmp_order_id_shipping'] ) );
			$formats['{county}'] = $order->get_meta( '_shipping_county' );
			unset( $_SERVER['tmp_order_id_shipping'] );
		} else {
			$formats['{county}'] = null;
		}

		return $formats;
	}

	add_filter( 'woocommerce_order_formatted_billing_address', function ( $args, $order ) {
		$args['county']                  = $order->get_meta( '_billing_county' );
		$_SERVER['tmp_order_id_billing'] = $order->get_id();

		return $args;
	}, 10, 3 );

	add_filter( 'woocommerce_order_formatted_shipping_address', function ( $args, $order ) {
		$args['county']                   = $order->get_meta( '_shipping_county' );
		$_SERVER['tmp_order_id_shipping'] = $order->get_id();

		return $args;
	}, 10, 3 );
	$WC_Avior = new WC_Avior( __FILE__ );

endif;
