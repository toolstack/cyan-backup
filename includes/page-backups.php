<?php
		$out   = '';
		$note  = '';
		$error = 0;
		$nonce_field = 'backup';

		if (isset($_POST['remove_backup'])) {
			if ($this->wp_version_check('2.5') && function_exists('check_admin_referer'))
				check_admin_referer($nonce_field, self::NONCE_NAME);
			if (isset($_POST['remove'])) {
				$postdata = $this->get_real_post_data();
				$count = 0;
				foreach((array)$_POST['remove'] as $index => $bfile) {
					$file = $postdata['remove[' . $index . ']'];
					if (($file = realpath($file)) !== FALSE) {
						if (@unlink($file))
							$count ++;
					}
				}
				if ($count > 0) {
					$note .= "<strong>".__('Delete Backup Files!', $this->textdomain)."</strong>";
				}
			}
		}

		$nonces =
			( $this->wp_version_check('2.5') && function_exists('wp_nonce_field') )
			? wp_nonce_field($nonce_field, self::NONCE_NAME, true, false)
			: '';

		$out .= '<div class="wrap">'."\n";

		$out .= '<div id="icon-options-cyan-backup" class="icon32"><br /></div>';
		$out .= '<h2>';
		$out .= __('CYAN Backup', $this->textdomain);
		$out .= '</h2>'."\n";

		$out .= '<h3>';
		$out .= __('Run Backup', $this->textdomain);
		$out .= '</h3>'."\n";

		$out .= '<form method="post" id="backup_site" action="'.$this->admin_action.'">'."\n";
		$out .= $nonces;
		$out .= '<input type="hidden" name="backup_site" class="button-primary sites_backup" value="'.__('Backup Now!', $this->textdomain).'" class="button" style="margin-left:1em;" />';
		$out .= '<p style="margin-top:1em">';
		$out .= '<input type="submit" name="backup_site" class="button-primary sites_backup" value="'.__('Backup Now!', $this->textdomain).'" class="button" style="margin-left:1em;" />';
		$out .= '</p>';
		$out .= '</form>'."\n";


		$out .= '<h3>';
		$out .= __('Backup Files', $this->textdomain);
		$out .= '</h3>'."\n";

		$out .= '<form method="post" action="'.$this->admin_action.'">'."\n";
		$out .= $nonces;

		$out .= '<table id="backuplist" class="wp-list-table widefat fixed" style="margin-top:0;">'."\n";

		$out .= '<thead><tr>';
		$out .= '<th>' . __('File Name', $this->textdomain) . '</th>';
		$out .= '<th>' . __('Date and Time', $this->textdomain) . '</th>';
		$out .= '<th>' . __('Size', $this->textdomain) . '</th>';
		$out .= '<th style="text-align: center;"><input type="checkbox" id="switch_checkboxes" name="switch_checkboxes" style="margin: 0px 4px 0px 0px;" /></th>';
		$out .= '</tr></thead>' . "\n";

		$out .= '<tfoot><tr>';
		$out .= '<th colspan="3">';
		$out .= '</th>';
		$out .= '<th style="width: 75px; text-align: center;"><input type="submit" name="remove_backup" class="button-primary" value="'.__('Delete', $this->textdomain).'" class="button" /></th>';
		$out .= '</tr></tfoot>' . "\n";

		$out .= '<tbody>';

		$backup_files = $this->backup_files_info($this->get_backup_files());
		$alternate = ' class="alternate"';
		if (count($backup_files) > 0) {
			$i = 0;
			foreach ($backup_files as $backup_file) {
				$out .= "<tr{$alternate}>";
				$out .= sprintf('<td>%s</td>', $backup_file['url']);

				$temp_time = strtotime( $backup_file['filemtime'] );

				$out .= sprintf('<td>%s</td>', date( get_option('date_format'), $temp_time ) . ' @ ' . date( get_option('time_format'), $temp_time ));
				$out .= sprintf('<td>%s MB</td>', number_format($backup_file['filesize'], 2));
				$out .= "<td style='text-align: center;'><input type=\"checkbox\" id=\"removefiles[{$i}]\" name=\"remove[{$i}]\" value=\"{$backup_file['filename']}\" /></td>";
				$out .= '</tr>' . "\n";
				$i++;
				$alternate = empty($alternate) ? ' class="alternate"' : '';
			}
		}

		$out .= '</tbody>' . "\n";
		$out .= '</table>' . "\n";
		$out .= '</form>'."\n";

		$out .= '</div>'."\n";

		// Output
		echo ( !empty($note) ? '<div id="message" class="updated fade"><p>'.$note.'</p></div>'  : '' );
		echo "\n";

		echo ( $error <= 0 ? $out : '' );
		echo "\n";
?>