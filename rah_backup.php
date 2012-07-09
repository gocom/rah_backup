<?php

/**
 * Rah_backup plugin for Textpattern CMS.
 *
 * @author Jukka Svahn
 * @date 2012-
 * @license GNU GPLv2
 * @link https://github.com/gocom/rah_backup
 *
 * Requires Textpattern v4.4.1 or newer and PHP 5.2.0 or newer.
 *
 * Copyright (C) 2012 Jukka Svahn <http://rahforum.biz>
 * Licensed under GNU Genral Public License version 2
 * http://www.gnu.org/licenses/gpl-2.0.html
 */

	if(@txpinterface == 'admin') {
		rah_backup::install();
		add_privs('rah_backup', '1,2');
		add_privs('rah_backup_create', '1,2');
		add_privs('rah_backup_restore', '1');
		add_privs('rah_backup_download', '1,2');
		add_privs('rah_backup_multi_edit', '1,2');
		add_privs('rah_backup_delete', '1');
		add_privs('rah_backup_preferences', '1');
		add_privs('plugin_prefs.rah_backup', '1,2');
		register_tab('extensions', 'rah_backup', gTxt('rah_backup'));
		register_callback(array('rah_backup', 'pane'), 'rah_backup');
		register_callback(array('rah_backup', 'head'), 'admin_side','head_end');
		register_callback(array('rah_backup', 'prefs'), 'plugin_prefs.rah_backup');
		register_callback(array('rah_backup', 'install'), 'plugin_lifecycle.rah_backup');
	}
	elseif(@txpinterface == 'public') {
		register_callback(array('rah_backup', 'call_backup'), 'textpattern');
	}

class rah_backup {
	
	static public $version = '0.1';
	
	/**
	 * @const int Filesystem backup type
	 */

	const BACKUP_FILESYSTEM = 1;
	
	/**
	 * @const int Filesystem backup type
	 */
	
	const BACKUP_DATABASE = 2;
	
	/**
	 * @var obj Stores instances
	 */
	
	static public $instance = NULL;
	
	/**
	 * @var string Path to directory storing backups
	 */
	
	private $backup_dir;
	
	/**
	 * @var string MySQL command
	 */
	
	private $mysql;
	
	/**
	 * @var string MySQLdump command
	 */
	
	private $mysqldump;
	
	/**
	 * @var string TAR command
	 */
	
	private $tar;
	
	/**
	 * @var string Gzip command
	 */
	
	private $gzip;
	
	/** 
	 * @var array List of backed up files
	 */
	
	private $copy_paths = array();
	
	/**
	 * @var array List of ignored tables
	 */
	
	private $ignore_tables = array();
	
	/**
	 * @var string Timestamp append to backup archives
	 */
	
	private $filestamp = '';
	
	/**
	 * @var string Path to created backup file
	 * @todo requires remodelling
	 */
	
	public $created = '';

	/**
	 * @var array List of invoked messages
	 */
	
	public $message = array();
	
	/**
	 * @var array List of invoked errors/notices
	 */
	
	public $warning = array();

	/**
	 * Constructor
	 */

