<?php
// Make sure if all else fails, we return a FALSE result.
$result = FALSE;

set_include_path(get_include_path() . PATH_SEPARATOR . dirname( __FILE__ ) . '/phpseclib');

include_once( 'Net/SFTP.php' );

//define('NET_SFTP_LOGGING', NET_SFTP_LOG_COMPLEX);

if( class_exists( 'Net_SFTP' ) ) {
	// We might be on windows and if so ftp needs unix style directory separators so convert windows style to unix style.
	$final_dir = str_replace( '\\', '/', $final_dir );

	//$this->write_debug_log( 'Start connection to ' . $remote_settings['host'] );
	$sftp_connection = new Net_SFTP( $remote_settings['host'] );
	
	if( $sftp_connection !== FALSE ) {
		//$this->write_debug_log( 'Connected' );
		if( $sftp_connection->login( $remote_settings['username'], $final_password ) !== FALSE ) {
			// The phpseclib needs to have the destination as a relative path to the users home directory, so strip the leading
			// directory marker if it exists.
			if( substr($final_dir, 0, 1) == '/' ) { $rel_final_dir = substr($final_dir,1); } else { $rel_final_dir = $final_dir; }

			//$this->write_debug_log( 'Final dir: ' . $final_dir );
			
			// Make sure the remote directory exists, phpseclib->mkdir() doesn't support recursive directories, so do it manually.
			$parts = explode( '/', $final_dir );
			
			// Get the current working directory so we can return to it later.
			$cwd = $sftp_connection->pwd();
			//$this->write_debug_log( 'CWD: ' . $cwd );
			
			foreach( $parts as $part ) {
				//$this->write_debug_log( 'Part: ' . $part );

				if( !$sftp_connection->chdir( $part ) ) {
					$sftp_connection->mkdir( $part );
					$sftp_connection->chdir( $part );
				}
			}
			
			// Go back to where we started.
			$sftp_connection->chdir( $cwd );
			
			//$this->write_debug_log( 'Sending file: ' . $rel_final_dir . $filename );
			$result = $sftp_connection->put( $rel_final_dir . $filename, $archive, NET_SFTP_LOCAL_FILE );
			
			// If we have been told to send the log file as well, let's do that now.
			if( $remote_settings['sendlog'] == 'on' ) {
				$sftp_connection->put( $rel_final_dir . $logname, $log, NET_SFTP_LOCAL_FILE );
			}
		}
		
	$sftp_connection->disconnect();
	
	//$this->write_debug_log( $sftp_connection->getSFTPLog() );
	}
}
?>