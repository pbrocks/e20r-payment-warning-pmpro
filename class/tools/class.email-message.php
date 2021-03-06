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

namespace E20R\Payment_Warning\Tools;

use E20R\Payment_Warning\Editor\Reminder_Editor;
use E20R\Payment_Warning\Payment_Warning;
use E20R\Payment_Warning\User_Data;
use E20R\Utilities\Email_Notice\Send_Email;
use E20R\Utilities\Utilities;

/**
 * Class Email_Message
 * @package E20R\Payment_Warning\Tools
 */
class Email_Message {
	
	/**
	 * Instance of the Email_Message class (singleton)
	 *
	 * @var Email_Message|null
	 */
	private static $instance = null;
	
	/**
	 * Name of the email template
	 *
	 * @var string
	 */
	private $template_name;
	
	/**
	 * Template specific settings
	 *
	 * @var null|array
	 */
	private $template_settings;
	
	/**
	 * User information used by Email_Message
	 *
	 * @var User_Data
	 */
	private $user_info;
	
	/**
	 * Email subject
	 *
	 * @var null|string
	 */
	private $subject = null;
	
	/**
	 * Current WordPress website name
	 *
	 * @var string|null
	 */
	private $site_name = null;
	
	/**
	 * The email address for the site admin (configured on the WP "Settings" -> "General" page)
	 * @var string|null
	 */
	private $site_email = null;
	
	/**
	 * Link to the site's Login page
	 * @var null|string
	 */
	private $login_link = null;
	
	/**
	 * Link to the "Cancel membership" page for the site
	 *
	 * @var null|string
	 */
	private $cancel_link = null;
	
	/**
	 * Email headers used by wp_mail()
	 *
	 * @var array
	 */
	private $headers = array();
	
	/**
	 * The Class handling transmitting of the message
	 *
	 * @var null|Send_Email
	 */
	private $sender = null;
	
	/**
	 * Email substitution variables
	 *
	 * @var array
	 */
	private $variables = array();
	
	/**
	 * Email_Message constructor.
	 *
	 * @param User_Data $user_info
	 * @param string    $template_name
	 * @param int       $type
	 */
	public function __construct( $user_info, $template_name, $type = E20R_PW_RECURRING_REMINDER, $template_settings = null ) {
		
		$util = Utilities::get_instance();
		
		// Default email subject text (translatable)
		$this->subject = sprintf( __( 'Reminder for your %s membership', Payment_Warning::plugin_slug ), '!!sitename!!' );
		
		$this->user_info     = $user_info;
		$this->template_name = $template_name;
		
		// Load the template settings and it's body content
		$this->template_settings = $template_settings;
		
		$this->site_name  = get_option( 'blogname' );
		$this->login_link = wp_login_url();
		
		if ( function_exists( 'pmpro_getOption' ) ) {
			$this->site_email  = pmpro_getOption( 'from_email' );
			$this->cancel_link = wp_login_url( pmpro_url( 'cancel' ) );
		} else {
			$this->site_email = get_option( 'admin_email' );
		}
		
		if ( is_null( self::$instance ) ) {
			self::$instance = $this;
		}
		
		$this->sender          = new Send_Email();
		$this->sender->user_id = $user_info->get_user_ID();
		$this->sender->set_module( Payment_Warning::plugin_slug );
		$this->sender->from = apply_filters( 'e20r-email-notice-sender', $this->site_email );
		
		if ( !empty( $template_settings ) ) {
			$this->sender->body = $template_settings['body'];
		}
		
		$util->log( "Instantiated for {$template_name}/{$type}: " . $user_info->get_user_ID() );
	}
	
