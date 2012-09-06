<?php

class rah_backup_zip {

	/**
	 * @var int The file descriptor limit and a reset point
	 */

	public $descriptor_limit = 200;
	
	/**
	 * Extract
	 */
	
	public function extract($filename, $destination) {
		$zip = new ZipArchive;
		$zip->open($filename);
		$zip->extractTo($destination);
		$zip->close();
	}

	/**
	 * Compresses a directory or a file
	 */

	public function create($sources, $destination) {
		
		if(!class_exists('ZipArchive') || !extension_loaded('zip')) {
			return false;
		}

		$zip = new ZipArchive();

		if(!$zip->open($destination, ZIPARCHIVE::OVERWRITE)) {
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
			
			$source = rtrim(str_replace('\\', '/', $source), '/') . '/';
			$sourceLenght = strlen($source);
			
			foreach($files as $file) {
					
				if(($count++) === $this->descriptor_limit) {
					$zip->close();
					$zip = new ZipArchive();
					$zip->open($destination);
					$count = 0;
				}
				
				if(is_link($file)) { // || in_array(substr($file, strrpos($file, '/')+1), array('.', '..'))) {
					continue;
				}
				
				$localname = $file;
				
				if(strpos(str_replace('\\', '/', $file), $source) === 0) {
					$localname = $sourceDirname.substr($file, $sourceLenght);
				}
				
				if(is_dir($file)) {
					$zip->addEmptyDir($localname);
				}
				
				else if(is_file($file)) {
					$zip->addFile($file, $localname);
				}
			}
		}

		return $zip->close();
	}
}

?>