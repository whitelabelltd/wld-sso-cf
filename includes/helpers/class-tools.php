<?php

namespace WLD_SSO_CF\Helpers;

/**
 * Tools class
 */
class Tools {

	/**
	 * Holds the options
	 *
	 * @var string
	 */
	public static $option_name = 'wld_sso_cf_options_user';

	/**
	 * Running in a Local Environment?
	 *
	 * @return bool
	 */
	public static function is_local_env() {
		if ( function_exists( 'wp_get_environment_type' ) ) {
			if ( 'local' === wp_get_environment_type() ) {
				return true;
			}
		} else {
			if ( isset( $_SERVER['SERVER_NAME'] ) ) {
				// phpcs:disable
				if ( str_contains( $_SERVER['SERVER_NAME'], '.local' ) ) {
					// phpcs:enable
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Is the Setting Enabled?
	 *
	 * @param string $setting_name Setting Name.
	 */
	public static function is_plugin_setting_enabled( $setting_name = '' ) : bool {
		$value = self::get_plugin_setting( $setting_name );
		if ( 'yes' === $value ) {
			return true;
		}
		return false;
	}

	/**
	 * Get the setting
	 *
	 * @param  string $setting_key Setting key.
	 * @return false|array|string
	 */
	public static function get_plugin_setting( $setting_key = null ) {

		// Load Defaults.
		$defaults = apply_filters( 'wld_sso_cf_options_defaults', array() );
		$settings = get_option( self::$option_name );
		$settings = wp_parse_args( $settings, $defaults );

		if ( ! empty( $setting_key ) ) {
			return $settings[ $setting_key ] ?? '';
		}

		return $settings;
	}

	/**
	 * Allows removal of filters/actions
	 *
	 * @param string $tag Tag.
	 * @param string $class_name Class Name.
	 * @param string $method_name Method.
	 * @param int    $priority Hook Priority = 10.
	 *
	 * @return bool
	 */
	public static function remove_class_hook( $tag, $class_name = '', $method_name = '', $priority = 10 ) {
		// phpcs:disable
		global $wp_filter;
		$is_hook_removed = false;
		if ( ! empty( $wp_filter[ $tag ]->callbacks[ $priority ] ) ) {
			$methods = array_filter(wp_list_pluck(
				$wp_filter[ $tag ]->callbacks[ $priority ],
				'function'
			), function ($method) {
				/**
				 * Allow only array & string notation for hooks, since we're
				 * looking to remove an exact method of a class anyway. And the
				 * method of the class is passed in as a string anyway.
				 */
				return is_string($method) || is_array($method);
			});
			$found_hooks = ! empty( $methods ) ? wp_list_filter( $methods, array( 1 => $method_name ) ) : array();
			foreach( $found_hooks as $hook_key => $hook ) {
				if ( ! empty( $hook[0] ) && is_object( $hook[0] ) && get_class( $hook[0] ) === $class_name ) {
					$wp_filter[ $tag ]->remove_filter( $tag, $hook, $priority );
					$is_hook_removed = true;
				}
			}
		}
		return $is_hook_removed;
		// phpcs:enable
	}

	/**
	 * Get the users IP
	 *
	 * @return mixed|string
	 */
	public static function get_ip() {
		// phpcs:disable
		if ( isset( $_SERVER[ 'REMOTE_ADDR' ] ) && $_SERVER[ 'REMOTE_ADDR' ] && filter_var( $_SERVER[ 'REMOTE_ADDR' ], FILTER_VALIDATE_IP ) ) {
			return $_SERVER[ 'REMOTE_ADDR' ];
			// phpcs:enable
		}
		// Fallback.
		return '127.0.0.1';
	}

	/**
	 * JSON Decode
	 *
	 * @param string $data Json String.
	 * @return false|mixed
	 */
	public static function json_decode( $data = '' ) {
		if ( $data ) {
			try {
				$json_obj = json_decode( $data, true, $depth = 512, JSON_THROW_ON_ERROR );
			} catch ( \Exception $e ) {
				// handle exception.
				return false;
			} catch ( \JsonException $e ) {
				return false;
			}
			return $json_obj;
		}
		return false;
	}

	/**
	 * Gets the value from an array
	 *
	 * @param string      $key_name Key Name.
	 * @param array       $array Array.
	 * @param false|mixed $default optional defaults to FALSE being returned if value is not found.
	 *
	 * @return false|mixed
	 */
	public static function array_value_get( $key_name = '', $array = array(), $default = false ) {
		if ( $array &&
			$key_name &&
			is_array( $array ) &&
			array_key_exists( $key_name, $array )
		) {
			return $array[ $key_name ];
		}
		return $default;
	}
}
