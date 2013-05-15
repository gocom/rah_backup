<?php

/**
 * Base class.
 */

abstract class Rah_Backup_Archive_Base
{
	/**
	 * The config.
	 *
	 * @var Rah_Backup_Archive_Config
	 */

	protected $config;

	/**
	 * An instance of ZipArchive.
	 *
	 * @var ZipArchive
	 */

	protected $zip;

    /**
     * Path to a temporary file.
     *
     * @var string
     */

    protected $temp;

	/**
	 * Constructor.
	 *
	 * @param Rah_Backup_Archive_Config
	 */

	public function __construct($config)
	{
		$this->config = $config;
        //$this->temp = tempnam($this->config->tmp, 'Rah_Zip');

		if (!class_exists('ZipArchive'))
		{
			throw new Exception('ZipArchive is not installed.');
		}

		$this->zip = new ZipArchive();
		$this->init();
	}

	/**
	 * Normalizes a filename.
	 *
	 * @param  string $path
	 * @return string
	 */

	protected function normalizePath($path)
	{
		return rtrim(str_replace('\\', '/', $path), '/');
	}

	/**
	 * Opens a file.
	 *
	 * @param int $flags
	 */

	protected function open($flags = ZIPARCHIVE::OVERWRITE)
	{
		if ($this->zip->open($this->config->file, $flags) !== true)
		{
			throw new Exception('Unable to open: ' . $this->config->file);
		}
	}

	/**
	 * Closes a file.
	 */

	protected function close()
	{
		if ($this->zip->close() === false)
		{
			throw new Exception('Unable to close: ' . $this->config->file);
		}
	}
}