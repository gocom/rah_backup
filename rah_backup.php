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
		rah_backup_install();
		add_privs('rah_backup', '1,2');
		add_privs('rah_backup_create', '1,2');
		add_privs('rah_backup_restore', '1');
		add_privs('rah_backup_download', '1,2');
		add_privs('rah_backup_delete', '1');
		add_privs('rah_backup_preferences', '1');
		add_privs('plugin_prefs.rah_backup', '1,2');
		register_tab('extensions', 'rah_backup', gTxt('rah_backup'));
		register_callback('rah_backup_page', 'rah_backup');
		register_callback('rah_backup_head', 'admin_side','head_end');
		register_callback('rah_backup_prefs', 'plugin_prefs.rah_backup');
		register_callback('rah_backup_install', 'plugin_lifecycle.rah_backup');
	}
	elseif(@txpinterface == 'public')
		register_callback('rah_backup_do', 'textpattern');

/**
 * Installer
 * @param string $event Admin-side event.
 * @param string $step Admin-side, plugin-lifecycle step.
 */

	function rah_backup_install($event='', $step='') {
		
		global $prefs;
		
		if($step == 'deleted') {
			
			safe_delete(
				'txp_prefs',
				"name like 'rah\_backup\_%'"
			);
			
			return;
		}
		
		$version = '0.1';
		
		$current = isset($prefs['rah_backup_version']) ?
			$prefs['rah_backup_version'] : 'base';
		
		if($current == $version)
			return;
		
		$position = 250;
		
		foreach(
			array(
				'path' => '',
				'copy_paths' => '',
				'mysql' => 'mysql',
				'mysqldump' => 'mysqldump',
				'tar' => 'tar',
				'compress' => 0,
				'gzip' => 'gzip',
				'gzip_level' => 6,
				'overwrite' => 1,
				'callback' => 0,
				'allow_restore' => 1,
				'key' => md5(uniqid(mt_rand(), TRUE)),
				'maintenance' => '',
				'sqlscript' => '',
			) as $name => $val
		) {

			$n = 'rah_backup_' . $name;
			
			if(!isset($prefs[$n])) {

				switch($name) {
					case 'callback':
					case 'compress':
					case 'overwrite':
					case 'allow_restore':
					case 'maintenance':
						$html = 'yesnoradio';
						break;
					case 'gzip_level':
						$html = 'rah_backup_gzip_level';
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
		
		set_pref('rah_backup_version', $version, 'rah_backup', 2, '', 0);
		$prefs['rah_backup_version'] = $version;
	}

/**
 * Deliver panel
 */

	function rah_backup_page() {
		require_privs('rah_backup');
		global $step;
		
		$steps = 
			array(
				'list' => false,
				'do' => true,
				'restore' => true,
				'delete' => true,
				'download' => true
			);
		
		if(!$step || !bouncer($step, $steps))
			$step = 'list';
		
		$func = 'rah_backup_' . $step;
		$func();
	}

/**
 * Adds the panel's CSS to the head segment.
 */

	function rah_backup_head() {
		global $event, $step;
		
		if($event != 'rah_backup')
			return;
			
		$pfx = 'rah_backup';
		
		$js = 
			json_encode(
				array(
					'database_will_be_overwriten' => gTxt('rah_backup_database_will_be_overwriten'),
					'inprogress' => gTxt('rah_backup_inprogress'),
					'confirm_backup' => gTxt('rah_backup_confirm_backup'),
					'restoring' => gTxt('rah_backup_restoring'),
					'restored' => gTxt('rah_backup_restored'),
				)
			);
		
		$msg = gTxt('are_you_sure');
		
		echo <<<EOF
			<script type="text/javascript">
				<!--
				
				{$pfx} = function() {
				
					var l10n = {$js};
					var pfx = '{$pfx}';
					var pane = $('#'+pfx+'_container');

					/**
						Multi-edit function, auto-hiden dropdown
					*/
				
					(function() {
						
						var steps = $('#'+pfx+'_step');
					
						if(!steps.length)
							return;
						
						steps.children('.smallerbox').hide();
						
						pane.find('th.rah_ui_selectall').html(
							'<input type="checkbox" name="selectall" value="1" />'
						);
						
						if(pane.children('input[type=checkbox]:checked').val() == null)
							steps.hide();
						
						/*
							Reset the value
						*/
	
						steps.children('select[name="step"]').val('');
						
						/*
							Check all
						*/
	
						pane.find('input[name="selectall"]').live('click',
							function() {
								var tr = pane.find('table tbody input[type=checkbox]');
								
								if($(this).is(':checked'))
									tr.attr('checked', true);
								else
									tr.removeAttr('checked');
							}
						);
						
						/*
							Every time something is checked, check if
							the dropdown should be shown
						*/
						
						pane.find('table input[type=checkbox], td').live('click',
							function(){
								steps.children('select[name="step"]').val('');
								
								if(pane.find('tbody input[type=checkbox]:checked').val() != null)
									steps.slideDown();
								else
									steps.slideUp();
							}
						);
						
						/*
							Uncheck the check all box if an item is unchecked
						*/
						
						pane.find('tbody input[type=checkbox]').live('click',
							function() {
								pane.find('input[name="selectall"]').removeAttr('checked');
							}
						);
	
						/*
							If value is changed, send the form
						*/
	
						steps.change(
							function(){
								steps.parents('form').submit();
							}
						);
	
						/*
							Verify if the sent is allowed
						*/
						
						
						$('form').submit(
							function() {
								if(!verify(l10n['are_you_sure'])) {
									steps.children('select[name="step"]').val('');
									return false;
								}
							}
						);
					})();
					
					/**
						Do a backup
					*/
					
					(function() {
						
						$('a#rah_backup_do').click(
							function(e) {
								e.preventDefault();
								
								if(!verify(l10n['confirm_backup']))
									return false;
									
								$(this).after('<span id="rah_backup_statusmsg">'+l10n['inprogress']+'</span>').hide();
									
								$.ajax(
									{
										type : 'POST',
										url : 'index.php',
										data : {
											'event' : textpattern['event'],
											'step' : 'do',
											'_txp_token' : textpattern['_txp_token'],
										},
										
										success: function(data, status, xhr) {
											$('#rah_backup_container table#list tbody').html($(data).find('#rah_backup_list').html());
										},
										
										error: function() {
											
										},
										
										complete: function() {
											$('#rah_backup_statusmsg').hide();
											$('#rah_backup_do').show();
										}
									}
								);
							}
						).attr('href','#');
					})();
					
					/**
						Restore a backup
					*/
					
					(function() {
						
						$('.rah_backup_restore a').live('click',
							function(e) {
								e.preventDefault();
								
								if(!verify(l10n['database_will_be_overwriten']))
									return false;
									
								$('.rah_backup_restore a').hide();
									
								var link = $(this);
								var filename = $(this).attr('title');
								
								link.after('<span class="rah_backup_restoring">'+l10n['restoring']+'</span>');
								
								$.ajax(
									{
										type : 'POST',
										url : 'index.php',
										data : {
											'event' : textpattern['event'],
											'step' : 'restore',
											'_txp_token' : textpattern['_txp_token'],
											'file' : filename
										},
										
										success: function(data, status, xhr) {
											link.next('span.rah_backup_restoring').text(l10n['restored']);
										},
										
										error: function() {
											
										},
										
										complete: function() {
											$('.rah_backup_restore a').show();
											link.hide();
										}
									}
								);	
								
							}
						).attr('href','#');
					})();
				};

				$(document).ready(function(){
					{$pfx}();
				});
				-->
			</script>
			<style type="text/css">
				#rah_backup_container {
					width: 950px;
					margin: 0 auto;
				}
				#rah_backup_container table {
					width: 100%;
				}
				#rah_backup_container #rah_backup_step {
					text-align: right;
				}
				#rah_backup_container td.rah_backup_restore {
					min-width: 100px;
				}
			</style>