	public function __construct() {

		global $prefs, $txpcfg;
		
		if(!is_callable('exec')) {
			$this->warning[] = gTxt('rah_backup_exec_disabled');
		}
		
		if(strpos($txpcfg['db'], '\\') !== false) {
			$this->warning[] = gTxt('rah_backup_safe_mode_no_exec_access');
		}
		
		if(!$prefs['rah_backup_path'] || !$prefs['rah_backup_mysql'] || !$prefs['rah_backup_mysqldump'] || ($prefs['rah_backup_copy_paths'] && !$prefs['rah_backup_tar']) || ($prefs['rah_backup_compress'] && !$prefs['rah_backup_gzip'])) {
			$this->message[] = gTxt('rah_backup_define_preferences', array(
				'{start_by}' => 
					'<a href="?event=prefs&amp;'.
						'step=advanced_prefs#prefs-rah_backup_path">'.
						gTxt('rah_backup_start_by').
					'</a>'
			),
			false);
		}
		
		else {
		
			$dir = $this->path($prefs['rah_backup_path']);
				
			if(!file_exists($dir) || !is_dir($dir)) {
				$this->warning[] = gTxt('rah_backup_dir_not_found', array('{path}' => $dir));
			}
			
			elseif(!is_readable($dir)) {
				$this->warning[] = gTxt('rah_backup_dir_not_readable', array('{path}' => $dir));
			}
			
			elseif(!is_writable($dir)) {
				$this->warning[] = gTxt('rah_backup_dir_not_writable', array('{path}' => $dir));
			}
			
			else {
				$this->backup_dir = $dir;
			}
		}
		
		@$tables = (array) getThings('SHOW TABLES');
		
		foreach(do_list($prefs['rah_backup_ignore_tables']) as $table) {
			
			if(!$table) {
				continue;
			}
			
			if(in_array(PFX.$table, $tables)) {
				$tbl = $txpcfg['db'].'.'.safe_pfx($table);
				$this->ignore_tables[$tbl] = '--ignore-table='.$this->arg($tbl);
			}
			
			else {
				$this->warning[] = gTxt('rah_backup_invalid_ignored_table', array('{name}' => $table));
			}
		}
		
		foreach(do_list($prefs['rah_backup_copy_paths']) as $f) {
			if($f && ($f = $this->path($f)) && file_exists($f) && is_dir($f) && is_readable($f)) {
				$this->copy_paths[$f] = $this->arg($f);
			}
		}
		
		foreach(array('mysql', 'mysqldump', 'tar', 'gzip') as $n) {
			
			$value = $prefs['rah_backup_'.$n];
			
			if(@ini_get('safe_mode') && (strpos($value, '..') !== false || strpos($value, './') !== false)) {
				$this->warning[] = gTxt('rah_backup_safe_mode_no_exec_access');
			}
			
			$this->$n = $value;
			
			if(DS != '/' && @ini_get('safe_mode')) {
				$this->$n =  str_replace('\\', '/', $this->$n);
			}
			
			$this->$n = rtrim(trim($this->$n),'/\\');
		}
		
		if(!$prefs['rah_backup_overwrite']) {
			$this->filestamp = '_'.safe_strtotime('now');
		}
	}

	/**
	 * Installer
	 * @param string $event Admin-side event.
	 * @param string $step Admin-side, plugin-lifecycle step.
	 */

	static public function install($event='', $step='') {
		
		global $prefs;
		
		if($step == 'deleted') {
			
			safe_delete(
				'txp_prefs',
				"name like 'rah\_backup\_%'"
			);
			
			return;
		}
		
		$current = isset($prefs['rah_backup_version']) ?
			(string) $prefs['rah_backup_version'] : 'base';
		
		if($current === self::$version)
			return;
		
		$position = 250;
		
		foreach(
			array(
				'path' => array('text_input', ''),
				'copy_paths' => array('text_input', './../'),
				'mysql' => array('text_input', 'mysql'),
				'mysqldump' => array('text_input', 'mysqldump'),
				'tar' => array('text_input', 'tar'),
				'gzip' => array('text_input', 'gzip'),
				'ignore_tables' => array('text_input', ''),
				'compress' => array('yesnoradio', 0),
				'overwrite' => array('yesnoradio', 0),
				'callback' => array('yesnoradio', 0),
				'key' => array('text_input', md5(uniqid(mt_rand(), TRUE))),
			) as $name => $val
		) {
			$n = 'rah_backup_'.$name;
			
			if(!isset($prefs[$n])) {
				safe_insert(
					'txp_prefs',
					"prefs_id=1,
					name='$n',
					val='".doSlash($val[1])."',
					type=1,
					event='rah_backup',
					html='".doSlash($val[0])."',
					position=".$position
				);
				
				$prefs[$n] = $val[1];
			}
			
			$position++;
		}
		
		set_pref('rah_backup_version', self::$version, 'rah_backup', 2, '', 0);
		$prefs['rah_backup_version'] = self::$version;
	}

	/**
	 * Gets an instance of the class
	 * @return obj
	 */

	static public function get() {
		
		if(self::$instance === NULL) {
			self::$instance = new rah_backup();
		}
		
		return self::$instance;
	}

	/**
	 * Delivers panels
	 */

