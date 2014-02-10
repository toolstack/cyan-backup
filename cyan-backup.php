<?php
/*
Plugin Name: CYAN Backup
Version: 0.5
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
	
	$cyan_backup->schedule_next_backup();
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

	private $default_excluded = array(
	    'wp-content/cache/',
	    'wp-content/tmp/',
	    'wp-content/upgrade/',
	    'wp-content/uploads/',
		);

	const   ACCESS_LEVEL = 'manage_options';
	const   NONCE_NAME   = '_wpnonce_CYAN_Backup';
	const   TIME_LIMIT   = 900;			// 15min * 60sec
	const	DEBUG_MODE   = FALSE;
	const	VERSION      = "0.5";

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
		$filemtime = filemtime($file_name)  + (int)get_option('gmt_offset') * 3600;
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
			$filesize = (int)sprintf('%u', filesize($backup_file)) / 1024 / 1024;
			return array(
				'backup_file' => $backup_file,
				'backup_date' => $this->get_filemtime($backup_file),
				'backup_size' => number_format($filesize, 2) . ' MB',
				);
		} else {
			return $result;
		}
	}

	public function scheduled_backup() {
		$remote_backuper = $this->remote_backuper();

		$remote_backuper->wp_backup();

		$options = (array)get_option($this->option_name);

		$this->prune_backups( $options['prune']['number'] );
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
		$json_backup_args = "userid:{$userid},\n";
		$json_method_type = 'POST';
		switch ($this->get_permalink_type()) {
		case 'Pretty':
			$json_backup_url .= 'json/backup/';
			$json_method_type = 'POST';
			break;
		case 'Almost Pretty':
			$json_backup_url .= 'index.php/json/backup/';
			$json_method_type = 'POST';
			break;
		case 'Ugly':
		default:
			$json_backup_args .= "json:'backup',\n";
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

		$.ajax({
			async: true,
			cache: false,
			data: args,
			dataType: 'json',
			success: function(json, status, xhr){
				$('img.updating', wrap).remove();
				if ( xhr.status == 200 && json.result ) {
					var backup_file = '<a href="?page=<?php echo $this->menu_base; ?>&download=' + encodeURIComponent(json.backup_file) + '<?php echo $nonces_2; ?>' + '" title="' + basename(json.backup_file) + '">' + basename(json.backup_file) + '</a>';
					var rowCount = $('#backuplist tr').length - 2;
					var tr = $('<tr><td>' + backup_file + '</td>' +
						'<td>' + json.backup_date  + '</td>' +
						'<td>' + json.backup_size  + '</td>' +
						'<td style="text-align: center;"><input type="checkbox" name="remove[' + ( rowCount )  + ']" value="<?php echo addslashes($archive_path);?>' + basename(json.backup_file) +'"></td></tr>');
					wrap.append('<?php echo $success_img; ?>');
					$('#backuplist').prepend(tr);
				} else {
					wrap.append('<?php echo $failure_img; ?> <span id="error_message">' + json.errors + '</span>');
				}
				buttons_disabled(false);
			},
			error: function(req, status, err){
				$('img.updating', wrap).remove();
				wrap.append('<?php echo $failure_img; ?> <span id="error_message">' + req.responseText + '</span>');
				buttons_disabled(false);
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
		$hook = add_menu_page(
			__('CYAN Backup', $this->textdomain) ,
			__('CYAN Backup', $this->textdomain) ,
			self::ACCESS_LEVEL,
			$this->menu_base ,
			array($this, 'site_backup') ,
			$this->plugin_url . 'images/backup16.png'
			);
		add_action('admin_print_scripts-'.$hook, array($this,'add_admin_scripts'));
		add_action('admin_head-'.$hook, array($this,'add_admin_head'));
		add_action('admin_head-'.$hook, array($this,'add_admin_head_main'));
		add_action('admin_print_styles-' . $hook, array($this, 'icon_style'));

		add_submenu_page(
			$this->menu_base ,
			__('Backups', $this->textdomain) ,
			__('Backups', $this->textdomain) ,
			self::ACCESS_LEVEL,
			$this->menu_base ,
			array($this, 'site_backup')
			);
			
		$hook = add_submenu_page(
			$this->menu_base ,
			__('Options &gt; CYAN Backup', $this->textdomain) ,
			__('Options', $this->textdomain) ,
			self::ACCESS_LEVEL,
			$this->menu_base . '-options' ,
			array($this, 'option_page')
			);

		add_action('admin_print_scripts-'.$hook, array($this,'add_admin_scripts'));
		add_action('admin_head-'.$hook, array($this,'add_admin_head'));
		add_action('admin_head-'.$hook, array($this,'add_admin_head_option'));
		add_action('admin_print_styles-' . $hook, array($this, 'icon_style'));
		
		$hook = add_submenu_page(
			$this->menu_base ,
			__('About &gt; CYAN Backup', $this->textdomain) ,
			__('About', $this->textdomain) ,
			self::ACCESS_LEVEL,
			$this->menu_base . '-about' ,
			array($this, 'about_page')
			);

		add_action('admin_print_scripts-'.$hook, array($this,'add_admin_scripts'));
		add_action('admin_head-'.$hook, array($this,'add_admin_head'));
		add_action('admin_head-'.$hook, array($this,'add_admin_head_option'));
		add_action('admin_print_styles-' . $hook, array($this, 'icon_style'));
	}

	//**************************************************************************************
	// sites backup
	//**************************************************************************************
	public function about_page() {
		$out .= '<div class="wrap">'."\n";

		$out .= '<div id="icon-options-cyan-backup" class="icon32"><br /></div>';

		$out .= '<fieldset style="border:1px solid #cecece;padding:15px; margin-top:25px" >';
		$out .= '<legend><span style="font-size: 24px; font-weight: 700;">&nbsp;' . __('About CYAN Backup', $this->textdomain) . '&nbsp;</span></legend>';
		$out .= '<img src="' . $this->plugin_url . 'images/cyan-backup.png" />';
		$out .= '<h2>' . sprintf(__('CYAN Backup Version %s', $this->textdomain), self::VERSION ) . '</h2>';
		$out .= '<p>' . __('by', $this->textdomain) . ' <a href="https://profiles.wordpress.org/gregross" target=_blank>Greg Ross</a></p>';
		$out .= '<p>&nbsp;</p>';
		$out .= sprintf( __('A fork of the great %sTotal Backup%s by %swokamoto%s.', $this->textdomain), '<a href="http://wordpress.org/plugins/total-backup/" target=_blank>', '</a>', '<a href="http://profiles.wordpress.org/wokamoto/" target=_blank>', '</a>');
		$out .= '<p>&nbsp;</p>';
		$out .= '<p>' . sprintf(__('Licenced under the %sGPL Version 2%s', $this->textdomain), '<a href="http://www.gnu.org/licenses/gpl-2.0.html" target=_blank>', '</a>') . '</p>';
		$out .= '<p>' . sprintf(__('To find out more, please visit the %sWordPress Plugin Directory page%s or the plugin home page on %sToolStack.com%s', $this->textdomain), '<a href="http://wordpress.org/plugins/cyan-backup/" target=_blank>', '</a>', '<a href="http://toolstack.com/cyan-backup" target=_blank>', '</a>') . '</p>';
		$out .= '<p>&nbsp;</p>';
		$out .= '<p>' . sprintf(__("Don't forget to %srate and review%s it too!", $this->textdomain), '<a href="http://wordpress.org/support/view/plugin-reviews/cyan-backup" target=_blank>', '</a>') . '</p>';
		$out .= '</fieldset>';

		
		$out .= '</div>'."\n";

		echo $out;
	}
	
	//**************************************************************************************
	// sites backup
	//**************************************************************************************
	public function site_backup() {
		$out   = '';
		$note  = '';
		$error = 0;
		$nonce_field = 'backup';

		if (isset($_POST['remove_backup'])) {
			if ($this->wp_version_check('2.5') && function_exists('check_admin_referer'))
				check_admin_referer($nonce_field, self::NONCE_NAME);
			if (isset($_POST['remove'])) {
				$postdata = $this->get_real_post_data();
				$count = 0;
				foreach((array)$_POST['remove'] as $index => $bfile) {
					$file = $postdata['remove[' . $index . ']'];
					if (($file = realpath($file)) !== FALSE) {
						if (@unlink($file))
							$count ++;
					}
				}
				if ($count > 0) {
					$note .= "<strong>".__('Delete Backup Files!', $this->textdomain)."</strong>";
				}
			}
		}

		$nonces =
			( $this->wp_version_check('2.5') && function_exists('wp_nonce_field') )
			? wp_nonce_field($nonce_field, self::NONCE_NAME, true, false)
			: '';

		$out .= '<div class="wrap">'."\n";

		$out .= '<div id="icon-options-cyan-backup" class="icon32"><br /></div>';
		$out .= '<h2>';
		$out .= __('CYAN Backup', $this->textdomain);
		$out .= '</h2>'."\n";

		$out .= '<h3>';
		$out .= __('Run Backup', $this->textdomain);
		$out .= '</h3>'."\n";

		$out .= '<form method="post" id="backup_site" action="'.$this->admin_action.'">'."\n";
		$out .= $nonces;
		$out .= '<input type="hidden" name="backup_site" class="button-primary sites_backup" value="'.__('Backup Now!', $this->textdomain).'" class="button" style="margin-left:1em;" />';
		$out .= '<p style="margin-top:1em">';
		$out .= '<input type="submit" name="backup_site" class="button-primary sites_backup" value="'.__('Backup Now!', $this->textdomain).'" class="button" style="margin-left:1em;" />';
		$out .= '</p>';
		$out .= '</form>'."\n";


		$out .= '<h3>';
		$out .= __('Backup Files', $this->textdomain);
		$out .= '</h3>'."\n";

		$out .= '<form method="post" action="'.$this->admin_action.'">'."\n";
		$out .= $nonces;

		$out .= '<table id="backuplist" class="wp-list-table widefat fixed" style="margin-top:0;">'."\n";

		$out .= '<thead><tr>';
		$out .= '<th>' . __('Backup file name', $this->textdomain) . '</th>';
		$out .= '<th>' . __('Datetime', $this->textdomain) . '</th>';
		$out .= '<th>' . __('Size', $this->textdomain) . '</th>';
		$out .= '<th style="text-align: center;"><input type="checkbox" id="switch_checkboxes" name="switch_checkboxes" style="margin: 0px 4px 0px 0px;" /></th>';
		$out .= '</tr></thead>' . "\n";

		$out .= '<tfoot><tr>';
		$out .= '<th colspan="3">';
		$out .= '</th>';
		$out .= '<th style="width: 75px; text-align: center;"><input type="submit" name="remove_backup" class="button-primary" value="'.__('Delete', $this->textdomain).'" class="button" /></th>';
		$out .= '</tr></tfoot>' . "\n";

		$out .= '<tbody>';

		$backup_files = $this->backup_files_info($this->get_backup_files());
		$alternate = ' class="alternate"';
		if (count($backup_files) > 0) {
			$i = 0;
			foreach ($backup_files as $backup_file) {
				$out .= "<tr{$alternate}>";
				$out .= sprintf('<td>%s</td>', $backup_file['url']);

				$temp_time = strtotime( $backup_file['filemtime'] );

				$out .= sprintf('<td>%s</td>', date( get_option('date_format'), $temp_time ) . ' @ ' . date( get_option('time_format'), $temp_time ));
				$out .= sprintf('<td>%s MB</td>', number_format($backup_file['filesize'], 2));
				$out .= "<td style='text-align: center;'><input type=\"checkbox\" id=\"removefiles[{$i}]\" name=\"remove[{$i}]\" value=\"{$backup_file['filename']}\" /></td>";
				$out .= '</tr>' . "\n";
				$i++;
				$alternate = empty($alternate) ? ' class="alternate"' : '';
			}
		}

		$out .= '</tbody>' . "\n";
		$out .= '</table>' . "\n";
		$out .= '</form>'."\n";

		$out .= '</div>'."\n";

		// Output
		echo ( !empty($note) ? '<div id="message" class="updated fade"><p>'.$note.'</p></div>'  : '' );
		echo "\n";

		echo ( $error <= 0 ? $out : '' );
		echo "\n";
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
	// Determine when the next backup should happen based on the schedule
	//**************************************************************************************
	private function calculate_next_backup( $schedule ) {
		if( !is_array($schedule) ) 
			{ 
			$options = (array)get_option($this->option_name);
			
			$schedule = $options['schedule'];
			}
		
		$parameter = '';
		
		if( $schedule['type'] == 'Once' )
			{
			$parameter = $schedule['dow'] . ' ' . $schedule['dom'] . ' ' . $schedule['tod'];
			}
		else if( $schedule['type'] == 'Hourly' )
			{
			$parameter = '+' . $schedule['interval'] . ' hours';
			}
		else if( $schedule['type'] == 'Daily' )
			{
			$parameter = '+' . $schedule['interval'] . ' days ' . $schedule['tod'];
			}
		else if( $schedule['type'] == 'Weekly' )
			{
			$parameter = '+' . $schedule['interval'] . ' weeks ' . $schedule['dow'] . ' ' . $schedule['tod'];
			}
		else if( $schedule['type'] == 'Monthly' )
			{
			$parameter = '+' . $schedule['interval'] . ' month ' . $schedule['tod'];
			}
		else if( $schedule['type'] == 'debug' )
			{
			$parameter = '+1 minute';
			}
			
		if( $parameter != '' )
			{
			$result = strtotime( $parameter );
			return $result;
			}
		else
			{
			return FALSE;
			}
	}

	public function schedule_next_backup( $schedule ) {
		$options = (array)get_option($this->option_name);
			
		if( $options['schedule']['enabled'] && $options['schedule']['type'] != 'Once' ) {
			$next_backup_time = $this->calculate_next_backup( $options['schedule'] );
		
			wp_schedule_single_event($next_backup_time, 'cyan_backup_hook');
		}
	}	
	
	//**************************************************************************************
	// Option Page
	//**************************************************************************************
	public function option_page() {
		$out   = '';
		$notes  = array();
		$error = 0;
		$nonce_field = 'option_update';

		$option = (array)get_option($this->option_name);
		$archive_path = $this->get_archive_path($option);
		$excluded_dir = $this->get_excluded_dir($option, array());

		// option update
		if (isset($_POST['options_update'])) {
			if ( $this->wp_version_check('2.5') && function_exists('check_admin_referer') )
				check_admin_referer($nonce_field, self::NONCE_NAME);

			$postdata = $this->get_real_post_data();

			if ( isset($postdata['archive_path']) ) {
				$abspath  = $this->chg_directory_separator(ABSPATH, FALSE);
				$dir = trim($postdata['archive_path']);

				if ( ($realpath = realpath($dir)) !== FALSE) {
					$realpath = $this->chg_directory_separator($realpath, FALSE);
					if ( is_dir($realpath) )
						$realpath = $this->trailingslashit($realpath, FALSE);
					$options['archive_path'] = $realpath;
					
					if( substr( $realpath, 0, strlen( $abspath) ) == $abspath ) {
						$notes[] = "<strong>". sprintf(__('WARNING: Archive directory ("%s") is a subdirectory in the WordPress root and may be accessible via the web, this could be an insecure configuration!', $this->textdomain), $realpath)."</strong>";
						$notes[] = "<strong>". __('WARNING: If you keep this configuration also make sure to exclude this directory from the backup.', $this->textdomain)."</strong>";
						$error++;
					}
				} else {
					$notes[] = "<strong>". sprintf(__('Failure!: Archive directory ("%s") is not found.', $this->textdomain), $realpath)."</strong>";
					$error++;
				}
			}
			
			if ( isset($postdata['excluded']) ) {
				$excluded = $excluded_dir = array();
				$abspath  = $this->chg_directory_separator(ABSPATH, FALSE);
				foreach ( explode("\n", $postdata['excluded']) as $dir ) {
					$dir = trim($dir);
					if ( !empty($dir) ) {
						if ( ($realpath = realpath($dir)) !== FALSE) {
							$realpath = $this->chg_directory_separator($realpath, FALSE);
							$dir = str_replace($abspath, '', $realpath);
							if ( is_dir($realpath) )
								$dir = $this->trailingslashit($dir, FALSE);
							$excluded[] = $dir;
							$excluded_dir[] = str_replace($abspath, '', $dir);
						} else {
							$notes[] = "<strong>". sprintf(__('Failure!: Excluded dir("%s") is not found.', $this->textdomain), $dir)."</strong>";
							$error++;
						}
					}
				}
				$options['excluded'] = $excluded;
			}

			if ( isset($_POST['schedule']) ) {
				if( is_array( $_POST['schedule'] ) ) {
					$options['schedule'] = $_POST['schedule'];
				}
			}

			// Remove the backup schedule if we've change it recurrence.
			if( wp_next_scheduled('cyan_backup_hook') && ( $options['schedule']['type'] != $option['schedule']['type'] || $options['schedule']['interval'] != $option['schedule']['interval'] || $options['schedule']['tod'] != $option['schedule']['tod'] || $options['schedule']['dom'] != $option['schedule']['dom'] || $options['schedule']['dow'] != $option['schedule']['dow'] ) ) {
			
				wp_unschedule_event(wp_next_scheduled('cyan_backup_hook'), 'cyan_backup_hook');
			}

			// Add the backup schedule if it doesn't exist and is enabled.
			if( !wp_next_scheduled('cyan_backup_hook') && $options['schedule']['enabled'] ) {
				$next_backup_time = $this->calculate_next_backup( $options['schedule'] );
			
				 wp_schedule_single_event($next_backup_time, 'cyan_backup_hook');
			}

			// Remove the backup schedule if it does exist and is disabled.
			if( wp_next_scheduled('cyan_backup_hook') && !$options['schedule']['enabled'] ) {
			
				wp_unschedule_event(wp_next_scheduled('cyan_backup_hook'), 'cyan_backup_hook');
			}

			if ( isset($_POST['prune']) ) {
				if( is_array( $_POST['prune'] ) ) {
					$options['prune'] = $_POST['prune'];
				}
			}
			
			update_option($this->option_name, $options);

			$option = $options;
			$archive_path = $this->get_archive_path($option);
			$excluded_dir = $this->get_excluded_dir($option, array());

			// Done!
			if ( $error <= 0 )
				$notes[] = "<strong>".__('Configuration saved!', $this->textdomain)."</strong>";
		}

		$schedule_types = array( 'Once', 'Hourly', 'Daily', 'Weekly', 'Monthly' );

		if( self::DEBUG_MODE == TRUE ) {
			$schedule_types[] = 'debug';
		}

		$display_settings = array();
		$display_type_settings = array( 
									'Once' => array( 
										'schedule_debug' => 'display: none;',
										'schedule_once' => '',
										'schedule_before' => 'display: none;',
										'schedule_interval' => 'display: none;',
										'schedule_hours' => 'display: none;',
										'schedule_days' => 'display: none;',
										'schedule_weeks' => 'display: none;',
										'schedule_months' => 'display: none;',
										'schedule_on' => '',
										'schedule_dow' => '',
										'schedule_the' => '',
										'schedule_dom' => '',
										'schedule_at' => '',
										'schedule_tod' => ''
										),
									'Hourly' => array( 
										'schedule_debug' => 'display: none;',
										'schedule_once' => 'display: none;',
										'schedule_before' => '',
										'schedule_interval' => '',
										'schedule_hours' => '',
										'schedule_days' => 'display: none;',
										'schedule_weeks' => 'display: none;',
										'schedule_months' => 'display: none;',
										'schedule_on' => 'display: none;',
										'schedule_dow' => 'display: none;',
										'schedule_the' => 'display: none;',
										'schedule_dom' => 'display: none;',
										'schedule_at' => '',
										'schedule_tod' => ''
										),
									'Daily' => array( 
										'schedule_debug' => 'display: none;',
										'schedule_once' => 'display: none;',
										'schedule_before' => '',
										'schedule_interval' => '',
										'schedule_hours' => 'display: none;',
										'schedule_days' => '',
										'schedule_weeks' => 'display: none;',
										'schedule_months' => 'display: none;',
										'schedule_on' => 'display: none;',
										'schedule_dow' => 'display: none;',
										'schedule_the' => 'display: none;',
										'schedule_dom' => 'display: none;',
										'schedule_at' => '',
										'schedule_tod' => ''
										),
									'Weekly' => array( 
										'schedule_debug' => 'display: none;',
										'schedule_once' => 'display: none;',
										'schedule_before' => '',
										'schedule_interval' => '',
										'schedule_hours' => 'display: none;',
										'schedule_days' => 'display: none;',
										'schedule_weeks' => '',
										'schedule_months' => 'display: none;',
										'schedule_on' => '',
										'schedule_dow' => '',
										'schedule_the' => 'display: none;',
										'schedule_dom' => 'display: none;',
										'schedule_at' => '',
										'schedule_tod' => ''
										),
									'Monthly' => array( 
										'schedule_debug' => 'display: none;',
										'schedule_once' => 'display: none;',
										'schedule_before' => '',
										'schedule_interval' => '',
										'schedule_hours' => 'display: none;',
										'schedule_days' => 'display: none;',
										'schedule_weeks' => 'display: none;',
										'schedule_months' => '',
										'schedule_on' => '',
										'schedule_dow' => 'display: none;',
										'schedule_the' => '',
										'schedule_dom' => '',
										'schedule_at' => '',
										'schedule_tod' => ''
										)
									);		
		
		if( self::DEBUG_MODE == TRUE ) {
			$display_type_settings['debug'] = array( 
										'schedule_debug' => '',
										'schedule_once' => 'display: none;',
										'schedule_before' => 'display: none;',
										'schedule_interval' => 'display: none;',
										'schedule_hours' => 'display: none;',
										'schedule_days' => 'display: none;',
										'schedule_weeks' => 'display: none;',
										'schedule_months' => 'display: none;',
										'schedule_on' => 'display: none;',
										'schedule_dow' => 'display: none;',
										'schedule_the' => 'display: none;',
										'schedule_dom' => 'display: none;',
										'schedule_at' => 'display: none;',
										'schedule_tod' => 'display: none;'
										);
		}
		
		$out .= '<script type="text/javascript">//<![CDATA[' . "\n";
		
		$out .= 'function set_schedule_display() {' . "\n";
		$out .= 'var display_type_settings = new Array() ' . "\n\n";

		foreach( $display_type_settings as $key => $value ) {
			$out .= 'display_type_settings[\'' . $key . '\'] = new Array();' . "\n";
		}
		
		foreach( $display_type_settings as $key => $value ) {
			foreach( $value as $subkey => $subvalue ) {
				$out .= 'display_type_settings[\'' . $key . '\'][\'' . $subkey . '\'] = \'';
				if( $subvalue == "display: none;" ) { $out .= '0'; } else { $out .= '1'; }
				$out .= '\';' . "\n";
			}
		}
		
		$out .= "\n";
		
		$out .= 'var type = jQuery("#schedule_type").val();' . "\n";
		$out .= "\n";
		$out .= 'for( var i in display_type_settings[type] ) {' . "\n";
		$out .= 'if( display_type_settings[type][i] == 0 ) { jQuery("#" + i).css( "display", "none" ); } else { jQuery("#" + i).css( "display", "" ); }' . "\n";
		$out .= '}' . "\n";
		
		$out .= '}' . "\n";
		
		$out .= '//]]></script>' . "\n";
		
		$out .= '<div class="wrap">'."\n";

		$out .= '<div id="icon-options-general" class="icon32"><br /></div>';
		$out .= '<h2>';
		$out .= __('CYAN Backup Options', $this->textdomain);
		$out .= '</h2>'."\n";

		$out .= '<h3>';
		$out .= __('Directory Options', $this->textdomain);
		$out .= '</h3>'."\n";

		$out .= '<form method="post" id="option_update" action="'.$this->admin_action.'-options">'."\n";
		if ($this->wp_version_check('2.5') && function_exists('wp_nonce_field') )
			$out .= wp_nonce_field($nonce_field, self::NONCE_NAME, true, false);

		$out .= "<table class=\"optiontable form-table\" style=\"margin-top:0;\"><tbody>\n";

		$out .= '<tr>';
		$out .= '<th>'.__('Archive path', $this->textdomain).'</th>';
		$out .= '<td><input type="text" name="archive_path" id="archive_path" size="100" value="'.htmlentities($archive_path).'" /></td>';
		$out .= '</tr>'."\n";

		$out .= '<tr>';
		$out .= '<th>'.__('Excluded dir', $this->textdomain).'</th>';
		$out .= '<td><textarea name="excluded" id="excluded" rows="5" cols="100">';
		$abspath  = $this->chg_directory_separator(ABSPATH, FALSE);
		foreach ($excluded_dir as $dir) {
			$out .= htmlentities($this->chg_directory_separator($abspath.$dir,FALSE)) . "\n";
		}
		$out .= '</textarea><br><br>';
		$out .= '<input class="button" id="AddArchiveDir" name="AddArchiveDir" type="button" value="Add Archive Dir" onClick="excluded.value = jQuery.trim( excluded.value ) + \'\n'. addslashes( $archive_path ) . '\';">&nbsp;';
		$out .= '<input class="button" id="AddWPContentDir" name="AddWPContentDir" type="button" value="Add WP-Content Dir" onClick="excluded.value = jQuery.trim( excluded.value ) + \'\n'. addslashes( WP_CONTENT_DIR ) . '\';">&nbsp;';
		$out .= '<input class="button" id="AddWPContentDir" name="AddWPUpgradeDir" type="button" value="Add WP-Upgrade Dir" onClick="excluded.value = jQuery.trim( excluded.value ) + \'\n'. addslashes( WP_CONTENT_DIR ) . '/upgrade\';">&nbsp;';
		$out .= '<input class="button" id="AddWPAdminDir" name="AddWPAdminDir" type="button" value="Add WP-Admin Dir" onClick="excluded.value = jQuery.trim( excluded.value ) + \'\n'. addslashes( $abspath ) . 'wp-admin\';">&nbsp;';
		$out .= '<input class="button" id="AddWPIncludesDir" name="AddWPIncludesDir" type="button" value="Add WP-Includes Dir" onClick="excluded.value = jQuery.trim( excluded.value ) + \'\n'. addslashes($abspath) . 'wp-includes\';">&nbsp;';
		$out .= '</td>';
		$out .= '</tr>'."\n";

		$out .= '</tbody></table>' . "\n";

		$out .= '<h3>';
		$out .= __('Schedule Options', $this->textdomain);
		$out .= '</h3>'."\n";

		$out .= "<table style=\"margin-top:0; width: auto;\"><tbody>\n";
		$out .= '<tr>';
		$out .= '<td class="description" style="width: auto; text-align: right; vertical-align: top;"><span class="description">' . __('Current server time', $this->textdomain) .'</span>:</td><td style="width: auto; text-align: left; vertical-align: top;"><code>';

		$next_schedule = time();
		$out .= date( get_option('date_format'), $next_schedule ) . ' @ ' . date( get_option('time_format'), $next_schedule );

		$out .= '</code></td>';
		$out .= '</tr>';
	
		if( $option['schedule']['enabled'] == 'on' ) { 
			$out .= '<tr>';
		
			$out .= '<td style="width: auto; text-align: right; vertical-align: top;"><span class="description">' . __('Next backup scheduled for', $this->textdomain) .'</span>:</td><td style="width: auto; text-align: left; vertical-align: top;"><code>';

			$next_schedule = wp_next_scheduled('cyan_backup_hook');

			if( $next_schedule ) {
				$out .= date( get_option('date_format'), $next_schedule ) . ' @ ' . date( get_option('time_format'), $next_schedule );
			}
			else {
				$out .= __('None', $this->textdomain );
			}

			$out .= '</code></td>';
			$out .= '</tr>';
		}

		$out .= '</tbody></table>' . "\n";
		
		$out .= "<table class=\"optiontable form-table\" style=\"margin-top:0;\"><tbody>\n";

		$out .= '<tr>';
		$out .= '<th>'.__('Enable', $this->textdomain).'</th>';
		$out .= '<td><input type=checkbox id="schedule_enabled" name="schedule[enabled]"';
		if( $option['schedule']['enabled'] == 'on' ) { $out .= ' CHECKED'; }
		$out .=	'></td>';
		$out .= '</tr>'."\n";

		$out .= '<tr>';
		$out .= '<th>'.__('Type', $this->textdomain).'</th>';
		$out .= '<td><select id="schedule_type" onChange="set_schedule_display();" name="schedule[type]">';
		
		foreach( $schedule_types as $type ) {
			$out .= '<option value="' . $type . '"';

			if( $option['schedule']['type'] == $type ) { $out .= ' SELECTED'; $display_settings = $display_type_settings[$type];}
			
			$out .= '>' . __($type, $this->textdomain) . '</option>';
		}
		
		$out .= '</select></td>';
		$out .= '</tr>'."\n";

		$out .= '<tr>';
		$out .= '<th>'.__('Schedule', $this->textdomain).'</th>';
		$out .= '<td>';
		
		if( self::DEBUG_MODE == TRUE ) {
			$out .= '<span id="schedule_debug" style="' . $display_settings['schedule_debug'] . '">' . __('Every minute, for debugging only', $this->textdomain) . '</span>';
		}
		
		$out .= '<span id="schedule_once" style="' . $display_settings['schedule_once'] . '">' . __('Only once', $this->textdomain) . '</span>';
		$out .= '<span id="schedule_before" style="' . $display_settings['schedule_before'] . '">' . __('Run backup every', $this->textdomain) . ' </span>';
		$out .= '<input type="text" id="schedule_interval" name="schedule[interval]" size="3" value="'. $option['schedule']['interval'] . '" style="' . $display_settings['schedule_interval'] . '">';
		$out .= '<span id="schedule_hours" style="' . $display_settings['schedule_hours'] . '"> ' . __('hour(s)', $this->textdomain) . '</span><span id="schedule_days" style="' . $display_settings['schedule_days'] . '"> ' . __('day(s)', $this->textdomain) . '</span><span id="schedule_weeks" style="' . $display_settings['schedule_weeks'] . '"> ' . __('week(s)', $this->textdomain) . '</span><span id="schedule_months" style="' . $display_settings['schedule_months'] . '"> ' . __('month(s)', $this->textdomain) . '</span>';
		$out .= '<span id="schedule_on" style="' . $display_settings['schedule_on'] . '"> ' . __('on', $this->textdomain) . '</span>';

		$out .= '<select id="schedule_dow" name="schedule[dow]" style="' . $display_settings['schedule_dow'] . '">';
		$out .= '<option value=""></option>';
		
		$weekdays = array( 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' );
		foreach( $weekdays as $day ) {
			$out .= '<option value="' . $day . '"';

			if( $option['schedule']['dow'] == $day ) { $out .= ' SELECTED'; }
			
			$out .= '>' . __($day, $this->textdomain) . '</option>';
		}
		
		$out .= '</select>';

		$out .= '<span id="schedule_the" style="' . $display_settings['schedule_the'] . '"> ' . __('the', $this->textdomain) . '</span>';
		
		$out .= '<select id="schedule_dom" name="schedule[dom]" style="' . $display_settings['schedule_dom'] . '">';
		$out .= '<option value=""></option>';
		
		for( $i = 1; $i < 28; $i++ ) {
			$out .= '<option value="' . $i . '"';

			if( $option['schedule']['dom'] == $i ) { $out .= ' SELECTED'; }
			
			$out .= '>' . $i . '</option>';
		}

		$out .= '</select>';
		
//		$out .= '<input type="text" id="schedule_dom" name="schedule[dom]" size="2" value="'. $option['schedule']['dom'] . '" style="' . $display_settings['schedule_dom'] . '">';
		$out .= '<span id="schedule_at" style="' . $display_settings['schedule_at'] . '"> ' . __('at', $this->textdomain) . '</span>';
		$out .= '<input type="text" id="schedule_tod" name="schedule[tod]" size="8" value="'. $option['schedule']['tod'] . '" style="' . $display_settings['schedule_tod'] . '">';
		$out .= '.</td>';
		$out .= '</tr>'."\n";

		$out .= '</tbody></table>' . "\n";

		$out .= '<h3>';
		$out .= __('Storage Maintenance', $this->textdomain);
		$out .= '</h3>'."\n";

		$out .= "<table class=\"optiontable form-table\" style=\"margin-top:0;\"><tbody>\n";

		$out .= '<tr>';
		$out .= '<th>'.__('Enable backup pruning', $this->textdomain).'</th>';
		$out .= '<td><input type=checkbox name="prune[enabled]"';
		if( $option['prune']['enabled'] == 'on' ) { $out .= ' CHECKED'; }
		$out .=	'></td>';
		$out .= '</tr>'."\n";

		$out .= '<tr>';
		$out .= '<th>'.__('Number of backups to keep', $this->textdomain).'</th>';
		$out .= '<td><input type="text" name="prune[number]" size="5" value="'. $option['prune']['number'] . '"></td>';
		$out .= '</tr>'."\n";
		
		$out .= '</tbody></table>' . "\n";

		$out .= '<p style="margin-top:1em;">';
		$out .= '<input type="submit" name="options_update" class="button-primary" value="'.__('Update Options', $this->textdomain).'" class="button" />';
		$out .= '</p>';

		$out .= '</form>'."\n";

		$out .= '</div>'."\n";

		// Output
		foreach( $notes as $note ) {
			echo '<div id="message" class="updated fade"><p>' . $note . '</p></div>';
			echo "\n";
		}

		echo $out;
		echo "\n";
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
						@unlink($file);
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
				header("Content-Type: application/octet-stream;");
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
