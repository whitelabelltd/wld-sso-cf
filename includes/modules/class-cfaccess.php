<?php

namespace WLD_SSO_CF\Modules;

use WLD_SSO_CF\Dependencies\Firebase\JWT\JWK;
use WLD_SSO_CF\Dependencies\Firebase\JWT\JWT;
use WLD_SSO_CF\Dependencies\Firebase\JWT\Key;
use WLD_SSO_CF\Dependencies\Firebase\JWT\SignatureInvalidException;

use WLD_SSO_CF\Helpers\CloudflareID;
use WLD_SSO_CF\Helpers\Log;
use WLD_SSO_CF\Helpers\Tools;
use WLD_SSO_CF\Helpers\IP;
use WLD_SSO_CF\Modules;

/**
 * Cloudflare Access SSO Class
 */
class CFAccess extends Modules {

	/**
	 * Location of the Public Certificate is stored, without the leading . or subdomain
	 * Without leading slash
	 * https://developers.cloudflare.com/cloudflare-one/identity/authorization-cookie/validating-json/
	 *
	 * @var string
	 */
	protected $url_postfix = 'cdn-cgi/access/certs';

	/**
	 * URL For Cloudflare Access (without trialing slash)
	 *
	 * @var string
	 */
	protected $url_cf = 'cloudflareaccess.com';

	/**
	 * The Cloudflare API Url for getting the latest IPs
	 *
	 * @var string
	 */
	protected $cloudflare_api_url = 'https://api.cloudflare.com/client/v4/ips';

	/**
	 * Cloudflare JWT Header
	 *
	 * @var string
	 */
	protected $header_name = 'HTTP_CF_ACCESS_JWT_ASSERTION';

	/**
	 * Holds the option name for storing the Cloudflare Certificate
	 *
	 * @var string
	 */
	protected $option_name_cf_cert = 'wld_sso_cf_cert';

	/**
	 * Holds the option name for storing Cloudflare IPs
	 *
	 * @var string
	 */
	protected $option_name_cf_ips = 'wld_sso_cf_ips';

	/**
	 * Holds the Plugin Option Name for the Cloudflare Subdomain Option
	 *
	 * @var string
	 */
	protected $plugin_option_name_cf_subdomain = 'cf_subdomain';

	/**
	 * Holds the Plugin Option Name for the Cloudflare Force SSO Option
	 *
	 * @var string
	 */
	protected $plugin_option_name_cf_force_sso = 'sso_force';

	/**
	 * Holds the Plugin Option Name for the Enable Link back to Cloudflare
	 *
	 * @var string
	 */
	protected $plugin_option_name_cf_link_to_cf = 'sso_link_to_cf';
	/**
	 * Holds the Plugin Option Name for the Enable creation of WP Users after using SSO
	 *
	 * @var string
	 */
	protected $plugin_option_name_cf_create_wp_user = 'sso_create_wp_user';

	/**
	 * Holds the Meta Key Name for WP User's that use SSO.
	 *
	 * @var string
	 */
	protected $user_meta_sso = 'wld_sso_cf';

	/**
	 * Holds any public errors
	 *
	 * @var array
	 */
	protected $errors = array();

	/**
	 * WP Hooks to Run
	 *
	 * @return void
	 */
	public function hooks() {

		// Add Options.
		add_filter( 'wld_sso_cf_options_sections', array( $this, 'option_add_section' ), 15, 1 );
		add_filter( 'wld_sso_cf_options_setting_fields', array( $this, 'option_add_fields' ), 10, 1 );
		add_filter( 'wld_sso_cf_options_defaults', array( $this, 'option_defaults' ), 10, 1 );
		add_action( 'wld_sso_cf_page_pre_options', array( $this, 'option_page_message' ) );

		// Make sure SSO is enabled.
		if ( $this->is_sso_enabled() ) {

			// SSO Login.
			add_action( 'init', array( $this, 'maybe_login' ), 12 );
			add_filter( 'wp_login_errors', array( $this, 'check_login_errors' ) );
			add_action( 'login_head', array( $this, 'login_css' ) );
			add_action( 'login_enqueue_scripts', array( $this, 'add_sso_button' ) );

			add_filter( 'authenticate', array( $this, 'disable_wp_login' ), 30, 3 );
			add_filter( 'allow_password_reset', array( $this, 'disable_wp_password_reset_for_sso_users' ), 10, 2 );
		}

		// Refresh Cloudflare Certificate.
		add_action( 'wld_sso_cf_refresh_cert', array( $this, 'refresh_cf_cert' ) );

		// During Cert Refresh update Cloudflare IPs.
		add_action( 'wld_sso_cf_refresh_cert', array( $this, 'update_cloudflare_ips' ) );

		// Update Cert if the URL Changes and also set the Cron Job.
		add_action( 'updated_option', array( $this, 'check_option_has_changed' ), 10, 3 );
		add_action( 'added_option', array( $this, 'check_option_has_been_added' ), 10, 2 );

		// Show Notice if not on the CF Network.
		add_action( 'admin_notices', array( $this, 'add_admin_notice' ) );

		// Adds the Debug Log to the options page.
		add_action( 'wld_sso_cf_page_options_footer', array( $this, 'show_debug_log' ) );

		// Update WP User in the DB as an SSO user.
		add_action( 'wld_sso_cf_login_success', array( $this, 'update_user_metadata' ), 10, 2 );
	}