	static public function pane() {
		require_privs('rah_backup');
		global $step;
		
		$steps = 
			array(
				'browser' => false,
				'create' => true,
				'restore' => true,
				'download' => true,
				'multi_edit' => true,
			);
		
		if(rah_backup::get()->message || rah_backup::get()->warning || !$step || !bouncer($step, $steps) || !has_privs('rah_backup_' . $step)) {
			$step = 'browser';
		}

		rah_backup::get()->$step();
	}

	/**
	 * Adds the panel's CSS to the head segment.
	 */

	static public function head() {
		global $event, $theme;
		
		if($event != 'rah_backup')
			return;
		
		gTxtScript(array(
			'rah_backup_confirm_restore',
			'rah_backup_confirm_backup',
		));
		
		$msg = array(
			'backup' => escape_js($theme->announce_async(gTxt('rah_backup_taking'))),
			'restore' => escape_js($theme->announce_async(gTxt('rah_backup_restoring'))),
		);
		
		echo <<<EOF
			<script type="text/javascript">
				<!--
				$(document).ready(function(){
					$('.rah_backup_restore, .rah_backup_take').live('click', function(e) {
						e.preventDefault();
						var obj = $(this);
						
						if(obj.hasClass('rah_backup_take')) {
							var message = textpattern.gTxt('rah_backup_confirm_backup');
						}
						else {
							var message = textpattern.gTxt('rah_backup_confirm_restore');
						}
								
						if(obj.hasClass('rah_backup_active') || !verify(message)) {
							return false;
						}
						
						if(obj.hasClass('rah_backup_take')) {
							$.globalEval('{$msg['backup']}');
						}
						else {
							$.globalEval('{$msg['restore']}');
						}
						
						var href = $(this).attr('href');
						obj.addClass('rah_backup_active').attr('href', '#');
						
						$.ajax({
							type: 'POST',
							url: 'index.php',
							data: href.substr(1) + '&app_mode=async',
							dataType: 'script'
						}).complete(function() {
							obj.removeClass('rah_backup_active').attr('href', href);
						});
					});
				});
				//-->
			</script>
EOF;
	}

	/**
	 * The main listing
	 * @param string $message Activity message.
	 */

	private function browser($message='') {
	
		global $event, $prefs, $app_mode, $theme;
		
		extract(gpsa(array(
			'sort',
			'dir',
		)));
		
		$methods = array();
		
		if(has_privs('rah_backup_delete')) {
			$methods['delete'] = gTxt('rah_backup_delete');
		}
		
		$columns = array('name', 'date', 'type', 'size');
		
		if($dir !== 'desc' && $dir !== 'asc') {
			$dir = get_pref($event.'_sort_dir', 'asc');
		}
		
		if(!in_array((string) $sort, $columns)) {
			$sort = get_pref($event.'_sort_column', 'name');
		}
		
		if($methods) {
			$column[] = hCell(fInput('checkbox', 'select_all', 0, '', '', '', '', '', 'select_all'), '', ' title="'.gTxt('toggle_all_selected').'" class="multi-edit"');
		}
		
		foreach($columns as $name) {
			$column[] = column_head($event.'_'.$name, $name, $event, true, $name === $sort && $dir === 'asc' ? 'desc' : 'asc', '', '', $name === $sort ? $dir : '', 'browse');
		}
		
		if(has_privs('rah_backup_restore')) {
			$column[] = hCell(gTxt('rah_backup_restore'));
		}
		
		set_pref($event.'_sort_column', $sort, $event, 2, '', 0, PREF_PRIVATE);
		set_pref($event.'_sort_dir', $dir, $event, 2, '', 0, PREF_PRIVATE);

		if(!$this->message) {
			$backups = $this->get_backups($sort, $dir);
			
			foreach($backups as $name => $backup) {
				
				$td = array();
				$name = htmlspecialchars($name);
				
				if($methods) {
					$td[] = td(fInput('checkbox', 'selected[]', $name), '', 'multi-edit');
				}
				
				if(has_privs('rah_backup_download')) {
					$td[] = td('<a title="'.gTxt('rah_backup_download').'" href="?event='.$event.'&amp;step=download&amp;file='.urlencode($name).'&amp;_txp_token='.form_token().'">'.$name.'</a>');
				}
				
				else {
					$td[] = td($name);
				}
				
				$td[] = td(safe_strftime(gTxt('rah_backup_dateformat'), $backup['date']));
				$td[] = td(gTxt('rah_backup_type_'.$backup['type']));
				$td[] = td($this->format_size($backup['size']));
				
				if(has_privs('rah_backup_restore')) {
					
					if($backup['type'] === self::BACKUP_DATABASE && !$this->warning) {
						$td[] = td('<a class="rah_backup_restore" title="'.$name.'" href="?event='.$event.'&amp;step=restore&amp;file='.urlencode($name).'&amp;_txp_token='.form_token().'">'.gTxt('rah_backup_restore').'</a>');
					}
					
					else {
						$td[] = td('');
					}
				}
				
				$out[] = tr(implode(n, $td));
			}
			
			if(!$backups) {
				$this->message[] = gTxt('rah_backup_no_backups');
			}
		}
		
		if($this->message) {
			$out[] = 
				'			<tr>'.n.
				'				<td colspan="'.count($column).'">'.$this->message[0].'</td>'.n.
				'			</tr>'.n;
		}
		
		$out = implode(n, $out);
		
		if($app_mode == 'async') {
			send_script_response($theme->announce_async($message).n.'$("#rah_backup_list").html("'.escape_js($out).'");');
			return;
		}
		
		$out = 
			($this->warning ? '<p id="warning">'.$this->warning[0].'</p>' : '').
		
			'<div class="txp-listtables">'.n.
			'<table class="txp-list">'.n.
			'	<thead>'.
			tr(implode(n, $column)).
			'	</thead>'.n.
			'	<tbody id="rah_backup_list">'.n.
				$out.n.
			'	</tbody>'.n.
			'</table>'.n.
			'</div>'.n;
		
		if($methods) {
			$out .= multi_edit($methods, $event, 'multi_edit');
		}
		
		$this->build_pane($out, $message);
	}
	