	/**
	 * The !!VARIABLE!! substitutions for the current template settings (body & subject of message)
	 *
	 * @param array   $template_settings
	 * @param array   $variables
	 * @param  string $type
	 *
	 * @return array
	 *
	 * @since 1.9.6 - ENHANCEMENT: Made replace_variable_text() function static & a filter hook
	 */
	/*
	public static function replace_variable_text( $template_settings, $variables, $type ) {
		
		$util = Utilities::get_instance();
		$util->log( "Running the variable replacement process for the email messsage" );
		
		foreach ( $variables as $var => $value ) {
			
			$util->log( "Replacing !!{$var}!! with {$value}?" );
			$template_settings['body']    = str_replace( "!!{$var}!!", $value, $template_settings['body'] );
			$template_settings['subject'] = str_replace( "!!{$var}!!", $value, $template_settings['subject'] );
		}
		
		return $template_settings;
	}
	*/
	/**
	 * Help text for supported message type specific substitution variables
	 *
	 * @param string $type
	 *
	 * @return array
	 *
	 * @since 1.9.6 - ENHANCEMENT: Added e20rpw_variable_help filter to result of default_variable_help()
	 */
	public static function default_variable_help( $type ) {
		
		$variables = array(
			'name'                  => __( 'Display Name (User Profile setting) for the user receiving the message', Payment_Warning::plugin_slug ),
			'user_login'            => __( 'Login / username for the user receiving the message', Payment_Warning::plugin_slug ),
			'sitename'              => __( 'The blog name (see General Settings)', Payment_Warning::plugin_slug ),
			'membership_id'         => __( 'The ID of the membership level for the user receiving the message', Payment_Warning::plugin_slug ),
			'membership_level_name' => __( "The active Membership Level name for the user receiving the message  (from the Membership Level settings page)", Payment_Warning::plugin_slug ),
			'siteemail'             => __( "The email address used as the 'From' email when sending this message to the user", Payment_Warning::plugin_slug ),
			'login_link'            => __( "A link to the login page for this site", Payment_Warning::plugin_slug ),
			'display_name'          => __( 'The Display Name for the user receiving the message', Payment_Warning::plugin_slug ),
			'user_email'            => __( 'The email address of the user receiving the message', Payment_Warning::plugin_slug ),
			'currency'              => __( 'The configured currency symbol. (Default: &dollar;)', Payment_Warning::plugin_slug ),
		);
		
		switch ( $type ) {
			case 'recurring':
				
				$variables['cancel_link']         = __( 'A link to the Membership Cancellation page', Payment_Warning::plugin_slug );
				$variables['billing_address']     = __( 'The stored PMPro billing address (formatted)', Payment_Warning::plugin_slug );
				$variables['saved_cc_info']       = __( "The stored Credit Card info for the payment method used when paying for the membership by the user receiving this message. The data is stored in a PCI-DSS compliant manner (the last 4 digits of the card, the type of card, and its expiration date)", Payment_Warning::plugin_slug );
				$variables['next_payment_amount'] = __( "The amount of the upcoming recurring payment for the user who's receving this message", Payment_Warning::plugin_slug );
				$variables['payment_date']        = __( "The date when the recurring payment will be charged to the user's payment method", Payment_Warning::plugin_slug );
				$variables['membership_ends']     = __( "If there is a termination date saved for the recipient's membership, it will be formatted per the 'Settings' => 'General' date settings.", Payment_Warning::plugin_slug );
				
				break;
			
			case 'expiration':
				$variables['membership_ends'] = __( "If there is a termination date saved for the recipient's membership, it will be formatted per the 'Settings' => 'General' date settings.", Payment_Warning::plugin_slug );
				
				break;
			
			case 'ccexpiration':
				
				$variables['billing_address'] = __( 'The stored PMPro billing address (formatted)', Payment_Warning::plugin_slug );
				$variables['saved_cc_info']   = __( "The stored Credit Card info for the payment method used when paying for the membership by the user receiving this message. The data is stored in a PCI-DSS compliant manner (the last 4 digits of the card, the type of card, and its expiration date)", Payment_Warning::plugin_slug );
				
				break;
		}
		
		return apply_filters( 'e20rpw_variable_help', $variables, $type );
	}
	
	/**
	 * Return or instantiate and return the Email_Message class
	 *
	 * @return Email_Message|null
	 */
	public static function get_instance() {
		
		return self::$instance;
	}
	
	/**
	 * Get billing address for the user (filter handler)
	 *
	 * @filter e20r-email-notice-custom-variable-filter
	 *
	 * @param mixed  $value
	 * @param string $var_name
	 * @param int    $user_id
	 * @param array  $settings
	 *
	 * @return string
	 */
	public function get_billing_address( $value, $var_name, $user_id, $settings ) {
		
		if ( 'billing_address' !== $var_name ) {
			return $value;
		}
		
		$utils = Utilities::get_instance();
		$utils->log( "Generate billing address as HTML for {$user_id}/{$var_name}" );
		
		if ( $user_id == $this->user_info->get_user_ID() ) {
			$value = $this->format_billing_address( $user_id );
		}
		
		return $value;
	}
	
