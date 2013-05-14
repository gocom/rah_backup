<?php

/**
 * Configuration.
 */

class Rah_Backup_Danpu_Dump extends Rah_Danpu_Dump
{
	/**
	 * Constructor.
	 */

	public function __construct()
	{
		global $txpcfg;
		$this->dsn = 'mysql:dbname='.$txpcfg['db'].';host='.$txpcfg['host'];
		$this->user = $txpcfg['user'];
		$this->pass = $txpcfg['pass'];
		$this->tmp = get_pref('tempdir');
	}
}