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
 * Core backup class: SQL dump, compression, S3 upload, notifications.
 *
 * @package    local_mysqlbackuppro
 * @copyright  2024 Louis Jhosimar Ocampo | GestLife Dev
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup {

    /** @var string Backup storage directory */
    private string $dir;

    /** @var \moodle_database */
    private \moodle_database $db;

    /** @var string Database name */
    private string $dbname;

    /** @var string Plugin version */
    private string $version;

    /**
     * Constructor.
     */
    public function __construct() {
        global $CFG, $DB;
        $this->dir = rtrim($CFG->dataroot, '/') . '/mysql-backup-pro/';
        $this->db = $DB;
        $this->dbname = $CFG->dbname;
        $this->version = '2.2.1';
    }

    /* ============ HELPERS ============ */

    /**
     * Get sanitized site domain.
     *
     * @return string
     */
    public static function get_site_domain(): string {
        global $CFG;
        $domain = parse_url($CFG->wwwroot, PHP_URL_HOST);
        if (empty($domain)) {
            $domain = $_SERVER['HTTP_HOST'] ?? 'unknown';
        }
        $domain = preg_replace('/^www\./', '', $domain);
        $domain = preg_replace('/[^a-zA-Z0-9.-]/', '-', $domain);
        return strtolower($domain);
    }

    /**
     * Generate backup filename.
     *
     * @return string
     */
    public static function generate_backup_filename(): string {
        $domain = self::get_site_domain();
        $ts = date('Y-m-d_H-i-s');
        return "backup_{$domain}_{$ts}.sql";
    }

    /**
     * Generate S3 key with domain-based structure.
     *
     * @param string $filename
     * @return string
     */
    public static function generate_s3_key(string $filename): string {
        $base_path = get_config('local_mysqlbackuppro', 's3_base_path') ?: 'mysql-backups';
        $domain = self::get_site_domain();
        $year = date('Y');
        $month = date('m');
        $base_path = trim($base_path, '/');
        $parts = [$base_path, $domain, $year, $month, $filename];
        $parts = array_filter($parts);
        return implode('/', $parts);
    }

    /**
     * Format bytes to human readable.
     *
     * @param int $bytes
     * @param int $prec
     * @return string
     */
    public static function fmt_bytes(int $bytes, int $prec = 2): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        if ($bytes <= 0) {
            return '0 B';
        }
        $pow = min((int) floor(log($bytes, 1024)), count($units) - 1);
        return round($bytes / (1024 ** $pow), $prec) . ' ' . $units[$pow];
    }

    /**
     * Escape a string value for safe SQL insertion.
     * Uses the database connection's escape method when available,
     * falls back to manual escaping for robustness.
     *
     * @param string $value
     * @return string
     */
    private function escape_sql_value(string $value): string {
        // Try to use the database driver's escape function if available.
        if ($this->db instanceof \moodle_database && method_exists($this->db, 'mysqli')) {
            $mysqli = $this->db->mysqli;
            if ($mysqli instanceof \mysqli) {
                return $mysqli->real_escape_string($value);
            }
        }
        // Fallback: manual escape for common special characters.
        return str_replace(
            ["\\", "'", "\r", "\n", "\t", "\0"],
            ["\\\\", "\\'", "\\r", "\\n", "\\t", "\\0"],
            $value
        );
    }

    /* ============ MAIN BACKUP ============ */

    /**
     * Execute backup process.
     *
     * @param string $type 'manual' or 'scheduled'
     * @return array
     */
    public function run(string $type = 'manual'): array {
        $start = microtime(true);
        $name = self::generate_backup_filename();
        $path = $this->dir . $name;
        $id = null;

        if (!is_dir($this->dir)) {
            make_writable_directory($this->dir);
        }

        // Protect directory.
        $this->protect_directory();

        try {
            ini_set('max_execution_time', '600');
            if (function_exists('ini_set')) {
                @ini_set('memory_limit', '512M');
            }

            // 1. Register in DB.
            $id = $this->db_insert($name, $path, $type);

            // 2. Generate SQL dump.
            $info = $this->dump_sql($path);

            // 3. Compress.
            $final_path = $path;
            if ((get_config('local_mysqlbackuppro', 'compress_backup') ?: '1') === '1' && function_exists('gzopen')) {
                $zipped = $this->gzip($path);
                if ($zipped !== $path && file_exists($zipped)) {
                    @unlink($path);
                    $final_path = $zipped;
                }
            }

            $size = file_exists($final_path) ? filesize($final_path) : 0;

            // 4. Upload to S3.
            $s3_ok = false;
            $s3_key = '';
            $s3_error = '';
            if (s3native::configured()) {
                try {
                    $s3 = new s3native();
                    $key = self::generate_s3_key(basename($final_path));
                    $up = $s3->upload($final_path, $key);
                    $s3_ok = $up['success'];
                    $s3_key = $up['key'] ?? '';
                    if (!$s3_ok) {
                        $s3_error = $up['message'] ?? 'Unknown S3 upload error';
                    }
                } catch (\Throwable $s3e) {
                    $s3_error = $s3e->getMessage();
                    logger::error('S3 upload exception: ' . $s3_error);
                }
            }

            // 5. Update record.
            $this->db_update($id, [
                'file_path'    => $final_path,
                'file_size'    => $size,
                'tables_list'  => $info['tables'],
                'rows_count'   => $info['rows'],
                'status'       => 'completed',
                'completed_at' => time(),
                's3_key'       => $s3_key,
                's3_bucket'    => s3native::configured() ? get_config('local_mysqlbackuppro', 's3_bucket') : null,
                's3_endpoint'  => s3native::configured() ? get_config('local_mysqlbackuppro', 's3_endpoint') : null,
                'error_msg'    => $s3_error ?: null,
            ]);

            // 6. Cleanup old backups.
            $this->cleanup();

            // 7. Notify.
            $this->notify(true, [
                'file'     => basename($final_path),
                'size'     => self::fmt_bytes($size),
                'tables'   => $info['tables'],
                'rows'     => $info['rows'],
                's3'       => $s3_ok,
                's3_error' => $s3_error,
                'duration' => round(microtime(true) - $start, 2),
            ]);

            logger::info("Backup completed: {$name} | Size: " . self::fmt_bytes($size) . " | S3: " . ($s3_ok ? 'OK' : 'FAIL'));

            return [
                'success'   => true,
                'id'        => $id,
                'filename'  => basename($final_path),
                'file_size' => self::fmt_bytes($size),
                'tables'    => $info['tables'],
                'rows'      => $info['rows'],
                's3'        => $s3_ok,
                's3_error'  => $s3_error,
                'duration'  => round(microtime(true) - $start, 2),
            ];

        } catch (\Throwable $e) {
            if ($id !== null) {
                $this->db_update($id, ['status' => 'failed', 'error_msg' => $e->getMessage()]);
            }
            logger::error('Backup failed: ' . $e->getMessage());
            $this->notify(false, ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /* ============ SQL DUMP ============ */

    /**
     * Perform SQL dump using PHP (no external dependencies).
     *
     * @param string $filepath
     * @return array
     */
    private function dump_sql(string $filepath): array {
        $fp = fopen($filepath, 'w');
        if (!$fp) {
            throw new \RuntimeException('Cannot create backup file. Check write permissions: ' . dirname($filepath));
        }

        $tables_list = [];
        $total_rows = 0;

        fwrite($fp, "-- MySQL Backup Pro v{$this->version}\n");
        fwrite($fp, "-- DB: {$this->dbname} | " . date('Y-m-d H:i:s') . "\n");
        fwrite($fp, "SET FOREIGN_KEY_CHECKS=0;\nSET AUTOCOMMIT=0;\nSTART TRANSACTION;\n\n");

        // Use information_schema for reliable table listing across MySQL versions.
        $rows = $this->db->get_records_sql(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE'",
            [$this->dbname]
        );
        if (empty($rows)) {
            fclose($fp);
            throw new \RuntimeException('No tables found in database');
        }

        foreach ($rows as $r) {
            $tbl = $r->table_name;
            if (empty($tbl)) {
                continue;
            }
            $tables_list[] = $tbl;

            // Structure.
            $create = $this->db->get_record_sql("SHOW CREATE TABLE `{$tbl}`");
            if (!$create) {
                continue;
            }
            $create_arr = (array)$create;
            $create_sql = end($create_arr);

            fwrite($fp, "\nDROP TABLE IF EXISTS `{$tbl}`;\n");
            fwrite($fp, $create_sql . ";\n\n");

            // Data in batches.
            $cnt = (int) $this->db->count_records_sql("SELECT COUNT(*) FROM `{$tbl}`");
            if ($cnt === 0) {
                continue;
            }

            // Get column names reliably.
            $col_records = $this->db->get_records_sql("SHOW COLUMNS FROM `{$tbl}`");
            $col_names_arr = [];
            foreach ($col_records as $col) {
                $col_names_arr[] = '`' . $col->field . '`';
            }
            $col_names = implode(',', $col_names_arr);

            $batch = 1000;
            for ($off = 0; $off < $cnt; $off += $batch) {
                $data = $this->db->get_records_sql("SELECT * FROM `{$tbl}` LIMIT {$batch} OFFSET {$off}");
                if (empty($data)) {
                    break;
                }

                $vals = [];
                foreach ($data as $row) {
                    $v = [];
                    foreach ((array)$row as $val) {
                        if ($val === null) {
                            $v[] = 'NULL';
                        } else {
                            $escaped = $this->escape_sql_value((string)$val);
                            $v[] = "'" . $escaped . "'";
                        }
                    }
                    $vals[] = '(' . implode(',', $v) . ')';
                    $total_rows++;
                }

                fwrite($fp, "INSERT INTO `{$tbl}` ({$col_names}) VALUES\n" . implode(",\n", $vals) . ";\n");
            }
        }

        fwrite($fp, "\nCOMMIT;\nSET FOREIGN_KEY_CHECKS=1;\n-- FIN\n");
        fclose($fp);

        return [
            'tables' => implode(', ', $tables_list),
            'rows'   => $total_rows,
        ];
    }

    /**
     * Gzip compress a file.
     *
     * @param string $file
     * @return string
     */
    private function gzip(string $file): string {
        $out = $file . '.gz';
        $src = fopen($file, 'rb');
        $dst = gzopen($out, 'wb9');
        if (!$src || !$dst) {
            if ($src) fclose($src);
            if ($dst) gzclose($dst);
            return $file;
        }
        while (!feof($src)) {
            gzwrite($dst, fread($src, 512 * 1024));
        }
        fclose($src);
        gzclose($dst);
        return $out;
    }

    /* ============ DB HELPERS ============ */

    /**
     * Insert backup record.
     *
     * @param string $name
     * @param string $path
     * @param string $type
     * @return int
     */
    private function db_insert(string $name, string $path, string $type): int {
        global $DB;
        $record = new \stdClass();
        $record->file_name   = $name;
        $record->file_path   = $path;
        $record->status      = 'pending';
        $record->backup_type = $type;
        $record->created_at  = time();
        return (int) $DB->insert_record('local_mbpro_backups', $record);
    }

    /**
     * Update backup record.
     *
     * @param int $id
     * @param array $data
     * @return void
     */
    private function db_update(int $id, array $data): void {
        global $DB;
        $record = (object) array_merge(['id' => $id], $data);
        $DB->update_record('local_mbpro_backups', $record);
    }

    /**
     * Get a backup record.
     *
     * @param int $id
     * @return object|null
     */
    public function get_row(int $id): ?object {
        global $DB;
        return $DB->get_record('local_mbpro_backups', ['id' => $id]) ?: null;
    }

    /* ============ CLEANUP ============ */

    /**
     * Remove old backups beyond retention limit.
     *
     * @return void
     */
    private function cleanup(): void {
        $max = (int) (get_config('local_mysqlbackuppro', 'retention_count') ?: '10');
        if ($max <= 0) {
            return;
        }
        global $DB;
        $old = $DB->get_records_sql(
            "SELECT id FROM {local_mbpro_backups} WHERE status = 'completed' ORDER BY completed_at DESC LIMIT 99999 OFFSET ?",
            [$max]
        );
        foreach ($old as $o) {
            $this->delete((int) $o->id);
        }
    }

    /**
     * Delete a backup (file + S3 + DB record).
     *
     * @param int $id
     * @return array
     */
    public function delete(int $id): array {
        $row = $this->get_row($id);
        if (!$row) {
            return ['success' => false, 'message' => 'Backup not found'];
        }
        if (!empty($row->file_path) && file_exists($row->file_path)) {
            @unlink($row->file_path);
        }
        if (!empty($row->s3_key) && s3native::configured()) {
            try {
                (new s3native())->delete($row->s3_key);
            } catch (\Throwable $e) {
                // Silenciar error de eliminacion S3.
            }
        }
        global $DB;
        $DB->delete_records('local_mbpro_backups', ['id' => $id]);
        return ['success' => true];
    }

    /* ============ EMAIL NOTIFICATION ============ */

    /**
     * Send email notification.
     *
     * @param bool $ok
     * @param array $data
     * @return void
     */
    private function notify(bool $ok, array $data): void {
        global $CFG;
        $email = get_config('local_mysqlbackuppro', 'notify_email') ?: $CFG->noreplyaddress;
        if (empty($email) || !validate_email($email)) {
            return;
        }

        $site = format_string($CFG->fullname);
        $subject = $ok
            ? "[{$site}] Backup OK - MySQL Backup Pro"
            : "[{$site}] Backup FAILED - MySQL Backup Pro";

        if ($ok) {
            $body = "Hello,\n\n";
            $body .= "The MySQL backup has completed successfully.\n\n";
            $body .= "=== BACKUP DETAILS ===\n";
            $body .= "File:     {$data['file']}\n";
            $body .= "Size:     {$data['size']}\n";
            $body .= "Tables:   {$data['tables']}\n";
            $body .= "Rows:     " . number_format($data['rows']) . "\n";
            $body .= "S3:       " . ($data['s3'] ? 'Uploaded successfully' : 'Not configured or upload failed') . "\n";
            if (!empty($data['s3_error'])) {
                $body .= "S3 Error: {$data['s3_error']}\n";
            }
            $body .= "Duration: {$data['duration']}s\n";
        } else {
            $body = "Hello,\n\n";
            $body .= "The MySQL backup has failed.\n\n";
            $body .= "=== ERROR ===\n";
            $body .= "Error: {$data['error']}\n\n";
            $body .= "Please check the plugin configuration.\n";
        }
        $body .= "\n---\n";
        $body .= "MySQL Backup Pro\n";
        $body .= "Site: {$CFG->wwwroot}\n";
        $body .= "Date: " . userdate(time(), '%Y-%m-%d %H:%M:%S') . "\n";

        $user = new \stdClass();
        $user->email = $email;
        $user->firstname = 'Admin';
        $user->lastname = '';
        $user->maildisplay = true;
        $user->mailformat = 0; // Plain text.
        $user->id = -99;

        $from = \core_user::get_support_user();

        $sent = email_to_user($user, $from, $subject, $body);

        if (!$sent) {
            logger::error('Failed to send notification email to ' . $email);
        } else {
            logger::info('Notification sent to ' . $email);
        }
    }

    /**
     * Protect backup directory.
     *
     * @return void
     */
    private function protect_directory(): void {
        $htaccess = $this->dir . '.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Options -Indexes\nDeny from all\n<FilesMatch \"\\.(sql|gz)$\">\nDeny from all\n</FilesMatch>\n");
        }
        $indexphp = $this->dir . 'index.php';
        if (!file_exists($indexphp)) {
            file_put_contents($indexphp, '<?php // Silence is golden');
        }
    }
}
