<?php

class Rah_Backup_Archive_Config
{
	/**
	 * The file descriptor limit and a reset point.
	 *
	 * @var int 
	 */

	public $descriptor_limit = 200;

	/**
	 * Ignored files.
	 *
	 * @var array 
	 */

	public $ignored = array();
}