EOF;
	}

/**
 * The main listing
 * @param string $message Activity message.
 */

	function rah_backup_list($message='') {
	
		global $event, $prefs;
		
		$out[] = 
			
			'	<table cellspacing="0" cellpadding="0" id="list">'.n.
			'		<thead>'.n.
			'			<tr>'.n.
			'				<th>'.gTxt('rah_backup_filename').'</th>'.n.
			'				<th>&#160;</th>'.n.
			'				<th>'.gTxt('rah_backup_date').'</th>'.n.
			'				<th>'.gTxt('rah_backup_size').'</th>'.n.
			'				<th>'.($prefs['rah_backup_allow_restore'] ? gTxt('rah_backup_restore') : '&#160;').'</th>'.n.
			'				<th class="rah_ui_selectall">&#160;</th>'.n.
			'			</tr>'.n.
			'		</thead>'.n.
			'		<tbody id="rah_backup_list">'.n;
		
		$er = rah_backup_er();

		if(!$er) {
			
			$files = glob(preg_replace('/(\*|\?|\[)/', '[$1]', $prefs['rah_backup_path']) . '/'.'*[.sql|.tar]', GLOB_NOSORT);
		
			if($files && is_array($files)) {
				
				$f = array();
			
				foreach($files as $file) {
				
					if(!is_readable($file) || !is_file($file))
						continue;
					
					$ext = pathinfo($file, PATHINFO_EXTENSION);
					$name = htmlspecialchars(basename($file));
					$gz = $file.'.gz';
				
					$f[$name] = 
					
						'			<tr>'.n.
						'				<td>'.
						
						(
							has_privs('rah_backup_download') ?
						
								'<a title="'.gTxt('rah_backup_download').
									'" href="?event='.$event.
									'&amp;step=download&amp;file='.urlencode($name).
									'&amp;_txp_token='.form_token().
									'">'.$name.'</a>'
							: $name
						).
									'</td>'.n.
						
						(
							has_privs('rah_backup_download') && file_exists($gz) && is_readable($gz) && is_file($gz) ?
								'				<td>'.
								'<a title="'.gTxt('rah_backup_download').' '.
								rah_backup_size(filesize($gz)).
								'" href="?event='.$event.
									'&amp;step=download&amp;file='.
										urlencode($name.'.gz').'&amp;_txp_token='.form_token().
									'">.gz</a></td>'.n
							: 
								'				<td>&#160;</td>'.n
						
						).
						
						'				<td>'.safe_strftime(gTxt('rah_backup_dateformat'), filemtime($file)).'</td>'.n.
						'				<td>'.rah_backup_size(filesize($file)).'</td>'.n.
						
						(
							has_privs('rah_backup_restore') && $ext == 'sql' && $prefs['rah_backup_allow_restore'] ? 
								'				<td class="rah_backup_restore">'.
									'<a title="'.$name.'" '.
										'href="?event='.$event.
											'&amp;step=restore&amp;file='.urlencode($name).
											'&amp;_txp_token='.form_token().
									'">'.gTxt('rah_backup_restore').'</a></td>'.n
							:
								'				<td>&#160;</td>'.n
						).
						
						'				<td>'.(has_privs('rah_backup_delete') ? '<input type="checkbox" name="selected[]" value="'.$name.'" />' : '').'</td>'.n.
						'			</tr>'.n;
						
				}
				
				krsort($f);
				$out[] = implode('',$f);
				
			}
			else
				$er = 'no_backups';
		}
		
		if($er)
			
			$out[] = 
				'			<tr>'.n.
				'				<td id="rah_backup_msgrow" colspan="6">'.
					gTxt(
						'rah_backup_'.$er,
						array(
							'{start_by}' => 
								'<a href="?event=prefs&amp;'.
									'step=advanced_prefs#prefs-rah_backup_path">'.
									gTxt('rah_backup_start_by').
								'</a>'
						),
						false
					).
								'</td>'.n.
				'			</tr>'.n;
		
		$out[] = 
			
			'		</tbody>'.n.
			'	</table>'.n.
			
			(has_privs('rah_backup_delete') ?
				'	<p id="rah_backup_step" class="rah_ui_step">'.n.
				'		<select name="step">'.n.
				'			<option value="">'.gTxt('rah_backup_with_selected').'</option>'.n.
				'			<option value="delete">'.gTxt('rah_backup_delete').'</option>'.n.
				'		</select>'.n.
				'		<input type="submit" class="smallerbox" value="'.gTxt('go').'" />'.n.
				'	</p>' : ''
			);
		
		rah_backup_header(
			$out,
			$message
		);
	}
	
