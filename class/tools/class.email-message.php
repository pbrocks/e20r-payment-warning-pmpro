<?php
/**
 * Copyright (c) 2017 - Eighty / 20 Results by Wicked Strong Chicks.
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

use E20R\Payment_Warning\Payment_Warning;
use E20R\Payment_Warning\User_Data;
use E20R\Payment_Warning\Editor\Editor;
use E20R\Utilities\Utilities;

class Email_Message {
	
	private static $instance = null;
	
	private $template_name;
	
	private $template_settings;
	
	private $user_info;
	
	private $subject = null;
	
	private $site_name = null;
	
	private $site_email = null;
	
	private $login_link = null;
	
	private $cancel_link = null;
	
	private $headers = array();
	
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
	 * @param string    $type
	 */
	public function __construct( $user_info, $template_name, $type = 'recurring' ) {
		
		$util = Utilities::get_instance();
		
		// Default email subject text (translatable)
		$this->subject = sprintf( __( 'Reminder for your %s membership', Payment_Warning::plugin_slug ), '!!sitename!!' );
		
		$this->user_info     = $user_info;
		$this->template_name = $template_name;
		
		// Load the template settings and it's body content
		$this->template_settings = Editor::get_template_by_name( $this->template_name, true );
		
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
		
		$util->log( "Instantiated for {$template_name}: " . $user_info->get_user_ID() );
	}
	
	/**
	 * Load the expected template substitution data for the specified template name;
	 *
	 * @param string $template_type
	 * @param bool   $force
	 *
	 * @return array
	 */
	public function configure_default_data( $template_type = null, $force = false ) {
		
		$util  = Utilities::get_instance();
		$data  = array();
		$level = null;
		
		global $pmpro_currency_symbol;
		
		$util->log( "Processing for {$template_type}" );
		
		if ( function_exists( 'pmpro_getLevel' ) ) {
			$level = pmpro_getMembershipLevelForUser( $this->user_info->get_user_ID() );
		}
		
		$level = apply_filters( 'e20r_pw_get_user_level', $level, $this->user_info );
		
		switch ( $template_type ) {
			
			case 'recurring':
				
				$data = array(
					'name'                  => $this->user_info->get_user_name(),
					'user_login'            => $this->user_info->get_user_login(),
					'sitename'              => $this->site_name,
					'membership_id'         => $this->user_info->get_membership_level_ID(),
					'membership_level_name' => $this->user_info->get_level_name(),
					'siteemail'             => $this->site_email,
					'login_link'            => $this->login_link,
					'display_name'          => $this->user_info->get_user_name(),
					'user_email'            => $this->user_info->get_user_email(),
					'currency'              => $pmpro_currency_symbol,
				);
				
				$data['cancel_link']         = $this->cancel_link;
				$data['billing_info']        = $this->format_billing_address();
				$data['saved_cc_info']       = $this->get_html_payment_info();
				$next_payment                = $this->user_info->get_next_payment();
				$data['next_payment_amount'] = $this->user_info->get_next_payment_amount();
				
				$util->log( "Using {$next_payment} as next payment date" );
				$data['payment_date'] = ! empty( $next_payment ) ? date_i18n( get_option( 'date_format' ), strtotime( $next_payment, current_time( 'timestamp' ) ) ) : 'Not found';
				
				$enddate = $this->user_info->get_end_of_membership_date();
				$util->log( "Using {$enddate} as membership end date" );
				$data['membership_ends'] = ( ! empty( $enddate ) ? $enddate : 'N/A' );
				
				break;
			
			case 'expiration':
				
				$data = array(
					'name'                  => $this->user_info->get_user_name(),
					'user_login'            => $this->user_info->get_user_login(),
					'sitename'              => $this->site_name,
					'membership_id'         => $this->user_info->get_membership_level_ID(),
					'membership_level_name' => $this->user_info->get_level_name(),
					'siteemail'             => $this->site_email,
					'login_link'            => $this->login_link,
					'display_name'          => $this->user_info->get_user_name(),
					'user_email'            => $this->user_info->get_user_email(),
					'currency'              => $pmpro_currency_symbol,
				);
				
				$enddate = $this->user_info->get_end_of_membership_date();
				$util->log( "Using {$enddate} as membership end date" );
				$data['membership_ends'] = ! empty( $enddate ) ? $enddate : 'Not recorded';
				
				break;
			
			case 'ccexpiration':
				$data = array(
					'name'                  => $this->user_info->get_user_name(),
					'user_login'            => $this->user_info->get_user_login(),
					'sitename'              => $this->site_name,
					'membership_id'         => $this->user_info->get_membership_level_ID(),
					'membership_level_name' => $this->user_info->get_level_name(),
					'siteemail'             => $this->site_email,
					'login_link'            => $this->login_link,
					'display_name'          => $this->user_info->get_user_name(),
					'user_email'            => $this->user_info->get_user_email(),
					'currency'              => $pmpro_currency_symbol,
				);
				
				$data['billing_info']  = $this->format_billing_address();
				$data['saved_cc_info'] = $this->get_html_payment_info();
				
				break;
		}
		
		return $data;
	}
	
	/**
	 * Return the Credit Card information we have on file (formatted for email/HTML use)
	 *
	 * @return string
	 */
	public function get_html_payment_info() {
		
		$util = Utilities::get_instance();
		
		$payment_info = $this->user_info->get_all_payment_info();
		
		$util->log( "Payment Info: " . print_r( $payment_info, true ) );
		
		if ( ! empty( $payment_info ) ) {
			
			$cc_data = sprintf( '<div class="e20r-payment-warning-cc-descr">%s:', __( 'The following payment source(s) will be used', Payment_Warning::plugin_slug ) );
			
			
			foreach ( $payment_info as $key => $card_data ) {
				
				$card_description = sprintf( __( 'Your %s card ending in %s ( Expires: %s/%s )', Payment_Warning::plugin_slug ), $card_data['brand'], $card_data['last4'], sprintf( '%02d', $card_data['exp_month'] ), $card_data['exp_year'] ) . '<br />';
				
				$cc_data .= '<p class="e20r-payment-warning-cc-entry">';
				$cc_data .= apply_filters( 'e20r-payment-warning-credit-card-text', $card_description, $card_data );
				$cc_data .= '</p>';
			}
			
			
			$cc_data .= sprintf( '<p>%s</p>', apply_filters( 'e20r-payment-warning-cc-billing-info-warning', __( 'Please make sure your billing information is current on our system', Payment_Warning::plugin_slug ) ) );
			$cc_data .= '</div>';
			
		} else {
			$cc_data = '<p>' . sprintf( __( "Payment Type: %s", Payment_Warning::plugin_slug ), $this->user_info->get_last_pmpro_order()->payment_type ) . '</p>';
		}
		
		return $cc_data;
	}
	
	/**
	 * Generate the billing address information stored locally as HTML formatted text
	 *
	 * @return string
	 */
	public function format_billing_address() {
		
		$address = '';
		$user_id = $this->user_info->get_user_ID();
		
		$bfname    = apply_filters( 'e20r-payment_warning-billing-firstname', get_user_meta( $user_id, 'pmpro_bfirstname', true ) );
		$blname    = apply_filters( 'e20r-payment_warning-billing-lastname', get_user_meta( $user_id, 'pmpro_blastname', true ) );
		$bsaddr1   = apply_filters( 'e20r-payment_warning-billing-address1', get_user_meta( $user_id, 'pmpro_baddress1', true ) );
		$bsaddr2   = apply_filters( 'e20r-payment_warning-billing-address2', get_user_meta( $user_id, 'pmpro_baddress2', true ) );
		$bcity     = apply_filters( 'e20r-payment_warning-billing-city', get_user_meta( $user_id, 'pmpro_bcity', true ) );
		$bpostcode = apply_filters( 'e20r-payment_warning-billing-postcode', get_user_meta( $user_id, 'pmpro_bzipcode', true ) );
		$bstate    = apply_filters( 'e20r-payment_warning-billing-state', get_user_meta( $user_id, 'pmpro_bstate', true ) );
		$bcountry  = apply_filters( 'e20r-payment_warning-billing-country', get_user_meta( $user_id, 'pmpro_bcountry', true ) );
		
		$address = '<div class="e20r-pw-billing-address">';
		$address .= sprintf( '<p class="e20r-pw-billing-name">' );
		if ( ! empty( $bfname ) ) {
			$address .= sprintf( '	<span class="e20r-pw-billing-firstname">%s</span>', $bfname );
		}
		
		if ( ! empty( $blname ) ) {
			$address .= sprintf( '	<span class="e20r-pw-billing-lastname">%s</span>', $blname );
		}
		$address .= sprintf( '</p>' );
		$address .= sprintf( '<p class="e20r-pw-billing-address">' );
		if ( ! empty( $bsaddr1 ) ) {
			$address .= sprintf( '%s', $bsaddr1 );
		}
		
		if ( ! empty( $bsaddr1 ) ) {
			$address .= sprintf( '<br />%s', $bsaddr2 );
		}
		
		if ( ! empty( $bcity ) ) {
			$address .= '<br />';
			$address .= sprintf( '<span class="e20r-pw-billing-city">%s</span>', $bcity );
		}
		
		if ( ! empty( $bstate ) ) {
			$address .= sprintf( ', <span class="e20r-pw-billing-state">%s</span>', $bstate );
		}
		
		if ( ! empty( $bpostcode ) ) {
			$address .= sprintf( '<span class="e20r-pw-billing-postcode">%s</span>', $bpostcode );
		}
		
		if ( ! empty( $bcountry ) ) {
			$address .= sprintf( '<br/>><span class="e20r-pw-billing-country">%s</span>', $bcountry );
		}
		
		$address .= sprintf( '</p>' );
		$address .= '</div > ';
		
		/**
		 * HTML formatted billing address for the current user (uses PMPro's billing info fields & US formatting by default)
		 *
		 * @filter string e20r_payment_warning_billing_address
		 */
		return apply_filters( 'e20r_payment_warning_billing_address', $address );
		
	}
	
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
	 */
	public function send_message( $type ) {
		
		$util = Utilities::get_instance();
		
		$util->log( "Preparing email type: {$type}" );
		$to = $this->user_info->get_user_email();
		
		$users  = get_option( "e20r_pw_sent_{$type}", array() );
		$today  = date_i18n( 'Y-m-d', current_time( 'timestamp' ) );
		$status = false;
		
		// Option is empty
		if ( empty( $users ) ) {
			$users[$today] = array();
			$users[$today][$to] = false;
		}
		
		// Process possible message for user
		if ( ! isset( $users[ $today ][ $to ] ) || ( isset( $users[ $today ][ $to ] ) && false == $users[ $today ][ $to ] ) ) {
			
			$variables = $this->configure_default_data( $type );
			
			$this->set_variable_pairs( $variables, $type );
			$util->log( "Using variables: " . print_r( $this->variables, true ) );
			$this->replace_variable_text();
			$this->prepare_headers();
			
			$this->subject = apply_filters( 'e20r-payment-warning-email-subject', $this->template_settings['subject'], $type );
			
			$util->log( "Sending message to {$to} -> " . $this->subject );
			$status = wp_mail( $to, $this->subject, wp_unslash( $this->template_settings['body'] ), $this->headers );
			
			if ( true == $status ) {
				
				$util->log( "Recording that we attempted to send a {$type} message to: {$to}" );
				
				if ( ! isset ( $users[ $today ] ) ) {
					
					$util->log( "Adding today's entries to the list of users we've sent {$type} warning messages to" );
					
					$users[ $today ] = array();
					
					if ( count( $users ) > 1 ) {
						
						$util->log( "Cleaning up the array of users" );
						$new   = array( $today => array() );
						$users = array_intersect_key( $users, $new );
					}
				}
				
				$users[ $today ][ $to ] = true;
				update_option( "e20r_pw_sent_{$type}", $users, false );
			} else {
				$util->log( "Error sending {$type} message to {$to}" );
			}
		} else {
			$util->log( "Already sent message on {$today} to {$to}" );
		}
		
		return $status;
	}
	
	/**
	 * @filter Action Hook for the wp_mail PHPMail exception handler
	 *
	 * @param \WP_Error $error
	 */
	public static function email_error_handler( \WP_Error $error ) {
		
		$util       = Utilities::get_instance();
		$error_data = $error->get_error_data( 'wp_mail_failed' );
		
		$util->log( "Error while attempting to send the email message for {$error_data['to']}/{$error_data['subject']}" );
		$util->log( "Actual PHPMailer error: " . $error->get_error_message( 'wp_mail_failed' ) );
	}
	
	/**
	 * Prepare the header content for the email message
	 */
	public function prepare_headers() {
		
		/**
		 * @filter string[] e20r-payment-warning-cc-list List of email addresses to Carbon Copy (visible list) for these payment/expiration warning messages
		 */
		$cc_list = apply_filters( 'e20r-payment-warning-cc-list', array() );
		
		/**
		 * @filter string[] e20r-payment-warning-bcc-list List of email addresses to Blind Copy (invisible list) for these payment/expiration warning messages
		 */
		$bcc_list = apply_filters( 'e20r-payment-warning-bcc-list', array() );
		
		/**
		 * @filter string e20r-payment-warning-sender-email Email address (formatted: '[ First Last | Site Name ] <email@example.com>' )
		 */
		$from = apply_filters( 'e20r-payment-warning-sender-email', "{$this->site_name} <{$this->site_email}>" );
		
		/**
		 * @filter string e20r-payment-warning-email-content-type Default eMail content type (HTML in UTF-8)
		 */
		$content_type = apply_filters( 'e20r-payment-warning-email-content-type', 'Content-Type: text/html; charset=UTF-8' );
		
		// Process all headers
		if ( ! empty( $cc_list ) ) {
			
			foreach ( $cc_list as $cc ) {
				$this->headers[] = "Cc: {$cc}";
			}
		}
		
		if ( ! empty( $bcc_list ) ) {
			foreach ( $bcc_list as $bcc ) {
				$this->headers[] = "Bcc: {$bcc}";
			}
		}
		
		if ( ! empty( $from ) ) {
			
			$this->headers[] = "From: {$from}";
		}
		
		if ( ! empty( $content_type ) ) {
			$this->headers[] = $content_type;
		}
	}
	
	/**
	 * Set the !!VARIABLE!! substitutions for the email message(s)
	 *
	 * @param array  $variables
	 * @param string $type ( 'recurring' or 'expiration' )
	 */
	public function set_variable_pairs( $variables, $type ) {
		
		$this->variables = apply_filters( 'e20r_pw_handler_substitution_variables', $variables, $type );
	}
	
	public static function default_variable_pairs( $variables, $type ) {
		
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
				$variables['billing_info']        = __( 'The stored PMPro billing information (formatted)', Payment_Warning::plugin_slug );
				$variables['saved_cc_info']       = __( "The stored Credit Card info for the payment method used when paying for the membership by the user receiving this message. The data is stored in a PCI-DSS compliant manner (the last 4 digits of the card, the type of card, and its expiration date)", Payment_Warning::plugin_slug );
				$variables['next_payment_amount'] = __( "The amount of the upcoming recurring payment for the user who's receving this message", Payment_Warning::plugin_slug );
				$variables['payment_date']        = __( "The date when the recurring payment will be charged to the user's payment method", Payment_Warning::plugin_slug );
				$variables['membership_ends']     = __( "If there is a termination date saved for the recipient's membership, it will be formatted per the 'Settings' => 'General' date settings.", Payment_Warning::plugin_slug );
				
				break;
			
			case 'expiration':
				$variables['membership_ends'] = __( "If there is a termination date saved for the recipient's membership, it will be formatted per the 'Settings' => 'General' date settings.", Payment_Warning::plugin_slug );
				
				break;
			
			case 'ccexpiration':
				
				$variables['billing_info']  = __( 'The stored PMPro billing information (formatted)', Payment_Warning::plugin_slug );
				$variables['saved_cc_info'] = __( "The stored Credit Card info for the payment method used when paying for the membership by the user receiving this message. The data is stored in a PCI-DSS compliant manner (the last 4 digits of the card, the type of card, and its expiration date)", Payment_Warning::plugin_slug );
				
				break;
		}
		
		return $variables;
	}
	
	/**
	 * The !!VARIABLE!! substitutions for the current template body message
	 */
	public function replace_variable_text() {
		
		$util = Utilities::get_instance();
		$util->log( "Running the variable replacement process for the email messsage" );
		
		foreach ( $this->variables as $var => $value ) {
			
			$util->log( "Replacing !!{$var}!! with {$value}?" );
			$this->template_settings['body']    = str_replace( "!!{$var}!!", $value, $this->template_settings['body'] );
			$this->template_settings['subject'] = str_replace( "!!{$var}!!", $value, $this->template_settings['subject'] );
		}
	}
	
	public function get_template_type() {
		return $this->template_settings['type'];
	}
	
	public function get_schedule() {
		return $this->template_settings['schedule'];
	}
	
	public function get_body() {
		return $this->template_settings['body'];
	}
	
	public function get_user() {
		return $this->user_info;
	}
	
	/**
	 * Return or instantiate and return the Email_Message class
	 *
	 * @return Email_Message|null
	 */
	public static function get_instance() {
		
		return self::$instance;
	}
}