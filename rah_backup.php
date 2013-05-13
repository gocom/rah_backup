<?php

/**
 * Rah_backup plugin for Textpattern CMS.
 *
 * @author  Jukka Svahn
 * @license GNU GPLv2
 * @link    https://github.com/gocom/rah_backup
 *
 * Copyright (C) 2013 Jukka Svahn http://rahforum.biz
 * Licensed under GNU General Public License version 2
 * http://www.gnu.org/licenses/gpl-2.0.html
 */

class rah_backup
{
	/**
	 * Filesystem backup type.
	 *
	 * @const int
	 */

	const BACKUP_FILESYSTEM = 1;

	/**
	 * Database backup type.
	 *
	 * @const int
	 */

	const BACKUP_DATABASE = 2;

	/**
	 * Path to directory storing backups.
	 *
	 * @var string 
	 */

	private $backup_dir;

	/**
	 * List of backed up files.
	 *
	 * @var array
	 */

	private $copy_paths = array();

	/**
	 * List of excluded files.
	 *
	 * @var array
	 */

	private $exclude_files = array();

	/**
	 * List of ignored tables.
	 *
	 * @var array
	 */

	private $ignore_tables = array();

	/**
	 * Timestamp append to backup archives.
	 *
	 * @var string
	 */

	private $filestamp = '';

	/**
	 * Paths to created backup files.
	 *
	 * @var array
	 */

	public $created = array();

	/**
	 * Paths to deleted backup files.
	 *
	 * @var array
	 */

	public $deleted = array();

	/**
	 * List of invoked messages.
	 *
	 * @var array
	 */

	public $message = array();

	/**
	 * List of invoked announcements.
	 *
	 * @var array
	 */

	private $announce = array();

	/**
	 * List of invoked errors/notices.
	 *
	 * @var array
	 */

	public $warning = array();

	/**
	 * Constructor.
	 */

	public function __construct()
	{
		add_privs('rah_backup', '1,2');
		add_privs('rah_backup_create', '1,2');
		add_privs('rah_backup_restore', '1');
		add_privs('rah_backup_download', '1,2');
		add_privs('rah_backup_multi_edit', '1,2');
		add_privs('rah_backup_delete', '1');
		add_privs('rah_backup_preferences', '1');
		add_privs('plugin_prefs.rah_backup', '1,2');
		add_privs('prefs.rah_backup', '1');
		register_tab('extensions', 'rah_backup', gTxt('rah_backup'));
		register_callback(array($this, 'install'), 'plugin_lifecycle.rah_backup', 'installed');
		register_callback(array($this, 'uninstall'), 'plugin_lifecycle.rah_backup', 'deleted');
		register_callback(array($this, 'prefs'), 'plugin_prefs.rah_backup');
		register_callback(array($this, 'pane'), 'rah_backup');
		register_callback(array($this, 'head'), 'admin_side', 'head_end');
		register_callback(array($this, 'call_backup'), 'textpattern');
	}

	/**
	 * Initializes.
	 */

	public function initialize()
	{
		global $prefs, $txpcfg;

		if (!$prefs['rah_backup_path'])
		{
			$this->message[] = gTxt('rah_backup_define_preferences', array(
				'{start_by}' => href(gTxt('rah_backup_start_by'), '?event=prefs#prefs-rah_backup_path'),
			), false);
		}

		else
		{
			$dir = $this->path($prefs['rah_backup_path']);

			if (!file_exists($dir) || !is_dir($dir))
			{
				$this->warning[] = gTxt('rah_backup_dir_not_found', array('{path}' => $dir));
			}

			else if (!is_readable($dir))
			{
				$this->warning[] = gTxt('rah_backup_dir_not_readable', array('{path}' => $dir));
			}

			else if (!is_writable($dir))
			{
				$this->warning[] = gTxt('rah_backup_dir_not_writable', array('{path}' => $dir));
			}

			else
			{
				$this->backup_dir = $dir;
			}
		}

		@$tables = (array) getThings('SHOW TABLES');

		foreach (do_list($prefs['rah_backup_ignore_tables']) as $table)
		{
			if (!$table)
			{
				continue;
			}

			if (in_array(PFX.$table, $tables))
			{
				$this->ignore_tables[PFX.$tbl] = PFX.$tbl;
			}
			else
			{
				$this->warning[] = gTxt('rah_backup_invalid_ignored_table', array('{name}' => $table));
			}
		}

		foreach (do_list($prefs['rah_backup_copy_paths']) as $f)
		{
			if (!$f)
			{
				continue;
			}

			$f = $this->path($f);

			if (file_exists($f) && is_readable($f))
			{
				$this->copy_paths[$f] = $f;
			}
			else
			{
				$this->warning[] = gTxt('rah_backup_invalid_ignored_file', array('{name}' => $f));
			}
		}

		foreach (do_list($prefs['rah_backup_exclude_files']) as $f)
		{
			if ($f)
			{
				$this->exclude_files[$f] = $f;
			}
		}

		if (!$prefs['rah_backup_overwrite'])
		{
			$this->filestamp = '_'.safe_strtotime('now');
		}
	}

