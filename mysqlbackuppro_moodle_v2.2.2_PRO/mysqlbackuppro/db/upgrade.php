<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Upgrade script for MySQL Backup Pro
 *
 * @package    local_mysqlbackuppro
 * @copyright  2024 Louis Jhosimar Ocampo | GestLife Dev
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Ensure the backup history table exists for installations upgraded from early builds.
 *
 * @param database_manager $dbman
 * @return void
 */
function xmldb_local_mysqlbackuppro_ensure_history_table(database_manager $dbman): void {
    $table = new xmldb_table('local_mbpro_backups');
    if ($dbman->table_exists($table)) {
        $fields = [
            new xmldb_field('file_path', XMLDB_TYPE_CHAR, '500', null, null, null, null, 'file_name'),
            new xmldb_field('s3_key', XMLDB_TYPE_CHAR, '500', null, null, null, null, 'file_path'),
            new xmldb_field('s3_bucket', XMLDB_TYPE_CHAR, '255', null, null, null, null, 's3_key'),
            new xmldb_field('s3_endpoint', XMLDB_TYPE_CHAR, '255', null, null, null, null, 's3_bucket'),
            new xmldb_field('file_size', XMLDB_TYPE_INTEGER, '20', null, null, null, '0', 's3_endpoint'),
            new xmldb_field('tables_list', XMLDB_TYPE_TEXT, null, null, null, null, null, 'file_size'),
            new xmldb_field('rows_count', XMLDB_TYPE_INTEGER, '20', null, null, null, '0', 'tables_list'),
            new xmldb_field('status', XMLDB_TYPE_CHAR, '50', null, null, null, 'pending', 'rows_count'),
            new xmldb_field('backup_type', XMLDB_TYPE_CHAR, '50', null, null, null, 'manual', 'status'),
            new xmldb_field('error_msg', XMLDB_TYPE_TEXT, null, null, null, null, null, 'backup_type'),
            new xmldb_field('created_at', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'error_msg'),
            new xmldb_field('completed_at', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'created_at'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        return;
    }

    $table->add_field('id', XMLDB_TYPE_INTEGER, '20', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
    $table->add_field('file_name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
    $table->add_field('file_path', XMLDB_TYPE_CHAR, '500', null, null, null, null);
    $table->add_field('s3_key', XMLDB_TYPE_CHAR, '500', null, null, null, null);
    $table->add_field('s3_bucket', XMLDB_TYPE_CHAR, '255', null, null, null, null);
    $table->add_field('s3_endpoint', XMLDB_TYPE_CHAR, '255', null, null, null, null);
    $table->add_field('file_size', XMLDB_TYPE_INTEGER, '20', null, null, null, '0');
    $table->add_field('tables_list', XMLDB_TYPE_TEXT, null, null, null, null, null);
    $table->add_field('rows_count', XMLDB_TYPE_INTEGER, '20', null, null, null, '0');
    $table->add_field('status', XMLDB_TYPE_CHAR, '50', null, null, null, 'pending');
    $table->add_field('backup_type', XMLDB_TYPE_CHAR, '50', null, null, null, 'manual');
    $table->add_field('error_msg', XMLDB_TYPE_TEXT, null, null, null, null, null);
    $table->add_field('created_at', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $table->add_field('completed_at', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
    $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $table->add_index('status_idx', XMLDB_INDEX_NOTUNIQUE, ['status']);
    $table->add_index('created_idx', XMLDB_INDEX_NOTUNIQUE, ['created_at']);
    $table->add_index('type_idx', XMLDB_INDEX_NOTUNIQUE, ['backup_type']);
    $dbman->create_table($table);
}

/**
 * Upgrade function.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_mysqlbackuppro_upgrade($oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2024060900) {
        upgrade_plugin_savepoint(true, 2024060900, 'local', 'mysqlbackuppro');
    }

    if ($oldversion < 2026060901) {
        xmldb_local_mysqlbackuppro_ensure_history_table($dbman);
        upgrade_plugin_savepoint(true, 2026060901, 'local', 'mysqlbackuppro');
    }

    return true;
}
