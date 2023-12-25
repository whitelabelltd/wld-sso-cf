<?php
/**
 * Core
 *
 * @package wld_sso_cf
 */

namespace WLD_SSO_CF;

use WLD_SSO_CF\helpers\Tools;

/**
 * Core Class
 */
class Core {

	/**
	 * Holds the Plugin Root file path
	 *
	 * @var string file of the plugin.
	 */
	protected $file;

	/**
	 * Load Items
	 *
	 * @return void
	 */
	protected function load() {
		// Composer Load.
		$this->load_composer();

		// Helpers.
		$this->load_helpers(
			array(
				'tools',
				'iputil',
				'cloudflareid',
				'log',
			)
		);

		// Modules.
		$this->load_modules(
			array(
				'options',
				'cfaccess',
			)
		);

		// Hosting Platform Modules.
		$this->load_modules_hosting(
			array(
				// Rocket.Net Hosting.
				'rocketnet',
				// Cloudways Hosting.
				'cloudways',
				// Flywheel Hosting.
				'flywheel',
				// SpinupWP Hosting.
				'spinupwp',
			)
		);
	}

	/**
	 * Initialises the Core Class
	 *
	 * @return void
	 */
	public function init() {
		// Set the Core File.
		$this->file = \WLD_SSO_CF_FILE;

		// Load Items.
		$this->load();

		// Load Text Domain.
		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Loads the Text Domain
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'wld-sso-cf', false, dirname( plugin_basename( WLD_SSO_CF_FILE ) ) . '/languages' );
	}

	/**
	 * Loads the Autoload for Composer
	 */
	protected function load_composer() {
		$path = plugin_dir_path( $this->file ) . 'vendor/autoload.php';
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}

	/**
	 * Loads any needed Helpers
	 *
	 * @param array $helpers helper names.
	 */
	protected function load_helpers( $helpers = array() ) {
		$path = plugin_dir_path( $this->file ) . 'includes/helpers/';
		foreach ( $helpers as $helper ) {
			$helper = $this->remove_test_item( $helper );
			$file   = trailingslashit( $path ) . basename( 'class-' . $helper ) . '.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	}

	/**
	 * Loads any needed Modules
	 *
	 * @param array  $modules module names.
	 * @param string $sub_folder sub-folder name.
	 */
	protected function load_modules( $modules = array(), $sub_folder = '' ) {
		$path = plugin_dir_path( $this->file ) . 'includes/modules/';
		if ( $sub_folder ) {
			$path .= basename( $sub_folder );
		} else {
			// Load Core.
			$path_core = plugin_dir_path( $this->file ) . 'includes/class-modules.php';
			if ( file_exists( $path_core ) ) {
				require_once $path_core;
			}
		}
		foreach ( $modules as $module ) {
			$module = $this->remove_test_item( $module );
			$file   = trailingslashit( $path ) . basename( 'class-' . $module ) . '.php';
			if ( $module && file_exists( $file ) ) {
				require_once $file;
			}
		}
	}

	/**
	 * Loads any needed hosting Modules
	 *
	 * @param array $hosting_modules hosting module names.
	 */
	protected function load_modules_hosting( $hosting_modules = array() ) {
		$this->load_modules( $hosting_modules, 'hosting' );
	}

	/**
	 * Is the item a test item, and we are running in a test environment, return it. Returns blank otherwise
	 *
	 * @param string $item item name.
	 * @return string
	 */
	protected function remove_test_item( $item = '' ) {

		$tag = '{local}';
		if ( str_contains( $item, $tag ) ) {
			if ( Tools::is_local_env() ) {
				return str_replace( $tag, '', $item );
			} else {
				return '';
			}
		}
		return $item;
	}
}