	/**
	 * Installer.
	 */

	public function install()
	{
		$position = 250;

		foreach (
			array(
				'path'          => array('text_input', ''),
				'copy_paths'    => array('text_input', '../'),
				'exclude_files' => array('text_input', ''),
				'ignore_tables' => array('text_input', ''),
				'compress'      => array('yesnoradio', 0),
				'overwrite'     => array('yesnoradio', 0),
				'callback'      => array('yesnoradio', 0),
				'key'           => array('text_input', md5(uniqid(mt_rand(), TRUE))),
			) as $name => $val
		)
		{
			$n = 'rah_backup_'.$name;

			if (get_pref($n, false) === false)
			{
				set_pref($n, $val[1], 'rah_backup', PREF_ADVANCED, $val[0], $position);
			}

			$position++;
		}
	}

	/**
	 * Uninstaller.
	 */

	public function uninstall()
	{
		safe_delete('txp_prefs', "name like 'rah\_backup\_%'");
	}

	/**
	 * Delivers panels.
	 */

	public function pane()
	{
		global $step;

		require_privs('rah_backup');

		$steps = 
			array(
				'browser' => false,
				'create' => true,
				'restore' => true,
				'download' => true,
				'multi_edit' => true,
			);

		$this->initialize();

		if ($this->message || $this->warning || !$step || !bouncer($step, $steps) || !has_privs('rah_backup_' . $step))
		{
			$step = 'browser';
		}

		$this->$step();
	}

	/**
	 * Adds the panel's CSS and JavaScript to the &lt;head&gt;.
	 */

	public function head()
	{
		global $event, $theme;

		if ($event != 'rah_backup')
		{
			return;
		}

		gTxtScript(array(
			'rah_backup_confirm_restore',
			'rah_backup_confirm_backup',
		));

		$msg = array(
			'backup' => escape_js($theme->announce_async(gTxt('rah_backup_taking'))),
			'restore' => escape_js($theme->announce_async(gTxt('rah_backup_restoring'))),
			'error' => escape_js($theme->announce_async(gTxt('rah_backup_task_error'))),
		);

		$js = <<<EOF
				$(document).ready(function(){
					$('.rah_backup_restore, .rah_backup_take').live('click', function(e) {
						e.preventDefault();
						var obj = $(this);

						if (obj.hasClass('rah_backup_take'))
						{
							var message = textpattern.gTxt('rah_backup_confirm_backup');
						}
						else
						{
							var message = textpattern.gTxt('rah_backup_confirm_restore');
						}
	
						if (obj.hasClass('disabled') || !verify(message))
						{
							return false;
						}

						if (obj.hasClass('rah_backup_take'))
						{
							$.globalEval('{$msg['backup']}');
							obj.parent().append(' <span class="spinner"></span>');
						}
						else
						{
							$.globalEval('{$msg['restore']}');
						}

						var href = $(this).attr('href');
						obj.addClass('disabled').attr('href', '#');

						sendAsyncEvent(href.substr(1), null, 'script').error(function() {
							$.globalEval('{$msg['error']}');
						}).complete(function() {
							obj.removeClass('disabled').attr('href', href);
							obj.parent().find('.spinner').remove();
						});
					});
				});
EOF;

		echo script_js($js);
	}

	/**
	 * Announces a message.
	 *
	 * @param  string|array $message The message
	 * @param  string       $type    The type, either 'message', 'inform', 'warning'
	 * @return rah_backup
	 */

