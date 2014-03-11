<?php
if (!class_exists('CYAN_Utilities')) :

class CYAN_Utilities {
	private $notes = array();

	public function record_notes( $message, $level = 0 ) {
		$this->notes[] = array( $message, $level );
	}
	
	public function output_notes() {
		$result = '';
	
		foreach( $this->notes as $note ) {
			switch( $note[1] ) {
				case 0:
					$result .= '<div id="message" class="updated fade"><p>' . $note[0] . '</p></div>' . "\n";
					break;
				case 1:
					$result .= '<div id="message" class="updated fade" style="border-left: 4px solid #fbff1c;"><p>' . __('Warning') . ': ' . $note[0] . '</p></div>' . "\n";
					break;
				case 2:
					$result .= '<div id="message" class="error fade"><p>' . __('ERROR') . ': ' . $note[0] . '</p></div>' . "\n";
					break;
			}
		}
		
		return $result;
	}
	
	public function clear_notes() {
		$this->notes = array();
	}
	
	public function recursive_remove($dir, $status=null) {
		if (is_dir($dir)) {
			$files = scandir($dir);
			foreach ($files as $file) {
				if ($file != "." && $file != "..")
					$this->recursive_remove($dir . DIRECTORY_SEPARATOR . $file);
			}
			
			rmdir($dir);
		} else if (file_exists($dir)) {
			unlink($dir);
		}
	} 

	// sys get temp dir
	public function sys_get_temp_dir() {
		if (isset($_ENV['TMP']) && !empty($_ENV['TMP'])) 
			return realpath($_ENV['TMP']);
		if (isset($_ENV['TMPDIR']) && !empty($_ENV['TMPDIR'])) 
			return realpath($_ENV['TMPDIR']);
		if (isset($_ENV['TEMP']) && !empty($_ENV['TEMP'])) 
			return realpath($_ENV['TEMP']);
		$tempfile = tempnam(__FILE__,'');
		if (file_exists($tempfile)) {
			unlink($tempfile);
			return realpath(dirname($tempfile));
		}
		return null;
	}

	// get wp dir
	public function get_wp_dir($wp_dir = NULL) {
		return $this->chg_directory_separator(
			$wp_dir
			? $wp_dir
			: (defined('ABSPATH') ? ABSPATH : dirname(__FILE__))
			, FALSE);
	}

	// chg directory separator
	public function chg_directory_separator( $content, $url = TRUE ) {
		if ( DIRECTORY_SEPARATOR !== '/' ) {
			if ( $url === FALSE ) {
				if (!is_array($content)) {
					$content = str_replace('/', DIRECTORY_SEPARATOR, $content);
				} else foreach( $content as $key => $val ) {
					$content[$key] = $this->chg_directory_separator($val, $url);
				}
			} else {
				if (!is_array($content)) {
					$content = str_replace(DIRECTORY_SEPARATOR, '/', $content);
				} else foreach( $content as $key => $val ) {
					$content[$key] = $this->chg_directory_separator($val, $url);
				}
			}
		}
		return $content;
	}

	// get date and gmt
	public function get_date_and_gmt($aa = NULL, $mm = NULL, $jj = NULL, $hh = NULL, $mn = NULL, $ss = NULL) {
		$tz = date_default_timezone_get();
		if ($tz !== 'UTC')
			date_default_timezone_set('UTC');
		$time = time() + (int)get_option('gmt_offset') * 3600;
		if ($tz !== 'UTC')
			date_default_timezone_set( $tz );

		$aa = (int)(!isset($aa) ? date('Y', $time) : $aa);
		$mm = (int)(!isset($mm) ? date('n', $time) : $mm);
		$jj = (int)(!isset($jj) ? date('j', $time) : $jj);
		$hh = (int)(!isset($hh) ? date('G', $time) : $hh);
		$mn = (int)(!isset($mn) ? date('i', $time) : $mn);
		$ss = (int)(!isset($ss) ? date('s', $time) : $ss);

		$aa = ($aa <= 0 ) ? date('Y', $time) : $aa;
		$mm = ($mm <= 0 ) ? date('n', $time) : $mm;
		$jj = ($jj > 31 ) ? 31 : $jj;
		$jj = ($jj <= 0 ) ? date('j', $time) : $jj;
		$hh = ($hh > 23 ) ? $hh -24 : $hh;
		$mn = ($mn > 59 ) ? $mn -60 : $mn;
		$ss = ($ss > 59 ) ? $ss -60 : $ss;
		$date = sprintf( "%04d-%02d-%02d %02d:%02d:%02d", $aa, $mm, $jj, $hh, $mn, $ss );
		$date_gmt = get_gmt_from_date( $date );

		return array('date' => $date, 'date_gmt' => $date_gmt);
	}

	// get filemtime
	public function get_filemtime($file_name) {
		$filemtime = filemtime($file_name);//  + (int)get_option('gmt_offset') * 3600;
		$date_gmt  = $this->get_date_and_gmt(
			(int)date('Y', $filemtime),
			(int)date('n', $filemtime),
			(int)date('j', $filemtime),
			(int)date('G', $filemtime),
			(int)date('i', $filemtime),
			(int)date('s', $filemtime)
			);
		$filemtime =
			isset($date_gmt['date'])
			? $date_gmt['date']
			: date("Y-m-d H:i:s.", $filemtime)
			;
		return $filemtime;
	}


}

endif;