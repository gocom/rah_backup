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
		foreach(array('get_tables', 'filter_ignored', 'lock_tables',  'dump', 'unlock_tables') as $method) {
			if($this->$method() === false) {
				return false;
			}
		}
	}
	
	/**
	 * Gets all tables
	 */
	
	public function get_tables() {
		if($this->tables = getThings('SHOW TABLES')) {
			return true;
		}
		
		return false;
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
		return safe_query('LOCK TABLES `' . implode('` WRITE, `', $this->tables).'` WRITE');
	}
	
	/**
	 * Unlock all tables
	 */
	
	public function unlock_tables() {
		return safe_query('UNLOCK TABLES');
	}
	
	/**
	 * Dump database table contents
	 */
	
	public function dump() {

		$fp = fopen($this->filename, 'wb');
		
		if(!$fp) {
			return false;
		}

		foreach($this->tables as $table) {
			
			$structure = getRow('SHOW CREATE TABLE `'.$table.'`');
			
			if(!$structure) {
				return;
			}
			
			$create = 
				n.'DROP TABLE IF EXISTS `' . $table . '`;'.
				n.end($structure).';'.n;
		
			if(fwrite($fp, $create, strlen($create)) === false) {
				return false;
			}
			
			$rs = startRows('SELECT * FROM `'.$table.'`');
			
			if(!$rs) {
				continue;
			}
			
			while($a = nextRow($rs)) {
				$insert = n.'INSERT INTO `'.$table.'` VALUES ('.implode(',', array_map(array($this, 'escape'), $a)).');';
				if(fwrite($fp, $insert, strlen($insert)) === false) {
					return false;
				}
			}
		}

		return fclose($fp);
	}

	/**
	 * Escapes SQL value
	 */
	
	public function escape($value) {
		if(is_null($value)) {
			return 'NULL';
		}
		
		return "'".doSlash($value)."'";
	}
}

?>