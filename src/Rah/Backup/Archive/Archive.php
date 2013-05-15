<?php

/**
 * Configure an archive instance.
 */

class Rah_Backup_Archive_Archive extends Rah_Backup_Archive_Config
{
	/**
	 * Sets configuration options.
	 *
	 * @return Rah_Backup_Archive_Archive
	 */

	public function __call($name, $args)
	{
		if (property_exists($this, $name) === false)
		{
			throw new Exception('Unknown config option given: '.$name);
		}

		$this->$name = $args[0];
		return $this;
	}
}