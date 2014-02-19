<?php
// Make sure if all else fails, we return a FALSE result.
$result = FALSE;

if( function_exists( 'ftp_ssl_connect' ) ) {
	// We might be on windows and if so ftp needs unix style directory separators so convert windows style to unix style.
	$final_dir = str_replace( '\\', '/', $final_dir );

	$ftp_connection = ftp_ssl_connect( $remote_settings['host'] );
	
	$this->write_debug_log( "password: $final_password" );
	
	if( $ftp_connection !== FALSE ) {
		if( @ftp_login( $ftp_connection, $remote_settings['username'], $final_password ) !== FALSE ) {
			// Make sure the remote directory exists.
			@ftp_mkdir( $ftp_connection, $final_dir );
			
			$result = ftp_put( $ftp_connection, $final_dir . $filename, $archive, FTP_BINARY );
			
			// If we have been told to send the log file as well, let's do that now.
			if( $remote_settings['sendlog'] == 'on' ) {
				ftp_put( $ftp_connection, $final_dir . $logname, $log, FTP_ASCII );
			}

		}
		
		ftp_close( $ftp_connection );
	}
}	
?>