	public function announce($message, $type=-1)
	{
		$this->announce[$type][] = $message;
		return $this;
	}

	/**
	 * The main panel listing backups.
	 *
	 * @param string|array $message The activity message
	 */

	private function browser($message = '')
	{
		global $event, $prefs, $app_mode, $theme;

		extract(gpsa(array(
			'sort',
			'dir',
		)));

		$methods = array();

		if (has_privs('rah_backup_delete'))
		{
			$methods['delete'] = gTxt('rah_backup_delete');
		}

		$columns = array('name', 'date', 'type', 'size');

		if ($dir !== 'desc' && $dir !== 'asc')
		{
			$dir = get_pref($event.'_sort_dir', 'asc');
		}

		if (!in_array((string) $sort, $columns))
		{
			$sort = get_pref($event.'_sort_column', 'name');
		}

		if ($methods)
		{
			$column[] = hCell(fInput('checkbox', 'select_all', 0, '', '', '', '', '', 'select_all'), '', ' title="'.gTxt('toggle_all_selected').'" class="multi-edit"');
		}

		foreach ($columns as $name)
		{
			$column[] = column_head($event.'_'.$name, $name, $event, true, $name === $sort && $dir === 'asc' ? 'desc' : 'asc', '', '', $name === $sort ? $dir : '', 'browse');
		}

		if (has_privs('rah_backup_restore'))
		{
			$column[] = hCell(gTxt('rah_backup_restore'));
		}

		set_pref($event.'_sort_column', $sort, $event, 2, '', 0, PREF_PRIVATE);
		set_pref($event.'_sort_dir', $dir, $event, 2, '', 0, PREF_PRIVATE);

		if (!$this->message)
		{
			$backups = $this->get_backups($sort, $dir);

			foreach ($backups as $backup)
			{
				$td = array();
				$name = txpspecialchars($backup['name']);

				if ($methods)
				{
					$td[] = td(fInput('checkbox', 'selected[]', $name), '', 'multi-edit');
				}

				if (has_privs('rah_backup_download'))
				{
					$td[] = td('<a title="'.gTxt('rah_backup_download').'" href="?event='.$event.'&amp;step=download&amp;file='.urlencode($name).'&amp;_txp_token='.form_token().'">'.$name.'</a>');
				}
				else
				{
					$td[] = td($name);
				}

				$td[] = td(safe_strftime(gTxt('rah_backup_dateformat'), $backup['date']));
				$td[] = td(gTxt('rah_backup_type_'.$backup['type']));
				$td[] = td(format_filesize($backup['size']));

				if (has_privs('rah_backup_restore'))
				{
					if ($backup['type'] === self::BACKUP_DATABASE && !$this->warning)
					{
						$td[] = td('<a class="rah_backup_restore" title="'.$name.'" href="?event='.$event.'&amp;step=restore&amp;file='.urlencode($name).'&amp;_txp_token='.form_token().'">'.gTxt('rah_backup_restore').'</a>');
					}
					else
					{
						$td[] = td('');
					}
				}

				$out[] = tr(implode(n, $td));
			}

			if (!$backups)
			{
				$this->message[] = gTxt('rah_backup_no_backups');
			}
		}

		if ($this->message)
		{
			$out[] = tr(tda($this->message[0], array('colspan' => count($column))));
		}

		$out = implode('', $out);

		if (!empty($this->announce[-1]))
		{
			$message = $this->announce[-1][0];
		}

		if ($app_mode == 'async')
		{
			send_script_response($theme->announce_async($message).n.'$("#rah_backup_list").html("'.escape_js($out).'");');
			return;
		}

		if ($this->warning)
		{
			$pane[] = '<p class="alert-block warning">'.$this->warning[0].'</p>';
		}

		foreach (array('success', 'warning', 'error', 'information', 'highlight') as $type)
		{
			if (!empty($this->announce[$type]))
			{
				$pane[] = '<p class="alert-block '.$type.'">'.implode('</p><p class="alert-block '.$type.'">', $this->announce[$type]).'</p>';
			}
		}

		$pane[] =
			n.tag_start('div', array('class' => 'txp-listtables')).
			n.tag_start('table', array('class' => 'txp-list')).
			n.tag_start('thead').
			tr(implode('', $column)).
			n.tag_end('thead').
			n.tag_start('tbody', array('id' => 'rah_backup_list')).
			$out.
			n.tag_end('tbody').
			n.tag_end('table').
			n.tag_end('div');

		if ($methods)
		{
			$pane[] = multi_edit($methods, $event, 'multi_edit');
		}

		pagetop(gTxt('rah_backup'), $message);

		echo
			hed(gTxt('rah_backup'), 1, array('class' => 'txp-heading')).

			n.tag_start('div', array(
				'class' => 'txp-container',
			)).

			n.tag_start('p', array('class' => 'txp-buttons'));

		if (has_privs('rah_backup_create') && !$this->warning)
		{
			echo n.href(gTxt('rah_backup_create'), array(
				'event'      => $event,
				'step'       => 'create',
				'_txp_token' => form_token(),
			), array(
				'class' => 'rah_backup_take',
			));
		}

		if (has_privs('prefs') && has_privs('rah_backup_preferences'))
		{
			echo n.href(gTxt('rah_backup_preferences'), '?event=prefs#prefs-rah_backup_path');
		}

		echo
			n.tag_end('p').
			n.tag_start('form', array(
				'action' => 'index.php',
				'method' => 'post',
				'class'  => 'multi_edit_form',
			)).
			tInput().
			n.implode('', $pane).
			n.tag_end('form').
			n.tag_end('div');
	}

