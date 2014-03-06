<?php
/*
Plugin Name: CYAN Backup
Version: 1.6.2
Plugin URI: http://toolstack.com/cyan-backup
Description: Backup your entire WordPress site and its database into a zip file on a schedule.
Author: Greg Ross
Author URI: http://toolstack.com/
Text Domain: cyan-backup
Domain Path: /languages/

Read the accompanying readme.txt file for instructions and documentation.

	Original Total Backup code Copyright 2011-2012 wokamoto (wokamoto1973@gmail.com)
	All additional code Copyright 2014 Greg Ross (greg@toolstack.com)

This software is released under the GPL v2.0, see license.txt for details

*/
if (!class_exists('CYANBackup')) {

function cyan_backup_scheduled_run() {
	global $cyan_backup;

	$cyan_backup->scheduled_backup();
}

add_action('cyan_backup_hook', 'cyan_backup_scheduled_run');

class CYANBackup {
	public  $plugin_name = 'CYAN Backup';
	public  $textdomain  = 'cyan-backup';

	private $plugin_basename, $plugin_dir, $plugin_file, $plugin_url;
	private $menu_base;
	private $option_name;
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

	const   ACCESS_LEVEL = 'manage_options';
	const   NONCE_NAME   = '_wpnonce_CYAN_Backup';
	const   TIME_LIMIT   = 900;			// 15min * 60sec
	const	DEBUG_MODE   = FALSE;
	const	VERSION      = "1.6.2";

	function __construct() {
		global $wpdb;

		$this->set_plugin_dir(__FILE__);
		$this->option_name = $this->plugin_name . ' Option';
		$this->load_textdomain($this->plugin_dir, 'languages', $this->textdomain);

		// add rewrite rules
		if (!class_exists('WP_AddRewriteRules'))
		        require_once 'includes/class-addrewriterules.php';
		new WP_AddRewriteRules('json/([^/]+)/?', 'json=$matches[1]', array(&$this, 'json_request'));

		if (is_admin()) {
			// add admin menu
			$this->menu_base = basename($this->plugin_file, '.php');
			if (function_exists('is_multisite') && is_multisite()) {
				$this->admin_action = $this->wp_admin_url('network/admin.php?page=' . $this->menu_base);
				add_action('network_admin_menu', array(&$this, 'admin_menu'));
			} else {
				$this->admin_action = $this->wp_admin_url('admin.php?page=' . $this->menu_base);
				add_action('admin_menu', array(&$this, 'admin_menu'));
				add_filter('plugin_action_links', array(&$this, 'plugin_setting_links'), 10, 2 );
			}
			add_action('init', array(&$this, 'file_download'));
		}

		$options = get_option( $this->option_name );
		
		// Run the upgrade code if required
		if( $options['version'] != self::VERSION ) 
			{
			$options['version'] = self::VERSION;
			$options['next_backup_time'] = wp_next_scheduled('cyan_backup_hook');

			if( ! isset( $options['schedule']['ampm'] ) ) {
				list( $hours, $options['schedule']['minutes'], $options['schedule']['hours'], $options['schedule']['ampm'] ) = $this->split_date_string( $schedule['tod'] );
			}
			
			update_option( $this->option_name, $options );
			}
		
		// activation & deactivation
		if (function_exists('register_activation_hook'))
			register_activation_hook(__FILE__, array(&$this, 'activation'));
		if (function_exists('register_deactivation_hook'))
			register_deactivation_hook(__FILE__, array(&$this, 'deactivation'));
	}

	function __destruct() {
		$this->close_debug_log();
	}
	
	//**************************************************************************************
	// Plugin activation
	//**************************************************************************************
	public function activation() {
		flush_rewrite_rules();
	}

	//**************************************************************************************
	// Plugin deactivation
	//**************************************************************************************
	public function deactivation() {
		flush_rewrite_rules();
	}

	//**************************************************************************************
	// Utility
	//**************************************************************************************

	private function chg_directory_separator( $content, $url = TRUE ) {
		if ( DIRECTORY_SEPARATOR !== '/' ) {
			if ( $url === FALSE ) {
				if (!is_array($content)) {
					$content = str_replace('/', DIRECTORY_SEPARATOR, $content);
				} else foreach( $content as $key => $val ) {
					$content[$key] = $this->chg_directory_separator($val, $url);
				}
			} else {
				if (!is_array($content)) {
					$content = str_replace(DIRECTORY_SEPARATOR, '/', $content);
				} else foreach( $content as $key => $val ) {
					$content[$key] = $this->chg_directory_separator($val, $url);
				}
			}
		}
		return $content;
	}

	private function trailingslashit( $content, $url = TRUE ) {
		return $this->chg_directory_separator(trailingslashit($content), $url);
	}

	private function untrailingslashit( $content, $url = TRUE ) {
		return $this->chg_directory_separator(untrailingslashit($content), $url);
	}

	// set plugin dir
	private function set_plugin_dir( $file = '' ) {
		$file_path = ( !empty($file) ? $file : __FILE__);
		$filename = explode("/", $file_path);
		if (count($filename) <= 1)
			$filename = explode("\\", $file_path);
		$this->plugin_basename = plugin_basename($file_path);
		$this->plugin_dir  = $filename[count($filename) - 2];
		$this->plugin_file = $filename[count($filename) - 1];
		$this->plugin_url  = $this->wp_plugin_url($this->plugin_dir);
		unset($filename);
	}

	// load textdomain
	private function load_textdomain( $plugin_dir, $sub_dir = 'languages', $textdomain_name = FALSE ) {
		$textdomain_name = $textdomain_name !== FALSE ? $textdomain_name : $plugin_dir;
		$plugins_dir = $this->trailingslashit( defined('PLUGINDIR') ? PLUGINDIR : 'wp-content/plugins', FALSE );
		$abs_plugin_dir = $this->wp_plugin_dir($plugin_dir);
		$sub_dir = (
			!empty($sub_dir)
			? preg_replace('/^\//', '', $sub_dir)
			: (file_exists($abs_plugin_dir.'languages') ? 'languages' : (file_exists($abs_plugin_dir.'language') ? 'language' : (file_exists($abs_plugin_dir.'lang') ? 'lang' : '')))
			);
		$textdomain_dir = $this->trailingslashit(trailingslashit($plugin_dir) . $sub_dir, FALSE);

		if ( $this->wp_version_check("2.6") && defined('WP_PLUGIN_DIR') )
			load_plugin_textdomain($textdomain_name, false, $textdomain_dir);
		else
			load_plugin_textdomain($textdomain_name, $plugins_dir . $textdomain_dir);

		return $textdomain_name;
	}

	// check wp version
	private function wp_version_check($version, $operator = ">=") {
		global $wp_version;
		return version_compare($wp_version, $version, $operator);
	}

	// WP_SITE_URL
	private function wp_site_url($path = '') {
		$siteurl = trailingslashit(function_exists('site_url') ? site_url() : get_bloginfo('wpurl'));
		return $siteurl . $path;
	}

	// admin url
	private function wp_admin_url($path = '') {
		$adminurl = '';
		if ( defined( 'WP_SITEURL' ) && '' != WP_SITEURL )
			$adminurl = WP_SITEURL . '/wp-admin/';
		elseif ( function_exists('site_url') && '' != site_url() )
			$adminurl = site_url('/wp-admin/');
		elseif ( function_exists( 'get_bloginfo' ) && '' != get_bloginfo( 'wpurl' ) )
			$adminurl = get_bloginfo( 'wpurl' ) . '/wp-admin/';
		elseif ( strpos( $_SERVER['PHP_SELF'], 'wp-admin' ) !== false )
			$adminurl = '';
		else
			$adminurl = 'wp-admin/';
		return trailingslashit($adminurl) . $path;
	}

	// WP_CONTENT_DIR
	private function wp_content_dir($path = '') {
		return $this->trailingslashit( trailingslashit( defined('WP_CONTENT_DIR')
			? WP_CONTENT_DIR
			: trailingslashit(ABSPATH) . 'wp-content'
			) . preg_replace('/^\//', '', $path), FALSE );
	}

	// WP_CONTENT_URL
	private function wp_content_url($path = '') {
		return trailingslashit( trailingslashit( defined('WP_CONTENT_URL')
			? WP_CONTENT_URL
			: trailingslashit(get_option('siteurl')) . 'wp-content'
			) . preg_replace('/^\//', '', $path) );
	}

	// WP_PLUGIN_DIR
	private function wp_plugin_dir($path = '') {
		return $this->trailingslashit($this->wp_content_dir( 'plugins/' . preg_replace('/^\//', '', $path) ), FALSE);
	}

	// WP_PLUGIN_URL
	private function wp_plugin_url($path = '') {
		return trailingslashit($this->wp_content_url( 'plugins/' . preg_replace('/^\//', '', $path) ));
	}

	// Sanitize string or array of strings for database.
	private function escape(&$array) {
		global $wpdb;

		if (!is_array($array)) {
			return($wpdb->escape($array));
		} else {
			foreach ( (array) $array as $k => $v ) {
				if ( is_array($v) ) {
					$this->escape($array[$k]);
				} else if ( is_object($v) ) {
					//skip
				} else {
					$array[$k] = $wpdb->escape($v);
				}
			}
		}
	}

	// get current user ID & Name
	private function get_current_user() {
		static $username = NULL;
		static $userid   = NULL;

		if ( $username && $userid )
			return array($userid, $username);

		if ( is_user_logged_in() ) {
			global $current_user;
			get_currentuserinfo();
			$username = $current_user->display_name;
			$userid   = $current_user->ID;
		}
		return array($userid, $username);
	}

	// json decode
	private function json_decode( $string, $assoc = FALSE ) {
		if ( function_exists('json_decode') ) {
			return json_decode( $string, $assoc );
		} else {
			// For PHP < 5.2.0
			if ( !class_exists('Services_JSON') ) {
				require_once( 'includes/class-json.php' );
			}
			$json = new Services_JSON();
			return $json->decode( $string, $assoc );
		}
	}

	// json encode
	private function json_encode( $content ) {
		if ( function_exists('json_encode') ) {
			return json_encode($content);
		} else {
			// For PHP < 5.2.0
			if ( !class_exists('Services_JSON') ) {
				require_once( 'includes/class-json.php' );
			}
			$json = new Services_JSON();
			return $json->encode($content);
		}
	}

	// get date and gmt
	private function get_date_and_gmt($aa = NULL, $mm = NULL, $jj = NULL, $hh = NULL, $mn = NULL, $ss = NULL) {
		$tz = date_default_timezone_get();
		if ($tz !== 'UTC')
			date_default_timezone_set('UTC');
		$time = time() + (int)get_option('gmt_offset') * 3600;
		if ($tz !== 'UTC')
			date_default_timezone_set( $tz );

		$aa = (int)(!isset($aa) ? date('Y', $time) : $aa);
		$mm = (int)(!isset($mm) ? date('n', $time) : $mm);
		$jj = (int)(!isset($jj) ? date('j', $time) : $jj);
		$hh = (int)(!isset($hh) ? date('G', $time) : $hh);
		$mn = (int)(!isset($mn) ? date('i', $time) : $mn);
		$ss = (int)(!isset($ss) ? date('s', $time) : $ss);

		$aa = ($aa <= 0 ) ? date('Y', $time) : $aa;
		$mm = ($mm <= 0 ) ? date('n', $time) : $mm;
		$jj = ($jj > 31 ) ? 31 : $jj;
		$jj = ($jj <= 0 ) ? date('j', $time) : $jj;
		$hh = ($hh > 23 ) ? $hh -24 : $hh;
		$mn = ($mn > 59 ) ? $mn -60 : $mn;
		$ss = ($ss > 59 ) ? $ss -60 : $ss;
		$date = sprintf( "%04d-%02d-%02d %02d:%02d:%02d", $aa, $mm, $jj, $hh, $mn, $ss );
		$date_gmt = get_gmt_from_date( $date );

		return array('date' => $date, 'date_gmt' => $date_gmt);
	}

	// sys get temp dir
	private function sys_get_temp_dir() {
		$temp_dir = NULL;
		if (function_exists('sys_get_temp_dir')) {
			$temp_dir = sys_get_temp_dir();
		} elseif (isset($_ENV['TMP']) && !empty($_ENV['TMP'])) {
			$temp_dir = realpath($_ENV['TMP']);
		} elseif (isset($_ENV['TMPDIR']) && !empty($_ENV['TMPDIR'])) {
			$temp_dir = realpath($_ENV['TMPDIR']);
		} elseif (isset($_ENV['TEMP']) && !empty($_ENV['TEMP']))  {
			$temp_dir = realpath($_ENV['TEMP']);
		} else {
			$temp_file = tempnam(__FILE__,'');
			if (file_exists($temp_file)) {
				unlink($temp_file);
				$temp_dir = realpath(dirname($temp_file));
			}
		}
		return $this->chg_directory_separator($temp_dir, FALSE);
	}

	// get nonces
	private function get_nonces($nonce_field = 'backup') {
		$nonces = array();
		if ($this->wp_version_check('2.5') && function_exists('wp_nonce_field') ) {
			$nonce = wp_nonce_field($nonce_field, self::NONCE_NAME, true, false);
			$pattern = '/<input [^>]*name=["]([^"]*)["][^>]*value=["]([^"]*)["][^>]*>/i';
			if (preg_match_all($pattern,$nonce,$matches,PREG_SET_ORDER)) {
			    foreach($matches as $match) {
					$nonces[$match[1]] = $match[2];
				}
			}
		}
		return $nonces;
	}

	// get permalink type
	private function get_permalink_type() {
		$permalink_structure = get_option('permalink_structure');
		$permalink_type = 'Ugly';
		if (empty($permalink_structure) || !$permalink_structure) {
			$permalink_type = 'Ugly';
		} else if (preg_match('/^\/index\.php/i', $permalink_structure)) {
			$permalink_type = 'Almost Pretty';
		} else {
			$permalink_type = 'Pretty';
		}
		return $permalink_type;
	}

	// get request var
	private function get_request_var($key, $defualt = NULL) {
		return isset($_POST[$key]) ? $_POST[$key] : (isset($_GET[$key]) ? $_GET[$key] : $defualt);
	}

	// get archive path
	private function get_archive_path($option = '') {
		if (empty($option) || !is_array($option))
			$option = (array)get_option($this->option_name);
		$archive_path = 
			(isset($option["archive_path"]) && is_dir($option["archive_path"]))
			? $option["archive_path"]
			: $this->sys_get_temp_dir() ;
		if ( is_dir($archive_path) && is_writable($archive_path) )
			return $archive_path;
		else
			return FALSE;
	}

	// get archive prefix
	private function get_archive_prefix($option = '') {
		return basename(ABSPATH) . '.';
	}

	// get excluded dir
	private function get_excluded_dir($option = '', $special = FALSE) {
		if (empty($option) || !is_array($option))
			$option = (array)get_option($this->option_name);
		if (!class_exists('WP_Backuper'))
			require_once 'includes/class-wp-backuper.php';

		$excluded =	(
			$special === FALSE
			? array(
				'./' ,
				'../' ,
				WP_Backuper::MAINTENANCE_MODE ,
				)
			: (array) $special
			);
		$excluded = $this->chg_directory_separator(
			(isset($option["excluded"]) && is_array($option["excluded"]))
			? array_merge($excluded, $option["excluded"])
			: array_merge($excluded, $this->default_excluded) ,
			FALSE);
		return $excluded;
	}

	// remote backuper
	private function remote_backuper($option = NULL) {
		static $remote_backuper;
		if (isset($remote_backuper))
			return $remote_backuper;

		if (!class_exists('WP_Backuper'))
			require_once 'includes/class-wp-backuper.php';

		if (!$option)
			$option = (array)get_option($this->option_name);
		$remote_backuper = new WP_Backuper(
			$this->get_archive_path($option) ,
			$this->get_archive_prefix($option) ,
			$this->trailingslashit(ABSPATH, FALSE) ,
			$this->get_excluded_dir($option)
			);
		return $remote_backuper;
	}

	// get filemtime
	private function get_filemtime($file_name) {
		$filemtime = filemtime($file_name); //  + (int)get_option('gmt_offset') * 3600;
		$date_gmt  = $this->get_date_and_gmt(
			(int)date('Y', $filemtime),
			(int)date('n', $filemtime),
			(int)date('j', $filemtime),
			(int)date('G', $filemtime),
			(int)date('i', $filemtime),
			(int)date('s', $filemtime)
			);
		$filemtime =
			isset($date_gmt['date'])
			? $date_gmt['date']
			: date("Y-m-d H:i:s.", $filemtime)
			;
		return $filemtime;
	}

	// get backup files
	private function get_backup_files() {
		$remote_backuper = $this->remote_backuper();
		return $remote_backuper->get_backup_files();
	}

	// backup files info
	private function backup_files_info($backup_files = NULL) {
		$nonces = '';
		foreach ($this->get_nonces('backup') as $key => $val) {
			$nonces .= '&' . $key . '=' . rawurlencode($val);
		}
		$remote_backuper = $this->remote_backuper();
		return $remote_backuper->backup_files_info($nonces, $this->menu_base);
	}

	// write a line to the log file
	private function write_debug_log( $text ) {
		if( $this->debug_log == null ) {
			$this->debug_log = fopen($this->get_archive_path() . 'debug.txt', 'a');
		}
		
		fwrite($this->debug_log, '[' . date("Y-m-d H:i:s") . '] ' . $text . "\n");
	}

	private function close_debug_log() {
		if( $this->debug_log != null ) {
			fclose( $this->debug_log );
		$this->debug_log = null;
		}
	}
	
	//**************************************************************************************
	// json request
	//**************************************************************************************
	public function json_request() {
		if (!is_user_logged_in()) {
			header("HTTP/1.0 401 Unauthorized");
			wp_die(__('not logged in!', $this->textdomain));
		}

		if ( !ini_get('safe_mode') )
			set_time_limit(self::TIME_LIMIT);

		$method_name = get_query_var('json');
		if ($this->wp_version_check('2.5') && function_exists('check_admin_referer'))
			check_admin_referer($method_name, self::NONCE_NAME);

		list($userid, $username) = $this->get_current_user();
		$userid = (int)$userid;
		$charset = get_bloginfo('charset');
		$content_type = 'application/json';	// $content_type = 'text/plain';
		$result = FALSE;

		switch ($method_name) {
			case 'backup':
				$result = $this->json_backup($userid);
				break;
			case 'status':
				$status = @file_get_contents( $this->get_archive_path() . 'status.log' );
				
				if( $status === FALSE ) 
					{ 
					$result = array( 
						'result' => FALSE,
						'method' => $method_name,
						'message' => __('No backup running!', $this->textdomain),
						);
					}
				else
					{
					list( $result['percentage'], $result['message'], $result['state'], $result['backup_file'], $result['backup_date'], $result['backup_size'] ) = explode( "\n", $status );
					$result['percentage'] = trim( $result['percentage'] );
					$result['message'] = trim( $result['message'] );
					$result['state'] = trim( $result['state'] );
					$result['backup_file'] = trim( $result['backup_file'] );
					$result['backup_date'] = trim( $result['backup_date'] );
					$result['backup_size'] = trim( $result['backup_size'] );

					$temp_time = strtotime($result['backup_date']);
					$result['backup_date'] = date( get_option('date_format'), $temp_time ) . ' @ ' . date( get_option('time_format'), $temp_time );

					$result['backup_size'] = number_format((float)$result['backup_size'], 2) . ' MB';
					}
					
				break;
			default:
				$result = array(
					'result' => FALSE,
					'method' => $method_name,
					'message' => __('Method not found!', $this->textdomain),
					);
				break;
		}

		header("Content-Type: {$content_type}; charset={$charset}" );
		echo $this->json_encode(
			$result
			? array_merge(array('result' => TRUE, 'method' => $method_name), (array)$result)
			: array_merge(array('result' => FALSE, 'method' => $method_name), (array)$result)
			);
		exit;
	}

	//**************************************************************************************
	// Site backup
	//**************************************************************************************
	private function json_backup($userid_org) {
		$userid = (int)($this->get_request_var('userid', -1));
		if ($userid !== $userid_org)
			return array('userid' => $userid, 'result' => FALSE, 'message' => 'UnKnown UserID!');

		$remote_backuper = $this->remote_backuper();
		$result = $remote_backuper->wp_backup();
		$backup_file = isset($result['backup']) ? $result['backup'] : FALSE;
		if ($backup_file && file_exists($backup_file)) {
			$options = (array)get_option( $this->option_name );

			$filesize = (int)sprintf('%u', filesize($backup_file)) / 1024 / 1024;
			$temp_time = strtotime($this->get_filemtime($backup_file));
			$filedate = date( get_option('date_format'), $temp_time ) . ' @ ' . date( get_option('time_format'), $temp_time );

			$this->transfer_backups( $backup_file, $options['remote'], 'manual' );

			$this->prune_backups( $options['prune']['number'] );

			return array(
				'backup_file' => $backup_file,
				'backup_date' => $filedate,
				'backup_size' => number_format($filesize, 2) . ' MB',
				'backup_deleted' => $options['remote']['deletelocal'],
				);
		} else {
			return $result;
		}
	}

	public function scheduled_backup() {
		$remote_backuper = $this->remote_backuper();

		// Run the backup.
		$result = $remote_backuper->wp_backup();

		// Get the options.
		$options = (array)get_option($this->option_name);

		// Determine the next backup time.
		$this->schedule_next_backup();
		
		// Send the backup to remote storage.
		$this->transfer_backups( $result['backup'], $options['remote'], 'schedule' );

		// Prune existing backup files as per the options.
		$this->prune_backups( $options['prune']['number'] );
	}

	private function transfer_backups( $archive, $remote_settings, $source ) {
		// We need to create the final remote directory to store the backup in.
		$final_dir = $remote_settings['path'];
		$final_dir = str_replace( '%m', date('m'), $final_dir );
		$final_dir = str_replace( '%d', date('d'), $final_dir );
		$final_dir = str_replace( '%Y', date('Y'), $final_dir );
		$final_dir = str_replace( '%M', date('M'), $final_dir );
		$final_dir = str_replace( '%F', date('F'), $final_dir );
		$final_dir = $this->trailingslashit( $final_dir, FALSE );
		
		// Let's make sure we don't have a funky archive path.
		$archive = realpath($archive);
		
		// Decrypt the password from the settings.
		$final_password = $this->decrypt_password( $remote_settings['password'] );
		
		// Get the basename of the archive for later.
		$filename = basename( $archive );

		// We need to find the log file path and name.
		$log = str_ireplace( '.zip', '.log', $archive );

		// Find the basename of the log file.
		$logname = basename( $log );
		
		// Do the work now.
		switch( $remote_settings['protocol'] )
			{
			case 'ftpwrappers':
				include_once( 'includes/protocol-ftpwrappers.php');
				
				break;
			case 'ftplibrary':
				include_once( 'includes/protocol-ftplibrary.php');
				
				break;
			case 'ftpswrappers':
				include_once( 'includes/protocol-ftpswrappers.php');
				
				break;
			case 'ftpslibrary':
				include_once( 'includes/protocol-ftpslibrary.php');
				
				break;
			case 'sftpwrappers':
				include_once( 'includes/protocol-sftpwrappers.php');
				
				break;
			case 'sftplibrary':
				include_once( 'includes/protocol-sftplibrary.php');
				
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
		$key_size = mcrypt_get_key_size(MCRYPT_RIJNDAEL_128, MCRYPT_MODE_CBC);
	
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
			$iv_size = mcrypt_get_iv_size(MCRYPT_RIJNDAEL_128, MCRYPT_MODE_CBC);
			$iv = mcrypt_create_iv($iv_size, MCRYPT_RAND);

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
			$iv_size = mcrypt_get_iv_size(MCRYPT_RIJNDAEL_128, MCRYPT_MODE_CBC);
			$iv = substr($password, 0, $iv_size);
			
			// Retrieves the cipher text (everything except the $iv_size in the front).
			$password = substr($password, $iv_size);

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
	// Add setting link
	//**************************************************************************************
	public function plugin_setting_links($links, $file) {
		global $wp_version;

		$this_plugin = plugin_basename(__FILE__);
		if ($file == $this_plugin) {
			$settings_link = '<a href="' . $this->admin_action . '-options">' . __('Settings', $this->textdomain) . '</a>';
			array_unshift($links, $settings_link); // before other links
		}

		return $links;
	}

	//**************************************************************************************
	// Add Admin Menu
	//**************************************************************************************
	public function add_admin_scripts() {
		wp_enqueue_script('jquery');
		wp_enqueue_script('jquery-ui-progressbar');

		global $wp_scripts; 
		wp_register_style("jquery-ui-css", plugin_dir_url(__FILE__) . "css/jquery-ui-1.10.4.custom.css");
		wp_enqueue_style("jquery-ui-css");

	}

	public function add_admin_head() {
?>
<style type="text/css" media="all">/* <![CDATA[ */
#backuplist td {
	line-height: 24px;
}
/* ]]> */</style>
<script type="text/javascript">//<![CDATA[
//]]></script>
<?php
	}

	public function add_admin_head_main() {
		list($userid, $username) = $this->get_current_user();
		$userid = (int)$userid;

		$site_url = trailingslashit(function_exists('home_url') ? home_url() : get_option('home'));
		$json_backup_url  = $site_url;
		$json_status_url  = $site_url;
		$json_backup_args = "userid:{$userid},\n";
		$json_status_args = "userid:{$userid},\n";
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
			$json_backup_args .= "json:'backup',\n";
			$json_status_args .= "json:'status',\n";
			$json_method_type = 'GET';
			break;
		}

		$img = '<img src="%1$s" class="%2$s" style="display:inline-block;position:relative;left:.25em;top:.25em;width:16p;height:16px;" />';
		$loading_img = sprintf($img, $this->wp_admin_url('images/wpspin_light.gif'), 'updating');
		$success_img = sprintf($img, $this->plugin_url . 'images/success.png', 'success');
		$failure_img = sprintf($img, $this->plugin_url . 'images/failure.png', 'failure');
		$nonces_1 = $nonces_2 = '';
		foreach ($this->get_nonces('backup') as $key => $val) {
			$nonces_1 .= "'{$key}':'{$val}',\n";
			$nonces_2 .= '&' . $key . '=' . rawurlencode($val);
		}
		$nonces_3 = '';
		foreach ($this->get_nonces('status') as $key => $val) {
			$nonces_3 .= "'{$key}':'{$val}',\n";
		}
		
		$option = (array)get_option($this->option_name);
		$archive_path = $this->get_archive_path($option);

?>
<script type="text/javascript">//<![CDATA[
jQuery(function($){
	function buttons_disabled(disabled) {
		$('input[name="backup_site"]').attr('disabled', disabled);
	}

	function basename(path, suffix) {
		// Returns the filename component of the path
		//
		// version: 910.820
		// discuss at: http://phpjs.org/functions/basename	// +   original by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
		// +   improved by: Ash Searle (http://hexmen.com/blog/)
		// +   improved by: Lincoln Ramsay
		// +   improved by: djmix
		// *	 example 1: basename('/www/site/home.htm', '.htm');	// *	 returns 1: 'home'
		// *	 example 2: basename('ecra.php?p=1');
		// *	 returns 2: 'ecra.php?p=1'
		var b = path.replace(/^.*[\/\\]/g, '');
		if (typeof(suffix) == 'string' && b.substr(b.length-suffix.length) == suffix) {
			b = b.substr(0, b.length-suffix.length);
		}
		return b;
	}

	$('#switch_checkboxes').click(function cyan_backup_toogle_checkboxes() {
		if( jQuery('#switch_checkboxes').attr( 'checked' ) ) {
			jQuery('[id^="removefiles"]').attr('checked', true);
		} else {
			jQuery('[id^="removefiles"]').attr('checked', false);
		}
	});

	$("#progressbar").progressbar();

	var CYANBackupInterval = null;

	CYANBackupActivityCheck();
	
	function CYANBackupActivityCheck() {
		var args = {
<?php echo $json_status_args; ?>
<?php echo $nonces_3; ?>
			};
		
		$.ajax({
			async: true,
			cache: false,
			data: args,
			dataType: 'json',
			success: function(json, status, xhr){
				if( json.state == 'active' ) {
					var wrap = $('#img_wrap');
					wrap.append('<?php echo $loading_img; ?>');
					buttons_disabled(true);

					$("#progressbar").progressbar("enable");
					
					if( CYANBackupInterval == null ) { CYANBackupInterval = setInterval( CYANBackupUpdater, 1000 ); }
					
					$("#progressbar").progressbar( "value", parseInt( json.percentage ) );
					$("#progresstext").html(json.message);
				}
			},
			type: '<?php echo $json_method_type; ?>',
			url: '<?php echo $json_status_url; ?>'
		});
	}
		
	function CYANBackupUpdater() {
		var args = {
<?php echo $json_status_args; ?>
<?php echo $nonces_3; ?>
			};
		
		$.ajax({
			async: true,
			cache: false,
			data: args,
			dataType: 'json',
			success: function(json, status, xhr){
				if( CYANBackupInterval != null ) {
					$("#progressbar").progressbar( "value", parseInt( json.percentage ) );
					$("#progresstext").html(json.message);

					var wrap = $('#img_wrap');

					if( json.state == 'complete' ) {
						var log_name = json.backup_file;
						var log_file = '';
						var backup_file = '<a href="?page=<?php echo $this->menu_base; ?>&download=' + encodeURIComponent(json.backup_file) + '<?php echo $nonces_2; ?>' + '" title="' + basename(json.backup_file) + '">' + basename(json.backup_file) + '</a>';
						var rowCount = $('#backuplist tr').length - 2;
						var tr = '';

						log_name = log_name.replace(".zip",".log");
						
						log_file = ' [<a href="?page=<?php echo $this->menu_base; ?>&download=' + encodeURIComponent(log_name) + '<?php echo $nonces_2; ?>' + '" title="<?php _e('log', $this->textdomain);?>"><?php _e('log', $this->textdomain);?></a>]';
							
						tr = $('<tr><td>' + backup_file + log_file + '</td>' +
							'<td>' + json.backup_date  + '</td>' +
							'<td>' + json.backup_size  + '</td>' +
							'<td style="text-align: center;"><input type="checkbox" name="remove[' + ( rowCount )  + ']" value="<?php echo addslashes($archive_path);?>' + basename(json.backup_file) +'"></td></tr>');

						$('img.success', wrap).remove();
						$('img.failure', wrap).remove();
						$('img.updating', wrap).remove();
						$('div#message').remove();
						$('span#error_message').remove();

						clearInterval( CYANBackupInterval );
						CYANBackupInterval = null;
						
						buttons_disabled(false);

						$("#progressbar").progressbar("disable");

						wrap.append('<?php echo $success_img; ?>');
						$('#backuplist').prepend(tr);
					} else if( json.state == 'error' ) {
						clearInterval( CYANBackupInterval );
						CYANBackupInterval = null;
						
						$('img.success', wrap).remove();
						$('img.failure', wrap).remove();
						$('img.updating', wrap).remove();
						$('div#message').remove();
						$('span#error_message').remove();

						buttons_disabled(false);

						$("#progressbar").progressbar("disable");

						wrap.append('<?php echo $failure_img; ?> <span id="error_message">' + json.errors + '</span>');
					}

				}
			},
			error: function(req, status, err){
				$("#progressbar").progressbar( "value", 0 );
				$("#progresstext").html(req.responseText);
			},
			type: '<?php echo $json_method_type; ?>',
			url: '<?php echo $json_status_url; ?>'
		});
	}
	
	$('input[name="backup_site"]').unbind('click').click(function(){
		var args = {
<?php echo $json_backup_args; ?>
<?php echo $nonces_1; ?>
			};
		var wrap = $(this).parent();
		$('img.success', wrap).remove();
		$('img.failure', wrap).remove();
		$('div#message').remove();
		$('span#error_message').remove();
		wrap.append('<?php echo $loading_img; ?>');
		buttons_disabled(true);

		$("#progressbar").progressbar("enable");
		$("#progresstext").html("<?php _e("Starting Backup...", $this->textdomain);?>");
		$("#progressbar").progressbar( "value", 0 );
		
		if( CYANBackupInterval == null ) { CYANBackupInterval = setInterval( CYANBackupUpdater, 1000 ); }
		
		$.ajax({
			async: true,
			cache: false,
			data: args,
			dataType: 'json',
			success: function(json, status, xhr){
				$('img.updating', wrap).remove();
				buttons_disabled(false);
				$("#progressbar").progressbar( "value", 100 );
				$("#progresstext").html("<?php _e("Backup complete!", $this->textdomain);?>");
			},
			error: function(req, status, err){
				clearInterval( CYANBackupInterval );
				CYANBackupInterval = null;
				$('img.updating', wrap).remove();
				wrap.append('<?php echo $failure_img; ?> <span id="error_message">' + req.responseText + '</span>');
				buttons_disabled(false);
				$("#progressbar").progressbar( "value", 100 );
				$("#progresstext").html("<?php _e("Backup failed!", $this->textdomain);?>");
			},
			type: '<?php echo $json_method_type; ?>',
			url: '<?php echo $json_backup_url; ?>'
		});

		return false;
	});
});
//]]></script>
<?php
	}

	public function add_admin_head_option() {
	}

	public function icon_style() {
?>
<link rel="stylesheet" type="text/css" href="<?php echo $this->plugin_url; ?>css/config.css" />
<?php
	}

	public function admin_menu() {
		$this->backup_page = add_menu_page(
			__('CYAN Backup', $this->textdomain) ,
			__('CYAN Backup', $this->textdomain) ,
			self::ACCESS_LEVEL,
			$this->menu_base ,
			array($this, 'site_backup') ,
			$this->plugin_url . 'images/backup16.png'
			);
		add_action('admin_print_scripts-'.$this->backup_page, array($this,'add_admin_scripts'));
		add_action('admin_head-'.$this->backup_page, array($this,'add_admin_head'));
		add_action('admin_head-'.$this->backup_page, array($this,'add_admin_head_main'));
		add_action('admin_print_styles-' . $this->backup_page, array($this, 'icon_style'));

		add_submenu_page(
			$this->menu_base ,
			__('Backups', $this->textdomain) ,
			__('Backups', $this->textdomain) ,
			self::ACCESS_LEVEL,
			$this->menu_base ,
			array($this, 'site_backup')
			);
			
		$this->option_page = add_submenu_page(
			$this->menu_base ,
			__('Options &gt; CYAN Backup', $this->textdomain) ,
			__('Options', $this->textdomain) ,
			self::ACCESS_LEVEL,
			$this->menu_base . '-options' ,
			array($this, 'option_page')
			);

		add_action('admin_print_scripts-'.$this->option_page, array($this,'add_admin_scripts'));
		add_action('admin_head-'.$this->option_page, array($this,'add_admin_head'));
		add_action('admin_head-'.$this->option_page, array($this,'add_admin_head_option'));
		add_action('admin_print_styles-'.$this->option_page, array($this, 'icon_style'));
		add_action('load-'.$this->option_page,array(&$this,'create_help_screen'));
		
		$this->about_page = add_submenu_page(
			$this->menu_base ,
			__('About &gt; CYAN Backup', $this->textdomain) ,
			__('About', $this->textdomain) ,
			self::ACCESS_LEVEL,
			$this->menu_base . '-about' ,
			array($this, 'about_page')
			);

		add_action('admin_print_scripts-'.$this->about_page, array($this,'add_admin_scripts'));
		add_action('admin_head-'.$this->about_page, array($this,'add_admin_head'));
		add_action('admin_head-'.$this->about_page, array($this,'add_admin_head_option'));
		add_action('admin_print_styles-' . $this->about_page, array($this, 'icon_style'));
	}

	public function create_help_screen() {
		include_once( 'includes/help-options.php' );
	}
	
	//**************************************************************************************
	// sites backup
	//**************************************************************************************
	public function about_page() {
		include_once( 'includes/page-about.php' );
	}
	
	//**************************************************************************************
	// sites backup
	//**************************************************************************************
	public function site_backup() {
		include_once( 'includes/page-backups.php' );
	}

	private function get_real_post_data() {
		// Processing of windows style paths is broken if magic quotes is enabled in php.ini but not enabled during runtime.
		if( get_magic_quotes_gpc() != get_magic_quotes_runtime() ) {
			
			// So we have to get the RAW post data and do the right thing.
			$raw_post_data = file_get_contents('php://input');	
			$post_split = array();
			$postdata = array();
			
			$post_split = explode( '&', $raw_post_data );

			foreach( $post_split as $entry ) {

				$entry_split = explode( '=', $entry, 2 );
				if( get_magic_quotes_runtime() == FALSE ) {
					$postdata[urldecode($entry_split[0])] = urldecode( $entry_split[1] );
				} else {
					$postdata[urldecode(stripslashes($entry_split[0]))] = urldecode( stripslashes($entry_split[1]) );
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
					$getdata[urldecode($entry_split[0])] = urldecode( $entry_split[1] );
				} else {
					$getdata[urldecode(stripslashes($entry_split[0]))] = urldecode( stripslashes($entry_split[1]) );
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
		list( $hours, $minutes) = explode( ':', trim($datestring) );
	
		// If there minutes is blank then there was no colon, otherwise we have a valid hour/minutes setting.
		if( $minutes != '' )
			{
			// Strip out the am if we have one.
			if( stristr( $minutes, 'am' ) ) { $minutes = str_ireplace( 'am', '', $minutes ); if( $hours == 12 ) { $hours = 0; } }
			
			// Strip out the pm if we have one and set the hours forward to represent a 24 hour clock.
			if( stristr( $minutes, 'pm' ) ) { $minutes = str_ireplace( 'pm', '', $minutes ); if( $hours < 12 ) { $hours += 12; } }
			}
		else
			{
			// If there was no colon, then assume whatever value we have is minutes.
			$minutes = $hours;
			$hours = '';
			}
			
		if( $hours > 11 ) { $ampm = 'pm'; }
		
		$long = $hours;
		if( $long > 12 ) { $long -= 12; }
		if( $long == 0 ) { $long = 12; }

		return array( $hours, $minutes, $long, $ampm );
	}
	
	//**************************************************************************************
	// Determine when the first backup should happen based on the schedule
	//**************************************************************************************
	private function calculate_initial_backup( $schedule ) {
		if( !is_array($schedule) ) 
			{ 
			$options = (array)get_option($this->option_name);
			
			$schedule = $options['schedule'];
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
		if( $hours == '' ) { $hours = $now['hours']; }
		if( $minutes == '' ) { $minutes = $now['minutes']; }

		// We have to do some work with day names and need to be able to translate them to numbers, setup an array for later to do this.
		$weekdays = array( 'Sunday'=>0, 'Monday'=>1, 'Tuesday'=>2, 'Wednesday'=>3, 'Thursday'=>4, 'Friday'=>5, 'Saturday'=>6 );
		
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
				if( $next_dow == 0 && $now['hours'] > $hours) { $next_dow += 7; }
				if( $next_dow == 0 && $now['hours'] == $hours && $now['minutes'] > $minutes ) { $next_dow += 7; }

				$now['mday'] += $next_dow;

				$result = mktime( $hours, $minutes, 0, $now['mon'], $now['mday'] );
				}
			else if( $schedule['dom'] != '' )
				{
				// Determine if we've passed the scheduled DOM yet this month.  If so, set it to next month.
				if( $schedule['dom'] > $now['mday'] ) { $now['mon'] ++; }

				// If we're on the DOM we're scheduled to run, check to see if we've passed the scheduled time, if so, sit it to next month.
				if( $schedule['dom'] == $now['mday'] && $now['hours'] > $hours ) { $now['mon']++; }
				if( $schedule['dom'] == $now['mday'] && $now['hours'] == $hours && $now['minutes'] > $minutes ) { $now['mon']++; }

				$result = mktime( $hours, $minutes, 0, $now['mon'], $schedule['dom'] );
				}
			}
		else if( $schedule['type'] == 'Hourly' )
			{
			// If we've passed the current time to run it, schedule it for next hour.
			if( $now['minutes'] > $minutes ) { $now['hours']++; }
			
			$result = mktime( $now['hours'], $minutes );
			}
		else if( $schedule['type'] == 'Daily' )
			{
			// If we've already passed the TOD to run it at, add another day to it.
			if( $now['hours'] > $hours ) { $now['mday']++; }
			if( $now['hours'] == $hours && $now['minutes'] > $minutes ) { $now['mday']++; }

			$result = mktime( $hours, $minutes, 0, $now['mon'], $now['mday'] );
			}
		else if( $schedule['type'] == 'Weekly' )
			{
			// If we have a schedule DOW use it, otherwise use today.
			if( $schedule['dow'] != '' ) { $schedule_dow = $weekdays[$schedule['dow']]; } else { $schedule_dow = $now['wday']; }

			// If we've already passed the TOD to run it at, add another week to it.
			if( $now['wday'] == $schedule_dow && $now['hours'] > $hours ) { $now['mday'] += 7; }
			if( $now['wday'] == $schedule_dow && $now['hours'] == $hours && $now['minutes'] > $minutes ) { $now['mday'] += 7; }
			
			// If we've passed the day this week to run it, add the required number of days to catch it the next week.
			if( $now['wday'] >  $schedule_dow ) { $now['mday'] += 7 - ( $now['wday'] - $schedule_dow ); }

			// If we haven't passed the day this week to run it, add the required number of days to set it.
			if( $now['wday'] <  $schedule_dow ) { $now['mday'] += ( $schedule_dow - $now['wday'] ); }
			
			$result = mktime( $hours, $minutes, 0, $now['mon'], $now['mday'] );
			}
		else if( $schedule['type'] == 'Monthly' )
			{
			// If we have a schedule DOm use it, otherwise use today.
			if( $schedule['dom'] == '' ) { $schedule['dom'] = $now['mday']; }

			// If we've already passed the TOD to run it at, add another week to it.
			if( $now['mday'] == $schedule['dom'] && $now['hours'] > $hours ) { $now['mon'] += 1; }
			if( $now['mday'] == $schedule['dom'] && $now['hours'] == $hours && $now['minutes'] > $minutes ) { $now['mon'] += 1; }
			
			// If we've already passed the DOM this month, set it to next month.
			if( $now['mday'] > $schedule['dom'] ) {	$now['mon'] += 1; }

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
		if( !is_array($options) ) 
			{ 
			$options = (array)get_option($this->option_name);
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
		$options = (array)get_option($this->option_name);
			
		if( $options['schedule']['enabled'] && $options['schedule']['type'] != 'Once' ) {
			$next_backup_time = $this->calculate_next_backup( $options );
		
			wp_schedule_single_event($next_backup_time, 'cyan_backup_hook');

			$options['next_backup_time'] = $next_backup_time;
			update_option($this->option_name, $options);
		}
	}	
	
	//**************************************************************************************
	// Option Page
	//**************************************************************************************
	public function option_page() {
		include_once( 'includes/page-options.php' );
	}

	//**************************************************************************************
	// prune the number of existing backup files in the archive directory
	//**************************************************************************************
	public function prune_backups( $number ) {
		$backup_files = $this->backup_files_info($this->get_backup_files());

		if (count($backup_files) > $number && $number > 1) {
			$i = 1;
			$j = 0;
			foreach ($backup_files as $backup_file) {
				if( $i > $number ) {
					if( ($file = realpath( $backup_file['filename'] ) ) !== FALSE) {
						$logfile = str_ireplace( '.zip', '.log', $file );
						@unlink($file);
						@unlink($logfile);
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
		if ( !is_admin() || !is_user_logged_in() )
			return;

		if ( isset($_GET['page']) && isset($_GET['download']) ) {
			if ( $_GET['page'] !== $this->menu_base )
				return;

			if ($this->wp_version_check('2.5') && function_exists('check_admin_referer'))
				check_admin_referer('backup', self::NONCE_NAME);

			$getdata = $this->get_real_get_data();
				
			if (($file = realpath($getdata['download'])) !== FALSE) {
				if( strtolower( substr( $file, -4 ) ) == ".log" ) {
					header("Content-Type: text/plain;");
				} else {
					header("Content-Type: application/octet-stream;");
				}
				header("Content-Disposition: attachement; filename=".urlencode(basename($file)));
				readfile($file);
			} else {
				header("HTTP/1.1 404 Not Found");
				wp_die(__('File not Found: ' . $getdata['download'], $this->textdomain));
			}
			exit;
		}
	}
}

global $cyan_backup;
$cyan_backup = new CYANBackup();

}
