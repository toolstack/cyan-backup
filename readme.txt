=== CYAN Backup ===
Contributors: GregRoss
Plugin URI: http://toolstack.com/cyan-backup
Author URI: http://toolstack.com
Tags: Backup, Schedule
Requires at least: 2.9
Tested up to: 3.8.1
Stable tag: 0.5
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
* wp-content/uploads/ : the directory for the uploaded files like images.

If you have configured your archive path below the main WordPress directory you MUST add it to the list of excluded directories as well.

Activate and configure the scheduler if you want to backup on a regular basis.  Schedule options include:

* Hourly (Backup your site every X hours, an hourly backup with an interval of 12 would run a backup twice a day).
* Daily (Backup your site every X days at a specific time.
* Weekly (Backup your site every X weeks at a specific day and time, for example every second Tuesday at 4am).
* Monthly (Backup your site every X months on a specific day and time, for example the 1st day of the month at 4am).

You can also enable auto pruning of old backups by setting the number of backup files you want to keep.

Backing up your site can take a while, you will want to ensure your PHP and webserver are configured to allow for the backup script to run long enough to complete the backup..

Once a backup is complete you can download the backup files from the links in Backup page.  You can delete old backup files by checking one or more boxes in the backup ulist and then clicking the Delete button.

The backup file of DB is included in the zip file as {the directory name of WordPress}.yyyymmdd.xxx.sql. 

== Installation ==

1. Extract the archive file into your plugins directory in the cyan-backup folder.
2. Activate the plugin in the Plugin options.
3. Configure the options.

== Frequently Asked Questions ==

= The backup runs for a while and then fails, what's wrong? =

This could be many things, but the most likely issue is your site is taking a long time to backup and the web server or PHP are timing out.  Make sure both have high enough time-out options set to let the backup complete.

== Screenshots ==

1. Backups page.
2. Options page.
3. About page.

== Upgrade Notice ==
= 0.5 =
* None at this time.

== Changelog == 
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
* 0.5 - Initial release
* 1.0 - Stability release
* 1.1 - Progress bar
* 1.2 - Logging
* 1.3 - email notifications/reporting
* 1.4 - FTP support (local network only)
* 1.5 - FTPS support
* 1.6 - SFTP support
* 1.7 - SCP support
* 1.8 - Dropbox support
* 2.0 - Restore support
* 2.5 - Zip file creation without temp copy