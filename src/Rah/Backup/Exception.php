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

/**
 * Our XSS-safe exception.
 *
 * Tells rah_backup we can print this message on the interface
 * without sanitization.
 */

class Rah_Backup_Exception extends Exception
{
}
