<?php
/**
 * Plugin Name: WLD SSO Cloudflare
 * Description: Replaces WP-Login with Cloudflare Access
 * Version: 1.0.0
 * Author: Whitelabel Digital
 * Author URI: https://whitelabel.ltd
 * Requires PHP: 8.0
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Plugin Folder: wld-sso-cf
 * Text Domain: wld-sso-cf
 * Domain Path: /languages/
 *
 * @package wld-sso-cf
 */

namespace WLD_SSO_CF;

use WLD_SSO_CF\Helpers\Tools;
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

defined( 'ABSPATH' ) || die( 'Cheatin&#8217; uh?' );

define( 'WLD_SSO_CF_VERSION', '1.0.0' );
define( 'WLD_SSO_CF_FILE', __FILE__ );
define( 'WLD_SSO_CF_URL', plugin_dir_url( WLD_SSO_CF_FILE ) );
define( 'WLD_SSO_CF_UPDATER_URL', 'https://github.com/whitelabelltd/wld-sso-cf/' );

/**
 * The Core plugin class
 */
require_once plugin_dir_path( WLD_SSO_CF_FILE ) . 'includes/class-core.php';

/**
 * Gets the main Class Instance
 *
 * @return Core
 */
function wld_sso_cf() {

	// Globals.
	global $wld_sso_cf;

	// Initialise.
	if ( ! isset( $wld_sso_cf ) ) {
		$wld_sso_cf = new \WLD_SSO_CF\Core();
		$wld_sso_cf->init();
	}

	// Return the class.
	return $wld_sso_cf;
}
wld_sso_cf();

/**
 * Auto Plugin Updater
 */
function _wld_sso_cf_updater() : void {
	// Only Run when not in a local environment.
	if ( ! Tools::is_local_env() ) {
		// Init Updater.
		$updater = PucFactory::buildUpdateChecker(
			WLD_SSO_CF_UPDATER_URL,
			WLD_SSO_CF_FILE,
			'wld-sso-cf'
		);
		// Look for Releases Only.
		$updater->getVcsApi()->enableReleaseAssets();
	}
}
 _wld_sso_cf_updater();
