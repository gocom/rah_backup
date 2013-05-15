<?php

class Rah_Backup_Archive_Config
{
	/**
	 * The filename.
	 *
	 * @var string
	 */

	public $file;

	/**
	 * The file descriptor limit and a reset point.
	 *
	 * @var int 
	 */

	public $descriptor_limit = 200;

	/**
	 * An array of ignored files.
	 *
	 * @var array 
	 */

	public $ignore = array();
}