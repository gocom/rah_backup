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

/**
 * Backup class
 */

class rah_backup {

	static public $version = '0.1';
	
	private $backup_dir;
	private $mysql;
	private $mysqldump;
	private $tar;
	private $gzip;
	private $copy_paths = array();
	private $ignore_tables = array();
	private $filestamp = '';
	public $message;

	/**
	 * Constructor
	 */

	public function __construct() {

		global $prefs, $txpcfg;
		
		if(!$prefs['rah_backup_path']) {
			$this->message = gTxt('rah_backup_define_preferences', array(
				'{start_by}' => 
					'<a href="?event=prefs&amp;'.
						'step=advanced_prefs#prefs-rah_backup_path">'.
						gTxt('rah_backup_start_by').
					'</a>'
			),
			false);
		}
		
		$dir = $this->path($prefs['rah_backup_path']);
			
		if(!file_exists($dir) || !is_dir($dir)) {
			$this->message = gTxt('rah_backup_dir_not_found');
		}
		
		elseif(!is_readable($dir)) {
			$this->message = gTxt('rah_backup_dir_not_readable');
		}
		
		elseif(!is_writable($dir)) {
			$this->message = gTxt('rah_backup_dir_not_writable');
		}
		
		else {
			$this->backup_dir = $dir;
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
				$this->message = gTxt('rah_backup_invalid_ignored_table', array('{name}' => $table));
			}
		}
		
		foreach(do_list($prefs['rah_backup_copy_paths']) as $f) {
			if($f && ($f = $this->path($f)) && file_exists($f) && is_dir($f) && is_readable($f)) {
				$this->copy_paths[$f] = $this->arg($f);
			}
		}
		
		foreach(array('mysql', 'mysqldump', 'tar', 'gzip') as $n) {
		
			if(@ini_get('safe_mode') && (strpos($n, '..') !== false || strpos($n, './') !== false)) {
				$this->message = gTxt('rah_backup_safe_mode_no_exec_access');
				continue;
			}
			
			$this->$n = $prefs['rah_backup_' . $n ];
			
			if(DS != '/' && @ini_get('safe_mode')) {
				$this->$n =  str_replace('\\', '/', $this->$n);
			}
			
			$this->$n = rtrim(trim($this->$n),'/\\');
		}
		
		if(strpos($txpcfg['db'], '\\') !== false) {
			$this->message = gTxt('rah_backup_safe_mode_no_exec_access');
		}
		
