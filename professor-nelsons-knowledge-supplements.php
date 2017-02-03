<?php
/*
Plugin Name: Professor Nelson's Knowledge Supplements
Plugin URI: https://inpropriapersona.com/knowledge-supplements/
Description: Provides widgets/tools that add supplemental data from APIs like Court Listener and DPLA.
Version: 0.2.0
Author: Kristopher A. Nelson
Author URI: https://krisnelson.org/
License: GPL2+
Text Domain: pkan
*/

require_once('lib.php');
require_once('courtlistener.php');
require_once('dpla.php');
//require_once('dandelion.php');

/**
 * Registers the widgets
 */
function pnks_widget_register() {
	register_widget( 'pnks_CourtListener_Widget' );
	register_widget( 'pnks_DPLA_Widget' );

	//register_widget( 'krisnelson_Dandelion_Widget' );

}
add_action( 'widgets_init', 'pnks_widget_register' );