	/**
	 * Backup callback
	 */
	
	static public function call_backup() {
		
		global $prefs;
		
		if(
			!gps('rah_backup') || 
			empty($prefs['rah_backup_callback']) || 
			empty($prefs['rah_backup_key']) || 
			$prefs['rah_backup_key'] !== gps('rah_backup')
		)
			return;
		
		if(!rah_backup::get()->message) {
			rah_backup::get()->create();
		}
	}
	
	/**
	 * Sanitize filename
	 * @param string $filename
	 * @return string
	 */
	
	public function sanitize($filename) {
		$filename = preg_replace('/[^A-Za-z0-9-._]/', '.', (string) $filename);
		return trim(preg_replace('/[_.-]{2,}/', '.', $filename), '. ');
	}
	
	/**
	 * Creates a new backup
	 */

	private function create() {
		global $txpcfg, $prefs;

		@set_time_limit(0);
		@ignore_user_abort(true);
		
		callback_event('rah_backup.create');
		
		$path = $this->backup_dir . '/' . $this->sanitize($txpcfg['db']) . $this->filestamp . '.sql';
		
		$this->exec_command(
			$this->mysqldump,
			' --opt --skip-comments'.
			' --host='.$this->arg($txpcfg['host']).
			' --user='.$this->arg($txpcfg['user']).
			' --password='.$this->arg($txpcfg['pass']).
			' --result-file='.$this->arg($path).
			' '.implode(' ', $this->ignore_tables).
			' '.$this->arg($txpcfg['db'])
		);
		
		if($prefs['rah_backup_compress'] && file_exists($path)) {
			$this->exec_command($this->gzip, '-c6 '.$this->arg($path).' > '.$this->arg($path.'.gz'));
			unlink($path);
			$path .= '.gz';
		}
		
		if(file_exists($path)) {
			$this->created = $path;
			callback_event('rah_backup.created');
		}
		
		if($this->copy_paths) {
			
			$path = $this->sanitize($prefs['siteurl']);
			
			if(!$path) {
				$path = 'filesystem';
			}
			
			$path = $this->backup_dir . '/' . $path . $this->filestamp . '.tar';
			$this->exec_command($prefs['rah_backup_tar'], '-cvpzf '.$this->arg($path).' '.implode(' ', $this->copy_paths));
			
			if($prefs['rah_backup_compress']) {
				$this->exec_command($this->gzip, '-c6 '.$this->arg($path).' > '.$this->arg($path.'.gz'));
				unlink($path);
				$path .= '.gz';
			}
			
			if(file_exists($path)) {
				$this->created = $path;
				callback_event('rah_backup.created');
			}
		}

		callback_event('rah_backup.done');

		if(txpinterface == 'public') {
			exit;
		}

		$this->browser(gTxt('rah_backup_done'));
	}

