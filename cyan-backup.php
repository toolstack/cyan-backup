<?php
/*
Plugin Name: CYAN Backup
Version: 3.0-alpha
Plugin URI: http://toolstack.com/cyan-backup
Description: Backup your entire WordPress site and its database into an archive file on a schedule.
Author: Greg Ross
Author URI: http://toolstack.com/
Text Domain: cyan-backup
Domain Path: /languages/

Read the accompanying readme.txt file for instructions and documentation.

	Original Total Backup code Copyright 2011-2012 wokamoto (wokamoto1973@gmail.com)
	All additional code Copyright 2014-2016 Greg Ross (greg@toolstack.com)

This software is released under the GPL v2.0, see license.txt for details.

*/

require_once( __DIR__ . '/vendor/autoload.php' );

include_once( 'includes/classes/class-cyan-backup.php' );

GLOBAL $cyan_backup;
$cyan_backup = new CYANBackup();

function cyan_backup_scheduled_run() {
	GLOBAL $cyan_backup;

	$cyan_backup->scheduled_backup();
}

add_action( 'cyan_backup_hook', 'cyan_backup_scheduled_run' );
