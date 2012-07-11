<?php

/**
 * Transfers backups made by rah_backup to offsite location
 * via SSH File Transfer Protocol.
 * 
 * @package rah_backup
 * @author Jukka Svahn <http://rahforum.biz/>
 * @copyright (c) 2011 Jukka Svahn
 * @license GLPv2
 * 
 * The module requires phpseclib (SFTP API) <http://phpseclib.sourceforge.net/>.
 * Only compatible with UNIX-platforms, SFTPv3 and only support 
 * transfering in binary-mode.
 */

/**
 * Registers the function. Hook to event 'rah_backup.done'
 */

	if(defined('txpinterface')) {
		register_callback('rah_backup__sftp_offsite', 'rah_backup.created');
	}

/**
 * Sends new backup files to remote server
 */

	function rah_backup__sftp_offsite($event, $files) {
		
		global $rah_backup__sftp_offsite;
		
		foreach((array) $rah_backup__sftp_offsite as $cfg) {
			
			if(empty($cfg['host'])) {
				continue;
			}
				
			if(!class_exists('Net_SFTP')) {
				$path = set_include_path($cfg['phpseclib_path']);
				
				if($path !== false) {
					include_once 'Net/SFTP.php';
					set_include_path($path);
				}
			}
			
			$sftp = new Net_SFTP($cfg['host'], (int) $cfg['port'], 90);
			
			if($sftp->login($cfg['user'], $cfg['pass'])) {
				if(!$cfg['path'] || $sftp->chdir($cfg['path'])) {
					foreach($files as $name => $path) {
						$sftp->put($name, $path, NET_SFTP_LOCAL_FILE);
					}
				}
			}
		}
	}
?>