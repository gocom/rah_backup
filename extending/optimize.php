<?php

/**
 * Optimizes all database tables when doing backups with rah_backup.
 * 
 * @package rah_backup
 * @author Jukka Svahn <http://rahforum.biz/>
 * @copyright (c) 2011 Jukka Svahn
 * @license GLPv2
 */

/**
 * Registers the function. Hook to event 'rah_backup_tasks', step 'backup_done'.
 */

	register_callback('rah_backup__module_optimize','rah_backup_tasks','backup_done');

/**
 * Optimizes database tables
 * @return bool
 */

	function rah_backup__module_optimize() {
		@$tables = getThings('SHOW TABLES');
		
		foreach((array) $tables as $table) {
			@safe_query('OPTIMIZE TABLE `'.$table.'`');
		}

		return true;
	}

?>