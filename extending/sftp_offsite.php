<?php

/**
 * Transfers backups made by rah_backup to offsite location
 * via SSH File Transfer Protocol.
 * 
 * @package stfp_offsite
 * @author Jukka Svahn <http://rahforum.biz/>
 * @copyright (c) 2011 Jukka Svahn
 * @license GLPv2
 * 
 * The module requires phpseclib (SFTP API) <http://phpseclib.sourceforge.net/>.
 * Only compatible with UNIX-platforms, SFTPv3 and only support 
 * transfering in binary-mode.
 */

/**
 * @global $rah_backup__module_sftp_offsite array
 */

	global $rah_backup__module_sftp_offsite;

/**
 * Your configuration. Used to connect to remote server.
 * @global $host string SFTP server's address (i.e. domain.ltd or IP).
 * @global $port int Remote server's SSH (SFTP) port.
 * @global $user string Remote server's username.
 * @global $pass string Remote server's password.
 * @global $path string Path to directory used to store the backups on the remote server.
 * @global $phpseclib_path string Absolute path to phpseclib's installation directory. The directory should cointain sub-directories as "Crypt" and "Net".
 */

	$rah_backup__module_sftp_offsite[] = array(
		'host' => '',
		'port' => 22,
		'user' => '',
		'pass' => '',
		'path' => '/path/to/remote/directory/',
		'phpseclib_path' => '/path/to/phpseclib/installation/directory/',
	);

/**
 * Registers the function. Hook to event 'rah_backup_tasks', step 'backup_done'.
 */

	register_callback('rah_backup__module_sftp_offsite','rah_backup_tasks','backup_done');

/**
 * Sends new backup files to remote server
 * @param $event string Callback event.
 * @param $step string Callback step.
 * @param $data mixed Data passed to the callback function.
 * @return bool
 */

	function rah_backup__module_sftp_offsite($event, $step, $data) {
		
		global $rah_backup__module_sftp_offsite;
		
		foreach((array) $rah_backup__module_sftp_offsite as $cfg) {
			
			if(!$cfg['host'])
				continue;
				
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
					foreach($data['files'] as $file) {
						$sftp->put(basename($file), $file, NET_SFTP_LOCAL_FILE);
					}
				}

			}
		}
		
		return true;
	}
?>