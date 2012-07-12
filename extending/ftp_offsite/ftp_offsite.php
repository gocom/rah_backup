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

	if(defined('txpinterface')) {
		new rah_backup__ftp_offsite();
	}

class rah_backup__ftp_offsite {
	
	protected $cfg = array();
	
	/**
	 * Constructor
	 */
	
	public function __construct() {
		global $rah_backup__ftp_offsite;
		
		if($rah_backup__ftp_offsite && is_array($rah_backup__ftp_offsite)) {
			$this->cfg = $rah_backup__ftp_offsite;
		}
		
		register_callback(array($this, 'upload'), 'rah_backup.created');
	}
	
	/**
	 * Sends new backup files to off site
	 */
	
	public function upload($event, $files) {
		
		if(!is_callable('ftp_connect')) {
			return;
		}
		
		foreach($this->cfg as $cfg) {
		
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
}

?>