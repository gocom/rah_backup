<?php

/**
 * Tranfers copies of backups made by rah_backup to Dropbox account
 * 
 * @package rah_backup
 * @author Jukka Svahn <http://rahforum.biz>
 * @copyright (c) 2011 Jukka Svahn
 * @license GLPv2
 *
 * Requires rah_backup, Ben Tadiar's Dropbox SDK <https://github.com/BenTheDesigner/Dropbox>,
 * PHP 5.3.1 or newer and cURL.
 */
 
 	if(defined('txpinterface')) {
 		new rah_backup__dropbox();
 	}

class rah_backup__dropbox {

	static public $version = '0.1';
	
	/**
	 * @var string User's consumer key
	 */
	
	protected $key;
	
	/**
	 * @var string User's consumer secret
	 */
	
	protected $secret;
	
	/**
	 * @var string Authencation string
	 */
	
	protected $token;
	
	/**
	 * @var string Path to Dropbox SDK's installation directory
	 */
	
	protected $api_dir;
	
	/**
	 * @var string Callback endpoint URL
	 */
	
	protected $callback_uri;
	
	/**
	 * @var obj Storage/session handler
	 */
	
	protected $storage;
	
	/**
	 * @var obj OAuth
	 */
	
	protected $oauth;
	
	/**
	 * @var obj Dropbox API
	 */
	
	protected $dropbox;
	
	/**
	 * Connected
	 */
	
	protected $connected = false;
	
	/**
	 * Installer
	 * @param string $event Admin-side event.
	 * @param string $step Admin-side, plugin-lifecycle step.
	 */

	static public function install($event='', $step='') {
		
		global $prefs;
		
		if($step == 'deleted') {
			
			safe_delete(
				'txp_prefs',
				"name like 'rah\_backup\__dropbox\_%'"
			);
			
			return;
		}
		
		$current = (string) get_pref(__CLASS__.'_version', 'base');
		
		if($current === self::$version)
			return;
		
		$position = 250;
		
		foreach(
			array(
				'api_dir' => array('text_input', './../dropbox'),
				'key' => array(__CLASS__.'_key', ''),
				'secret' => array(__CLASS__.'_key', ''),
				'token' => array(__CLASS__.'_token', ''),
			) as $name => $val
		) {
			$n = __CLASS__.'_'.$name;
			
			if(!isset($prefs[$n])) {
				set_pref($n, $val[1], 'rah_bckp_db',  1, $val[0], $position);
				$prefs[$n] = $val[1];
			}
			
			$position++;
		}
		
		set_pref(__CLASS__.'_version', self::$version, 'rah_bckp_db', 2, '', 0);
		$prefs[__CLASS__.'_version'] = self::$version;
	}

	/**
	 * Constructor
	 */

	public function __construct() {
		self::install();
		add_privs('plugin_prefs.'.__CLASS__, '1,2');
		register_callback(array($this, 'sync'), 'rah_backup.created');
		register_callback(array($this, 'sync'), 'rah_backup.deleted');
		register_callback(array($this, 'authentication'), 'textpattern');
		
		if(txpinterface == 'admin') {
			register_callback(array($this, 'unlink_account'), 'prefs');
			register_callback(array(__CLASS__, 'prefs'), 'plugin_prefs.'.__CLASS__);
			register_callback(array(__CLASS__, 'install'), 'plugin_lifecycle.'.__CLASS__);
		}
		
		$this->callback_uri = hu.'?'.__CLASS__.'_oauth=accesstoken';
		
		foreach(array('key', 'secret', 'api_dir', 'token') as $name) {
			$this->$name = get_pref(__CLASS__.'_'.$name);
		}
	}
	
	/**
	 * Import Dropbox SDK. We don't use autoloading due to this going to CMS
	 * @return bool
	 */
	
	public function import_api() {
		
		static $imported = false;
		
		if(!$this->api_dir || $imported == true) {
			return $imported;
		}
		
		if(strpos($this->api_dir, './') === 0) {
			$this->api_dir = txpath.'/'.substr($this->api_dir, 2);
		}
		
		$this->api_dir = rtrim($this->api_dir, '\\/');
	
		if(!file_exists($this->api_dir) || !is_dir($this->api_dir) || !is_readable($this->api_dir)) {
			return false;
		}
		
		foreach(array(
			'API.php',
			'Exception.php',
			'OAuth/Consumer/ConsumerAbstract.php',
			'OAuth/Consumer/Curl.php',
			'OAuth/Storage/Encrypter.php',
			'OAuth/Storage/StorageInterface.php',
			'OAuth/Storage/Session.php'
		) as $name) {
			$f = $this->api_dir.'/'.$name;
			
			if(!file_exists($f) || !is_file($f) || !is_readable($f)) {
				$this->api_dir = null;
				return false;
			}
			
			include_once $f;
		}
		
		$imported = true;
		return true;
	}
	
	/**
	 * Unlinks account
	 */
	
	public function unlink_account() {
		global $prefs;
		
		if(!gps('rah_backup__dropbox_unlink') || !has_privs('prefs')) {
			return;
		}
	
		foreach(array('key', 'secret', 'token') as $name) {
			$name = __CLASS__.'_'.$name;
			set_pref($name, '');
			$prefs[$name] = '';
			$this->$name = '';
		}
	}

