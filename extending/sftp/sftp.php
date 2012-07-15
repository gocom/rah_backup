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

	if(defined('txpinterface')) {
		new rah_backup__sftp();
	}

class rah_backup__sftp {
	
	/**
	 * @var array Configuration stack
	 */
	
	protected $cfg;
	
	/**
	 * @var array Accepted configuration options
	 */
	
	protected $atts;
	
	/**
	 * @var string Path to phpseclib installation directory
	 */
	
	protected $api_dir;

	/**
	 * Constructor
	 */
	
	public function __construct() {
		global $rah_backup__sftp;
		
		if($rah_backup__sftp && is_array($rah_backup__sftp)) {
			$this->cfg = $rah_backup__sftp;
		}
		
		if(defined('rah_backup__sftp_phpseclib_path')) {
			$this->api_dir = rtrim(rah_backup__sftp_phpseclib_path, '\\/');
		}
		
		$this->atts = array(
			'host' => '',
			'port' => 22,
			'timeout' => 90,
			'user' => '',
			'pass' => '',
			'path' => '',
		);
		
		register_callback(array($this, 'sync'), 'rah_backup.created');
		register_callback(array($this, 'sync'), 'rah_backup.deleted');
	}
	
	/**
	 * Import API
	 */
	
	public function import_api() {
	
		if(class_exists('Net_SFTP')) {
			return true;
		}
	
		if(!$this->api_dir || !file_exists($this->api_dir) || !is_dir($this->api_dir) || !is_readable($this->dir)) {
			return false;
		}
	
		$path = set_include_path($this->api_dir);
			
		if($path !== false) {
			include_once 'Net/SFTP.php';
			set_include_path($path);
		}
		
		return true;
	}
	
	/**
	 * Syncs backups over SFTP
	 */
	
	public function sync() {
		
		if(!$this->cfg) {
			return;
		}
		
		if(!$this->import_api()) {
			rah_backup::get()->announce(array(gTxt(
				__CLASS__.'_unable_import_api',
				array('{path}' => $this->api_dir)
			), E_ERROR));
			return;
		}
		
		foreach($this->cfg as $cfg) {
			
			extract(lAtts($this->atts, $cfg));
			
			if(!$host || !$port) {
				continue;
			}
			
			$sftp = new Net_SFTP($host, (int) $port, 90);
			
			if(!$sftp) {
				rah_backup::get()->announce(array(gTxt(
					__CLASS__.'_connection_error',
					array('{host}' => $host.':'.$port)
				), E_ERROR));
				continue;
			}
			
			if(!$sftp->login($user, $pass)) {
				rah_backup::get()->announce(array(gTxt(
					__CLASS__.'_login_error',
					array('{host}' => $host, '{user}' => $user)
				), E_ERROR));
				continue;
			}
			
			if($path && $sftp->chdir($path) === false) {
				rah_backup::get()->announce(array(gTxt(
						__CLASS__.'_chdir_error',
						array('{host}' => $host, '{user}' => $user)
				), E_ERROR));
				continue;
			}
				
			foreach(rah_backup::get()->created as $name => $filepath) {
				if($sftp->put($name, $filepath, NET_SFTP_LOCAL_FILE) === false) {
					rah_backup::get()->announce(array(gTxt(
						__CLASS__.'_put_error',
						array('{host}' => $host)
					), E_ERROR));
				}
			}
					
			foreach(rah_backup::get()->deleted as $name => $filepath) {
				if($sftp->delete($name) === false) {
					rah_backup::get()->announce(array(gTxt(
						__CLASS__.'_delete_error',
						array('{host}' => $host)
					), E_ERROR));
				}
			}
		}
	}
}

?>