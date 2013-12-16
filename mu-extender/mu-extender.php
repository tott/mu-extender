<?php
/**
 * Plugin Name: MU Extender
 * Plugin URI:  http://thorsten-ott.de
 * Description: MU-Plugins drop-in that provides finer grained control over plugins and settings
 * Version:     0.1.0
 * Author:      Thorsten Ott
 * Author URI:  http://thorsten-ott.de
 * License:     GPLv2+
 * Text Domain: muext
 * Domain Path: /languages
 */

/**
 * Copyright (c) 2013 Thorsten Ott (email : thorsten@thorsten-ott.de)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2 or, at
 * your discretion, any later version, as published by the Free
 * Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */

/**
 * Config file hierarchy
 * - wp-config.php
 * if exists load configurations/<WP_ENV>-<blog_id>-conf.php
 * if exists load configurations/<WP_ENV>-conf.php
 * if exists load configurations/default-conf.php
 * then require the extensions/<extension_dir>/extension-conf.php allowing to set default configuration
 * each setting will be set using if ( defined() ) checks
 */

/**
 * Feature priority
 * by default all extensions are active.
 * de-activation will be checked from top to bottom.
 * the first request for deactivation will deactivate the extension
 * DEFINE_DEACTIVATION
 * DASHBOARD_DEACTIVATION
 * IP_DEACTIVATION
 * USER_DEACTIVATION
 * TIMED_DEACTIVATION
 */

class MU_Extender {

	private static $__instance = NULL;

	private $settings = array();
	private $default_settings = array();
	private $settings_texts = array();

	private $plugin_prefix = 'muext';
	private $plugin_name = 'MU Extender';
	private $settings_page_name = null;
	private $dashed_name = 'mu-extender';
	private $underscored_name = 'mu_extender';
	private $js_version = '131129105831';
	private $css_version = '131129105831';

	private $wp_list_table = NULL;
	private $plugin_root = NULL;

	private $capability = 'install_plugins';
	private $user_extensions = array();

	public function __construct() {
		add_action( 'admin_init', array( &$this, 'register_setting' ) );
		add_action( 'admin_menu', array( &$this, 'register_settings_page' ) );

		$this->plugin_root = dirname( __FILE__ ) . '/extensions';

		/**
		 * Default settings that will be used for the setup. You can alter these value with a simple filter such as this
		 * add_filter( 'pluginprefix_default_settings', 'mypluginprefix_settings' );
		 * function mypluginprefix_settings( $settings ) {
		 *   $settings['enable'] = false;
		 *   return $settings;
		 * }
		 */
		$this->default_settings = (array) apply_filters( $this->plugin_prefix . '_default_settings', array(
				'enable'    => 1,
				'timed_deactivation_interval' => 300,
			) );

		/**
		 * Define fields that will be used on the options page
		 * the array key is the field_name the array then describes the label, description and type of the field. possible values for field types are 'text' and 'yesno' for a text field or input fields or 'echo' for a simple output
		 * a filter similar to the default settings (ie pluginprefix_settings_texts) can be used to alter this values
		 */
		$this->settings_texts = (array) apply_filters( $this->plugin_prefix . '_settings_texts', array(
				'enable' => array(
					'label' => sprintf( __( 'Enable %s', $this->plugin_prefix ), $this->plugin_name ),
					'desc' => sprintf( __( 'Enable %s', $this->plugin_prefix ), $this->plugin_name ),
					'type' => 'yesno'
				),
				'timed_deactivation_interval' => array(
					'label' => __( 'Deactivation interval', $this->plugin_prefix ),
					'desc' => __( 'When using timed deactivation deactivate plugin for this many seconds.', $this->plugin_prefix ),
					'type' => 'text'
				),
			) );

		$user_settings = get_option( $this->plugin_prefix . '_settings' );
		if ( false === $user_settings )
			$user_settings = array();

		// after getting default settings make sure to parse the arguments together with the user settings
		$this->settings = wp_parse_args( $user_settings, $this->default_settings );
	}

	public static function init() {
		self::instance()->settings_page_name = sprintf( __( '%s Settings', self::instance()->plugin_prefix ), self::instance()->plugin_name );

		if ( 1 == self::instance()->settings['enable'] ) {
			self::instance()->init_hook_enabled();
		}
		self::instance()->init_hook_always();
	}

