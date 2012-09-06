<?php

/**
 * Wrapper for ZipArchive extension.
 * 
 * @package rah_backup
 * @author Jukka Svahn <http://rahforum.biz/>
 * @copyright (c) 2012 Jukka Svahn
 * @license GLPv2
 */

class rah_backup_zip {

	/**
	 * @var int The file descriptor limit and a reset point
	 */

	public $descriptor_limit = 200;

	/**
	 * Extract
	 * @param string $filename
	 * @param string $destination
	 * @return bool
	 */

	public function extract($filename, $target) {
		$zip = new ZipArchive;
		
		if(!$zip || !$zip->open($filename)) {
			return false;
		}
		
		if(!$zip->extractTo($target)) {
			return false;
		}
		
		return $zip->close();
	}

	/**
	 * Compresses a directory or a file
	 * @param string|array $sources
	 * @param string $destination
	 * @return bool
	 */

	public function create($sources, $destination) {
		
		if(!class_exists('ZipArchive') || !extension_loaded('zip')) {
			return false;
		}

		$zip = new ZipArchive();

		if(!$zip || !$zip->open($destination, ZIPARCHIVE::OVERWRITE)) {
			return false;
		}

		$count = 0;
		
		foreach((array) $sources as $source) {
		
			$sourceDirname = '';
		
			if(is_dir($source)) {
				$files = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator($source, FilesystemIterator::UNIX_PATHS | FilesystemIterator::SKIP_DOTS),
					RecursiveIteratorIterator::SELF_FIRST
				);
				
				if(count($sources) > 1) {
					$sourceDirname = md5($source).'/';
				}
			}
			
			else {
				$files = (array) $source;
			}
			
			if(!$files) {
				return false;
			}
			
			$source = rtrim(str_replace('\\', '/', dirname($source)), '/') . '/';
			$sourceLenght = strlen($source);
			
			foreach($files as $file) {
				
				if(($count++) === $this->descriptor_limit) {
					
					if(!$zip->close()) {
						return false;
					}
					
					$zip = new ZipArchive();
					
					if(!$zip || !$zip->open($destination)) {
						return false;
					}
					
					$count = 0;
				}
				
				if(is_link($file)) {
					continue;
				}
				
				$localname = $file;
				
				if(strpos(str_replace('\\', '/', $file), $source) === 0) {
					$localname = $sourceDirname.substr($file, $sourceLenght);
				}
				
				if(is_dir($file)) {
					if(!$zip->addEmptyDir($localname)) {
						return false;
					}
				}
				
				else if(is_file($file)) {
					if(!$zip->addFile($file, $localname)) {
						return false;
					}
				}
			}
		}

		return $zip->close();
	}
}

?>