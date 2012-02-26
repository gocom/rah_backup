<?php

/**
 * This is an exmple configuration file for rah_backup.
 * The file's contents could added to Textpattern's configuration file
 * (i.e. /textpattern/config.php).
 *
 * The file shows available core options (static vcs directory), and some options
 * that are for rah_backup's plugins.
 */

/**
 * Sets connection details for FTP_offsite module
 * See ../extending/ftp_offsite.php for detailed descriptions.
 */

$rah_backup__module_ftp_offsite[] = array(
	'host' => '',
	'port' => 21,
	'user' => '',
	'pass' => '',
	'path' => '/absolute/path/to/remote/directory',
	'passive' => true,
	'as_binary' => true,
);

/**
 * Sets connection details for SFTP_offsite module.
 * See ../extending/sftp_offsite.php for detailed descriptions.
 */

$rah_backup__module_sftp_offsite[] = array(
	'host' => '',
	'port' => 22,
	'user' => '',
	'pass' => '',
	'path' => '/absolute/path/to/remote/directory',
	'phpseclib_path' => '/absolute/path/to/phpseclib/installation/dir',
);

?>