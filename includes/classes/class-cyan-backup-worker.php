<?php
if( !class_exists( 'CYAN_WP_Backuper' ) ) :

class CYAN_Backup_Worker {
	private $wp_dir;
	private $archive_path;
	private $archive_pre;
	private $archive_file;
	private $default_excluded = array(
										'wp-content/cache/',
										'wp-content/tmp/',
										'wp-content/upgrade/',
									);

	private $dump_file;
	private $core_tables = array();
	private $files = array();
	private $statuslogfile = null;
	private $logfile = null;
	private $currentcount = 0;
	private $increment = 0;
	private $percentage = 0;
	private $last_percentage = 0;
	private $email_sendto = null;
	private $option = array();
	private $Utils;

	private $error = array();

	const ROWS_PER_SEGMENT = 100;
	const TIME_LIMIT       = 900;		// 15min * 60sec
	const EXCLUSION_KEY    = 'CYAN_WP_Backuper::wp_backup';
	const OPTION_NAME      = 'CYAN Backup Option';

	//**************************************************************************************
	// Constructor
	//**************************************************************************************
	function __construct( $archive_path = FALSE, $archive_prefix = FALSE, $wp_dir = FALSE, $excluded = FALSE, $utils = FALSE, $options = FALSE ) {
		$this->Utils = $utils;
		$this->options = $options;
		
		if( $archive_path === FALSE && isset( $this->options['archive_path'] ) && is_dir( $this->options['archive_path'] ) ) {
			$archive_path = $this->options['archive_path'];
		}
		
		if( $excluded === FALSE && isset( $this->options['excluded'] ) && is_array( $this->options['excluded'] ) ) {
			$excluded = (array)$this->options['excluded'];
		}

		$this->archive_path = $this->get_archive_path( $archive_path );
		$this->archive_pre  = $this->get_archive_prefix( $archive_prefix );
		$this->wp_dir       = $this->Utils->get_wp_dir( $wp_dir );
		$this->archive_file = FALSE;
		$this->excluded     = array_merge(
											array(
													'.'.DIRECTORY_SEPARATOR ,
													'..'.DIRECTORY_SEPARATOR ,
												),
											$this->get_excluded_dir( $excluded )
										);

		if( !array_key_exists( 'emaillog', $this->option ) ) { 
			$this->options['emaillog'] = 'off'; 
		}
		
		if( $this->options['emaillog'] == 'on' ) {
			$this->email_sendto = $this->options['sendto'];
		}
	}

	//**************************************************************************************
	// Utility
	//**************************************************************************************

	// get archive path
	private function get_archive_path( $archive_path = NULL ) {
		if( NULL == $archive_path && defined( 'ABSPATH' ) ) {
			$archive_path = dirname( ABSPATH );
		} else {
			$archive_path = sys_get_temp_dir();
		}

		return $this->Utils->chg_directory_separator( trailingslashit( $archive_path ), FALSE );
	}

	// get excluded dir
	private function get_excluded_dir( $excluded = NULL ) {
		if( !is_array( $excluded ) ) {
			$excluded = $this->default_excluded;
		}
		
		return $this->Utils->chg_directory_separator( $excluded, FALSE );
	}

	// get archive prefix
	private function get_archive_prefix( $archive_prefix = NULL ) {
		if( $archive_prefix ) { 
			$archive_prefix = str_replace( DIRECTORY_SEPARATOR, '-', untrailingslashit( $archive_prefix ) );
		} else {
			$archive_prefix = basename(ABSPATH) . '.';
		}

		return $archive_prefix;
	}

	// set transient
	private function set_transient( $key, $value, $expiration = 0 ) {
		return set_transient( $key, $value, $expiration );
	}

	// get transient
	private function get_transient( $key ) {
		return get_transient( $key );
	}

	// delete_transient
	private function delete_transient( $key ) {
		return delete_transient( $key );
	}

	// verify nonce if no logged in
	private function verify_nonce_no_logged_in( $nonce, $action = -1 ) {
		$i = wp_nonce_tick();

		// Nonce generated 0-12 hours ago
		if( substr( wp_hash( $i . $action, 'nonce' ), -12, 10 ) == $nonce ) {
			return 1;
		}
		
		// Nonce generated 12-24 hours ago
		if( substr( wp_hash( ( $i - 1 ) . $action, 'nonce' ), -12, 10 ) == $nonce ) {
			return 2;
		}
		
		// Invalid nonce
		return false;
	}

	// create nonce if no logged in
	private function create_nonce_no_logged_in( $action = -1 ) {
		$i = wp_nonce_tick();
		
		return substr( wp_hash( $i . $action, 'nonce' ), -12, 10 );
	}

