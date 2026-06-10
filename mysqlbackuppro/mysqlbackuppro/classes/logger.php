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

namespace local_mysqlbackuppro;

defined('MOODLE_INTERNAL') || die();

/**
 * Logger class for MySQL Backup Pro
 *
 * @package    local_mysqlbackuppro
 * @copyright  2024 Louis Jhosimar Ocampo | GestLife Dev
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class logger {

    /** @var string Log filename */
    const LOG_FILE = 'mysql-backup.log';

    /**
     * Get the full path to the log file.
     *
     * @return string
     */
    private static function get_log_path(): string {
        global $CFG;

        $logdir = $CFG->dataroot . '/mysql-backup-logs';

        if (!file_exists($logdir)) {
            make_writable_directory($logdir);
        }

        return rtrim($logdir, '/') . '/' . self::LOG_FILE;
    }

    /**
     * Generic log method.
     *
     * @param string $level
     * @param string $message
     * @return void
     */
    public static function log(string $level, string $message): void {
        $allowed = ['INFO', 'WARNING', 'ERROR'];
        $level = strtoupper($level);
        if (!in_array($level, $allowed, true)) {
            $level = 'INFO';
        }

        $date = userdate(time(), '%Y-%m-%d %H:%M:%S');
        $formatted = sprintf("[%s] [%s] %s%s", $date, $level, $message, PHP_EOL);

        $logpath = self::get_log_path();
        error_log($formatted, 3, $logpath);
    }

    /**
     * Log INFO level.
     *
     * @param string $message
     * @return void
     */
    public static function info(string $message): void {
        self::log('INFO', $message);
    }

    /**
     * Log WARNING level.
     *
     * @param string $message
     * @return void
     */
    public static function warning(string $message): void {
        self::log('WARNING', $message);
    }

    /**
     * Log ERROR level.
     *
     * @param string $message
     * @return void
     */
    public static function error(string $message): void {
        self::log('ERROR', $message);
    }

    /**
     * Get all log contents.
     *
     * @return string
     */
    public static function get_logs(): string {
        $path = self::get_log_path();
        if (!file_exists($path)) {
            return '';
        }
        return file_get_contents($path);
    }

    /**
     * Get logs as array of lines.
     *
     * @return array
     */
    public static function get_logs_array(): array {
        $logs = self::get_logs();
        if (empty($logs)) {
            return [];
        }
        return array_filter(explode(PHP_EOL, $logs));
    }

    /**
     * Clear all logs.
     *
     * @return void
     */
    public static function clear_logs(): void {
        $path = self::get_log_path();
        if (file_exists($path)) {
            file_put_contents($path, '');
        }
    }

    /**
     * Check if log file exists.
     *
     * @return bool
     */
    public static function exists(): bool {
        return file_exists(self::get_log_path());
    }

    /**
     * Get log file size in bytes.
     *
     * @return int
     */
    public static function size(): int {
        $path = self::get_log_path();
        if (!file_exists($path)) {
            return 0;
        }
        return filesize($path);
    }
}
