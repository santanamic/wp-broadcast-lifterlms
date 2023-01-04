<?php 

/*
Plugin Name: Broadcast LifterLMS
Version: 2.2.7
Description: 
*/

function load_broadcast_lifterlms() {

	if ( ! function_exists( 'ThreeWP_Broadcast' ) )
		wp_die( 'Please activate Broadcast before this plugin.' );
		
		$plugin_path = plugin_dir_path( __FILE__ );
		$broadcast_path = plugin_dir_path( dirname( __FILE__ ) ) . 'threewp-broadcast/';

		require_once( $broadcast_path . 'src/premium_pack/base.php' );
		require_once( $plugin_path . 'class-broadcast-plugin-lifterlms.php' );	
}

add_action( 'threewp_broadcast_loaded', 'load_broadcast_lifterlms' );
