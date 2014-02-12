<?php

	if( !is_admin() )
		wp_die(__('Access denied!', $this->textdomain));
		
	$notes = array();
	$nonce_field = 'option_update';

	$option = (array)get_option($this->option_name);
	$archive_path = $this->get_archive_path($option);
	$excluded_dir = $this->get_excluded_dir($option, array());

	// Create the .htaccess or WebConfig files
	if (isset($_POST['CreateWebConfig']) || isset($_POST['Createhtaccess'])) {
		if ( $this->wp_version_check('2.5') && function_exists('check_admin_referer') )
			check_admin_referer($nonce_field, self::NONCE_NAME);

		if( isset($_POST['CreateWebConfig']) )
			{
			$access_filename = $archive_path . 'Web.config';
			
			if( !file_exists( $access_filename ) )
				{
				$access_file = fopen( $access_filename, 'w' );
				
				fwrite( $access_file, '<?xml version="1.0" encoding="utf-8" ?>' . "\n");
				fwrite( $access_file, '<configuration>' . "\n");
				fwrite( $access_file, '	<system.webServer>' . "\n");
				fwrite( $access_file, '		<security>' . "\n");
				fwrite( $access_file, '			<authorization>' . "\n");
				fwrite( $access_file, '				<remove users="*" roles="" verbs="" />' . "\n");
				fwrite( $access_file, '				<add accessType="Allow" roles="Administrators" />' . "\n");
				fwrite( $access_file, '			</authorization>' . "\n");
				fwrite( $access_file, '		</security>' . "\n");
				fwrite( $access_file, '	</system.webServer>' . "\n");
				fwrite( $access_file, '</configuration>' . "\n");
				
				fclose( $access_file );
				
				$notes[] = array( "<strong>". __('Web.Config written!', $this->textdomain)."</strong>", 0);
				}
			else 
				{
				$notes[] = array( "<strong>". __('WARNING: Web.Config already exists, please edit it manually!', $this->textdomain)."</strong>", 1);
				}
			
			}
		
		if( isset($_POST['Createhtaccess']) )
			{
			$access_filename = $archive_path . '.htaccess';
			
			if( !file_exists( $access_filename ) )
				{
				$access_file = fopen( $access_filename, 'w' );

				fwrite( $access_file, '<FilesMatch ".*">' . "\n" );
				fwrite( $access_file, '  Order Allow,Deny' . "\n" );
				fwrite( $access_file, '  Deny from all' . "\n" );
				fwrite( $access_file, '</FilesMatch>' . "\n" );
				
				fclose( $access_file );

				$notes[] = array( "<strong>". __('.htaccess written!', $this->textdomain)."</strong>", 0);
				}
			else 
				{
				$notes[] = array( "<strong>". __('WARNING: .htaccess already exists, please edit it manually!', $this->textdomain)."</strong>", 1);
				}
			}
	}
	
	// option update
	if (isset($_POST['options_update'])) {
		if ( $this->wp_version_check('2.5') && function_exists('check_admin_referer') )
			check_admin_referer($nonce_field, self::NONCE_NAME);

		$postdata = $this->get_real_post_data();

		if ( isset($postdata['archive_path']) ) {
			$abspath  = $this->chg_directory_separator(ABSPATH, FALSE);
			$dir = trim($postdata['archive_path']);

			if ( ($realpath = realpath($dir)) !== FALSE) {
				$realpath = $this->chg_directory_separator($realpath, FALSE);
				if ( is_dir($realpath) )
					$realpath = $this->trailingslashit($realpath, FALSE);
				$options['archive_path'] = $realpath;
				
				if( substr( $realpath, 0, strlen( $abspath) ) == $abspath ) {
					$test_name = $realpath . "test.zip";
					$test_text = "This is a test file\n";
					$test_file = fopen( $test_name, 'w' );
					
					if( $test_file ) {
						fwrite($test_file, $test_text);
						fclose( $test_file );
				
						$test_url = $this->wp_site_url( substr( $realpath, strlen( $abspath ) ) . 'test.zip' );
				
						$test_read = @file_get_contents($test_url);
						
						unlink( $test_name );
						
						if( $test_read == $test_text ) {
							$notes[] = array( "<strong>". sprintf(__('WARNING: Archive directory ("%s") is a subdirectory in the WordPress root and is accessible via the web, this is an insecure configuration!', $this->textdomain), $realpath)."</strong>", 1);
						}
					} else {
						$notes[] = array( "<strong>". __('ERROR: Archive directory ("%s") is not writable!', $this->textdomain)."</strong>", 2);
					}
				}
			} else {
				$notes[] = array( "<strong>". sprintf(__('ERROR: Archive directory ("%s") does not exist!', $this->textdomain), $realpath)."</strong>", 2);
			}
		}
		
		if ( isset($postdata['excluded']) ) {
			$excluded = $excluded_dir = array();
			$abspath  = $this->chg_directory_separator(ABSPATH, FALSE);
			$check_archive_excluded = FALSE;
			$archive_path_found = FALSE;
			
			if( substr( $archive_path, 0, strlen( $abspath) ) == $abspath ) { $check_archive_excluded = TRUE; }

			foreach ( explode("\n", $postdata['excluded']) as $dir ) {
				$dir = trim($dir);
				if ( !empty($dir) ) {
					if ( ($realpath = realpath($dir)) !== FALSE) {
						$realpath = $this->chg_directory_separator($realpath, FALSE);
						$dir = str_replace($abspath, '', $realpath);
						if ( is_dir($realpath) )
							$dir = $this->trailingslashit($dir, FALSE);
						$excluded[] = $dir;
						$excluded_dir[] = str_replace($abspath, '', $dir);

						$realpath = $this->trailingslashit($realpath, FALSE);
						if( $check_archive_excluded && $realpath == $archive_path ) { $archive_path_found = TRUE; }
					} else {
						$notes[] = array("<strong>". sprintf(__('WARNING: Excluded directory ("%s") is not found, removed from exclusions.', $this->textdomain), $dir)."</strong>", 1);
					}
				}
			}

			if( $check_archive_excluded == TRUE && $archive_path_found == FALSE ) {
				$archive_dir = str_replace($abspath, '', $archive_path);
				$excluded[] = $archive_dir;
				$excluded_dir[] = $archive_dir;

				$notes[] = array( "<strong>". __('INFO: Archive path is in the WordPress directory tree but was not found in the exclusions, it has automatically been added.', $this->textdomain)."</strong>", 0);
			}
			
			$options['excluded'] = $excluded;
		}

		if ( isset($_POST['schedule']) ) {
			if( is_array( $_POST['schedule'] ) ) {
				$options['schedule'] = $_POST['schedule'];
			}
		}

		// Remove the backup schedule if we've change it recurrence.
		if( wp_next_scheduled('cyan_backup_hook') && ( $options['schedule']['type'] != $option['schedule']['type'] || $options['schedule']['interval'] != $option['schedule']['interval'] || $options['schedule']['tod'] != $option['schedule']['tod'] || $options['schedule']['dom'] != $option['schedule']['dom'] || $options['schedule']['dow'] != $option['schedule']['dow'] ) ) {
		
			wp_unschedule_event(wp_next_scheduled('cyan_backup_hook'), 'cyan_backup_hook');
		}

		// Add the backup schedule if it doesn't exist and is enabled.
		if( !wp_next_scheduled('cyan_backup_hook') && $options['schedule']['enabled'] ) {
			$next_backup_time = $this->calculate_initial_backup( $options['schedule'] );

			if( $next_backup_time > time() ) {
				wp_schedule_single_event($next_backup_time, 'cyan_backup_hook');
				$options['next_backup_time'] = $next_backup_time;
			} else {
				$notes[] = array( __('ERROR: Schedule not set, failed to determine the next scheduled time to backup!', $this->textdomain), 2);
			}
		}

		// Remove the backup schedule if it does exist and is disabled.
		if( wp_next_scheduled('cyan_backup_hook') && !$options['schedule']['enabled'] ) {
		
			wp_unschedule_event(wp_next_scheduled('cyan_backup_hook'), 'cyan_backup_hook');
		}

		if ( isset($_POST['prune']) ) {
			if( is_array( $_POST['prune'] ) ) {
				$options['prune'] = $_POST['prune'];
			}
		}
		
		update_option($this->option_name, $options);

		$option = $options;
		$archive_path = $this->get_archive_path($option);
		$excluded_dir = $this->get_excluded_dir($option, array());

		// Done!
		$notes[] = array("<strong>".__('Configuration saved!', $this->textdomain)."</strong>", 0);
	}

	$schedule_types = array( 'Once', 'Hourly', 'Daily', 'Weekly', 'Monthly' );

	if( self::DEBUG_MODE == TRUE ) {
		$schedule_types[] = 'debug';
	}

	$display_settings = array();
	$display_type_settings = array( 
								'Once' => array( 
									'schedule_debug' => 'display: none;',
									'schedule_once' => '',
									'schedule_before' => 'display: none;',
									'schedule_interval' => 'display: none;',
									'schedule_hours' => 'display: none;',
									'schedule_days' => 'display: none;',
									'schedule_weeks' => 'display: none;',
									'schedule_months' => 'display: none;',
									'schedule_on' => '',
									'schedule_dow' => '',
									'schedule_the' => '',
									'schedule_dom' => '',
									'schedule_at' => '',
									'schedule_tod' => ''
									),
								'Hourly' => array( 
									'schedule_debug' => 'display: none;',
									'schedule_once' => 'display: none;',
									'schedule_before' => '',
									'schedule_interval' => '',
									'schedule_hours' => '',
									'schedule_days' => 'display: none;',
									'schedule_weeks' => 'display: none;',
									'schedule_months' => 'display: none;',
									'schedule_on' => 'display: none;',
									'schedule_dow' => 'display: none;',
									'schedule_the' => 'display: none;',
									'schedule_dom' => 'display: none;',
									'schedule_at' => '',
									'schedule_tod' => ''
									),
								'Daily' => array( 
									'schedule_debug' => 'display: none;',
									'schedule_once' => 'display: none;',
									'schedule_before' => '',
									'schedule_interval' => '',
									'schedule_hours' => 'display: none;',
									'schedule_days' => '',
									'schedule_weeks' => 'display: none;',
									'schedule_months' => 'display: none;',
									'schedule_on' => 'display: none;',
									'schedule_dow' => 'display: none;',
									'schedule_the' => 'display: none;',
									'schedule_dom' => 'display: none;',
									'schedule_at' => '',
									'schedule_tod' => ''
									),
								'Weekly' => array( 
									'schedule_debug' => 'display: none;',
									'schedule_once' => 'display: none;',
									'schedule_before' => '',
									'schedule_interval' => '',
									'schedule_hours' => 'display: none;',
									'schedule_days' => 'display: none;',
									'schedule_weeks' => '',
									'schedule_months' => 'display: none;',
									'schedule_on' => '',
									'schedule_dow' => '',
									'schedule_the' => 'display: none;',
									'schedule_dom' => 'display: none;',
									'schedule_at' => '',
									'schedule_tod' => ''
									),
								'Monthly' => array( 
									'schedule_debug' => 'display: none;',
									'schedule_once' => 'display: none;',
									'schedule_before' => '',
									'schedule_interval' => '',
									'schedule_hours' => 'display: none;',
									'schedule_days' => 'display: none;',
									'schedule_weeks' => 'display: none;',
									'schedule_months' => '',
									'schedule_on' => '',
									'schedule_dow' => 'display: none;',
									'schedule_the' => '',
									'schedule_dom' => '',
									'schedule_at' => '',
									'schedule_tod' => ''
									)
								);		
	
	if( self::DEBUG_MODE == TRUE ) {
		$display_type_settings['debug'] = array( 
									'schedule_debug' => '',
									'schedule_once' => 'display: none;',
									'schedule_before' => 'display: none;',
									'schedule_interval' => 'display: none;',
									'schedule_hours' => 'display: none;',
									'schedule_days' => 'display: none;',
									'schedule_weeks' => 'display: none;',
									'schedule_months' => 'display: none;',
									'schedule_on' => 'display: none;',
									'schedule_dow' => 'display: none;',
									'schedule_the' => 'display: none;',
									'schedule_dom' => 'display: none;',
									'schedule_at' => 'display: none;',
									'schedule_tod' => 'display: none;'
									);
	}
	
	echo '<script type="text/javascript">//<![CDATA[' . "\n";
	
	echo 'function set_schedule_display() {' . "\n";
	echo 'var display_type_settings = new Array() ' . "\n\n";

	foreach( $display_type_settings as $key => $value ) {
		echo 'display_type_settings[\'' . $key . '\'] = new Array();' . "\n";
	}
	
	foreach( $display_type_settings as $key => $value ) {
		foreach( $value as $subkey => $subvalue ) {
			echo 'display_type_settings[\'' . $key . '\'][\'' . $subkey . '\'] = \'';
			if( $subvalue == "display: none;" ) { echo '0'; } else { echo '1'; }
			echo '\';' . "\n";
		}
	}
	
	echo "\n";
	
	echo 'var type = jQuery("#schedule_type").val();' . "\n";
	echo "\n";
	echo 'for( var i in display_type_settings[type] ) {' . "\n";
	echo 'if( display_type_settings[type][i] == 0 ) { jQuery("#" + i).css( "display", "none" ); } else { jQuery("#" + i).css( "display", "" ); }' . "\n";
	echo '}' . "\n";
	
	echo '}' . "\n";
	
	echo '//]]></script>' . "\n";

	// Output
	foreach( $notes as $note ) {
		switch( $note[1] )
			{
			case 0:
				echo '<div id="message" class="updated fade"><p>' . $note[0] . '</p></div>';
				break;
			case 1:
				echo '<div id="message" class="updated fade" style="border-left: 4px solid #fbff1c;"><p>' . $note[0] . '</p></div>';
				break;
			case 2:
				echo '<div id="message" class="error fade"><p>' . $note[0] . '</p></div>';
				break;
			}
			
		echo "\n";
	}
