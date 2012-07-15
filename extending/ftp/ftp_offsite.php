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
		new rah_backup__ftp();
	}

class rah_backup__ftp {
	
	/**
	 * @var array Configuration stack
	 */
	
	public $cfg = array();
	
	/**
	 * @var array Accepted configuration options
	 */
	
	public $atts = array();
	
	/**
	 * Constructor
	 */
	
	public function __construct() {
		global $rah_backup__ftp;
		
		if($rah_backup__ftp && is_array($rah_backup__ftp)) {
			$this->cfg = $rah_backup__ftp;
		}
		
		$this->atts = array(
			'host' => '',
			'port' => 21,
			'user' => '',
			'pass' => '',
			'path' => '',
			'passive' => true,
			'as_binary' => true,
		);
		
		register_callback(array($this, 'sync'), 'rah_backup.created');
		register_callback(array($this, 'sync'), 'rah_backup.deleted');
		register_callback(array($this, 'requirements'), 'rah_backup', '', 1);
	}
	
	/**
	 * Requirements
	 */
	
	public function requirements() {
		if($this->cfg && !is_callable('ftp_connect')) {
			rah_backup::get()->announce(array(gTxt(__CLASS__.'_ftp_required'), E_ERROR));
		}
	}
	
	/**
	 * Syncs backup files over FTP
	 */
	
	public function sync() {
		
		if(!is_callable('ftp_connect')) {
			return;
		}
		
		foreach($this->cfg as $cfg) {
			
			extract(lAtts($this->atts, $cfg));
			
			if(!$host || !$port) {
				continue;
			}
			
			@$ftp = ftp_connect($host, $port);
			
			if(!$ftp) {
				rah_backup::get()->announce(array(gTxt(
					__CLASS__.'_connection_error',
					array('{host}' => $host.':'.$port)
				), E_ERROR));
				continue;
			}
			
			if(!ftp_login($ftp, $user, $pass)) {
				rah_backup::get()->announce(array(gTxt(
					__CLASS__.'_login_error',
					array('{host}' => $host, '{user}' => $user)
				), E_ERROR));
			}
			
			else {
				ftp_pasv($ftp, (bool) $cfg['passive']);
				
				if($path && @ftp_chdir($ftp, $path) === false) {
					rah_backup::get()->announce(array(gTxt(
						__CLASS__.'_chdir_error',
						array('{host}' => $host, '{user}' => $user)
					), E_ERROR));
				}
				
				else {
					
					foreach(rah_backup::get()->created as $name => $filepath) {
						if(ftp_put($ftp, $name, $filepath, $as_binary ? FTP_BINARY : FTP_ASCII) === false) {
							rah_backup::get()->announce(array(gTxt(
								__CLASS__.'_put_error',
								array('{host}' => $host)
							), E_ERROR));
						}
					}
					
					foreach(rah_backup::get()->deleted as $name => $filepath) {
						if(ftp_delete($ftp, $name) === false) {
							rah_backup::get()->announce(array(gTxt(
								__CLASS__.'_delete_error',
								array('{host}' => $host)
							), E_ERROR));
						}
					}
				}
			}
		
			ftp_close($ftp);
		}
	}
}

?>