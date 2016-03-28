jQuery( function($) {
	
	function buttons_disabled( disabled ) {
		jQuery('input[name="backup_site"]').attr( 'disabled', disabled );
	}

	function basename( path, suffix ) {
		// Returns the filename component of the path
		//
		// version: 910.820
		// discuss at: http://phpjs.org/functions/basename	// +   original by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
		// +   improved by: Ash Searle (http://hexmen.com/blog/)
		// +   improved by: Lincoln Ramsay
		// +   improved by: djmix
		// *	 example 1: basename('/www/site/home.htm', '.htm');	// *	 returns 1: 'home'
		// *	 example 2: basename('ecra.php?p=1');
		// *	 returns 2: 'ecra.php?p=1'
		var b = path.replace( /^.*[\/\\]/g, '' );
		if( typeof( suffix ) == 'string' && b.substr( b.length-suffix.length ) == suffix ) {
			b = b.substr( 0, b.length-suffix.length );
		}
		
		return b;
	}

	jQuery('#switch_checkboxes').click( function cyan_backup_toogle_checkboxes() {
		if( jQuery('#switch_checkboxes').attr( 'checked' ) ) {
			jQuery('[id^="removefiles"]').attr( 'checked', true );
		} else {
			jQuery('[id^="removefiles"]').attr( 'checked', false );
		}
	});

	jQuery("#progressbar").progressbar();

	var CYANBackupInterval = null;

	CYANBackupActivityCheck();

	function CYANBackupActivityCheck() {
		var args = {};
		
		args[0] = CYANBackupVariables( 'json_status_args' );
		args[1] = CYANBackupVariables( 'nonces_3' );

		jQuery.ajax( {
			async: true,
			cache: false,
			data: args,
			dataType: 'json',
			success: function( json, status, xhr ) {
				if( json.state == 'active' ) {
					var wrap = jQuery('#img_wrap');
					wrap.append( CYANBackupVariables( 'loading_img' ) );
					buttons_disabled( true );

					jQuery("#progressbar").progressbar( "enable" );

					if( CYANBackupInterval == null ) { CYANBackupInterval = setInterval( CYANBackupUpdater, 1000 ); }

					jQuery("#progressbar").progressbar( "value", parseInt( json.percentage ) );
					jQuery("#progresstext").html( json.message );
				}
			},
			type: CYANBackupVariables( 'json_method_type' ),
			url: CYANBackupVariables( 'json_status_url' ),
		});
	}

	function CYANBackupUpdater() {
		var args = {};
		
		args[0] = CYANBackupVariables( 'json_status_args' );
		args[1] = CYANBackupVariables( 'nonces_3' );

		jQuery.ajax( {
			async: true,
			cache: false,
			data: args,
			dataType: 'json',
			success: function( json, status, xhr ){
				if( CYANBackupInterval != null ) {
					jQuery("#progressbar").progressbar( "value", parseInt( json.percentage ) );
					jQuery("#progresstext").html( json.message );

					var wrap = jQuery('#img_wrap');

					if( json.state == 'complete' ) {
						var log_name = json.backup_file;
						var log_file = '';
						var backup_file = '<a href="?page=' + CYANBackupVariables( 'menu_base' ) + '&download=' + encodeURIComponent( json.backup_file ) + CYANBackupVariables( 'nonces_2' ) + '" title="' + basename( json.backup_file ) + '">' + basename( json.backup_file ) + '</a>';
						var rowCount = jQuery('#backuplist tr').length - 2;
						var tr = '';

						log_name = log_name.replace( ".zip",".log" );

						log_file = ' [<a href="?page=' + CYANBackupVariables( 'menu_base' ) + '&download=' + encodeURIComponent( log_name ) + CYANBackupVariables( 'nonces_2' ) + '" title="log">log</a>]';

						tr = jQuery('<tr><td>' + backup_file + log_file + '</td>' +
							'<td>' + json.backup_date  + '</td>' +
							'<td>' + json.backup_size  + '</td>' +
							'<td style="text-align: center;"><input type="checkbox" name="remove[' + ( rowCount )  + ']" value="' + CYANBackupVariables( 'archive_path' ) + basename( json.backup_file ) +'"></td></tr>');

						jQuery('img.success', wrap).remove();
						jQuery('img.failure', wrap).remove();
						jQuery('img.updating', wrap).remove();
						jQuery('div#message').remove();
						jQuery('span#error_message').remove();

						clearInterval( CYANBackupInterval );
						CYANBackupInterval = null;

						buttons_disabled( false );

						jQuery("#progressbar").progressbar( "disable" );

						wrap.append( CYANBackupVariables( 'success_img' ) );
						
						jQuery('#backuplist').prepend( tr );
					} else if( json.state == 'error' ) {
						clearInterval( CYANBackupInterval );
						CYANBackupInterval = null;

						jQuery('img.success', wrap).remove();
						jQuery('img.failure', wrap).remove();
						jQuery('img.updating', wrap).remove();
						jQuery('div#message').remove();
						jQuery('span#error_message').remove();

						buttons_disabled( false );

						jQuery("#progressbar").progressbar( "disable" );

						wrap.append( CYANBackupVariables( 'failure_img' ) + ' <span id="error_message">' + json.errors + '</span>' );
					}

				}
			},
			type: CYANBackupVariables( 'json_method_type' ),
			url: CYANBackupVariables( 'json_status_url' ),
		});
	}

	jQuery('input[name="backup_site"]').unbind('click').click( function(){
		var args = {};
		
		args[0] = CYANBackupVariables( 'json_status_args' );
		args[1] = CYANBackupVariables( 'nonces_1' );

		var wrap = jQuery(this).parent();
		
		jQuery('img.success', wrap).remove();
		jQuery('img.failure', wrap).remove();
		jQuery('div#message').remove();
		jQuery('span#error_message').remove();
		wrap.append( CYANBackupVariables( 'loading_img' ) );
		buttons_disabled( true );

		jQuery("#progressbar").progressbar( "enable" );
		jQuery("#progresstext").html( "Starting Backup..." );
		jQuery("#progressbar").progressbar( "value", 0 );

		if( CYANBackupInterval == null ) { CYANBackupInterval = setInterval( CYANBackupUpdater, 1000 ); }

		jQuery.ajax( {
			async: true,
			cache: false,
			data: args,
			dataType: 'json',
			success: function( json, status, xhr ){
				jQuery('img.updating', wrap).remove();
				buttons_disabled( false );
				jQuery("#progressbar").progressbar( "value", 100 );
				jQuery("#progresstext").html( "Backup complete!" );
			},
			type: CYANBackupVariables( 'json_method_type' ),
			url: CYANBackupVariables( 'json_backup_url' ),
		});

		return false;
	});
});