	private function email_log_file( $addresses, $filename, $status ) {
		$blogname = get_bloginfo( 'name' );
		$blogemail = get_bloginfo( 'admin_email' );
		
		if( trim( $addresses ) == '' ) { 
			$addresses = $blogemail; 
		}
		
		$headers[] = "From: $blogname <$blogemail>";
		$headers[] = "MIME-Version: 1.0";
		$headers[] = "Content-type: text/html; charset=utf-8";
		
		$body  = __( 'Please find attached the backup log file for your reference.' ) . "\r\n";
		$body .= "\r\n";
		
		if( is_array( $status ) ) {
			foreach( $status as $key => $value ) {
				$body .= "\t$key: $value\r\n";
			}
		} else {
			$body .= $status . "\r\n";
		}

		wp_mail( $addresses, __( 'CYAN Backup Log', 'cyan-backup' ), $body, $headers, $filename );
	}
	
	//**************************************************************************************
	// Get the total number of rows in the WordPress tables we're going to backup.
	//**************************************************************************************
	private function get_sql_row_count() {
		GLOBAL $wpdb;
		
		// get core tables
		$core_tables = $this->get_core_tables();
		$row_count = 0;

		// Count the total number of rows in the tables.
		foreach( $core_tables as $table ) {
			$row_count += $wpdb->get_var( "SELECT count(*) FROM `{$table}`" );
		}
		
		return $row_count;
	}

	private function write_status_file( $percentage, $message, $state = 'active' ) {
		if( $this->statuslogfile == null ) { 
			return; 
		}
	
		$status_file = fopen( $this->statuslogfile, 'w' );

		if( $status_file !== FALSE ) {
			fwrite( $status_file, $percentage . "\n" );
			fwrite( $status_file, $message . "\n" );
			fwrite( $status_file, $state . "\n" );
			fwrite( $status_file, realpath( $this->archive_file ). "\n" );
			
			if( $state == 'complete' ) {
				fwrite( $status_file, $this->get_filemtime( $this->archive_file ) . "\n" );
				fwrite( $status_file, ( filesize( $this->archive_file ) / 1024 / 1024 ) . "\n");
			} else {
				fwrite( $status_file, date( 'Y-m-d H:i:s', time() ) );
				fwrite( $status_file, '0' );
			}
			
			fclose( $status_file );	
		}
	}

	private function open_log_file( $name ) {
		if( $this->logfile == null ) {
			$this->logfile = fopen( $name, 'a' );
		}
	}
	
	private function write_log_file( $message ) {
		if( $this->logfile != null ) {
			fwrite( $this->logfile, '[' . date( 'Y-m-d H:i:s' ) . '] ' . $message . "\n" );
		}
	}
	
	private function close_log_file() {
		if( $this->logfile != null ) {
			fclose( $this->logfile );
			$this->logfile = null;
		}
	}