	/*
	 * Use this singleton to address methods
	 */
	public static function instance() {
		if ( self::$__instance == NULL )
			self::$__instance = new self();
		return self::$__instance;
	}

	/**
	 * Run these functions when the plugin is enabled
	 */
	public function init_hook_enabled() {
		add_action( 'admin_init', array( &$this, 'register_list' ) );
		add_filter( 'wpext_extension_data_filter', array( &$this, 'inject_extension_data' ), 10, 2 );
		add_action( 'plugins_loaded', array( &$this, 'action_handler' ) );
		add_action( 'muplugins_loaded', array( &$this, 'extension_loader' ), 1 );
		add_action( 'set_current_user', array( &$this, 'user_extension_loader' ) );
	}

	public function register_list() {
		require_once( dirname( __FILE__ ) . '/includes/class-wp-extensions-list-table.php' );
		$this->wp_list_table = new WP_Extension_List_Table;
	}

	/**
	 * Run these functions all the time
	 */
	public function init_hook_always() {
		/**
		 * If a css file for this plugin exists in ./css/wp-cron-control.css make sure it's included
		 */
		if ( file_exists( dirname( __FILE__ ) . "/css/" . $this->underscored_name . ".css" ) )
			wp_enqueue_style( $this->dashed_name, plugins_url( "css/" . $this->underscored_name . ".css", __FILE__ ), $deps = array(), $this->css_version );
		/**
		 * If a js file for this plugin exists in ./js/wp-cron-control.css make sure it's included
		 */
		if ( file_exists( dirname( __FILE__ ) . "/js/" . $this->underscored_name . ".js" ) )
			wp_enqueue_script( $this->dashed_name, plugins_url( "js/" . $this->underscored_name . ".js", __FILE__ ), array(), $this->js_version, true );

		/**
		 * Locale setup
		 */
		$locale = apply_filters( 'plugin_locale', get_locale(), $this->plugin_prefix );
		load_textdomain( $this->plugin_prefix, WP_LANG_DIR . '/' . $this->plugin_prefix . '/' . $this->plugin_prefix . '-' . $locale . '.mo' );
		load_plugin_textdomain( $this->plugin_prefix, false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

	}

	public function register_settings_page() {
		add_options_page( $this->settings_page_name, $this->plugin_name, $this->capability, $this->dashed_name, array( &$this, 'settings_page' ) );
	}

	public function register_setting() {
		register_setting( $this->plugin_prefix . '_settings', $this->plugin_prefix . '_settings', array( &$this, 'validate_settings' ) );
	}

	public function validate_settings( $settings ) {
		// reset to defaults
		if ( !empty( $_POST[ $this->dashed_name . '-defaults'] ) ) {
			$settings = $this->default_settings;
			$_REQUEST['_wp_http_referer'] = add_query_arg( 'defaults', 'true', $_REQUEST['_wp_http_referer'] );

			// or do some custom validations
		} else {

		}
		return $settings;
	}

	public function settings_page() {
		if ( !current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not permission to access this page', $this->plugin_prefix ) );
		}
		?>
		<div class="wrap">
		<?php if ( function_exists( 'screen_icon' ) ) screen_icon(); ?>
			<h2><?php echo $this->settings_page_name; ?></h2>

			<form method="post" action="options.php">

			<?php settings_fields( $this->plugin_prefix . '_settings' ); ?>

			<table class="form-table">
				<?php foreach ( $this->settings as $setting => $value ): ?>
				<tr valign="top">
					<th scope="row">
						<label for="<?php echo $this->dashed_name . '-' . $setting; ?>">
						<?php if ( isset( $this->settings_texts[$setting]['label'] ) ) {
							echo $this->settings_texts[$setting]['label'];
						} else {
							echo $setting;
						} ?>
						</label>
					</th>
					<td>
						<?php
						/**
						 * Implement various handlers for the different types of fields. This could be easily extended to allow for drop-down boxes, textareas and more
						 */
						?>
						<?php switch ( $this->settings_texts[$setting]['type'] ):
							case 'yesno': ?>
								<select name="<?php echo $this->plugin_prefix; ?>_settings[<?php echo $setting; ?>]" id="<?php echo $this->dashed_name . '-' . $setting; ?>" class="postform">
									<?php
										$yesno = array( 0 => __( 'No', $this->plugin_prefix ), 1 => __( 'Yes', $this->plugin_prefix ) );
										foreach ( $yesno as $val => $txt ) {
											echo '<option value="' . esc_attr( $val ) . '"' . selected( $value, $val, false ) . '>' . esc_html( $txt ) . "&nbsp;</option>\n";
										}
									?>
								</select><br />
							<?php break;
							case 'text': ?>
								<div><input type="text" name="<?php echo $this->plugin_prefix; ?>_settings[<?php echo $setting; ?>]" id="<?php echo $this->dashed_name . '-' . $setting; ?>" class="postform" value="<?php echo esc_attr( $value ); ?>" /></div>
							<?php break;
							case 'echo': ?>
								<div><span id="<?php echo $this->dashed_name . '-' . $setting; ?>" class="postform"><?php echo esc_attr( $value ); ?></span></div>
							<?php break;
							default: ?>
								<?php echo $this->settings_texts[$setting]['type']; ?>
							<?php break;
							endswitch; ?>
						<?php if ( !empty( $this->settings_texts[$setting]['desc'] ) ) { echo $this->settings_texts[$setting]['desc']; } ?>
					</td>
				</tr>
				<?php endforeach; ?>
				<?php if ( 1 == $this->settings['enable'] ): ?>
					<tr>
						<td colspan="3">
							<p>The script is currently enabled</p>
						</td>
					</tr>
				</table>
				<?php else: ?>
				</table>
				<?php endif; ?>


			<p class="submit">
		<?php
		if ( function_exists( 'submit_button' ) ) {
			submit_button( null, 'primary', $this->dashed_name . '-submit', false );
			echo ' ';
			submit_button( __( 'Reset to Defaults', $this->plugin_prefix ), '', $this->dashed_name . '-defaults', false );
		} else {
			echo '<input type="submit" name="' . $this->dashed_name . '-submit" class="button-primary" value="' . __( 'Save Changes', $this->plugin_prefix ) . '" />' . "\n";
			echo '<input type="submit" name="' . $this->dashed_name . '-defaults" id="' . $this->dashed_name . '-defaults" class="button-primary" value="' . __( 'Reset to Defaults', $this->plugin_prefix ) . '" />' . "\n";
		}
		?>
			</p>

			<?php if ( 1 == $this->settings['enable'] ): ?>

				<?php if ( !is_null( $this->wp_list_table ) ): ?>
					<?php
						$pagenum = $this->wp_list_table->get_pagenum();

						$action = $this->wp_list_table->current_action();
						$this->wp_list_table->prepare_items();
					?>
					<?php $this->wp_list_table->views(); ?>

					<input type="hidden" name="plugin_status" value="<?php echo esc_attr($status) ?>" />
					<input type="hidden" name="paged" value="<?php echo esc_attr($page) ?>" />

					<?php $this->wp_list_table->display(); ?>

				<?php endif; ?>

			<?php endif; ?>
			</form>
		</div>

		<?php
	}

	/**
	 * Fork of get_plugins
	 *
	 * @return array Key is the plugin file path and the value is an array of the plugin data.
	 */
	public function get_extensions() {

		if ( ! $cache_plugins = wp_cache_get( 'extensions', $this->plugin_prefix . '_extensions' ) )
			$cache_plugins = array();

		$plugins_dir = @ opendir( $this->plugin_root );

		if ( isset( $cache_plugins[ $plugins_dir ] ) )
			return $cache_plugins[ $plugins_dir ];

		$wp_plugins = array ();

		// Files in extensions directory
		$plugin_files = array();
		if ( $plugins_dir ) {
			while ( ( $file = readdir( $plugins_dir ) ) !== false ) {
				if ( substr( $file, 0, 1 ) == '.' )
					continue;
				if ( is_dir( $this->plugin_root.'/'.$file ) ) {
					$plugins_subdir = @ opendir( $this->plugin_root.'/'.$file );
					if ( $plugins_subdir ) {
						while ( ( $subfile = readdir( $plugins_subdir ) ) !== false ) {
							if ( substr( $subfile, 0, 1 ) == '.' )
								continue;
							if ( substr( $subfile, -4 ) == '.php' )
								$plugin_files[] = "$file/$subfile";
						}
						closedir( $plugins_subdir );
					}
				} else {
					if ( substr( $file, -4 ) == '.php' )
						$plugin_files[] = $file;
				}
			}
			closedir( $plugins_dir );
		}

		if ( empty( $plugin_files ) )
			return $wp_plugins;

		foreach ( $plugin_files as $plugin_file ) {
			if ( !is_readable( "$this->plugin_root/$plugin_file" ) )
				continue;

			$plugin_data = get_plugin_data( "$this->plugin_root/$plugin_file", false, false ); //Do not apply markup/translate as it'll be cached.

			if ( empty ( $plugin_data['Name'] ) )
				continue;

			$wp_plugins[plugin_basename( $plugin_file )] = $plugin_data;
		}

		uasort( $wp_plugins, '_sort_uname_callback' );

		$cache_plugins[ $plugins_dir ] = $wp_plugins;
		wp_cache_set( 'extensions', $cache_plugins, $this->plugin_prefix . '_extensions' );

		return $wp_plugins;
	}

	public function inject_extension_data( $extension_data, $plugin_file ) {
		$extension_data['extension_features'] = $this->extension_can( $plugin_file );
		$extension_data['extension_prefix'] = $this->get_extension_prefix( $plugin_file );
		return $extension_data;
	}

	public function is_extension_active( $extension_file, $return_deactivation_types=false ) {
		$extension_features = $this->extension_can( $extension_file );
		$deactivated_types = array();

		// DEFINE_DEACTIVATION
		if ( isset( $extension_features['DEFINE_DEACTIVATION'] ) && true === $extension_features['DEFINE_DEACTIVATION'] ) {
			if ( defined( $this->get_extension_prefix( $extension_file ) . 'ACTIVE' ) && constant( $this->get_extension_prefix( $extension_file ) . 'ACTIVE' ) === false ) {
				if ( true === $return_deactivation_types ) {
					$deactivated_types[] = 'DEFINE_DEACTIVATION';
				} else {
					return false;
				}
			}
		}
		// DASHBOARD_DEACTIVATION
		if ( isset( $extension_features['DASHBOARD_DEACTIVATION'] ) && true === $extension_features['DASHBOARD_DEACTIVATION'] ) {
			if ( $this->is_extension_dashboard_deactivated( $extension_file ) ) {
				if ( true === $return_deactivation_types ) {
					$deactivated_types[] = 'DASHBOARD_DEACTIVATION';
				} else {
					return false;
				}
			}
		}
		// IP_DEACTIVATION
		if ( isset( $extension_features['IP_DEACTIVATION'] ) && true === $extension_features['IP_DEACTIVATION'] ) {
			if ( $this->is_extension_ip_deactivated( $extension_file ) ) {
				if ( true === $return_deactivation_types ) {
					$deactivated_types[] = 'IP_DEACTIVATION';
				} else {
					return false;
				}
			}
		}
		// USER_DEACTIVATION
		if ( isset( $extension_features['USER_DEACTIVATION'] ) && true === $extension_features['USER_DEACTIVATION'] ) {
			if ( $this->is_extension_user_deactivated( $extension_file ) ) {
				if ( true === $return_deactivation_types ) {
					$deactivated_types[] = 'USER_DEACTIVATION';
				} else {
					return false;
				}
			}
		}
		// TIMED_DEACTIVATION
		if ( isset( $extension_features['TIMED_DEACTIVATION'] ) && true === $extension_features['TIMED_DEACTIVATION'] ) {
			if ( $this->is_extension_time_deactivated( $extension_file ) ) {
				if ( true === $return_deactivation_types ) {
					$deactivated_types[] = 'TIMED_DEACTIVATION';
				} else {
					return false;
				}
			}
		}
		if ( true === $return_deactivation_types && !empty( $deactivated_types ) ) {
			return $deactivated_types;
		}
		return true;
	}

	// DASHBOARD

	private function is_extension_dashboard_deactivated( $extension_file ) {
		return in_array( $extension_file, (array) get_option( $this->plugin_prefix . '_dashboard_deactivated_extensions', array() ) );
	}

	private function dashboard_deactivate_extension( $extension_file ) {
		$settings = (array) get_option( $this->plugin_prefix . '_dashboard_deactivated_extensions', array() );
		if ( ! in_array( $extension_file, $settings ) ) {
			$settings[] = $extension_file;
			update_option( $this->plugin_prefix . '_dashboard_deactivated_extensions', $settings );
			return true;
		}
		return false;
	}

	private function dashboard_activate_extension( $extension_file ) {
		$settings = (array) get_option( $this->plugin_prefix . '_dashboard_deactivated_extensions', array() );
		if ( in_array( $extension_file, $settings ) ) {
			foreach( $settings as $key => $extension_cmp ) {
				if ( $extension_cmp == $extension_file ) {
					unset( $settings[$key] );
					break;
				}
			}
			update_option( $this->plugin_prefix . '_dashboard_deactivated_extensions', $settings );
			return true;
		}
		return false;
	}

	// IP

	private function is_extension_ip_deactivated( $extension_file ) {
		$ip_deactivated_extensions = (array) get_option( $this->plugin_prefix . '_ip_deactivated_extensions', array() );
		if ( isset( $ip_deactivated_extensions[$extension_file] ) ) {
			$is_ip_deactivated = $this->user_has_deactivated_ip( $ip_deactivated_extensions[$extension_file] );
			return $is_ip_deactivated;
		}
		return false;
	}

	private function ip_deactivate_extension( $extension_file ) {
		$ip_deactivated_extensions = (array) get_option( $this->plugin_prefix . '_ip_deactivated_extensions', array() );
		$changed = false;
		if ( isset( $ip_deactivated_extensions[$extension_file] ) ) {
			$is_ip_deactivated = $this->user_has_deactivated_ip( $ip_deactivated_extensions[$extension_file] );
			if ( false === $is_ip_deactivated ) {
				$ip_deactivated_extensions[$extension_file][] = $_SERVER['REMOTE_ADDR'] . '/32';
				$changed = true;
			}
		} else {
			$ip_deactivated_extensions[$extension_file] = array( $_SERVER['REMOTE_ADDR'] . '/32' );
			$changed = true;
		}

		if ( true === $changed ) {
			update_option( $this->plugin_prefix . '_ip_deactivated_extensions', $ip_deactivated_extensions );
			return true;
		}

		return false;
	}

	private function ip_activate_extension( $extension_file ) {
		$ip_deactivated_extensions = (array) get_option( $this->plugin_prefix . '_ip_deactivated_extensions', array() );
		$changed = false;
		if ( isset( $ip_deactivated_extensions[$extension_file] ) ) {
			$is_ip_deactivated = $this->user_has_deactivated_ip( $ip_deactivated_extensions[$extension_file] );
			if ( true === $is_ip_deactivated ) {
				foreach( $ip_deactivated_extensions[$extension_file] as $key => $ip_cmp ) {
					if ( $this->user_has_deactivated_ip( array( $ip_cmp ) ) ) {
						unset( $ip_deactivated_extensions[$extension_file][$key] );
						$changed = true;
					}
				}
			}
		}

		if ( true === $changed ) {
			update_option( $this->plugin_prefix . '_ip_deactivated_extensions', $ip_deactivated_extensions );
			return true;
		}

		return false;
	}

	// USER

	private function is_extension_user_deactivated( $extension_file ) {
		$user_deactivated_extensions = (array) get_option( $this->plugin_prefix . '_user_deactivated_extensions', array() );
		if ( isset( $user_deactivated_extensions[$extension_file] ) ) {
			global $current_user;
			if ( $current_user && in_array( $current_user->ID, $user_deactivated_extensions[$extension_file] ) ) {
				return true;
			}
		}
		return false;
	}

	private function user_deactivate_extension( $extension_file ) {
		$user_deactivated_extensions = (array) get_option( $this->plugin_prefix . '_user_deactivated_extensions', array() );
		global $current_user;
		if ( isset( $user_deactivated_extensions[$extension_file] ) ) {
			if ( $current_user && ! in_array( $current_user->ID, $user_deactivated_extensions[$extension_file] ) ) {
				$user_deactivated_extensions[$extension_file][] = $current_user->ID;
				update_option( $this->plugin_prefix . '_user_deactivated_extensions', $user_deactivated_extensions );
				return true;
			}
		} else {
			if ( $current_user ) {
				$user_deactivated_extensions[$extension_file] = array( $current_user->ID );
				update_option( $this->plugin_prefix . '_user_deactivated_extensions', $user_deactivated_extensions );
				return true;
			}
		}
		return false;
	}

	private function user_activate_extension( $extension_file ) {
		$user_deactivated_extensions = (array) get_option( $this->plugin_prefix . '_user_deactivated_extensions', array() );
		if ( isset( $user_deactivated_extensions[$extension_file] ) ) {
			global $current_user;
			if ( $current_user && in_array( $current_user->ID, $user_deactivated_extensions[$extension_file] ) ) {
				foreach( $user_deactivated_extensions[$extension_file] as $key => $user_cmp ) {
					if ( $user_cmp == $current_user->ID ) {
						unset( $user_deactivated_extensions[$extension_file][$key] );
						update_option( $this->plugin_prefix . '_user_deactivated_extensions', $user_deactivated_extensions );
						return true;
					}
				}
			}
		}
		return false;
	}

	// TIME

	private function is_extension_time_deactivated( $extension_file ) {
		$time_deactivated_extensions = (array) get_option( $this->plugin_prefix . '_time_deactivated_extensions', array() );
		if ( isset( $time_deactivated_extensions[$extension_file] ) ) {
			if ( time() - $time_deactivated_extensions[$extension_file] <= $this->settings['timed_deactivation_interval'] ) {
				return true;
			}
		}
		return false;
	}

	private function time_deactivate_extension( $extension_file ) {
		$time_deactivated_extensions = (array) get_option( $this->plugin_prefix . '_time_deactivated_extensions', array() );
		$time_deactivated_extensions[$extension_file] = time() + $this->settings['timed_deactivation_interval'];
		update_option( $this->plugin_prefix . '_time_deactivated_extensions', $time_deactivated_extensions );
		return true;
	}

	private function time_activate_extension( $extension_file ) {
		$time_deactivated_extensions = (array) get_option( $this->plugin_prefix . '_time_deactivated_extensions', array() );
		if ( isset( $time_deactivated_extensions[$extension_file] ) ) {
			unset( $time_deactivated_extensions[$extension_file] );
			update_option( $this->plugin_prefix . '_time_deactivated_extensions', $time_deactivated_extensions );
			return true;
		}
		return false;
	}

	private function get_extension_prefix( $plugin_file ) {
		return strtoupper( str_replace( '-', '_', substr( basename( $plugin_file ), 0, -4 ) ) ) . '_';
	}

	public function get_deactivation_texts( $feature ) {
		global $current_user;
		switch( $feature ) {
			case 'DEFINE_DEACTIVATION':
				return 'Deactivate using define()';
			case 'DASHBOARD_DEACTIVATION':
				return 'Deactivate';
			case 'TIMED_DEACTIVATION':
				return 'Deactivate for ' . $this->settings['timed_deactivation_interval'] . ' seconds';
			case 'USER_DEACTIVATION':
				return 'Deactivate for ' . $current_user->user_login;
			case 'IP_DEACTIVATION':
				return 'Deactivate for ' . esc_attr( $_SERVER['REMOTE_ADDR'] );
			default:
				return 'Deactivate via ' . $feature;
		}
	}

	public function get_activation_texts( $feature ) {
		global $current_user;
		switch( $feature ) {
			case 'DEFINE_ACTIVATION':
				return 'Activate using define()';
			case 'DASHBOARD_ACTIVATION':
				return 'Remove dashboard deactivation';
			case 'TIMED_ACTIVATION':
				return 'Remove time deactivation';
			case 'USER_ACTIVATION':
				return 'Reactivate for ' . $current_user->user_login;
			case 'IP_ACTIVATION':
				return 'Reactivate for ' . esc_attr( $_SERVER['REMOTE_ADDR'] );
			default:
				return 'Remove deactivation for ' . $feature;
		}
	}

	/**
	 * Check if extension supports a certain feature
	 * Settings are read from extension-conf.php in plugin directory
	 * @param  string  $plugin_file path to plugin file
	 * @param  string  $question    DEFINE_DEACTIVATION, DASHBOARD_DEACTIVATION, TIMED_DEACTIVATION, USER_DEACTIVATION,
	 *                              ip_DEactivation
	 * @return boolean              true / false result
	 */
	public function extension_can( $plugin_file, $feature=NULL ) {
		$features = array(
			'DEFINE_DEACTIVATION' => true,	// allow activation control via define statement
			'DASHBOARD_DEACTIVATION' => false,
			'TIMED_DEACTIVATION' => false,
			'USER_DEACTIVATION' => false,
			'IP_DEACTIVATION' => false,
		);

		foreach( $features as $key => $val ) {
			$default_headers[$key] = $key;
		}

		if ( file_exists( dirname( "$this->plugin_root/$plugin_file" ) . '/extension-conf.php' ) && $config_data = get_file_data( dirname( "$this->plugin_root/$plugin_file" ) . '/extension-conf.php', $default_headers, 'plugin' ) ) {
			$features = $config_data;
		}

		if ( ! isset( $features['DEFINE_DEACTIVATION'] ) || ! ( 'false' == strtolower( $features['DEFINE_DEACTIVATION'] ) || false === $features['DEFINE_DEACTIVATION'] ) ) {
			$features['DEFINE_DEACTIVATION'] = true;
		}

		foreach( $features as $key => $val ) {
			if ( strtolower( $val ) == 'true' || $val === true ) {
				$features[$key] = true;
			} else {
				unset( $features[$key] );
			}
		}

		if ( ! empty( $feature ) ) {
			if ( isset( $features[$feature] ) ) {
				return true;
			} else {
				return false;
			}
		}

		return $features;
	}

	public function extension_loader() {
		global $blog_id;
		require_once( ABSPATH . '/wp-admin/includes/plugin.php' );
		$available_extensions = $this->get_extensions();

		/**
		 * Load configs
		 * - configurations/<WP_ENV>-<blog_id>-conf.php
		 * - configurations/<WP_ENV>-conf.php
		 * - configurations/default-conf.php
		 * - extensions/<extension_dir>/extension-conf.php
		 */

		if ( defined( 'WP_ENV' ) && file_exists( dirname( __FILE__ ) . '/configurations/' . WP_ENV . '-' . $blog_id . '-conf.php' ) ) {
			require_once( dirname( __FILE__ ) . '/configurations/' . WP_ENV . '-' . $blog_id . '-conf.php' );
		}

		if ( defined( 'WP_ENV' ) && file_exists( dirname( __FILE__ ) . '/configurations/' . WP_ENV . '-conf.php' ) ) {
			require_once( dirname( __FILE__ ) . '/configurations/' . WP_ENV . '-conf.php' );
		}

		if ( file_exists( dirname( __FILE__ ) . '/configurations/default-conf.php' ) ) {
			require_once( dirname( __FILE__ ) . '/configurations/default-conf.php' );
		}


		foreach( $available_extensions as $extension_file => $extension_data ) {
			// for these extension we need the user object so check them later.
			if ( true === $this->extension_can( $extension_file, 'USER_DEACTIVATION' ) ) {
				$this->user_extensions[$extension_file] = $extension_data;
				continue;
			}

			if ( file_exists( dirname( "$this->plugin_root/$extension_file" ) . '/extension-conf.php' ) ) {
				require_once( dirname( "$this->plugin_root/$extension_file" ) . '/extension-conf.php' );
			}
			if ( true === $this->is_extension_active( $extension_file ) ) {
				// require the file
				require_once( "$this->plugin_root/$extension_file" );
			}
		}
	}

	public function user_extension_loader() {
		foreach( $this->user_extensions as $extension_file => $extension_data ) {
			if ( file_exists( dirname( "$this->plugin_root/$extension_file" ) . '/extension-conf.php' ) ) {
				require_once( dirname( "$this->plugin_root/$extension_file" ) . '/extension-conf.php' );
			}
			if ( true === $this->is_extension_active( $extension_file ) ) {
				// require the file
				require_once( "$this->plugin_root/$extension_file" );
			}
		}
	}

	private function user_has_deactivated_ip( $deactivated_ips ) {
		if ( ! is_array( $deactivated_ips ) || empty( $deactivated_ips ) ) {
			return false;
		}

		$proxy_headers = array(
			'HTTP_VIA',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_FORWARDED_FOR',
			'HTTP_X_FORWARDED',
			'HTTP_FORWARDED',
			'HTTP_CLIENT_IP',
			'HTTP_FORWARDED_FOR_IP',
			'VIA',
			'X_FORWARDED_FOR',
			'FORWARDED_FOR',
			'X_FORWARDED',
			'FORWARDED',
			'CLIENT_IP',
			'FORWARDED_FOR_IP',
			'HTTP_PROXY_CONNECTION'
		);
		$proxy_ip = false;
		foreach( $proxy_headers as $header ) {
			if ( isset( $_SERVER[$header] ) ) {
				$proxy_ip = $_SERVER[$header];
				break;
			}
		}

		$remote_ip = $_SERVER['REMOTE_ADDR'];

		$ip_match = false;
		foreach( $deactivated_ips as $range ) {
			if ( defined( 'MU_EXTENDER_ALLOW_PROXY_IP' ) && MU_EXTENDER_ALLOW_PROXY_IP ) {
				if ( $this->ip_in_range( $remote_ip, $range ) || $this->ip_in_range( $proxy_ip, $range ) ) {
					$ip_match = true;
					break;
				}
			} else if ( $this->ip_in_range( $remote_ip, $range ) ) {
				$ip_match = true;
				break;
			}
		}

		return $ip_match;
	}


	/**
	 * Check if a given ip is in a network
	 * @param  string $ip    IP to check in IPV4 format eg. 127.0.0.1
	 * @param  string $range IP/CIDR netmask eg. 127.0.0.0/24, also 127.0.0.1 is accepted and /32 assumed
	 * @return boolean true if the ip is in this range / false if not.
	 */
	public function ip_in_range( $ip, $range ) {
		if ( strpos( $range, '/' ) == false ) {
			$range .= '/32';
		}
		// $range is in IP/CIDR format eg 127.0.0.1/24
		list( $range, $netmask ) = explode( '/', $range, 2 );
		$range_decimal = ip2long( $range );
		$ip_decimal = ip2long( $ip );
		$wildcard_decimal = pow( 2, ( 32 - $netmask ) ) - 1;
		$netmask_decimal = ~ $wildcard_decimal;
		return ( ( $ip_decimal & $netmask_decimal ) == ( $range_decimal & $netmask_decimal ) );
	}

	public function action_handler() {
		if ( isset( $_REQUEST['action'] ) && isset( $_REQUEST['extension'] ) && in_array( $_REQUEST['action'], $this->extension_can( $_REQUEST['extension'] ) ) ) {
			if ( !isset( $_REQUEST['activation_nonce'] ) || ! wp_verify_nonce( $_REQUEST['activation_nonce'], $_REQUEST['action'] . '_' . $_REQUEST['extension'] ) ) {
				wp_die( __( "Seems you're cheating. If not, contact your administrator." ) );
			}

			if ( ! current_user_can( $this->capability ) ) {
				wp_die( __( "Sorry Dave, I can't do this." ) );
			}

			switch( $_REQUEST['action'] ) {
				case 'DASHBOARD_ACTIVATION':
					$this->dashboard_activate_extension( $_REQUEST['extension'] );
					break;
				case 'DASHBOARD_DEACTIVATION':
					$this->dashboard_deactivate_extension( $_REQUEST['extension'] );
					break;
				case 'TIMED_ACTIVATION':
					$this->time_activate_extension( $_REQUEST['extension'] );
					break;
				case 'TIMED_DEACTIVATION':
					$this->time_deactivate_extension( $_REQUEST['extension'] );
					break;
				case 'USER_ACTIVATION':
					$this->user_activate_extension( $_REQUEST['extension'] );
					break;
				case 'USER_DEACTIVATION':
					$this->user_deactivate_extension( $_REQUEST['extension'] );
					break;
				case 'IP_ACTIVATION':
					$this->ip_activate_extension( $_REQUEST['extension'] );
					break;
				case 'IP_DEACTIVATION':
					$this->ip_deactivate_extension( $_REQUEST['extension'] );
					break;
				default:
					wp_die( __( "This action is invalid" ) );
			}
			if ( wp_get_referer() ) {
				wp_safe_redirect( wp_get_referer() );
			}
		}
	}

}

// if we loaded wp-config then ABSPATH is defined and we know the script was not called directly to issue a cli call
if ( defined( 'ABSPATH' ) ) {
	MU_Extender::init();
} else {
	// otherwise parse the arguments and call the cron.
	if ( !empty( $argv ) && $argv[0] == basename( __FILE__ ) || $argv[0] == __FILE__ ) {
		if ( isset( $argv[1] ) ) {
			echo "You could do something here";
		} else {
			echo "Usage: php " . __FILE__ . " <param1>\n";
			echo "Example: php " . __FILE__ . " superduperparameter\n";
			exit;
		}
	}
}