/**
 * Does dump from the database
 * @param string $event Callback event.
 */

	function rah_backup_do($event='') {
		
		global $txpcfg, $prefs;
		
		if(!$event && !has_privs('rah_backup_create')) {
			rah_backup_list();
			return;
		}
		
		if(
			$event && 
			(
				!gps('rah_backup') || 
				!$prefs['rah_backup_callback'] || 
				!$prefs['rah_backup_key'] || 
				$prefs['rah_backup_key'] != gps('rah_backup')
			)
		)
			return;
		
		if(($er = rah_backup_er()) && $er) {
			if(!$event)
				rah_backup_list($er);
			return;
		}

		@set_time_limit(0);
		@ignore_user_abort(true);
		
		callback_event('rah_backup_tasks', 'backuping');
		
		$file = array();
		
		/*
			Dump database
		*/
		
		$now = $prefs['rah_backup_overwrite'] ? '' : '_'.safe_strtotime('now');
		$db = substr(preg_replace('/[^A-Za-z0-9-._]/','',$txpcfg['db']),0,64);
		$db = ($db ? $db : 'database') . $now . '.sql';
		$file['db'] = rah_backup_path('path') . '/' . $db;
		
		$returned = 
			rah_backup_exec(
				rah_backup_path('mysqldump'),
				array(
					'--opt' => false,
					'--skip-comments' => false,
					'--host' => $txpcfg['host'],
					'--user' => $txpcfg['user'],
					'--password' => $txpcfg['pass'],
					'--result-file' => $file['db'],
					'' => $txpcfg['db']
				)
			);
		
		/*
			In practice it almost never returns false,
			so this might be pretty pointless really
		*/
		
		if($returned === false) {
			
			/*
				Remove the partial, possibly corrupted, backup file
			*/
			
			if(file_exists($file['db']) && is_file($file['db']) && is_writeable($file['db']))
				unlink($file['db']);
			
			unset($file['db']);
			
			if(!$event)
				rah_backup_list('dumping_db_failed');
			
			return;
		}
		
		/*
			Create additional compressed file
		*/
		
		if($prefs['rah_backup_compress']) {
			
			$gzip_level = 
				in_array($prefs['rah_backup_gzip_level'], range(1,9)) ? 
					$prefs['rah_backup_gzip_level'] : 9;
			
			$file['db_gz'] = $file['db'].'.gz';
			
			rah_backup_exec(
				rah_backup_path('gzip'),
				array(
					'-c'.$gzip_level => false,
					'' => $file['db'],
					'>' => $file['db_gz']
				)
			);
		}
		
		/*
			Copy directories
		*/
		
		if($prefs['rah_backup_copy_paths'] && $dirs = explode(',', $prefs['rah_backup_copy_paths'])) {
			
			$site = 
				substr(
					preg_replace('/[^A-Za-z0-9-_]/','',
						str_replace(
							array('.',':','/'),
							'_',
							trim($prefs['siteurl'])
						)
					), 0, 64
				);
			
			$site = $site ? $site : 'filesystem';
			
			$file['fs'] = rah_backup_path('path').'/'.$site.$now.'.tar';
			
			$opt = 
				array(
					array('-cvpzf' => false),
					array('' => $file['fs'])
				);
			
			$paths = false;
			
			foreach($dirs as $path) {
				$path = rah_backup_path($path, false);
				if($path && file_exists($path) && is_readable($path) && is_dir($path)) {
					$opt[] = array('' => $path);
					$paths = true;
				}
			}

			if($paths) {
				
				rah_backup_exec(
					$prefs['rah_backup_tar'],
					$opt
				);
				
				if($prefs['rah_backup_compress']) {
					
					$file['fs_gz'] = $file['fs'].'.gz';
					
					rah_backup_exec(
						rah_backup_path('gzip'),
						array(
							'-c'.$gzip_level => false,
							'' => $file['fs'],
							'>' => $file['fs_gz']
						)
					);
				}
			}
		}

		callback_event('rah_backup_tasks', 'backup_done', 0, array('files' => $file));

		if($event)
			die();

		rah_backup_list('done');
	}

