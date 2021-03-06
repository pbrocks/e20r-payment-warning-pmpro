<?php
/**
 * Copyright (c) 2018 - Eighty / 20 Results by Wicked Strong Chicks.
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

use E20R\Utilities\Utilities;
use E20R\Utilities\Cache;
use E20R\Utilities\Licensing\Licensing;
use E20R\Payment_Warning\Payment_Warning;

class Global_Settings {
	
	/**
	 * Instance of this class (Global_Settings)
	 *
	 * @var Global_Settings|null $instance
	 *
	 * @access private
	 * @since  1.0
	 */
	static private $instance = null;
	
	private $addon_settings = array();
	
	private $settings_page_hook = null;
	
	private $settings_name = 'e20r_payment_warning';
	
	private $settings = array();
	
	/**
	 * Global_Settings constructor.
	 *
	 * @access private
	 * @since  1.0
	 */
	private function __construct() {}
	
	/**
	 * Returns the instance of this class (singleton pattern)
	 *
	 * @return Global_Settings
	 *
	 * @access public
	 * @since  1.0
	 */
	static public function get_instance() {
		
		if ( is_null( self::$instance ) ) {
			
			self::$instance = new self;
		}
		
		return self::$instance;
	}
	
	/**
	 * Load settings/options for the plugin
	 *
	 * @param $option_name
	 *
	 * @return bool|mixed
	 */
	public static function load_options( $option_name ) {
		
		$class = self::get_instance();
		$settings = $class->get_settings();
		
		if ( isset( $settings[ $option_name ] ) && ! empty( $settings[ $option_name ] ) ) {
			
			return $settings[ $option_name ];
		}
		
		return false;
	}
	
	/**
	 * Loads the settings for this plugin from the WP Database
	 *
	 * @return array
	 */
	private function get_settings() {
		
		$this->settings = get_option( "{$this->settings_name}", $this->default_settings() );
		
		return $this->settings;
	}
	
	/**
	 * Validating the returned values from the Settings API page on save/submit
	 *
	 * @param array $input Changed values from the settings page
	 *
	 * @return array Validated array
	 *
	 * @since  1.0
	 * @since  2.1 - BUG FIX: Didn't clear the Active Addon cache when saving the Options page
	 *
	 * @access public
	 */
	public function validate_settings( $input ) {
		
		global $e20r_pw_addons;
		
		$utils = Utilities::get_instance();
		
		$utils->log( "E20R Payment Warning input settings: " . print_r( $input, true ) );
		
		foreach ( $e20r_pw_addons as $addon_name => $settings ) {
			
			$utils->log( "Trigger local toggle_addon action for {$addon_name}: is_active = " . ( isset( $input["is_{$addon_name}_active"] ) ? 'Yes' : 'No' ) );
			
			do_action( 'e20r_pw_addon_toggle_addon', $addon_name, isset( $input["is_{$addon_name}_active"] ) );
		}
		
		$defaults = $this->default_settings();
		
		foreach ( $defaults as $key => $value ) {
			
			if ( isset( $input[ $key ] ) ) {
				$this->settings[ $key ] = $input[ $key ];
			} else {
				$this->settings[ $key ] = $defaults[ $key ];
			}
		}
		
		// Force update of the active addon cache
		Cache::delete( 'e20r_pw_active_addons', Payment_Warning::cache_group );
		
		// Validated & updated settings
		return $this->settings;
	}
	
	/**
	 * Configure the default (global) settings for this add-on
	 * @return array
	 */
	private function default_settings() {
		
		return array(
			'deactivation_reset'            => false,
			'enable_gateway_fetch'          => false,
			'enable_expiration_warnings'    => false,
			'enable_payment_warnings'       => false,
			'enable_cc_expiration_warnings' => false,
			'company_name' => null,
			'company_address' => null,
		);
	}
	
	/**
	 * Generate the options page for this plugin
	 */
	public function load_admin_settings_page() {
		
		$utils = Utilities::get_instance();
		
		$utils->log( "Loading options page for Payment Warnings" );
		
		$this->settings_page_hook = add_options_page(
			__( "Payment Warnings for Paid Memberships Pro", Payment_Warning::plugin_slug ),
			__( "Payment Warnings", Payment_Warning::plugin_slug ),
			apply_filters( 'e20rpw_min_settings_capabilities', 'manage_options' ),
			'e20r-payment-warning-settings',
			array( $this, 'global_settings_page' )
		);
		
		Licensing::add_options_page();
	}
	
	/**
	 * Configure options page for the plugin and include any configured add-ons if needed.
	 */
	public function register_settings_page() {
		
		$utils = Utilities::get_instance();
		$utils->log( "Register settings for Payment Warnings" );
		
		// Configure our own settings
		register_setting( Payment_Warning::option_group, "{$this->settings_name}", array(
			$this,
			'validate_settings',
		) );
		
		$utils->log( "Added Global Settings" );
		add_settings_section(
			'e20r_pw_global',
			__( 'Global Settings: E20R Payment Warnings for PMPro', Payment_Warning::plugin_slug ),
			array( $this, 'render_global_settings_text', ),
			'e20r-payment-warning-settings'
		);
		
		add_settings_field(
			'e20r_pw_global_reset',
			__( "Reset data on deactivation", Payment_Warning::plugin_slug ),
			array( $this, 'render_checkbox' ),
			'e20r-payment-warning-settings',
			'e20r_pw_global',
			array( 'option_name' => 'deactivation_reset' )
		);
		
		add_settings_field(
			'e20r_pw_company_name',
			__( "Company Name", Payment_Warning::plugin_slug ),
			array( $this, 'render_input' ),
			'e20r-payment-warning-settings',
			'e20r_pw_global',
			array( 'option_name' => 'company_name' )
		);
		
		add_settings_field(
			'e20r_pw_company_address',
			__( "Company Address", Payment_Warning::plugin_slug ),
			array( $this, 'render_textarea' ),
			'e20r-payment-warning-settings',
			'e20r_pw_global',
			array( 'option_name' => 'company_address' )
		);
		
		/**
		 *                 'enable_payment_warnings' => false,
		 * 'enable_cc_expiration_warnings' => false,
		 */
		add_settings_field(
			'e20r_pw_global_gateway_fetch',
			__( "Fetch data: Payment Gateways", Payment_Warning::plugin_slug ),
			array( $this, 'render_checkbox' ),
			'e20r-payment-warning-settings',
			'e20r_pw_global',
			array( 'option_name' => 'enable_gateway_fetch' )
		);
		
		add_settings_section(
			'e20r_pw_messages',
			__( 'Active Message Types', Payment_Warning::plugin_slug ),
			array( $this, 'render_message_type_text', ),
			'e20r-payment-warning-settings'
		);
		
		add_settings_field(
			'e20r_pw_global_expiration_warning',
			__( "Membership Expiration", Payment_Warning::plugin_slug ),
			array( $this, 'render_checkbox' ),
			'e20r-payment-warning-settings',
			'e20r_pw_messages',
			array( 'option_name' => 'enable_expiration_warnings' )
		);
		
		add_settings_field(
			'e20r_pw_global_payment_warnings',
			__( "Recurring Payment", Payment_Warning::plugin_slug ),
			array( $this, 'render_checkbox' ),
			'e20r-payment-warning-settings',
			'e20r_pw_messages',
			array( 'option_name' => 'enable_payment_warnings' )
		);
		
		add_settings_field(
			'e20r_pw_global_cc_warning',
			__( "Credit Card Expiration", Payment_Warning::plugin_slug ),
			array( $this, 'render_checkbox' ),
			'e20r-payment-warning-settings',
			'e20r_pw_messages',
			array( 'option_name' => 'enable_cc_expiration_warnings' )
		);
		
		$utils->log( "Added Add-on Settings for Payment Warnings" );
		add_settings_section(
			'e20r_pw_addons',
			__( 'Gateways', Payment_Warning::plugin_slug ),
			array( $this, 'render_addon_header' ),
			'e20r-payment-warning-settings'
		);
		
		global $e20r_pw_addons;
		
		/*
		if ( WP_DEBUG ) {
			error_log("Register Settings - List of add-ons: " . print_r( $e20r_payment_gateways, true ));
		}
		*/
		foreach ( $e20r_pw_addons as $addon => $settings ) {
			
			$utils->log( "Adding settings for {$addon}: {$settings['label']}" );
			
			add_settings_field(
				"e20r_pw_addons_{$addon}",
				$settings['label'],
				array( $this, 'render_addon_entry' ),
				'e20r-payment-warning-settings',
				'e20r_pw_addons',
				$settings
			);
		}
		
		// Load/Register settings for all active add-ons
		foreach ( $e20r_pw_addons as $name => $info ) {
			
			$utils->log( "Settings for {$name}..." );
			
			if ( true == $info['is_active'] ) {
				
				$addon_fields = apply_filters( "e20r_pw_addon_options_{$info['class_name']}", array() );
				
				foreach ( $addon_fields as $type => $config ) {
					
					if ( 'setting' === $type ) {
						$utils->log( sprintf( "Loading: %s/{$config['option_name']}", Payment_Warning::option_group ) );
						register_setting( Payment_Warning::option_group, $config['option_name'], $config['validation_callback'] );
					}
					
					if ( 'section' === $type ) {
						
						$utils->log( "Processing " . count( $config ) . " sections" );
						
						// Iterate through the section(s)
						foreach ( $config as $section ) {
							
							$utils->log( "Loading: {$section['id']}/{$section['label']}" );
							add_settings_section( $section['id'], $section['label'], $section['render_callback'], 'e20r-payment-warning-settings' );
							
							$utils->log( "Processing " . count( $section['fields'] ) . " fields" );
							
							foreach ( $section['fields'] as $field ) {
								
								$utils->log( "Loading: {$field['id']}/{$field['label']}" );
								
								add_settings_field( $field['id'], $field['label'], $field['render_callback'], 'e20r-payment-warning-settings', $section['id'] );
							}
						}
					}
				}
			} else {
				$utils->log( "Addon settings are disabled for {$name}" );
			}
		}
		
		$utils->log( "Configure licensing info for Payment Warning plugin" );
		// Load settings for the Licensing code
		Licensing::register_settings();
	}
	
	/**
	 * Loads the text for the add-on list (to enable/disable add-ons)
	 */
	public function render_addon_header() {
		?>
		<p class="e20r-pw-addon-header-text">
		<?php _e( "Use checkbox to enable/disable any licensed gateways", Payment_Warning::plugin_slug ); ?>
		</p><?php
	}
	
	/**
	 * Render the checkbox for the specific add-on (based on passed config)
	 *
	 * @param array $config
	 */
	public function render_addon_entry( $config ) {
		
		if ( ! empty( $config ) ) {
			$is_active  = $config['is_active'];
			$addon_name = strtolower( $config['class_name'] );
			?>
			<input id="<?php esc_attr_e( $addon_name ); ?>-checkbox" type="checkbox"
			       name="<?php esc_attr_e( $this->settings_name ); ?>[<?php esc_attr_e( "is_{$addon_name}_active" ); ?>]"
			       value="1" <?php checked( $is_active, true ); ?> />
			<?php
		}
	}
	
	/**
	 * Render description for the global plugin settings
	 */
	public function render_global_settings_text() {
		$next_run = get_option( 'e20r_pw_next_gateway_check', null );
		
		if ( empty( $next_run ) ) {
			$next_run = wp_next_scheduled( 'e20r_run_remote_data_update' );
		}
		?>
		<p class="e20r-pw-global-settings-text">
			<?php _e( "Configure plugin settings", Payment_Warning::plugin_slug ); ?>
		</p>
		<p class="e20r-pw-global-settings-info">
			<?php printf( __( '%1$sNext scheduled data fetch from the payment gateway(s) will happen on %2$s%3$s', Payment_Warning::plugin_slug ),
				'<span class="e20r-pw-gateway-fetch-status">',
				date_i18n( get_option( 'date_format' ), $next_run ),
				'</span>'
			); ?>
		</p>
		<?php
	}
	
	/**
	 * Render the description for the types to send messages for
	 */
	public function render_message_type_text() {
		?>
		<p class="e20r-pw-global-messages-text">
			<?php _e( "Select (check) the warning message types you want the plugin to send.", Payment_Warning::plugin_slug ); ?>
		</p>
		
		<?php
	}
	
	/**
	 * Render description for the Reminder Schedule settings
	 */
	public function render_upcoming_payment_text() {
		?>
		<p class="e20r-pw-global-settings-text">
			<?php _e( "Reminder Schedule settings", Payment_Warning::plugin_slug ); ?>
		</p>
		<?php
	}
	
	/**
	 * Render a checkbox for the Settings page
	 *
	 * @param array $settings
	 */
	public function render_checkbox( $settings ) {
		
		$value = self::load_options( $settings['option_name'] );
		?>
		<input type="checkbox"
		       name="<?php esc_attr_e( $this->settings_name ); ?>[<?php esc_html_e( $settings['option_name'] ); ?>]"
		       value="1" <?php checked( 1, $value ); ?> />
		<?php
	}
	
	/**
	 * Render an input field for the Settings page
	 *
	 * @param array $settings
	 */
	public function render_input( $settings ) {
		
		$value = self::load_options( $settings['option_name'] );
		$type = empty( $settings['type'] ) ? 'text' : $settings['type'];
		?>
		<input type="<?php esc_attr_e( $type ); ?>"
		       name="<?php esc_attr_e( $this->settings_name ); ?>[<?php esc_html_e( $settings['option_name'] ); ?>]"
		       value="<?php esc_attr_e( $value ); ?>" />
		<?php
	}
	
	/**
	 * Render a textarea field on the Settings page
	 *
	 * @param array $settings
	 */
	public function render_textarea( $settings ) {
		$value = self::load_options( $settings['option_name'] );
		?>
		<textarea name="<?php esc_attr_e( $this->settings_name ); ?>[<?php esc_html_e( $settings['option_name'] ); ?>]" rows="5" cols="50" placeholder="<?php _e("Enter address using the &lt;br/&gt; html element for line breaks", Payment_Warning::plugin_slug ); ?>" ><?php trim( esc_html_e( $value ) ); ?></textarea>
		<?php
	}
	/**
	 * Generates the Settings API compliant option page
	 */
	public function global_settings_page() {
		?>
		<div class="e20r-pw-settings">
			<div class="wrap">
				<h2 class="e20r-pw-pmpro-settings"><?php _e( 'Settings: Eighty / 20 Results - Payment Warnings for Paid Memberships Pro', Payment_Warning::plugin_slug ); ?></h2>
				<p class="e20r-pw-pmpro-settings">
					<?php _e( "Configure global 'E20R Payment Warnings for Paid Memberships Pro' settings", Payment_Warning::plugin_slug ); ?>
				</p>
				<form method="post" action="options.php">
					<?php settings_fields( Payment_Warning::option_group ); ?>
					<?php do_settings_sections( 'e20r-payment-warning-settings' ); ?>
					<p class="submit">
						<input type="submit" class="button-primary" value="<?php _e( 'Save Changes' ); ?>"/>
					</p>
				</form>
			
			</div>
		</div>
		<?php
	}
}