	/**
	 * Restores backup
	 */

	private function restore() {
		global $txpcfg, $prefs;
		
		@set_time_limit(0);
		@ignore_user_abort(true);
		
		$file = (string) gps('file');
		$backups = $this->get_backups();
		
		if(!isset($backups[$file]) || $backups[$file]['type'] != self::BACKUP_DATABASE) {
			$this->browser(array(gTxt('rah_backup_can_not_restore'), E_ERROR));
			return;
		}
		
		extract($backups[$file]);
		
		if($ext == 'gz') {
			$this->exec_command($this->gzip, '-cd '.$this->arg($path).' > '.$this->arg($path.'.tmp'));
			$path .= '.tmp';
		}
		
		if(
			$this->exec_command(
				$prefs['rah_backup_mysql'], 
				' --host='.$this->arg($txpcfg['host']).
				' --user='.$this->arg($txpcfg['user']).
				' --password='.$this->arg($txpcfg['pass']).
				' '.$this->arg($txpcfg['db']).
				' < '.$this->arg($path)
			) === false
		) {
			$this->browser(array(gTxt('rah_backup_can_not_restore'), E_ERROR));
			return;
		}
		
		if($ext === 'gz') {
			@unlink($path);
		}
		
		$this->browser(gTxt('rah_backup_restored'));
	}

	/**
	 * Downloads a backup file
	 */

	private function download() {

		$file = (string) gps('file');
		
		if(!($backups = $this->get_backups()) || !isset($backups[$file])) {
			$this->browser(array(gTxt('rah_backup_can_not_download'), E_ERROR));
			return;
		}

		extract($backups[$file]);

		@ini_set('zlib.output_compression', 'Off');
		@set_time_limit(0);
		@ignore_user_abort(true);

		ob_clean();
		header('Content-Description: File Download');
		header('Content-Type: application/octet-stream');
		header('Content-Disposition: attachment; filename="'.$name.'"; size="'.$size.'"');
		header('Content-Transfer-Encoding: binary');
		header('Expires: 0');
		header('Cache-Control: private');
		header('Content-Length: '.$size);
		ob_flush();
		flush();

		if($f = fopen($path, 'rb')) {
			while(!feof($f) && connection_status() == 0) {
				echo fread($f, 1024*64);
				ob_flush();
				flush();
			}
			fclose($f);
		}

		exit;
	}
	
	/**
	 * Multi-edit handler
	 */
	
	private function multi_edit() {
		
		extract(psa(array(
			'selected',
			'edit_method',
		)));
		
		require_privs('rah_backup_'.((string) $edit_method));
		
		if(!is_string($edit_method) || empty($selected) || !is_array($selected)) {
			$this->browser(array(gTxt('rah_backup_select_something'), E_WARNING));
			return;
		}
		
		$method = 'multi_option_' . $edit_method;
		
		if(!method_exists($this, $method)) {
			$method = 'browse';
		}
		
		$this->$method();
	}

	/**
	 * Deletes selected backups
	 */

	private function multi_option_delete() {
		
		$selected = ps('selected');
		
		foreach($this->get_backups() as $name => $file) {
			if(in_array($name, $selected)) {
				@unlink($file['path']);
			}
		}
		
		$this->browser(gTxt('rah_backup_removed'));
	}
	
	/**
	 * Escape shell argument
	 */
	
	public function arg($arg) {
		return "'".str_replace("'", "'\\''", $arg)."'";
	}

	/**
	 * Execute shell command
	 * @param string $command The program to run.
	 * @param string $args The arguments passed to the application.
	 * @return bool
	 */

	public function exec_command($command, $args) {
	
		static $disabled = NULL;
		
		if($disabled === NULL) {
			$disabled = @ini_get('safe_mode') || !is_callable('escapeshellcmd');
		}
		
		if(!$disabled) {
			$command = escapeshellcmd($command);
		}
		
		return exec($command.' '.$args);
	}

	/**
	 * Format filesize
	 * @param int $bytes Size in bytes.
	 * @return string Formatted size.
	 * @todo Should actually divide by 1000, or use different prefix
	 */

