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
 * Library functions for MySQL Backup Pro
 *
 * @package    local_mysqlbackuppro
 * @copyright  2024 Louis Jhosimar Ocampo | GestLife Dev
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use local_mysqlbackuppro\backup;
use local_mysqlbackuppro\s3native;
use local_mysqlbackuppro\crypto;

/**
 * Get all plugin settings.
 *
 * @return array
 */
function local_mysqlbackuppro_get_settings(): array {
    return [
        'enabled'       => get_config('local_mysqlbackuppro', 'enabled') ?: '1',
        'frequency'     => get_config('local_mysqlbackuppro', 'frequency') ?: 'daily',
        'backup_time'   => get_config('local_mysqlbackuppro', 'backup_time') ?: '02:00',
        's3_endpoint'   => get_config('local_mysqlbackuppro', 's3_endpoint') ?: '',
        's3_region'     => get_config('local_mysqlbackuppro', 's3_region') ?: 'default',
        's3_bucket'     => get_config('local_mysqlbackuppro', 's3_bucket') ?: '',
        's3_access_key' => crypto::decrypt(get_config('local_mysqlbackuppro', 's3_access_key') ?: ''),
        's3_secret_key' => crypto::decrypt(get_config('local_mysqlbackuppro', 's3_secret_key') ?: ''),
        's3_path_style' => get_config('local_mysqlbackuppro', 's3_path_style') ?: '1',
        's3_base_path'  => get_config('local_mysqlbackuppro', 's3_base_path') ?: 'mysql-backups',
        'retention'     => get_config('local_mysqlbackuppro', 'retention_count') ?: '10',
        'compress'      => get_config('local_mysqlbackuppro', 'compress_backup') ?: '1',
        'notify_email'  => get_config('local_mysqlbackuppro', 'notify_email') ?: '',
    ];
}

/**
 * Get backup statistics.
 *
 * @return array
 */
function local_mysqlbackuppro_get_stats(): array {
    global $DB, $CFG;

    $total  = (int) $DB->count_records('local_mbpro_backups');
    $ok     = (int) $DB->count_records('local_mbpro_backups', ['status' => 'completed']);
    $fail   = (int) $DB->count_records('local_mbpro_backups', ['status' => 'failed']);
    $size   = (int) $DB->get_field_sql("SELECT COALESCE(SUM(file_size), 0) FROM {local_mbpro_backups} WHERE status = 'completed'");
    $last   = $DB->get_record_sql("SELECT * FROM {local_mbpro_backups} WHERE status = 'completed' ORDER BY completed_at DESC LIMIT 1");

    $dbname = $CFG->dbname;
    $dbinfo = $DB->get_record_sql("SELECT SUM(data_length + index_length) AS dbsize, COUNT(*) AS tblcount
                                    FROM information_schema.tables
                                    WHERE table_schema = ? AND table_type = 'BASE TABLE'", [$dbname]);

    return [
        'total_backups' => $total,
        'successful'    => $ok,
        'failed'        => $fail,
        'total_size'    => backup::fmt_bytes((int)$size),
        'last_backup'   => $last ?: null,
        'db_name'       => $dbname,
        'db_size'       => backup::fmt_bytes((int) ($dbinfo->dbsize ?? 0)),
        'table_count'   => (int) ($dbinfo->tblcount ?? 0),
        'next_backup'   => local_mysqlbackuppro_next_backup_human(),
    ];
}

/**
 * Get human-readable next scheduled backup time.
 *
 * @return string
 */
function local_mysqlbackuppro_next_backup_human(): string {
    $tasks = \core\task\manager::load_scheduled_tasks_for_component('local_mysqlbackuppro');
    foreach ($tasks as $task) {
        if ($task instanceof \local_mysqlbackuppro\task\scheduled_backup) {
            $next = $task->get_next_run_time();
            if ($next) {
                return userdate($next, '%Y-%m-%d %H:%M:%S');
            }
        }
    }
    return get_string('not_scheduled', 'local_mysqlbackuppro');
}

/**
 * Reschedule backup task based on current settings.
 *
 * @return void
 */
function local_mysqlbackuppro_reschedule(): void {
    global $DB;

    $taskrecord = $DB->get_record('task_scheduled', ['component' => 'local_mysqlbackuppro', 'classname' => '\\local_mysqlbackuppro\\task\\scheduled_backup']);
    if (!$taskrecord) {
        return;
    }

    $enabled = get_config('local_mysqlbackuppro', 'enabled');
    if ($enabled !== '1') {
        $taskrecord->disabled = 1;
        $DB->update_record('task_scheduled', $taskrecord);
        return;
    }

    $taskrecord->disabled = 0;

    $time = get_config('local_mysqlbackuppro', 'backup_time') ?: '02:00';
    [$h, $m] = array_pad(explode(':', $time), 2, 0);
    $taskrecord->hour = (int) $h;
    $taskrecord->minute = (int) $m;

    $freq = get_config('local_mysqlbackuppro', 'frequency') ?: 'daily';
    switch ($freq) {
        case 'hourly':
            $taskrecord->minute = '*';
            $taskrecord->hour = '*';
            break;
        case 'twicedaily':
            $taskrecord->hour = '2,14';
            $taskrecord->minute = '0';
            break;
        case 'daily':
            $taskrecord->hour = (int) $h;
            $taskrecord->minute = (int) $m;
            break;
        case 'weekly':
            $taskrecord->dayofweek = '0';
            $taskrecord->hour = (int) $h;
            $taskrecord->minute = (int) $m;
            break;
        case 'monthly':
            $taskrecord->day = '1';
            $taskrecord->hour = (int) $h;
            $taskrecord->minute = (int) $m;
            break;
    }

    $DB->update_record('task_scheduled', $taskrecord);
}

/**
 * Serve plugin file (for downloads).
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool
 */
function local_mysqlbackuppro_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []): bool {
    if ($context->contextlevel != CONTEXT_SYSTEM) {
        return false;
    }
    require_capability('local/mysqlbackuppro:manage', $context);

    if ($filearea !== 'backup') {
        return false;
    }

    $backupid = (int) array_shift($args);
    global $DB;
    $record = $DB->get_record('local_mbpro_backups', ['id' => $backupid]);
    if (!$record || empty($record->file_path) || !file_exists($record->file_path)) {
        return false;
    }

    $filename = basename($record->file_path);
    $mime = (str_ends_with($record->file_path, '.gz')) ? 'application/gzip' : 'application/sql';

    header('Content-Description: File Transfer');
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($record->file_path));
    header('Cache-Control: no-cache');
    readfile($record->file_path);
    die;
}