?>

<div class="wrap">

	<div id="icon-options-general" class="icon32"><br /></div>

	<h2><?php _e('CYAN Backup Options', $this->textdomain);?></h2>

	<h3><?php _e('Directory Options', $this->textdomain);?></h3>

	<form method="post" id="option_update" action="<?php echo $this->admin_action;?>-options">
<?php if ($this->wp_version_check('2.5') && function_exists('wp_nonce_field') )
		echo wp_nonce_field($nonce_field, self::NONCE_NAME, true, false);
?>

		<table class="optiontable form-table" style="margin-top:0;">
			<tbody>
				<tr>
					<th><?php _e('Archive path', $this->textdomain);?></th>

					<td>
						<input type="text" name="archive_path" id="archive_path" size="100" value="<?php echo htmlentities($archive_path);?>" /><br><br>
						<input class="button" id="Createhtaccess" name="Createhtaccess" type="submit" value="<?php _e('Create .htaccess File', $this->textdomain);?>">&nbsp;
						<input class="button" id="CreateWebConfig" name="CreateWebConfig" type="submit" value="<?php _e('Create WebConfig File', $this->textdomain);?>">
					</td>
				</tr>

				<tr>
					<th><?php _e('Excluded dir', $this->textdomain);?></th>
					
					<td><textarea name="excluded" id="excluded" rows="5" cols="100">
