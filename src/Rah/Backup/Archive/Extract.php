<?php

/**
 * Extracts an archive.
 */

class Rah_Backup_Archive_Extract extends Rah_Backup_Archive_Base
{
	/**
	 * Initializes.
	 */

	protected function init()
	{
		$this->open();

		if ($zip->extractTo($this->config->file) === false || $this->close())
		{
			throw new Exception('Unable to extract: ' . $this->config->file);
		}
	}
}