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
 * Read a plugin config value without treating the string "0" as empty.
 *
 * @param string $name
 * @param mixed $default
 * @return mixed
 */
function local_mysqlbackuppro_get_config(string $name, $default = '') {
    $value = get_config('local_mysqlbackuppro', $name);
    return ($value === false || $value === null) ? $default : $value;
}

/**
 * PHP 7.4-compatible str_ends_with replacement for Moodle 4.1 installations.
 *
 * @param string $haystack
 * @param string $needle
 * @return bool
 */
function local_mysqlbackuppro_ends_with(string $haystack, string $needle): bool {
    if ($needle === '') {
        return true;
    }
    return substr($haystack, -strlen($needle)) === $needle;
}

/**
 * Check whether the backup history table exists.
 *
 * @return bool
 */
function local_mysqlbackuppro_history_table_exists(): bool {
    global $DB;
    try {
        $dbman = $DB->get_manager();
        return $dbman->table_exists(new xmldb_table('local_mbpro_backups'));
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Get all plugin settings.
 *
 * @return array
 */
function local_mysqlbackuppro_get_settings(): array {
    return [
        'enabled'       => (string) local_mysqlbackuppro_get_config('enabled', '1'),
        'frequency'     => (string) local_mysqlbackuppro_get_config('frequency', 'daily'),
        'backup_time'   => (string) local_mysqlbackuppro_get_config('backup_time', '02:00'),
        's3_endpoint'   => (string) local_mysqlbackuppro_get_config('s3_endpoint', ''),
        's3_region'     => (string) local_mysqlbackuppro_get_config('s3_region', 'default'),
        's3_bucket'     => (string) local_mysqlbackuppro_get_config('s3_bucket', ''),
        's3_access_key' => crypto::decrypt((string) local_mysqlbackuppro_get_config('s3_access_key', '')),
        's3_secret_key' => crypto::decrypt((string) local_mysqlbackuppro_get_config('s3_secret_key', '')),
        's3_path_style' => (string) local_mysqlbackuppro_get_config('s3_path_style', '1'),
        's3_base_path'  => (string) local_mysqlbackuppro_get_config('s3_base_path', 'mysql-backups'),
        'retention'     => (string) local_mysqlbackuppro_get_config('retention_count', '10'),
        'compress'      => (string) local_mysqlbackuppro_get_config('compress_backup', '1'),
        'notify_email'  => (string) local_mysqlbackuppro_get_config('notify_email', ''),
    ];
}

/**
 * Get backup statistics.
 *
 * @return array
 */
function local_mysqlbackuppro_get_stats(): array {
    global $DB, $CFG;

    $total = 0;
    $ok = 0;
    $fail = 0;
    $size = 0;
    $last = null;

    if (local_mysqlbackuppro_history_table_exists()) {
        try {
            $total = (int) $DB->count_records('local_mbpro_backups');
            $ok    = (int) $DB->count_records('local_mbpro_backups', ['status' => 'completed']);
            $fail  = (int) $DB->count_records('local_mbpro_backups', ['status' => 'failed']);
            $size  = (int) $DB->get_field_sql("SELECT COALESCE(SUM(file_size), 0) FROM {local_mbpro_backups} WHERE status = ?", ['completed']);
            $last  = $DB->get_record_sql("SELECT * FROM {local_mbpro_backups} WHERE status = ? ORDER BY completed_at DESC", ['completed'], IGNORE_MULTIPLE);
        } catch (Throwable $e) {
            local_mysqlbackuppro_safe_log('error', 'Stats query failed: ' . $e->getMessage());
        }
    }

    $dbname = $CFG->dbname ?? '';
    $dbsize = 0;
    $tablecount = 0;

    try {
        $dbinfo = $DB->get_record_sql(
            "SELECT SUM(data_length + index_length) AS dbsize, COUNT(*) AS tblcount
               FROM information_schema.tables
              WHERE table_schema = ? AND table_type = 'BASE TABLE'",
            [$dbname]
        );
        $dbsize = (int) ($dbinfo->dbsize ?? 0);
        $tablecount = (int) ($dbinfo->tblcount ?? 0);
    } catch (Throwable $e) {
        try {
            if (method_exists($DB, 'get_tables')) {
                $tables = $DB->get_tables(false);
                $tablecount = is_array($tables) ? count($tables) : 0;
            }
        } catch (Throwable $ignored) {
            $tablecount = 0;
        }
    }

    return [
        'total_backups' => $total,
        'successful'    => $ok,
        'failed'        => $fail,
        'total_size'    => backup::fmt_bytes((int) $size),
        'last_backup'   => $last ?: null,
        'db_name'       => $dbname,
        'db_size'       => backup::fmt_bytes((int) $dbsize),
        'table_count'   => $tablecount,
        'next_backup'   => local_mysqlbackuppro_next_backup_human(),
    ];
}

/**
 * Log without breaking page rendering if the logger class is unavailable during upgrade/install.
 *
 * @param string $level
 * @param string $message
 * @return void
 */
function local_mysqlbackuppro_safe_log(string $level, string $message): void {
    try {
        if (class_exists('local_mysqlbackuppro\\logger')) {
            switch ($level) {
                case 'error':
                    \local_mysqlbackuppro\logger::error($message);
                    break;
                case 'warning':
                    \local_mysqlbackuppro\logger::warning($message);
                    break;
                default:
                    \local_mysqlbackuppro\logger::info($message);
            }
        }
    } catch (Throwable $ignored) {
        // Never let logging break the UI.
    }
}

/**
 * Get human-readable next scheduled backup time.
 *
 * @return string
 */
function local_mysqlbackuppro_next_backup_human(): string {
    try {
        $tasks = \core\task\manager::load_scheduled_tasks_for_component('local_mysqlbackuppro');
        foreach ($tasks as $task) {
            if ($task instanceof \local_mysqlbackuppro\task\scheduled_backup) {
                $next = $task->get_next_run_time();
                if ($next) {
                    return userdate($next, '%Y-%m-%d %H:%M:%S');
                }
            }
        }
    } catch (Throwable $e) {
        local_mysqlbackuppro_safe_log('warning', 'Could not read scheduled task: ' . $e->getMessage());
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

    $taskrecord = $DB->get_record('task_scheduled', [
        'component' => 'local_mysqlbackuppro',
        'classname' => '\\local_mysqlbackuppro\\task\\scheduled_backup'
    ]);
    if (!$taskrecord) {
        local_mysqlbackuppro_safe_log('warning', 'Scheduled task record not found while saving settings.');
        return;
    }

    $enabled = (string) local_mysqlbackuppro_get_config('enabled', '1');
    if ($enabled !== '1') {
        $taskrecord->disabled = 1;
        $DB->update_record('task_scheduled', $taskrecord);
        return;
    }

    $taskrecord->disabled = 0;
    $taskrecord->minute = '0';
    $taskrecord->hour = '2';
    $taskrecord->day = '*';
    $taskrecord->month = '*';
    $taskrecord->dayofweek = '*';

    $time = (string) local_mysqlbackuppro_get_config('backup_time', '02:00');
    [$h, $m] = array_pad(explode(':', $time), 2, 0);
    $h = max(0, min(23, (int) $h));
    $m = max(0, min(59, (int) $m));

    $freq = (string) local_mysqlbackuppro_get_config('frequency', 'daily');
    switch ($freq) {
        case 'hourly':
            $taskrecord->minute = '0';
            $taskrecord->hour = '*';
            break;
        case 'twicedaily':
            $taskrecord->hour = '2,14';
            $taskrecord->minute = '0';
            break;
        case 'weekly':
            $taskrecord->dayofweek = '0';
            $taskrecord->hour = (string) $h;
            $taskrecord->minute = (string) $m;
            break;
        case 'monthly':
            $taskrecord->day = '1';
            $taskrecord->hour = (string) $h;
            $taskrecord->minute = (string) $m;
            break;
        case 'daily':
        default:
            $taskrecord->hour = (string) $h;
            $taskrecord->minute = (string) $m;
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
    $mime = local_mysqlbackuppro_ends_with($record->file_path, '.gz') ? 'application/gzip' : 'application/sql';

    header('Content-Description: File Transfer');
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($record->file_path));
    header('Cache-Control: no-cache');
    readfile($record->file_path);
    die;
}