<?php
	$abspath  = $this->chg_directory_separator(ABSPATH, FALSE);
	foreach ($excluded_dir as $dir) {
		echo htmlentities($this->chg_directory_separator($abspath.$dir,FALSE)) . "\n";
	}
?></textarea><br><br>

						<input class="button" id="AddArchiveDir" name="AddArchiveDir" type="button" value="<?php _e('Add Archive Dir', $this->textdomain);?>" onClick="excluded.value = jQuery.trim( excluded.value ) + \'\n'. addslashes( $archive_path ) . '\">&nbsp;
						<input class="button" id="AddWPContentDir" name="AddWPContentDir" type="button" value="<?php _e('Add WP-Content Dir', $this->textdomain);?>" onClick="excluded.value = jQuery.trim( excluded.value ) + \'\n'. addslashes( WP_CONTENT_DIR ) . '\">&nbsp;
						<input class="button" id="AddWPContentDir" name="AddWPUpgradeDir" type="button" value="<?php _e('Add WP-Upgrade Dir', $this->textdomain);?>" onClick="excluded.value = jQuery.trim( excluded.value ) + \'\n'. addslashes( WP_CONTENT_DIR ) . '/upgrade\">&nbsp;
						<input class="button" id="AddWPAdminDir" name="AddWPAdminDir" type="button" value="<?php _e('Add WP-Admin Dir', $this->textdomain);?>" onClick="excluded.value = jQuery.trim( excluded.value ) + \'\n'. addslashes( $abspath ) . 'wp-admin\">&nbsp;
						<input class="button" id="AddWPIncludesDir" name="AddWPIncludesDir" type="button" value="<?php _e('Add WP-Includes Dir', $this->textdomain);?>" onClick="excluded.value = jQuery.trim( excluded.value ) + \'\n'. addslashes($abspath) . 'wp-includes\">&nbsp;
					</td>
				</tr>
			</tbody>
		</table>

		<h3><?php _e('Schedule Options', $this->textdomain);?></h3>

		<table style=\"margin-top:0; width: auto;\">
			<tbody>
				<tr>
					<td class="description" style="width: auto; text-align: right; vertical-align: top;"><span class="description"><?php _e('Current server time', $this->textdomain);?></span>:</td><td style="width: auto; text-align: left; vertical-align: top;"><code>
