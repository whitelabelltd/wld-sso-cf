<?php
/**
 * Modules
 *
 * @package wld-sso-cf
 */

namespace WLD_SSO_CF;

use Exception;
use WLD_SSO_CF\Helpers\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Modules Class
 */
abstract class Modules {

	/**
	 * Constructor
	 */
	public function __construct() {
		// Run Init First.
		$this->init();

		// Load remaining hooks.
		add_action( 'plugins_loaded', array( $this, 'hooks' ) );
	}

	/**
	 * Can be used to run code immediately
	 *
	 * @return void
	 */
	protected function init() {}

	/**
	 * Runs the main hooks
	 *
	 * @return mixed
	 */
	abstract public function hooks();

	/**
	 * Are we running in a local environment?
	 */
	protected function is_local_env() : bool {
		return Tools::is_local_env();
	}

	/**
	 * Is the Setting Enabled?
	 *
	 * @param string $setting_name Setting Name.
	 */
	protected function is_setting_enabled( $setting_name = '' ) : bool {
		return Tools::is_plugin_setting_enabled( $setting_name );
	}

	/**
	 * Get the setting
	 *
	 * @param  string $setting_key Setting key.
	 * @return false|array|string
	 */
	protected function get_setting( $setting_key = null ) {
		return Tools::get_plugin_setting( $setting_key );
	}
}
