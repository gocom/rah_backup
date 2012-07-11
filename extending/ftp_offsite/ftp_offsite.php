<?php

/**
 * Transfers backups made by rah_backup to offsite location via FTP.
 * 
 * @package rah_backup
 * @author Jukka Svahn <http://rahforum.biz>
 * @copyright (c) 2011 Jukka Svahn
 * @license GLPv2
 *
 * This rah_backup module requires PHP's FTP extension.
 * <http://www.php.net/manual/en/book.ftp.php>
 */

/**
 * Registers the function. Hook to event 'rah_backup.done'.
 */

	if(defined('txpinterface')) {
		register_callback('rah_backup__module_ftp_offsite', 'rah_backup.created');
	}

/**
 * Sends new backup files to off site
 */

	function rah_backup__module_ftp_offsite($event, $files) {
		
		global $rah_backup__module_ftp_offsite;
		
		if(!is_callable('ftp_connect')) {
			return;
		}
		
		foreach((array) $rah_backup__module_ftp_offsite as $cfg) {
		
			if(empty($cfg['host']) || (($ftp = ftp_connect($cfg['host'], $cfg['port'])) && !$ftp)) {
				continue;
			}
			
			if(@ftp_login($ftp, $cfg['user'], $cfg['pass'])) {
				ftp_pasv($ftp, (bool) $cfg['passive']);
				
				if(!$cfg['path'] || @ftp_chdir($ftp, $cfg['path'])) {
					foreach($files as $name => $path) {
						@ftp_put($ftp, $name, $path, $cfg['as_binary'] ? FTP_BINARY : FTP_ASCII);
					}
				}
			}
		
			ftp_close($ftp);
		}
		
		return;
	}
?>