<?php
	$next_schedule = time();
	echo date( get_option('date_format'), $next_schedule ) . ' @ ' . date( get_option('time_format'), $next_schedule );
?></code>
					</td>
				</tr>
<?php if( $option['schedule']['enabled'] == 'on' ) { ?>
				<tr>
		
				<td style="width: auto; text-align: right; vertical-align: top;"><span class="description"><?php _e('Next backup scheduled for', $this->textdomain);?></span>:</td><td style="width: auto; text-align: left; vertical-align: top;"><code>
<?php
			$next_schedule = wp_next_scheduled('cyan_backup_hook');

			if( $next_schedule ) {
				echo date( get_option('date_format'), $next_schedule ) . ' @ ' . date( get_option('time_format'), $next_schedule );
			}
			else {
				_e('None', $this->textdomain );
			}
?></code>
				</td>
			</tr>
<?php	}?>
			</tbody>
		</table>
		
		<table class="optiontable form-table" style="margin-top:0;">
			<tbody>
				<tr>
					<th><?php _e('Enable', $this->textdomain);?></th>

					<td><input type=checkbox id="schedule_enabled" name="schedule[enabled]"<?php if( $option['schedule']['enabled'] == 'on' ) { echo ' CHECKED'; }?>></td>
				</tr>

				<tr>
					<th><?php _e('Type', $this->textdomain);?></th>

					<td>
						<select id="schedule_type" onChange="set_schedule_display();" name="schedule[type]">
