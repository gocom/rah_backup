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
 * @global array $rah_backup__module_ftp_offsite
 */

	global $rah_backup__module_ftp_offsite;

/**
 * Your configuration. Used to connect to remote server.
 * @global string $host FTP server's address (i.e. domain.ltd or IP).
 * @global int $port Remote server's FTP port
 * @global string $user Remote server's username.
 * @global string $pass Remote server's password.
 * @global string $path Path to directory used to store the backups on the remote server.
 * @global bool $passive Turns passive mode on or off.
 * @global bool $as_binary Transfer files in binary mode. If FALSE, ASCII mode is used instead.
 */

	$rah_backup__module_ftp_offsite[] = array(
		'host' => '',
		'port' => 21,
		'user' => '',
		'pass' => '',
		'path' => '/path/to/remote/directory/',
		'passive' => true,
		'as_binary' => true,
	);

/**
 * Registers the function. Hook to event 'rah_backup.done'.
 */

	if(defined('txpinterface')) {
		register_callback('rah_backup__module_ftp_offsite', 'rah_backup.created');
	}

/**
 * Sends new backup files to off site
 */

	function rah_backup__module_ftp_offsite() {
		
		global $rah_backup__module_ftp_offsite;
		
		if(!is_callable('ftp_connect')) {
			return;
		}
		
		foreach((array) $rah_backup__module_ftp_offsite as $cfg) {
		
			if(!$cfg['host'] || (($ftp = ftp_connect($cfg['host'], $cfg['port'])) && !$ftp)) {
				continue;
			}
			
			if(@ftp_login($ftp, $cfg['user'], $cfg['pass'])) {
				ftp_pasv($ftp, (bool) $cfg['passive']);
				
				if(!$cfg['path'] || @ftp_chdir($ftp, $cfg['path'])) {
					$file = rah_backup::get()->created;
					@ftp_put($ftp, basename($file), $file, ($cfg['as_binary'] ? FTP_BINARY : FTP_ASCII));
				}
			}
		
			ftp_close($ftp);
		}
		
		return;
	}
?>