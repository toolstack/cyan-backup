<?php
if (!class_exists('WP_Backuper')) :

class WP_Backuper {
	private $wp_dir;
	private $archive_path;
	private $archive_pre;
	private $archive_file;
	private $default_excluded = array(
	    'wp-content/cache/',
	    'wp-content/tmp/',
	    'wp-content/upgrade/',
		);
	private $textdomain  = 'cyan-backup';

	private $dump_file;
	private $core_tables = array();
	private $files = array();
	private $statuslogfile = null;
	private $logfile = null;
	private $currentcount = 0;
	private $increment = 0;
	private $percentage = 0;
	private $last_percentage = 0;

	private $error = array();

	const ROWS_PER_SEGMENT = 100;
	const MAINTENANCE_MODE = '.maintenance';
	const TIME_LIMIT       = 900;		// 15min * 60sec
	const EXCLUSION_KEY    = 'WP_Backuper::wp_backup';
	const OPTION_NAME      = 'CYAN Backup Option';

	//**************************************************************************************
	// Constructor
	//**************************************************************************************
	function __construct($archive_path = FALSE, $archive_prefix = FALSE, $wp_dir = FALSE, $excluded = FALSE){
		$option = (array)get_option(self::OPTION_NAME);
		$archive_path = 
			($archive_path === FALSE && isset($option["archive_path"]) && is_dir($option["archive_path"]))
			? $option["archive_path"]
			: $archive_path ;
		$excluded = (array)
			($excluded === FALSE && isset($option["excluded"]) && is_array($option["excluded"]))
			? $option["excluded"]
			: $excluded ;

		$this->archive_path = $this->get_archive_path($archive_path);
		$this->archive_pre  = $this->get_archive_prefix($archive_prefix);
		$this->wp_dir       = $this->get_wp_dir($wp_dir);
		$this->archive_file = FALSE;
		$this->excluded     = array_merge(
			array(
				'.'.DIRECTORY_SEPARATOR ,
				'..'.DIRECTORY_SEPARATOR ,
				self::MAINTENANCE_MODE ,
				),
			$this->get_excluded_dir($excluded)
			);
	}

	//**************************************************************************************
	// Utility
	//**************************************************************************************

	// sys get temp dir
	private function sys_get_temp_dir() {
		if (isset($_ENV['TMP']) && !empty($_ENV['TMP'])) 
			return realpath($_ENV['TMP']);
		if (isset($_ENV['TMPDIR']) && !empty($_ENV['TMPDIR'])) 
			return realpath($_ENV['TMPDIR']);
		if (isset($_ENV['TEMP']) && !empty($_ENV['TEMP'])) 
			return realpath($_ENV['TEMP']);
		$tempfile = tempnam(__FILE__,'');
		if (file_exists($tempfile)) {
			unlink($tempfile);
			return realpath(dirname($tempfile));
		}
		return null;
	}

	// get archive path
	private function get_archive_path($archive_path = NULL) {
		return $this->chg_directory_separator(trailingslashit(
			$archive_path
			? $archive_path
			: (defined('ABSPATH') ? dirname(ABSPATH) : $this->sys_get_temp_dir())
			), FALSE);
	}

	// get excluded dir
	private function get_excluded_dir($excluded = NULL) {
		return $this->chg_directory_separator(
			$excluded && is_array($excluded) ? $excluded : $this->default_excluded ,
			FALSE
			);
	}

	// get archive prefix
	private function get_archive_prefix($archive_prefix = NULL) {
		return (
			$archive_prefix
			? str_replace(DIRECTORY_SEPARATOR, '-', untrailingslashit($archive_prefix))
			: basename(ABSPATH).'.'
			);
	}

	// get wp dir
	private function get_wp_dir($wp_dir = NULL) {
		return $this->chg_directory_separator(
			$wp_dir
			? $wp_dir
			: (defined('ABSPATH') ? ABSPATH : dirname(__FILE__))
			, FALSE);
	}

	// chg directory separator
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

