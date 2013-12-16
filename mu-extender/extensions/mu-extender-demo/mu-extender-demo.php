<?php
/**
 * Plugin Name: Just a very basic extension for testing mu-extender
 * Description: This extension just creates a slide-out at the bottom right when enabled
 * Author: Thorsten Ott (10up)
 * Version: 0.1
 * Author URI: http://10up.com
 */

add_action( 'wp_enqueue_scripts', 'dbg_slideout_enqueue' );
function dbg_slideout_enqueue() {
	wp_enqueue_script( 'jQuery' );
}

add_action( 'admin_head', 'dbg_slideout_css' );
add_action( 'wp_head', 'dbg_slideout_css' );
function dbg_slideout_css() {
	?>
	<style type="text/css">
	.dbg-slider {
		position:fixed;
		right:0;
		bottom:0;
		margin-right:4px;
		z-index: 1000;
	}
	.dbg-slider a {
		display:block;
		height:23px;
		width:100px;
		text-align:center;
		background: #222;
		padding:5px;
		float:left;
		cursor:pointer;

		-moz-border-top-left-radius:15px;
		border-top-left-radius: 5px;
		-moz-border-top-right-radius:15px;
		border-top-right-radius: 5px;

		/*Font*/
		color:#eee;
		font-weight:bold;
		font-size:14px;
	}
	.dbg-slider .dbg-info {
		clear:both;
		height:80px;
		width:450px;
		background:#222;
		padding:15px;
		display: none;
		color:#eee;
	}
	</style>
	<?php
}
add_action( 'admin_footer', 'dbg_slideout_info' );
add_action( 'wp_footer', 'dbg_slideout_info' );
function dbg_slideout_info() {
	global $current_user, $wpdb;
	$info = sprintf( "Hello %s, you're visiting with IP %s.<br/>This page was rendered using %d queries", $current_user->user_login, $_SERVER['REMOTE_ADDR'], count( $wpdb->queries ) );
	?>
	<div class="dbg-slider">
		<a id="dbg_button">DBG</a>
		<div class="dbg-info">
			<p><?php echo $info; ?></p>
		</div>
	</div>
	<script>
	jQuery("#dbg_button").click(function(){
	    jQuery('.dbg-info').slideToggle();
	});
	</script>
	<?php
}
