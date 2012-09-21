<?php

/**
 * Rah_backup plugin for Textpattern CMS.
 *
 * @author Jukka Svahn
 * @date 2012-
 * @license GNU GPLv2
 * @link https://github.com/gocom/rah_backup
 *
 * Copyright (C) 2012 Jukka Svahn <http://rahforum.biz>
 * Licensed under GNU Genral Public License version 2
 * http://www.gnu.org/licenses/gpl-2.0.html
 */

	rah_backup::get();

class rah_backup {
	
	static public $version = '0.1';
	
	/**
	 * @const int Filesystem backup type
	 */

	const BACKUP_FILESYSTEM = 1;
	
	/**
	 * @const int Database backup type
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
	 * @var array List of backed up files
	 */
	
	private $copy_paths = array();
	
	/**
	 * @var array List of excluded files
	 */
	
	private $exclude_files = array();
	
	/**
	 * @var array List of ignored tables
	 */
	
	private $ignore_tables = array();
	
	/**
	 * @var string Timestamp append to backup archives
	 */
	
	private $filestamp = '';
	
	/**
	 * @var array Paths to created backup files
	 */
	
	public $created = array();
	
	/**
	 * @var array Paths to deleted backup files
	 */
	
	public $deleted = array();

	/**
	 * @var array List of invoked messages
	 */
	
	public $message = array();
	
	/**
	 * @var array List of invoked announcements
	 */
	
	private $announce = array();
	
	/**
	 * @var array List of invoked errors/notices
	 */
	
	public $warning = array();

	/**
	 * Constructor
	 */

	public function __construct() {
		add_privs('rah_backup', '1,2');
		add_privs('rah_backup_create', '1,2');
		add_privs('rah_backup_restore', '1');
		add_privs('rah_backup_download', '1,2');
		add_privs('rah_backup_multi_edit', '1,2');
		add_privs('rah_backup_delete', '1');
		add_privs('rah_backup_preferences', '1');
		add_privs('plugin_prefs.rah_backup', '1,2');
		register_tab('extensions', 'rah_backup', gTxt('rah_backup'));
		register_callback(array(__CLASS__, 'install'), 'plugin_lifecycle.rah_backup');
		register_callback(array($this, 'prefs'), 'plugin_prefs.rah_backup');
		register_callback(array($this, 'pane'), 'rah_backup');
		register_callback(array($this, 'head'), 'admin_side', 'head_end');
		register_callback(array($this, 'call_backup'), 'textpattern');
	}
	
	/**
	 * Initializes
	 */
	
	public function initialize() {

		global $prefs, $txpcfg;
		
		if(!$prefs['rah_backup_path']) {
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
				$this->ignore_tables[PFX.$tbl] = PFX.$tbl;
			}
			
			else {
				$this->warning[] = gTxt('rah_backup_invalid_ignored_table', array('{name}' => $table));
			}
		}
		
		foreach(do_list($prefs['rah_backup_copy_paths']) as $f) {
		
			if(!$f) {
				continue;
			}
			
			$f = $this->path($f);
		
			if(file_exists($f) && is_readable($f)) {
				$this->copy_paths[$f] = $f;
			}
			
			else {
				$this->warning[] = gTxt('rah_backup_invalid_ignored_file', array('{name}' => $f));
			}
		}
		
