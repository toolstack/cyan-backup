<?php
		$out   = '';
		$notes  = array();
		$error = 0;
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
					
					$notes[] = "<strong>". __('Web.Config written!', $this->textdomain)."</strong>";
					$errors++;
					}
				else 
					{
					$notes[] = "<strong>". __('WARNING: Web.Config already exists, please edit it manually!', $this->textdomain)."</strong>";
					$error++;
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

					$notes[] = "<strong>". __('.htaccess written!', $this->textdomain)."</strong>";
					$errors++;
					}
				else 
					{
					$notes[] = "<strong>". __('WARNING: .htaccess already exists, please edit it manually!', $this->textdomain)."</strong>";
					$error++;
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
								$notes[] = "<strong>". sprintf(__('WARNING: Archive directory ("%s") is a subdirectory in the WordPress root and is accessible via the web, this is an insecure configuration!', $this->textdomain), $realpath)."</strong>";
								$error++;
							}
						} else {
							$notes[] = "<strong>". __('ERROR: Archive directory ("%s") is not writable!', $this->textdomain)."</strong>";
							$error++;
						}
					}
				} else {
					$notes[] = "<strong>". sprintf(__('ERROR: Archive directory ("%s") does not exist!', $this->textdomain), $realpath)."</strong>";
					$error++;
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
							$notes[] = "<strong>". sprintf(__('WARNING: Excluded directory ("%s") is not found, removed from exclusions.', $this->textdomain), $dir)."</strong>";
							$error++;
						}
					}
				}

				if( $check_archive_excluded == TRUE && $archive_path_found == FALSE ) {
					$archive_dir = str_replace($abspath, '', $archive_path);
					$excluded[] = $archive_dir;
					$excluded_dir[] = $archive_dir;

					$notes[] = "<strong>". __('INFO: Archive path is in the WordPress directory tree but was not found in the exclusions, it has automatically been added.', $this->textdomain)."</strong>";
					$error++;
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
				$next_backup_time = $this->calculate_next_backup( $options['schedule'] );
			
				 wp_schedule_single_event($next_backup_time, 'cyan_backup_hook');
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
			if ( $error <= 0 )
				$notes[] = "<strong>".__('Configuration saved!', $this->textdomain)."</strong>";
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
		
		$out .= '<script type="text/javascript">//<![CDATA[' . "\n";
		
		$out .= 'function set_schedule_display() {' . "\n";
		$out .= 'var display_type_settings = new Array() ' . "\n\n";

		foreach( $display_type_settings as $key => $value ) {
			$out .= 'display_type_settings[\'' . $key . '\'] = new Array();' . "\n";
		}
		
		foreach( $display_type_settings as $key => $value ) {
			foreach( $value as $subkey => $subvalue ) {
				$out .= 'display_type_settings[\'' . $key . '\'][\'' . $subkey . '\'] = \'';
				if( $subvalue == "display: none;" ) { $out .= '0'; } else { $out .= '1'; }
				$out .= '\';' . "\n";
			}
		}
		
		$out .= "\n";
		
		$out .= 'var type = jQuery("#schedule_type").val();' . "\n";
		$out .= "\n";
		$out .= 'for( var i in display_type_settings[type] ) {' . "\n";
		$out .= 'if( display_type_settings[type][i] == 0 ) { jQuery("#" + i).css( "display", "none" ); } else { jQuery("#" + i).css( "display", "" ); }' . "\n";
		$out .= '}' . "\n";
		
		$out .= '}' . "\n";
		
		$out .= '//]]></script>' . "\n";
		
		$out .= '<div class="wrap">'."\n";

		$out .= '<div id="icon-options-general" class="icon32"><br /></div>';
		$out .= '<h2>';
		$out .= __('CYAN Backup Options', $this->textdomain);
		$out .= '</h2>'."\n";

		$out .= '<h3>';
		$out .= __('Directory Options', $this->textdomain);
		$out .= '</h3>'."\n";

		$out .= '<form method="post" id="option_update" action="'.$this->admin_action.'-options">'."\n";
		if ($this->wp_version_check('2.5') && function_exists('wp_nonce_field') )
			$out .= wp_nonce_field($nonce_field, self::NONCE_NAME, true, false);

		$out .= "<table class=\"optiontable form-table\" style=\"margin-top:0;\"><tbody>\n";

		$out .= '<tr>';
		$out .= '<th>'.__('Archive path', $this->textdomain).'</th>';
		$out .= '<td>';
		$out .= '<input type="text" name="archive_path" id="archive_path" size="100" value="'.htmlentities($archive_path).'" /><br><br>';
		$out .= '<input class="button" id="Createhtaccess" name="Createhtaccess" type="submit" value="' . __('Create .htaccess File', $this->textdomain) . '">&nbsp;';
		$out .= '<input class="button" id="CreateWebConfig" name="CreateWebConfig" type="submit" value="' . __('Create WebConfig File', $this->textdomain) . '">';
		$out .= '</td>';
		$out .= '</tr>'."\n";

		$out .= '<tr>';
		$out .= '<th>'.__('Excluded dir', $this->textdomain).'</th>';
		$out .= '<td><textarea name="excluded" id="excluded" rows="5" cols="100">';
		$abspath  = $this->chg_directory_separator(ABSPATH, FALSE);
		foreach ($excluded_dir as $dir) {
			$out .= htmlentities($this->chg_directory_separator($abspath.$dir,FALSE)) . "\n";
		}
		$out .= '</textarea><br><br>';
		$out .= '<input class="button" id="AddArchiveDir" name="AddArchiveDir" type="button" value="' . __('Add Archive Dir', $this->textdomain) . '" onClick="excluded.value = jQuery.trim( excluded.value ) + \'\n'. addslashes( $archive_path ) . '\';">&nbsp;';
		$out .= '<input class="button" id="AddWPContentDir" name="AddWPContentDir" type="button" value="' . __('Add WP-Content Dir', $this->textdomain) . '" onClick="excluded.value = jQuery.trim( excluded.value ) + \'\n'. addslashes( WP_CONTENT_DIR ) . '\';">&nbsp;';
		$out .= '<input class="button" id="AddWPContentDir" name="AddWPUpgradeDir" type="button" value="' . __('Add WP-Upgrade Dir', $this->textdomain) . '" onClick="excluded.value = jQuery.trim( excluded.value ) + \'\n'. addslashes( WP_CONTENT_DIR ) . '/upgrade\';">&nbsp;';
		$out .= '<input class="button" id="AddWPAdminDir" name="AddWPAdminDir" type="button" value="' . __('Add WP-Admin Dir', $this->textdomain) . '" onClick="excluded.value = jQuery.trim( excluded.value ) + \'\n'. addslashes( $abspath ) . 'wp-admin\';">&nbsp;';
		$out .= '<input class="button" id="AddWPIncludesDir" name="AddWPIncludesDir" type="button" value="' . __('Add WP-Includes Dir', $this->textdomain) . '" onClick="excluded.value = jQuery.trim( excluded.value ) + \'\n'. addslashes($abspath) . 'wp-includes\';">&nbsp;';
		$out .= '</td>';
		$out .= '</tr>'."\n";

		$out .= '</tbody></table>' . "\n";

		$out .= '<h3>';
		$out .= __('Schedule Options', $this->textdomain);
		$out .= '</h3>'."\n";

		$out .= "<table style=\"margin-top:0; width: auto;\"><tbody>\n";
		$out .= '<tr>';
		$out .= '<td class="description" style="width: auto; text-align: right; vertical-align: top;"><span class="description">' . __('Current server time', $this->textdomain) .'</span>:</td><td style="width: auto; text-align: left; vertical-align: top;"><code>';

		$next_schedule = time();
		$out .= date( get_option('date_format'), $next_schedule ) . ' @ ' . date( get_option('time_format'), $next_schedule );

		$out .= '</code></td>';
		$out .= '</tr>';
	
		if( $option['schedule']['enabled'] == 'on' ) { 
			$out .= '<tr>';
		
			$out .= '<td style="width: auto; text-align: right; vertical-align: top;"><span class="description">' . __('Next backup scheduled for', $this->textdomain) .'</span>:</td><td style="width: auto; text-align: left; vertical-align: top;"><code>';

			$next_schedule = wp_next_scheduled('cyan_backup_hook');

			if( $next_schedule ) {
				$out .= date( get_option('date_format'), $next_schedule ) . ' @ ' . date( get_option('time_format'), $next_schedule );
			}
			else {
				$out .= __('None', $this->textdomain );
			}

			$out .= '</code></td>';
			$out .= '</tr>';
		}

		$out .= '</tbody></table>' . "\n";
		
		$out .= "<table class=\"optiontable form-table\" style=\"margin-top:0;\"><tbody>\n";

		$out .= '<tr>';
		$out .= '<th>'.__('Enable', $this->textdomain).'</th>';
		$out .= '<td><input type=checkbox id="schedule_enabled" name="schedule[enabled]"';
		if( $option['schedule']['enabled'] == 'on' ) { $out .= ' CHECKED'; }
		$out .=	'></td>';
		$out .= '</tr>'."\n";

		$out .= '<tr>';
		$out .= '<th>'.__('Type', $this->textdomain).'</th>';
		$out .= '<td><select id="schedule_type" onChange="set_schedule_display();" name="schedule[type]">';
		
		foreach( $schedule_types as $type ) {
			$out .= '<option value="' . $type . '"';

			if( $option['schedule']['type'] == $type ) { $out .= ' SELECTED'; $display_settings = $display_type_settings[$type];}
			
			$out .= '>' . __($type, $this->textdomain) . '</option>';
		}
		
		$out .= '</select></td>';
		$out .= '</tr>'."\n";

		$out .= '<tr>';
		$out .= '<th>'.__('Schedule', $this->textdomain).'</th>';
		$out .= '<td>';
		
		if( self::DEBUG_MODE == TRUE ) {
			$out .= '<span id="schedule_debug" style="' . $display_settings['schedule_debug'] . '">' . __('Every minute, for debugging only', $this->textdomain) . '</span>';
		}
		
		$out .= '<span id="schedule_once" style="' . $display_settings['schedule_once'] . '">' . __('Only once', $this->textdomain) . '</span>';
		$out .= '<span id="schedule_before" style="' . $display_settings['schedule_before'] . '">' . __('Run backup every', $this->textdomain) . ' </span>';
		$out .= '<input type="text" id="schedule_interval" name="schedule[interval]" size="3" value="'. $option['schedule']['interval'] . '" style="' . $display_settings['schedule_interval'] . '">';
		$out .= '<span id="schedule_hours" style="' . $display_settings['schedule_hours'] . '"> ' . __('hour(s)', $this->textdomain) . '</span><span id="schedule_days" style="' . $display_settings['schedule_days'] . '"> ' . __('day(s)', $this->textdomain) . '</span><span id="schedule_weeks" style="' . $display_settings['schedule_weeks'] . '"> ' . __('week(s)', $this->textdomain) . '</span><span id="schedule_months" style="' . $display_settings['schedule_months'] . '"> ' . __('month(s)', $this->textdomain) . '</span>';
		$out .= '<span id="schedule_on" style="' . $display_settings['schedule_on'] . '"> ' . __('on', $this->textdomain) . '</span>';

		$out .= '<select id="schedule_dow" name="schedule[dow]" style="' . $display_settings['schedule_dow'] . '">';
		$out .= '<option value=""></option>';
		
		$weekdays = array( 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' );
		foreach( $weekdays as $day ) {
			$out .= '<option value="' . $day . '"';

			if( $option['schedule']['dow'] == $day ) { $out .= ' SELECTED'; }
			
			$out .= '>' . __($day, $this->textdomain) . '</option>';
		}
		
		$out .= '</select>';

		$out .= '<span id="schedule_the" style="' . $display_settings['schedule_the'] . '"> ' . __('the', $this->textdomain) . '</span>';
		
		$out .= '<select id="schedule_dom" name="schedule[dom]" style="' . $display_settings['schedule_dom'] . '">';
		$out .= '<option value=""></option>';
		
		for( $i = 1; $i < 28; $i++ ) {
			$out .= '<option value="' . $i . '"';

			if( $option['schedule']['dom'] == $i ) { $out .= ' SELECTED'; }
			
			$out .= '>' . $i . '</option>';
		}

		$out .= '</select>';
		
//		$out .= '<input type="text" id="schedule_dom" name="schedule[dom]" size="2" value="'. $option['schedule']['dom'] . '" style="' . $display_settings['schedule_dom'] . '">';
		$out .= '<span id="schedule_at" style="' . $display_settings['schedule_at'] . '"> ' . __('at', $this->textdomain) . '</span>';
		$out .= '<input type="text" id="schedule_tod" name="schedule[tod]" size="8" value="'. $option['schedule']['tod'] . '" style="' . $display_settings['schedule_tod'] . '">';
		$out .= '.</td>';
		$out .= '</tr>'."\n";

		$out .= '</tbody></table>' . "\n";

		$out .= '<h3>';
		$out .= __('Storage Maintenance', $this->textdomain);
		$out .= '</h3>'."\n";

		$out .= "<table class=\"optiontable form-table\" style=\"margin-top:0;\"><tbody>\n";

		$out .= '<tr>';
		$out .= '<th>'.__('Enable backup pruning', $this->textdomain).'</th>';
		$out .= '<td><input type=checkbox name="prune[enabled]"';
		if( $option['prune']['enabled'] == 'on' ) { $out .= ' CHECKED'; }
		$out .=	'></td>';
		$out .= '</tr>'."\n";

		$out .= '<tr>';
		$out .= '<th>'.__('Number of backups to keep', $this->textdomain).'</th>';
		$out .= '<td><input type="text" name="prune[number]" size="5" value="'. $option['prune']['number'] . '"></td>';
		$out .= '</tr>'."\n";
		
		$out .= '</tbody></table>' . "\n";

		$out .= '<p style="margin-top:1em;">';
		$out .= '<input type="submit" name="options_update" class="button-primary" value="'.__('Update Options', $this->textdomain).'" class="button" />';
		$out .= '</p>';

		$out .= '</form>'."\n";

		$out .= '</div>'."\n";

		// Output
		foreach( $notes as $note ) {
			echo '<div id="message" class="updated fade"><p>' . $note . '</p></div>';
			echo "\n";
		}

		echo $out;
		echo "\n";
?>