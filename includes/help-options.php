<?php

	if( !is_admin() )
		wp_die(__('Access denied!', $this->textdomain));
	
	$help_screen = WP_Screen::get($this->option_page);

	$help_screen->add_help_tab(
		array(
			'title'    => 	__('Overview', $this->textdomain),
			'id'       => 	'overview_tab',
			'content'  => 	'<p>' . __('This page allows you to set all of your options for CYAN Backup.', $this->textdomain) . '</p>' .
							'<p>' . __('There are six overall categories of options to set, you can find details on each by selecting the related tab to the left.', $this->textdomain) . '</p>' .
							'<p>' . __('CYAN Backup is a low level tool for WordPress and should be configured with care.  Where ever possible, incorrect configurations are detected and a warning or error message will be displayed.  However not all can be detected and you should be aware of the impact of your configuration on your site.', $this->textdomain) . '</p>'
			,
			'callback' => 	false
		)
	);
	
	$help_screen->add_help_tab(
		array(
			'title'    => 	__('Directory Options', $this->textdomain),
			'id'       => 	'dir_tab',
			'content'  => 	'<p>' . __('<b>Archive Path</b>: This is where you wish to store the completed backups.  This will also be used as the temporary location of working files for CYAN Backup.  This directory should not be accessible to users as your SQL table exports will be here, along with your WordPress configuration files.', $this->textdomain) . '</p>' .
							'<p>' . __('<b>Create .htaccess/WebConfig File</b>: If you must have your archive path in a web accessible location (for example, perhaps your hosting provider only allows for subdirectories inside your web root), you should make sure your web server configuration blocks access to all files in the archive directory.  These buttons will create .htaccess/Web.Config files that will do this if they do not already exist.', $this->textdomain) . '</p>' .
							'<p>' . __('<b>Exclude Directories</b>: If you wish to exclude certain directories from the backup you may enter them here.  Several buttons are provided to add commonly selected directories to the list.  Note if your archive directory is in the WordPress directory tree it will automatically be added to the exclusion list when you save the settings.', $this->textdomain) . '</p>'
			,
			'callback' => 	false
		)
	);

	$help_screen->add_help_tab(
		array(
			'title'    => 	__('Log Options', $this->textdomain),
			'id'       => 	'log_tab',
			'content'  => 	'<p>' . __('<b>E-Mail the log file</b>:  If this option is enabled the log file will be e-mailed after a backup has been completed.', $this->textdomain) . '</p>' .
							'<p>' . __('<b>Send to Addresses</b>:  This is a comma separated list of e-mail addresses to send the log to.  If this option is left blank, the site administrators e-mail address will be used.', $this->textdomain) . '</p>'
			,
			'callback' => 	false
		)
	);

	$help_screen->add_help_tab(
		array(
			'title'    => 	__('Schedule Options', $this->textdomain),
			'id'       => 	'schedule_tab',
			'content'  => 	'<p>' . sprintf(__('<b>Current Server Time</b>:  This displays the server time when you loaded this page, it is here for reference only.  If this does not display the time you expect your %stimezone setting%s may be incorrect.', $this->textdomain), '<a href="' . admin_url('options-general.php') . '">','</a>') . '</p>' .
							'<p>' . __('<b>Next backup scheduled for</b>:  This displays the next scheduled backup in WP Cron, it is here for reference only.', $this->textdomain) . '</p>' .
							'<p>' . __('<b>Enable</b>:  This enables/disables the scheduler.', $this->textdomain) . '</p>' .
							'<p>' . __('<b>Type</b>:  This selects the schedule type, options are Once, Hourly, Daily, Weekly and Monthly.  Note that selecting different schedule types will change the options presented in the schedule field.', $this->textdomain) . '</p>' .
							'<p>' . __('<b>Schedule - Once</b>:  You may select a day of the week OR a day of the month to run the backup on.  You may also select a time.  If both the day of the week and day of the month values have been selected, the day of teh month will take precedence.', $this->textdomain) . '</p>' .
							'<p>' . __('<b>Schedule - Hourly</b>:  You may run an hourly backup on a recurring interval, for example select to run every 6 hours would create a backup file 4 times a day.  You may also select at what time past the hour you wish to run the backup.', $this->textdomain) . '</p>' .
							'<p>' . __('<b>Schedule - Daily</b>:  You may run a daily backup on a recurring number of days at a specific time.', $this->textdomain) . '</p>' .
							'<p>' . __('<b>Schedule - Weekly</b>:  Weekly schedules can have the recurring time as well as the day of the week set.  For example you could select every two weeks on Monday at 11:15pm.', $this->textdomain) . '</p>' .
							'<p>' . __('<b>Schedule - Monthly</b>:  Montly schedules can have the recurrance as well as the day of the month set with a time.', $this->textdomain) . '</p>'
			,
			'callback' => 	false
		)
	);

		$help_screen->add_help_tab(
		array(
			'title'    => 	__('Storage Maintenance', $this->textdomain),
			'id'       => 	'storage_tab',
			'content'  => 	'<p>' . __('<b>Enable backup pruning</b>:  Backup pruning will automatically delete older backup files after a new backup has completed.', $this->textdomain) . '</p>' .
							'<p>' . __('<b>Number of backups to keep</b>:  This is the number of backups to keep based upon the date and time of the backup files.  If this is set to 0, all backups will be retained.  You should not set this value too low or you may lose data if you need to recover an older version of your site.', $this->textdomain) . '</p>'
			,
			'callback' => 	false
		)
	);

	$help_screen->add_help_tab(
		array(
			'title'    => 	__('Remote Storage', $this->textdomain),
			'id'       => 	'remote_tab',
			'content'  => 	'<p>' . __('<b>Enable remote storage</b>:  This will enable the remote storage of your backup files.  You should ALWAYS keep copies of your backup files on a different host than your main website as if your site is compromised or has a major hardware failure you may not be able to access your files on the primary host.', $this->textdomain) . '</p>' .
							'<p>' . __('<b>Protocol</b>:  Select the transfer protocol to use.  At this time only FTP is supported', $this->textdomain) . '</p>' .
							'<p>' . __('<b>Protocol - FTP</b>:  FTP IS INSECURE.  DO NOT USE THIS ON PRODUCTION SYSTEMS.  FTP is included here only for testing purposes.  FTP connections will only be allowed to remote systems on your local subnet.', $this->textdomain) . '</p>' .
							'<p>' . __('<b>Username</b>:  The username to login to the remote server with.  Ideally this user will only be able to write files to the remote location, not read.  This will ensure that even if your site is compromised, your remote storage cannot be used to as a distribution point for hackers.', $this->textdomain) . '</p>' .
							'<p>' . __('<b>Password</b>:  The password to login to the remote server with.', $this->textdomain) . '</p>' .
							'<p>' . __('<b>Certificate</b>:  Unused at this time.', $this->textdomain) . '</p>' .
							'<p>' . __('<b>Remote path</b>:  This is the remote path to use to store the backup.', $this->textdomain) . __( "You many use the follow place holders: %m = month (01-12), %d = day (01-31), %Y = year (XXXX), %M = month (Jan...Dec), %F = month (January...December)" ) . '</p>' .
							'<p>' . __('<b>Include log file</b>:  By default, only the archive log is sent to the remote server, selecting this option will also send the log file.', $this->textdomain) . '</p>' .
							'<p>' . __('<b>Delete local copy</b>:  Once the transfer is successfull, this option will automatically delete the local copy of the backup and log file.', $this->textdomain) . '</p>'
			,
			'callback' => 	false
		)
	);

	$help_screen->add_help_tab(
		array(
			'title'    => 	__('Clear Active Backup', $this->textdomain),
			'id'       => 	'active_tab',
			'content'  => 	'<p>' . __("<b>Clear active backup status</b>:  Only check this if a backup has hung and you can no longer execute backups.  CYAN Backup uses a status file to tell if a backup is running or not, if this file hasn't been deleted after a backup is complete you won't be able to run another backup for 30 minutes.  If you wish to force the deletion of the file check this option and save the settings.  This will force the deletion of the file.", $this->textdomain) . '</p>'
			,
			'callback' => 	false
		)
	);

?>