	/**
	 * Generate the billing address information stored locally as HTML formatted text
	 *
	 * @return string
	 */
	public function format_billing_address( $user_id ) {
		
		$address = '';
		
		$bfname    = apply_filters( 'e20r-email-notice-billing-firstname', get_user_meta( $user_id, 'pmpro_bfirstname', true ) );
		$blname    = apply_filters( 'e20r-email-notice-billing-lastname', get_user_meta( $user_id, 'pmpro_blastname', true ) );
		$bsaddr1   = apply_filters( 'e20r-email-notice-billing-address1', get_user_meta( $user_id, 'pmpro_baddress1', true ) );
		$bsaddr2   = apply_filters( 'e20r-email-notice-billing-address2', get_user_meta( $user_id, 'pmpro_baddress2', true ) );
		$bcity     = apply_filters( 'e20r-email-notice-billing-city', get_user_meta( $user_id, 'pmpro_bcity', true ) );
		$bpostcode = apply_filters( 'e20r-email-notice-billing-postcode', get_user_meta( $user_id, 'pmpro_bzipcode', true ) );
		$bstate    = apply_filters( 'e20r-email-notice-billing-state', get_user_meta( $user_id, 'pmpro_bstate', true ) );
		$bcountry  = apply_filters( 'e20r-email-notice-billing-country', get_user_meta( $user_id, 'pmpro_bcountry', true ) );
		
		$address = '<div class="e20r-email-notice-billing-address">';
		$address .= sprintf( '<p class="e20r-pw-billing-name">' );
		if ( ! empty( $bfname ) ) {
			$address .= sprintf( '	<span class="e20r-email-notice-billing-firstname">%s</span>', $bfname );
		}
		
		if ( ! empty( $blname ) ) {
			$address .= sprintf( '	<span class="e20r-email-notice-billing-lastname">%s</span>', $blname );
		}
		$address .= sprintf( '</p>' );
		$address .= sprintf( '<p class="e20r-email-notice-billing-address">' );
		if ( ! empty( $bsaddr1 ) ) {
			$address .= sprintf( '%s', $bsaddr1 );
		}
		
		if ( ! empty( $bsaddr1 ) ) {
			$address .= sprintf( '<br />%s', $bsaddr2 );
		}
		
		if ( ! empty( $bcity ) ) {
			$address .= '<br />';
			$address .= sprintf( '<span class="e20r-email-notice-billing-city">%s</span>', $bcity );
		}
		
		if ( ! empty( $bstate ) ) {
			$address .= sprintf( ', <span class="e20r-email-notice-billing-state">%s</span>', $bstate );
		}
		
		if ( ! empty( $bpostcode ) ) {
			$address .= sprintf( '<span class="e20r-email-notice-billing-postcode">%s</span>', $bpostcode );
		}
		
		if ( ! empty( $bcountry ) ) {
			$address .= sprintf( '<br/>><span class="e20r-email-notice-billing-country">%s</span>', $bcountry );
		}
		
		$address .= sprintf( '</p>' );
		$address .= '</div > ';
		
		/**
		 * HTML formatted billing address for the current user (uses PMPro's billing info fields & US formatting by default)
		 *
		 * @filter string e20r-email-notice-formatted-billing-address
		 */
		return apply_filters( 'e20r-email-notice-formatted-billing-address', $address );
	}
	
	/**
	 * Get credit card info for the user (filter handler)
	 *
	 * @filter e20r-email-notice-custom-variable-filter
	 *
	 * @param mixed  $value
	 * @param string $var_name
	 * @param int    $user_id
	 * @param array  $settings
	 *
	 * @return string
	 */
	public function get_cc_info( $value, $var_name, $user_id, $settings ) {
		
		if ( 'saved_cc_info' !== $var_name ) {
			return $value;
		}
		
		$utils = Utilities::get_instance();
		$utils->log( "Generate credit card info list in HTML for {$var_name}/{$user_id}" );
		
		if ( $user_id == $this->user_info->get_user_ID() ) {
			$value = $this->get_html_payment_info();
		}
		
		return $value;
	}
	
