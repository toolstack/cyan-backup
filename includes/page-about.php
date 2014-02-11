<?php 
	if( !is_admin() )
		wp_die(__('Access denied!', $this->textdomain));
?>
<div class="wrap">

	<div id="icon-options-cyan-backup" class="icon32"><br /></div>

	<fieldset style="border:1px solid #cecece;padding:15px; margin-top:25px" >
		<legend><span style="font-size: 24px; font-weight: 700;">&nbsp;<?php _e('About CYAN Backup', $this->textdomain);?>&nbsp;</span></legend>
		<img src="<?php echo $this->plugin_url; ?>images/cyan-backup.png" />
		<h2><?php printf(__('CYAN Backup Version %s', $this->textdomain), self::VERSION );?></h2>
		<p><?php _e('by', $this->textdomain);?> <a href="https://profiles.wordpress.org/gregross" target=_blank>Greg Ross</a></p>
		<p>&nbsp;</p>
		<p><?php printf( __('A fork of the great %sTotal Backup%s by %swokamoto%s.', $this->textdomain), '<a href="http://wordpress.org/plugins/total-backup/" target=_blank>', '</a>', '<a href="http://profiles.wordpress.org/wokamoto/" target=_blank>', '</a>');?></p>
		<p>&nbsp;</p>
		<p><?php printf(__('Licenced under the %sGPL Version 2%s', $this->textdomain), '<a href="http://www.gnu.org/licenses/gpl-2.0.html" target=_blank>', '</a>');?></p>
		<p><?php printf(__('To find out more, please visit the %sWordPress Plugin Directory page%s or the plugin home page on %sToolStack.com%s', $this->textdomain), '<a href="http://wordpress.org/plugins/cyan-backup/" target=_blank>', '</a>', '<a href="http://toolstack.com/cyan-backup" target=_blank>', '</a>');?></p>
		<p>&nbsp;</p>
		<p><?php printf(__("Don't forget to %srate and review%s it too!", $this->textdomain), '<a href="http://wordpress.org/support/view/plugin-reviews/cyan-backup" target=_blank>', '</a>');?></p>
	</fieldset>
		
</div>