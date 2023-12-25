<?php

namespace WLD_SSO_CF\Modules\Hosting;

use WLD_SSO_CF\Helpers\Tools;
use WLD_SSO_CF\Modules;

/**
 * Adds support for Activity Logs for Websites hosted with Rocket.Net
 */
class RocketNet extends Modules {

	/**
	 * Runs the main hooks
	 */
	public function hooks() {
		// Only run when running on a site using Rocket.Net.
		if ( $this->is_using_rocket_net_hosting() ) {
			// Log Failed WP_Login attempt when SSO is Enforced.
			add_action( 'wld_sso_cf_login_requires_sso', array( $this, 'log_sso_required_for_login' ), 10, 1 );

			// Log Successfull SSO Login.
			add_action( 'wld_sso_cf_login_success', array( $this, 'log_sso_login_success' ), 10, 2 );
		}
	}

	/**
	 * Logs the SSO Login being Successfull
	 *
	 * @param string   $user_login WP Username.
	 * @param \WP_USER $user WP_USER.
	 *
	 * @return void
	 */
	public function log_sso_login_success( $user_login, \WP_USER $user ) {

		// Get User Details.
		$user_id      = $user->ID;
		$email        = $user->user_email;
		$display_name = $user->display_name;

		// Prep Log Item.
		$log_item = array(
			'ip'           => Tools::get_ip(),
			'label'        => 'SSO',
			'action'       => 'logged_in_sso',
			'type'         => 'Users',
			'description'  => sprintf( 'SSO Login with user %s', $email ),
			'user_login'   => $user_login,
			'author'       => strval( $user_id ),
			'display_name' => $display_name,
			'user_email'   => $email,
			'roles'        => implode( ',', $user->roles ),
		);

		// Send the Log to Rocket.Net API.
		if ( $this->log( $log_item ) ) {
			// Disable the Rocket.Net log filter (to avoid double logging).
			Tools::remove_class_hook( 'wp_login', 'Activity_Log_Hook_Users', 'wp_login_log' );
		}
	}

	/**
	 * Logs a failed login attempt using wp-login when SSO is enforced
	 *
	 * @param string $username WP Username.
	 * @return void
	 */
	public function log_sso_required_for_login( $username ) {

		// Prep Log Item.
		$log_item = array(
			'ip'           => Tools::get_ip(),
			'label'        => 'SSO',
			'action'       => 'login_not_allowed_sso',
			'type'         => 'Users',
			'description'  => sprintf( 'SSO required for login (%s)', $username ),
			'user_login'   => $username,
			'author'       => '',
			'display_name' => '',
			'user_email'   => '',
			'roles'        => '',
		);

		// Send the Log to Rocket.Net API.
		if ( $this->log( $log_item ) ) {
			// Disable the Rocket.Net log filter (to avoid double logging).
			Tools::remove_class_hook( 'wp_login_failed', 'Activity_Log_Hook_Users', 'wrong_password_log' );
		}
	}

	/**
	 * Sends the log data using the MU-Plugin present on Rocket.Net hosted websites
	 *
	 * @param array $params Parameters.
	 *
	 * @return bool
	 */
	protected function log( $params ) {
		// Make sure the Log API Class Exists otherwise we cannot call the API.
		if ( class_exists( 'CDN_Activity_Log_Api' ) ) {
			// Check the response was successful.
			$response = \CDN_Activity_Log_Api::activity_log_api_call( $params );
			if ( $response && is_object( $response ) && $response->success ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Checks if we are running on Rocket.Net
	 *
	 * @return bool
	 */
	protected function is_using_rocket_net_hosting() {

		// Skip for local environments.
		if ( Tools::is_local_env() ) {
			return false;
		}

		// Check for specific variables.
		if ( defined( 'CDN_SITE_TOKEN' ) && defined( 'CDN_SITE_ID' ) ) {
			return true;
		}

		// Fallback Check.
		// phpcs:disable
		if ( isset( $_SERVER['SERVER_ADMIN'] ) ) {
			if ( str_ends_with( $_SERVER['SERVER_ADMIN'], 'wpdns.site') ) {
				// phpcs:enable
				return true;
			}
		}
		return false;
	}
}
new RocketNet();
