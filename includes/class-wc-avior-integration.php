<?php
/**
 * Integration Avior
 *
 * @package  WC_Avior_Integration
 */

if ( ! class_exists( 'WC_Avior_Integration' ) ) :

	class WC_Avior_Integration extends WC_Integration {
		const ENDPOINT_LOGIN     = '/api/auth/token/login';
		const ENDPOINT_FETCH_TAX = '/suttaxd/gettax/';

		/**
		 * Init and hook in the integration.
		 */
		public function __construct() {
			$this->id                 = 'avior-integration';
			$this->method_title       = __( 'Avior Suttax Integration', 'wc-avior' );
			$this->method_description = __( 'Avior SutTax offers sales tax determination web service to retailers. With SutTax Woocommerce Plugin, retailers are able to add accurate sales tax to their invoices.
Avior is tax compliance software provider for tobacco, alcohol, motor fuel, and other industries.
NOTE: The Extension currently only support US based addresses.

To get more pricing info and sign up with us contact:
E-mail: sales@avior.tax
Phone Number: +1 (972)-535-4506 
', 'wc-avior' );
			// Load the settings.
			$this->init_settings();

			// Define user set variables.
			$this->enabled      = filter_var( $this->get_option( 'enabled' ), FILTER_VALIDATE_BOOLEAN );
			$this->is_connected = filter_var( $this->get_option( 'is_connected' ), FILTER_VALIDATE_BOOLEAN );
			$this->username     = $this->get_option( 'username' );
			$this->password     = $this->get_option( 'password' );
			$this->seller_id    = $this->get_option( 'seller_id' );
			$this->endpoint     = $this->get_option( 'endpoint' );
			$this->api_token    = $this->get_option( 'api_token' );
			$this->debug        = filter_var( $this->get_option( 'debug' ), FILTER_VALIDATE_BOOLEAN );

			// Build the Admin Form
			$this->init_form_fields();

			// Catch Rates for 1 hour
			$this->cache_time = HOUR_IN_SECONDS;

			// Avior Config Integration Tab
			add_action( 'woocommerce_update_options_integration_' . $this->id, array(
				$this,
				'process_admin_options'
			) );
			add_action( 'admin_menu', array( $this, 'avior_admin_menu' ), 15 );

			if ( ( 'yes' == $this->settings['enabled'] ) ) {
				// Calculate Taxes
//				add_action( 'woocommerce_calculate_totals', array( $this, 'use_avior_total' ), 20 );
				add_action( 'woocommerce_after_calculate_totals', array( $this, 'use_avior_total' ), 20 );
				add_action( 'woocommerce_checkout_create_order', array( $this, 'use_avior_total' ), 10, 1 );

				add_action( 'woocommerce_before_checkout_process', array( $this, 'avior_error_check' ), 9999, 2 );

				// admin
				add_action( 'woocommerce_order_after_calculate_totals', array(
					$this,
					'use_avior_total_order'
				), 20, 2 );

//				add_filter( 'woocommerce_ajax_calc_line_taxes', array( $this, 'admin_ajax_calculate_taxes' ), 1, 4 );

				// Settings Page
				add_action( 'woocommerce_sections_tax', array( $this, 'output_sections_before' ), 9 );

				add_action( 'woocommerce_sections_tax', array( $this, 'output_sections_after' ), 11 );

				// If Avior is enabled and a user disables taxes we renable them
				update_option( 'woocommerce_calc_taxes', 'yes' );

				// Users can set either billing or shipping address for tax rates but not shop
				update_option( 'woocommerce_tax_based_on', 'shipping' );

				// Rate calculations assume tax not inlcuded
				update_option( 'woocommerce_prices_include_tax', 'no' );

				// Don't ever set a default customer address
				update_option( 'woocommerce_default_customer_address', '' );

				// Use no special handling on shipping taxes, our API handles that
				update_option( 'woocommerce_shipping_tax_class', '' );

				// API handles rounding precision
				update_option( 'woocommerce_tax_round_at_subtotal', 'no' );

				// Rates are calculated in the cart assuming tax not included
				update_option( 'woocommerce_tax_display_shop', 'excl' );

				// Avior returns one total amount, not line item amounts
				update_option( 'woocommerce_tax_display_cart', 'excl' );

				// Avior returns one total amount, not line item amounts
				update_option( 'woocommerce_tax_total_display', 'single' );

			}
		}

		/**
		 * Initialize integration settings form fields.
		 *
		 * @return void
		 */
		// fix undefined offset for country not set...
		public function init_form_fields() {
			if ( is_admin() && isset( $_GET['page'] ) && 'wc-settings' == $_GET['page'] && isset( $_GET['section'] ) && 'avior-integration' == $_GET['section'] ) {
				$this->loginAvior();
			}
			if ( $this->get_option( 'is_connected' ) ) {
				$text = '<span class="grid-severity-notice" style="width: 30%;padding: 4px 0;"><span>' . __( 'Connected' ) . '</span></span>
    <ipp:blueDot></ipp:blueDot>';
			} else {
				$text = '<span class="grid-severity-critical"
          style="width: 35%;padding: 4px 0;"><span>' . __( 'Not Connected' ) . '</span></span>';
			}
			// Build the form array
			$this->form_fields = array(
				'enabled'      => array(
					'title'       => __( 'Sales Tax Calculation', 'wc-avior' ),
					'type'        => 'checkbox',
					'label'       => __( 'Enable Avior Calculations', 'wc-avior' ),
					'default'     => 'no',
					'description' => __( 'If enabled, Avior will calculate all sales tax for your store.', 'wc-avior' ),
				),
				'is_connected' => array(
					'title'       => __( 'Connection Status', 'wc-avior' ),
					'type'        => 'hidden',
					'description' => $text,
					'class'       => 'input-text disabled regular-input',
					'disabled'    => 'disabled',
				),

				'username'  => array(
					'title' => __( 'Username', 'wc-avior' ),
					'type'  => 'text'
				),
				'password'  => array(
					'title' => __( 'Password', 'wc-avior' ),
					'type'  => 'text'
				),
				'seller_id' => array(
					'title' => __( 'Seller Id', 'wc-avior' ),
					'type'  => 'text'
				),
				'endpoint'  => array(
					'title' => __( 'Endpoint', 'wc-avior' ),
					'type'  => 'text'
				),
				'debug'     => array(
					'title'       => __( 'Debug Log', 'wc-avior' ),
					'type'        => 'checkbox',
					'label'       => __( 'Enable logging', 'wc-avior' ),
					'default'     => 'no',
					'description' => __( 'Log events such as API requests.', 'wc-avior' ),
				),
				'api_token' => array(
					'title'    => '',
					'type'     => 'hidden',
					'class'    => 'input-text disabled regular-input',
					'disabled' => 'disabled'
				)
			);
		}

		private function loginAvior() {
			try {
				if ( isset( $_REQUEST['save'] ) ) {
					$this->username  = isset( $_REQUEST['woocommerce_avior-integration_username'] ) ? sanitize_text_field( $_REQUEST['woocommerce_avior-integration_username'] ) : '';
					$this->password  = isset( $_REQUEST['woocommerce_avior-integration_password'] ) ? sanitize_text_field( $_REQUEST['woocommerce_avior-integration_password'] ) : '';
					$this->seller_id = isset( $_REQUEST['woocommerce_avior-integration_seller_id'] ) ? sanitize_text_field( $_REQUEST['woocommerce_avior-integration_seller_id'] ) : '';
					$this->endpoint  = isset( $_REQUEST['woocommerce_avior-integration_endpoint'] ) ? sanitize_text_field( $_REQUEST['woocommerce_avior-integration_endpoint'] ) : '';
					$this->debug     = isset( $_REQUEST['woocommerce_avior-integration_debug'] ) ? sanitize_text_field( $_REQUEST['woocommerce_avior-integration_debug'] ) : '';
				}
				$data     = array(
					'username' => $this->username,
					'password' => $this->password
				);
				$url      = $this->endpoint . self::ENDPOINT_LOGIN;
				$response = $this->postData( $data, $url );
				$token    = @$response['auth_token'];
				if ( empty( $token ) ) {
					$this->update_option( 'is_connected', 0 );
					$this->update_option( 'api_token', null );
				} else {
					$this->update_option( 'is_connected', 1 );
					$this->update_option( 'api_token', $token );
				}

				$this->_log( '--- login ---' );
				$this->_log( 'Login Request: ' . json_encode( $data ) );
				$this->_log( 'Login Response: ' . $token );
				$this->_log( '--- login end ---' );

			} catch ( Exception $exception ) {
				$this->update_option( 'is_connected', 0 );
				$this->update_option( 'api_token', null );

				$this->_log( '--- login EXCEPTION---' );
				$this->_log( 'Login Request: ' . json_encode( $data ) );
				$this->_log( 'Exception: ' . $exception->getMessage() );
				$this->_log( '--- login end ---' );
			}
		}

		private function postData( $data, $url, $needToken = false ) {

			$http = new WP_Http();

			$options = array(
				'method'  => 'POST',
				'body'    => json_encode( $data ),
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
			);
			if ( $needToken ) {
				$options['headers']['Authorization'] = 'Token ' . $this->api_token;
			}
			$response = $http->request( $url, $options );
			$result   = wp_remote_retrieve_body( $response );

			$response = json_decode( $result, true );
			if ( is_null( $response ) ) {
				$response = array();
			}

			return $response;
		}

		private function _log( $message ) {
			if ( WP_DEBUG === true ) {
				if ( is_array( $message ) || is_object( $message ) ) {
					error_log( print_r( $message, true ) );
				} else {
					error_log( $message );
				}
			}
			if ( $this->debug ) {
				$this->log = new WC_Logger();
				if ( is_array( $message ) || is_object( $message ) ) {
					$this->log->add( 'avior', print_r( $message, true ) );
				} else {
					$this->log->add( 'avior', $message );
				}
			}
		}

		public function use_avior_total( $wc_cart_object ) {
			if ( is_checkout() && ( 'checkout' == ( isset( $_REQUEST['wc-ajax'] ) ? sanitize_text_field( $_REQUEST['wc-ajax'] ) : '' ) || ( 'update_order_review' == ( isset( $_REQUEST['wc-ajax'] ) ? sanitize_text_field( $_REQUEST['wc-ajax'] ) : '' ) && isset( $_REQUEST['post_data'] ) ) ) ) {
				try {
					$commonData = $this->getCommonData( $wc_cart_object );
					$data       = $this->getLineItems( $wc_cart_object, $commonData );

					if ( ( $this->validateRequest( $data ) ) === true ) {
						$url      = $this->endpoint . self::ENDPOINT_FETCH_TAX;
						$response = $this->postData( $data, $url, true );

						$this->_log( '--- fetchTax ---' );
						$this->_log( 'fetchTax Request: ' . json_encode( $data ) );
						$this->_log( 'fetchTax Response: ' . json_encode( $response ) );

						if ( ! $response || ! $this->validateResponse( $response, $data ) ) {
							$this->_log( 'INVALIDATE RESPONSE' );

							$wc_cart_object->tax_total = 0;
//							$this->setErrorMsg( 'Please enter the correct address for the best tax calculation results.' );

						} else {
							$taxCollectable  = 0;
							$taxCombinedRate = 0;
							$id              = '';
							foreach ( $response as $lineItemTax ) {
								foreach ( $lineItemTax as $key => $value ) {
									if ( strpos( $key, 'fips tax amount' ) === 0 ) {
										$taxCollectable += $value;
									}
									if ( strpos( $key, 'fips tax rate' ) === 0 ) {
										$taxCombinedRate += $value;
									}
									if ( strpos( $key, 'fips jurisdiction code' ) === 0 ) {
										$id .= $value;
									}
								}
							}
							if ( $wc_cart_object instanceof WC_Order ) {
								$items_total    = $wc_cart_object->get_subtotal();
								$shipping_total = $wc_cart_object->get_shipping_total();
								$fee_total      = $wc_cart_object->get_total_fees();
								$total          = $items_total + $shipping_total + $fee_total + $taxCollectable;

								$wc_cart_object->set_cart_tax( $taxCollectable );

//								$wc_cart_object->set( $items_total + $taxCollectable );
//								$wc_cart_object->set_cart_contents_tax( $items_total + $taxCollectable );
//								$wc_cart_object->set_cart_contents_taxes( $items_total + $taxCollectable );
								$wc_cart_object->set_total( max( 0, $total ) );
//						$wc_cart_object->set_taxes( array( $id => $taxCollectable ) );
								$wc_cart_object->taxes = array( $id => $taxCollectable );
								$this->setIsCalculated();
							} else {
								$items_total    = $wc_cart_object->get_cart_contents_total();
								$shipping_total = $wc_cart_object->get_shipping_total();
								$fee_total      = $wc_cart_object->get_fee_total();
								$total          = $items_total + $shipping_total + $fee_total + $taxCollectable;

								$wc_cart_object->set_total_tax( $taxCollectable );

								$wc_cart_object->set_subtotal_tax( $items_total + $taxCollectable );
								$wc_cart_object->set_cart_contents_tax( $items_total + $taxCollectable );
								$wc_cart_object->set_cart_contents_taxes( $items_total + $taxCollectable );
								$wc_cart_object->set_total( max( 0, $total ) );
//						$wc_cart_object->set_taxes( array( $id => $taxCollectable ) );
								$wc_cart_object->taxes = array( $id => $taxCollectable );

								$this->setIsCalculated();
//								wc_add_notice( 'Tax calculated successfully', 'success' );

							}
						}
					} else {
						$this->setErrorMsg( 'Please enter the correct address for the best tax calculation results.' );
					}
				} catch ( Exception $exception ) {
					$this->_log( '--- fetchTax EXCEPTION---' );
					$this->_log( 'fetchTax Request: ' . json_encode( $data ) );
					$this->_log( 'Exception: ' . $exception->getMessage() );
					$this->setErrorMsg( 'Please enter the correct address for the best tax calculation results.' );
				}
				$this->_log( '--- fetchTax end ---' );
			} else {
				$this->setErrorMsg( 'Please enter the correct address for the best tax calculation results.' );
			}
		}

		public function use_avior_total_order( $and_taxes, $wc_cart_object ) {
			if ( is_admin() && 'woocommerce_calc_line_taxes' == ( isset( $_REQUEST['action'] ) ? sanitize_text_field( $_REQUEST['action'] ) : '' ) ) {
				try {
					$commonData = $this->getCommonData( $wc_cart_object );
					$data       = $this->getLineItems( $wc_cart_object, $commonData );

					if ( ( $this->validateRequest( $data ) ) === true ) {
						$url      = $this->endpoint . self::ENDPOINT_FETCH_TAX;
						$response = $this->postData( $data, $url, true );

						$this->_log( '--- fetchTax ---' );
						$this->_log( 'fetchTax Request: ' . json_encode( $data ) );
						$this->_log( 'fetchTax Response: ' . json_encode( $response ) );

						if ( ! $response || ! $this->validateResponse( $response, $data ) ) {
							$this->_log( 'INVALIDATE RESPONSE' );

							$wc_cart_object->tax_total = 0;
						} else {
							$taxCollectable  = 0;
							$taxCombinedRate = 0;
							$id              = '';
							foreach ( $response as $lineItemTax ) {
								foreach ( $lineItemTax as $key => $value ) {
									if ( strpos( $key, 'fips tax amount' ) === 0 ) {
										$taxCollectable += $value;
									}
									if ( strpos( $key, 'fips tax rate' ) === 0 ) {
										$taxCombinedRate += $value;
									}
									if ( strpos( $key, 'fips jurisdiction code' ) === 0 ) {
										$id .= $value;
									}
								}
							}
							if ( $wc_cart_object instanceof WC_Order ) {
								$items_total    = $wc_cart_object->get_subtotal();
								$shipping_total = $wc_cart_object->get_shipping_total();
								$fee_total      = $wc_cart_object->get_total_fees();
								$total          = $items_total + $shipping_total + $fee_total + $taxCollectable;

								$wc_cart_object->set_cart_tax( $taxCollectable );

//								$wc_cart_object->set( $items_total + $taxCollectable );
//								$wc_cart_object->set_cart_contents_tax( $items_total + $taxCollectable );
//								$wc_cart_object->set_cart_contents_taxes( $items_total + $taxCollectable );
								$wc_cart_object->set_total( max( 0, $total ) );
//						$wc_cart_object->set_taxes( array( $id => $taxCollectable ) );
								$wc_cart_object->taxes = array( $id => $taxCollectable );
							} else {
								$items_total    = $wc_cart_object->get_cart_contents_total();
								$shipping_total = $wc_cart_object->get_shipping_total();
								$fee_total      = $wc_cart_object->get_fee_total();
								$total          = $items_total + $shipping_total + $fee_total + $taxCollectable;

								$wc_cart_object->set_total_tax( $taxCollectable );

								$wc_cart_object->set_subtotal_tax( $items_total + $taxCollectable );
								$wc_cart_object->set_cart_contents_tax( $items_total + $taxCollectable );
								$wc_cart_object->set_cart_contents_taxes( $items_total + $taxCollectable );
								$wc_cart_object->set_total( max( 0, $total ) );
//						$wc_cart_object->set_taxes( array( $id => $taxCollectable ) );
								$wc_cart_object->taxes = array( $id => $taxCollectable );

								$this->setIsCalculated();
//								wc_add_notice( 'Tax calculated successfully', 'success' );

							}
						}
					} else {
						$this->setErrorMsg( 'Please enter the correct address for the best tax calculation results.' );
					}
				} catch ( Exception $exception ) {
					$this->_log( '--- fetchTax EXCEPTION---' );
					$this->_log( 'fetchTax Request: ' . json_encode( $data ) );
					$this->_log( 'Exception: ' . $exception->getMessage() );
				}
				$this->_log( '--- fetchTax end ---' );
			}
		}

		private function getLineItems( $wc_cart_object, $commonData ) {
			$lineItems = [];
			global $woocommerce;
			if ( is_admin() && $wc_cart_object instanceof WC_Order ) {
				$items = $wc_cart_object->get_items();
			} else {
				$items = $woocommerce->cart->get_cart();
			}

			if ( count( $items ) > 0 ) {
				foreach ( $items as $item => $values ) {
					$quantity = $values['quantity'];
					$price    = get_post_meta( $values['product_id'], '_price', true );

					array_push( $lineItems, array_merge( $commonData, [
						'sku'            => (string) 'AAA',
						'amount of sale' => (string) ( $quantity * $price ),
					] ) );
				}
			}

			return $lineItems;
		}

		private function setIsCalculated() {
			WC()->session->set( 'isCalculated', true );
			WC()->session->set( 'isCalculated_lastError', false );
		}

		private function setErrorMsg( $msg ) {
			WC()->session->set( 'isCalculated', false );
			WC()->session->set( 'isCalculated_lastError', $msg );
		}

		private function validateResponse( $response, $data ) {
			$c = count( $data );

			for ( $i = 0; $i < $c; $i ++ ) {
				$dataToCheck = $data[ $i ];
				unset( $dataToCheck['date'], $dataToCheck['record number'] );

				$diff = array_diff_assoc( $dataToCheck, $response[ $i ] );
				if ( ! empty( $diff ) ) {
					$this->setErrorMsg( 'Please enter the correct address for the best tax calculation results.' );

					return false;
				}
			}

			foreach ( $response as $data ) {
				if ( isset( $data['error code'] ) ) {
					$this->setErrorMsg( $data['error comments'] );

					return false;
				}
			}

			return true;
		}

		private function validateRequest( $data ) {
			$reqValid     = true;
			$fieldCounter = 0;

			$fields = array(
				'date',
				'record number',
				'seller id',
				'seller location id',
				'seller state',
				'delivery method',
				'customer entity code',
				'ship to address',
				'ship to suite',
				'ship to city',
				'ship to county',
				'ship to state',
				'ship to zip code',
				'sku',
				'amount of sale'
			);

			foreach ( $data as $item ) {
				#Validates all expected fields are present in the request and values are correct
				foreach ( $item as $key => $value ) {
					if ( in_array( $key, $fields ) ) {
						$fieldCounter ++;

						if ( 'date' == $key ) {
							if ( empty( $item['date'] ) || ! is_numeric( $item['date'] ) ) {
								$msg      = $item['date'] . " : date not valid\n";
								$reqValid = false;
							}
						}
						if ( 'record number' == $key ) {
							if ( empty( $item['record number'] ) || ! is_numeric( $item['record number'] ) ) {
								$msg      = $item['record number'] . " : record number not valid\n";
								$reqValid = false;
							}
						}
						if ( 'seller id' == $key ) {
							if ( empty( $item['seller id'] ) || ! ctype_alnum( trim( str_replace( ' ', '', $item['seller id'] ) ) ) ) {
								$msg      = $item['seller id'] . " : seller id not valid\n";
								$reqValid = false;
							}
						}
						if ( 'seller location id' == $key ) {
							if ( ! empty( $item['seller location id'] ) && ! ctype_alnum( trim( str_replace( ' ', '', $item['seller location id'] ) ) ) ) {
								$msg      = $item['seller location id'] . " : seller location id not valid\n";
								$reqValid = false;
							}
						}
						if ( 'delivery method' == $key ) {
							if ( ! empty( $item['delivery method'] ) && ( 'Y' != $item['delivery method'] && 'N' != $item['delivery method'] ) ) {
								$msg      = $item['delivery method'] . " : delivery method not valid\n";
								$reqValid = false;
							}
						}
						if ( 'seller state' == $key ) {
							if ( ! empty( $item['seller state'] ) && ( ! is_string( $item['seller state'] ) ) ) {
								$msg      = $item['seller state'] . " : seller state not valid\n";
								$reqValid = false;
							}
						}
						if ( 'customer entity code' == $key ) {
							if ( empty( $item['customer entity code'] ) || ( 'T' != $item['customer entity code'] && 'E' != $item['customer entity code'] ) ) {
								$msg      = $item['customer entity code'] . " : customer entity code not valid\n";
								$reqValid = false;
							}
						}
						if ( 'ship to address' == $key ) {
							if ( empty( $item['ship to address'] ) || ! ctype_alnum( trim( str_replace( ' ', '', $item['ship to address'] ) ) ) ) {
								$msg      = $item['ship to address'] . " : ship to address not valid\n";
								$reqValid = false;
							}
						}
						if ( 'ship to city' == $key ) {
							if ( empty( $item['ship to city'] ) || ! is_string( $item['ship to city'] ) ) {
								$msg      = $item['ship to city'] . " : ship to city not valid\n";
								$reqValid = false;
							}
						}
						if ( 'ship to county' == $key ) {
							if ( ! empty( $item['ship to county'] ) && ! is_string( $item['ship to county'] ) ) {
								$msg      = $item['ship to county'] . " : ship to county not valid\n";
								$reqValid = false;
							}
						}
						if ( 'ship to state' == $key ) {
							if ( empty( $item['ship to state'] ) || ! is_string( $item['ship to state'] ) ) {
								$msg      = $item['ship to state'] . " : ship to state not valid\n";
								$reqValid = false;
							}
						}
						if ( 'ship to zip code' == $key ) {
							if ( empty( $item['ship to zip code'] ) || ! is_numeric( $item['ship to zip code'] ) ) {
								$msg      = $item['ship to zip code'] . " : ship to zip code not valid\n";
								$reqValid = false;
							}
						}
						if ( 'sku' == $key ) {
							if ( empty( $item['sku'] ) ) {
								$msg      = $item['sku'] . " : sku not valid\n";
								$reqValid = false;
							}
						}
						if ( 'amount of sale' == $key ) {
							if ( empty( $item['amount of sale'] ) || ! is_numeric( $item['amount of sale'] ) ) {
								$msg      = $item['amount of sale'] . " : amount of sale not valid\n";
								$reqValid = false;
							}
						}
					}
				}
			}
			if ( 16 == $fieldCounter ) {
				$reqValid = false;
			}

			#Return true or false
			return $reqValid ? true : $msg;
		}

		private function getCommonData( $wc_cart_object ) {
			$taxable_address = isset( $_REQUEST['post_data'] ) ? sanitize_text_field( $_REQUEST['post_data'] ) : '';
			if ( 'checkout' == ( isset( $_REQUEST['wc-ajax'] ) ? sanitize_text_field( $_REQUEST['wc-ajax'] ) : '' ) ) {
				if ( isset( $_REQUEST['ship_to_different_address'] ) ? sanitize_text_field( $_REQUEST['ship_to_different_address'] ) : '' ) {
					$to_state   = isset( $_REQUEST['shipping_state'] ) ? sanitize_text_field( $_REQUEST['shipping_state'] ) : '';
					$to_zip     = isset( $_REQUEST['shipping_postcode'] ) ? sanitize_text_field( $_REQUEST['shipping_postcode'] ) : '';
					$to_city    = isset( $_REQUEST['shipping_city'] ) ? sanitize_text_field( $_REQUEST['shipping_city'] ) : '';
					$to_address = isset( $_REQUEST['shipping_address_1'], $_REQUEST['shipping_address_2'] ) ? sanitize_text_field( $_REQUEST['shipping_address_1'] . ' ' . $_REQUEST['shipping_address_2'] ) : '';
					$to_county  = isset( $_REQUEST['shipping_county'] ) ? sanitize_text_field( $_REQUEST['shipping_county'] ) : '';
				} else {
					$to_state   = isset( $_REQUEST['billing_state'] ) ? sanitize_text_field( $_REQUEST['billing_state'] ) : '';
					$to_zip     = isset( $_REQUEST['billing_postcode'] ) ? sanitize_text_field( $_REQUEST['billing_postcode'] ) : '';
					$to_city    = isset( $_REQUEST['billing_city'] ) ? sanitize_text_field( $_REQUEST['billing_city'] ) : '';
					$to_address = isset( $_REQUEST['billing_address_1'], $_REQUEST['billing_address_2'] ) ? sanitize_text_field( $_REQUEST['billing_address_1'] ) . ' ' . sanitize_text_field( $_REQUEST['billing_address_2'] ) : '';
					$to_county  = isset( $_REQUEST['billing_county'] ) ? sanitize_text_field( $_REQUEST['billing_county'] ) : '';
				}
				$shippingRegionId = explode( ':', get_option( 'woocommerce_default_country' ) );
			} elseif ( is_admin() && 'woocommerce_calc_line_taxes' == ( isset( $_REQUEST['action'] ) ) ? sanitize_text_field( $_REQUEST['action'] ) : '' ) {
				$to_state         = $wc_cart_object->get_meta( '_shipping_state' );
				$to_zip           = $wc_cart_object->get_meta( '_shipping_postcode' );
				$to_city          = $wc_cart_object->get_meta( '_shipping_city' );
				$to_address       = $wc_cart_object->get_meta( '_shipping_address_1' ) . ' ' . $wc_cart_object->get_meta( '_shipping_address_2' );
				$to_county        = $wc_cart_object->get_meta( '_shipping_county' );
				$shippingRegionId = explode( ':', get_option( 'woocommerce_default_country' ) );
			} else {
				$taxable_address = explode( '&', $taxable_address );

				$shippingAddress = [];
				foreach ( $taxable_address as $field ) {
					$tmp = explode( '=', $field );
					if ( $tmp ) {
						$shippingAddress[ $tmp[0] ] = urldecode( $tmp[1] );
					}
				}
				$to_state         = @$shippingAddress['ship_to_different_address'] ? $shippingAddress['shipping_state'] : $shippingAddress['billing_state'];
				$to_zip           = @$shippingAddress['ship_to_different_address'] ? $shippingAddress['shipping_postcode'] : $shippingAddress['billing_postcode'];
				$to_city          = @$shippingAddress['ship_to_different_address'] ? $shippingAddress['shipping_city'] : $shippingAddress['billing_city'];
				$to_address       = @$shippingAddress['ship_to_different_address'] ? $shippingAddress['shipping_address_1'] . ' ' . $shippingAddress['shipping_address_2'] : $shippingAddress['billing_address_1'] . ' ' . $shippingAddress['billing_address_2'];
				$to_county        = @$shippingAddress['ship_to_different_address'] ? $shippingAddress['shipping_county'] : $shippingAddress['billing_county'];
				$shippingRegionId = explode( ':', get_option( 'woocommerce_default_country' ) );
			}

			return [
				'date'                 => current_time( 'Ymd' ),
				'record number'        => (string) rand( 111111, 999999 ),
				'seller id'            => $this->seller_id,
				'seller location id'   => '1',//todo
				'seller state'         => $shippingRegionId[1],
				'delivery method'      => 'N',//todo Y or N
				'customer entity code' => 'T',//todo T or E
				'ship to address'      => trim( $to_address ),
				'ship to suite'        => '',//todo
				'ship to city'         => trim( $to_city ),
				'ship to county'       => trim( $to_county ),
				'ship to state'        => trim( $to_state ),
				'ship to zip code'     => trim( $to_zip ),
				'ship to zip plus'     => ''
			];
		}

		public function avior_error_check() {
			if ( WC()->session->get( 'isCalculated' ) !== true ) {
				// Set to false
				$passed = false;
				// Display a message
				if ( WC()->session->get( 'isCalculated_lastError' ) ) {
					wc_add_notice( __( WC()->session->get( 'isCalculated_lastError' ), 'woocommerce' ), 'error' );
				} else {
					wc_add_notice( __( 'Please enter the correct address for the best tax calculation results.', 'woocommerce' ), 'error' );
				}
			}

			return $passed;
		}

		public function avior_admin_menu() {
			add_submenu_page( 'woocommerce', __( 'Avior Settings', 'woocommerce' ), __( 'Avior', 'woocommerce' ), 'manage_woocommerce', 'admin.php?page=wc-settings&tab=integration&section=avior-integration' );
		}

		/**
		 * Hide the tax sections for additional tax class rate tables.
		 *
		 */
		public function output_sections_before() {
			echo '<div class="avior"><h3>Tax Rates Powered by <a href="https://suttax.avior.tax/" target="_blank">Avior</a>. <a href="admin.php?page=wc-settings&tab=integration">Configure Avior</a></h3></div>';
			echo '<div style="display:none;">';
		}

		/**
		 * Hide the tax sections for additional tax class rate tables.
		 *
		 */
		public function output_sections_after() {
			echo '</div>';
		}
	}

endif;