/**
 * Restore backup
 */

	function rah_backup_restore() {
		
		global $txpcfg, $prefs;
		
		if(!has_privs('rah_backup_restore') || !$prefs['rah_backup_allow_restore']) {
			rah_backup_list();
			return;
		}
		
		if(($er = rah_backup_er()) && $er) {
			rah_backup_list($er);
			return;
		}
		
		$file = preg_replace('/[^A-Za-z0-9-._]/','',gps('file'));
		$path = rah_backup_path('path').'/'.$file;
		$ext = pathinfo($file, PATHINFO_EXTENSION);

		if(
			!$file || 
			!$ext || 
			($ext != 'sql' && $ext != 'gz') || 
			!file_exists($path) || 
			!is_readable($path) || 
			!is_file($path)
		) {
			rah_backup_list('can_not_restore');
			return;	
		}
		
		callback_event('rah_backup_tasks', 'restoring', 1);
		
		$returned = 
			rah_backup_exec(
				$prefs['rah_backup_mysql'],
				array(
					'--host' => $txpcfg['host'],
					'--user' => $txpcfg['user'],
					'--password' => $txpcfg['pass'],
					'' => $txpcfg['db'],
					'<' => $path
				)
			);
		
		if($returned === false) {
			rah_backup_list('can_not_restore');
			return;	
		}
		
		$path = rah_backup_path($prefs['rah_backup_sqlscript'],false);
		
		if(
			$prefs['rah_backup_maintenance'] &&
			$path &&
			file_exists($path) &&
			is_readable($path) &&
			is_file($path)
		)
			rah_backup_exec(
				$prefs['rah_backup_mysql'],
				array(
					'--host' => $txpcfg['host'],
					'--user' => $txpcfg['user'],
					'--password' => $txpcfg['pass'],
					'' => $txpcfg['db'],
					'<' => $path
				)
			);
		
		callback_event('rah_backup_tasks', 'restore_done');
		rah_backup_list('restore_done');
	}

