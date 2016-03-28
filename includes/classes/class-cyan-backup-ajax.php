<?php
if( !class_exists( 'CYAN_WP_Backup_Ajax' ) ) :

class CYAN_Backup_Ajax {
	private $options;
	private $Utils;

	//**************************************************************************************
	// Constructor
	//**************************************************************************************
	function __construct( $utils = FALSE, $options = FALSE ) {
		$this->Utils = $utils;
		$this->options = $options;
	}

}

endif;