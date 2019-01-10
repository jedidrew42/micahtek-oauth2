<?php 

/*
Plugin Name: MicahTek Auth
Description: Allows user registration and login by authenticating against MicahTek
Plugin URI: https://verygoodplugins.com/
Version: 1.4
Author: Very Good Plugins
Author URI: https://verygoodplugins.com/
*/

define( 'MT_CLIENT_ID', '1kvmhkgc99ayjsndsorfqlgfrdmns2wytkbafyh8wk9wwstiouygnktzkdkds88j' );
define( 'MT_CLIENT_SECRET', 'Tw5QSAV(.pEYJ+e/wx3Ge~R6am!A}FGD55vbJj{3PKR!yLg3NwkoRs<rwpW7oNkj' );

final class MicahTek_Auth {

	/** Singleton *************************************************************/

	/**
	 * @var MicahTek_Auth The one true MicahTek_Auth
	 * @since 1.0
	 */
	private static $instance;

	/**
	 * @var Error Any errors encountered
	 * @since 1.0
	 */
	private $error;

	/**
	 * @var Form URL where user started process
	 * @since 1.0
	 */
	private $form_url;

	/**
	 * @var User data for new user
	 * @since 1.0
	 */
	private $user_data;

	/**
	 * @var User ID for new user
	 * @since 1.0
	 */
	private $user_id;

	/**
	 * Main MicahTek_Auth Instance
	 *
	 * Insures that only one instance of MicahTek_Auth exists in memory at any one
	 * time. Also prevents needing to define globals all over the place.
	 *
	 * @since 1.0
	 * @static
	 * @static var array $instance
	 * @return MicahTek_Auth The one true MicahTek_Auth
	 */

	public static function instance() {

		if ( ! isset( self::$instance ) && ! ( self::$instance instanceof MicahTek_Auth ) ) {

			self::$instance = new MicahTek_Auth;

			add_filter( 'mt_errors', array( self::$instance, 'errors' ) );
			add_action( 'template_redirect', array( self::$instance, 'maybe_handle_errors' ) );

			// Login / registration form
			add_shortcode( 'mt_login', array( self::$instance, 'login_form' ) );
			add_action( 'llms_before_person_login_form', array( self::$instance, 'llms_login_form' ) );

			add_action( 'plugins_loaded', array( self::$instance, 'process_form_actions' ) );

			// Handle incoming login / register requests
			add_action( 'plugins_loaded', array( self::$instance, 'process_login_response' ) );

			// Admin settings
			add_action( 'init', array( self::$instance, 'initialize_settings' ) );
			add_action( 'admin_menu', array( self::$instance, 'admin_menu') );

			// Lesson settings
			add_action( 'add_meta_boxes', array( self::$instance, 'add_meta_box' ), 20, 2 );
			add_action( 'save_post', array( self::$instance, 'save_meta_box_data' ), 20 );

		}

	}

	/**
	 * Output any errors
	 *
	 * @access public
	 * @return string Errors
	 */

	public function errors( $output ) {

		if( isset( $_GET['mt_error'] ) ) {

			if( $_GET['mt_error'] == 'auth_error' ) {

				$output .= '<div class="mt-error"><strong>Error:</strong> Authentication error. Please try again or contact support.</div>';

			} elseif( $_GET['mt_error'] == 'multiple_emails' ) {

				$user_data = json_decode( stripslashes( $_GET['userdata'] ) );

				$output .= '<div class="mt-error"><strong>Error:</strong> Multiple email addresses were found on your MicahTek account. Which email would you like to log in with? (' . implode( ', ', $user_data->user->emails ) . ')</div>';

			} elseif( $_GET['mt_error'] == 'missing_setup' ) {

				$output .= '<div class="mt-error"><strong>Error:</strong> For login to work you must first set the class code for this site in the WP Admin, under Settings &raquo; MicahTek Auth.</div>';

			} elseif( $_GET['mt_error'] == 'invalid_class_code' ) {

				$output .= '<div class="mt-error"><strong>Error:</strong> None of your class codes are valid for this site.</div>';

			}

		}

		return $output;

	}

	/**
	 * Redirects back to the login / registration form if errors are encountered
	 *
	 * @access public
	 * @return void
	 */

	public function maybe_handle_errors() {

		if( ! empty( self::$instance->error ) ) {

			error_log('error found ' . self::$instance->error );

			wp_redirect( self::$instance->form_url . '?mt_error=' . self::$instance->error . '&userdata=' . urlencode( json_encode( self::$instance->user_data ) ) );
        	die;

		}

	}

	/**
	 * OAuth handle login response and request access code
	 *
	 * @access public
	 * @return void
	 */