/**
 * Downloads backup file
 */

	function rah_backup_download() {

		global $prefs;
		
		if(!has_privs('rah_backup_download')) {
			rah_backup_list();
			return;
		}

		$file = preg_replace('/[^A-Za-z0-9-._]/','',gps('file'));
		$path = rah_backup_path('path').'/'.$file;
		$ext = pathinfo($file, PATHINFO_EXTENSION);

		if(
			!$file || 
			!$ext || 
			($ext != 'sql' && $ext != 'gz' && $ext != 'tar') || 
			!file_exists($path) || 
			!is_writeable($path) || 
			!is_file($path)
		) {
			rah_backup_list('can_not_download');
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
		
		callback_event('rah_backup_tasks', 'download_done', 0, $path);
		exit;
	}

/**
 * Deletes a backup file
 */

	function rah_backup_delete() {
		
		global $prefs;
		
		if(!has_privs('rah_backup_delete')) {
			rah_backup_list();
			return;
		}
		
		$selected = ps('selected');
		
		if(empty($selected) || !is_array($selected)) {
			rah_backup_list('select_something');
			return;
		}
		
		$dir = rah_backup_path('path');
		
		foreach($selected as $file) {
			
			$ext = pathinfo($file, PATHINFO_EXTENSION);
			
			if(!$file || !$ext || ($ext != 'sql' && $ext != 'gz' && $ext != 'tar'))
				continue;
			
			$file = $dir.'/'.preg_replace('/[^A-Za-z0-9-._]/','',$file);
			
			if(!file_exists($file) || !is_writeable($file) || !is_file($file))
				continue;
			
			$gz = $file.'.gz';
			
			if(file_exists($gz) && is_writeable($gz) && is_file($gz))
				unlink($gz);
			
			unlink($file);
		}
		
		rah_backup_list('removed');
	}

/**
 * Checks for errors/problems that could prevent commands from running.
 * @param bool $cmd_check Wheter check exec() access and for safe-mode restrictions.
 * @return string Language string or false when no issues are found.
 */

	function rah_backup_er($cmd_check=true) {
		
		global $txpcfg, $prefs;
	
		if(
			!trim($prefs['rah_backup_mysqldump']) ||
			!trim($prefs['rah_backup_mysql']) ||
			!trim($prefs['rah_backup_path'])
		)
			return 'define_preferences';
		
		$dir = rah_backup_path('path');
		
		if(!file_exists($dir) || !is_dir($dir))
			return 'backup_dir_not_found';
		
		if(!is_readable($dir))
			return 'backup_dir_not_readable';
		
		if(!is_writable($dir))
			return 'backup_dir_not_writable';
		
		/*
			End here if command checks are ignored
		*/
		
		if($cmd_check == false)
			return false;
		
		if(rah_backup_is_disabled('exec'))
			return 'exec_func_unavailable';
		
		/*
			Check for safe mode limitations
		*/
		
		if(
			@ini_get('safe_mode') && 
			(
				strpos(
					$txpcfg['db'], '\\'
				) !== false ||
				strpos(
					$prefs['rah_backup_mysqldump'].
					$prefs['rah_backup_mysql'].
					$prefs['rah_backup_path'].
					$txpcfg['db'], '..'
				) !== false
			)
		)
			return 'safe_mode_no_exec_access';
		
		return false;
	}

/**
 * Execute shell command
 * @param string $command The program to run.
 * @param array $args The arguments passed to the application.
 * @return bool
 */

	function rah_backup_exec($command, $args) {

		$escape = @ini_get('safe_mode') || rah_backup_is_disabled('escapeshellcmd');
		$cmd[] = $escape ? $command : escapeshellcmd($command);
		
		foreach($args as $arg => $val) {
			
			if(is_array($val)) {
				foreach($val as $k => $v) {
					$arg = $k;
					$val = $v;
				}
			}
			
			if($val !== false)
				$val = "'".str_replace("'", "'\\''", $val)."'";
			
			if($arg == '<' || $arg == '>' || $arg === '')
				$cmd[] = ($arg ? $arg.' ' : '').$val;
			
			else {
				$arg = $escape ? $arg : escapeshellcmd($arg);
				
				if($val === false)
					$cmd[] = $arg;
				
				else
					$cmd[] = $arg.'='.$val;
				
			}
		}
		
		return exec(implode(' ', $cmd));
	}

/**
 * Checks whether function is disabled
 * @param string $func
 * @return bool
 */

	function rah_backup_is_disabled($func) {
		return is_disabled($func) || !function_exists($func);
	}

/**
 * Return paths
 * @param string $item Path to return.
 * @param bool $pref Is $item preference string or path.
 * @return string
 */

	function rah_backup_path($item, $pref=true) {

		global $prefs;
		
		if($pref) {
			if(!isset($prefs['rah_backup_'.$item]))
				return;
			
			$path = $prefs['rah_backup_'.$item];
		}
		else
			$path = $item;
		
		if(DS != '/' && @ini_get('safe_mode'))
			$path = str_replace('\\', '/', $path);
		
		return rtrim(trim($path),'/\\');
	}

/**
 * Format filesize
 * @param int $bytes Size in bytes.
 * @return string Formatted size.
 */

	function rah_backup_size($bytes) {
		$units = array('b', 'k', 'm', 'g', 't', 'p', 'e', 'z', 'y');
		$pow = floor(($bytes ? log($bytes) : 0) / log(1024));
		$pow = min($pow, count($units) - 1);
		$bytes /= pow(1024, $pow);
		$separators = localeconv();
		$sep_dec = isset($separators['decimal_point']) ? $separators['decimal_point'] : '.';
		$sep_thous = isset($separators['thousands_sep']) ? $separators['thousands_sep'] : ',';
		return number_format($bytes, 2, $sep_dec, $sep_thous) . ' ' . gTxt('rah_backup_units_' . $units[$pow]);
	}

/**
 * Gzip compression level option
 * @param string $name Field name.
 * @param int $val Current value.
 * @return HTML select field.
 */

	function rah_backup_gzip_level($name, $val) {
		
		foreach(range(1,9) as $level)
			$out[$level] = gTxt('rah_backup_gzip_level_n_'.$level);
		
		return selectInput($name, $out, $val, '', '', $name);
	}

/**
 * Echoes the panels and header
 * @param string $content Pane's HTML markup.
 * @param string $message The activity message.
 */

	function rah_backup_header($content, $message) {
		
		global $event;
		
		pagetop(gTxt('rah_backup'), $message ? gTxt('rah_backup_' . $message) : '');
		
		if(is_array($content))
			$content = implode('',$content);
		
		echo 
			n.
			
			'<form action="index.php" method="post" id="rah_backup_container" class="rah_ui_container">'.n.
			'	'.eInput($event).n.
			'	'.tInput().n.
			
			'	<p class="rah_ui_nav">'.
			
			(has_privs('rah_backup_create') ? 
				' <span class="rah_ui_sep">&#187;</span> '.
				'<a id="rah_backup_do" href="?event='.$event.'&amp;step=do&amp;_txp_token='.form_token().'">'.
					gTxt('rah_backup_create').
				'</a>' : ''
			).
			
			(has_privs('prefs') && has_privs('rah_backup_preferences') ? 
				' <span class="rah_ui_sep">&#187;</span> '.
				'<a href="?event=prefs&amp;step=advanced_prefs#prefs-rah_backup_path">'.
					gTxt('rah_backup_preferences').
				'</a>' : ''
			).
					
			'</p>'.n.
			$content.n.
			'</form>'.n;
	}

/**
 * Redirect to the admin-side interface
 */

	function rah_backup_prefs() {
		header('Location: ?event=rah_backup');
		echo 
			'<p>'.n.
			'	<a href="?event=rah_backup">'.gTxt('continue').'</a>'.n.
			'</p>';
	}
?>