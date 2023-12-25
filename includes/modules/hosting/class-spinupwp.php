<?php

namespace WLD_SSO_CF\Modules\Hosting;

use WLD_SSO_CF\Helpers\Tools;
use WLD_SSO_CF\Modules;

/**
 * Adds support for SpinupWP hosted sites using Cloudflare
 */
class SpinupWP extends Modules {

	/**
	 * Runs the main hooks
	 */
	public function init() {
		// Only run when running on a site using SpinupWP.
		if ( $this->is_hosted_on() ) {
			// Check if we are using the SpinupWP Network, if set the HTTP Header.
			add_filter( 'wld_sso_cf_network_check_ip_cf_header', array( $this, 'maybe_set_cf_ip_http_header' ) );
			add_filter( 'wld_sso_cf_network_check_ip_user_header', array( $this, 'maybe_set_user_ip_http_header' ) );
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
	 * SpinupWP does NOT auto-set the real IP.
	 * Whilst we cannot be certain it is a legitimate request from Cloudflare we can guess
	 * As we do not use IP checks to actually authenticate, it minimises security issues associated with this bypass.
	 *
	 * @param string $http_header_name The HTTP Header Name to check.
	 *
	 * @return string
	 */
	public function maybe_set_cf_ip_http_header( $http_header_name ) {
		// phpcs:disable
		// Check if these HTTP Headers are present, means we are most likely behind Cloudflare.
		if ( isset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_CF_RAY'], $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {

			// Make sure the Address is different.
			if ($_SERVER['REMOTE_ADDR'] !== $_SERVER['HTTP_X_FORWARDED_FOR']) {
				// phpcs:enable
				// Set the HTTP Header which should contain the Cloudflare IP.
				return 'REMOTE_ADDR';
			}
		}
		return $http_header_name;
	}

	/**
	 * Check if we might be using Cloudflare and if so set the correct HTTP Header
	 * SpinupWP does NOT auto-set the real IP.
	 * Whilst we cannot be certain it is a legitimate request from Cloudflare we can guess
	 * As we do not use IP checks to actually authenticate, it minimises security issues associated with this bypass.
	 *
	 * @param string $http_header_name The HTTP Header Name to check.
	 *
	 * @return string
	 */
	public function maybe_set_user_ip_http_header( $http_header_name ) {
		// phpcs:disable
		// Check if these HTTP Headers are present, means we are most likely behind Cloudflare.
		if ( isset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CF_RAY'], $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {

			// Make sure the Address is different.
			if ($_SERVER['REMOTE_ADDR'] !== $_SERVER['HTTP_X_FORWARDED_FOR']) {
				// phpcs:enable
				// Set the HTTP Header which should contain the User IP.
				return 'HTTP_X_FORWARDED_FOR';
			}
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
		if ( isset( $_SERVER['SPINUPWP_SITE'], $_SERVER['SPINUPWP_LOG_PATH'] ) &&
		     str_contains( $_SERVER['SPINUPWP_LOG_PATH'], 'sites/' )
		) {
			// phpcs:enable
			return true;
		}
		return false;
	}
}
new SpinupWP();
