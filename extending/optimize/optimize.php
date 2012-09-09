<?php

/**
 * Optimizes all database tables when doing backups with rah_backup.
 * 
 * @package rah_backup
 * @author Jukka Svahn <http://rahforum.biz/>
 * @copyright (c) 2011 Jukka Svahn
 * @license GLPv2
 */

	register_callback('rah_backup__optimize', 'rah_backup.created');

/**
 * Optimizes database tables
 */

	function rah_backup__optimize() {
		@$tables = getThings('SHOW TABLES');
		
		foreach($tables as $table) {
			@safe_query('OPTIMIZE TABLE `'.$table.'`');
		}
	}

?>