		foreach(do_list($prefs['rah_backup_exclude_files']) as $f) {
			if($f) {
				$this->exclude_files[$f] = $f;
			}
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
		
		if((string) get_pref(__CLASS__.'_version') === self::$version) {
			return;
		}
		
		$position = 250;
		
		foreach(
			array(
				'path' => array('text_input', ''),
				'copy_paths' => array('text_input', './../'),
				'exclude_files' => array('text_input', ''),
				'ignore_tables' => array('text_input', ''),
				'compress' => array('yesnoradio', 0),
				'overwrite' => array('yesnoradio', 0),
				'callback' => array('yesnoradio', 0),
				'key' => array('text_input', md5(uniqid(mt_rand(), TRUE))),
			) as $name => $val
		) {
			$n = __CLASS__.'_'.$name;
			
			if(!isset($prefs[$n])) {
				set_pref($n, $val[1], __CLASS__, PREF_ADVANCED, $val[0], $position);
				$prefs[$n] = $val[1];
			}
			
			$position++;
		}
		
		set_pref(__CLASS__.'_version', self::$version, __CLASS__, 2, '', 0);
		$prefs[__CLASS__.'_version'] = self::$version;
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

	public function pane() {
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
		
		$this->initialize();
		
		if($this->message || $this->warning || !$step || !bouncer($step, $steps) || !has_privs('rah_backup_' . $step)) {
			$step = 'browser';
		}

		$this->$step();
	}

	/**
	 * Adds the panel's CSS to the head segment.
	 */

	public function head() {
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
			'error' => escape_js($theme->announce_async(gTxt('rah_backup_task_error'))),
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
								
						if(obj.hasClass('disabled') || !verify(message)) {
							return false;
						}
						
						if(obj.hasClass('rah_backup_take')) {
							$.globalEval('{$msg['backup']}');
							obj.parent().append(' <span class="spinner"></span>');
						}
						else {
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
				//-->
			</script>
EOF;
	}
	
	/**
	 * Announce message
	 * @param string|array $message
	 * @param string $type message|inform|warning
	 * @return obj
	 */
	
	public function announce($message, $type=-1) {
		$this->announce[$type][] = $message;
		return $this;
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
			
			foreach($backups as $backup) {
				
				$td = array();
				$name = txpspecialchars($backup['name']);
				
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
				$td[] = td(format_filesize($backup['size']));
				
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
		
		if(!empty($this->announce[-1])) {
			$message = $this->announce[-1][0];
		}
		
		if($app_mode == 'async') {
			send_script_response($theme->announce_async($message).n.'$("#rah_backup_list").html("'.escape_js($out).'");');
			return;
		}
		
		if($this->warning) {
			$pane[] = '<p class="alert-block warning">'.$this->warning[0].'</p>';
		}
		
		foreach(array('success', 'warning', 'error', 'information', 'highlight') as $type) {
			if(!empty($this->announce[$type])) {
				$pane[] = '<p class="alert-block '.$type.'">'.implode('</p><p class="alert-block '.$type.'">', $this->announce[$type]).'</p>';
			}
		}
		
		$pane[] = 
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
			$pane[] = multi_edit($methods, $event, 'multi_edit');
		}
		
		$this->build_pane($pane, $message);
	}
	
	/**
	 * Backup callback
	 */
	
	public function call_backup() {
		
		global $prefs;
		
		if(
			!gps('rah_backup') || 
			empty($prefs['rah_backup_callback']) || 
			empty($prefs['rah_backup_key']) || 
			$prefs['rah_backup_key'] !== gps('rah_backup')
		)
			return;
		
		$this->initialize();
		
		if(!$this->message) {
			$this->create();
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
		
		$dump = new rah_backup_mysqldump();
		$dump->filename = $path;
		$dump->run();
		
		if($prefs['rah_backup_compress'] && file_exists($path)) {
			
			$zip = new rah_backup_zip();
			
			if($zip->create($path, $path.'.zip')) {
				unlink($path);
				$path .= '.zip';
			}
		}

		if(file_exists($path)) {
			$this->created[basename($path)] = $path;
		}
		
		if($this->copy_paths) {
			
			$path = $this->sanitize($prefs['siteurl']);
			
			if(!$path) {
				$path = 'filesystem';
			}
			
			$path = $this->backup_dir . '/' . $path . $this->filestamp . '.zip';
			
			$zip = new rah_backup_zip();
			$zip->ignored = $this->exclude_files;
			
			if($zip->create($this->copy_paths, $path)) {
				$this->created[basename($path)] = $path;
			}
		}
		
		callback_event('rah_backup.created');

		if(txpinterface == 'public') {
			exit;
		}

		$this->browser(gTxt('rah_backup_done'));
	}

	/**
	 * Restores backup
	 */

	private function restore() {
		
		$this->browser(array('Restoring is not implemented yet.', E_WARNING));
		return;
	
		global $txpcfg, $prefs;
		
		$file = (string) gps('file');
		$backups = $this->get_backups();
		
		if(!isset($backups[$file]) || $backups[$file]['type'] != self::BACKUP_DATABASE) {
			$this->browser(array(gTxt('rah_backup_can_not_restore'), E_ERROR));
			return;
		}
		
		extract($backups[$file]);
		
		@set_time_limit(0);
		@ignore_user_abort(true);
		
		if($ext == 'zip') {
			$path .= '.tmp';
		}
		
		if(1 == 0) {
			$this->browser(array(gTxt('rah_backup_can_not_restore'), E_ERROR));
			return;
		}
		
		if($ext === 'zip') {
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
				$this->deleted[$name] = $file['path'];
				@unlink($file['path']);
			}
		}
		
		callback_event('rah_backup.deleted');
		$this->browser(gTxt('rah_backup_removed'));
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
				preg_replace('/(\*|\?|\[)/', '[$1]', $prefs['rah_backup_path']) . '/'.'*[.sql|.zip]',
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
			
			if($backup['ext'] === 'sql' || ($backup['ext'] === 'zip' && substr($backup['name'], -8, 4) === '.sql')) {
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
			'<h1 class="txp-heading">'.gTxt('rah_backup').'</h1>'.n.
			'<form action="index.php" method="post" class="txp-container multi_edit_form">'.n.
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
		
		else if(strpos($path, '../') === 0) {
			$path = dirname(txpath).'/'.substr($path, 3);
		}
		
		return rtrim($path, "/\\");
	}

	/**
	 * Redirect to the admin-side interface
	 */

	public function prefs() {
		header('Location: ?event=rah_backup');
		echo 
			'<p>'.n.
			'	<a href="?event=rah_backup">'.gTxt('continue').'</a>'.n.
			'</p>';
	}
}
?>