		if(!function_exists('exec') || is_disabled('exec')) {
			$this->message = gTxt('rah_backup_exec_func_unavailable');
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
				'path' => '',
				'copy_paths' => './../',
				'mysql' => 'mysql',
				'mysqldump' => 'mysqldump',
				'ignore_tables' => '',
				'tar' => 'tar',
				'compress' => 0,
				'gzip' => 'gzip',
				'overwrite' => 1,
				'allow_restore' => 1,
				'callback' => 0,
				'key' => md5(uniqid(mt_rand(), TRUE)),
			) as $name => $val
		) {

			$n = 'rah_backup_' . $name;
			
			if(!isset($prefs[$n])) {

				switch($name) {
					case 'callback':
					case 'compress':
					case 'overwrite':
					case 'allow_restore':
						$html = 'yesnoradio';
						break;
					default:
						$html = 'text_input';
				}

				safe_insert(
					'txp_prefs',
					"prefs_id=1,
					name='$n',
					val='$val',
					type=1,
					event='rah_backup',
					html='$html',
					position=".$position
				);
				
				$prefs[$n] = $val;
			}
			
			$position++;
		}
		
		set_pref('rah_backup_version', self::$version, 'rah_backup', 2, '', 0);
		$prefs['rah_backup_version'] = self::$version;
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
				'delete' => true,
				'download' => true
			);
		
		$uix = new rah_backup();
		
		if($uix->message || !$step || !bouncer($step, $steps) || !has_privs('rah_backup_' . $step))
			$step = 'browser';

		$uix->$step();
	}

	/**
	 * Adds the panel's CSS to the head segment.
	 */

	static public function head() {
		global $event;
		
		if($event != 'rah_backup')
			return;
		
		gTxtScript(array(
			'rah_backup_database_will_be_overwriten',
			'rah_backup_inprogress',
			'rah_backup_confirm_backup',
			'rah_backup_restoring',
			'rah_backup_restored',
			'are_you_sure',
		));
		
		echo <<<EOF
			<script type="text/javascript">
				<!--
				$(document).ready(function(){
					var pane = $('#rah_backup_container');

					/*
						Multi-edit function, auto-hiden dropdown
					*/
				
					(function() {
						
						var steps = $('select[name=step]').parent();
						var form = steps.parents('form');
					
						if(steps.length < 1)
							return;
						
						steps.find('input[type=submit]').hide();
						
						if(form.find('input[type=checkbox]:checked').val() == null) {
							steps.hide();
						}
	
						steps.find('select[name=step]').val('');
	
						form.find('input[name=selectall]').click(function() {
							
							var tr = form.find('tbody input[type=checkbox]');
								
							if($(this).is(':checked')) {
								tr.attr('checked', true);
							}
							else {
								tr.removeAttr('checked');
							}
						});
						
						form.find('input[type=checkbox], td, th').live('click', function(){
							steps.children('select[name="step"]').val('');
								
							if(pane.find('tbody input[type=checkbox]:checked').val() != null) {
								steps.slideDown();
							}
							else {
								steps.slideUp();
							}
						});
						
						form.find('tbody input[type=checkbox]').live('click', function() {
							form.find('input[name="selectall"]').removeAttr('checked');
						});
	
						steps.find('select[name=step]').change(function(){
							form.submit();
						});
	
						form.submit(function() {
							if(!verify(textpattern.gTxt('are_you_sure'))) {
								steps.find('select[name="step"]').val('');
								return false;
							}
						});
					})();
					
					/*
						Do a backup
					*/
					
					(function() {
						$('a#rah_backup_do').click(function(e) {
							e.preventDefault();
								
							if(!verify(textpattern.gTxt('rah_backup_confirm_backup')))
								return false;
									
							$(this).after('<span class="navlink-active" id="rah_backup_statusmsg">'+textpattern.gTxt('rah_backup_inprogress')+'</span>').hide();
									
							$.ajax({
								type : 'POST',
								url : 'index.php',
								data : {
									'event' : textpattern['event'],
									'step' : 'create',
									'_txp_token' : textpattern['_txp_token'],
								},
								success: function(data, status, xhr) {
									$('#rah_backup_container table.txp-list tbody').html($(data).find('#rah_backup_list').html());
								},
								error: function() {
								},
								complete: function() {
									$('#rah_backup_statusmsg').hide();
									$('#rah_backup_do').show();
								}
							});
						}).attr('href','#');
					})();
					
					/*
						Restore a backup
					*/
					
					(function() {
						$('.rah_backup_restore').live('click', function(e) {
							e.preventDefault();
								
							if(!verify(textpattern.gTxt('rah_backup_database_will_be_overwriten')))
								return false;
									
							$('.rah_backup_restore').hide();
									
							var link = $(this);
							var filename = $(this).attr('title');
								
							link.after('<span class="rah_backup_restoring">'+textpattern.gTxt('rah_backup_restoring')+'</span>');
								
							$.ajax({
								type : 'POST',
								url : 'index.php',
								data : {
									'event' : textpattern['event'],
									'step' : 'restore',
									'_txp_token' : textpattern['_txp_token'],
									'file' : filename
								},		
								success: function(data, status, xhr) {
									link.next('span.rah_backup_restoring').text(textpattern.gTxt('rah_backup_restored'));
								},		
								error: function() {	
								},		
								complete: function() {
									$('.rah_backup_restore').show();
									link.hide();
								}
							});	
						}).attr('href','#');
					})();
				});
				//-->
			</script>
			<style type="text/css">
				#rah_backup_container .rah_ui_step {
					text-align: right;
				}
				#rah_backup_container .rah_backup_restore {
					min-width: 100px;
				}
			</style>
