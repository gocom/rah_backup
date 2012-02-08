<?php

/**
 * Empties visitor logs before taking a backup with rah_backup.
 *
 * @package rah_backup
 * @author Jukka Svahn <http://rahforum.biz>
 * @copyright (c) 2011 Jukka Svahn
 * @license GLPv2
 * 
 * Note that the module will empty the actual table, not just records from backup files.
 * Visitor logs will be permanently lost.
 */

/**
 * Registers the function. Hook to event 'rah_backup_tasks', step 'backuping'.
 */

	register_callback('rah_backup__module_clearlogs','rah_backup_tasks','backuping');

/**
 * Empties txp_log table.
 * @return bool
 */

	function rah_backup__module_clearlogs() {
		return safe_query('TRUNCATE TABLE '.safe_pfx('txp_log'));
	}
?>