	//**************************************************************************************
	// WP Backup
	//**************************************************************************************
	public function wp_backup( $db_backup = TRUE ) {

	    $this->set_transient( self::EXCLUSION_KEY, TRUE );
		
		if( $this->get_transient( self::EXCLUSION_KEY ) === false ) {
			$this->error[] = __( 'Could not set transient!', 'cyan-backup' );

			return array(
							'result'    => FALSE ,
							'errors'    => $this->error ,
						);
		}

		if( !$this->can_user_backup() ) {
			$this->error[] = __( 'User does not have rights to backup!', 'cyan-backup' );

			return array(
							'result'    => FALSE ,
							'errors'    => $this->error ,
						);
		}

		try {
		    $this->set_transient( self::EXCLUSION_KEY, TRUE );

			// Increase script execution time-limit to 15 min.
			if( !ini_get( 'safe_mode' ) )
				set_time_limit( self::TIME_LIMIT );

			$archive_path   = $this->get_archive_path( $this->archive_path );
			$archive_prefix = $this->get_archive_prefix( $this->archive_pre );
			$filename       = $archive_prefix . date( 'Ymd.His' );

			$active_filename = $archive_path . 'backup.active';
			if( file_exists( $active_filename ) ) {
				$active_filetime = strtotime( $this->get_filemtime( $active_filename ) );
				
				// Check to see if the active state is stale ( >30 minutes old )
				if( time() - $active_filetime > (60 * 10) ) {
					unlink( $active_filename );
				} else {
					$this->error[] = __( 'Another backup is already running!', 'cyan-backup' );
					return array(
									'result'    => FALSE ,
									'errors'    => $this->error ,
								);
				}
			} 

			if( $this->options['disabledbbackup'] == true ) {
				$db_backup = FALSE;
			}
			
			// Create a semaphore file to indicate we're active.
			$active_backup = fopen( $active_filename, 'w' );
			fwrite( $active_backup, "placeholder\n" );
			fclose( $active_backup );
			
			$this->statuslogfile = $archive_path . 'status.log';
			$this->write_status_file( 0, __( 'Calculating backup size...', 'cyan-backup' ) );

			$backup_start = time();
			$this->open_log_file( $archive_path . $filename . '.log' );
			$this->write_log_file( __( 'Calculating backup size...', 'cyan-backup' ) );

			$sqlrowcount = 0;
			// get SQL rows.
			if( $db_backup ) {
				$sqlrowcount = $this->get_sql_row_count();
			}
			
			// get files
			$this->files = $files = $this->get_files( $this->wp_dir, $this->excluded );
			$filecount = count( $files );
			
			// Total count is the sqlrowcount + once through the file tree
			$total_count = $sqlrowcount + $filecount;
			
			$this->increment = 100 / $total_count;

			$this->write_status_file( 0, __( 'Backup started, processing SQL tables...', 'cyan-backup' ) );
			$this->write_log_file( __( 'Backup started, processing SQL tables...', 'cyan-backup' ) );

			// DB backup
			if( $db_backup ) {
				$this->dump_file = $this->wpdb_dump($archive_path, $archive_prefix);
			}

			$this->write_status_file( $this->last_percentage, __( 'Archiving files...', 'cyan-backup' ) );
			$this->write_log_file( __( 'Archiving files...', 'cyan-backup' ) );

			// WP Core files archive
			$archive_file = $this->Utils->chg_directory_separator( trailingslashit( $archive_path ) . $filename . $this->GetArchiveExtension() );
			$backup = $this->files_archive( $this->wp_dir, $files, $archive_file );

			$this->write_status_file( $this->last_percentage, __( 'Removing temporary files...', 'cyan-backup' ) );
			$this->write_log_file( __( 'Removing temporary files...', 'cyan-backup' ) );

			// If we successfully created the backupfile, save it's name to the class GLOBALs.
			if ( file_exists($backup) ) {
				$this->archive_file = $backup;
			} else {
				$this->archive_file = FALSE;
			}

			// Remove DB backup files
			if ( $db_backup ) {
				if( is_array( $this->dump_file ) ) {
					foreach( $this->dump_file as $dumpfile ) {
						if( file_exists( $dumpfile ) ) {
							unlink( $dumpfile );
						}
					}
				} else {
					unlink( $this->dump_file );
				}
			}
			
			$this->delete_transient( self::EXCLUSION_KEY );

			$backup_elapsed = time() - $backup_start;
			$backup_quantum = __( 'seconds', 'cyan-backup' );
			if( $backup_elapsed > 60 ) {
				$backup_elapsed = round( $backup_elapsed / 60, 1 );
				$backup_quantum = __( 'minutes', 'cyan-backup' );
			} else if( $backup_elapsed > 3600 ) {
				$backup_elapsed = round( $backup_elapsed / 3600, 1 );
				$backup_quantum = __( 'hours', 'cyan-backup' );
			}
			
			$this->write_log_file( __( 'Elapsed Time', 'cyan-backup' ) . ': ' . $backup_elapsed . ' ' . $backup_quantum );
			
			if( count( $this->error ) > 0 )	{
				$this->write_status_file( 100, __( 'ERROR:', 'cyan-backup' ) . implode( '<br>', $this->error ), 'error' );
				$this->write_log_file( __( 'ERROR:', 'cyan-backup' ) . implode( ' - ', $this->error ) );
				$this->statuslogfile = null;
				
				$this->close_log_file();

				if( $this->email_sendto !== null ) { $this->email_log_file( $this->email_sendto, $archive_path . $filename . '.log', $this->error ); }
			} else {
				$this->write_status_file( 100, __( 'Backup complete!', 'cyan-backup' ), 'complete' );
				$this->write_log_file( __( 'Backup complete!', 'cyan-backup' ) );
				$this->write_log_file( __( 'Backup size', 'cyan-backup' ) . ': ' . round( filesize( $this->archive_file ) / 1024 / 1024, 2 ) . 'MB' );
				$this->statuslogfile = null;

				$this->close_log_file();

				if( $this->email_sendto !== null ) { 
					$this->email_log_file( $this->email_sendto, $archive_path . $filename . '.log', __( 'Backup complete!', 'cyan-backup' ) ); 
				}
			}
			
			unlink( $active_filename );
			
			$result = array( 'backup' => FALSE, 'db_backup' => FALSE, 'errors' => $this->error );
			
			if( $backup && file_exists( $backup ) ) {
				$result['backup'] = $this->archive_file;
			}
			
			if( $db_backup ) {
				$result['db_backup'] = TRUE;
			}
			
			return $result;

		} catch( Exception $e ) {
			$this->delete_transient( self::EXCLUSION_KEY );
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
	public function can_user_backup( $loc = 'main' ) {
		if( current_user_can( 'manage_options' ) ) {
			return TRUE;
		}
		
		return FALSE;
	}

	//**************************************************************************************
	// Get All WP Files
	//**************************************************************************************
	private function get_files( $dir, $excluded, $pre = '' ) {
		$result = array();
		
		if( file_exists( $dir ) && is_dir( $dir ) ) {
			if( $dh = opendir( $dir ) ) {
				$file = readdir( $dh );
				
				while( $file !== false ) {
					if( is_dir( $dir . $file ) ) {
						if( $file == '.' || $file == '..' ) { 
							continue; 
						}
						
						$file .= DIRECTORY_SEPARATOR;
						
						$result[] = $pre . $file;
						
						if( !in_array( $pre . $file, $excluded ) ) {
							$result = array_merge( $result, $this->get_files( $dir . $file, $excluded, $pre . $file ) );
						}
					} else if( !in_array( $pre . $file, $excluded ) ) {
						if( !( substr( $file, 0, 7 ) == 'pclzip-' && substr( $file, -4 ) == '.tmp' ) ) {
							$result[] = $pre . $file;
						}
					}
				}
				
				closedir( $dh );
			}
		}
		
		return $result;
	}

	//**************************************************************************************
	// WP Files Backup
	//**************************************************************************************
	private function files_backup( $source_dir, $files, $dest_dir ) {
		if( !$this->can_user_backup() ) {
			$this->write_log_file( __( 'Could not backup!', 'cyan-backup' ) );
			throw new Exception( __( 'Could not backup!', 'cyan-backup' ) );
		}
		
		try {
			$dest_dir = trailingslashit( $dest_dir );
			if( file_exists( $this->dump_file ) ) {
				copy( $this->dump_file, $dest_dir . basename( $this->dump_file ) );
			}

			$dest_dir = trailingslashit( $dest_dir . basename( $source_dir ) );
			$dest_dir = $this->Utils->chg_directory_separator( $dest_dir );
			if( !file_exists( $dest_dir ) ) {
				mkdir($dest_dir, 0700);
			}
				
			if( !is_writable( $dest_dir ) ) {
				$this->write_log_file( __( 'Could not open the destination directory for writing!', 'cyan-backup' ) );
				throw new Exception( __( 'Could not open the destination directory for writing!', 'cyan-backup' ) );
			}
			
			$source_dir = $this->Utils->chg_directory_separator( trailingslashit( $source_dir ) );

			foreach( $files as $file ) {
				$this->currentcount++;
				$this->percentage += $this->increment;
				
				if( round( $this->percentage ) > $this->last_percentage ) {
					$this->last_percentage = round( $this->percentage );
					$this->write_status_file( $this->last_percentage, sprintf( __( 'Copying %s...', 'cyan-backup' ), realpath( $file ) ) );
				}

				$this->write_log_file( sprintf( __( 'Copying %s...', 'cyan-backup' ), realpath( $file ) ) );
				
				if( is_dir( $source_dir . $file ) ) {
					if( !file_exists( $dest_dir . $file ) )
						mkdir( $dest_dir . $file );
				} else {
					copy( $source_dir . $file, $dest_dir . $file );
				}
			}
		} catch( Exception $e ) {
			$this->write_log_file( $e->getMessage() );
			throw new Exception( $e->getMessage() );
		}

		return TRUE;
	}

	private function recursive_rmdir( $dir ) {
		if( is_dir( $dir ) ) {
			$files = scandir( $dir );
			
			foreach($files as $file ) {
				if( $file != '.' && $file != '..' ) {
					$this->recursive_rmdir( $dir . DIRECTORY_SEPARATOR . $file );
				}
			}
			
			$this->currentcount++;
			$this->percentage += $this->increment;
			
			if( round( $this->percentage ) > $this->last_percentage ) {
				$this->last_percentage = round( $this->percentage );
				$this->write_status_file( $this->last_percentage, sprintf( __( 'Deleting %s...', 'cyan-backup' ), realpath( $dir ) ) );
			}

			$this->write_log_file( sprintf( __( 'Deleting %s...', 'cyan-backup' ), realpath( $dir ) ) );
			
			rmdir( $dir );
		} else if( file_exists( $dir ) ) {
			$this->currentcount++;
			$this->percentage += $this->increment;
			
			if( round( $this->percentage ) > $this->last_percentage ) {
				$this->last_percentage = round( $this->percentage );
				$this->write_status_file( $this->last_percentage, sprintf( __( 'Deleting %s...', 'cyan-backup' ), realpath( $dir ) ) );
			}

			$this->write_log_file( sprintf( __( 'Deleting %s...', 'cyan-backup' ), realpath( $dir ) ) );
			unlink($dir);
		}
	} 
	
	private function OpenArchiveFile( $filename ) {
		$handle = FALSE;
		
		$zip = new Zip();
		$zip->create( $filename );

		$handle = $zip;
				
		return $handle;
	}
	
	private function AddArchiveFile( $handle, $file, $archive_file = null, $dir_to_strip = null) {
		if( $handle === FALSE ) {
			return;
		}
	
		$handle->addFile( $file, $archive_file );
	}

	private function AddArchiveDir( $handle, $dir ) {
			return;
	}

	private function CloseArchiveFile( $handle ) {
		if( $handle === FALSE ) {
			return;
		}
	
		$handle->close();

		return;
	}
	
	public function GetArchiveExtension() {
		return '.zip';
	}

	//**************************************************************************************
	// WP Files Archive
	//**************************************************************************************
	private function files_archive( $source_dir, $files, $archive_file ) {
		GLOBAL $cyan_backup;
		
		if( !$this->can_user_backup()) {
			$this->write_log_file( __( 'Could not backup!', 'cyan-backup' ) );
			throw new Exception( __( 'Could not backup!', 'cyan-backup' ) );
		}

		if( file_exists( $archive_file ) ) {
			@unlink( $archive_file );
		}

		$wp_dir          = basename( $this->wp_dir ) . DIRECTORY_SEPARATOR;
		$last_time       = time();
		$cur_time        = $last_time;
		$last_count      = $this->currentcount;
		$archive_methods = $cyan_backup->get_archive_methods();
		$archive_method  = $this->options['archive_method'];
		$dir_to_strip    = dirname( $this->wp_dir );
		$artifical_time  = 10;
		$artifical_wait  = 250000;
		
		if( !array_key_exists( $archive_method, $archive_methods) ) {
			$this->write_log_file( __( 'Invalid archive method!', 'cyan-backup' ) );
			throw new Exception( __( 'Invalid archive method!', 'cyan-backup'));
		}

		try {
			$this->write_log_file( __( 'Creating ', 'cyan-backup' ) . $archive_file . '.' );
	
			$archive = $this->OpenArchiveFile( $archive_file );
			
			if( $archive !== FALSE ) {
				foreach( $files as $file ) {
					$this->currentcount++;
					$this->percentage += $this->increment;
					
					$current_file = realpath( $file );

					if( $this->options['artificialdelay'] ) {
						$cur_time = time();
						
						if( $cur_time - $last_time > $artifical_time || $this->currentcount - $last_count > 100) {
							$this->write_log_file( sprintf( __( 'Artificial delay of %.2f sec...', 'cyan-backup' ), $artifical_wait_seconds ) );
							$last_time = $cur_time;
							$last_count = $this->currentcount;
							usleep( $artifical_wait );
						}
					}
				
					if( round( $this->percentage ) > $this->last_percentage ) {
						$this->last_percentage = round( $this->percentage );
						$this->write_status_file( $this->last_percentage, sprintf( __( 'Archiving %s...', 'cyan-backup' ), $current_file ) );
					}
				
					$this->write_log_file( sprintf( __( 'Archiving %s...', 'cyan-backup' ), $current_file ) );
				
					if( is_dir( $current_file ) ) {
						$this->AddArchiveDir( $archive, $current_file );
					} else {
						$this->AddArchiveFile( $archive, $current_file, $wp_dir.$file, $dir_to_strip );
					}
				}

				if( ( !is_array( $this->dump_file ) && file_exists( $this->dump_file ) ) 
					|| ( is_array( $this->dump_file ) && file_exists( $this->dump_file[0] ) ) ) {
					$this->write_log_file( __( 'Archiving SQL dump...', 'cyan-backup' ) );
					$this->write_status_file( $this->last_percentage, __( 'Archiving SQL dump...', 'cyan-backup' ) );

					if( is_array( $this->dump_file ) ) {
						foreach( $this->dump_file as $dumpfile ) {
							$this->AddArchiveFile( $archive, $dumpfile, basename( $dumpfile ), $dir_to_strip );
							if( $this->options['artificialdelay'] ) {
								usleep( $artifical_wait );
							}
						}
					} else {
						$this->AddArchiveFile( $archive, $this->dump_file, basename( $this->dump_file ), $dir_to_strip );
					}
				}

				$this->CloseArchiveFile( $archive );
			} else {
				$this->write_log_file( __( 'Could not create the archive file!', 'cyan-backup' ) );
				throw new Exception(__( 'Could not create the archive file!', 'cyan-backup' ) );
			}
		} catch(Exception $e) {
			$this->write_log_file( $e->getMessage() );
			throw new Exception( $e->getMessage() );
		}

		if (file_exists($archive_file)) {
			$this->write_log_file( __( 'Updating permission on archive file.', 'cyan-backup' ) );

			chmod( $archive_file, 0600 );
			
			return $archive_file;
		} else {
			$this->write_log_file( __( 'Archive file does not exist after the backup is complete!', 'cyan-backup' ) );
			throw new Exception( __( 'Archive file does not exist after the backup is complete!', 'cyan-backup' ) );
		}
	}

	//**************************************************************************************
	// Better addslashes for SQL queries.
	// Taken from phpMyAdmin.
	//**************************************************************************************
	private function sql_addslashes( $a_string = '', $is_like = false ) {
		if( $is_like ) {
			$a_string = str_replace( '\\', '\\\\\\\\', $a_string );
		} else {
			$a_string = str_replace( '\\', '\\\\', $a_string );
		}
		
		return str_replace( '\'', '\\\'', $a_string );
	} 

	//**************************************************************************************
	// Add backquotes to tables and db-names in
	// SQL queries. Taken from phpMyAdmin.
	//**************************************************************************************
	private function backquote( $a_name ) {
		if( !empty( $a_name ) && $a_name != '*' ) {
			if( is_array( $a_name ) ) {
				$result = array();
				
				reset($a_name);
				
				while( list( $key, $val ) = each( $a_name ) ) {
					$result[$key] = '`' . $val . '`';
				}
				
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
		GLOBAL $table_prefix, $wpdb;

		$core_tables = array();
		$table_prefix = $wpdb->prefix;
		$tables = $wpdb->get_col( 'SHOW TABLES' );
		$pattern = '/^'. preg_quote( $table_prefix, '/' ) . '/i';
		
		foreach( $tables as $table ) {
			if( preg_match( $pattern, $table ) ) {
				$core_tables[] = $table;
			}
		}
		sort( $core_tables, SORT_STRING );

		return $core_tables;
	}

	//**************************************************************************************
	// WP DataBase Backup
	//**************************************************************************************
	private function wpdb_dump( $path = FALSE, $pre = FALSE, $core_tables = FALSE ) {
		GLOBAL $wpdb;

		if( !$this->can_user_backup() ) {
			return FALSE;
		}

		// get core tables
		if( $core_tables === FALSE ) {
			$core_tables = $this->get_core_tables();
		} else {
			$core_tables = (array)$core_tables;
		}
		
		$this->core_tables = $core_tables;

		if( $path === FALSE ) {
			$path = $this->wp_dir;
		}
		
		$file_path   = $this->Utils->chg_directory_separator( $path, FALSE);
		
		if( $pre === FALSE ) {
			$pre = 'dump.';
		} else {
			$pre = str_replace( DIRECTORY_SEPARATOR, '-', untrailingslashit( $pre ) );
		}
		
		$file_prefix = untrailingslashit( $pre );

		$artifical_time = 10;
		$artifical_wait = 250000;
		
		if( $this->options['lowiomode'] ) {
			$artifical_time = 1;
			$artifical_wait = 1000000;
			$this->options['artificialdelay'] = 'on';
		}
		
		$artifical_wait_seconds = $artifical_wait / 1000000;

		
		if( $this->options['splitdbbackup'] == true ) {
			$sqlfiles = array();
		
			foreach( $core_tables as $table ) {
				$file_name = $file_path . $this->Utils->chg_directory_separator( $file_prefix . $table . '.' . date( 'Ymd.His' ) . '.sql', FALSE );
				
				$fp = @fopen( $file_name, 'w' );
				if( $fp ) {
					//Begin new backup of MySql
					$this->sql_export_headers( $fp );
					
					// backup table
					$this->table_dump( $fp, $table );
					
					fclose( $fp );

					chmod( $file_name, 0600 );
					
					$sqlfiles[] = $file_name;
				} else {
					$this->error[] = __( 'Could not open the db dump file for writing!', 'cyan-backup' );
				}
				
				if( $this->options['artificialdelay'] ) {
					usleep( $artifical_wait );
				}
			}
			
			return $sqlfiles;
		} else {
			// get dump file name
			$file_name = $file_path . $this->Utils->chg_directory_separator( $file_prefix . date( 'Ymd.His' ) . '.sql', FALSE );

			if( !is_writable( $file_path ) ) {
				return FALSE;
			}
			
			$fp = @fopen( $file_name, 'w' );
			if( $fp ) {
				//Begin new backup of MySql
				$this->sql_export_headers( $fp );
				
				// backup tables
				foreach ($core_tables as $table) {
					$this->table_dump( $fp, $table );
					
					if( $this->options['artificialdelay'] ) {
						usleep( $artifical_wait );
					}
				}
				
				fclose( $fp );
			} else {
				$this->error[] = __( 'Could not open the db dump file for writing!', 'cyan-backup' );
			}

			if( file_exists( $file_name ) ) {
				chmod( $file_name, 0600 );
				return $file_name;
			} 
		}
		
	return FALSE;
	}

	private function sql_export_headers( $fp ) {
		$this->fwrite( $fp, "# " . __('WordPress MySQL database backup', 'cyan-backup') . "\n" );
		$this->fwrite( $fp, "#\n" );
		$this->fwrite( $fp, "# " . sprintf(__('Generated: %s', 'cyan-backup'), date("l j. F Y H:i T")) . "\n" );
		$this->fwrite( $fp, "# " . sprintf(__('Hostname: %s', 'cyan-backup'),  DB_HOST) . "\n" );
		$this->fwrite( $fp, "# " . sprintf(__('Database: %s', 'cyan-backup'),  $this->backquote(DB_NAME)) . "\n" );
		$this->fwrite( $fp, "# --------------------------------------------------------\n" );
	}
	
	//**************************************************************************************
	// Write to the dump file
	//**************************************************************************************
	function fwrite( $fp, $query_line ) {
		if( false === @fwrite( $fp, $query_line ) ) {
			$this->error[] = __( 'There was an error writing a line to the backup script:',  'cyan-backup' ) . '  ' . $query_line . '  ' . $php_errormsg;
		}
	}

	//**************************************************************************************
	// table dump
	//**************************************************************************************
	private function table_dump( $fp, $table ) {
		GLOBAL $table_prefix, $wpdb;

		if( !$fp || empty($table) ) {
			return FALSE;
		}

		$this->write_log_file( sprintf( __( 'Processing %s...', 'cyan-backup' ), $table ) );

		// Increase script execution time-limit to 15 min.
		if( !ini_get( 'safe_mode' ) ) {
			@set_time_limit( self::TIME_LIMIT );
		}

		// Create the SQL statements
		$this->fwrite( $fp, "# --------------------------------------------------------\n" );
		$this->fwrite( $fp, "# " . sprintf( __( 'Table: %s', 'cyan-backup' ), $this->backquote( $table ) ) . "\n" );
		$this->fwrite( $fp, "# --------------------------------------------------------\n" );

		// Get Table structure
	 	$table_structure = $wpdb->get_results( "DESCRIBE $table" );
		if( !$table_structure ) {
			$this->error[] = __( 'Error getting table details', 'cyan-backup' ) . ': $table';
			return FALSE;
		}

		// Add SQL statement to drop existing table
		$this->fwrite( $fp, "\n\n" );
		$this->fwrite( $fp, "#\n" );
		$this->fwrite( $fp, "# " . sprintf( __( 'Delete any existing table %s', 'cyan-backup' ), $this->backquote( $table ) ) . "\n" );
		$this->fwrite( $fp, "#\n" );
		$this->fwrite( $fp, "\n" );
		$this->fwrite( $fp, "DROP TABLE IF EXISTS " . $this->backquote( $table ) . ";\n" );

		// Table structure
		$this->fwrite( $fp, "\n\n" );
		$this->fwrite( $fp, "#\n" );
		$this->fwrite( $fp, "# " . sprintf( __( 'Table structure of table %s', 'cyan-backup' ), $this->backquote( $table ) ) . "\n" );
		$this->fwrite( $fp, "#\n" );
		$this->fwrite( $fp, "\n" );

		$sql = "SHOW CREATE TABLE $table";
		
		$pkey = '';
		$create_table = $wpdb->get_results( $sql, ARRAY_N );
		
		if( $create_table !== FALSE ) {
			$this->fwrite( $fp, $create_table[0][1] . ' ;' );
			$this->fwrite( $fp, "\n\n" );
			$this->fwrite( $fp, "#\n" );
			$this->fwrite( $fp, '# ' . sprintf( __( 'Data contents of table %s', 'cyan-backup' ), $this->backquote( $table ) ) . "\n");
			$this->fwrite( $fp, "#\n" );
			
			if( preg_match( '/PRIMARY KEY \(([^\)]*)\)/i', $create_table[0][1], $matches ) ) {
				$pkey = $matches[1];
			}
		} else {
			$err_msg = sprintf( __( 'Error with SHOW CREATE TABLE for %s.', 'cyan-backup' ), $table );
			$this->error[] = $err_msg;
			$this->fwrite( $fp, "#\n# $err_msg\n#\n" );
			
			$err_msg = sprintf( __( 'Error getting table structure of %s', 'cyan-backup'), $table );
			$this->error[] = $err_msg;
			$this->fwrite( $fp, "#\n# $err_msg\n#\n" );
		}

		$defs = array();
		$ints = array();
		foreach( $table_structure as $struct ) {
			$type = strtolower( $struct->Type );
			
			if( 0 === strpos( $type, 'tinyint' ) || 0 === strpos( $type, 'smallint' ) || 0 === strpos( $type, 'mediumint' ) || 0 === strpos( $type, 'int' ) || 0 === strpos( $type, 'bigint' ) ) {
				if( null === $struct->Default ) {
					$defs[strtolower( $struct->Field )] = 'NULL';
				} else {
					$defs[strtolower( $struct->Field )] = $struct->Default;
				}
				
				$ints[strtolower( $struct->Field )] = "1";
			}
		}

		// Batch by $row_inc
		$segment = 0;
		$table_data = array();
		
		do {
			$row_inc = self::ROWS_PER_SEGMENT;
			$row_start = $segment * self::ROWS_PER_SEGMENT;

			// spam or revision excluded
			$where = '';
			if( preg_match( '/comments$/i', $table ) ) {
				$where = ' WHERE comment_approved != "spam"';
			} else if( preg_match( '/posts$/i', $table ) ) {
				$where = ' WHERE post_type != "revision"';
			}

			$sql = "SELECT * FROM $table $where";
			
			if( !empty( $pkey ) ) {
				$sql .= " ORDER BY $pkey";
			}
			
			$sql .= " LIMIT {$row_start}, {$row_inc}";

			$this->fwrite( $fp, "\n# $sql \n" );

			// get table data
			$table_data = $wpdb->get_results( $sql, ARRAY_A );
			
			if( $table_data !== FALSE ) {
				//    \x08\\x09, not required
				$search = array( "\x00", "\x0a", "\x0d", "\x1a" );
				$replace = array( '\0', '\n', '\r', '\Z' );

				if( count( $table_data ) > 0 ) {
					$entries = 'INSERT INTO ' . $this->backquote($table) . ' VALUES (';	
					
					foreach( $table_data as $row ) {
						$this->currentcount++;
						$this->percentage += $this->increment;
						
						if( round( $this->percentage ) > $this->last_percentage ) {
							$this->last_percentage = round( $this->percentage );
							$this->write_status_file( $this->last_percentage, sprintf( __( 'Processing %s...', 'cyan-backup' ), $table ) );
						}

						$values = array();
						
						foreach( $row as $key => $value ) {
							if( isset($ints[strtolower( $key )]) && $ints[strtolower( $key )]) {
								if( null === $value || '' === $value ) {
									$value = $defs[strtolower( $key )];
								} else {
									$value = $value;
								}
								
								if( '' === $value ) {
									$values[] = "''";
								} else {
									$values[] = $value;
								}
							} else {
								$values[] = "'" . str_replace( $search, $replace, $this->sql_addslashes( $value ) ) . "'";
							}
						}
						
						$this->fwrite( $fp, " \n" . $entries . implode( ', ', $values ) . ');' );
					}
				}
			}
			
			$segment++;
		} while( ( count( $table_data ) > 0 ) || ( $segment === 0 ) );

		// Create footer/closing comment in SQL-file
		$this->fwrite( $fp, "\n" );
		$this->fwrite( $fp, "#\n" );
		$this->fwrite( $fp, "# " . sprintf( __( 'End of data contents of table %s', 'cyan-backup' ), $this->backquote( $table ) ) . "\n" );
		$this->fwrite( $fp, "# --------------------------------------------------------\n" );
		$this->fwrite( $fp, "\n" );

		return TRUE;
	}

	//**************************************************************************************
	// get backup files
	//**************************************************************************************
	public function get_backup_files() {
		$scan_pattern = '/^' . preg_quote( $this->archive_pre, '/' ) . '.*' . preg_quote( $this->GetArchiveExtension(), '/' ) . '$/i';
		$files = array_reverse( scandir( $this->archive_path ) );
		$backup_files = array();
		
		foreach( $files as $file ) {
			if( preg_match( $scan_pattern, $file ) ) {
				$backup_files[] = $this->archive_path . $file;
			}
		}
		
		return $backup_files;
	}

	//**************************************************************************************
	// backup files info
	//**************************************************************************************
	public function backup_files_info( $nonces = FALSE, $page = FALSE, $backup_files = FALSE ) {
		if( !$backup_files ) {
			$backup_files = $this->get_backup_files();
		}

		$backup_files_info = array();
		if( count( $backup_files ) > 0) {
			foreach( (array)$backup_files as $backup_file ) {
				if( file_exists( $backup_file ) ) {
					$filemtime = $this->Utils->get_filemtime( $backup_file );
					
					if( !$nonces ) {
						$nonces = '&nonce=' . $this->create_nonce_no_logged_in();
					}
					
					if( $page ) { 
						$query = "?page={$page}&download=" . rawurlencode($backup_file) . $nonces;
					} else {
						$query = '?download=' . rawurlencode( $backup_file ) . $nonces;
					}
					
					$url = sprintf( '<a href="%1$s" title="%2$s">%2$s</a>', $query, esc_html( basename( $backup_file ) ) );
					
					$filesize = (int)sprintf( '%u', filesize( $backup_file ) ) / 1024 / 1024;

					$log_file = str_ireplace( $this->GetArchiveExtension(), '.log', $backup_file );
					
					if( file_exists( $log_file ) ) {
						if( $page ) {
							$logquery = "?page={$page}&download=" . rawurlencode( $log_file ) . $nonces;
						} else {
							$logquery = '?download=' . rawurlencode( $log_file ) . $nonces;
						}
						
						$logurl = sprintf( '<a href="%1$s" title="log">log</a>', $logquery, esc_html( basename( $log_file ) ) );
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
		return array( 'backup_files' => $this->backup_files_info() );
	}
}

endif;