<?php		
		foreach( $schedule_types as $type ) {
			echo "\t\t\t\t\t<option value=\"" . $type . '"';

			if( $option['schedule']['type'] == $type ) { echo ' SELECTED'; $display_settings = $display_type_settings[$type]; }
			
			echo '>' . __($type, $this->textdomain) . '</option>';
		}
?>		
						</select>
					</td>
				</tr>

				<tr>
					<th><?php _e('Schedule', $this->textdomain);?></th>
					
					<td>
<?php		
		if( self::DEBUG_MODE == TRUE ) {
			echo "\t\t\t\t\t" . '<span id="schedule_debug" style="' . $display_settings['schedule_debug'] . '">' . __('Every minute, for debugging only', $this->textdomain) . '</span>';
		}
?>		
						<span id="schedule_once" style="<?php echo $display_settings['schedule_once'];?>"><?php _e('Only once', $this->textdomain);?></span>
						<span id="schedule_before" style="<?php echo $display_settings['schedule_before'];?>"><?php _e('Run backup every', $this->textdomain);?> </span>
						<input type="text" id="schedule_interval" name="schedule[interval]" size="3" value="<?php echo $option['schedule']['interval'];?>" style="<?php echo $display_settings['schedule_interval'];?>">
						<span id="schedule_hours" style="<?php echo $display_settings['schedule_hours'];?>"> <?php _e('hour(s)', $this->textdomain);?></span><span id="schedule_days" style="<?php echo $display_settings['schedule_days'];?>"> <?php _e('day(s)', $this->textdomain);?></span><span id="schedule_weeks" style="<?php echo $display_settings['schedule_weeks'];?>"> <?php _e('week(s)', $this->textdomain);?></span><span id="schedule_months" style="<?php echo $display_settings['schedule_months'];?>"> <?php _e('month(s)', $this->textdomain);?></span>
						<span id="schedule_on" style="<?php echo $display_settings['schedule_on'];?>"> <?php _e('on', $this->textdomain);?></span>

						<select id="schedule_dow" name="schedule[dow]" style="<?php echo $display_settings['schedule_dow'];?>">
							<option value=""></option>