	/**
	 * Public callback end-point for creating backups.
	 */

	public function call_backup()
	{
		global $prefs;

		if (
			!gps('rah_backup') || 
			empty($prefs['rah_backup_callback']) || 
			empty($prefs['rah_backup_key']) || 
			$prefs['rah_backup_key'] !== gps('rah_backup')
		)
		{
			return;
		}

		$this->initialize();

		if (!$this->message)
		{
			$this->create();
		}
	}

	/**
	 * Sanitizes filename.
	 *
	 * @param  string $filename The filename
	 * @return string A safe filename
	 */

	public function sanitize($filename)
	{
		$filename = preg_replace('/[^A-Za-z0-9-._]/', '.', (string) $filename);
		return trim(preg_replace('/[_.-]{2,}/', '.', $filename), '. ');
	}

	/**
	 * Creates a new backup.
	 *
	 * @todo Implement table ignoring
	 */

	private function create()
	{
		global $txpcfg, $prefs;

		@set_time_limit(0);
		@ignore_user_abort(true);

		callback_event('rah_backup.create');

		$path = $this->backup_dir . '/' . $this->sanitize($txpcfg['db']) . $this->filestamp . '.sql';

		if (get_pref('rah_backup_compress'))
		{
			$path .= '.gz';
		}

		$this->created[basename($path)] = $path;

		$config = new Rah_Danpu_Dump();
		$config
			->file($path)
			->db($txpcfg['db'])
			->user($txpcfg['user'])
			->pass($txpcfg['pass'])
			->host($txpcfg['host'])
			->temp(get_pref('tempdir'));

		try
		{
			new Rah_Danpu_Export($config);
		}
		catch(Exception $e)
		{
			array_pop($this->created);
		}

		if ($this->copy_paths)
		{
			$path = $this->sanitize($prefs['siteurl']);

			if (!$path)
			{
				$path = 'filesystem';
			}

			$path = $this->backup_dir . '/' . $path . $this->filestamp . '.zip';

			$zip = new rah_backup_zip();
			$zip->ignored = $this->exclude_files;

			if ($zip->create($this->copy_paths, $path))
			{
				$this->created[basename($path)] = $path;
			}
		}

		callback_event('rah_backup.created');

		if (txpinterface == 'public')
		{
			exit;
		}

		$this->browser(gTxt('rah_backup_done'));
	}

	/**
	 * Restores backup.
	 *
	 * @todo Not ready
	 */

