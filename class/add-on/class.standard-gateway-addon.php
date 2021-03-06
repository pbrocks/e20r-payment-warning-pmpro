<?php
/**
 * Copyright (c) 2017-2018 - Eighty / 20 Results by Wicked Strong Chicks.
 * ALL RIGHTS RESERVED
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace E20R\Payment_Warning\Addon;

use E20R\Payment_Warning\Payment_Warning;
use E20R\Payment_Warning\User_Data;
use E20R\Utilities\Utilities;
use E20R\Utilities\Licensing\Licensing;

if ( ! class_exists( 'E20R\Payment_Warning\Addon\Standard_Gateway_Addon' ) ) {
	
	if ( ! defined( 'DEBUG_DEFAULT_KEY' ) ) {
		define( 'DEBUG_DEFAULT_KEY', null );
	}
	
	class Standard_Gateway_Addon extends E20R_PW_Gateway_Addon {
		
		const CACHE_GROUP = 'e20r_std_addon';
		
		/**
		 * The name of this class
		 *
		 * @var string
		 */
		protected $class_name;
		
		/**
		 * @var Stripe_Gateway_Addon
		 */
		private static $instance;
		
		/**
		 * @var array
		 */
		protected $gateway_sub_statuses = array();
		
		/**
		 * Instance of the active/primary Payment Gateway Class(es) for PMPro
		 *
		 * @var array|
		 *      \PMProGateway[]|
		 *      \PMProGateway_authorizenet|
		 *      \PMProGateway_braintree|
		 *      \PMProGateway_check|
		 *      \PMProGateway_cybersource|
		 *      \PMProGateway_payflowpro|
		 *      \PMProGateway_paypal|
		 *      \PMProGateway_paypalexpress|
		 *      \PMProGateway_paypalstandard|
		 *      \PMProGateway_stripe|
		 *      \PMProGateway_Twocheckout
		 */
		protected $pmpro_gateway = array();
		
		/**
		 * @var null
		 */
		protected $gateway = null;
		
		/**
		 * @var array
		 */
		protected $subscriptions = array();
		
		/**
		 * @var null|string
		 */
		protected $gateway_name = null;
		
		/**
		 * @var bool
		 */
		protected $gateway_loaded = false;
		
		/**
		 * @var null|string $current_gateway_type Can be set to 'live' or 'sandbox' or null
		 */
		protected $current_gateway_type = null;
		
		/**
		 * Name of the WordPress option key
		 *
		 * @var string $option_name
		 */
		protected $option_name = 'e20r_egwao_standard';
		
		/**
		 * Return the array of supported subscription statuses to capture data about
		 *
		 * @param string[]  $statuses Array of valid gateway statuses
		 * @param string $gateway  The gateway name we're processing for
		 * @param string $addon
		 *
		 * @return string[]
		 */
		public function valid_gateway_subscription_statuses( $statuses, $gateway, $addon ) {
			
			if ( $gateway === $this->gateway_name ) {
				
				$statuses = array( 'trialing', 'active', 'unpaid', 'past_due', );
			}
			
			return $statuses;
		}
		
		/**
		 * Fetch the (current) Payment Gateway specific customer ID from the local Database
		 *
		 * @param string    $gateway_customer_id
		 * @param string    $gateway_name
		 * @param User_Data $user_info
		 *
		 * @return mixed
		 */
		public function get_local_user_customer_id( $gateway_customer_id, $gateway_name, $user_info ) {
			
			$util = Utilities::get_instance();
			$stub = apply_filters( 'e20r_pw_addon_check_gateway_addon_name', null );
			
			if ( false === $this->verify_gateway_processor( $user_info, $stub, $this->gateway_name ) ) {
				$util->log( "Failed check of gateway / gateway addon licence for the add-on" );
				
				return $gateway_customer_id;
			}
			
			// Don't run this action handler (unexpected gateway name)
			if ( 1 !== preg_match( "/{$this->gateway_name}/i", $gateway_name ) ) {
				$util->log( "Specified gateway name doesn't match this add-on's gateway: {$gateway_name} vs {$this->gateway_name}. Returning: {$gateway_customer_id}" );
				
				return $gateway_customer_id;
			}
			
			$gateway_customer_id = $user_info->get_user_ID();
			$util->log( "Located Check user ID: {$gateway_customer_id} for WP User " . $user_info->get_user_ID() );
			
			return $gateway_customer_id;
		}
		
		/**
		 * Do what's required to make Stripe libraries visible/active
		 */
		private function load_check_libs() {
			
			if ( function_exists( 'pmpro_getOption' ) ) {
				$gateway = pmpro_getGateway();
			}
			
			$gateway_class = "\\PMProGateway_{$gateway}";
			$this->pmpro_gateway = new $gateway_class();;
			$this->gateway_loaded = true;
			
		}
		
		/**
		 * Load the payment gateway specific class/code/settings from PMPro
		 *
		 * @param string $addon_name
		 *
		 * @return boolean
		 */
		public function load_gateway( $addon_name ) {
			
			$util = Utilities::get_instance();
			
			if ( $addon_name !== 'check' ) {
				$util->log( "Not processing for this addon (default): {$addon_name}" );
				
				return false;
			}
			
			// This will load the Check/PMPro Gateway class _and_ its library(ies)
			$util->log( "PMPro loaded? " . ( defined( 'PMPRO_VERSION' ) ? 'Yes' : 'No' ) );
			
			// Configure Stripe API call version
			$this->gateway_timezone = get_option( 'timezone_string' );
			$util->log( "Using {$this->gateway_timezone} as the timezone value" );
			
			return true;
		}
		
		/**
		 * Configure the subscription information for the user data for the current Payment Gateway
		 *
		 * @param User_Data $user_data The User_Data record to process for
		 *
		 * @return bool|User_Data
		 */
		public function get_gateway_subscriptions( $user_data, $addon ) {
			
			$utils = Utilities::get_instance();
			$stub  = apply_filters( "e20r_pw_addon_standard_gateway_addon_name", null );
			$data  = null;
			
			if ( 1 !== preg_match( "/check/i", $addon ) ) {
				$utils->log( "While in the Check module, the system asked to process for {$addon}" );
				
				return $user_data;
			}
			
			if ( false === $this->verify_gateway_processor( $user_data, $stub, $this->gateway_name ) ) {
				$utils->log( "Failed check of gateway / gateway licence for the add-on" );
				
				return false;
			}
			
			if ( false === $this->gateway_loaded ) {
				$utils->log( "Loading the PMPro Check Gateway instance" );
				$this->load_check_libs();
			}
			
			$cust_id = $user_data->get_gateway_customer_id();
			
			if ( empty( $cust_id ) ) {
				
				$utils->log( "No Gateway specific customer ID found for specified user: " . $user_data->get_user_ID() );
				
				return false;
			}
			
			try {
				
				$utils->log( "Accessing Check API service for {$cust_id}" );
				$data = Customer::retrieve( $cust_id );
				
			} catch ( \Exception $exception ) {
				
				$utils->log( "Error fetching customer data: " . $exception->getMessage() );
				$utils->add_message( sprintf( __( "Unable to fetch Stripe.com data for %s", Payment_Warning::plugin_slug ), $user_data->get_user_email() ), 'warning', 'backend' );
				
				$user_data->set_active_subscription( false );
				
				return false;
			}
			
			$user_email = $user_data->get_user_email();
			
			$utils->log( "All available Stripe subscription data collected for {$cust_id} -> {$user_email}" );
			// Make sure the user email on record locally matches that of the upstream email record for the specified Stripe gateway ID
			if ( isset( $data->email ) && $user_email !== $data->email ) {
				
				$utils->log( "The specified user ({$user_email}) and the customer's email account on the Check gateway ({$data->email}) doesn't match! Saving to metadata!" );
				
				do_action( 'e20r_pw_addon_save_email_error_data', $this->gateway_name, $cust_id, $data->email, $user_email );
				
				return false;
			}
			
			// $utils->log( "Retrieved customer data: " . print_r( $data, true ) );
			
			$utils->log( "Loading most recent local PMPro order info" );
			
			$local_order    = $user_data->get_last_pmpro_order();
			$check_statuses = apply_filters( 'e20r_pw_addon_subscr_statuses', array(), $this->gateway_name, $addon );
			
			// $user_data->add_subscription_list( $data->subscriptions->data );
			
			// Iterate through subscription plans on Stripe.com & fetch required date info
			foreach ( $data->subscriptions->data as $subscription ) {
				
				$payment_next  = date_i18n( 'Y-m-d H:i:s', ( $subscription->current_period_end + 1 ) );
				$already_saved = $user_data->has_subscription_id( $subscription->id );
				$saved_next    = $user_data->get_next_payment( $subscription->id );
				
				$utils->log( "Using {$payment_next} for payment_next and saved_next: {$saved_next}" );
				$utils->log( "Stored subscription ID? " . ( $already_saved ? 'Yes' : 'No' ) );
				
				/*if ( true === $already_saved && $payment_next == $saved_next ) {
					
					$utils->log( "Have a current version of the upstream subscription record. No need to process!" );
					continue;
				}
				*/
				$user_data->set_gw_subscription_id( $subscription->id );
				$user_data->set_active_subscription( true );
				
				if ( $subscription->id == $local_order->subscription_transaction_id && in_array( $subscription->status, $check_statuses ) ) {
					
					$utils->log( "Processing {$subscription->id} for customer ID {$cust_id}" );
					
					if ( empty( $subscription->cancel_at_period_end ) && empty( $subscription->cancelled_at ) && in_array( $subscription->status, array(
							'trialing',
							'active',
						) )
					) {
						$utils->log( "Setting payment status to 'active' for {$cust_id}" );
						$user_data->set_payment_status( 'active' );
					}
					
					if ( ! empty( $subscription->cancel_at_period_end ) || ! empty( $subscription->cancelled_at ) || ! in_array( $subscription->status, array(
							'trialing',
							'active',
						) )
					) {
						$utils->log( "Setting payment status to 'stopped' for {$cust_id}/" . $user_data->get_user_ID() );
						$user_data->set_payment_status( 'stopped' );
					}
					
					// Set the date for the next payment
					if ( $user_data->get_payment_status() === 'active' ) {
						
						// Get the date when the currently paid for period ends.
						$current_payment_until = date_i18n( 'Y-m-d 23:59:59', $subscription->current_period_end );
						$user_data->set_end_of_paymentperiod( $current_payment_until );
						$utils->log( "End of the current payment period: {$current_payment_until}" );
						
						// Add a day (first day of new payment period)
						
						$user_data->set_next_payment( $payment_next );
						$utils->log( "Next payment on: {$payment_next}" );
						
						$plan_currency = ! empty( $subscription->plan->currency ) ? strtoupper( $subscription->plan->currency ) : 'USD';
						$user_data->set_payment_currency( $plan_currency );
						
						$utils->log( "Payments are made in: {$plan_currency}" );
						
						$amount = $utils->amount_by_currency( $subscription->plan->amount, $plan_currency );
						$user_data->set_next_payment_amount( $amount );
						
						$utils->log( "Next payment of {$plan_currency} {$amount} will be charged within 24 hours of {$payment_next}" );
						
						$user_data->set_reminder_type( 'recurring' );
					} else {
						
						$utils->log( "Subscription payment plan is going to end after: " . date_i18n( 'Y-m-d 23:59:59', $subscription->current_period_end + 1 ) );
						$user_data->set_subscription_end();
						
						$ends = date_i18n( 'Y-m-d H:i:s', $subscription->current_period_end );
						
						$utils->log( "Setting end of membership to {$ends}" );
						$user_data->set_end_of_membership_date( $ends );
					}
					
					$utils->log( "Attempting to load credit card (payment method) info from gateway" );
					
					// Trigger handler for credit card data
					$user_data = $this->process_credit_card_info( $user_data, $data->sources->data, $this->gateway_name );
					
				} else {
					
					$utils->log( "Mismatch between expected (local) subscription ID {$local_order->subscription_transaction_id} and remote ID {$subscription->id}" );
					/**
					 * @action e20r_pw_addon_save_subscription_mismatch
					 *
					 * @param string       $this ->gateway_name
					 * @param User_Data    $user_data
					 * @param \MemberOrder $local_order
					 * @param Subscription $subscription
					 */
					do_action( 'e20r_pw_addon_save_subscription_mismatch', $this->gateway_name, $user_data, $local_order, $subscription->id );
					
				}
			}
			
			$utils->log( "Returning possibly updated user data to calling function" );
			
			return $user_data;
		}
		
		/**
		 * Configure Charges (one-time charges) for the user data from the specified payment gateway
		 *
		 * @param User_Data|bool $user_data User data to update/process
		 * @param string         $addon     The Payment Gateway addon module we're processing for
		 *
		 * @return bool|User_Data
		 */
		public function get_gateway_payments( $user_data, $addon ) {
			
			$utils = Utilities::get_instance();
			$stub  = apply_filters( 'e20r_pw_addon_check_gateway_addon_name', null );
			$data  = null;
			
			if ( 1 !== preg_match( "/check/i", $addon ) ) {
				$utils->log( "While in the Check module, the system asked to process for {$addon}" );
				
				return $user_data;
			}
			
			if ( false === $this->verify_gateway_processor( $user_data, $stub, $this->gateway_name ) ) {
				$utils->log( "Failed check of gateway / gateway addon licence for the add-on" );
				
				return $user_data;
			}
			
			if ( $user_data->get_gateway_name() !== $this->gateway_name ) {
				$utils->log( "Gateway name: {$this->gateway_name} vs " . $user_data->get_gateway_name() );
				
				return $user_data;
			}
			
			if ( false === $this->gateway_loaded ) {
				$utils->log( "Loading the PMPro Stripe Gateway instance" );
				$this->load_check_libs();
			}
			
			$cust_id = $user_data->get_gateway_customer_id();
			$user_data->set_active_subscription( false );
			
			$last_order    = $user_data->get_last_pmpro_order();
			$last_order_id = ! empty( $last_order->payment_transaction_id ) ? $last_order->payment_transaction_id : null;
			
			if ( empty( $cust_id ) ) {
				
				$utils->log( "No Gateway specific customer ID found for specified user: " . $user_data->get_user_ID() );
				
				return false;
			}
			
			if ( empty( $last_order_id ) ) {
				$utils->log( "Unexpected: There's no Transaction ID for " . $user_data->get_user_ID() . " / {$cust_id}" );
				
				return false;
			}
			
			try {
				
				$utils->log( "Accessing Stripe API service for {$cust_id}" );
				$customer = Customer::retrieve( $cust_id );
				
			} catch ( \Exception $exception ) {
				
				$utils->log( "Error fetching customer data: " . $exception->getMessage() );
				
				// $utils->add_message( sprintf( __( "Unable to fetch Stripe.com data for %s", Payment_Warning::plugin_slug ), $user_data->get_user_email() ), 'warning', 'backend' );
				
				return false;
			}
			
			if ( $customer->subscriptions->total_count > 0 ) {
				
				$utils->log( "User ID ({$cust_id}) on check.com has {$customer->subscriptions->total_count} subscription plans. Skipping payment/charge processing" );
				
				return false;
			}
			
			$user_email = $user_data->get_user_email();
			
			if ( ! empty( $last_order_id ) && false !== strpos( $last_order_id, 'in_' ) ) {
				
				$utils->log( "Local order saved a Stripe Invoice ID, not a Charge ID" );
				
				try {
					$inv = Invoice::retrieve( $last_order_id );
					
					if ( ! empty( $inv ) ) {
						$last_order_id = $inv->charge;
					}
				} catch ( \Exception $exception ) {
					$utils->log( "Error fetching invoice info: " . $exception->getMessage() );
					
					return false;
				}
			}
			
			if ( ! empty( $last_order_id ) && false !== strpos( $last_order_id, 'ch_' ) ) {
				try {
					
					$utils->log( "Loading charge data for {$last_order_id}" );
					$charge = Charge::retrieve( $last_order_id );
					
				} catch ( \Exception $exception ) {
					$utils->log( "Error fetching charge/payment: " . $exception->getMessage() );
					
					return false;
				}
			}
			
			$utils->log( "Stripe payment data collected for {$last_order_id} -> {$user_email}" );
			
			// Make sure the user email on record locally matches that of the upstream email record for the specified Stripe gateway ID
			if ( isset( $customer->email ) && $user_email !== $customer->email ) {
				
				$utils->log( "The specified user ({$user_email}) and the customer's email Check gateway account {$customer->email} doesn't match! Saving to metadata!" );
				
				do_action( 'e20r_pw_addon_save_email_error_data', $this->gateway_name, $cust_id, $customer->email, $user_email );
				
				return false;
			}
			
			if ( ! empty( $charge ) ) {
				
				if ( 'charge' != $charge->object ) {
					$utils->log( "Error: This is not a valid Stripe Charge! " . print_r( $charge, true ) );
					
					return $user_data;
				}
				
				$amount = $utils->amount_by_currency( $charge->amount, $charge->currency );
				
				$user_data->set_charge_info( $last_order_id );
				$user_data->set_payment_amount( $amount, $charge->currency );
				
				$payment_status = ( 'paid' == $charge->paid ? true : false );
				$user_data->is_payment_paid( $payment_status, $charge->failure_message );
				
				$user_data->set_payment_date( $charge->created, $this->gateway_timezone );
				$user_data->set_end_of_membership_date();
				
				$user_data->set_reminder_type( 'expiration' );
				// $user_data->add_charge_list( $charge );
				
				// Add any/all credit card info used for this transaction
				$user_data = $this->process_credit_card_info( $user_data, $charge->source, $this->gateway_name );
			}
			
			
			return $user_data;
		}
		
		/**
		 * Filter handler for upstream user Credit Card data...
		 *
		 * @filter e20r_pw_addon_process_cc_info
		 *
		 * @param           $card_data
		 * @param User_Data $user_data
		 * @param           $gateway_name
		 *
		 * @return User_Data
		 */
		public function update_credit_card_info( User_Data $user_data, $card_data, $gateway_name ) {
			
			return $user_data;
		}
		
		/**
		 * Return the gateway name for the matching add-on
		 *
		 * @param null|string $gateway_name
		 * @param string     $addon
		 *
		 * @return null|string
		 */
		public function get_gateway_class_name( $gateway_name = null , $addon ) {
			
		    $utils = Utilities::get_instance();
		    $utils->log("Gateway name: {$gateway_name}. Addon name: {$addon}");
		    
		    if ( !empty( $gateway_name ) && 1 !== preg_match( "/{$addon}/i", $this->get_class_name() ) ) {
			    $utils->log("{$addon} not matching the standard gateway's expected add-on");
		        return $gateway_name;
            }
            
            $gateway_name =  $this->get_class_name();
			return $gateway_name;
		}
		
		/**
		 *  Stripe_Gateway_Addon constructor.
		 */
		public function __construct() {
			
			parent::__construct();
			
			add_filter( 'e20r-licensing-text-domain', array( $this, 'set_stub_name' ) );
			
			if ( is_null( self::$instance ) ) {
				self::$instance = $this;
			}
			
			$this->class_name   = $this->maybe_extract_class_name( get_class( $this ) );
			$this->gateway_name = 'check';
			
			if ( function_exists( 'pmpro_getOption' ) ) {
				$this->current_gateway_type = pmpro_getOption( "gateway_environment" );
			}
			
			$this->define_settings();
		}
		
		/**
		 * Set the name of the add-on (using the class name as an identifier)
		 *
		 * @param null $name
		 *
		 * @return null|string
		 */
		public function set_stub_name( $name = null ) {
			
			$name = strtolower( $this->get_class_name() );
			
			return $name;
		}
		
		/**
		 * Get the add-on name
		 *
		 * @return string
		 */
		public function get_class_name() {
			
			if ( empty( $this->class_name ) ) {
				$this->class_name = $this->maybe_extract_class_name( get_class( self::$instance ) );
			}
			
			return $this->class_name;
		}
		
		/**
		 * Filter Handler: Add the 'add bbPress add-on license' settings entry
		 *
		 * @filter e20r-license-add-new-licenses
		 *
		 * @param array $license_settings
		 * @param array $plugin_settings
		 *
		 * @return array
		 */
		public function add_new_license_info( $license_settings, $plugin_settings ) {
			
			//No license to add for the default functionality
			return $license_settings;
		}
		
		
		/**
		 * Action handler: Core E20R Payment Warnings plugin activation hook
		 *
		 * @action e20r_pw_addon_activating_core
		 *
		 * @access public
		 * @since  1.0
		 */
		public function activate_addon() {
			
			$util = Utilities::get_instance();
			$util->log( "Triggering Plugin activation actions for: Check Payment Gateway add-on" );
			
			// FixMe: Any and all activation activities that are add-on specific
			return;
		}
		
		
		/**
		 * Action handler: Core E20R Payment Warnings plugin deactivation hook
		 *
		 * @action e20r_pw_addon_deactivating_core
		 *
		 * @param bool $clear
		 *
		 * @access public
		 * @since  1.0
		 */
		public function deactivate_addon( $clear = false ) {
			
			$util = Utilities::get_instance();
			if ( true == $clear ) {
				
				// FixMe: Delete all option entries from the Database for this payment gateway add-on
				$util->log( "Deactivate the add-on specific settings for the Check Payment Gateway" );
			}
		}
		
		/**
		 * Loads the default settings (keys & values)
		 *
		 * TODO: Specify settings for this add-on
		 *
		 * @return array
		 *
		 * @access private
		 * @since  1.0
		 */
		private function load_defaults() {
			
			return array();
			
		}
		
		/**
		 * Load the saved options, or generate the default settings
		 */
		protected function define_settings() {
			
			parent::define_settings();
			
			$this->settings = get_option( $this->option_name, $this->load_defaults() );
			$defaults       = $this->load_defaults();
			
			foreach ( $defaults as $key => $dummy ) {
				$this->settings[ $key ] = isset( $this->settings[ $key ] ) ? $this->settings[ $key ] : $defaults[ $key ];
			}
		}
		
		/**
		 * Action Hook: Enable/disable this add-on. Will clean up if we're being deactivated & configured to do so
		 *
		 * @action e20r_pw_addon_toggle_addon
		 *
		 * @param string $addon
		 * @param bool   $is_active
		 *
		 * @return bool
		 */
		public function toggle_addon( $addon, $is_active = false ) {
			
			global $e20r_pw_addons;
			
			$self = strtolower( $this->get_class_name() );
			
			if ( $self !== $addon ) {
				return $is_active;
			}
			
			$utils = Utilities::get_instance();
			$utils->log( "In toggle_addon action handler for the {$e20r_pw_addons[$addon]['label']} add-on" );
			
			$expected_stub = strtolower( $this->get_class_name() );
			
			if ( $expected_stub !== $addon ) {
				$utils->log( "Not processing the {$e20r_pw_addons[$addon]['label']} add-on: {$addon}" );
				
				return false;
			}
			
			if ( $is_active === false ) {
				
				$utils->log( "Deactivating the add-on so disable the license" );
				Licensing::deactivate_license( $addon );
			}
			
			if ( $is_active === false && true == $this->load_option( 'deactivation_reset' ) ) {
				
				// FixMe: Delete the option entry/entries from the Database
				
				$utils->log( "Deactivate the capabilities for all levels & all user(s)!" );
			}
			
			$e20r_pw_addons[ $addon ]['is_active'] = $is_active;
			
			$utils->log( "Setting the {$addon} option to {$e20r_pw_addons[ $addon ]['is_active']}" );
			update_option( "e20r_pw_addon_{$addon}_enabled", $e20r_pw_addons[ $addon ]['is_active'], 'no' );
		}
		
		/**
		 * Load the specific option from the option array
		 *
		 * @param string $option_name
		 *
		 * @return bool
		 */
		public function load_option( $option_name ) {
			
			$this->settings = get_option( "{$this->option_name}" );
			
			if ( isset( $this->settings[ $option_name ] ) && ! empty( $this->settings[ $option_name ] ) ) {
				
				return $this->settings[ $option_name ];
			}
			
			return false;
			
		}
		
		/**
		 * Load add-on actions/filters when the add-on is active & enabled
		 *
		 * @param string $stub Lowercase Add-on class name
		 *
		 * @return mixed
		 */
		final public static function is_enabled( $stub ) {
			
			$utils = Utilities::get_instance();
			$controller = Payment_Warning::get_instance();
			
			global $e20r_pw_addons;
			
			// TODO: Set the filter name to match the sub for this plugin.
			$utils->log( "Running for {$stub}" );
			
			if ( false === $controller->has_licensed_gateway() ) {
				
				$utils->log("Only loading the Default Gateway if there are no licensed Payment Gateway Add-ons loaded");

				/**
				 * Toggle ourselves on/off, and handle any deactivation if needed.
				 */
				add_action( 'e20r_pw_addon_toggle_addon', array( self::get_instance(), 'toggle_addon' ), 10, 2 );
				add_action( 'e20r_pw_addon_activating_core', array( self::get_instance(), 'activate_addon', ), 10, 0 );
				add_action( 'e20r_pw_addon_deactivating_core', array( self::get_instance(), 'deactivate_addon', ), 10, 1 );
				
				/**
				 * Configuration actions & filters
				 */
				add_filter( 'e20r-license-add-new-licenses', array(
					self::get_instance(),
					'add_new_license_info',
				), 10, 2 );
				add_filter( "e20r_pw_addon_options_{$e20r_pw_addons[$stub]['class_name']}", array(
					self::get_instance(),
					'register_settings',
				), 10, 1 );
				$utils->log( "{$e20r_pw_addons[$stub]['label']} active: Loading add-on specific actions/filters" );
				
				parent::load_hooks_for( self::get_instance() );
				
				if ( WP_DEBUG ) {
					add_action( 'wp_ajax_test_get_gateway_subscriptions', array(
						self::get_instance(),
						'test_gateway_subscriptions',
					) );
				}
			} else {
				$utils->log("Not enabling the Standard Gateway Add-on since we have an active and licensed add-on module");
			}
		}
		
		/**
		 * Loading add-on specific handler for Stripe (use early handling to stay out of the way of PMPro itself)
		 *
		 * @param string|null $stub
		 */
		public function load_webhook_handler( $stub = null ) {
			
			global $e20r_pw_addons;
			$util = Utilities::get_instance();
			
			if ( empty( $stub ) ) {
				$stub = strtolower( $this->get_class_name() );
			}
			
			parent::load_webhook_handler( $stub );
			
			$util->log( "Site has the expected Default Webhook action: " . (
				has_action(
					"wp_ajax_{$e20r_pw_addons[$stub]['handler_name']}",
					array( self::get_instance(), 'webhook_handler', ) ) ? 'Yes' : 'No' )
			);
		}
		
		/**
		 * IPN handler for Stripe. Ensures that the PMPro Webhook will run too.
		 *
		 * @return bool
		 */
		public function webhook_handler() {
			
			return false;
		}
		
		/**
		 * Update/Delete subscription data for specified user
		 *
		 * @param string $operation
		 * @param array  $data
		 *
		 * @return bool
		 */
		public function maybe_update_subscription( $operation, $data ) {
			
			$util = Utilities::get_instance();
			$util->log( "Dumping subscription related event data (for: {$operation}) -> " . print_r( $data, true ) );
			
			if ( isset( $data->object->object ) && 'subscription' !== $data->object->object ) {
				$util->log( "Not a subscription object! Exiting" );
				
				return false;
			}
			
			return false;
		}
		
		/**
		 * Add a local order record if we can't find one by the stripe Subscription ID
		 *
		 * @param Customer $stripe_customer
		 * @param \WP_User $user
		 * @param array    $subscription
		 *
		 * @return User_Data
		 */
		public function add_local_subscription_order( $stripe_customer, $user, $subscription ) {
			
			$util = Utilities::get_instance();
			
			$order = new \MemberOrder();
			
			$order->getLastMemberOrderBySubscriptionTransactionID( $subscription->id );
			
			// Add a new order record to local system if needed
			if ( empty( $order->user_id ) ) {
				
				$order->getEmptyMemberOrder();
				
				$order->setGateway( 'check' );
				$order->gateway_environment = pmpro_getOption( "gateway_environment" );
				
				$order->user_id = $user->ID;
				
				// Set the current level info if needed
				if ( ! isset( $user->membership_level ) || empty( $user->membership_level ) ) {
					
					$util->log( "Adding membership level info for user {$user->ID}" );
					$user->membership_level = pmpro_getMembershipLevelForUser( $user->ID );
				}
				
				$order->membership_id               = isset( $user->membership_level->id ) ? $user->membership_level->id : 0;
				$order->membership_name             = isset( $user->membership_level->name ) ? $user->membership_level->name : null;
				$order->subscription_transaction_id = $subscription->id;
				
				// No initial payment info found...
				$order->InitialPayment = 0;
				
				if ( isset( $subscription->items ) ) {
					
					// Process the subscription plan(s)
					global $pmpro_currencies;
					if ( count( $subscription->items->data ) <= 1 ) {
						
						$util->log( "One or less Plans for the Subscription" );
						$plan = $subscription->items->data[0]->plan;
						
						$currency        = $pmpro_currencies[ strtoupper( $plan->currency ) ];
						$decimal_divisor = 100;
						$decimals        = 2;
						
						if ( isset( $currency['decimals'] ) ) {
							$decimals = $currency['decimals'];
						}
						
						$decimal_divisor = intval( sprintf( "1'%0{$decimals}d", $decimal_divisor ) );
						$util->log( "Using decimal divisor of: {$decimal_divisor}" );
						
						$order->PaymentAmount    = floatval( $plan->amount / $decimal_divisor );
						$order->BillingPeriod    = ucfirst( $plan->interval );
						$order->BillingFrequency = intval( $plan->interval_count );
						$order->ProfileStartDate = date_i18n( 'Y-m-d H:i:s', $plan->created );
						
						$order->status = 'success';
					}
				}
				
				
				$order->billing   = $this->get_billing_info( $stripe_customer );
				$order->FirstName = $user->first_name;
				$order->LastName  = $user->last_name;
				$order->Email     = $user->user_email;
				
				$order->Address1 = $order->billing->street;
				$order->City     = $order->billing->city;
				$order->State    = $order->billing->state;
				
				$order->Zip         = $order->billing->zip;
				$order->PhoneNumber = null;
				
				// Card data
				$order->cardtype        = $stripe_customer->sources->data[0]->brand;
				$order->accountnumber   = hideCardNumber( $stripe_customer->sources->data[0]->last4 );
				$order->expirationmonth = $stripe_customer->sources->data[0]->exp_month;
				$order->expirationyear  = $stripe_customer->sources->data[0]->exp_year;
				
				// Custom card expiration info
				$order->ExpirationDate        = "{$order->expirationmonth}{$order->expirationyear}";
				$order->ExpirationDate_YdashM = "{$order->expirationyear}-{$order->expirationmonth}";
				
				$order->saveOrder();
				$order->getLastMemberOrder( $user->ID );
				
				$util->log( "Saved new (local) order for user ({$user->ID})" );
			}
			
			$user_info = new User_Data( $user, $order, 'recurring' );
			
			return $user_info;
		}
		
		/**
		 * @param Customer $customer -- Stripe Customer Object
		 *
		 * @return \stdClass
		 */
		public function get_billing_info( $customer ) {
			
			$check_billing_info = $customer->sources->data[0];
			
			$billing          = new \stdClass();
			$billing->name    = $check_billing_info->name;
			$billing->street  = $check_billing_info->address_line1;
			$billing->city    = $check_billing_info->address_city;
			$billing->state   = $check_billing_info->address_state;
			$billing->zip     = $check_billing_info->address_zip;
			$billing->country = $check_billing_info->address_country;
			$billing->phone   = null;
			
			return $billing;
		}
		
		/**
		 * Append this add-on to the list of configured & enabled add-ons
		 *
		 * @param array $addons
		 */
		public function configure_addon( $addons ) {
			
			$class = self::get_instance();
			$name  = strtolower( $class->get_class_name() );
			
			parent::is_enabled( $name );
		}
		
		/**
		 * Configure the settings page/component for this add-on
		 *
		 * @param array $settings
		 *
		 * @return array
		 */
		public function register_settings( $settings = array() ) {
			
			$utils = Utilities::get_instance();
			
			$settings['setting'] = array(
				'option_group'        => "{$this->option_name}_settings",
				'option_name'         => "{$this->option_name}",
				'validation_callback' => array( $this, 'validate_settings' ),
			);
			
			// $utils->log( " Loading settings for..." . print_r( $settings, true ) );
			
			$settings['section'] = array(
				array(
					'id'              => 'e20rpw_addon_check_global',
					'label'           => __( "Check Gateway Settings", Payment_Warning::plugin_slug ),
					'render_callback' => array( $this, 'render_settings_text' ),
					'fields'          => array(
						array(),
					),
				),
			);
			
			return $settings;
		}
		
		/**
		 * Checkbox for the role/capability cleanup option on the global settings page
		 */
		public function render_cleanup() {
			
			$cleanup = $this->load_option( 'deactivation_reset' );
			?>
			<input type="checkbox" id="<?php esc_attr_e( $this->option_name ); ?>-deactivation_reset"
			       name="<?php esc_attr_e( $this->option_name ); ?>[deactivation_reset]"
			       value="1" <?php checked( 1, $cleanup ); ?> />
			<?php
		}
		
		/**
		 * Validate the option responses before saving them
		 *
		 * @param mixed $input
		 *
		 * @return mixed $validated
		 */
		public function validate_settings( $input ) {
			
			$defaults = $this->load_defaults();
			
			foreach ( $defaults as $key => $value ) {
				
				if ( false !== stripos( 'level_settings', $key ) && isset( $input[ $key ] ) ) {
					
					foreach ( $input['level_settings'] as $level_id => $settings ) {
						
						// TODO: Add level specific capabilities
					}
					
				} else if ( isset( $input[ $key ] ) ) {
					
					$this->settings[ $key ] = $input[ $key ];
				} else {
					$this->settings[ $key ] = $defaults[ $key ];
				}
				
			}
			
			return $this->settings;
		}
		
		/**
		 * Informational text about the bbPress Role add-on settings
		 */
		public function render_settings_text() {
			?>
			<p class="e20r-example-global-settings-text">
				<?php _e( "Configure global settings for the E20R Payment Warnings: Check Gateway add-on", Payment_Warning::plugin_slug ); ?>
			</p>
			<?php
		}
		
		
		/**
		 * Fetch the properties for the Stripe Gateway add-on class
		 *
		 * @return Standard_Gateway_Addon
		 *
		 * @since  1.0
		 * @access public
		 */
		public static function get_instance() {
			
			if ( is_null( self::$instance ) ) {
				
				self::$instance = new self;
			}
			
			return self::$instance;
		}
	}
}

add_filter( "e20r_pw_addon_standard_gateway_addon_name", array(
	Standard_Gateway_Addon::get_instance(),
	'set_stub_name',
) );

// Configure the add-on (global settings array)
global $e20r_pw_addons;
$stub = apply_filters( "e20r_pw_addon_standard_gateway_addon_name", null );

$e20r_pw_addons[ $stub ] = array(
	'class_name'            => 'Standard_Gateway_Addon',
	'handler_name'          => null,
	'is_active'             => ( get_option( "e20r_pw_addon_{$stub}_enabled", false ) == 1 ? true : false ),
	'active_license'        => ( get_option( "e20r_pw_addon_{$stub}_licensed", false ) == 1 ? true : false ),
	'status'                => 'deactivated',
	// ( 1 == get_option( "e20r_pw_addon_{$stub}_enabled", false ) ? 'active' : 'deactivated' ),
	'label'                 => 'Standard',
	'admin_role'            => 'manage_options',
	'required_plugins_list' => array(
		'paid-memberships-pro/paid-memberships-pro.php' => array(
			'name' => 'Paid Memberships Pro',
			'url'  => 'https://wordpress.org/plugins/paid-memberships-pro/',
		),
	),
);
