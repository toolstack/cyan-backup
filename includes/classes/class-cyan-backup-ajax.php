<?php
if( !class_exists( 'CYAN_WP_Backup_Ajax' ) ) :

class CYAN_Backup_Ajax {
	private $options;
	private $Utils;

	//**************************************************************************************
	// Constructor
	//**************************************************************************************
	function __construct( $utils = FALSE, $options = FALSE ) {
		$this->Utils   = $utils;
		$this->options = $options;

		// Add the AJAX actions.
		add_action( 'wp_ajax_cyan_backup_start', array( &$this, 'cyan_backup_start_action_callback' ) );
		add_action( 'wp_ajax_cyan_backup_status', array( &$this, 'cyan_backup_status_action_callback' ) );

	}
	
	// Setup an AJAX action to get the current backup status.
	public function cyan_backup_status_action_callback() {
		GLOBAL $cyan_backup;

		$action = 'cyan_backup_status';
	
		$status = @file_get_contents( $cyan_backup->archive_path . 'status.log' );

		if( $status !== FALSE ) {
			if( file_exists( $cyan_backup->archive_path . 'backup.active' ) ) {
				if( time() - filemtime( $cyan_backup->archive_path . 'backup.active' ) > (60 * 10) ) {
					unlink( $cyan_backup->archive_path . 'backup.active' );
					$status = FALSE;
				}
			}
		}

		if( $status === FALSE ) {
			$result = array(
								'result' => FALSE,
								'method' => $action,
								'message' => __('No backup running!', 'cyan-backup'),
							);
		} else {
			list( $result['percentage'], $result['message'], $result['state'], $result['backup_file'], $result['backup_date'], $result['backup_size'] ) = explode( "\n", $status );
			
			$result['percentage']  = trim( $result['percentage'] );
			$result['message']     = trim( $result['message'] );
			$result['state']       = trim( $result['state'] );
			$result['backup_file'] = trim( $result['backup_file'] );
			$result['backup_date'] = trim( $result['backup_date'] );
			$result['backup_size'] = trim( $result['backup_size'] );

			$temp_time = strtotime( $result['backup_date'] );
			$result['backup_date'] = date( get_option( 'date_format' ), $temp_time ) . ' @ ' . date( get_option( 'time_format' ), $temp_time );

			$result['backup_size'] = number_format( (float)$result['backup_size'], 2 ) . ' MB';
			}
		
		$result = array_merge( array( 'result' => TRUE, 'method' => $action ), (array)$result );

		echo json_encode( $result );

		wp_die(); // this is required to terminate immediately and return a proper response
	}

	// Setup an AJAX action to start a backup.
	public function cyan_backup_start_action_callback() {
		GLOBAL $cyan_backup;
		
		$action = 'cyan_backup_start';

		if( ! $cyan_backup->CYANBackupWorker->can_user_backup() ) {
			$result = array(
								'result' => FALSE,
								'method' => $action,
								'message' => __('You don\'t have privileges to start a backup!', 'cyan-backup' ),
							);
				
		} else {		
			$user = wp_get_current_user();
			
			$result = $cyan_backup->json_backup( $user->ID );
			
			if( $result ) {
				$result = array_merge( array( 'result' => TRUE, 'method' => $action ), (array)$result );
			} else {
				$result = array_merge( array( 'result' => FALSE, 'method' => $action ), (array)$result );
			}
		}
		
		echo json_encode( $result );

		wp_die(); // this is required to terminate immediately and return a proper response
	}
}

endif;