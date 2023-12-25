<?php

namespace WLD_SSO_CF\Helpers;

/**
 * Debug Log Class
 */
class Log {

	/**
	 * Plugin Option Name for enabling logging
	 *
	 * @var string
	 */
	public static $setting_name = 'sso_debug_log';

	/**
	 * WP Option Name for storing the logs.
	 *
	 * @var string
	 */
	public static $option_name_log = 'wld_sso_cf_logs';

	/**
	 * Maximum number of logs to keep, only designed for temporary logging.
	 *
	 * @var int
	 */
	protected static $number_of_logs_to_keep = 50;

	/**
	 * Holds the logs whilst running.
	 *
	 * @var array
	 */
	protected static $logs = array();


	/**
	 * Add a new log entry.
	 *
	 * @param string $message The message.
	 * @param string $type Type, defaults to login.
	 * @param mixed  $data Optional Data.
	 *
	 * @return bool
	 */
	public static function add( $message, $type = 'login', $data = array() ) {
		if ( self::is_enabled() ) {
			// Load Logs and add to them.
			$logs       = self::get();
			$logs[]     = self::format_entry( $message, $type, $data );
			self::$logs = $logs;

			// Maybe Trim old logs.
			self::maybe_trim();

			// Save logs.
			return self::save();
		}
		return false;
	}

	/**
	 * Formats the new entry and adds extra data.
	 *
	 * @param string $message The message.
	 * @param string $type Type, defaults to login.
	 * @param mixed  $data Optional Data.
	 *
	 * @return array
	 */
	protected static function format_entry( $message, $type = '', $data = array() ) {
		// Determine the type of message.
		if ( empty( $type ) ) {
			$type = 'login';

			if ( is_array( $data ) && isset( $data['type'] ) ) {
				$type = $data['type'];
			} elseif ( is_wp_error( $data ) ) {
				$type = $data->get_error_code();
			}
		}

		// Get the URL.
		$request_uri = ! empty( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : 'Unknown';

		// Construct the message.
		return array(
			'type'    => $type,
			'message' => esc_html( $message ),
			'time'    => time(),
			'user_ID' => get_current_user_id(),
			'uri'     => preg_replace( '/code=([^&]+)/i', 'code=', $request_uri ),
			'data'    => $data,
		);
	}

	/**
	 * Gets all the logs.
	 *
	 * @return array
	 */
	public static function get() {
		if ( empty( self::$logs ) ) {
			self::$logs = get_option( self::$option_name_log, array() );
		}
		return self::$logs;
	}

	/**
	 * Clears the logs from the DB.
	 *
	 * @return void
	 */
	public static function clear_all() {
		self::$logs = array();
		self::save();
	}

	/**
	 * Generates a HTML table with the log entries.
	 *
	 * @return false|string
	 */
	public static function get_html_table() {
		$logs = self::get();
		if ( ! $logs ) {
			return false;
		}
		$logs = array_reverse( $logs );

		ob_start();
		?>
		<table id="logger-table" class="wp-list-table widefat fixed striped posts">
			<thead>
			<th class="col-details">Details</th>
			<th class="col-message">Message</th>
			<th class="col-data">Data</th>
			</thead>
			<tbody>
			<?php foreach ( $logs as $log ) { ?>
				<tr>
					<td class="col-details">
						<div>
							<label><?php esc_html_e( 'Type', 'wld-sso-cf' ); ?>: </label>
							<?php print esc_html( $log['type'] ); ?>
						</div>
						<div>
							<label><?php esc_html_e( 'Date (UTC)', 'wld-sso-cf' ); ?>: </label>
							<?php print esc_html( gmdate( 'Y-m-d H:i:s', $log['time'] ) ); ?>
						</div>
						<div>
							<label><?php esc_html_e( 'User', 'wld-sso-cf' ); ?>: </label>
							<?php print esc_html( get_userdata( $log['user_ID'] ) ? get_userdata( $log['user_ID'] )->user_login : '0' ); ?>
						</div>
						<div>
							<label><?php esc_html_e( 'URI ', 'wld-sso-cf' ); ?>: </label>
							<?php print esc_url( $log['uri'] ); ?>
						</div>
					</td>
					<td class="col-data">
						<?php
						if ( isset( $log['message'] ) && $log['message'] ) {
							echo esc_html( $log['message'] );
						}
						?>
					</td>
					<td class="col-data"><pre>
							<?php
							if ( isset( $log['data'] ) && $log['data'] ) {
								// phpcs:disable
								var_dump( $log['data'] );
								// phpcs:enable
							}
							?>
						</pre></td>
				</tr>
			<?php } ?>
			</tbody>
		</table>
		<?php
		return ob_get_clean();
	}

	/**
	 * Trims the logs if needed (need to save afterwards)
	 *
	 * @return void
	 */
	protected static function maybe_trim() {
		$logs            = self::get();
		$items_to_remove = count( $logs ) - self::$number_of_logs_to_keep;

		// Are we removing logs?
		if ( $items_to_remove > 0 ) {
			// Only keep the last $log_limit messages from the end.
			$logs = array_slice( $logs, $items_to_remove * -1 );
		}

		// Update Logs.
		self::$logs = $logs;
	}

	/**
	 * Saves the logs to DB.
	 *
	 * @return bool
	 */
	protected static function save() {
		// Save the logs, making sure not to autoload them.
		return update_option( self::$option_name_log, self::$logs, false );
	}

	/**
	 * Is thr Debug Log enabled?
	 *
	 * @return bool
	 */
	protected static function is_enabled() {
		return Tools::is_plugin_setting_enabled( self::$setting_name );
	}
}
