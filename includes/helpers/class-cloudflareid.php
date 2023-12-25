<?php

namespace WLD_SSO_CF\Helpers;

/**
 * Cloudflare ID
 */
class CloudflareID {

	/**
	 * Holds the URL we need to do the request to.
	 *
	 * @var string
	 */
	protected static $url = 'https://%s.cloudflareaccess.com/cdn-cgi/access/get-identity';

	/**
	 * Gets the User details from Cloudflare Access
	 *
	 * @param string $domain Cloudflare Team Domain.
	 * @param string $auth_code Cloudflare AuthCode (optional).
	 *
	 * @return false|array
	 */
	public static function get_user( $domain, $auth_code = '' ) {

		// Create API URL.
		$url = sprintf( self::$url, $domain );

		// Grab the AuthCode if none is provided.
		if ( ! $auth_code ) {
			$auth_code = self::get_cf_auth();
		}

		// Make sure we have an Auth Code.
		if ( ! $auth_code ) {
			return false;
		}

		// Prep Headers.
		$headers = array(
			'cookie' => sprintf( 'CF_Authorization=%s', $auth_code ),
			'Accept' => 'application/json',
		);

		// Prep Payload.
		$payload = array(
			'method'      => 'GET',
			'timeout'     => 10,
			'redirection' => 3,
			'httpversion' => '1.1',
			'user-agent'  => 'WLD-SSO-CF/' . WLD_SSO_CF_VERSION . ' ' . site_url(),
			'headers'     => $headers,
		);

		// Do the API Call.
		$response = wp_remote_get( $url, $payload );

		// Check if we got an Error.
		if ( is_wp_error( $response ) ) {
			Log::add( 'Error getting Cloudflare ID for User', 'cloudflare_id', $response );
			return false;
		}

		// Check HTTP Code.
		if ( ! self::response_code_valid( $response ) ) {
			Log::add( 'Cloudflare ID API Error', 'cloudflare_id', Tools::json_decode( wp_remote_retrieve_body( $response ) ) );
			return false;
		}

		// Get Data.
		$data = Tools::json_decode( wp_remote_retrieve_body( $response ) );
		if ( ! $data ) {
			Log::add( 'Cloudflare ID API Data Error', 'cloudflare_id', $response );
			return false;
		}

		// Extract some data.
		$name  = Tools::array_value_get( 'name', $data );
		$email = Tools::array_value_get( 'email', $data );

		// Log it.
		Log::add(
			'Retrieved Cloudflare ID Data',
			'cloudflare_id',
			array(
				'name'  => $name,
				'email' => $email,
			)
		);

		// Return the Data.
		return $data;
	}

	/**
	 * Checks if the HTTP Code is valid (200-210)
	 *
	 * @param mixed $response WP HTTP Response.
	 *
	 * @return bool
	 */
	protected static function response_code_valid( $response ) {
		$code = wp_remote_retrieve_response_code( $response );

		// 200 OK
		if ( ( $code >= 200 ) && ( $code < 210 ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Gets the CF Authorisation Key.
	 *
	 * @return false|string
	 */
	protected static function get_cf_auth() {
		// Get AuthHeader.
		if ( isset( $_SERVER['HTTP_COOKIE'] ) ) {
			// Set the Regex Pattern.
			$re = '/CF_Authorization=([^;]+)/';
			// Find the Value form the cookie.
			// phpcs:disable
			preg_match( $re, $_SERVER['HTTP_COOKIE'], $matches );
			// phpcs:enable
			if ( isset( $matches[1] ) ) {
				return $matches[1];
			}
		}
		return false;
	}
}
