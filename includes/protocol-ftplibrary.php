<?php
// Make sure if all else fails, we return a FALSE result.
$result = FALSE;

if( function_exists( 'ftp_connect' ) ) {
	// We might be on windows and if so ftp needs unix style directory separators so convert windows style to unix style.
	$final_dir = str_replace( '\\', '/', $final_dir );

	$server_ip = explode( '.', $_SERVER['LOCAL_ADDR'] );
	$remote_ip = explode( '.', gethostbyname( $remote_settings['host'] ) ); 
	
	// FTP is insecure, make sure we're in the same class 'C' subnet as the remote server we're connecting to.  Also make sure we actually got a result from the explode calls.
	if( $server_ip[0] == $remote_ip[0] && $server_ip[1] == $remote_ip[1] && $server_ip[2] == $remote_ip[2] && $server_ip !== FALSE && $remote_ip !== FALSE) {
		$ftp_connection = ftp_connect( $remote_settings['host'] );
		
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
}	
?>