	public function process_login_response() {

		if( ! isset( $_GET['state'] ) || ! isset( $_GET['code'] ) || ! empty( self::$instance->user_data ) || is_user_logged_in() ) {
			return;
		}

		error_log('starting process login response');

		$state = json_decode( urldecode( $_GET['state'] ) );
		self::$instance->form_url = $state->url;

		$params = array(
			'timeout'     => 30,
			'headers'     => array(
				'Content-Type'  => 'application/json',
			)
		);

		$body = array(
			'code' 				=> rawurldecode( $_GET['code'] ),
			'client_id'     	=> MT_CLIENT_ID,
			'client_secret' 	=> MT_CLIENT_SECRET,
			'grant_type' 		=> 'authorization_code'
		);

		$params['body'] = json_encode( $body );

		error_log('doing access code request');
		error_log(print_r($params, true));

		$response = wp_remote_post( 'https://oauth.netviewshop.com', $params );

		error_log('got response');
		error_log(print_r($response, true));

		if( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) == '401' || wp_remote_retrieve_response_code( $response ) == '400' ) {
			
			self::$instance->error = 'auth_error';
			return;

		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		// // Get classes
		$params['headers']['Authorization'] = 'Bearer ' . $response->access_token;
		unset( $params['body'] );

		$response = wp_remote_post( 'https://oauth.netviewshop.com/user/classes', $params );

		error_log('response from get classes');
		error_log(print_r($response, true));

		if( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) == '401' || wp_remote_retrieve_response_code( $response ) == '400' ) {
			
			self::$instance->error = 'auth_error';
			return;

		}

		$user_data = json_decode( wp_remote_retrieve_body( $response ) );

		self::$instance->user_data = $user_data;

		error_log('got user data');
		error_log(print_r($user_data, true));

		error_log('state');
		error_log(print_r($state, true));

		if( count( $user_data->user->emails ) > 1 ) {

			self::$instance->error = 'multiple_emails';
			return;

		}
		
		// Check class code

		$settings = get_option( 'mta_settings', array() );

		if( empty( $settings ) || empty( $settings['class_code'] ) ) {
			self::$instance->error = 'missing_setup';
			return;
		}

		$class_code_valid = false;

		foreach( $user_data->classes as $class ) {

			if( $class->classCode == $settings['class_code'] ) {
				$class_code_valid = true;
				break;
			}

		}

		if( ! $class_code_valid ) {

			self::$instance->error = 'invalid_class_code';
			return;

		}


		// Handle request

		$user = get_user_by( 'email', $user_data->user->emails[0] );

		error_log('getting existing user');
		error_log(print_r($user, true));