	/**
	 * Intercepts the login page
	 *
	 * @return void
	 */
	public function maybe_login() {

		// Only run on the WP Login page.
		if ( ! is_login() ) {
			return;
		}

		// Skip if logging out or doing other login actions.
		// phpcs:disable
		if ( isset($_REQUEST['action']) || isset($_GET['loggedout']) || isset($_REQUEST['login-error']) || isset($_REQUEST['login']) ) {
			Log::add( 'Doing another login action', 'login_page' );
			return;
		}

		// Are we enforcing SSO?
		// phpcs:disable
		if ( isset( $_REQUEST['pwd'] ) || isset( $_REQUEST['log'] ) ) {
			// phpcs:enable
			// If SSO is Enforced.
			if ( $this->is_setting_enabled( $this->plugin_option_name_cf_force_sso ) ) {
				// Add Front-End Error.
				$this->login_error( 'sso_enforced', 'You can only login using SSO' );

				// Log It.
				Log::add(
					'SSO Enforced, WP-Login not allowed',
					'login_failure',
					array(
						// phpcs:disable
						'username' => wp_unslash( $_REQUEST['log'] ?? false ),
						// phpcs:enable
					)
				);
			} else {
				// Log It.
				Log::add(
					'Doing WP-Login, Skipping SSO Checks',
					'login_page',
					array(
						// phpcs:disable
						'username' => wp_unslash( $_REQUEST['log'] ?? false ),
						// phpcs:enable
					)
				);
			}

			// End.
			return;
		}

		// Ensure we are on the Cloudflare Network.
		if ( ! $this->on_cloudflare_network() ) {
			$this->login_error( 'sso_network_not_supported', 'ERROR: Cloudflare Network Required for SSO' );
			return;
		}

		// Make sure we have SSO Setup.
		$access_url = $this->get_cf_access_url();
		if ( ! $access_url ) {
			$this->login_error( 'sso_setup_required', 'SSO not fully setup' );
			return;
		}

		// Skip if the user is already logged in.
		if ( is_user_logged_in() ) {
			Log::add( 'User already logged in', 'sso_login' );
			return;
		}

		// Get the Token.
		$jwt_token = $this->get_jwt_token();
		if ( ! $jwt_token ) {
			// Add Front-End Error.
			$this->login_error( 'sso_token_missing', 'No SSO Token found' );

			// Log It.
			Log::add(
				'Login failed using SSO, JWT Token not found',
				'sso_login_failure'
			);

			// End.
			return;
		}

		// Verify Token.
		$jwt_payload = $this->verify_jwt_token( $jwt_token );
		if ( ! $jwt_payload ) {
			// Add Front-End Error.
			$this->login_error( 'sso_token_error', __( 'JWT Token is invalid', 'wld-sso-cf' ) );

			// Log It.
			Log::add(
				'Login failed using SSO, JWT Token invalid',
				'sso_login_failure',
				array(
					'jwt_token' => $jwt_token,
				)
			);

			// End.
			return;
		}

		// Make sure we have an email address.
		if ( ! isset( $jwt_payload->email ) ) {
			// Add Front-End Error.
			$this->login_error( 'sso_token_email_error', __( 'JWT Payload missing email data', 'wld-sso-cf' ) );

			// Log It.
			Log::add(
				'Login failed using SSO, payload missing email data',
				'sso_login_failure',
				array(
					'jwt_payload' => $jwt_payload,
					'jwt_token'   => $jwt_token,
				)
			);

			// End.
			return;
		}

		// Grab Email.
		// phpcs:disable
		$email = $jwt_payload->email ?: false;
		// phpcs:enable

		// Load the WP User based on email.
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {

			// Check if we are allowed to create a new WP User.
			if ( $this->is_setting_enabled( $this->plugin_option_name_cf_create_wp_user ) ) {
				// Create a new WP User.
				$user = self::create_new_wp_user( $jwt_payload );
				if ( ! $user ) {
					// Add Front-End Error.
					$this->login_error( 'sso_user_error', __( 'Could not create a new WordPress User', 'wld-sso-cf' ) );

					// End.
					return;
				}
			} else {
				// Add Front-End Error.
				$this->login_error( 'sso_user_error', __( 'User does not exist or is not allowed to login', 'wld-sso-cf' ) );

				// Log It.
				Log::add(
					'Login failed using SSO, user does not exist',
					'sso_login_failure',
					array(
						'jwt_payload' => $jwt_payload,
						'jwt_token'   => $jwt_token,
						'user_email'  => $email,
						'headers'     => $_SERVER,
					)
				);

				// End.
				return;
			}
		}

		// Log the user in.
		wp_clear_auth_cookie();
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID );

		// Custom Login Hook.
		do_action( 'wld_sso_cf_login_success', $user->login, $user );

		// Log It.
		Log::add(
			'Login successfull using SSO',
			'sso_login',
			array(
				'jwt_payload' => $jwt_payload,
				'user_email'  => $user->user_email,
			)
		);

		// Standard WP Hook.
		do_action( 'wp_login', $user->login, $user );

		// redirect after login.
		$redirect_url = home_url();
		if ( current_user_can( 'manage_options' ) ) {
			$redirect_url = admin_url();
		}

