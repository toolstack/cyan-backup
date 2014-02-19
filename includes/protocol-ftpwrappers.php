<?php
// Make sure if all else fails, we return a FALSE result.
$result = FALSE;

if( in_array( 'ftp', stream_get_wrappers() ) ) {
	// We might be on windows and if so ftp needs unix style directory separators so convert windows style to unix style.
	$final_dir = str_replace( '\\', '/', $final_dir );
	
	$server_ip = explode( '.', $_SERVER['LOCAL_ADDR'] );
	$remote_ip = explode( '.', gethostbyname( $remote_settings['host'] ) ); 
	
	// FTP is insecure, make sure we're in the same class 'C' subnet as the remote server we're connecting to.  Also make sure we actually got a result from the explode calls.
	if( $server_ip[0] == $remote_ip[0] && $server_ip[1] == $remote_ip[1] && $server_ip[2] == $remote_ip[2] && $server_ip !== FALSE && $remote_ip !== FALSE) {
		// Setup a connection string and make sure the remote directory exists.
		$connection = 'ftp://' . $remote_settings['username'] . ':' . $final_password . '@' . $remote_settings['host'] . $final_dir;
		@mkdir( $connection );
		
		// Take a quick nap to make sure the remote host finished creating the new directory before we start sending the zip file.
		sleep( 2 );
		
		// Setup a connection string to send the zip file to the remote host.
		$connection = 'ftp://' . $remote_settings['username'] . ':' . $final_password . '@' . $remote_settings['host'] . $final_dir . $filename;

		// Open the zip file to stream to the remote host.
		$filestream = fopen( $archive, 'r' );
		
		// If we opened the zip successfully, go ahead and send it.
		if( $filestream !== FALSE ) {
			// Send the file and close it once done.  Save the result for use later.
			$result = @file_put_contents( $connection, $filestream );
			fclose( $filestream );

			// If we have been told to send the log file as well, let's do that now.
			if( $remote_settings['sendlog'] == 'on' ) {
				// Setup a connection string to send the log file to the remote host.
				$connection = 'ftp://' . $remote_settings['username'] . ':' . $final_password . '@' . $remote_settings['host'] . $final_dir . $logname;
				
				// Take a quick nap to make sure the remote host finished receiving file before we start sending the log file.
				sleep( 2 );

				// Open the log file to stream to the remote host.
				$filestream = fopen( $log, 'r' );

				// If we opened the log successfully, go ahead and send it.
				if( $filestream !== FALSE ) {
					// Send the file and close it once done.  Don't save the result for use later as we only care that the zip file made it successfully.
					@file_put_contents( $connection, $filestream );
					fclose( $filestream );
				}
			}
		}
	}
}	
?>