	/**
	 * Return the Credit Card information we have on file (formatted for email/HTML use)
	 *
	 * @return string
	 */
	public function get_html_payment_info() {
		
		$util = Utilities::get_instance();
		
		$cc_data         = $this->user_info->get_all_payment_info();
		$billing_page_id = apply_filters( 'e20r-payment-warning-billing-info-page', null );
		$billing_page    = get_permalink( $billing_page_id );
		
		$util->log( "Payment Info: " . print_r( $cc_data, true ) );
		
		if ( ! empty( $cc_data ) ) {
			
			$cc_info = sprintf( '<div class="e20r-payment-warning-cc-descr">%s:', __( 'The following payment source(s) may be used', Payment_Warning::plugin_slug ) );
			
			foreach ( $cc_data as $key => $card_data ) {
				
				$card_description = sprintf(
					__( 'Your %1$s card, ending with the numbers %2$s (Expires: %3$s/%4$s)', Payment_Warning::plugin_slug ),
					$card_data['brand'],
					$card_data['last4'],
					sprintf( '%02d', $card_data['exp_month'] ),
					$card_data['exp_year']
				);
				
				
				$cc_info .= '<p class="e20r-payment-warning-cc-entry">';
				$cc_info .= apply_filters( 'e20r-payment-warning-credit-card-text', $card_description, $card_data );
				$cc_info .= '</p>';
			}
			
			$warning_text = sprintf(
				__( 'Please make sure your %1$sbilling information%2$s is up to date on our system before %3$s', Payment_Warning::plugin_slug ),
				sprintf(
					'<a href="%s" target="_blank" title="%s">',
					esc_url_raw( $billing_page ),
					__( 'Link to update your credit card information', Payment_Warning::plugin_slug )
				),
				'</a>',
				apply_filters( 'e20r-payment-warning-next-payment-date', $this->user_info->get_next_payment() )
			);
			
			$cc_info .= sprintf( '<p>%s</p>', apply_filters( 'e20r-payment-warning-cc-billing-info-warning', $warning_text ) );
			$cc_info .= '</div>';
			
		} else {
			$cc_info = '<p>' . sprintf( __( "Payment Type: %s", Payment_Warning::plugin_slug ), $this->user_info->get_last_pmpro_order()->payment_type ) . '</p>';
		}
		
		return $cc_info;
	}
	
	/**
	 * Load the expected template substitution data for the specified template name;
	 *
	 * @param string $template_type
	 * @param bool   $force
	 *
	 * @return array
	 */
	/**
	 * public function configure_default_data( $template_type = null, $force = false ) {
	 *
	 * $util  = Utilities::get_instance();
	 * $data  = array();
	 * $level = null;
	 *
	 * global $pmpro_currency_symbol;
	 *
	 * $util->log( "Processing for {$template_type}" );
	 *
	 * if ( function_exists( 'pmpro_getLevel' ) ) {
	 * $level = pmpro_getMembershipLevelForUser( $this->user_info->get_user_ID() );
	 * }
	 *
	 * $level = apply_filters( 'e20r_pw_get_user_level', $level, $this->user_info );
	 *
	 * switch ( $template_type ) {
	 *
	 * case 'recurring':
	 *
	 * $data = array(
	 * 'name'                  => $this->user_info->get_user_name(),
	 * 'user_login'            => $this->user_info->get_user_login(),
	 * 'sitename'              => $this->site_name,
	 * 'membership_id'         => $this->user_info->get_membership_level_ID(),
	 * 'membership_level_name' => $this->user_info->get_level_name(),
	 * 'siteemail'             => $this->site_email,
	 * 'login_link'            => $this->login_link,
	 * 'display_name'          => $this->user_info->get_user_name(),
	 * 'user_email'            => $this->user_info->get_user_email(),
	 * 'currency'              => $pmpro_currency_symbol,
	 * );
	 *
	 * $data['cancel_link']         = $this->cancel_link;
	 * $data['billing_info']        = $this->sender->format_billing_address();
	 * $data['saved_cc_info']       = $this->get_html_payment_info();
	 * $next_payment                = $this->user_info->get_next_payment();
	 * $data['next_payment_amount'] = $this->user_info->get_next_payment_amount();
	 *
	 * $util->log( "Using {$next_payment} as next payment date" );
	 * $data['payment_date'] = ! empty( $next_payment ) ? date_i18n( get_option( 'date_format' ), strtotime(
	 * $next_payment, current_time( 'timestamp' ) ) ) : 'Not found';
	 *
	 * $enddate = $this->user_info->get_end_of_membership_date();
	 *
	 * if ( ! empty( $enddate ) ) {
	 * $formatted_date = date_i18n( get_option( 'date_format' ), strtotime( $enddate, current_time( 'timestamp' ) ) );
	 * $util->log( "Using {$formatted_date} as membership end date" );
	 * $data['membership_ends'] = $formatted_date;
	 * } else {
	 * $data['membership_ends'] = 'N/A';
	 * }
	 *
	 * break;
	 *
	 * case 'expiration':
	 *
	 * $data = array(
	 * 'name'                  => $this->user_info->get_user_name(),
	 * 'user_login'            => $this->user_info->get_user_login(),
	 * 'sitename'              => $this->site_name,
	 * 'membership_id'         => $this->user_info->get_membership_level_ID(),
	 * 'membership_level_name' => $this->user_info->get_level_name(),
	 * 'siteemail'             => $this->site_email,
	 * 'login_link'            => $this->login_link,
	 * 'display_name'          => $this->user_info->get_user_name(),
	 * 'user_email'            => $this->user_info->get_user_email(),
	 * 'currency'              => $pmpro_currency_symbol,
	 * );
	 *
	 * $enddate = $this->user_info->get_end_of_membership_date();
	 *
	 * if ( ! empty( $enddate ) ) {
	 * $formatted_date = date_i18n( get_option( 'date_format' ), strtotime( $enddate, current_time( 'timestamp' ) ) );
	 * $util->log( "Using {$formatted_date} as membership end date" );
	 * $data['membership_ends'] = $formatted_date;
	 * } else {
	 * $data['membership_ends'] = 'Not recorded';
	 * }
	 *
	 * break;
	 *
	 * case 'ccexpiration':
	 * $data = array(
	 * 'name'                  => $this->user_info->get_user_name(),
	 * 'user_login'            => $this->user_info->get_user_login(),
	 * 'sitename'              => $this->site_name,
	 * 'membership_id'         => $this->user_info->get_membership_level_ID(),
	 * 'membership_level_name' => $this->user_info->get_level_name(),
	 * 'siteemail'             => $this->site_email,
	 * 'login_link'            => $this->login_link,
	 * 'display_name'          => $this->user_info->get_user_name(),
	 * 'user_email'            => $this->user_info->get_user_email(),
	 * 'currency'              => $pmpro_currency_symbol,
	 * );
	 *
	 * $data['billing_info']  = $this->sender->format_billing_address();
	 * $data['saved_cc_info'] = $this->get_html_payment_info();
	 *
	 * break;
	 * }
	 *
	 * return $data;
	 * }
	 */
	/**
	 * Determine whether or not to send the current message (to the user)
	 *
	 * @param string $comparison_date
	 * @param int    $interval
	 * @param string $type
	 *
	 * @return boolean
	 */
	public function should_send( $comparison_date, $interval, $type ) {
		
		$util = Utilities::get_instance();
		
		$send = false; // Assume we shouldn't send (unless one of the message handlers tells us otherwise
		
		$util->log( "Applying the send-email filter check for {$type}: {$comparison_date} and {$interval}" );
		
		return apply_filters( 'e20r-payment-warning-send-email', $send, $comparison_date, $interval, $type );
	}
	
