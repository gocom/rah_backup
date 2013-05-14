<?php

/**
 * Wrapper for ZipArchive extension.
 * 
 * @package rah_backup
 * @author Jukka Svahn <http://rahforum.biz/>
 * @copyright (c) 2012 Jukka Svahn
 * @license GLPv2
 */

class Rah_Backup_Compress
{
	/**
	 * Compresses a directory or a file.
	 */

	protected function init()
	{
		$this->zip->open(ZIPARCHIVE::OVERWRITE);
		$count = 0;

		foreach((array) $this->config->source as $source)
		{
			$files = $this->fileList($source);
			$sourceDirname = '';

			if (is_array($this->config->source) && count($this->config->source) > 1)
			{
				$sourceDirname = md5($source).'/';
			}

			$source = $this->normalizePath(dirname($source));
			$sourceLenght = strlen($source) + 1;

			foreach ($files as $file)
			{
				if(($count++) === $this->config->descriptor_limit)
				{
					$this->close();
					$this->open();
					$count = 0;
				}

				if (is_link($file))
				{
					continue;
				}

				$ignore = false;

				foreach ((array) $this->config->ignored as $f)
				{
					if (strpos($file, $f) !== false)
					{
						$ignore = true;
						break;
					}
				}

				if ($ignore)
				{
					continue;
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
	 * Collects a file list.
	 *
	 * @return array|RecursiveIteratorIterator
	 */

	protected function fileList($filename)
	{
		if (!is_dir($filename))
		{
			return (array) $filename;
		}

		$directory = new RecursiveDirectoryIterator($filename, FilesystemIterator::UNIX_PATHS | FilesystemIterator::SKIP_DOTS);
		return new RecursiveIteratorIterator($directory, RecursiveIteratorIterator::SELF_FIRST);
	}
}