<?php

/*
 * rah_backup - Textpattern backup utility
 * https://github.com/gocom/rah_backup
 *
 * Copyright (C) 2014 Jukka Svahn
 *
 * This file is part of rah_backup.
 *
 * rah_backup is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation, version 2.
 *
 * rah_backup is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with rah_backup. If not, see <http://www.gnu.org/licenses/>.
 */

class Rah_Backup
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
        add_privs('rah_backup_download', '1,2');
        add_privs('rah_backup_multi_edit', '1,2');
        add_privs('rah_backup_delete', '1');
        add_privs('rah_backup_preferences', '1');
        add_privs('plugin_prefs.rah_backup', '1,2');
        add_privs('prefs.rah_backup', '1');
        register_tab('admin', 'rah_backup', gTxt('rah_backup'));
        register_callback(array($this, 'install'), 'plugin_lifecycle.rah_backup', 'installed');
        register_callback(array($this, 'uninstall'), 'plugin_lifecycle.rah_backup', 'deleted');
        register_callback(array($this, 'prefs'), 'plugin_prefs.rah_backup');
        register_callback(array($this, 'pane'), 'rah_backup');
        register_callback(array($this, 'head'), 'admin_side', 'head_end');
        register_callback(array($this, 'endpoint'), 'textpattern');
        register_callback(array($this, 'takeBackup'), 'rah_backup.backup');
    }

    /**
     * Installer.
     */

    public function install()
    {
        $position = 250;

        foreach (array(
            'path'          => array('text_input', '../../backups'),
            'copy_paths'    => array('text_input', '../'),
            'exclude_files' => array('text_input', ''),
            'ignore_tables' => array('text_input', ''),
            'overwrite'     => array('yesnoradio', 0),
            'key'           => array('text_input', md5(uniqid(mt_rand(), TRUE))),
        ) as $name => $val) {
            $n = 'rah_backup_'.$name;

            if (get_pref($n, false) === false) {
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

        $steps = array(
            'browser' => false,
            'create' => true,
            'download' => true,
            'multi_edit' => true,
        );

        if (!$step || !bouncer($step, $steps) || !has_privs('rah_backup_' . $step)) {
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

        if ($event != 'rah_backup') {
            return;
        }

        gTxtScript(array(
            'rah_backup_confirm_backup',
        ));

        $msg = array(
            'backup' => escape_js($theme->announce_async(gTxt('rah_backup_taking'))),
            'error' => escape_js($theme->announce_async(gTxt('rah_backup_task_error'))),
        );

        $js = <<<EOF
            $(function ()
            {
                $('.rah_backup_take').on('click', function (e)
                {
                    e.preventDefault();
                    var obj = $(this), href, spinner;

                    if (obj.hasClass('disabled') || !verify(textpattern.gTxt('rah_backup_confirm_backup'))) {
                        return false;
                    }

                    $.globalEval('{$msg['backup']}');

                    spinner = $('<span> <span class="spinner"></span> </span>');
                    href = obj.attr('href');
                    obj.addClass('disabled').attr('href', '#').after(spinner);

                    $.ajax('index.php', {
                        data: href.substr(1) + '&app_mode=async',
                        dataType: 'script',
                        timeout: 1800000
                    }).fail(function ()
                    {
                        $.globalEval('{$msg['error']}');
                    }).always(function ()
                    {
                        obj.removeClass('disabled').attr('href', href);
                        spinner.remove();
                    });
                });
            });
EOF;

        echo script_js($js);
    }

    /**
     * The main panel listing backups.
     *
     * @param string|array $message The activity message
     */

    protected function browser($message = '')
    {
        global $event, $app_mode, $theme;

        extract(gpsa(array(
            'sort',
            'dir',
        )));

        $methods = array();

        $path = get_pref('rah_backup_path');
        $writeable = $path && file_exists($path) && is_dir($path) && is_writable($path);

        if (has_privs('rah_backup_delete') && $writeable) {
            $methods['delete'] = gTxt('rah_backup_delete');
        }

        $columns = array('name', 'date', 'type', 'size');

        if ($dir !== 'desc' && $dir !== 'asc') {
            $dir = get_pref($event.'_sort_dir', 'asc');
        }

        if (!in_array((string) $sort, $columns)) {
            $sort = get_pref($event.'_sort_column', 'name');
        }

        if ($methods) {
            $column[] = hCell(
                fInput('checkbox', 'select_all', 0, '', '', '', '', '', 'select_all'),
                '',
                ' title="'.gTxt('toggle_all_selected').'" class="multi-edit"'
            );
        }

        foreach ($columns as $name) {
            $column[] = column_head(
                $event.'_'.$name,
                $name,
                $event,
                true,
                $name === $sort && $dir === 'asc' ? 'desc' : 'asc',
                '',
                '',
                $name === $sort ? $dir : '', 'browse'
            );
        }

        set_pref($event.'_sort_column', $sort, $event, 2, '', 0, PREF_PRIVATE);
        set_pref($event.'_sort_dir', $dir, $event, 2, '', 0, PREF_PRIVATE);

        try {
            $backups = $this->getBackups($sort, $dir);

            foreach ($backups as $backup) {
                $td = array();
                $name = txpspecialchars($backup['name']);

                if ($methods) {
                    $td[] = td(fInput('checkbox', 'selected[]', $name), '', 'multi-edit');
                }

                if (has_privs('rah_backup_download') && $backup['readable']) {
                    $td[] = td('<a title="'.gTxt('rah_backup_download').'" href="?event='.$event.'&amp;step=download&amp;file='.urlencode($name).'&amp;_txp_token='.form_token().'">'.$name.'</a>');
                } else {
                    $td[] = td($name);
                }

                $td[] = td(safe_strftime(gTxt('rah_backup_dateformat'), $backup['date']));
                $td[] = td(gTxt('rah_backup_type_'.$backup['type']));
                $td[] = td(format_filesize($backup['size']));
                $out[] = tr(implode(n, $td));
            }

            if (!$backups) {
                $out[] = tr(tda(gTxt('rah_backup_no_backups'), 'colspan="'.count($column).'"'));
            }

        } catch (Rah_Backup_Exception $e) {
            $out[] = tr(tda($e->getMessage(), 'colspan="'.count($column).'"'));
        }

        $out = implode('', $out);

        if ($app_mode === 'async') {
            send_script_response($theme->announce_async($message).n.'$("#rah_backup_list").html("'.escape_js($out).'");');
            return;
        }

        $pane[] =
            n.'<div class="txp-listtables">'.
            n.'<table class="txp-list">'.
            n.'<thead>'.
            tr(implode('', $column)).
            n.'</thead>'.
            n.'<tbody id="rah_backup_list">'.
            $out.
            n.'</tbody>'.
            n.'</table>'.
            n.'</div>';

        if ($methods) {
            $pane[] = multi_edit($methods, $event, 'multi_edit');
        }

        pagetop(gTxt('rah_backup'), $message);

        echo
            hed(gTxt('rah_backup'), 1, 'class="txp-heading"').

            n.'<div class="txp-container">'.
            n.'<p class="txp-buttons">';

        if (has_privs('rah_backup_create') && $writeable) {
            echo n.'<a class="rah_backup_take" href="?event='.$event.
                '&amp;step=create&amp;_txp_token='.form_token().'">'.
                gTxt('rah_backup_create').'</a>';
        }

        if (has_privs('prefs') && has_privs('rah_backup_preferences')) {
            echo n.'<a href="?event=prefs#prefs-rah_backup_path">'.gTxt('rah_backup_preferences').'</a>';
        }

        echo
            n.'</p>'.
            n.'<form action="index.php" method="post" class="multi_edit_form">'.
            tInput().
            n.implode('', $pane).
            n.'</form>'.
            n.'<div>';
    }

    /**
     * Public callback end-point for creating backups.
     *
     * Creates a new backup when ?rah_backup_key parameter
     * is passed in the request.
     */

    public function endpoint()
    {
        if (!gps('rah_backup_key') || get_pref('rah_backup_key') !== gps('rah_backup_key')) {
            return;
        }

        header('Content-Type: application/json; charset=utf-8');

        try {
            callback_event('rah_backup.backup');
        } catch (Exception $e) {
            txp_status_header('500 Internal Server Error');

            die(json_encode(array(
                'success' => false,
                'error'   => $e->getMessage(),
            )));
        }

        die(json_encode(array(
            'success' => true,
        )));
    }

    /**
     * Sanitizes filename.
     *
     * @param  string $filename The filename
     * @return string A safe filename
     */

    public function sanitize($filename)
    {
        $filename = preg_replace('/[^A-Za-z0-9\-\._]/', '.', (string) $filename);
        return trim(substr(preg_replace('/[_\.\-]{2,}/', '.', $filename), 0, 40), '._-');
    }

    /**
     * Takes a new set of backups.
     *
     * This method creates a new set of backups. It triggers
     * two callback events 'rah_backup.create' and 'rah_backup.created',
     * where the latter contains an data-map of create backup files.
     *
     * @throws Exception
     */

    public function takeBackup()
    {
        global $txpcfg;

        if (($directory = get_pref('rah_backup_path')) === '') {
            throw new Rah_Backup_Exception(
                gTxt('rah_backup_define_preferences', array(
                    '{start_by}' => href(gTxt('rah_backup_start_by'), '?event=prefs#prefs-rah_backup_path'),
                ), false)
            );
        }

        $directory = txpath . '/' . $directory;

        if (!file_exists($directory) || !is_dir($directory) || !is_writable($directory)) {
            throw new Rah_Backup_Exception(gTxt('rah_backup_dir_not_writable', array('{path}' => $dir)));
        }

        @set_time_limit(0);
        @ignore_user_abort(true);

        callback_event('rah_backup.create');

        if (!get_pref('rah_backup_overwrite')) {
            $filestamp = '_'.safe_strtotime('now');
        }

        $name = $this->sanitize($txpcfg['db']);

        if (!$name) {
            $name = 'database';
        }

        $path = $directory . '/' . $name . $filestamp . '.sql.gz';
        $created = array();
        $created[basename($path)] = $path;

        $dump = new \Rah\Danpu\Dump;
        $dump
            ->file($path)
            ->dsn('mysql:dbname='.$txpcfg['db'].';host='.$txpcfg['host'])
            ->user($txpcfg['user'])
            ->pass($txpcfg['pass'])
            ->tmp(get_pref('tempdir'));

        if (get_pref('rah_backup_ignore_tables')) {
            $ignore = array();

            foreach (do_list(get_pref('rah_backup_ignore_tables')) as $table) {
                $ignore[] = PFX.$table;
            }

            $dump->ignore($ignore);
        }

        if (PFX) {
            $dump->prefix(PFX);
        }

        new \Rah\Danpu\Export($dump);

        if (get_pref('rah_backup_copy_paths') && !is_disabled('exec') && is_callable('exec')) {

            // Copied paths.

            $copy = array();

            foreach (do_list(get_pref('rah_backup_copy_paths')) as $path) {
                if ($path) {
                    $path = txpath . '/' . $path;

                    if (file_exists($path) && is_readable($path) && $path = escapeshellarg($path)) {
                        $copy[$path] = ' '.$path;
                    }
                }
            }

            // Excluded paths.

            $exclude = array();

            foreach (do_list(get_pref('rah_backup_exclude_files')) as $path) {
                if ($path && $path = escapeshellarg(txpath . '/' . $path)) {
                    $exclude[$path] = ' --exclude='.$path;
                }
            }

            $name = $this->sanitize(get_pref('siteurl'));

            if (!$name) {
                $name = 'filesystem';
            }

            $path = $directory . '/'. $name . $filestamp . '.tar.gz';

            if (exec(
                'tar -cvpzf '.escapeshellarg($path).
                implode('', $exclude).
                implode('', $copy)
            ) !== false) {
                $created[basename($path)] = $path;
            }
        }

        callback_event('rah_backup.created', '', 0, array(
            'files' => $created,
        ));
    }

    /**
     * Creates a new backup.
     */

    protected function create()
    {
        try {
            callback_event('rah_backup.backup');
        } catch (Rah_Backup_Exception $e) {
            $this->browser(array($e->getMessage(), E_ERROR));
            return;
        } catch (Exception $e) {
            $this->browser(array(txpspecialchars($e->getMessage()), E_ERROR));
            return;
        }

        $this->browser(gTxt('rah_backup_done'));
    }

    /**
     * Streams backups for downloading.
     */

    protected function download()
    {
        $file = (string) gps('file');

        try {
            $backups = $this->getBackups();
        } catch (Exception $e) {
        }

        if (empty($backups) || !isset($backups[$file])) {
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

        if ($f = fopen($path, 'rb')) {
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
     * Multi-edit handler.
     */

    protected function multi_edit()
    {
        extract(psa(array(
            'selected',
            'edit_method',
        )));

        require_privs('rah_backup_'.((string) $edit_method));

        if (!is_string($edit_method) || empty($selected) || !is_array($selected)) {
            $this->browser(array(gTxt('rah_backup_select_something'), E_WARNING));
            return;
        }

        $method = 'multi_option_' . $edit_method;

        if (!method_exists($this, $method)) {
            $method = 'browse';
        }

        $this->$method();
    }

    /**
     * Deletes selected backups.
     */

    protected function multi_option_delete()
    {
        $selected = ps('selected');
        $deleted = array();

        try {
            foreach ($this->getBackups() as $name => $file) {
                if (in_array($name, $selected, true)) {
                    $deleted[$name] = $file['path'];
                    @unlink($file['path']);
                }
            }
        } catch (Exception $e) {
        }

        callback_event('rah_backup.deleted', '', 0, array(
            'files' => $deleted,
        ));

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

    public function getBackups($sort = 'name', $direction = 'asc', $offset = 0, $limit = null)
    {
        if (($directory = get_pref('rah_backup_path')) === '') {
            throw new Rah_Backup_Exception(
                gTxt('rah_backup_define_preferences', array(
                    '{start_by}' => href(gTxt('rah_backup_start_by'), '?event=prefs#prefs-rah_backup_path'),
                ), false)
            );
        }

        $directory = txpath . '/' . $directory;

        if (!file_exists($directory) || !is_dir($directory) || !is_readable($directory)) {
            throw new Rah_Backup_Exception(
                gTxt('rah_backup_dir_not_readable', array('{path}' => $directory))
            );
        }

        $order = $files = array();

        $sort_crit = array(
            'name' => SORT_REGULAR,
            'ext' => SORT_REGULAR,
            'date' => SORT_NUMERIC,
            'size' => SORT_NUMERIC,
            'type' => SORT_NUMERIC,
        );

        if (!is_string($sort) || !isset($sort_crit[$sort])) {
            $sort = 'name';
        }

        foreach (new DirectoryIterator($directory) as $file) {

            if (!$file->isFile() || !preg_match('/^[a-z0-9\-_\.]+\.(sql\.gz|tar\.gz)$/i', $file->getFilename())) {
                continue;
            }

            $backup = array(
                'path' => $file->getPathname(),
                'name' => $file->getFilename(),
                'ext' => $file->getExtension(),
                'date' => $file->getMTime(),
                'size' => $file->getSize(),
                'type' => self::BACKUP_FILESYSTEM,
                'readable' => $file->isReadable(),
                'writable' => $file->isWritable(),
            );

            if (preg_match('/\.sql\.gz$/i', $backup['name'])) {
                $backup['type'] = self::BACKUP_DATABASE;
            }

            $files[$backup['name']] = $backup;
            $order[$backup['name']] = $backup[$sort];
        }

        if (!$files) {
            return array();
        }

        array_multisort($order, $sort_crit[$sort], $files);

        if ($direction === 'desc') {
            $files = array_reverse($files);
        }

        return array_slice($files, $offset, $limit);
    }

    /**
     * The plugin's options panel.
     *
     * Redirect to the admin-side interface.
     */

    public function prefs()
    {
        header('Location: ?event=rah_backup');
        echo '<p><a href="?event=rah_backup">'.gTxt('continue').'</a></p>';
    }
}

new Rah_Backup();