	/**
	 * Attempt to hand the email message off to the email sub-system
	 *
	 * @param string $type Message/Template type to send to the specified/defined user
	 *
	 * @return bool
	 *
	 * @since 1.9.6 - BUG FIX: Variable substitution for messages providing incorrect information
	 * @since 1.9.7 - BUG FIX: Extra slashes in Subject
	 * @since 3.9.0 - ENHANCEMENT: Refactored send_message() method
	 */
	public function send_message( $type ) {
		
		$util = Utilities::get_instance();
		
		$util->log( "Preparing email type: {$type} for {$this->template_name}" );
		$to      = $this->user_info->get_user_email();
		$user_id = $this->user_info->get_user_ID();
		
		$today  = date( 'Y-m-d', current_time( 'timestamp' ) );
		$users  = get_option( "e20r_pw_sent_{$type}", array( $today => array() ) );
		$status = false;
		
		// Option is empty
		if ( empty( $users ) ) {
			$users[ $today ]        = array();
			$users[ $today ][ $to ] = array();
		}
		
		$override = apply_filters( 'e20r-email-notice-send-override', false );
		
		/**
		 * @since 3.9.0 - ENHANCEMENT: Refactored send_message() method
		 */
		if ( false === $override && ( isset( $users[ $today ][ $to ] ) && in_array( $this->template_name, $users[ $today ][ $to ] ) ) ) {
			
			$util->log( "Already sent message {$this->template_name} on {$today} to {$to}" );
			return $status;
		}
		
		/**
		 * Process message to possibly send to user
		 */
		
		/** @since 1.9.7 - BUG FIX: Extra slashes in subject */
		$this->subject = apply_filters( 'e20r-email-notice-subject', wp_unslash( $this->template_settings['subject'] ), $type, $to, $user_id );
		$this->subject = $this->sender->substitute_in_text( $this->subject, $type );
		
		$util->log( "Sending message to {$to} -> " . $this->subject );
		$this->sender->template = $this->template_name;
		
		// Add filters for billing address & CC info
		$util->log( "Loading filter handlers for 'e20r-email-notice-custom-variable-filter'" );
		add_filter( 'e20r-email-notice-custom-variable-filter', array( $this, 'get_cc_info' ), 10, 4 );
		add_filter( 'e20r-email-notice-custom-variable-filter', array( $this, 'get_billing_address' ), 11, 4 );
		add_filter( 'e20r-email-notice-custom-variable-filter', array( Reminder_Editor::get_instance(), 'load_filter_value' ), 99, 4 );
		
		add_filter( 'e20r-email-notice-content-body', array( $this, 'load_message_body' ), 10, 2 );
		add_filter( 'e20r-email-notice-data-variables', array( Reminder_Editor::get_instance(), 'default_data_variables' ), 10, 2 );
		add_filter( 'e20r-email-notice-membership-level-for-user', array( Reminder_Editor::get_instance(), 'get_member_level_for_user' ), 10, 3 );
		add_filter( 'e20r-email-notice-membership-page-for-user', array( Reminder_Editor::get_instance(), 'get_member_page_for_user' ), 10, 3 );
		add_filter( 'e20r-payment-warning-billing-info-page', array( Reminder_Editor::get_instance(), 'load_billing_page' ), 10, 1 );
		
		/**
		 * @since v3.9.0 - BUG FIX: Didn't load the body of the email message
		 */
		add_filter( 'e20r-email-notice-loaded', '__return_true' );
		
		if ( true === ( $status = $this->sender->send( $to, null, null, $this->subject, $this->template_name, $type ) ) ) {
			
			$util->log( "Recording that we attempted to send a {$type}/{$this->template_name} message to: {$to}" );
			
			if ( ! isset ( $users[ $today ] ) ) {
				
				$util->log( "Adding today's entries to the list of users we've sent {$type} warning messages to" );
				
				$users[ $today ] = array();
				
				if ( count( $users ) > 1 ) {
					
					$util->log( "Cleaning up the array of users" );
					$new   = array( $today => array() );
					$users = array_intersect_key( $users, $new );
				}
			}
			
			if ( ! isset( $users[ $today ][ $to ] ) || ! is_array( $users[ $today ][ $to ] ) ) {
				$users[ $today ][ $to ] = array();
			}
			
			$users[ $today ][ $to ][] = $this->template_name;
			
			update_option( "e20r_pw_sent_{$type}", $users, 'no' );
			
		} else {
			$util->log( "Error sending message {$this->template_name}/{$type} to {$to}" );
		}
		
		return $status;
	}
	
