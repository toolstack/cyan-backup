=== CYAN Backup ===
Contributors: GregRoss
Plugin URI: http://toolstack.com/cyan-backup
Author URI: http://toolstack.com
Tags: Backup, Schedule
Requires at least: 2.9
Tested up to: 3.8.1
Stable tag: 1.3
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Backup your entire WordPress site and its database into a zip file on a schedule.

== Description ==

Backup your entire WordPress site and its database into a zip file on a schedule.

CYAN Backup is a fork of the great [Total Backup](http://wordpress.org/plugins/total-backup/) by [wokamoto](http://profiles.wordpress.org/wokamoto/).

Currently support schedules are hourly, daily, weekly and monthly with intervals for each (for example you could select a schedule of every 4 hours or every 6 weeks, etc.).

**PHP5 Required**

= Localization =

CYAN Backup is fully ready to be translated in to any supported languaged, if you have translated into your language, please let me know.

= Usage =

Configure the archive path which specifies the directory to store your backups to.  This must be writeable by the web server but should not be accessible via the web as a hacker could guess the filename and get a copy copy of your database.  If you must place the backups in a directory inside of the WordPress directory (or web server root) make sure to block extenal access via .htaccess or other means.  The default path is the directory for the temp files returned by sys_get_temp_dir().

Configure the excluded paths which specify the directories you don't want to back up.  The default excluded directories are:

* wp-content/cache/ : the directory for the cache files used by WP super cache and so on.
* wp-content/tmp/ : the directory for the cache files used by DB Cache Reloaded Fix so on.
* wp-content/upgrade/ : the directory for the temp files used by the WordPress upgrade function.

If you have configured your archive path below the main WordPress directory you MUST add it to the list of excluded directories as well.

Activate and configure the scheduler if you want to backup on a regular basis.  Schedule options include:

* Hourly (Backup your site every X hours, an hourly backup with an interval of 12 would run a backup twice a day).
* Daily (Backup your site every X days at a specific time.
* Weekly (Backup your site every X weeks at a specific day and time, for example every second Tuesday at 4am).
* Monthly (Backup your site every X months on a specific day and time, for example the 1st day of the month at 4am).

You can also enable auto pruning of old backups by setting the number of backup files you want to keep.

Backing up your site can take a while, you will want to ensure your PHP and webserver are configured to allow for the backup script to run long enough to complete the backup..

Once a backup is complete you can download the backup files from the links in Backup page.  You can delete old backup files by checking one or more boxes in the backup list and then clicking the Delete button.

The backup file of DB is included in the zip file as {the directory name of WordPress}.yyyymmdd.hhmmss.sql. 

== Installation ==

1. Extract the archive file into your plugins directory in the cyan-backup folder.
2. Activate the plugin in the Plugin options.
3. Configure the options.

== Frequently Asked Questions ==

= The backup runs for a while and then fails, what's wrong? =

This could be many things, but the most likely issue is your site is taking a long time to backup and the web server or PHP are timing out.  Make sure both have high enough time-out options set to let the backup complete.

= Something has gone horrible wrong and I can no longer run a backup, what can I do? =

CYAN Backup uses a status file to tell if a backup is running or not, if this file hasn't been deleted after a backup is complete you won't be able to run another backup for 30 minutes.  If you wish to force the deletion of the file, go in to Options and check the "Clear active backup status" and save the settings.  This will force the deletion of the file.

= The progress bar never updates until the backup is complete. =

The progress bar uses AJAX requests to the site to get the status, the backup process is pretty resource intensive, if your host cannot respond to the AJAX calls while the backup is running then you won't see the progress bar move until it does.

== Screenshots ==

1. Backups page.
2. Options page.
3. About page.

== Upgrade Notice ==
= 1.0 =
* None at this time.

== Changelog == 
= 1.3 =
* Added: E-Mail notifications.
* Updated: Manual backups now add the log download link to the backup list.
* Updated: Backup list formatting change for better display on smaller displays.

= 1.2.1 =
* Added: Log file deletion when zip is deleted.
* Fixed: Deletion of files through the backups page now works again.
* Fixed: Spurious error when deleting files.

= 1.2 =
* Added: Log file creation and download support.
* Removed: Duplicate download hook in backup class.

= 1.1.1 =
* Fixed: Spinning icon while backing up disappeared after first update of the progress bar.

= 1.1 =
* Added: Progress bar when manually backing up.
* Added: Code to avoid executing two backups at the same time.
* Added: When a backup is running and you go to the backup page, the current status will be displayed.
* Updated: Backup library now uses same text domain as main backup class.
* Updated: Exclusion buttons now display the appropriate slash for the OS you are running on.
* Removed: Old Windows based zip routine.  Now always use a PHP based library.

= 1.0 =
* Updated: Upgrade function now updates the schedule between V0.5 and V0.6 style configuration settings. 

= 0.7 =
* Fixed: Exclusion buttons in options.
* Updated: Translation files.

= 0.6 =
* Added: Check to see if web access to archive directory is enabled if it is inside of the WordPress directory.
* Added: Automatic addition of archive directory to the excluded directories list if it is inside of the WordPress directory.
* Added: Buttons to create .htaccess and Web.Config files in the archive directory.
* Added: Error message if the schedule failed to properly.
* Updated: Icon files.
* Updated: Split Backups/Settings/About pages out of the main file in to separate includes.
* Updated: Default exclusion list to not include the upload directory.
* Updated: Rewrote the scheduler code to better set the initial backup and handle more cases.
* Updated: Backup files now use YYYYMMDD.HHMMSS instead of YYYYMMDD.B, B could wrap around if multiple backups were done in a single day and cause the file list to display incorrectly.
* Updated: All fields in the scheduler are now drop down boxes instead of text input fields.
* Fixed: File times were being reported incorrectly due to GMT offset being applied twice.

= 0.5 =
* Renamed: Total Backup code base to CYAN backup.
* Added: About page.
* Added: check/uncheck all backup files checkbox.
* Added: support to display error messages when a backup fails beside the backup button.
* Added: After a backup completes and adds a row to the file list, it now adds the delete checkbox as well.
* Added: JavaScript buttons to add some common excluded directories to the excluded list.
* Fixed: error reporting when reporting transient or user access issues.
* Fixed: transient not being set before starting a backup.
* Fixed: delete checkbox column with new table style in WordPress 3.8.
* Fixed: Downloaded files now use "Content-Type: application/octet-stream;" instead of "Content-Type: application/x-compress;" to avoid the browser renaming the file.
* Updated: Grammatical items and other updates.
* Updated: First submenu in the top menu is no longer a repeat of the plugin name but "Backups".
* Updated: Date/time in the backup list now follow the format specified in the WordPress configuration.
* Updated: Errors and warnings when the options are saved now report in separate div's instead of being combined in to a single one.
* Updated: Replaced htmlspecial() with htmlentities() for more complete coverage.
* Updated: Added additional information to several error messages to make them clearer.

= 0.4 =
* Added: backup pruning.

= 0.3 =
* Added: scheduler backend.

= 0.2 =
* Fixed: support for PHP 5.3 with Magic Quotes GPC enabled.

= 0.1 =
* Initial fork from Total Backup.

== Road Map ==
* 1.4 - FTP support (local network only)
* 1.5 - FTPS support
* 1.6 - SFTP support
* 1.7 - SCP support
* 1.8 - Dropbox support
* 2.0 - Restore support
* 2.5 - Zip file creation without temp copy