		// phpcs:disable
		wp_safe_redirect( isset( $_GET['redirect_to'] ) ? wp_unslash( $_GET['redirect_to'] ) : $redirect_url );
		// phpcs:enable
		exit;
	}

	/**
	 * Sets a user as using SSO when they login.
	 *
	 * @param string         $username WP Username.
	 * @param \WP_USER|mixed $user WP USER.
	 *
	 * @return void
	 */
	public function update_user_metadata( $username, $user ) {
		if ( ! get_user_meta( $user->ID, $this->user_meta_sso, true ) ) {
			update_user_meta( $user->ID, $this->user_meta_sso, true );
		}
	}

	/**
	 * Creates a new WP User based on the JWT Payload.
	 *
	 * @param object $jwt_payload JWT Payload.
	 *
	 * @return false|\WP_User
	 */
	protected function create_new_wp_user( $jwt_payload ) {

		// Get Team Domain.
		$team_domain = $this->get_setting( $this->plugin_option_name_cf_subdomain );
		if ( ! $team_domain ) {

			// Log It.
			Log::add(
				'Team Domain not set in settings',
				'cloudflare_id',
			);

			// Exit.
			return false;
		}

		// Get Email.
		// phpcs:disable
		$email = $jwt_payload->email ?: false;
		// phpcs:enable
		if ( ! $email ) {

			// Log It.
			Log::add(
				'Email missing from JWT Payload',
				'cloudflare_id',
				array(
					'jwt_payload' => $jwt_payload,
				)
			);

			// Exit.
			return false;
		}

		// Initialise.
		$full_name = '';

		// Attempt to get User Data from Cloudflare.
		$user_data = CloudflareID::get_user( $team_domain );
		if ( ! $user_data ) {

			// No User Data, generate a username based on the email.
			$username = self::generate_username_from_email( $email );

			// Log It.
			Log::add(
				'User Data Missing, Fallback to Username from Email',
				'cloudflare_id',
				array(
					'email'    => $email,
					'username' => $username,
				)
			);

		} else {
			// Get the email from our Cloudflare ID API Call.
			$email_cf = Tools::array_value_get( 'email', $user_data );
			// Check Email Matches.
			if ( $email !== $email_cf ) {

				// Log It.
				Log::add(
					'Email Mismatch',
					'cloudflare_id',
					array(
						'email'     => $email,
						'email_cf'  => $email_cf,
						'user_data' => $user_data,
					)
				);

				// Exit.
				return false;
			}

			// Load User Data, generate a username based on that.
			$full_name  = Tools::array_value_get( 'name', $user_data );
			$user_id_cf = Tools::array_value_get( 'id', $user_data );
			$username   = self::generate_username_from_name( $full_name, $user_id_cf );
		}

		// Generate a random password.
		$random_password = wp_generate_password( 64, true );

		// Get Default Role for new WP users from WP Options, fallback to subscriber.
		$default_role = get_option( 'default_role', 'subscriber' );

		// Prep new User Payload.
		$user_payload = array(
			'user_pass'    => $random_password,
			'user_email'   => $email,
			'user_login'   => $username,
			'display_name' => $full_name,
			'nickname'     => $full_name,
			'use_ssl'      => true,
			'role'         => $default_role,
			'meta_input'   => array( $this->user_meta_sso => true ),
		);

		// Create the WP User.
		$user_id = wp_insert_user( $user_payload );
		if ( is_wp_error( $user_id ) ) {
			// Log It.
			Log::add(
				'Could not create a new user',
				'sso_create_user',
				array(
					'username' => $username,
					'email'    => $email,
				)
			);

			// Exit.
			return false;
		}

		// Get the new WP_User.
		$user = get_user_by( 'id', $user_id );

		// Log It.
		Log::add(
			'New WP User created',
			'sso_create_user',
			array(
				'user_id'      => $user_id,
				'email'        => $email,
				'username'     => $username,
				'display_name' => $full_name,
				'role'         => $default_role,
			)
		);

		// Return the User.
		return $user;
	}

	/**
	 * Generates a username based on the full name plus ID
	 *
	 * @param string $full_name Full Name.
	 * @param string $user_id User ID.
	 *
	 * @return string
	 */
	protected function generate_username_from_name( $full_name, $user_id = '' ) {
		// Remove spaces, lowercase the full name, and replace spaces with underscores.
		$username = str_replace( ' ', '_', strtolower( $full_name ) );

		// Add the first 4 characters from the $user_id.
		if ( $user_id ) {
			$username .= '_';
			$username .= substr( $user_id, 0, 4 );
		}

		// Convert the username to lowercase.
		$username = strtolower( $username );

		// Remove non-allowed characters.
		$allowed_characters = '/[^a-z0-9_]/i';
		return preg_replace( $allowed_characters, '', $username );
	}

	/**
	 * Generates a username based on an email address
	 *
	 * @param string $email Email Address.
	 *
	 * @return string
	 */
	protected function generate_username_from_email( $email ) {
		// Validate email format.
		if ( ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			return false;
		}

		// Remove '@' symbol.
		$username = str_replace( '@', '', $email );

		// Convert . to _.
		$username = str_replace( '.', '_', $username );

		// Lowercase the username.
		$username = strtolower( $username );

		// Add sso at the end.
		$username .= '_sso';

		// Remove non-allowed characters.
		$allowed_characters = '/[^a-z0-9_]/i';
		return preg_replace( $allowed_characters, '', $username );
	}

	/**
	 * Add Warning when not on the CF Network, show only to Admins
	 *
	 * @return void
	 */
	public function add_admin_notice() {
		if ( current_user_can( 'manage_options' ) ) {

			// Initialise.
			$text = '';

			// Let the user know we need to use Cloudflare for SSO to work.
			if ( ! $this->on_cloudflare_network() ) {
				$text = __( 'Cloudflare Zero Trust SSO is Disabled when not on the Cloudflare Network. Please ensure you are on the Cloudflare Network.', 'wld-sso-cf' );
			}

			// Let the user know we need to set a Cloudflare Team Domain in options.
			if ( ! $this->get_cf_access_url() ) {
				$text = __( 'Cloudflare Zero Trust SSO requires a Team Domain to be set', 'wld-sso-cf' );
			}

			if ( $text ) {
				if ( Tools::is_local_env() ) {
					$text .= '&nbsp;' . __( 'This will not work on a local environment', 'wld-sso-cf' );
				}
				echo '<div class="notice notice-warning"><p>' . esc_html( $text ) . '</p></div>';
			}
		}
	}

	/**
	 * Use JS to replace their text button with our own one
	 */
	public function add_sso_button() {

		// Get Button URL.
		$btn_url = $this->get_cf_access_url();

		// Check if we are hiding the login form.
		$hide_login_form = false;
		if ( $this->is_setting_enabled( $this->plugin_option_name_cf_force_sso ) ) {
			$hide_login_form = true;
		}

		// Are we showing the SSO Button, always show it when the form is hidden.
		$show_sso_button = false;
		if ( $hide_login_form || $this->is_setting_enabled( $this->plugin_option_name_cf_link_to_cf ) ) {
			if ( $btn_url ) {
				$show_sso_button = true;
			}
		}

		?>
		<script type="application/javascript">

			document.addEventListener("DOMContentLoaded", function() {

				<?php if ( $show_sso_button ) { ?>
				document.getElementById('loginform').insertAdjacentHTML( 'afterbegin',
					'<div id="wld_sso_cf_div" class="wld-sso-cf">' +
					'<a href="<?php echo esc_url( $btn_url ); ?>" class="button button-hero button-primary">' +
					'<?php esc_html_e( 'Log in with Cloudflare', 'wld-sso-cf' ); ?>' +
					'</a>' +
					'<span class="wld-sso-cf-or"><span>or</span></span>' +
					'</div>'
				);
					<?php
				}

				if ( $hide_login_form ) {
					?>

				// Get the form element
				var formElement = document.getElementById('loginform');

				// Remove elements based on CSS selectors
				var elementsToRemove = [
					'#loginform > p:nth-child(2)',
					'#loginform .user-pass-wrap',
					'#loginform .forgetmenot',
					'#loginform .submit',
					'#loginform .wp-login-lost-password',
					'#loginform .wld-sso-cf-or'
				];

				elementsToRemove.forEach(function (selector) {
					var elements = formElement.querySelectorAll(selector);
					elements.forEach(function (element) {
						element.remove();
					});
				});

				<?php } ?>

			});

		</script>
		<?php
	}

	/**
	 * Disables WP Password resets when SSO is enforced
	 *
	 * @param bool $is_allowed Is a reset allowed.
	 * @param int  $user_id WP_USER ID.
	 *
	 * @return false
	 */
	public function disable_wp_password_reset_for_sso_users( $is_allowed, $user_id = 0 ) {
		// Is the Option on to require all users to use SSO or the user was created with SSO? If so disable local password resets.
		if ( $this->is_setting_enabled( $this->plugin_option_name_cf_force_sso ) || get_user_meta( $user_id, $this->user_meta_sso, true ) ) {
			add_filter( 'gettext', array( $this, 'change_password_reset_message' ), 20, 3 );
			return false;
		}
		return $is_allowed;
	}

	/**
	 * Changes the Password Reset not allowed message for SSO Users
	 *
	 * @param string $translated_text Translated text.
	 * @param string $untranslated_text Un-Translated text.
	 * @param string $domain Text Domain.
	 *
	 * @return string
	 */
	public function change_password_reset_message( $translated_text = '', $untranslated_text = '', $domain = '' ) {
		if ( 'default' === $domain ) {
			if ( 'Password reset is not allowed for this user' === $untranslated_text ) {
				return __( 'Please use your identity provider to change your password', 'wld-sso-cf' );
			}
		}
		return $translated_text;
	}

	/**
	 * Disables all logins without SSO when on the CF Network
	 *
	 * @param mixed  $user WP USER.
	 * @param string $username WP Username.
	 * @param string $password WP Password.
	 *
	 * @return \WP_Error
	 */
	public function disable_wp_login( $user, $username, $password ) {
		if ( $this->is_setting_enabled( $this->plugin_option_name_cf_force_sso ) &&
			$this->on_cloudflare_network()
		) {
			Log::add( 'SSO Enforced, WP Login not allowed', 'login', array( 'username' => $username ) );
			do_action( 'wld_sso_cf_login_requires_sso', $username );
			return new \WP_Error( 'sso_enforced', __( 'ERROR: Please use SSO to sign-in', 'wld-sso-cf' ) );
		}
		return $user;
	}

	/**
	 * Is SSO Enabled?
	 *
	 * @return bool
	 */
	protected function is_sso_enabled() {
		// Make sure we have a team domain set and we are using the CF Network.
		if ( $this->get_cf_access_url() && $this->on_cloudflare_network() ) {
			return true;
		}
		return false;
	}

	/**
	 * Set CSS for the Login Form with SSO button
	 *
	 * @return void
	 */
	public function login_css() {

		?>
		<style>
			.wld-sso-cf {
				font-weight: normal;
				overflow: hidden;
				text-align: center;
				margin-left: 0;
				padding-top: 8px;
				font-weight: 400;
				overflow: hidden;
			}

			.wld-sso-cf .button-primary {
				float: none;
				text-transform: capitalize;
			}

			.wld-sso-cf-or {
				margin: 2em 0;
				width: 100%;
				display: block;
				border-bottom: 1px solid rgba(0,0,0,0.13);
				text-align: center;
				line-height: 1;
			}

			.wld-sso-cf-or span {
				position: relative;
				top: 0.5em;
				background: white;
				padding: 0 1em;
				color: #72777c;
			}

			<?php
			// CSS Fallback if JS is disabled.
			if ( $this->is_setting_enabled( $this->plugin_option_name_cf_force_sso ) ) {
				?>

			#loginform > p:nth-child(2),
			.user-pass-wrap,
			.forgetmenot,
			.wp-login-lost-password,
			.submit,
			.wld-sso-cf-or {
				display: none;
			}

		<?php } ?>


		</style>
		<?php
	}

	/**
	 * If the user has updated the options trigger a sync of the Cert
	 *
	 * @param string $option_name Option Name.
	 * @param mixed  $old_value   Old Value.
	 * @param mixed  $value       Value.
	 *
	 * @return void
	 */
	public function check_option_has_changed( $option_name, $old_value, $value ) {
		// Only trigger for this plugin settings.
		if ( Tools::$option_name === $option_name ) {
			$subdomain_old = Tools::array_value_get( $this->plugin_option_name_cf_subdomain, $old_value );
			$subdomain     = Tools::array_value_get( $this->plugin_option_name_cf_subdomain, $value );

			// Only trigger if the Subdomain has changed and has a value.
			if ( $subdomain && ( $subdomain !== $subdomain_old ) ) {

				// Log It.
				Log::add( 'Refreshing JWT Certificate from Cloudflare', 'jwt_cert' );

				// Maybe set the Cron Job.
				$this->set_cron_job_for_cert_refresh();

				// Refresh the Cert.
				$this->refresh_cf_cert();
			} else {
				// Log It.
				Log::add( 'Team Domain not changed, prevent refreshing JWT Certificate', 'jwt_cert' );
			}

			// Log Option Check.
			$logs_option_old = Tools::array_value_get( Log::$setting_name, $old_value );
			$logs_option_new = Tools::array_value_get( Log::$setting_name, $value );

			// Check if the option has changed.
			if ( $logs_option_new !== $logs_option_old ) {
				// Clear Logs.
				Log::clear_all();
			}
		}
	}

	/**
	 * If the user has saved the options for the first time trigger a sync of the Cert
	 *
	 * @param string $option_name Option Name.
	 * @param mixed  $value Value.
	 *
	 * @return void
	 */
	public function check_option_has_been_added( $option_name, $value ) {
		// Only trigger for this plugin settings.
		if ( Tools::$option_name === $option_name ) {
			$subdomain = Tools::array_value_get( $this->plugin_option_name_cf_subdomain, $value );
			if ( $subdomain ) {

				// Log It.
				Log::add( 'Getting new JWT Certificate from Cloudflare and setting cron job', 'jwt_cert' );

				// Set the Cron Job.
				$this->set_cron_job_for_cert_refresh();

				// Refresh the Cert.
				$this->refresh_cf_cert();

				// Refresh the Cloudflare IPs.
				$this->update_cloudflare_ips();
			}
		}
	}

	/**
	 * Schedule Daily Certificate Update
	 *
	 * @return void
	 */
	public function set_cron_job_for_cert_refresh() {
		$cronjob_name = 'wld_sso_cf_cert_refresh';
		if ( ! wp_next_scheduled( $cronjob_name ) ) {
			wp_schedule_event( time(), 'daily', $cronjob_name );
		}
	}

	/**
	 * Gets the JWT Token
	 *
	 * @return false|string
	 */
	protected function get_jwt_token() {
		$jwt_header = $this->header_name;
		if ( ! isset( $_SERVER[ $jwt_header ] ) ) {
			Log::add( 'JWT Auth: WARNING: the expected JWT was not found. Please double check your reverse proxy configuration.', 'jwt_auth' );
			return false;
		}

		// Handle "Header: Bearer <JWT>" form by stripping the "Bearer " prefix.
		$array = explode( ' ', sanitize_text_field( wp_unslash( $_SERVER[ $jwt_header ] ) ) );
		if ( 'Bearer' === $array[0] ) {
			array_shift( $array );
		}

		return implode( ' ', $array );
	}

	/**
	 * Verifies the JWT Token and returns the Payload.
	 *
	 * @param string $jwt_token JWT Token.
	 *
	 * @return false|\stdClass
	 */
	protected function verify_jwt_token( $jwt_token ) {
		$key = $this->get_token_key();
		if ( false === $key ) {
			return false;
		}
		try {
			$payload = JWT::decode( $jwt_token, $key );
		} catch ( SignatureInvalidException | \Exception $e ) {
			Log::add( 'JWT Auth: ERROR: Cannot verify the JWT: ' . $e->getMessage(), 'jwt_auth', $e );
			return false;
		}
		return $payload;
	}

	/**
	 * Gets the Token Key from the Certificate
	 *
	 * @return false|Key[]
	 */
	protected function get_token_key() {
		// Load the Certificate.
		$jwk_key_set = Tools::array_value_get( 'keys', $this->get_cf_cert() );
		if ( $jwk_key_set ) {

			try {
				$keys = JWK::parseKeySet( array( 'keys' => $jwk_key_set ) );
			} catch ( \Exception $e ) {
				Log::add( 'JWT Auth: ERROR: Problem parsing key-set: ' . $e->getMessage(), 'jwt_auth', $e );
				return false;
			}
			return $keys;
		}
		return false;
	}

	/**
	 * Get the Cloudflare JWT Certificate
	 *
	 * @return false|mixed
	 */
	protected function get_cf_cert() {

		// Load the Certificate.
		$cert_json = get_option( $this->option_name_cf_cert );
		if ( $cert_json ) {
			// try to decode json.
			$jwks = Tools::json_decode( $cert_json );
			if ( null === $jwks ) {
				Log::add( 'JWT Auth: ERROR: cannot decode the JSON retrieved from the JWKS URL', 'jwt_auth' );
				return false;
			}
			return $jwks;
		}
		return false;
	}

	/**
	 * Gets the Cloudflare Access URL
	 * including trialing slash
	 *
	 * @todo improve validation on user input saving the option, so we don't have to do this
	 *
	 * @return false|string
	 */
	protected function get_cf_access_url() {
		$cf_subdomain = $this->get_setting( $this->plugin_option_name_cf_subdomain );
		if ( $cf_subdomain ) {
			$cf_subdomain = str_replace(
				array(
					'https://',
					'http://',
					'wwww.',
					'.cloudflareaccess.com',
					'/',
					'@',
				),
				'',
				$cf_subdomain
			);

			// Return the CF URL.
			return sprintf( 'https://%s.%s/', $cf_subdomain, $this->url_cf );
		}
		return false;
	}

	/**
	 * Gets the JKWS Cert URL
	 *
	 * @return false|string
	 */
	protected function get_jwks_url() {
		$cf_subdomain = $this->get_setting( $this->plugin_option_name_cf_subdomain );
		if ( $cf_subdomain ) {
			// Get CF URL.
			$url = $this->get_cf_access_url();
			// Return the full URL.
			return sprintf( '%s%s', $url, $this->url_postfix );
		}
		return false;
	}

	/**
	 * Refreshes the Cloudflare JWT Certificate
	 *
	 * @return bool
	 */
	public function refresh_cf_cert() {

		// Load URL.
		$jwks_url = $this->get_jwks_url();
		if ( ! $jwks_url ) {
			return false;
		}

		// if transient did not exist, attempt to get url.
		$response = wp_remote_get( $jwks_url );
		if ( is_wp_error( $response ) ) {
			Log::add( 'JWT Auth: ERROR: error retrieving the JWKS URL', 'jwt_auth' );
			return false;
		}

		// Grab response body.
		$json = wp_remote_retrieve_body( $response );

		// Check that response was not empty.
		if ( ! $json ) {
			Log::add( 'JWT Auth: ERROR: could not retrieve the specified JWKS URL', 'jwt_auth' );
			return false;
		}

		// Get the JSON.
		$jwks = Tools::json_decode( $json );
		if ( ! $jwks ) {
			Log::add( 'JWT Auth: ERROR: cannot decode the JSON retrieved from the JWKS URL', 'jwt_auth' );
			return false;
		}

		// Save the Certificate.
		update_option( $this->option_name_cf_cert, $json, false );

		// Done.
		return true;
	}

	/**
	 * Adds the Login Errors to the front end
	 *
	 * @param \WP_Error $wp_errors WP_ERROR Arrays.
	 */
	public function check_login_errors( \WP_Error $wp_errors ) {
		if ( $this->errors ) {
			foreach ( $this->errors as $error ) {
				$code    = Tools::array_value_get( 'code', $error );
				$message = Tools::array_value_get( 'message', $error );
				if ( $message && $code ) {
					$wp_errors = $this->add_to_wp_error( $wp_errors, $code, $message );
				}
			}
		}
		return $wp_errors;
	}

	/**
	 * Adds the error to a WP_Error object creating one if it doesn't exist
	 *
	 * @param mixed  $wp_errors WP Errors.
	 * @param string $code Error Code.
	 * @param string $message Message.
	 *
	 * @return \WP_Error
	 */
	protected function add_to_wp_error( $wp_errors, $code, $message ) {
		if ( is_wp_error( $wp_errors ) ) {
			$wp_errors->add( $code, $message );
		} else {
			$wp_errors = new \WP_Error( $code, $message );
		}
		return $wp_errors;
	}

	/**
	 * Adds an error to the front end page
	 *
	 * @param string $code Error Code.
	 * @param string $message Message.
	 *
	 * @return void
	 */
	protected function login_error( $code = '', $message = '' ) {
		if ( ! $code ) {
			$code = 'sso_login_error';
		}
		if ( ! $message ) {
			return;
		}
		$this->errors[] = array(
			'code'    => $code,
			'message' => $message,
		);
	}

	/**
	 * Get Cloudflare IPs from Cloudflare API and saves them in the DB
	 *
	 * @return bool
	 */
	public function update_cloudflare_ips() {

		// Log It.
		Log::add( 'Updating known Cloudflare IPs', 'cloudflare_api' );

		// Set the API URL.
		$url = $this->cloudflare_api_url;

		// Set API Call Details.
		$args = array(
			'headers' => array(
				'User-Agent' => 'WLD-SSO-CF/' . WLD_SSO_CF_VERSION . ' ' . site_url(),
			),
		);
		// Make the request.
		$response = wp_safe_remote_get( $url, $args );

		// Check Response.
		if ( ! is_wp_error( $response ) ) {
			$response = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( isset( $response['success'] ) && true === $response['success'] ) {
				$ips = array(
					'ipv4'          => $response['result']['ipv4_cidrs'] ?? '',
					'ipv6'          => $response['result']['ipv6_cidrs'] ?? '',
					'_last_updated' => time(),
				);

				if ( $ips ) {
					// Update Option.
					update_option( $this->option_name_cf_ips, $ips, false );
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Checks if we are running on the Cloudflare network
	 * Allows filter to override this.
	 *
	 * @return bool
	 */
	protected function on_cloudflare_network() {

		// Allow override using a filter, as some hosting platforms remove some headers that this check uses.
		if ( apply_filters( 'wld_sso_cf_override_on_cf_network_check', false ) ) {
			return true;
		}

		// Which Header contains the Cloudflare IP Address? Defaults to HTTP_CF_CONNECTING_IP.
		$cf_ip_header_name = apply_filters( 'wld_sso_cf_network_check_ip_cf_header', 'HTTP_CF_CONNECTING_IP' );

		// Which Header contains the User IP Address? Defaults to REMOTE_ADDR.
		$user_ip_header_name = apply_filters( 'wld_sso_cf_network_check_ip_user_header', 'REMOTE_ADDR' );

		// Make sure we have an IP Set for both the user and CF.
		// phpcs:disable
		if ( isset( $_SERVER[ $cf_ip_header_name ], $_SERVER[ $user_ip_header_name ] ) ) {
			$ip_cf   = $_SERVER[ $cf_ip_header_name ];
			$ip_user = $_SERVER[ $user_ip_header_name ];
		} else {
			// phpcs:enable
			return false;
		}

		// Check if we are using Cloudflare O2O (Orange 2 Orange) - [ https://developers.cloudflare.com/cloudflare-for-platforms/cloudflare-for-saas/saas-customers/how-it-works/ ].
		// phpcs:disable
		if ( isset( $_SERVER['HTTP_CF_CONNECTING_O2O'] ) &&
		     $_SERVER['HTTP_CF_CONNECTING_O2O']
		) {
			// Check if HTTP_X_FORWARDED_FOR is present.
			if ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
				// Remove spaces and convert to array of IPs.
				$cf_forwarded_for = str_replace( ' ', '', $_SERVER['HTTP_X_FORWARDED_FOR'] );
				// phpcs:enable
				$cf_forwarded_for = array_unique( explode( ',', $cf_forwarded_for ) );
				if ( $cf_forwarded_for ) {
					foreach ( $cf_forwarded_for as $ff_ip ) {
						// Skip User IP.
						if ( $ip_user === $ff_ip ) {
							continue;
						}

						// Set User IP as the Cloudflare IP.
						$ip_user = $ff_ip;
					}
				}
			}
		}

		// only run this logic if the CF IP is populated, to avoid causing notices in CLI mode.
		if ( $ip_cf ) {

			// Grab the Current Cloudflare Address Range.
			$cf_ips_values = get_option( $this->option_name_cf_ips );
			if ( ! $cf_ips_values ) {
				return false;
			}

			// Check if we are getting a IPv4 or IPv6 Address.
			if ( ! str_contains( $ip_cf, ':' ) ) {
				$cf_ip_ranges = $cf_ips_values['ipv4'] ?? '';

				// IPv4: Check if the current IP value is in the specified range.
				foreach ( $cf_ip_ranges as $range ) {
					if ( IP::ipv4_in_range( $ip_cf, $range ) ) {
						return true;
					}
				}
			} else {

				// IPv6: Check if the current IP value is in the specified range.
				$cf_ip_ranges = $cf_ips_values['ipv6'];
				$ipv6         = IP::get_ipv6_full( $ip_cf );
				foreach ( $cf_ip_ranges as $range ) {
					if ( IP::ipv6_in_range( $ipv6, $range ) ) {
						return true;
					}
				}
			}
		}
		return false;
	}

	/**
	 * Outputs the Debug Log.
	 *
	 * @return void
	 */
	public function show_debug_log() {
		if ( $this->is_setting_enabled( Log::$setting_name ) ) {

			// Title.
			echo '<h2>' . esc_html__( 'Debug Logs', 'wld-sso-cf' ) . '</h2>';

			// Get the Table.
			$html = Log::get_html_table();
			if ( $html ) {
				// phpcs:disable
				echo $html;
				// phpcs:enable
			} else {
				esc_html_e( 'No Logs Found', 'wld-sso-cf' );
			}
		}
	}

	/**
	 * Add some extra text before the plugin options
	 *
	 * @return void
	 */
	public function option_page_message() {
		/* translators: Describes how the user will need to use the App Launcher on Cloudflare Zero Trust with a help link */
		$link_text = esc_html__( "You will need to add the WordPress site as a '%s' on Cloudflare Zero Trust.", 'wld-sso-cf' );
		$link      = '<a href="https://developers.cloudflare.com/cloudflare-one/applications/configure-apps/self-hosted-apps/" target="_blank">self-hosted SaaS app</a>';
		?>
		<div>
			<h2><?php esc_html_e( 'Cloudflare Access', 'wld-sso-cf' ); ?></h2>
			<p><?php esc_html_e( 'When setup using the App Launcher, visiting the wp-login.php page will require the user to be logged into Cloudflare (CF) Access (it will redirect them otherwise to login using CF Access)', 'wld-sso-cf' ); ?>
				<br /><span style="color: #c73a3a;"><?php esc_html_e( 'Warning: Setting up CF Access using the app launcher will block any other users wanting to login to WordPress that cannot login to CF Access', 'wld-sso-cf' ); ?></span>
			</p>
			<h2><?php esc_html_e( 'How it Works', 'wld-sso-cf' ); ?></h2>
			<p><?php esc_html_e( 'When a Cloudflare Team Domain is added, WordPress will auto get the JWT Certificates from Cloudflare on a regular basis. This allows the plugin to check the request from Cloudflare Access for SSO is valid as well as determine the user.', 'wld-sso-cf' ); ?>
				<br /><?php esc_html_e( 'If a WP User matches the one validated by Cloudflare, it logs the user in to WordPress. Users are matched using the Email address. Should no WP User exist with the email address, the login is denied, unless the option is enabled for creating a new WP User.', 'wld-sso-cf' ); ?>
				<br /><?php esc_html_e( 'You can login by going to the Cloudflare Zero Trust App Dashboard and clicking on your app or just visit the WP-Login Page, it will then login the relevant user automatically.', 'wld-sso-cf' ); ?>
			</p>
			<h2><?php esc_html_e( 'App Launcher', 'wld-sso-cf' ); ?></h2>
			<p>
			<?php
				// phpcs:disable
				echo sprintf( $link_text, $link );
				// phpcs:enable
			?>
				<br /><?php esc_html_e( "The Path should point to 'wp-login.php', with the subdomain and domain matching your site. Ensure the site is proxied (orange cloud) in your Cloudflare DNS.", 'wld-sso-cf' ); ?>
			</p>
			<p><strong><?php esc_html_e( 'Path:', 'wld-sso-cf' ); ?></strong>&nbsp;<code>wp-login.php</code></p>
			<p>
			<?php
			// phpcs:disable
				esc_html_e( 'Example: ', 'wld-sso-cf' ); ?><br><img alt="Example of the URL filled in on Cloudflare" src="<?php echo WLD_SSO_CF_URL . '/assets/cf-domain-example.webp'; ?>" width="550px"></p>
		</div>
		<?php
		// phpcs:enable
	}

	/**
	 * Add Option Section
	 *
	 * @param array $sections Option Sections.
	 * @return array
	 */
	public function option_add_section( $sections ) {
		$sections['wld_sso_cf_cloudflare'] = array(
			'title' => _x( 'Cloudflare', 'Plugin Setting Section Title', 'wld-sso-cf' ),
			'text'  => _x( 'Manage Cloudflare related options.', 'Plugin Setting Section Description', 'wld-sso-cf' ),
		);
		return $sections;
	}

	/**
	 * Adds the setting fields
	 *
	 * @param array $fields Option Fields.
	 * @return array
	 */
	public function option_add_fields( $fields ) {

		// Section Names.
		$section = 'wld_sso_cf_cloudflare';

		// Setting Field - Cloudflare Access Subdomain.
		$fields[] = array(
			'name'        => $this->plugin_option_name_cf_subdomain,
			'label'       => _x( 'Cloudflare Team Domain', 'Plugin Setting Label', 'wld-sso-cf' ),
			'placeholder' => 'mydomain',
			'help_text'   => _x( 'if your Cloudflare URL is \'mydomain.cloudflareaccess.com\', then your team domain would be \'mydomain\'', 'CF Access Subdomain Setting Placeholder', 'wld-sso-cf' ),
			'section'     => $section,

			// Callback Arguments.
			'type'        => 'text',
		);

		// Setting Field - Add Link to Cloudflare.
		$fields[] = array(
			'name'            => $this->plugin_option_name_cf_link_to_cf,
			'label'           => _x( 'Add SSO Button to WP Login', 'Plugin Setting Label', 'wld-sso-cf' ),
			'help_text'       => _x( 'Adds a Cloudflare Access button to the WP Login page. Option is always set to YES when forcing SSO', 'Force SSO Setting Help text', 'wld-sso-cf' ),
			'section'         => $section,

			// Callback Arguments.
			'type'            => 'radio',
			'label_radio_yes' => _x( 'Yes', 'Plugin Setting Radio Label', 'wld-sso-cf' ),
			'label_radio_no'  => _x( 'No', 'Plugin Setting Radio Label', 'wld-sso-cf' ),
		);

		// Setting Field - Create new user.
		$fields[] = array(
			'name'            => $this->plugin_option_name_cf_create_wp_user,
			'label'           => _x( 'Create new WP User', 'Plugin Setting Label', 'wld-sso-cf' ),
			'help_text'       => _x( "When doing a successfull SSO login, it creates a new WP User (User level determined by 'New User Default Role' WP Option) if they do not already exist. Ignores the 'Anyone can register' WP Setting", 'Create WP User Setting Help text', 'wld-sso-cf' ),
			'section'         => $section,

			// Callback Arguments.
			'type'            => 'radio',
			'label_radio_yes' => _x( 'Yes', 'Plugin Setting Radio Label', 'wld-sso-cf' ),
			'label_radio_no'  => _x( 'No', 'Plugin Setting Radio Label', 'wld-sso-cf' ),
		);

		// Setting Field - Force SSO.
		$fields[] = array(
			'name'            => $this->plugin_option_name_cf_force_sso,
			'label'           => _x( 'Force SSO for Logins', 'Plugin Setting Label', 'wld-sso-cf' ),
			'help_text'       => _x( 'Only allows logins using SSO and disables WP Logins. Always shows the SSO Button when enabled.', 'Force SSO Setting Help text', 'wld-sso-cf' ),
			'warning_text'    => _x( 'WARNING: Do NOT enable until you have tested SSO works correctly, or you may lose access to your site', 'Force SSO Setting Warning Text', 'wld-sso-cf' ),
			'section'         => $section,

			// Callback Arguments.
			'type'            => 'radio',
			'label_radio_yes' => _x( 'Yes', 'Plugin Setting Radio Label', 'wld-sso-cf' ),
			'label_radio_no'  => _x( 'No', 'Plugin Setting Radio Label', 'wld-sso-cf' ),
		);

		// Setting Field - Debug Log.
		$fields[] = array(
			'name'            => Log::$setting_name,
			'label'           => _x( 'Debug Log', 'Plugin Setting Label', 'wld-sso-cf' ),
			'help_text'       => _x( 'Enables the debug log at the bottom of this page', 'Force SSO Setting Help text', 'wld-sso-cf' ),
			'section'         => $section,

			// Callback Arguments.
			'type'            => 'radio',
			'label_radio_yes' => _x( 'Yes', 'Plugin Setting Radio Label', 'wld-sso-cf' ),
			'label_radio_no'  => _x( 'No', 'Plugin Setting Radio Label', 'wld-sso-cf' ),
		);

		return $fields;
	}

	/**
	 * Set Option Defaults
	 *
	 * @param array $defaults Option Defaults.
	 */
	public function option_defaults( $defaults ) : array {
		$defaults[ $this->plugin_option_name_cf_subdomain ]      = '';
		$defaults[ $this->plugin_option_name_cf_force_sso ]      = 'no';
		$defaults[ $this->plugin_option_name_cf_link_to_cf ]     = 'yes';
		$defaults[ $this->plugin_option_name_cf_create_wp_user ] = 'no';
		$defaults[ Log::$setting_name ]                          = 'no';
		return $defaults;
	}
}
new CFAccess();