		if( ! empty( $user ) ) {

			self::$instance->do_login( $user_data->user->emails[0] );

		} else {

			self::$instance->do_register( $user_data, $user_data->user->emails[0] );

		}

	}

	/**
	 * Log in existing user
	 *
	 * @access public
	 * @return void
	 */

	public function do_login( $login_email ) {

		error_log('doing login');

		$user = get_user_by( 'email', $login_email );

		wp_clear_auth_cookie();
	    wp_set_current_user( $user->ID );
	    wp_set_auth_cookie( $user->ID );

	}


	/**
	 * Register a new user and log them in
	 *
	 * @access public
	 * @return void
	 */

	public function do_register( $user_data, $register_email ) {

		error_log('doing register');

		$new_user = array(
			'user_email'	=> $register_email,
			'first_name'	=> $user_data->user->firstName,
			'last_name'		=> $user_data->user->lastName,
			'user_login'	=> $register_email,
			'user_pass'		=> wp_generate_password()
		);

		error_log(print_r($new_user, true));

		$user_id = wp_insert_user( $new_user );

		wp_clear_auth_cookie();
	    wp_set_current_user( $user_id );
	    wp_set_auth_cookie( $user_id );

	    self::$instance->user_data = $user_data;
	    self::$instance->user_id = $user_id;

	    // Queue course progress setting for after WP is loaded
	    add_action( 'init', array( self::$instance, 'set_course_progress' ) );

	}


	/**
	 * Set new user course progress
	 *
	 * @access public
	 * @return void
	 */

	public function set_course_progress() {

		$user_data = self::$instance->user_data;
		$user_id = self::$instance->user_id;

		// Figure out which user is logging in
		$user = get_user_by( 'id', $user_id );

		foreach( $user_data->user->emails as $i => $email ) {

			if($user->user_email == $email) {
				$session_class = $i;
			}

		}

		$site_settings = get_option( 'mta_settings', array() );

		error_log('set course progress for ' . $user_id . ' with data');
		error_log(print_r($user_data, true));
		error_log('session class is ' . $session_class);

		// First check if student progress is empty (on ProvInst)

		if( $site_settings['class_code'] == 'ProvInst' ) {

			$progress_empty = true;

			foreach( $user_data->classes as $class ) {

				if( $class->classCode == 'ProvInst' && $session_class == 0 && ! empty( $class->progress ) ) {

					$progress_empty = false;

				} elseif( $class->classCode == 'ProvInst2' && $session_class == 1 && ! empty( $class->progress ) ) {

					$progress_empty = false;

				}

			}

			// If progress is emtpy enroll them in default course on ProvInst

			if( $progress_empty ) {

				error_log('progress empty');

				$student = new LLMS_Student( $user_id );
				$student->enroll( 5294, 'micahtek' );

				return;

			}

		}

		// Set course progress

		$lessons = get_posts( array(
			'post_type'  => 'lesson',
			'nopaging'   => true,
			'fields'     => 'ids',
			'meta_query' => array(
				array(
					'key'     => 'micahteck-setup',
					'compare' => 'EXISTS'
				),
			),
		) );

		foreach( $lessons as $lesson_id ) {

			$settings = get_post_meta( $lesson_id, 'micahteck-setup', true );
			$course_id = get_post_meta( $lesson_id, '_llms_parent_course', true );
			$course = new LLMS_Course( $course_id );

			if( empty( $settings ) || empty( $settings['progress_code'] ) ) {
				continue;
			}

			foreach( $user_data->classes as $class ) {

				if( $class->classCode == $site_settings['class_code'] ) {

					if( in_array( $settings['progress_code'], $class->progress ) ) {

						// Progress needs to be set

						$student = new LLMS_Student( $user_id );

						if( ! llms_is_user_enrolled( $user_id, $course_id ) ) {

							error_log('enrolling in ' . $course_id);
							$student->enroll( $course_id, 'micahtek' );

						}

						$lessons_in_course = $course->get_lesson_ids();

						error_log('lesson ids');
						error_log(print_r($lessons_in_course, true));

						foreach( $lessons_in_course as $lesson_in_course_id ) {

							if( $lesson_in_course_id == $lesson_id ) {
								break;
							}

							error_log('marking lesson ' . $lesson_in_course_id . ' complete');
							$student->mark_complete( $lesson_in_course_id, 'lesson', 'micahteck' );

						}


					}

				}

			}

		}

	}

	/**
	 * OAuth request login
	 *
	 * @access public
	 * @return void
	 */

	public function get_oauth_login_url( $form_url ) {

		$url = 'https://garykeesee.netviewshop.com/OAuthLogin';

		$redirect_uri = str_replace('http://', 'https://', get_home_url());

		$args = array(
			'response_type' => 'code',
			'client_id'     => MT_CLIENT_ID,
			'redirect_uri'  => urlencode( $redirect_uri ),
			'scope'         => 'user%20classes',
			'state'         => urlencode( json_encode( array( 'url' => $form_url ) ) )
		);

		$url = add_query_arg( $args, $url );

		return $url;

	}

	/**
	 * Process register / login form submissions
	 *
	 * @access public
	 * @return void
	 */

	public function process_form_actions() {

		if( isset( $_POST['mt_action'] ) && $_POST['mt_action'] == 'login' ) {

			error_log('process form');
			error_log(print_r($_POST, true));

			// Verify that the nonce is valid.
			if ( ! wp_verify_nonce( $_POST['mt_login_nonce'], 'mt_login' ) ) {
				return;
			}

			$user = get_user_by( 'email', $_POST['mt_login_email'] );

			if( empty( $user ) ) {
				
				$user_data = json_decode( stripslashes( $_POST['mt_user_data'] ) );

				self::$instance->do_register( $user_data, $_POST['mt_login_email'] );

			} else {

				self::$instance->do_login( $_POST['mt_login_email'] );

				wp_redirect( home_url() );
				exit;

			}

		}

	}


	/**
	 * Render login form
	 *
	 * @access public
	 * @return string Login Form
	 */

	public function login_form() {

		if( is_user_logged_in() ) {

			$current_user = wp_get_current_user();

			return 'You are currently logged in as ' . $current_user->user_email;

		}

		$output = '';

		if( ! isset( $_GET['mt_error'] ) || $_GET['mt_error'] != 'multiple_emails' ) {

			$url = self::$instance->get_oauth_login_url( get_permalink() );

			$output .= apply_filters( 'mt_errors', $output );

			$output .= '<a href="' . $url . '" class="button mt-login-button">Log In</a>';

			return $output;

		} else {

			$output .= apply_filters( 'mt_errors', $output );

			$output .= '<form id="mt-login" method="post">';
			$output .= wp_nonce_field( 'mt_login', 'mt_login_nonce', true, false );
			$output .= '<p><input type="text" placeholder="Email address" id="mt-login-email" name="mt_login_email" /></p>';
			$output .= '<input type="hidden" name="mt_user_data" value=\'' . stripslashes( $_GET['userdata'] ) . '\'">';
			$output .= '<input type="hidden" name="mt_action" value="login">';
			$output .= '<input type="hidden" name="mt_form_url" value="' . get_permalink() . '">';
			$output .= '<input type="submit" value="Log In">';
			$output .= '</form>';

			return $output;

		}
	 
	}

	/**
	 * Replace LLMS login form with this one
	 *
	 * @access public
	 * @return mixed HTML output
	 */

	public function llms_login_form() {

		echo self::$instance->login_form();
		echo '<style type="text/css"> .llms-person-login-form-wrapper { display: none; } .llms-new-person-form-wrapper { display: none; }</style>';

	}

	/**
	 * Initialize plugin settings to default
	 *
	 * @access public
	 * @return void
	 */

	public function initialize_settings() {

		$settings = get_option( 'mta_settings', array() ); 

		if( empty( $settings ) ) {
			
			$settings = array(
				'class_code'			=> false
			);

			update_option( 'mta_settings', $settings );
		}

	}

	/**
	 * Creates admin menu item
	 *
	 * @access public
	 * @return void
	 */

	public function admin_menu() {

		$id = add_submenu_page(
			'options-general.php',
			'MicahTek Auth',
			'MicahTek Auth',
			'manage_options',
			'mta-settings',
			array(self::$instance, 'render_admin_menu')
		);

	}

	/**
	 * Output settings page
	 *
	 * @access public
	 * @return mixed HTML Output
	 */

	public function render_admin_menu() {

		// Save settings

		if (isset($_POST['mta_settings_nonce']) && wp_verify_nonce($_POST['mta_settings_nonce'], 'mta_settings')) {
			update_option('mta_settings', $_POST['mta_settings']);
			echo '<div id="message" class="updated fade"><p><strong>Settings saved.</strong></p></div>';
		}

		?>

		<div class="wrap">
			<h2>MicahTek Auth Settings</h2>

			<form id="ss-settings" action="" method="post">
				<?php wp_nonce_field('mta_settings', 'mta_settings_nonce'); ?>
				<?php $settings = get_option( 'mta_settings', array() ); ?>

				<input type="hidden" name="action" value="update">

				<table class="form-table">
					<tbody>

						<tr valign="top">
							<th scope="row">
								Class Code
							</th>
							<td>
								<input id="class_code" class="form-control" type="text" name="mta_settings[class_code]" placeholder="" value="<?php echo $settings["class_code"]; ?>" />
								<br /><span class="description">Enter the class code for this site. Users will need this class code to create an account.</span>
							</td>
						</tr>

					</tbody>
				</table>

				<p class="submit">
					<input name="Submit" type="submit" class="button-primary" value="Save Changes"/>
				</p>
			</form>
		</div>

		<?php

	}

	/**
	 * Adds meta box
	 *
	 * @access public
	 * @return mixed
	 */

	public function add_meta_box( $post_id, $data ) {

		add_meta_box( 'micahtek-setup', 'Progress Code', array( self::$instance, 'meta_box_callback' ), 'lesson' );

	}


	/**
	 * Displays meta box content
	 *
	 * @access public
	 * @return mixed
	 */

	public function meta_box_callback( $post ) {

		wp_nonce_field( 'micahtek_setup', 'micahtek_setup_nonce' );

		$settings = array(
			'progress_code' => false
		);

		if ( get_post_meta( $post->ID, 'micahteck-setup', true ) ) {
			$settings = array_merge( $settings, get_post_meta( $post->ID, 'micahteck-setup', true ) );
		}

		echo '<input type="text" name="mt_setup[progress_code]" value="' . $settings["progress_code"] . '" />';
		echo '<br /><span class="description">Enter a progress code associated with this lesson. When a user registers with this progress code, their progress will be set to this point.</span>';

	}

	/**
	 * Runs when WPF meta box is saved
	 *
	 * @access public
	 * @return void
	 */

	public function save_meta_box_data( $post_id ) {

		// Check if our nonce is set.
		if ( ! isset( $_POST['micahtek_setup_nonce'] ) ) {
			return;
		}

		// Verify that the nonce is valid.
		if ( ! wp_verify_nonce( $_POST['micahtek_setup_nonce'], 'micahtek_setup' ) ) {
			return;
		}

		// If this is an autosave, our form has not been submitted, so we don't want to do anything.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Don't update on revisions
		if ( $_POST['post_type'] == 'revision' ) {
			return;
		}


		if ( isset( $_POST['mt_setup'] ) ) {
			$data = $_POST['mt_setup'];
		} else {
			$data = array();
		}

		// Update the meta field in the database.
		update_post_meta( $post_id, 'micahteck-setup', $data );

	}


}


function micahtek_auth() {
	return MicahTek_Auth::instance();
}

// Get plugin running
micahtek_auth();