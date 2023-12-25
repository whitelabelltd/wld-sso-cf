<?php

namespace WLD_SSO_CF\Modules\Hosting;

use WLD_SSO_CF\Helpers\Tools;
use WLD_SSO_CF\Modules;

/**
 * Adds support for Flywheel hosted sites using Cloudflare
 */
class Cloudways extends Modules {

	/**
	 * Runs the main hooks
	 */
	public function init() {
		// Only run when running on a site using Cloudways.
		if ( $this->is_hosted_on() ) {
			// Check if we are using the Cloudflare Network.
			add_filter( 'wld_sso_cf_override_on_cf_network_check', array( $this, 'using_cloudflare' ) );
		}
	}

	/**
	 * WP Hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		// Left Blank.
	}

	/**
	 * Check if we might be using Cloudflare and if so bypass the check.
	 * Cloudways auto-sets the real IP, but removes the Cloudflare IP headers, so we have to guess.
	 * Whilst we cannot be certain its a legitimate request from Cloudflare we can guess
	 * As we do not use IP checks to actually authenticate, it minimises security issues associated with this bypass.
	 *
	 * @param bool $allow_network_bypass Allow Bypass of CF Network Check.
	 *
	 * @return bool
	 */
	public function using_cloudflare( $allow_network_bypass ) {
		// phpcs:disable
		// Check if these HTTP Headers are present, means we are most likely behind Cloudflare.
		if ( isset( $_SERVER['HTTP_CF_RAY'], $_SERVER['HTTP_CF_IPCOUNTRY'] )
		) {
			// phpcs:enable
			return true;
		}
		return $allow_network_bypass;
	}

	/**
	 * Checks if we are running on the hosting provider's environment.
	 *
	 * @return bool
	 */
	protected function is_hosted_on() {

		// Skip for local environments.
		if ( Tools::is_local_env() ) {
			return false;
		}

		// phpcs:disable
		if ( str_contains($_SERVER['DOCUMENT_ROOT'], 'cloudwaysapps.com') ) {
			// phpcs:enable
			return true;
		}
		return false;
	}
}
new Cloudways();
