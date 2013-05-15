<?php

/**
 * Creates an archive.
 */

class Rah_Backup_Archive_Create extends Rah_Backup_Archive_Base
{
	/**
	 * Compresses a directory or a file.
	 */

	protected function init()
	{
		$this->zip->open(ZIPARCHIVE::OVERWRITE);
		$count = 0;

		foreach ((array) $this->config->source as $source)
		{
			if (is_dir($source))
			{
				$files = new RecursiveDirectoryIterator($source);
				$file = new RecursiveIteratorIterator($files, RecursiveIteratorIterator::SELF_FIRST);
			}
			else
			{
				$this->addFile($source);
				continue;
			}

			$sourceDirname = '';

			if (is_array($this->config->source) && count($this->config->source) > 1)
			{
				$sourceDirname = md5($source).'/';
			}

			$source = $this->normalizePath(dirname($source));
			$sourceLenght = strlen($source) + 1;

			while ($file->valid())
			{
				if ($file->isDot() || $file->isLink() || $file->isReadable() === false || $this->isIgnored($file->getFileName()))
				{
					$this->next();
					continue;
				}

				if (($count++) === $this->config->descriptor_limit)
				{
					$this->close();
					$this->open();
					$count = 0;
				}

				$localname = $file;

				if (strpos($this->normalizePath($file).'/', $source.'/') === 0)
				{
					$localname = $sourceDirname.substr($file, $sourceLenght);
				}

				if (is_dir($file))
				{
					if (!$zip->addEmptyDir($localname))
					{
						return false;
					}
				}
				else if (is_file($file))
				{
					if (!$zip->addFile($file, $localname))
					{
						return false;
					}
				}
			}
		}

		return $zip->close();
	}

	/**
	 * Whether the file is ignored.
	 *
	 * @return string $file The filename
	 */

	protected function isIgnored($file)
	{
		foreach ((array) $this->config->ignored as $f)
		{
			if (strpos($file, $f) !== false)
			{
				return true;
			}
		}

		return false;
	}
}