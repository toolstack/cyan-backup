<?php
if( !class_exists( 'CYAN_WP_Backup' ) ) {
	class CYANBackup {
		public $plugin_name = 'CYAN Backup';
		public $archive_path;
		public $CYANBackupWorker;
		public $CYANBackupAjax;
		public $Utils;

		private $plugin_basename;
		private $plugin_dir;
		private $plugin_file;
		private $plugin_url;
		private $plugin_path;
		private $include_path;
		private $menu_base;
		private $option_name;
		private $options;
		private $admin_action;
		private $debug_log = null;
		private $backup_page;
		private $option_page;
		private $about_page;

		private $default_excluded = array(
			'wp-content/cache/',
			'wp-content/tmp/',
			'wp-content/upgrade/',
			);

		const ACCESS_LEVEL = 'manage_options';
		const NONCE_NAME   = '_wpnonce_CYAN_Backup';
		const TIME_LIMIT   = 900;			// 15min * 60sec
		const DEBUG_MODE   = FALSE;
		const VERSION      = '3.0-alpha';

		function __construct() {
			GLOBAL $wpdb;

			$this->set_plugin_dir( __FILE__ );

			include_once( $this->include_path . '/classes/class-cyan-utilities.php' );
			$this->Utils = new CYAN_Utilities;

			$this->option_name = $this->plugin_name . ' Option';
			$this->load_textdomain( $this->plugin_dir, 'languages', 'cyan-backup' );

			// add admin menu
			$this->menu_base = basename( $this->plugin_file, '.php' );
			if( function_exists( 'is_multisite' ) && is_multisite() ) {
				$this->admin_action = $this->wp_admin_url( 'network/admin.php?page=' . $this->menu_base );
				add_action( 'network_admin_menu', array( &$this, 'admin_menu' ) );
			} else {
				$this->admin_action = $this->wp_admin_url( 'admin.php?page=' . $this->menu_base );
				add_action( 'admin_menu', array( &$this, 'admin_menu' ) );
				add_filter( 'plugin_action_links', array( &$this, 'plugin_setting_links' ), 10, 2 );
			}
			add_action( 'init', array( &$this, 'file_download' ) );

			$this->options = get_option( $this->option_name );

			$this->archive_path = $this->get_archive_path( $this->options );
			
			$this->CYANBackupWorker = new CYAN_Backup_Worker(
																$this->archive_path,
																$this->get_archive_prefix( $this->options ),
																trailingslashit( ABSPATH, FALSE ),
																$this->get_excluded_dir( $this->options ),
																$this->Utils,
																$this->options
															);

			$this->CYANBackupAjax = new CYAN_Backup_Ajax(
																$this->Utils,
																$this->options
															);

			// Run the upgrade code if required
			if( $this->options['version'] != self::VERSION )
				{
				$this->options['version'] = self::VERSION;
				$this->options['next_backup_time'] = wp_next_scheduled( 'cyan_backup_hook' );

				if( !isset( $this->options['schedule']['ampm'] ) ) {
					list( $hours, $this->options['schedule']['minutes'], $this->options['schedule']['hours'], $this->options['schedule']['ampm'] ) = $this->split_date_string( $schedule['tod'] );
				}

				// Remove the old 'Disable ZipArchive' option, but if it was set, update the new archive_method if it hasn't already been set by the user.
				if( array_key_exists( 'disableziparchive', $this->options ) && $options['disableziparchive'] ) {
					if( !array_key_exists( 'archive_method', $this->options ) ) { $this->options['archive_method'] = 'PclZip'; }
					unset( $this->options['disableziparchive'] );
				}

				update_option( $this->option_name, $this->options );
				}

			// activation & deactivation
			if( function_exists( 'register_activation_hook' ) ) {
				register_activation_hook(__FILE__, array(&$this, 'activation'));
			}

			if( function_exists( 'register_deactivation_hook' ) ) {
				register_deactivation_hook( __FILE__, array( &$this, 'deactivation' ) );
			}
		}

		function __destruct() {
			$this->close_debug_log();
		}

		//**************************************************************************************
		// Plugin activation
		//**************************************************************************************
		public function activation() {
		}

		//**************************************************************************************
		// Plugin deactivation
		//**************************************************************************************
		public function deactivation() {
		}

		//**************************************************************************************
		// Utility
		//**************************************************************************************

		// set plugin dir
		private function set_plugin_dir( $file = '' ) {
			if( !empty( $file ) ) {
				$file_path = $file;
			} else {
				$file_path = __FILE__;
			}

			$filename = explode( "/", $file_path );

			if( count( $filename ) <= 1 ) {
				$filename = explode("\\", $file_path);
			}

			$this->plugin_basename = plugin_basename( $file_path );
			$this->plugin_dir  = $filename[count( $filename ) - 4];
			$this->plugin_file = $filename[count( $filename ) - 1];
			$this->plugin_url  = $this->wp_plugin_url($this->plugin_dir);

			unset( $filename[count( $filename ) - 1] );
			unset( $filename[count( $filename ) - 1] );
			unset( $filename[count( $filename ) - 1] );
			$this->plugin_path = implode( '/', $filename );

			$this->include_path = $this->plugin_path . '/includes';
		}

		// load textdomain
		private function load_textdomain( $plugin_dir, $sub_dir = 'languages', $textdomain_name = FALSE ) {
			if( FALSE !== $textdomain_name ) {
				$textdomain_name = $textdomain_name;
			} else {
				$textdomain_name = $plugin_dir;
			}

			$abs_plugin_dir = $this->wp_plugin_dir( $plugin_dir );

			$sub_dir = preg_replace('/^\//', '', $sub_dir);

			$textdomain_dir = trailingslashit( trailingslashit( $plugin_dir ) . $sub_dir, FALSE );

			load_plugin_textdomain( $textdomain_name, null, $textdomain_dir );

			return $textdomain_name;
		}

		// WP_SITE_URL
		private function wp_site_url( $path = '' ) {
			return trailingslashit( site_url() ) . $path;
		}

		// admin url
		private function wp_admin_url( $path = '' ) {
			return admin_url( $path );
		}

		// WP_CONTENT_DIR
		private function wp_content_dir( $path = '' ) {
			return trailingslashit( WP_CONTENT_DIR, FALSE ) . $path;
		}

		// WP_CONTENT_URL
		private function wp_content_url( $path = '' ) {
			return content_url( $path );
		}

		// WP_PLUGIN_DIR
		private function wp_plugin_dir( $path = '' ) {
			return trailingslashit( $this->wp_content_dir( 'plugins/' . preg_replace( '/^\//', '', $path ) ), FALSE );
		}

		// WP_PLUGIN_URL
		private function wp_plugin_url( $path = '' ) {
			return trailingslashit( $this->wp_content_url( 'plugins/' . preg_replace( '/^\//', '', $path ) ) );
		}

		// get current user ID & Name
		private function get_current_user() {
			static $username = NULL;
			static $userid   = NULL;

			if( is_user_logged_in() ) {
				$current_user = wp_get_current_user();

				$username = $current_user->display_name;
				$userid   = $current_user->ID;
			}

			return array( $userid, $username );
		}

		// get nonces
		private function get_nonces( $nonce_field = 'backup' ) {
			$nonces = array();

			$nonce = wp_nonce_field( $nonce_field, self::NONCE_NAME, true, false );
			$pattern = '/<input [^>]*name=["]([^"]*)["][^>]*value=["]([^"]*)["][^>]*>/i';

			if( preg_match_all( $pattern, $nonce, $matches, PREG_SET_ORDER ) ) {
				foreach( $matches as $match ) {
					$nonces[$match[1]] = $match[2];
				}
			}

			return $nonces;
		}

		// get permalink type
		private function get_permalink_type() {
			$permalink_structure = get_option('permalink_structure');

			if( empty( $permalink_structure ) || !$permalink_structure ) {
				$permalink_type = 'Ugly';
			} else if( preg_match( '/^\/index\.php/i', $permalink_structure ) ) {
				$permalink_type = 'Almost Pretty';
			} else {
				$permalink_type = 'Pretty';
			}

			return $permalink_type;
		}

		// get request var
		private function get_request_var( $key, $default = NULL ) {
			if( isset( $_POST[$key] ) ) {
				return $_POST[$key];
			} else if( isset( $_GET[$key] ) ) {
				return $_GET[$key];
			}

			return $default;
		}

		// get archive path
		private function get_archive_path( $option = '' ) {
			if( empty( $option ) || !is_array( $option ) ) {
				$option = $this->options;
			}

			$temp_path = sys_get_temp_dir();

			$archive_path = '';

			if( isset( $option['archive_path'] ) && is_dir( $option['archive_path'] ) ) {
				$archive_path = $option['archive_path'];
			} else {
				$archive_path = $temp_path;
			}

			if( is_dir( $archive_path ) && is_writable( $archive_path ) ) {
				return $archive_path;
			}

			return FALSE;
		}

		// get archive prefix
		private function get_archive_prefix( $option = '' ) {
			if( is_array( $option ) && array_key_exists( 'archive_prefix', $option ) && $option['archive_prefix'] != '' ) {
				return $option['archive_prefix'];
			} else {
				return basename( ABSPATH ) . '.';
			}
		}

		// get excluded dir
		private function get_excluded_dir( $option = '', $special = FALSE ) {
			if( empty( $option ) || !is_array( $option ) ) {
				$option = (array)get_option( $this->option_name );
			}

			if( FALSE === $special ) {
				$excluded =	array( './' , '../' );
			} else {
				$excluded = (array) $special;
			}

			if( isset( $option['excluded'] ) && is_array( $option['excluded'] ) ) {
				$excluded = array_merge( $excluded, $option['excluded'] );
			} else {
				$excluded = array_merge( $excluded, $this->default_excluded );
			}

			$excluded = $this->Utils->chg_directory_separator( $excluded, FALSE );

			return $excluded;
		}

		// remote backuper
		private function remote_backuper( $option = NULL ) {
			return $this->CYANBackupWorker;
		}

		// get backup files
		private function get_backup_files() {
			$remote_backuper = $this->remote_backuper();

			return $remote_backuper->get_backup_files();
		}

		// backup files info
		private function backup_files_info( $backup_files = NULL ) {
			$nonces = '';

			foreach( $this->get_nonces( 'backup' ) as $key => $val ) {
				$nonces .= '&' . $key . '=' . rawurlencode( $val );
			}

			$remote_backuper = $this->remote_backuper();

			return $remote_backuper->backup_files_info( $nonces, $this->menu_base );
		}

		// write a line to the log file
		private function write_debug_log( $text ) {
			if( $this->debug_log == null ) {
				$this->debug_log = fopen( $this->archive_path . 'debug.txt', 'a' );
			}

			fwrite( $this->debug_log, '[' . date( 'Y-m-d H:i:s' ) . '] ' . $text . "\n" );
		}

		private function close_debug_log() {
			if( $this->debug_log != null ) {
				fclose( $this->debug_log );

				$this->debug_log = null;
			}
		}

		//**************************************************************************************
		// Site backup
		//**************************************************************************************
		public function json_backup() {
			$remote_backuper = $this->remote_backuper();

			$result = $remote_backuper->wp_backup();

			if( isset( $result['backup'] ) ) {
				$backup_file = $result['backup'];
			} else {
				$backup_file = FALSE;
			}

			if( $backup_file && file_exists( $backup_file ) ) {
				$filesize = (int)sprintf( '%u', filesize( $backup_file ) ) / 1024 / 1024;
				$temp_time = strtotime( $this->Utils->get_filemtime( $backup_file ) );
				$filedate = date( get_option( 'date_format' ), $temp_time ) . ' @ ' . date( get_option( 'time_format' ), $temp_time );

				$this->transfer_backups( $backup_file, $this->options['remote'], 'manual' );

				$this->prune_backups( $this->options['prune']['number'] );

				return array(
								'backup_file' => $backup_file,
								'backup_date' => $filedate,
								'backup_size' => number_format($filesize, 2) . ' MB',
								'backup_deleted' => $this->options['remote']['deletelocal'],
								);
			} else {
				return $result;
			}
		}

		public function scheduled_backup() {
			$remote_backuper = $this->remote_backuper();

			//$this->write_debug_log( "Starting backup" );
			// Run the backup.
			$result = $remote_backuper->wp_backup();
			//$this->write_debug_log( "Completed backup" );

			//$this->write_debug_log( "Starting next schedule" );
			// Determine the next backup time.
			$this->schedule_next_backup();
			//$this->write_debug_log( "Completed next schedule" );

			//$this->write_debug_log( "Starting transfer" );
			// Send the backup to remote storage.
			$this->transfer_backups( $result['backup'], $this->options['remote'], 'schedule' );
			//$this->write_debug_log( "Completed transfer" );

			//$this->write_debug_log( "Starting pruning" );
			// Prune existing backup files as per the options.
			$this->prune_backups( $this->options['prune']['number'] );
			//$this->write_debug_log( "Completed pruning" );
		}

		private function transfer_backups( $archive, $remote_settings, $source ) {
			// We need to create the final remote directory to store the backup in.
			$final_dir = $remote_settings['path'];
			$final_dir = str_replace( '%m', date('m'), $final_dir );
			$final_dir = str_replace( '%d', date('d'), $final_dir );
			$final_dir = str_replace( '%Y', date('Y'), $final_dir );
			$final_dir = str_replace( '%M', date('M'), $final_dir );
			$final_dir = str_replace( '%F', date('F'), $final_dir );
			$final_dir = trailingslashit( $final_dir, FALSE );

			// Let's make sure we don't have a funky archive path.
			$archive = realpath( $archive );

			// Decrypt the password from the settings.
			$final_password = $this->decrypt_password( $remote_settings['password'] );

			// Get the basename of the archive for later.
			$filename = basename( $archive );

			$rb = $this->remote_backuper();

			// We need to find the log file path and name.
			$log = str_ireplace( $rb->GetArchiveExtension(), '.log', $archive );

			// Find the basename of the log file.
			$logname = basename( $log );

			// Do the work now.
			switch( $remote_settings['protocol'] )
				{
				case 'ftpwrappers':
					include_once( $this->include_path . '/protocols/protocol-ftpwrappers.php' );

					break;
				case 'ftplibrary':
					include_once( $this->include_path . '/protocols/protocol-ftplibrary.php' );

					break;
				case 'ftpswrappers':
					include_once( $this->include_path . '/protocols/protocol-ftpswrappers.php' );

					break;
				case 'ftpslibrary':
					include_once( $this->include_path . '/protocols/protocol-ftpslibrary.php' );

					break;
				case 'sftpwrappers':
					include_once( $this->include_path . '/protocols/protocol-sftpwrappers.php' );

					break;
				case 'sftplibrary':
					include_once( $this->include_path . '/protocols/protocol-sftplibrary.php' );

					break;
				case 'sftpphpseclib':
					include_once( $this->include_path . '/protocols/protocol-sftpphpseclib.php' );

					break;
				}

			// If the send of the zip file worked and we've been told to delete the local copies of the zip and log, do so now.
			if( $result !== FALSE ) {
				if( ( $remote_settings['deletelocalmanual'] == 'on' && $source == 'manual' ) || ( $remote_settings['deletelocalschedule'] == 'on' && $source == 'schedule' ) ) {
					@unlink( $archive );
					@unlink( $log );
				}
			}
		}

		private function get_encrypt_key() {
			// First determine how large of key we need.
			$key_size = mcrypt_get_key_size( MCRYPT_RIJNDAEL_128, MCRYPT_MODE_CBC );

			// Use WordPress's generated constant for the key, trimming it to the length we need.
			$key = substr( SECURE_AUTH_KEY, 0, $key_size );

			return $key;
		}

		private function encrypt_password( $password ) {
			// If mcrypt isn't supported or it's a blank password, don't encrypt it.
			if( function_exists( 'mcrypt_encrypt' ) && $password != '' ) {
				// Get the encryption key we're going to use.
				$key = $this->get_encrypt_key();

				// Create a random IV (with the specific length we need) to use with CBC encoding.
				$iv_size = mcrypt_get_iv_size( MCRYPT_RIJNDAEL_128, MCRYPT_MODE_CBC );
				$iv = mcrypt_create_iv( $iv_size, MCRYPT_RAND );

				// Paste the IV and newly encrypted string together.
				$cpassword = $iv . mcrypt_encrypt( MCRYPT_RIJNDAEL_128, $key, $password, MCRYPT_MODE_CBC, $iv );

				// Return a nice base64 encoded string to make it all look nice.
				return base64_encode( $cpassword );
			} else {
				return $password;
			}
		}

		private function decrypt_password( $password ) {
			// If mcrypt isn't supported or it's a blank password, don't decrypt it.
			if( function_exists( 'mcrypt_encrypt' ) && $password != '') {
				// Get the encryption key we're going to use.
				$key = $this->get_encrypt_key();

				// Since we made it look nice with base64 while encrypting it, make it look messy again.
				$password = base64_decode( $password );

				// Retrieves the IV from the combined string.
				$iv_size = mcrypt_get_iv_size( MCRYPT_RIJNDAEL_128, MCRYPT_MODE_CBC );
				$iv = substr( $password, 0, $iv_size );

				// Retrieves the cipher text (everything except the $iv_size in the front).
				$password = substr( $password, $iv_size );

				// Decrypt the password.
				$dpassword = mcrypt_decrypt( MCRYPT_RIJNDAEL_128, $key, $password, MCRYPT_MODE_CBC, $iv );

				// may have to remove 00h valued characters from the end of plain text
				$dpassword = str_replace( chr(0), '', $dpassword );

				return $dpassword;
			} else {
				return $password;
			}
		}


		//**************************************************************************************
		// Add setting link to the WordPress plugin page
		//**************************************************************************************
		public function plugin_setting_links( $links, $file ) {
			GLOBAL $wp_version;

			$this_plugin = plugin_basename( __FILE__ );
			if( $file == $this_plugin ) {
				$settings_link = '<a href="' . $this->admin_action . '-options">' . __( 'Settings', 'cyan-backup' ) . '</a>';
				array_unshift( $links, $settings_link ); // before other links
			}

			return $links;
		}

		//**************************************************************************************
		// Add Admin Menu Scripts
		//**************************************************************************************
		public function add_admin_scripts() { 
			wp_enqueue_script( 'jquery' );
			wp_enqueue_script( 'jquery-ui-core' );
			wp_enqueue_script( 'jquery-ui-tabs' );
			wp_enqueue_script( 'jquery-ui-progressbar' );

			wp_register_style( 'jquery-ui-css', $this->plugin_url . 'css/jquery-ui-1.10.4.custom.css' );
			wp_enqueue_style( 'jquery-ui-css' );

			wp_register_style( 'cyan-backup-css', $this->plugin_url . 'css/backup.css' );
			wp_enqueue_style( 'cyan-backup-css' );
		}

		public function add_admin_head() {
		}

		public function add_admin_head_main() {
			list( $userid, $username ) = $this->get_current_user();
			$userid = (int)$userid;
			$option = (array)get_option( $this->option_name );

			$site_url = trailingslashit( home_url() );

			if( array_key_exists( 'forcessl', $option ) ) {
				if( $option['forcessl'] == 'on' ) {
					$site_url = str_ireplace( 'http://', 'https://', $site_url );
				}
			}

			$json_backup_url  = $site_url;
			$json_status_url  = $site_url;
			$json_backup_args = "userid:{$userid}";
			$json_status_args = "userid:{$userid}";
			$json_method_type = 'POST';

			switch ($this->get_permalink_type()) {
				case 'Pretty':
					$json_backup_url .= 'json/backup/';
					$json_status_url .= 'json/status/';
					$json_method_type = 'POST';
					break;
				case 'Almost Pretty':
					$json_backup_url .= 'index.php/json/backup/';
					$json_status_url .= 'index.php/json/status/';
					$json_method_type = 'POST';
					break;
				case 'Ugly':
				default:
					$json_backup_args .= "json:'backup'";
					$json_status_args .= "json:'status'";
					$json_method_type = 'GET';
					break;
			}

			$img = '<img src="%1$s" class="%2$s" style="display:inline-block;position:relative;left:.25em;top:.25em;width:16p;height:16px;" />';
			$loading_img = sprintf( $img, $this->wp_admin_url('images/wpspin_light.gif'), 'updating' );
			$success_img = sprintf( $img, $this->plugin_url . 'images/success.png', 'success' );
			$failure_img = sprintf( $img, $this->plugin_url . 'images/failure.png', 'failure' );

			$nonces_1 = $nonces_2 = $nonces_3 = '';

			foreach( $this->get_nonces( 'backup' ) as $key => $val ) {
				$nonces_1 .= "'{$key}':'{$val}'";
				$nonces_2 .= '&' . $key . '=' . rawurlencode($val);
			}

			foreach( $this->get_nonces( 'status' ) as $key => $val ) {
				$nonces_3 .= "'{$key}':'{$val}'";
			}

			wp_register_script( 'cyan-backup-js', $this->plugin_url . 'js/backup.js' );
			wp_enqueue_script( 'cyan-backup-js' );
			
	?>
	<script type="text/javascript">//<![CDATA[
	function CYANBackupVariables( name ) {
		switch( name ) {
			case 'json_status_args':
				return <?php echo json_encode( $json_status_args ); ?>;
				break;
			case 'json_backup_url':
				return <?php echo json_encode( $json_backup_url ); ?>;
				break;
			case 'json_status_url':
				return <?php echo json_encode( $json_status_url ); ?>;
				break;
			case 'json_backup_args':
				return <?php echo json_encode( $json_backup_args ); ?>;
				break;
			case 'nonces_1':
				return <?php echo json_encode( $nonces_1 ); ?>;
				break;
			case 'nonces_2':
				return <?php echo json_encode( $nonces_2 ); ?>;
				break;
			case 'nonces_3':
				return <?php echo json_encode( $nonces_3 ); ?>;
				break;
			case 'loading_img':
				return <?php echo json_encode( $loading_img ); ?>;
				break;
			case 'menu_base':
				return <?php echo json_encode( $this->menu_base ); ?>;
				break;
			case 'success_img':
				return <?php echo json_encode( $success_img ); ?>;
				break;
			case 'failure_img':
				return <?php echo json_encode( $failure_img ); ?>;
				break;
			case 'archive_path':
				return <?php echo json_encode( $this->archive_path ); ?>;
				break;
		}
		
		return '';
	}
	//]]></script>
	<?php
		}

		public function add_admin_head_option() {
		}

		public function icon_style() {
			wp_register_style( 'cyan-backup-config-css', $this->plugin_url . 'css/config.css' );
			wp_enqueue_style( 'cyan-backup-config-css' );
		}

		public function add_admin_tabs() {
			wp_register_style( 'cyan-backup-tabs-css', $this->plugin_url . 'css/jquery-ui-cyan-tabs.css' );
			wp_enqueue_style( 'cyan-backup-tabs-css' );
		}

		public function admin_menu() {
			$this->backup_page = add_menu_page(
												__( 'CYAN Backup', 'cyan-backup' ),
												__( 'CYAN Backup', 'cyan-backup' ),
												self::ACCESS_LEVEL,
												$this->menu_base ,
												array( $this, 'site_backup' ),
												$this->plugin_url . 'images/backup16.png'
											);

//admin_print_scripts-wp-eventcal/eventcal-manager
											
			add_action( 'admin_print_scripts-' . $this->backup_page, array( $this, 'add_admin_scripts' ) );
			add_action( 'admin_head-' . $this->backup_page, array( $this, 'add_admin_head' ) );
			add_action( 'admin_head-' . $this->backup_page, array( $this, 'add_admin_head_main' ) );
			add_action( 'admin_print_styles-' . $this->backup_page, array( $this, 'icon_style' ) );

			add_submenu_page(
								$this->menu_base ,
								__( 'Backups', 'cyan-backup' ),
								__( 'Backups', 'cyan-backup' ),
								self::ACCESS_LEVEL,
								$this->menu_base,
								array( $this, 'site_backup' )
							);

			$this->option_page = add_submenu_page(
													$this->menu_base,
													__( 'Options &gt; CYAN Backup', 'cyan-backup' ),
													__( 'Options', 'cyan-backup' ),
													self::ACCESS_LEVEL,
													$this->menu_base . '-options',
													array( $this, 'option_page' )
												);

			add_action( 'admin_print_scripts-' . $this->option_page, array( $this, 'add_admin_scripts' ) );
			add_action( 'admin_head-' . $this->option_page, array( $this,'add_admin_head' ) );
			add_action( 'admin_head-' . $this->option_page, array( $this,'add_admin_head_option' ) );
			add_action( 'admin_print_styles-' . $this->option_page, array( $this, 'icon_style' ) );
			add_action( 'admin_print_styles-' . $this->option_page, array( $this, 'add_admin_tabs' ) );
			add_action( 'load-' . $this->option_page, array( &$this, 'create_help_screen' ) );

			$this->about_page = add_submenu_page(
													$this->menu_base,
													__( 'About &gt; CYAN Backup', 'cyan-backup' ),
													__( 'About', 'cyan-backup' ),
													self::ACCESS_LEVEL,
													$this->menu_base . '-about',
													array( $this, 'about_page' )
												);

			add_action( 'admin_print_scripts-' . $this->about_page, array( $this, 'add_admin_scripts' ) );
			add_action( 'admin_head-' . $this->about_page, array( $this, 'add_admin_head' ) );
			add_action( 'admin_head-' . $this->about_page, array( $this, 'add_admin_head_option' ) );
			add_action( 'admin_print_styles-' . $this->about_page, array( $this, 'icon_style' ) );
		}

		public function create_help_screen() {
			include_once( $this->include_path . '/pages/help-options.php' );
		}

		//**************************************************************************************
		// About page
		//**************************************************************************************
		public function about_page() {
			include_once( $this->include_path . '/pages/page-about.php' );
		}

		//**************************************************************************************
		// Backup page
		//**************************************************************************************
		public function site_backup() {
			include_once( $this->include_path . '/pages/page-backups.php' );
		}

		//**************************************************************************************
		// Clears the backup state if it's been running for more than 12 hours.
		//**************************************************************************************
		public function verify_status_file() {
			if( file_exists( $this->archive_path . 'backup.active' ) ) {
				$state = filemtime( $this->archive_path . 'backup.active' );

				// Check to see if the state file is more than 12 hours stale.
				if( time() - $state > 43200 ) {
					@unlink( $this->archive_path . 'backup.active' );
					@unlink( $this->archive_path . 'status.log' );
				}
			}
		}

		private function get_real_post_data() {
			// Processing of windows style paths is broken if magic quotes is enabled in php.ini but not enabled during runtime.
			if( get_magic_quotes_gpc() != get_magic_quotes_runtime() ) {

				// So we have to get the RAW post data and do the right thing.
				$raw_post_data = file_get_contents( 'php://input' );
				$post_split = array();
				$postdata = array();

				$post_split = explode( '&', $raw_post_data );

				foreach( $post_split as $entry ) {

					$entry_split = explode( '=', $entry, 2 );
					if( get_magic_quotes_runtime() == FALSE ) {
						$postdata[urldecode( $entry_split[0] )] = urldecode( $entry_split[1] );
					} else {
						$postdata[urldecode( stripslashes( $entry_split[0] ) )] = urldecode( stripslashes( $entry_split[1] ) );
					}
				}

				return $postdata;
			} else {
				return $_POST;
			}
		}

		private function get_real_get_data() {
			// Processing of windows style paths is broken if magic quotes is enabled in php.ini but not enabled during runtime.
			if( get_magic_quotes_gpc() != get_magic_quotes_runtime() ) {

				// So we have to get the RAW post data and do the right thing.
				$raw_get_data = $_SERVER['REQUEST_URI'];
				$get_split = array();
				$getdata = array();

				$get_split = explode( '&', $raw_get_data );

				foreach( $get_split as $entry ) {

					$entry_split = explode( '=', $entry, 2 );
					if( get_magic_quotes_runtime() == FALSE ) {
						$getdata[urldecode( $entry_split[0] )] = urldecode( $entry_split[1] );
					} else {
						$getdata[urldecode( stripslashes( $entry_split[0] ) )] = urldecode( stripslashes( $entry_split[1] ) );
					}
				}

				return $getdata;
			} else {
				return $_GET;
			}
		}

		//**************************************************************************************
		// Determine when the first backup should happen based on the schedule
		//**************************************************************************************
		private function split_date_string( $datestring ) {
			$hours = '';
			$minutes = '';
			$long = '';
			$ampm = 'am';
			
			// First, split the string at the colon.
			list( $hours, $minutes) = explode( ':', trim( $datestring ) );

			// If there minutes is blank then there was no colon, otherwise we have a valid hour/minutes setting.
			if( $minutes != '' )
				{
				// Strip out the am if we have one.
				if( stristr( $minutes, 'am' ) ) {
					$minutes = str_ireplace( 'am', '', $minutes );

					if( $hours == 12 ) {
						$hours = 0;
					}
				}

				// Strip out the pm if we have one and set the hours forward to represent a 24 hour clock.
				if( stristr( $minutes, 'pm' ) ) {
					$minutes = str_ireplace( 'pm', '', $minutes );

					if( $hours < 12 ) {
						$hours += 12;
					}
				}
			} else {
				// If there was no colon, then assume whatever value we have is minutes.
				$minutes = $hours;
				$hours = '';
			}

			if( $hours > 11 ) {
				$ampm = 'pm';
			}

			$long = $hours;
			if( $long > 12 ) {
				$long -= 12;
			}

			if( $long == 0 ) {
				$long = 12;
			}

			return array( $hours, $minutes, $long, $ampm );
		}

		//**************************************************************************************
		// Determine when the first backup should happen based on the schedule
		//**************************************************************************************
		private function calculate_initial_backup( $schedule ) {
			if( !is_array( $schedule ) )
				{
				$schedule = $this->options['schedule'];
				}

			// Get the current date/time and split it up for reference later on.
			$now = getdate( time() );

			// TOD is stored as a single string, we need to split it for use later on.
			$hours = '';
			$minutes = '';
			$long = '';
			$ampm = '';

			if( $schedule['tod'] != '' )
				{
				list( $hours, $minutes, $long, $ampm ) = $this->split_date_string( $schedule['tod'] );
				}

			// Now that we've processed the hours/minutes, lets make sure they aren't blank.  If they are, set them to the current time.
			if( $hours == '' ) {
				$hours = $now['hours'];
			}

			if( $minutes == '' ) {
				$minutes = $now['minutes'];
			}

			// We have to do some work with day names and need to be able to translate them to numbers, setup an array for later to do this.
			$weekdays = array( 'Sunday' => 0, 'Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3, 'Thursday' => 4, 'Friday' => 5, 'Saturday' => 6 );

			if( $schedule['type'] == 'Once' )
				{
				// DOW takes precedence over DOM.
				if( $schedule['dow'] != '' )
					{
					// Convert the scheduled DOW to a number.
					$schedule_dow = $weekdays[$schedule['dow']];

					// Determine if we've passed the scheduled DOW yet this week.
					$next_dow = $schedule_dow - $now['wday'];

					// If we have, we need to add a week.
					if( $next_dow < 0 ) { $next_dow += 7; }

					// If we're on the DOW we're scheduled to run, check to see if we've passed the scheduled time, if so, sit it to next week.
					if( $next_dow == 0 && $now['hours'] > $hours) {
						$next_dow += 7;
					}

					if( $next_dow == 0 && $now['hours'] == $hours && $now['minutes'] > $minutes ) {
						$next_dow += 7;
					}

					$now['mday'] += $next_dow;

					$result = mktime( $hours, $minutes, 0, $now['mon'], $now['mday'] );
					}
				else if( $schedule['dom'] != '' )
					{
					// Determine if we've passed the scheduled DOM yet this month.  If so, set it to next month.
					if( $schedule['dom'] > $now['mday'] ) {
						$now['mon'] ++;
					}

					// If we're on the DOM we're scheduled to run, check to see if we've passed the scheduled time, if so, sit it to next month.
					if( $schedule['dom'] == $now['mday'] && $now['hours'] > $hours ) { $now['mon']++; }
					if( $schedule['dom'] == $now['mday'] && $now['hours'] == $hours && $now['minutes'] > $minutes ) { $now['mon']++; }

					$result = mktime( $hours, $minutes, 0, $now['mon'], $schedule['dom'] );
					}
				}
			else if( $schedule['type'] == 'Hourly' )
				{
				// If we've passed the current time to run it, schedule it for next hour.
				if( $now['minutes'] > $minutes ) {
					$now['hours']++;
				}

				$result = mktime( $now['hours'], $minutes );
				}
			else if( $schedule['type'] == 'Daily' )
				{
				// If we've already passed the TOD to run it at, add another day to it.
				if( $now['hours'] > $hours ) {
					$now['mday']++;
				}

				if( $now['hours'] == $hours && $now['minutes'] > $minutes ) {
					$now['mday']++;
				}

				$result = mktime( $hours, $minutes, 0, $now['mon'], $now['mday'] );
				}
			else if( $schedule['type'] == 'Weekly' )
				{
				// If we have a schedule DOW use it, otherwise use today.
				if( $schedule['dow'] != '' ) {
					$schedule_dow = $weekdays[$schedule['dow']];
				} else {
					$schedule_dow = $now['wday'];
				}

				// If we've already passed the TOD to run it at, add another week to it.
				if( $now['wday'] == $schedule_dow && $now['hours'] > $hours ) {
					$now['mday'] += 7;
				}

				if( $now['wday'] == $schedule_dow && $now['hours'] == $hours && $now['minutes'] > $minutes ) {
					$now['mday'] += 7;
				}

				// If we've passed the day this week to run it, add the required number of days to catch it the next week.
				if( $now['wday'] >  $schedule_dow ) {
					$now['mday'] += 7 - ( $now['wday'] - $schedule_dow );
				}

				// If we haven't passed the day this week to run it, add the required number of days to set it.
				if( $now['wday'] <  $schedule_dow ) {
					$now['mday'] += ( $schedule_dow - $now['wday'] );
				}

				$result = mktime( $hours, $minutes, 0, $now['mon'], $now['mday'] );
				}
			else if( $schedule['type'] == 'Monthly' )
				{
				// If we have a schedule DOm use it, otherwise use today.
				if( $schedule['dom'] == '' ) {
					$schedule['dom'] = $now['mday'];
				}

				// If we've already passed the TOD to run it at, add another week to it.
				if( $now['mday'] == $schedule['dom'] && $now['hours'] > $hours ) {
					$now['mon'] += 1;
				}

				if( $now['mday'] == $schedule['dom'] && $now['hours'] == $hours && $now['minutes'] > $minutes ) {
					$now['mon'] += 1;
				}

				// If we've already passed the DOM this month, set it to next month.
				if( $now['mday'] > $schedule['dom'] ) {
					$now['mon'] += 1;
				}

				$result = mktime( $hours, $minutes, 0, $now['mon'], $schedule['dom'] );
				}
			else if( $schedule['type'] == 'debug' )
				{
				// The debug schedule is every minute, so just set it to now + 1.
				$result = mktime( $now['hours'], $now['minutes'] + 1 );
				}
			else
				{
				// On an unknown type, return FALSE.
				$result = FALSE;
				}

			return $result;
		}

		//**************************************************************************************
		// Determine when the next backup should happen based on the schedule
		//**************************************************************************************
		private function calculate_next_backup( $options ) {
			if( !is_array( $options ) )
				{
				$options = $this->options;
				}

			$schedule = $options['schedule'];
			$last_schedule = $options['next_backup_time'];

			// Get the last schedule we set to use as a baseline, then we can just add the appropriate interval to it.
			$now = time();
			$last = getdate( $last_schedule );

			if( $schedule['type'] == 'Hourly' )
				{
				$result = mktime( $last['hours'] + $schedule['interval'], $last['minutes'], 0, $last['mon'], $last['mday'], $last['year'] );
				}
			else if( $schedule['type'] == 'Daily' )
				{
				$result = mktime( $last['hours'], $last['minutes'], 0, $last['mon'], $last['mday'] + $schedule['interval'], $last['year'] );
				}
			else if( $schedule['type'] == 'Weekly' )
				{
				$result = mktime( $last['hours'], $last['minutes'], 0, $last['mon'], $last['mday'] + ( $schedule['interval'] * 7 ), $last['year'] );
				}
			else if( $schedule['type'] == 'Monthly' )
				{
				$result = mktime( $last['hours'], $last['minutes'], 0, $last['mon'] + $schedule['interval'], $last['mday'], $last['year'] );
				}
			else if( $schedule['type'] == 'debug' )
				{
				$result = mktime( $last['hours'], $last['minutes'] + 1, 0, $last['mon'], $last['mday'], $last['year'] );
				}
			else
				{
				$result = FALSE;
				}

			// If we've calculated a result but it's in the past, get the next possible schedule, which happens to be the same as the initial schedule.
			if( $result !== FALSE && $result < $now ) {
				$result = calculate_initial_backup( $schedule );
			}

			return $result;
		}

		public function schedule_next_backup( $schedule ) {
			if( $this->options['schedule']['enabled'] && $this->options['schedule']['type'] != 'Once' ) {
				$next_backup_time = $this->calculate_next_backup( $this->options );

				wp_schedule_single_event( $next_backup_time, 'cyan_backup_hook' );

				$this->options['next_backup_time'] = $next_backup_time;
				update_option( $this->option_name, $this->options );
			}
		}

		//**************************************************************************************
		// Option Page
		//**************************************************************************************
		public function option_page() {
			include_once( $this->include_path . '/pages/page-options.php' );
		}

		//**************************************************************************************
		// prune the number of existing backup files in the archive directory
		//**************************************************************************************
		public function prune_backups( $number ) {
			$backup_files = $this->backup_files_info( $this->get_backup_files() );

			$rb = $this->remote_backuper();

			$ext = $rb->GetArchiveExtension();

			if( count( $backup_files ) > $number && $number > 1) {
				$i = 1;
				$j = 0;
				foreach( $backup_files as $backup_file ) {
					if( $i > $number ) {
						$file = realpath( $backup_file['filename'] );

						if( $file !== FALSE) {
							$logfile = str_ireplace( $ext, '.log', $file );
							@unlink( $file );
							@unlink( $logfile );
							$j++;
						}
					}
					$i++;
				}

			return $j;
			}

		}

		//**************************************************************************************
		// file download
		//**************************************************************************************
		public function file_download() {
			if ( !is_admin() || !is_user_logged_in() ) {
				return;
			}

			if( isset( $_GET['page'] ) && isset( $_GET['download'] ) ) {
				if ( $_GET['page'] !== $this->menu_base ) {
					return;
				}

				check_admin_referer('backup', self::NONCE_NAME);

				$getdata = $this->get_real_get_data();
				$file = realpath($getdata['download']);

				if( $file !== FALSE) {
					if( strtolower( substr( $file, -4 ) ) == ".log" ) {
						header("Content-Type: text/plain;");
					} else {
						header("Content-Type: application/octet-stream;");
					}

					header("Content-Disposition: attachment; filename=".urlencode(basename($file)));

					// The following code is in place as, while readfile() doesn't use memory to read the contents, if output buffering
					// is enabled it will buffer our output of the file, which can cause an out of memory condition for large backups.

					// Default buffer is 2meg, max is 20meg.
					$buffer_size = 2048000;
					$max_buffer_size = 20480000;

					$php_limit = ini_get( 'memory_limit' );

					// The ini file might have some text like KB to indicate the size so replace it with some zeros now.
					$php_limit = str_ireplace( 'KB', '000', $php_limit );
					$php_limit = str_ireplace( 'MB', '000000', $php_limit );
					$php_limit = str_ireplace( 'GB', '000000000', $php_limit );
					$php_limit = str_ireplace( 'K', '000', $php_limit );
					$php_limit = str_ireplace( 'M', '000000', $php_limit );
					$php_limit = str_ireplace( 'G', '000000000', $php_limit );

					// Let's make sure the number is a real integer.
					$php_limit = intval( $php_limit );

					// Let's get the current memory usage
					$current_usage = memory_get_usage( true );
					$remaining = $php_limit - $current_usage;

					$filesize = filesize( $file );

					// If the file size is less than the remaining memory (plus a 20% buffer ), then we can use readfile()
					if( ( $filesize * 1.2 ) < $remaining ) {
						readfile( $file );
					}
					else {
						// If the remaining memory is greater than the current buffer size, change the buffer size.
						if( $remaining > $buffer_size ) {
							// if the remaining memory is greater than the max buffer size, use the max buffer size.
							if( $remaining > $max_buffer_size ) {
								$buffer_size = $max_buffer_size;
							}
							else {
								// Only use 80% of the remaining memory to ensure we don't fault out.
								$buffer_size = $remaining * .8;
							}
						}

						// Now divide the buffer size by 2 as we're going to have 2 copies of the data in memory at any
						// givin time (one from the fread, one in the output buffer.
						$buffer_size = $buffer_size / 2;

						// Open the file for reading.
						$fh = fopen( $file, 'rb' );

						// Loop through.
						while( !feof( $fh ) && $fh !== false ) {
							// Read a chunk of the file in to memory.
							$buffer = fread( $fh, $buffer_size );

							// Output the contents of the chunk.
							print( $buffer );

							// Make sure we free the temporary buffer.
							unset( $buffer );

							// Make sure we've flushed the output buffer.
							ob_flush();
							flush();
						}

						// Close the file.
						fclose( $fh );
					}
				} else {
					header("HTTP/1.1 404 Not Found");
					wp_die(__('File not Found: ' . $getdata['download'], 'cyan-backup'));
				}
				exit;
			}
		}
		
		//**************************************************************************************
		// Define the different Archive types.
		//**************************************************************************************
		public function get_archive_methods() {
			$archive_methods = array( 	
										'PHPArchiveZip' 		=> __( 'zip (PHP-Archive)', 'cyan-backup' ),
									);

			return $archive_methods;
		}
	}

}