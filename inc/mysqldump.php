<?php

/**
 * MySQLdump application written in PHP
 * 
 * @package rah_backup
 * @author Jukka Svahn <http://rahforum.biz/>
 * @copyright (c) 2012 Jukka Svahn
 * @license GLPv2
 */

class rah_backup_mysqldump {

	/**
	 * @var array All tables
	 */

	protected $tables = array();
	
	/**
	 * @var array Ignored tables
	 */
	
	public $ignored = array();
	
	/**
	 * @var string Saved file
	 */
	
	public $filename;
	
	/**
	 * Dumps
	 */
	
	public function run() {
		$this->get_tables();
		$this->filter_ignored();
		$this->dump();
	}
	
	/**
	 * Gets all tables
	 */
	
	public function get_tables() {
		$this->tables = getThings('SHOW TABLES');
	}
	
	/**
	 * Filter ignored
	 */
	
	public function filter_ignored() {
		foreach($this->tables as $key => $table) {
			if(in_array($table, $this->ignored)) {
				unset($this->tables[$key]);
			}
		}
	}
	
	/**
	 * Lock all tables
	 */
	
	public function lock_tables() {
		foreach($this->tables as $table) {
			safe_query('LOCK TABLES `'.$table.'` WRITE');
		}
	}
	
	/**
	 * Unlock all tables
	 */
	
	public function unlock_tables() {
		safe_query('UNLOCK TABLES');
	}
	
	/**
	 * Dump database table contents
	 */
	
	public function dump() {

		$fp = fopen($this->filename, 'wb');

		foreach($this->tables as $table) {
		
			$create = 
				n.'DROP TABLE IF EXISTS `' . $table . '`;'.
				n.end(getRow('SHOW CREATE TABLE `'.$table.'`')).';'.n;
		
			fwrite($fp, $create, strlen($create));
			
			$rs = startRows('SELECT * FROM `'.$table.'`');
			
			if(!$rs) {
				continue;
			}
			
			while($a = nextRow($rs)) {
				$insert = n.'INSERT INTO `'.$table.'` VALUES ('.implode(',', array_map(array($this, 'escape'), $a)).');';
				fwrite($fp, $insert, strlen($insert));
			}
		}

		fclose($fp);
	}

	/**
	 * Escapes SQL value
	 */
	
	public function escape($value) {
		if(is_null($value)) {
			return 'NULL';
		}
		
		if(is_int($value)) {
			return $value;
		}
		
		return "'".doSlash($value)."'";
	}
}

?>