	// get filemtime
	private function get_filemtime($file_name) {
		$filemtime = filemtime($file_name);//  + (int)get_option('gmt_offset') * 3600;
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

	// set transient
	private function set_transient($key, $value, $expiration = 0) {
		return
			function_exists('set_site_transient')
			? set_site_transient($key, $value, $expiration)
			: set_transient($key, $value, $expiration);
	}

	// get transient
	private function get_transient($key) {
		return
			function_exists('get_site_transient')
			? get_site_transient($key)
			: get_transient($key);
	}

	// delete_transient
	private function delete_transient($key) {
		return
			function_exists('delete_site_transient')
			? delete_site_transient($key)
			: delete_transient($key);
	}

	// maintenance mode
	private function maintenance_mode($enable = FALSE) {
		$file = $this->wp_dir . self::MAINTENANCE_MODE;
		if ( $enable ) {
			// Create maintenance file to signal that we are upgrading
			$maintenance_string = '<?php $upgrading = ' . time() . '; ?>';
			if (file_exists($file))
				@unlink($file);
			file_put_contents($file, $maintenance_string);
		} else if ( !$enable && file_exists($file) ) {
			@unlink($file);
		}

		return $enable;
	}

	// verify nonce if no logged in
	private function verify_nonce_no_logged_in($nonce, $action = -1) {
		$i = wp_nonce_tick();

		// Nonce generated 0-12 hours ago
		if ( substr(wp_hash($i . $action, 'nonce'), -12, 10) == $nonce )
			return 1;
		// Nonce generated 12-24 hours ago
		if ( substr(wp_hash(($i - 1) . $action, 'nonce'), -12, 10) == $nonce )
			return 2;
		// Invalid nonce
		return false;
	}

	// create nonce if no logged in
	private function create_nonce_no_logged_in($action = -1) {
		$i = wp_nonce_tick();
		return substr(wp_hash($i . $action, 'nonce'), -12, 10);
	}

	//**************************************************************************************
	// Get the total number of rows in the WordPress tables we're going to backup.
	//**************************************************************************************
	private function get_sql_row_count() {
		global $wpdb;
		
		// get core tables
		$core_tables = $this->get_core_tables();
		$row_count = 0;

		// Count the total number of rows in the tables.
		foreach( $core_tables as $table ) {
			$row_count += $wpdb->get_var("SELECT count(*) FROM `{$table}`" );
		}
		
		return $row_count;
	}

	private function write_status_file( $percentage, $message, $state = 'active') {
		if( $this->statuslogfile == null ) { return; }
	
		$status_file = fopen( $this->statuslogfile, "w" );

		if( $status_file !== FALSE ) {
			fwrite( $status_file, $percentage . "\n" );
			fwrite( $status_file, $message . "\n" );
			fwrite( $status_file, $state . "\n" );
			fwrite( $status_file, realpath( $this->archive_file ). "\n" );
			
			if( $state == 'complete' ) {
				fwrite( $status_file, $this->get_filemtime($this->archive_file) . "\n" );
				fwrite( $status_file, ( filesize($this->archive_file) / 1024 / 1024 ) . "\n");
			} else {
				fwrite( $status_file, date("Y-m-d H:i:s", time() ) );
				fwrite( $status_file, "0" );
			}
			
			fclose( $status_file );	
		}
	}

	private function open_log_file( $name ) {
		if( $this->logfile == null ) {
			$this->logfile = fopen($name, 'a');
		}
	}
	
	private function write_log_file( $message ) {
		if( $this->logfile != null ) {
			fwrite($this->logfile, '[' . date("Y-m-d H:i:s") . '] ' . $message . "\n");
		}
	}
	
	private function close_log_file() {
		if( $this->logfile != null ) {
			fclose($this->logfile);
			$this->logfile = null;
		}
	}

	//**************************************************************************************
	// WP Backup
	//**************************************************************************************
	public function wp_backup($db_backup = TRUE) {

	    $this->set_transient(self::EXCLUSION_KEY, TRUE);
		
		if ($this->get_transient(self::EXCLUSION_KEY) === false) {
			$this->error[] = __('Could not set transient!', $this->textdomain);
			return array(
				'result'    => FALSE ,
				'errors'    => $this->error ,
				);
		}

		if (!$this->can_user_backup()) {
			$this->error[] = __('User does not have rights to backup!', $this->textdomain);
			return array(
				'result'    => FALSE ,
				'errors'    => $this->error ,
				);
		}

		try {
		    $this->set_transient(self::EXCLUSION_KEY, TRUE);

			// Increase script execution time-limit to 15 min.
			if ( !ini_get('safe_mode'))
				set_time_limit(self::TIME_LIMIT);

			$archive_path   = $this->get_archive_path($this->archive_path);
			$archive_prefix = $this->get_archive_prefix($this->archive_pre);
			$filename       = $archive_prefix . date('Ymd.His');

			$active_filename = $archive_path . 'backup.active';
			if( file_exists( $active_filename ) ) {
				$active_filetime = strtotime( $this->get_filemtime($active_filename) );
				
				// Check to see if the active state is stale ( >30 minutes old )
				if( time() - $active_filetime > (60 * 30) ) {
					unlink( $active_filename );
				} else {
					$this->error[] = __('Another backup is already running!', $this->textdomain);
					return array(
						'result'    => FALSE ,
						'errors'    => $this->error ,
						);
				}
			} 

			// Create a semaphore file to indicate we're active.
			$active_backup = fopen( $active_filename, "w" );
			fwrite( $active_backup, "placeholder\n" );
			fclose( $active_backup );
			
			$this->statuslogfile = $archive_path . 'status.log';
			$this->write_status_file( 0, __('Calculating backup size...', $this->textdomain) );
			
			$this->open_log_file( $archive_path . $filename . ".log" );
			$this->write_log_file( __('Calculating backup size...', $this->textdomain) );

			// Maintenance mode ON
			//$this->maintenance_mode(TRUE);

			// get SQL rows.
			$sqlrowcount = $this->get_sql_row_count();
			
			// get files
			$this->files = $files = $this->get_files($this->wp_dir, $this->excluded);
			$filecount = count( $files );
			
			// Total count is the sqlrowcount + 3 times through the file tree
			// pass 1: copy to temp directory
			// pass 2: add to zip
			// pass 3: remove temp directory
			$total_count = $sqlrowcount + ( $filecount * 3 );
			
			$this->increment = 100 / $total_count;

			$this->write_status_file( 0, __('Backup started, processing SQL tables...', $this->textdomain));
			$this->write_log_file( __('Backup started, processing SQL tables...', $this->textdomain) );
			
			// DB backup
			if ($db_backup)
				$this->dump_file = $this->wpdb_dump($archive_path, $archive_prefix);

			$this->write_status_file( $this->last_percentage, __('Making temporary copying of WordPress...', $this->textdomain));
			$this->write_log_file( __('Making temporary copying of WordPress...', $this->textdomain) );

			// WP Core files backup
			$backup_dir = trailingslashit(trailingslashit($archive_path).$filename);
			$this->files_backup($this->wp_dir, $files, $backup_dir);

			// Maintenance mode OFF
			//$this->maintenance_mode(FALSE);

			$this->write_status_file( $this->last_percentage, __('Archiving files...', $this->textdomain));
			$this->write_log_file( __('Archiving files...', $this->textdomain) );

			// WP Core files archive
			$zip_file = $this->chg_directory_separator(trailingslashit($archive_path).$filename.'.zip');
			$backup = $this->files_archive($backup_dir, $files, $zip_file);

			$this->write_status_file( $this->last_percentage, __('Removing temporary files...', $this->textdomain));
			$this->write_log_file( __('Removing temporary files...', $this->textdomain) );

			// Remove DB backup files
			if ( $db_backup && file_exists($this->dump_file) ) {
				$db_backup = TRUE;
				if ( file_exists($backup) ) {
					$this->archive_file = $backup;
					unlink($this->dump_file);
				} else {
					$this->archive_file = FALSE;
				}
			} else {
				$db_backup = FALSE;
				$this->archive_file = FALSE;
			}
			if ( file_exists($backup_dir) ) {
				$this->recursive_rmdir($backup_dir);
			}

			$this->delete_transient(self::EXCLUSION_KEY);

			if( count( $this->error ) > 0 )
				{
				$this->write_status_file( 100, __('ERROR:', $this->textdomain ) . implode( '<br>', $this->error ), 'error' );
				$this->write_log_file( __('ERROR:', $this->textdomain ) . implode( ' - ', $this->error ) );
				$this->statuslogfile = null;
				}
			else
				{
				$this->write_status_file( 100, __('Backup complete!', $this->textdomain ), 'complete' );
				$this->write_log_file( __('Backup complete!', $this->textdomain) );
				$this->statuslogfile = null;
				}
			
			unlink( $active_filename );
			
			$this->close_log_file();
			
			return array(
				'backup'    => ($backup && file_exists($backup)) ? $this->archive_file : FALSE ,
				'db_backup' => $db_backup ? basename($this->dump_file) : FALSE ,
				'errors'    => $this->error ,
//				'wp_dir'    => $this->wp_dir ,
//				'excluded'  => $this->excluded ,
//				'files'     => $this->files ,
				);

		} catch(Exception $e) {
			// Maintenance mode OFF
			//$this->maintenance_mode(FALSE);
			$this->delete_transient(self::EXCLUSION_KEY);
			$this->error[] = $e->getMessage();
			return array(
				'result'    => FALSE ,
				'errors'    => $this->error ,
				);
		}
	}

	//**************************************************************************************
	// Get Archive File Name
	//**************************************************************************************
	public function archive_file() {
		return $this->archive_file;
	}

	//**************************************************************************************
	// can user backup ?
	//**************************************************************************************
	private function can_user_backup($loc = 'main') {
		$can = TRUE;
		return $can;
	}

	//**************************************************************************************
	// Get All WP Files
	//**************************************************************************************
	private function get_files($dir, $excluded, $pre = '') {
		$result = array();
		if (file_exists($dir) && is_dir($dir)) {
			if ($dh = opendir($dir)) {
				while (($file = readdir($dh)) !== false) {
					if (is_dir($dir.$file)) {
						$file .= DIRECTORY_SEPARATOR;
						$result[] = $pre.$file;
						if (!(in_array($file, $excluded) || in_array($pre.$file, $excluded))) {
							$result = array_merge($result, $this->get_files($dir.$file,$excluded,$pre.$file));
						}
					} else if (!(in_array($file, $excluded) || in_array($pre.$file, $excluded))) {
						$result[] = $pre.$file;
					}
				}
				closedir($dh);
			}
		}
		return $result;
	}

	//**************************************************************************************
	// WP Files Backup
	//**************************************************************************************
	private function files_backup($source_dir, $files, $dest_dir) {
		if (!$this->can_user_backup())
			throw new Exception(__('Could not backup!', $this->textdomain));

		try {
			if ( !file_exists($dest_dir) )
				mkdir($dest_dir, 0700);
			if (!is_writable($dest_dir))
				throw new Exception(__('Could not open the backup file for writing!', $this->textdomain));

			$dest_dir = trailingslashit($dest_dir);
			if ( file_exists($this->dump_file) )
				copy( $this->dump_file, $dest_dir.basename($this->dump_file) );

			$dest_dir = trailingslashit($dest_dir . basename($source_dir));
			$dest_dir = $this->chg_directory_separator($dest_dir);
			if ( !file_exists($dest_dir) )
				mkdir($dest_dir, 0700);
			if (!is_writable($dest_dir))
				throw new Exception(__('Could not open the backup file for writing!', $this->textdomain));

			$source_dir = $this->chg_directory_separator(trailingslashit($source_dir));

			foreach ($files as $file) {
				$this->currentcount++;
				$this->percentage += $this->increment;
				
				if( round( $this->percentage ) > $this->last_percentage ) {
					$this->last_percentage = round( $this->percentage );
					$this->write_status_file( $this->last_percentage, sprintf( __("Copying %s...", $this->textdomain), realpath($file) ) );
				}

				$this->write_log_file( sprintf( __("Copying %s...", $this->textdomain), realpath($file) ) );
				
				if ( is_dir($source_dir.$file) ) {
					if ( !file_exists($dest_dir.$file) )
						mkdir($dest_dir.$file);
				} else {
					copy($source_dir.$file, $dest_dir.$file);
				}
			}
		} catch(Exception $e) {
			throw new Exception($e->getMessage());
		}

		return TRUE;
	}

	private function recursive_rmdir($dir) {
		if (is_dir($dir)) {
			$files = scandir($dir);
			foreach ($files as $file) {
				if ($file != "." && $file != "..")
					$this->recursive_rmdir($dir . DIRECTORY_SEPARATOR . $file);
			}
			
			$this->currentcount++;
			$this->percentage += $this->increment;
			
			if( round( $this->percentage ) > $this->last_percentage ) {
				$this->last_percentage = round( $this->percentage );
				$this->write_status_file( $this->last_percentage, sprintf( __("Deleting %s...", $this->textdomain), realpath($dir) ) );
			}

			$this->write_log_file( sprintf( __("Deleting %s...", $this->textdomain), realpath($dir) ) );
			
			rmdir($dir);
		} else if (file_exists($dir)) {
			$this->currentcount++;
			$this->percentage += $this->increment;
			
			if( round( $this->percentage ) > $this->last_percentage ) {
				$this->last_percentage = round( $this->percentage );
				$this->write_status_file( $this->last_percentage, sprintf( __("Deleting %s...", $this->textdomain), realpath($dir) ) );
			}

			$this->write_log_file( sprintf( __("Deleting %s...", $this->textdomain), realpath($dir) ) );
			unlink($dir);
		}
	} 
	//**************************************************************************************
	// WP Files Archive
	//**************************************************************************************
	private function files_archive($source_dir, $files, $zip_file) {
		if (!$this->can_user_backup())
			throw new Exception(__('Could not backup!', $this->textdomain));

		if (file_exists($zip_file))
			@unlink($zip_file);

		$wp_dir    = basename($this->wp_dir) . DIRECTORY_SEPARATOR;
		$dump_file = basename($this->dump_file);

		try {
			$dump_file  = $source_dir . DIRECTORY_SEPARATOR . $dump_file;
			$source_dir = $source_dir . DIRECTORY_SEPARATOR . $wp_dir;

			if (class_exists('ZipArchive')) {
				$zip = new ZipArchive;
				if ( $zip->open($zip_file, ZipArchive::CREATE) === TRUE ) {
					$zip->addEmptyDir($parent);
					foreach ($files as $file) {
						$this->currentcount++;
						$this->percentage += $this->increment;
						
						if( round( $this->percentage ) > $this->last_percentage ) {
							$this->last_percentage = round( $this->percentage );
							$this->write_status_file( $this->last_percentage, sprintf( __("Archiving %s...", $this->textdomain), realpath($file) ) );
						}
					
						$this->write_log_file( sprintf( __("Archiving %s...", $this->textdomain), realpath($file) ) );
					
						if ( !is_dir($source_dir.$file) )
							$zip->addFile($source_dir.$file, $wp_dir.$file);
					}

					if (file_exists($dump_file)) {
						$this->write_status_file( $this->last_percentage, __("Archiving SQL dump...", $this->textdomain) );
						$zip->addFile($dump_file, basename($this->dump_file));
					}

					$zip->close();
				} else {
					throw new Exception(__('Could not open the backup file for writing!', $this->textdomain));
				}

			} else {
				if (!class_exists('PclZip'))
					require_once 'class-pclzip.php';

				$zip = new PclZip($zip_file);
				$backup_files = array();
				foreach ($files as $file) {
					$this->currentcount++;
					$this->percentage += $this->increment;
					
					if( round( $this->percentage ) > $this->last_percentage ) {
						$this->last_percentage = round( $this->percentage );
						$this->write_status_file( $this->last_percentage, sprintf( __("Archiving %s...", $this->textdomain), realpath($file) ) );
					}

					$this->write_log_file( sprintf( __("Archiving %s...", $this->textdomain), realpath($file) ) );
					
					if ( !is_dir($source_dir.$file) )
						$backup_files[] = $source_dir.$file;

					if (count($backup_files) > self::ROWS_PER_SEGMENT) {
						$zip->add(implode(',', $backup_files), PCLZIP_OPT_REMOVE_PATH, $this->wp_dir, PCLZIP_OPT_ADD_PATH, $wp_dir);
						$backup_files = array();
					}
				}
				if (count($backup_files) > 0) {
					$zip->add(implode(',', $backup_files), PCLZIP_OPT_REMOVE_PATH, $this->wp_dir, PCLZIP_OPT_ADD_PATH, $parent);
				}

				if (file_exists($dump_file)) {
					$this->write_status_file( $this->last_percentage, __("Archiving SQL dump...", $this->textdomain) );
					$this->write_log_file( sprintf( __("Archiving SQL dump...", $this->textdomain), realpath($file) ) );
					
					$zip->add($dump_file, PCLZIP_OPT_REMOVE_PATH, dirname($this->dump_file));
				}
			}
		} catch(Exception $e) {
			throw new Exception($e->getMessage());
		}

		if (file_exists($zip_file)) {
			chmod($zip_file, 0600);
			return $zip_file;
		} else {
			throw new Exception(__('Could not open the backup file for writing!', $this->textdomain));
		}
	}

	//**************************************************************************************
	// Better addslashes for SQL queries.
	// Taken from phpMyAdmin.
	//**************************************************************************************
	private function sql_addslashes($a_string = '', $is_like = false) {
		if ($is_like) $a_string = str_replace('\\', '\\\\\\\\', $a_string);
		else $a_string = str_replace('\\', '\\\\', $a_string);
		return str_replace('\'', '\\\'', $a_string);
	} 

	//**************************************************************************************
	// Add backquotes to tables and db-names in
	// SQL queries. Taken from phpMyAdmin.
	//**************************************************************************************
	private function backquote($a_name) {
		if (!empty($a_name) && $a_name != '*') {
			if (is_array($a_name)) {
				$result = array();
				reset($a_name);
				while(list($key, $val) = each($a_name)) 
					$result[$key] = '`' . $val . '`';
				return $result;
			} else {
				return '`' . $a_name . '`';
			}
		} else {
			return $a_name;
		}
	} 

	//**************************************************************************************
	// Get WP core tables
	//**************************************************************************************
	private function get_core_tables() {
		global $table_prefix, $wpdb;

		$core_tables = array();
		$table_prefix = isset( $table_prefix ) ? $table_prefix : $wpdb->prefix;
		$tables = $wpdb->get_col('SHOW TABLES');
		$pattern = '/^'. preg_quote($table_prefix,'/') . '/i';
		foreach ( $tables as $table ) {
			if ( preg_match( $pattern, $table ) )
				$core_tables[] = $table;
		}
		sort($core_tables, SORT_STRING);

		return $core_tables;
	}

	//**************************************************************************************
	// WP DataBase Backup
	//**************************************************************************************
	private function wpdb_dump($path = FALSE, $pre = FALSE, $core_tables = FALSE) {
		global $wpdb;

		if (!$this->can_user_backup())
			return FALSE;

		// get dump file name
		$file_path = $this->chg_directory_separator($path === FALSE ? $this->wp_dir : $path, FALSE);
		$file_name = $this->chg_directory_separator(
			$file_path .
			untrailingslashit($pre === FALSE ? 'dump.' : str_replace(DIRECTORY_SEPARATOR, '-', untrailingslashit($pre))) .
			date('Ymd.His') . '.sql',
			FALSE);
		if (!is_writable($file_path))
			return FALSE;

		// get core tables
		$core_tables =
			$core_tables === FALSE
			? $this->get_core_tables()
			: (array)$core_tables
			;
		$this->core_tables = $core_tables;

		$fp = @fopen($file_name, 'w');
		if($fp) {
			//Begin new backup of MySql
			$this->fwrite($fp, "# " . __('WordPress MySQL database backup', $this->textdomain) . "\n");
			$this->fwrite($fp, "#\n");
			$this->fwrite($fp, "# " . sprintf(__('Generated: %s', $this->textdomain), date("l j. F Y H:i T")) . "\n");
			$this->fwrite($fp, "# " . sprintf(__('Hostname: %s', $this->textdomain),  DB_HOST) . "\n");
			$this->fwrite($fp, "# " . sprintf(__('Database: %s', $this->textdomain),  $this->backquote(DB_NAME)) . "\n");
			$this->fwrite($fp, "# --------------------------------------------------------\n");

			// backup tables
			foreach ($core_tables as $table) {
				$this->table_dump($fp, $table);
			}
			fclose($fp);
		} else {
			$this->error[] = __('Could not open the db dump file for writing!', $this->textdomain);
		}

		if (file_exists($file_name)) {
			chmod($file_name, 0600);
			return $file_name;
		} else {
			return FALSE;
		}
	}

	//**************************************************************************************
	// Write to the dump file
	//**************************************************************************************
	function fwrite($fp, $query_line) {
		if(false === @fwrite($fp, $query_line))
			$this->error[] = __('There was an error writing a line to the backup script:',  $this->textdomain) . '  ' . $query_line . '  ' . $php_errormsg;
	}

	//**************************************************************************************
	// table dump
	//**************************************************************************************
	private function table_dump($fp, $table) {
		global $table_prefix, $wpdb;

		if( !$fp || empty($table) )
			return FALSE;

		$this->write_log_file( sprintf( __("Processing %s...", $this->textdomain), $table ) );

		// Increase script execution time-limit to 15 min.
		if ( !ini_get('safe_mode'))
			@set_time_limit(self::TIME_LIMIT);

		// Create the SQL statements
		$this->fwrite($fp, "# --------------------------------------------------------\n");
		$this->fwrite($fp, "# " . sprintf(__('Table: %s', $this->textdomain),$this->backquote($table)) . "\n");
		$this->fwrite($fp, "# --------------------------------------------------------\n");

		// Get Table structure
		$table_structure = $wpdb->get_results("DESCRIBE $table");
		if ( !$table_structure ) {
			$this->error[] = __('Error getting table details', $this->textdomain) . ': $table';
			return FALSE;
		}

		// Add SQL statement to drop existing table
		$this->fwrite($fp, "\n\n");
		$this->fwrite($fp, "#\n");
		$this->fwrite($fp, "# " . sprintf(__('Delete any existing table %s', $this->textdomain), $this->backquote($table)) . "\n");
		$this->fwrite($fp, "#\n");
		$this->fwrite($fp, "\n");
		$this->fwrite($fp, "DROP TABLE IF EXISTS " . $this->backquote($table) . ";\n");

		// Table structure
		$this->fwrite($fp, "\n\n");
		$this->fwrite($fp, "#\n");
		$this->fwrite($fp, "# " . sprintf(__('Table structure of table %s', $this->textdomain), $this->backquote($table)) . "\n");
		$this->fwrite($fp, "#\n");
		$this->fwrite($fp, "\n");

		$sql = "SHOW CREATE TABLE $table";
		$pkey = '';
		if ( ($create_table = $wpdb->get_results($sql, ARRAY_N)) !== FALSE ) {
			$this->fwrite($fp, $create_table[0][1] . ' ;');
			$this->fwrite($fp, "\n\n");
			$this->fwrite($fp, "#\n");
			$this->fwrite($fp, '# ' . sprintf(__('Data contents of table %s', $this->textdomain),$this->backquote($table)) . "\n");
			$this->fwrite($fp, "#\n");
			if ( preg_match('/PRIMARY KEY \(([^\)]*)\)/i', $create_table[0][1], $matches) ) {
				$pkey = $matches[1];
			}
		} else {
			$err_msg = sprintf(__('Error with SHOW CREATE TABLE for %s.', $this->textdomain), $table);
			$this->error[] = $err_msg;
			$this->fwrite($fp, "#\n# $err_msg\n#\n");
			$err_msg = sprintf(__('Error getting table structure of %s', $this->textdomain), $table);
			$this->error[] = $err_msg;
			$this->fwrite($fp, "#\n# $err_msg\n#\n");
		}

		$defs = array();
		$ints = array();
		foreach ($table_structure as $struct) {
			$type = strtolower($struct->Type);
			if ( (0 === strpos($type, 'tinyint')) || (0 === strpos($type, 'smallint')) || (0 === strpos($type, 'mediumint')) || (0 === strpos($type, 'int')) || (0 === strpos($type, 'bigint')) ) {
				$defs[strtolower($struct->Field)] = ( null === $struct->Default ) ? 'NULL' : $struct->Default;
				$ints[strtolower($struct->Field)] = "1";
			}
		}

		// Batch by $row_inc
		$segment = 0;
		$table_data = array();
		do {
			$row_inc = self::ROWS_PER_SEGMENT;
			$row_start = $segment * self::ROWS_PER_SEGMENT;

			// spam or rivision excluded
			$where = '';
			if ( preg_match('/comments$/i', $table) ) {
				$where = ' WHERE comment_approved != "spam"';
			} elseif ( preg_match('/posts$/i', $table) ) {
				$where = ' WHERE post_type != "revision"';
			}

			$sql = "SELECT * FROM $table $where";
			if ( !empty($pkey) ) {
				$sql .= " ORDER BY $pkey";
			}
			$sql .= " LIMIT {$row_start}, {$row_inc}";

			$this->fwrite($fp, "\n# $sql \n");

			// get table data
			if ( ($table_data = $wpdb->get_results($sql, ARRAY_A)) !== FALSE ) {
				//    \x08\\x09, not required
				$search = array("\x00", "\x0a", "\x0d", "\x1a");
				$replace = array('\0', '\n', '\r', '\Z');

				if( count($table_data) > 0 ) {
					$entries = 'INSERT INTO ' . $this->backquote($table) . ' VALUES (';	
					foreach ($table_data as $row) {
						$this->currentcount++;
						$this->percentage += $this->increment;
						
						if( round( $this->percentage ) > $this->last_percentage ) {
							$this->last_percentage = round( $this->percentage );
							$this->write_status_file( $this->last_percentage, sprintf( __("Processing %s...", $this->textdomain), $table ) );
						}

						$values = array();
						foreach ($row as $key => $value) {
							if (isset($ints[strtolower($key)]) && $ints[strtolower($key)]) {
								$value = ( null === $value || '' === $value) ? $defs[strtolower($key)] : $value;
								$values[] = ( '' === $value ) ? "''" : $value;
							} else {
								$values[] = "'" . str_replace($search, $replace, $this->sql_addslashes($value)) . "'";
							}
						}
						$this->fwrite($fp, " \n" . $entries . implode(', ', $values) . ');');
					}
				}
			}
			$segment++;
		} while((count($table_data) > 0) || ($segment === 0));

		// Create footer/closing comment in SQL-file
		$this->fwrite($fp, "\n");
		$this->fwrite($fp, "#\n");
		$this->fwrite($fp, "# " . sprintf(__('End of data contents of table %s', $this->textdomain),$this->backquote($table)) . "\n");
		$this->fwrite($fp, "# --------------------------------------------------------\n");
		$this->fwrite($fp, "\n");

		return TRUE;
	}

	//**************************************************************************************
	// get backup files
	//**************************************************************************************
	public function get_backup_files() {
		$scan_pattern = '/^' . preg_quote($this->archive_pre, '/') . '.*\.zip$/i';
		$files = array_reverse(scandir($this->archive_path));
		$backup_files = array();
		foreach ($files as $file) {
			if (preg_match($scan_pattern, $file)) {
				$backup_files[] = $this->archive_path . $file;
			}
		}
		return $backup_files;
	}

	//**************************************************************************************
	// backup files info
	//**************************************************************************************
	public function backup_files_info($nonces = FALSE, $page = FALSE, $backup_files = FALSE) {
		if (!$backup_files)
			$backup_files = $this->get_backup_files();

		$backup_files_info = array();
		if (count($backup_files) > 0) {
			foreach ((array)$backup_files as $backup_file) {
				if (file_exists($backup_file)) {
					$filemtime = $this->get_filemtime($backup_file);
					if (!$nonces)
						$nonces = '&nonce=' . $this->create_nonce_no_logged_in();
					$query =
						$page
						? "?page={$page}&download=" . rawurlencode($backup_file) . $nonces
						: '?download=' . rawurlencode($backup_file) . $nonces ;
					$url = sprintf(
						'<a href="%1$s" title="%2$s">%2$s</a>' ,
						(is_admin() ? '' : trailingslashit(function_exists('home_url') ? home_url() : get_option('home'))) . $query, 
						esc_html(basename($backup_file))
						);
					$filesize = (int)sprintf('%u', filesize($backup_file)) / 1024 / 1024;

					$log_file = str_replace( '.zip', '.log', $backup_file );
					if (file_exists($log_file)) {
						$logquery =
							$page
							? "?page={$page}&download=" . rawurlencode($log_file) . $nonces
							: '?download=' . rawurlencode($log_file) . $nonces ;
						$logurl = sprintf(
							'<a href="%1$s" title="log">log</a>' ,
							(is_admin() ? '' : trailingslashit(function_exists('home_url') ? home_url() : get_option('home'))) . $logquery, 
							esc_html(basename($log_file))
							);
					} else {
						$logurl = '';
					}
					
					$backup_files_info[] = array(
						'filename'  => $backup_file ,
						'filemtime' => $filemtime ,
						'filesize'  => $filesize ,
						'url'       => $url ,
						'logurl' 	=> $logurl,
						);
				}
			}
		}
		return $backup_files_info;
	}
	public function wp_backup_files_info() {
		return array('backup_files' => $this->backup_files_info());
	}
}

endif;