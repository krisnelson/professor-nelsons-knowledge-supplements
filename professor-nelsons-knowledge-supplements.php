<?php
/*
Plugin Name: Professor Nelson's Knowledge Supplements (PNKS)
Plugin URI: https://inpropriapersona.com/knowledge-supplements/
Description: Provides widgets/tools that add supplemental data from APIs like Court Listener and DPLA.
Version: 20170205
Author: Kristopher A. Nelson
Author URI: https://krisnelson.org/
License: GPL2+
Text Domain: pnks
*/

/*
PNKS is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License, or
any later version.
 
PNKS is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
 
*/
require_once('lib.php');
require_once('widget.php');
require_once('supplements/dpla.php');
require_once('supplements/courtlistener.php');
require_once('supplements/crossref.php');

/**
 * Registers the widgets
 */
function pnks_widget_register() {
	register_widget( 'pnks_Multi_Widget' );

	//register_widget( 'pnks_CourtListener_Widget' );
	//register_widget( 'pnks_DPLA_Widget' );

	//register_widget( 'krisnelson_Dandelion_Widget' );

}
add_action( 'widgets_init', 'pnks_widget_register' );




///////////////////////////////////////////////////////////////////////////
// SETTINGS
# http://kovshenin.com/2012/the-wordpress-settings-api/
# http://codex.wordpress.org/Settings_API
add_action( 'admin_menu', 'pnks_add_admin_submenu' );
function pnks_add_admin_submenu() {
    add_submenu_page( 
    	'tools.php',
    	__('PN Knowledge Settings', 'pnks' ), 
    	__('PN Knowledge Settings', 'pnks' ), 
    	'manage_options', 
    	'pnks', 
    	'pnks_settings_page' );
}
add_action( 'admin_init', 'pnks_admin_init' );
function pnks_admin_init() {
  
  /* 
	 * http://codex.wordpress.org/Function_Reference/register_setting
	 * register_setting( $option_group, $option_name, $sanitize_callback );
	 * The second argument ($option_name) is the option name. It’s the one we use with functions like get_option() and update_option()
	 * */
  	# With input validation:
  	# register_setting( 'my-settings-group', 'my-plugin-settings', 'my_settings_validate_and_sanitize' );    
  	register_setting( 'pnks-settings-group', 'pnks-plugin-settings' );
	
  	/* 
	 * http://codex.wordpress.org/Function_Reference/add_settings_section
	 * add_settings_section( $id, $title, $callback, $page ); 
	 * */	 
	add_settings_section( 'section-pnks', __( 'General Settings and Options', 'pnks' ), 'section_pnks_callback', 'pnks-plugin' );

  	add_settings_section( 'section-courtlistener', __( 'Court Listener', 'pnks' ), 'section_courtlistener_callback', 'pnks-plugin' );
	add_settings_section( 'section-dpla', __( 'Digital Public Library of America', 'pnks' ), 'section_dpla_callback', 'pnks-plugin' );
	
	/* 
	 * http://codex.wordpress.org/Function_Reference/add_settings_field
	 * add_settings_field( $id, $title, $callback, $page, $section, $args );
	 * */
	add_settings_field( 'days_to_cache', __( 'Approx. Days to Cache Results', 'pnks' ), 'days_to_cache_callback', 'pnks-plugin', 'section-pnks' );
	//add_settings_field( 'field-1-2', __( 'Field Two', 'pnks' ), 'field_1_2_callback', 'pnks-plugin', 'section-courtlistener' );


  	add_settings_field( 'courtlistener_auth', __( 'API Authorization Token', 'pnks' ), 'courtlistener_auth_callback', 'pnks-plugin', 'section-courtlistener' );	
	add_settings_field( 'dpla_auth', __( 'DPLA API Key', 'pnks' ), 'dpla_auth_callback', 'pnks-plugin', 'section-dpla' );
	
}
/* 
 * THE ACTUAL PAGE 
 * */
function pnks_settings_page() {
	// check user capabilities
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	// add error/update messages

	// check if the user have submitted the settings
	// wordpress will add the "settings-updated" $_GET parameter to the url
	if ( isset( $_GET['settings-updated'] ) ) {
	// add settings saved message with the class of "updated"
	add_settings_error( 'pnks_messages', 'pnks_message', __( 'Settings Saved', 'pnks' ), 'updated' );
	}

	// show error/update messages
	settings_errors( 'pnks_messages' );

	?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		 	<form action="options.php" method="post">
			 <?php
				 // output security fields for the registered setting "pnks"
				 settings_fields( 'pnks-settings-group' );
				 // output setting sections and their fields
				 // (sections are registered for "pnks", each field is registered to a specific section)
				 do_settings_sections( 'pnks-plugin' );
				 // output save settings button
				 submit_button( 'Save Settings' );
			?>
		 	</form>
		 </div>
	<?php
}
/*
* THE SECTIONS
* Hint: You can omit using add_settings_field() and instead
* directly put the input fields into the sections.
* */
function section_pnks_callback() {
	echo "General settings and options for PNKS.";
}
function section_courtlistener_callback() {
	echo "For information on the Court Listener API and to get your authorization token, visit: ";
	echo "<a href='https://www.courtlistener.com/api/rest-info/' target='_blank'>Court Listener REST API</a>.";
}
function section_dpla_callback() {
	echo "For information on the DPLA API and to get your API key, visit: ";
	echo "<a href='https://dp.la/info/developers/codex/policies/#get-a-key' target='_blank'>DPLA Policies</a>.";
}
/*
* THE FIELDS
* */
function days_to_cache_callback() {
	
	$settings = (array) get_option( 'pnks-plugin-settings' );
	$field = "days_to_cache";
	$value = esc_attr( $settings[$field] );
	
	echo "<input type='text' name='pnks-plugin-settings[$field]' value='$value' />";
}
// Court Listener
function courtlistener_auth_callback() {
	
	$settings = (array) get_option( 'pnks-plugin-settings' );
	$field = "courtlistener_auth";
	$value = esc_attr( $settings[$field] );
	
	echo "<input type='text' name='pnks-plugin-settings[$field]' value='$value' />";
}
// DPLA 
function dpla_auth_callback() {
	
	$settings = (array) get_option( 'pnks-plugin-settings' );
	$field = "dpla_auth";
	$value = esc_attr( $settings[$field] );
	
	echo "<input type='text' name='pnks-plugin-settings[$field]' value='$value' />";
}
/*
* INPUT VALIDATION:
* */
function pnks_settings_validate_and_sanitize( $input ) {
	$settings = (array) get_option( 'pnks-plugin-settings' );
	
//	if ( $some_condition == $input['field_1_1'] ) {
//		$output['field_1_1'] = $input['field_1_1'];
//	} else {
//		add_settings_error( 'pnks-plugin-settings', 'invalid-field_1_1', 'You have entered an invalid value into Field One.' );
//	}
//	
//	if ( $some_condition == $input['field_1_2'] ) {
//		$output['field_1_2'] = $input['field_1_2'];
//	} else {
//		add_settings_error( 'pnks-plugin-settings', 'invalid-field_1_2', 'You have entered an invalid value into Field One.' );
//	}
//	
//	// and so on for each field
//	
//	return $output;
}