<?php		
		$weekdays = array( 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' );
		foreach( $weekdays as $day ) {
			echo "\t\t\t\t\t\t\t" . '<option value="' . $day . '"';

			if( $option['schedule']['dow'] == $day ) { echo' SELECTED'; }
			
			echo '>' . __($day, $this->textdomain) . '</option>';
		}
?>		
						</select>

						<span id="schedule_the" style="<?php echo $display_settings['schedule_the'];?>"> <?php _e('the', $this->textdomain);?></span>
			
						<select id="schedule_dom" name="schedule[dom]" style="<?php echo $display_settings['schedule_dom'];?>">
							<option value=""></option>
<?php		
		for( $i = 1; $i < 28; $i++ ) {
			echo "\t\t\t\t\t\t\t" . '<option value="' . $i . '"';

			if( $option['schedule']['dom'] == $i ) { echo' SELECTED'; }
			
			echo '>' . $i . '</option>';
		}
?>
						</select>
			
						<span id="schedule_at" style="<?php echo $display_settings['schedule_at'];?>"> <?php _e('at', $this->textdomain);?></span>
						<input type="text" id="schedule_tod" name="schedule[tod]" size="8" value="<?php echo $option['schedule']['tod'];?>" style="<?php echo $display_settings['schedule_tod'];?>">
					.</td>
				</tr>
			</tbody>
		</table>

		<h3><?php _e('Storage Maintenance', $this->textdomain);?></h3>

		<table class="optiontable form-table" style="margin-top:0;">
			<tbody>
				<tr>
					<th><?php _e('Enable backup pruning', $this->textdomain);?></th>
					
					<td><input type=checkbox name="prune[enabled]"<?php	if( $option['prune']['enabled'] == 'on' ) { echo' CHECKED'; }?>></td>
				</tr>

				<tr>
					<th><?php _e('Number of backups to keep', $this->textdomain);?></th>
					<td><input type="text" name="prune[number]" size="5" value="<?php echo $option['prune']['number'];?>"></td>
				</tr>
			</tbody>
		</table>

		<p style="margin-top:1em;"><input type="submit" name="options_update" class="button-primary" value="<?php _e('Update Options', $this->textdomain);?>" class="button" /></p>

	</form>

</div>