	/**
	 * Authentication handler
	 */
	
	public function authentication() {
		
		$auth = (string) gps(__CLASS__.'_oauth');
		$method = 'auth_'.$auth;
		
		if(!$auth || $this->token || !$this->api_dir || !$this->key || !$this->secret || !method_exists($this, $method)) {
			return;
		}
		
		if(!$this->import_api()) {
			die(gTxt(__CLASS__.'_unable_import_api'));
		}
		
		$this->$method();
	}
	
	/**
	 * Redirects user to web endpoint
	 */
	
	protected function auth_authorize() {
		if(!$this->connect()) {
			die(gTxt(__CLASS__ . '_connection_error'));
		}
	}
	
	/**
	 * Gets token and writes it to DB
	 */
	
	protected function auth_accesstoken() {
		
		if(!$this->connect()) {
			die(gTxt(__CLASS__ . '_connection_error'));
		}
			
		$token = $this->storage->get('access_token');
		
		if(!$token) {
			exit(gTxt(__CLASS__ . '_token_error'));
		}
		
		set_pref(__CLASS__.'_token', json_encode($token), 'rah_bckp_db', 2, '', 0);
		exit(gTxt(__CLASS__.'_authenticated'));
	}

	/**
	 * Connect to Dropbox
	 * @return bool
	 */
	
	public function connect() {
		
		$this->import_api();
		
		if(!$this->key || !$this->secret || !$this->api_dir) {
			return false;
		}
		
		if($this->connected) {
			return true;
		}
		
		try {
			$this->storage = new \Dropbox\OAuth\Storage\Session();
		
			if($this->token) {
				$this->storage->set(json_decode($this->token), 'access_token');
			}
		
			$this->oauth = new \Dropbox\OAuth\Consumer\Curl($this->key, $this->secret, $this->storage, $this->callback_uri);
		
			if($this->token) {
				$this->dropbox = new \Dropbox\API($this->oauth);
			}
		}
		
		catch(exception $e) {
			rah_backup::get()->announce(array('Dropbox SDK said: '.$e->getMessage(), E_ERROR));
			return false;
		}
		
		$this->connected = true;
		return true;
	}
	
	/**
	 * Syncs backups
	 * @param string $event
	 * @param array $files
	 */
	
	public function sync($event, $files) {
		
		if(!$this->import_api()) {
			rah_backup::get()->announce(array(gTxt(__CLASS__.'_unable_import_api'), E_ERROR));
			return;
		}
	
		if(!$this->token || !$this->connect()) {
			return;
		}
		
		try {
			foreach(rah_backup::get()->deleted as $name => $path) {
				$this->dropbox->delete($name);
			}
			
			foreach(rah_backup::get()->created as $name => $path) {
				$this->dropbox->putFile($path, $name);
			}
		}
		
		catch(exception $e) {
			rah_backup::get()->announce(array('Dropbox SDK said: '.$e->getMessage(), E_ERROR));
		}
	}
	
	/**
	 * Redirect to the admin-side interface
	 */
	
	static public function prefs() {
		header('Location: ?event=prefs&step=advanced_prefs#prefs-rah_backup__dropbox_api_dir');
		
		echo 
			'<p>'.n.
			'	<a href="?event=prefs&amp;step=advanced_prefs#prefs-rah_backup__dropbox_api_dir">'.gTxt('continue').'</a>'.n.
			'</p>';
	}
}

	/**
	 * Options controller for token pref
	 * @return string HTML
	 */

	function rah_backup__dropbox_token() {
		
		if(
			!get_pref('rah_backup__dropbox_api_dir', '', true) || 
			!get_pref('rah_backup__dropbox_key', '', true) || 
			!get_pref('rah_backup__dropbox_secret', '', true)
		) {
			return 
				'<span class="navlink-disabled">'.gTxt('rah_backup__dropbox_authorize').'</span>'.n.
				'<span class="information">'.gTxt('rah_backup__dropbox_set_keys', array('{save}' => gTxt('save'))).'</span>';
		}
		
		if(get_pref('rah_backup__dropbox_token')) {
			return '<a class="navlink" href="?event=prefs'.a.'step=advanced_prefs'.a.'rah_backup__dropbox_unlink=1">'.gTxt('rah_backup__dropbox_unlink').'</a>';
		}
		
		return 
			'<a class="navlink" href="'.hu.'?rah_backup__dropbox_oauth=authorize">'.gTxt('rah_backup__dropbox_authorize').'</a>'.n.
			'<a class="navlink" href="?event=prefs'.a.'step=advanced_prefs'.a.'rah_backup__dropbox_unlink=1">'.gTxt('rah_backup__dropbox_reset').'</a>';
	}
	
	/**
	 * Options controller for app key
	 * @param string $name Field name
	 * @param string $value Current value
	 * @return string HTML
	 */

	function rah_backup__dropbox_key($name, $value) {
		
		if($value !== '') {
			$value = str_pad(substr($value, 0, 3), max(3, strlen($value)-3), '*');
			return fInput('text', $name.'_null', $value, '', '', '', '', '', '', true);
		}
		
		return text_input($name, $value);
	}

?>