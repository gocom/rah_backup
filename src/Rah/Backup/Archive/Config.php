<?php

/**
 * The configuration options.
 */

abstract class Rah_Backup_Archive_Config
{
	/**
	 * The filename.
	 *
	 * @var string
	 */

	public $file;

    /**
     * Source files or directories.
     */

    public $source = array();

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

    /**
     * Path to temporary directory.
     *
     * @var string
     */

    public $tmp = '/tmp';
}