	/**
	 * @param string $body
	 * @param string|int $template_slug
	 *
	 * @return string
	 */
	public function load_message_body( $body, $template_slug ) {
		
		$utils = Utilities::get_instance();
		
		if ( 1 === preg_match( "/{$template_slug}/i", $this->template_name ) ) {
			$utils->log("Already loaded body for {$template_slug}");
			return $this->get_body();
		}
		
		return $body;
	}
	
	/**
	 * Set the !!VARIABLE!! substitutions for the email message(s)
	 *
	 * @param array  $variables
	 * @param string $type ( 'recurring' or 'expiration' )
	 *
	 * @return array
	 *
	 * @since 1.9.6 - ENHANCEMENT: Apply variable substitution via filter for template(s)/message type(s)
	 */
	public function set_variable_pairs( $variables, $type ) {
		
		return apply_filters( 'e20r_pw_message_substitution_variables', $this->template_settings, $variables, $type );
	}
	
	/**
	 * Return the name of the template being used for this email message
	 *
	 * @return string
	 */
	public function get_template_name() {
		return $this->template_name;
	}
	
	/**
	 * Return the type of template being used for this email message
	 *
	 * @return string|int
	 */
	public function get_template_type() {
		return $this->template_settings['type'];
	}
	
	/**
	 * Return the defined send schedule for this email message
	 *
	 * @return int[]
	 */
	public function get_schedule() {
		return $this->template_settings['schedule'];
	}
	
	/**
	 * Return the body (text/html) of this email message
	 *
	 * @return string
	 */
	public function get_body() {
		return $this->template_settings['body'];
	}
	
	/**
	 * Get the User_Data for this email message
	 *
	 * @return User_Data
	 */
	public function get_user() {
		return $this->user_info;
	}
}