EOF;
	}

	/**
	 * The main listing
	 * @param string $message Activity message.
	 */

	private function browser($message='') {
	
		global $event, $prefs;
		
		$column = array(
			gTxt('rah_backup_filename'),
			gTxt('rah_backup_date'),
			gTxt('rah_backup_size')
		);
		
		if(has_privs('rah_backup_restore') && $prefs['rah_backup_allow_restore']) {
			$column[] = gTxt('rah_backup_restore');
		}
		
		if(has_privs('rah_backup_delete')) {
			$column[] = '<input type="checkbox" name="selectall" value="1" />';
		}
		
		$out[] = 
			'	<table class="txp-list">'.n.
			'		<thead>'.
			tr(implode(n, doArray($column, 'hCell'))).
			'		</thead>'.n.
			'		<tbody id="rah_backup_list">'.n;
		
		$msg = $this->message;

		if(!$msg) {
			
			foreach($this->get_backups() as $name => $backup) {
				
				$column = array();
				$name = htmlspecialchars($name);
				
				if(has_privs('rah_backup_download')) {
					$column[] = '<a title="'.gTxt('rah_backup_download').'" href="?event='.$event.'&amp;step=download&amp;file='.urlencode($name).'&amp;_txp_token='.form_token().'">'.$name.'</a>';
				}
				
				else {
					$column[] = $name;
				}
				
				$column[] = safe_strftime(gTxt('rah_backup_dateformat'), $backup['modified']);
				$column[] = $this->format_size($backup['size']);
				
				if(has_privs('rah_backup_restore') && $prefs['rah_backup_allow_restore']) {
					
					if($backup['type'] === 'database') {
						$column[] = '<a class="rah_backup_restore" title="'.$name.'" href="?event='.$event.'&amp;step=restore&amp;file='.urlencode($name).'&amp;_txp_token='.form_token().'">'.gTxt('rah_backup_restore').'</a>';
					}
					
					else {
						$column[] = '';
					}
				}
				
				if(has_privs('rah_backup_delete')) {
					$column[] = '<input type="checkbox" name="selected[]" value="'.$name.'" />';
				}
				
				$out[] = tr(implode(n, doArray($column, 'td')));
			}
		}
		
		if($msg) {
			$out[] = 
				'			<tr>'.n.
				'				<td id="rah_backup_msgrow" colspan="'.count($column).'">'.$msg.'</td>'.n.
				'			</tr>'.n;
		}
		
		$out[] = 
			
			'		</tbody>'.n.
			'	</table>'.n.
			
			(has_privs('rah_backup_delete') ?
				'	<p class="rah_ui_step">'.n.
				'		<select name="step">'.n.
				'			<option value="">'.gTxt('rah_backup_with_selected').'</option>'.n.
				'			<option value="delete">'.gTxt('rah_backup_delete').'</option>'.n.
				'		</select>'.n.
				'		<input type="submit" value="'.gTxt('go').'" />'.n.
				'	</p>' : ''
			);
		
		$this->build_pane(
			$out,
			$message
		);
	}
	
	/**
	 * Backup callback
	 * @return nothing
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
			
		$backup = new rah_backup();
		
		if(!$backup->message)
			$backup->create(true);
	}
	
	/**
	 * Sanitize filename
	 * @param string $filename
	 */
	
	public function sanitize($filename) {
		$filename = preg_replace('/[^A-Za-z0-9-._]/', '.', (string) $filename);
		return trim(preg_replace('/[_.-]{2,}/', '.', $filename), '. ');
	}
	
	/**
	 * Creates a new backup
	 * @param bool $silent Return output or not.
	 * @todo Site URL might not be trusted.
	 */

	private function create($silent=false) {
		
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
		
		callback_event('rah_backup.created');
		
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
			
			callback_event('rah_backup.created');
		}

		callback_event('rah_backup.done');

		if($silent) {
			exit;
		}

		$this->browser(gTxt('rah_backup_done'));
	}

	/**
	 * Restores backup
	 */

	private function restore() {
		
		global $txpcfg, $prefs;
		
		if(!$prefs['rah_backup_allow_restore']) {
			$this->browser();
			return;
		}
		
		$file = preg_replace('/[^A-Za-z0-9-._]/', '', (string) gps('file'));
		$path = $this->backup_dir.'/'.$file;
		$ext = pathinfo($file, PATHINFO_EXTENSION);

		if(
			!$file || 
			($ext !== 'sql' && $ext !== 'gz') || 
			!file_exists($path) || 
			!is_readable($path) || 
			!is_file($path)
		) {
			$this->browser(array(gTxt('rah_backup_can_not_restore'), E_ERROR));
			return;
		}
		
		if($ext === 'gz') {
			$this->exec_command($this->gzip, '-cd '.$this->arg($path).' > '.$this->arg($path.'.tmp'));
			$path .= '.tmp';
		}
		
		$returned = 
			$this->exec_command(
				$prefs['rah_backup_mysql'], 
				' --host='.$this->arg($txpcfg['host']).
				' --user='.$this->arg($txpcfg['user']).
				' --password='.$this->arg($txpcfg['pass']).
				' '.$this->arg($txpcfg['db']).
				' < '.$this->arg($path)
			);
		
		if($returned === false) {
			$this->browser(array(gTxt('rah_backup_can_not_restore'), E_ERROR));
			return;	
		}
		
		if($ext === 'gz') {
			@unlink($path);
		}
		
		$this->browser(gTxt('rah_backup_restore_done'));
	}

	/**
	 * Downloads a backup file
	 */

	private function download() {

		$file = preg_replace('/[^A-Za-z0-9-._]/', '', gps('file'));
		$path = $this->backup_dir.'/'.$file;
		$ext = pathinfo($file, PATHINFO_EXTENSION);

		if(
			!$file || 
			!$ext || 
			($ext != 'sql' && $ext != 'gz' && $ext != 'tar') || 
			!file_exists($path) || 
			!is_writeable($path) || 
			!is_file($path)
		) {
			$this->browser(array(gTxt('rah_backup_can_not_download'), E_ERROR));
			return;	
		}

		$size = filesize($path);

		@ini_set('zlib.output_compression', 'Off');
		@set_time_limit(0);
		@ignore_user_abort(true);

		ob_clean();

		header('Content-Description: File Download');
		header('Content-Type: application/octet-stream');
		header('Content-Disposition: attachment; filename="'.$file.'"; size="'.$size.'"');
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
	 * Deletes a backup file
	 */

	private function delete() {
		
		$selected = ps('selected');
		
		if(empty($selected) || !is_array($selected)) {
			$this->browser(array(gTxt('rah_backup_select_something'), E_WARNING));
			return;
		}
		
		foreach($selected as $file) {
			
			$ext = pathinfo((string) $file, PATHINFO_EXTENSION);
			
			if(!$file || !$ext || ($ext !== 'sql' && $ext !== 'gz' && $ext !== 'tar')) {
				continue;
			}
			
			$file = $this->backup_dir.'/'.preg_replace('/[^A-Za-z0-9-._]/', '', (string) $file);
			
			if(!file_exists($file) || !is_writeable($file) || !is_file($file)) {
				continue;
			}
			
			unlink($file);
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
			'modified' => SORT_NUMERIC,
			'size' => SORT_NUMERIC,
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
				'ext' => pathinfo($file, PATHINFO_EXTENSION),
				'path' => $file,
				'name' => basename($file),
				'modified' => (int) filemtime($file),
				'size' => (int) filesize($file),
				'type' => 'filesystem',
			);
			
			if($backup['ext'] === 'sql' || ($backup['ext'] === 'gz' && substr($backup['name'], -7, 4) === '.sql')) {
				$backup['type'] = 'database';
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
	 * @param string $content Pane's HTML markup.
	 * @param string $message The activity message.
	 */

	private function build_pane($content, $message) {
		
		global $event;
		
		pagetop(gTxt('rah_backup'), $message ? $message : '');
		
		if(is_array($content)) {
			$content = implode('', $content);
		}
		
		echo 
			n.
			
			'<form action="index.php" method="post" id="rah_backup_container" class="txp-container">'.n.
			'	'.eInput($event).n.
			'	'.tInput().n.
			
			'	<p class="nav-tertiary">'.
			
			(has_privs('rah_backup_create') && !$this->message ? 
				'<a class="navlink" id="rah_backup_do" href="?event='.$event.'&amp;step=create&amp;_txp_token='.form_token().'">'.
					gTxt('rah_backup_create').
				'</a>' : ''
			).
			
			(has_privs('prefs') && has_privs('rah_backup_preferences') ? 
				'<a class="navlink" href="?event=prefs&amp;step=advanced_prefs#prefs-rah_backup_path">'.
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