	public function format_size($bytes) {
		$units = array('B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');
		$pow = floor(($bytes ? log($bytes) : 0) / log(1024));
		$pow = min($pow, count($units) - 1);
		$bytes /= pow(1024, $pow);
		$separators = localeconv();
		$sep_dec = isset($separators['decimal_point']) ? $separators['decimal_point'] : '.';
		$sep_thous = isset($separators['thousands_sep']) ? $separators['thousands_sep'] : ',';
		return number_format($bytes, 2, $sep_dec, $sep_thous) . ' ' . $units[$pow];
	}
	
	/**
	 * Gets a list of backups
	 * @param string $sort
	 * @param string $direction
	 * @param int $offset
	 * @param int $limit
	 * @return array
	 */
	
	public function get_backups($sort='name', $direction='asc', $offset=0, $limit=NULL) {
		
		global $prefs;
		
		$order = $files = array();
		
		$sort_crit = array(
			'name' => SORT_REGULAR,
			'ext' => SORT_REGULAR,
			'date' => SORT_NUMERIC,
			'size' => SORT_NUMERIC,
			'type' => SORT_NUMERIC,
		);
		
		if(!is_string($sort) || !isset($sort_crit[$sort])) {
			$sort = 'name';
		}
		
		foreach(
			(array) glob(
				preg_replace('/(\*|\?|\[)/', '[$1]', $prefs['rah_backup_path']) . '/'.'*[.sql|.tar|.gz]',
				GLOB_NOSORT
			) as $file
		) {
			
			if(!$file || !is_readable($file) || !is_file($file)) {
				continue;
			}
			
			$backup = array(
				'path' => $file,
				'name' => basename($file),
				'ext' => pathinfo($file, PATHINFO_EXTENSION),
				'date' => (int) filemtime($file),
				'size' => (int) filesize($file),
				'type' => self::BACKUP_FILESYSTEM,
			);
			
			if($backup['ext'] === 'sql' || ($backup['ext'] === 'gz' && substr($backup['name'], -7, 4) === '.sql')) {
				$backup['type'] = self::BACKUP_DATABASE;
			}
			
			$files[$backup['name']] = $backup;
			$order[$backup['name']] = $backup[$sort];
		}
		
		if(!$files) {
			return array();
		}
		
		array_multisort($order, $sort_crit[$sort], $files);
		
		if($direction === 'desc') {
			$files = array_reverse($files);
		}
		
		return array_slice($files, $offset, $limit);
	}

	/**
	 * Echoes the panels and header
	 * @param string|array $content Pane's HTML markup.
	 * @param string|array $message The activity message.
	 */

	private function build_pane($content, $message='') {
		
		global $event;
		
		pagetop(gTxt('rah_backup'), $message);
		
		if(is_array($content)) {
			$content = implode('', $content);
		}
		
		echo 
			n.
			
			'<form action="index.php" method="post" id="rah_backup_container" class="txp-container multi_edit_form">'.n.
			'	'.tInput().n.
			
			'	<p class="txp-buttons">'.
			
			(has_privs('rah_backup_create') && !$this->warning ? 
				'<a class="rah_backup_take" href="?event='.$event.'&amp;step=create&amp;_txp_token='.form_token().'">'.
					gTxt('rah_backup_create').
				'</a> ' : ''
			).
			
			(has_privs('prefs') && has_privs('rah_backup_preferences') ? 
				'<a href="?event=prefs&amp;step=advanced_prefs#prefs-rah_backup_path">'.
					gTxt('rah_backup_preferences').
				'</a>' : ''
			).
					
			'</p>'.n.
			$content.n.
			'</form>'.n;
	}
	
	/**
	 * Format a path
	 * @param string $path
	 * @return string|bool
	 */
	
	public function path($path) {
		
		if(strpos($path, './') === 0) {
			$path = txpath.'/'.substr($path, 2);
		}
		
		return rtrim($path, "/\\");
	}

	/**
	 * Redirect to the admin-side interface
	 */

	static public function prefs() {
		header('Location: ?event=rah_backup');
		echo 
			'<p>'.n.
			'	<a href="?event=rah_backup">'.gTxt('continue').'</a>'.n.
			'</p>';
	}
}
?>