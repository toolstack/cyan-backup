<?php
// Make sure if all else fails, we return a FALSE result.
$result = FALSE;

if( function_exists( 'ssh2_connect' ) ) {
	// We might be on windows and if so ftp needs unix style directory separators so convert windows style to unix style.
	$final_dir = str_replace( '\\', '/', $final_dir );

	$sftp_connection = ssh2_connect( $remote_settings['host'] );
	
	if( $sftp_connection !== FALSE ) {
		if( @ssh2_auth_password( $ftp_connection, $remote_settings['username'], $final_password ) !== FALSE ) {
			// Start a SFTP session.
			$sftp = ssh2_sftp($connection);

			// Make sure the remote directory exists.
			@ssh2_sftp_mkdir( $sftp, $final_dir );
			
			$result = ssh2_scp_send( $sftp_connection, $archive, $final_dir . $filename );
			
			// If we have been told to send the log file as well, let's do that now.
			if( $remote_settings['sendlog'] == 'on' ) {
				ssh2_scp_send( $sftp_connection, $log, $final_dir . $logname );
			}
		}

		ftp_close( $sftp_connection );
	}
}
?>