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

namespace local_mysqlbackuppro\task;

defined('MOODLE_INTERNAL') || die();

use local_mysqlbackuppro\backup;
use local_mysqlbackuppro\logger;

/**
 * Scheduled backup task for MySQL Backup Pro
 *
 * @package    local_mysqlbackuppro
 * @copyright  2024 Louis Jhosimar Ocampo | GestLife Dev
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scheduled_backup extends \core\task\scheduled_task {

    /**
     * Get task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('scheduledtask_name', 'local_mysqlbackuppro');
    }

    /**
     * Execute the scheduled backup.
     *
     * @return void
     */
    public function execute(): void {
        $enabled = get_config('local_mysqlbackuppro', 'enabled');
        if ($enabled !== '1') {
            logger::info('Scheduled backup skipped: plugin is disabled.');
            return;
        }

        // Check lock.
        $lockfile = $this->get_lock_path();
        if (file_exists($lockfile) && (time() - filemtime($lockfile)) < 3600) {
            logger::warning('Scheduled backup skipped: another instance is running (lock file exists).');
            return;
        }

        touch($lockfile);

        try {
            $b = new backup();
            $result = $b->run('scheduled');
            if ($result['success']) {
                logger::info('Scheduled backup successful: ' . $result['filename']);
                mtrace('MySQL Backup Pro: Scheduled backup completed - ' . $result['filename']);
            } else {
                logger::error('Scheduled backup failed: ' . ($result['message'] ?? 'Unknown error'));
                mtrace('MySQL Backup Pro: Scheduled backup FAILED - ' . ($result['message'] ?? 'Unknown'));
            }
        } catch (\Throwable $e) {
            logger::error('Scheduled backup exception: ' . $e->getMessage());
            mtrace('MySQL Backup Pro: Exception - ' . $e->getMessage());
        } finally {
            if (file_exists($lockfile)) {
                @unlink($lockfile);
            }
        }
    }

    /**
     * Get lock file path.
     *
     * @return string
     */
    private function get_lock_path(): string {
        global $CFG;
        $lockdir = $CFG->dataroot . '/mysql-backup-locks';
        if (!file_exists($lockdir)) {
            make_writable_directory($lockdir);
        }
        return $lockdir . '/scheduled.lock';
    }
}
