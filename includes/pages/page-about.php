<?php 
	if( !is_admin() ) {
		wp_die( __( 'Access denied!', 'cyan-backup' ) );
	}
	
	$this->verify_status_file();

?>
<div class="wrap">
	<table class="form-table">
		<tbody>
			<tr valign="top">
				<td scope="row" align="center"><img src="<?php echo plugins_url( 'cyan-backup/images/cyan-backup.png' ); ?>"></td>
			</tr>

			<tr valign="top">
				<td scope="row" align="center">
					<h2><?php echo sprintf( __( 'CYAN Backup V%s', 'cyan-backup' ), self::VERSION ); ?></h2>
					<p><?php _e( 'by', 'cyan-backup' );?> <a href="https://profiles.wordpress.org/gregross" target=_blank>Greg Ross</a></p>
				</td>
			</tr>

			<tr valign="top">
				<td scope="row" align="center"><hr /></td>
			</tr>

			<tr valign="top">
				<td scope="row" colspan="2"><h2><?php _e( 'More Infomration', 'cyan-backup' ); ?></h2></td>
			</tr>
			
			<tr valign="top">
				<td scope="row" colspan="2" style="padding-left:5%;">
					<p><?php printf( __( 'A fork of the great %sTotal Backup%s by %swokamoto%s.', 'cyan-backup' ), '<a href="http://wordpress.org/plugins/total-backup/" target=_blank>', '</a>', '<a href="http://profiles.wordpress.org/wokamoto/" target=_blank>', '</a>' );?></p>
					<p>&nbsp;</p>
					<p><?php printf( __( 'To find out more, please visit the %sWordPress Plugin Directory page%s or the plugin home page on %sToolStack.com%s', 'cyan-backup' ), '<a href="http://wordpress.org/plugins/cyan-backup/" target=_blank>', '</a>', '<a href="http://toolstack.com/cyan-backup" target=_blank>', '</a>' );?></p>
				</td>
			</tr>

			<tr valign="top">
				<td scope="row" colspan="2"><h2><?php _e( 'Rate and Review at WordPress.org', 'cyan-backup' ); ?></h2></td>
			</tr>
			
			<tr valign="top">
				<td scope="row" colspan="2" style="padding-left:5%;"><?php _e( 'Thanks for installing CYAN Backup, I encourage you to submit a ', 'cyan-backup' );?> <a href="http://wordpress.org/support/view/plugin-reviews/cyan-backup" target="_blank"><?php _e( 'rating and review', 'cyan-backup' ); ?></a> <?php _e( 'over at WordPress.org.  Your feedback is greatly appreciated!', 'cyan-backup' );?></td>
			</tr>
			
			<tr valign="top">
				<td scope="row" colspan="2"><h2><?php _e( 'License', 'cyan-backup' ); ?></h2></td>
			</tr>
			
			<tr valign="top">
				<td scope="row" colspan="2" style="padding-left:5%;"><?php printf( __( 'Licenced under the %sGPL Version 2%s', 'cyan-backup' ), '<a href="http://www.gnu.org/licenses/gpl-2.0.html" target=_blank>', '</a>' );?></td>
			</tr>

			<tr valign="top">
				<td scope="row" colspan="2"><h2><?php _e( 'Support' ); ?></h2></td>
			</tr>

			<tr valign="top">
				<td scope="row" colspan="2" style="padding-left:5%;">
					<p><?php _e("Here are a few things to do submitting a support request:", 'cyan-backup' ); ?></p>

					<ul style="list-style-type: disc; list-style-position: inside; padding-left: 25px;">
						<li><?php echo sprintf( __( 'Have you read the %s?', 'cyan-backup' ), '<a title="' . __( 'FAQs', 'cyan-backup' ) . '" href="http://os-integration.com/?page_id=19" target="_blank">' . __( 'FAQs', 'cyan-backup' ). '</a>' );?></li>
						<li><?php echo sprintf( __( 'Have you search the %s for a similar issue?', 'cyan-backup' ), '<a href="http://wordpress.org/support/plugin/cyan-backup" target="_blank">' . __( 'support forum', 'cyan-backup' ) . '</a>' );?></li>
						<li><?php _e( 'Have you search the Internet for any error messages you are receiving?', 'cyan-backup' );?></li>
						<li><?php _e( 'Make sure you have access to your PHP error logs.', 'cyan-backup' );?></li>
					</ul>

					<p><?php _e( 'And a few things to double-check:', 'cyan-backup' );?></p>

					<ul style="list-style-type: disc; list-style-position: inside; padding-left: 25px;">
						<li><?php _e( 'Have you double checked the plugin settings?', 'cyan-backup' );?></li>
						<li><?php _e( 'Do you have all the required PHP extensions installed?', 'cyan-backup' );?></li>
						<li><?php _e( 'Are you getting a blank or incomplete page displayed in your browser?  Did you view the source for the page and check for any fatal errors?', 'cyan-backup' );?></li>
						<li><?php _e( 'Have you checked your PHP and web server error logs?', 'cyan-backup' );?></li>
					</ul>

					<p><?php _e( 'Still not having any luck?' );?> <?php echo sprintf( __( 'Then please open a new thread on the %s.', 'cyan-backup' ), '<a href="http://wordpress.org/support/plugin/cyan-backup" target="_blank">' . __( 'WordPress.org support forum', 'cyan-backup' ) . '</a>' );?></p>
				</td>
			</tr>

		</tbody>
	</table>
</div>