	private function restore()
	{
		$this->browser(array('Restoring is not implemented yet.', E_WARNING));
		return;

		global $txpcfg, $prefs;

		$file = (string) gps('file');
		$backups = $this->get_backups();

		if (!isset($backups[$file]) || $backups[$file]['type'] != self::BACKUP_DATABASE)
		{
			$this->browser(array(gTxt('rah_backup_can_not_restore'), E_ERROR));
			return;
		}

		extract($backups[$file]);

		@set_time_limit(0);
		@ignore_user_abort(true);

		if ($ext == 'zip')
		{
			$path .= '.tmp';
		}

		if (1 == 0)
		{
			$this->browser(array(gTxt('rah_backup_can_not_restore'), E_ERROR));
			return;
		}

		if ($ext === 'zip')
		{
			@unlink($path);
		}

		$this->browser(gTxt('rah_backup_restored'));
	}

	/**
	 * Streams backups for downloading.
	 */

	private function download()
	{
		$file = (string) gps('file');

		if (!($backups = $this->get_backups()) || !isset($backups[$file]))
		{
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

		if ($f = fopen($path, 'rb'))
		{
			while(!feof($f) && connection_status() == 0)
			{
				echo fread($f, 1024*64);
				ob_flush();
				flush();
			}
			fclose($f);
		}

		exit;
	}
	
	/**
	 * Multi-edit handler.
	 */

	private function multi_edit()
	{
		extract(psa(array(
			'selected',
			'edit_method',
		)));

		require_privs('rah_backup_'.((string) $edit_method));

		if (!is_string($edit_method) || empty($selected) || !is_array($selected))
		{
			$this->browser(array(gTxt('rah_backup_select_something'), E_WARNING));
			return;
		}

		$method = 'multi_option_' . $edit_method;

		if (!method_exists($this, $method))
		{
			$method = 'browse';
		}

		$this->$method();
	}

	/**
	 * Deletes selected backups.
	 */

	private function multi_option_delete()
	{
		$selected = ps('selected');

		foreach ($this->get_backups() as $name => $file)
		{
			if (in_array($name, $selected))
			{
				$this->deleted[$name] = $file['path'];
				@unlink($file['path']);
			}
		}

		callback_event('rah_backup.deleted');
		$this->browser(gTxt('rah_backup_removed'));
	}

	/**
	 * Gets a list of backups.
	 *
	 * @param  string $sort      Sorting criteria, either 'name', 'ext', 'date', 'size', 'type'
	 * @param  string $direction Sorting direction, either 'asc' or 'desc'
	 * @param  int    $offset    Offset
	 * @param  int    $limit     Limit results
	 * @return array
	 */

	public function get_backups($sort = 'name', $direction = 'asc', $offset = 0, $limit = NULL)
	{
		global $prefs;

		$order = $files = array();

		$sort_crit = array(
			'name' => SORT_REGULAR,
			'ext' => SORT_REGULAR,
			'date' => SORT_NUMERIC,
			'size' => SORT_NUMERIC,
			'type' => SORT_NUMERIC,
		);

		if (!is_string($sort) || !isset($sort_crit[$sort]))
		{
			$sort = 'name';
		}

		foreach (
			(array) glob(
				preg_replace('/(\*|\?|\[)/', '[$1]', $prefs['rah_backup_path']) . '/'.'*[.gz|.sql|.zip]',
				GLOB_NOSORT
			) as $file
		) {
			if (!$file || !is_readable($file) || !is_file($file))
			{
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

			if (preg_match('/\.sql[\.zip|\.gz]?$/i', $backup['name']))
			{
				$backup['type'] = self::BACKUP_DATABASE;
			}

			$files[$backup['name']] = $backup;
			$order[$backup['name']] = $backup[$sort];
		}

		if (!$files)
		{
			return array();
		}

		array_multisort($order, $sort_crit[$sort], $files);

		if ($direction === 'desc')
		{
			$files = array_reverse($files);
		}

		return array_slice($files, $offset, $limit);
	}

	/**
	 * Formats a path.
	 *
	 * @param  string $path The path
	 * @return string
	 */

	public function path($path)
	{
		if (strpos($path, './') === 0)
		{
			$path = txpath.'/'.substr($path, 2);
		}
		else if (strpos($path, '../') === 0)
		{
			$path = dirname(txpath).'/'.substr($path, 3);
		}

		return rtrim($path, "/\\");
	}

	/**
	 * The plugin's options panel.
	 *
	 * Redirect to the admin-side interface.
	 */

	public function prefs()
	{
		header('Location: ?event=rah_backup');
		echo graf(href(gTxt('continue'), array('event' => 'rah_backup')));
	}
}

new rah_backup();