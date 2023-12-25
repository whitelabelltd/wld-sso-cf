<?php

namespace WLD_SSO_CF\Modules\Hosting;

use WLD_SSO_CF\Helpers\Tools;
use WLD_SSO_CF\Modules;

/**
 * Adds support for Flywheel hosted sites using Cloudflare
 */
class Flywheel extends Modules {

	/**
	 * Runs the main hooks
	 */
	public function init() {
		// Only run when running on a site using Flywheel.
		if ( $this->is_hosted_on() ) {
			// Check if we are using the Cloudflare Network, if set the HTTP Header.
			add_filter( 'wld_sso_cf_network_check_ip_cf_header', array( $this, 'maybe_set_cf_http_header' ) );
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
	 * Check if we might be using Cloudflare and if so set the correct HTTP Header
	 * Flywheel auto-sets the real IP, but removes some of Cloudflare IP headers, so we have to guess.
	 * Whilst we cannot be certain it is a legitimate request from Cloudflare we can guess
	 * As we do not use IP checks to actually authenticate, it minimises security issues associated with this bypass.
	 *
	 * @param string $http_header_name The HTTP Header Name to check.
	 *
	 * @return string
	 */
	public function maybe_set_cf_http_header( $http_header_name ) {
		// phpcs:disable
		// Check if these HTTP Headers are present, means we are most likely behind Cloudflare.
		if ( isset( $_SERVER['HTTP_X_PROXY_IP'], $_SERVER['HTTP_CF_VISITOR'], $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
			// phpcs:enable
			// Set the HTTP Header which should contain the Cloudflare IP.
			return 'HTTP_X_PROXY_IP';
		}
		return $http_header_name;
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
		if ( str_contains( $_SERVER['SERVER_SOFTWARE'], 'Flywheel' ) ) {
			// phpcs:enable
			return true;
		}
		return false;
	}
}
new Flywheel();
