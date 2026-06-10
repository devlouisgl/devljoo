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
 * AJAX handler for MySQL Backup Pro
 *
 * @package    local_mysqlbackuppro
 * @copyright  2024 Louis Jhosimar Ocampo | GestLife Dev
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/mysqlbackuppro/lib.php');

use local_mysqlbackuppro\backup;
use local_mysqlbackuppro\s3native;
use local_mysqlbackuppro\crypto;
use local_mysqlbackuppro\logger;

require_login();
require_capability('local/mysqlbackuppro:manage', context_system::instance());
require_sesskey();

// Ensure no output buffering issues.
if (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: application/json; charset=utf-8');

$action = required_param('action', PARAM_ALPHANUMEXT);
$response = ['success' => false, 'message' => 'Unknown action'];

try {
    switch ($action) {

        /* ============ BACKUP OPERATIONS ============ */

        case 'run_backup':
            $b = new backup();
            $result = $b->run('manual');
            if ($result['success']) {
                $response = ['success' => true, 'data' => $result];
            } else {
                $response = ['success' => false, 'message' => $result['message'] ?? 'Backup error'];
            }
            break;

        case 'delete_backup':
            $id = required_param('id', PARAM_INT);
            $b = new backup();
            $result = $b->delete($id);
            if ($result['success']) {
                $response = ['success' => true];
            } else {
                $response = ['success' => false, 'message' => $result['message']];
            }
            break;

        case 'download_backup':
            $id = required_param('id', PARAM_INT);
            $b = new backup();
            $row = $b->get_row($id);
            if (!$row) {
                $response = ['success' => false, 'message' => 'Backup not found'];
                break;
            }

            // Try S3 presigned URL first.
            if (!empty($row->s3_key) && s3native::configured()) {
                try {
                    $s3 = new s3native();
                    $url = $s3->presigned_url($row->s3_key);
                    if ($url['success']) {
                        $response = ['success' => true, 'data' => ['type' => 's3', 'url' => $url['url']]];
                        break;
                    }
                } catch (\Throwable $e) {
                    // Fall through to local.
                }
            }

            // Local download via pluginfile.
            if (!empty($row->file_path) && file_exists($row->file_path)) {
                $downloadurl = new moodle_url('/local/mysqlbackuppro/download.php', ['id' => $id, 'sesskey' => sesskey()]);
                $response = ['success' => true, 'data' => [
                    'type'     => 'local',
                    'filename' => basename($row->file_path),
                    'url'      => $downloadurl->out(false),
                ]];
            } else {
                $response = ['success' => false, 'message' => 'File not available locally or in S3'];
            }
            break;

        case 'upload_to_s3':
            $id = required_param('id', PARAM_INT);
            if (!s3native::configured()) {
                $response = ['success' => false, 'message' => 'S3 is not configured. Go to Settings to configure S3 credentials.'];
                break;
            }
            global $DB;
            $row = $DB->get_record('local_mbpro_backups', ['id' => $id]);
            if (!$row) {
                $response = ['success' => false, 'message' => 'Backup not found'];
                break;
            }
            if (empty($row->file_path) || !file_exists($row->file_path)) {
                $response = ['success' => false, 'message' => 'Local file not found. It may have been deleted.'];
                break;
            }

            $s3 = new s3native();
            $key = backup::generate_s3_key(basename($row->file_path));
            $up = $s3->upload($row->file_path, $key);

            if ($up['success']) {
                $row->s3_key = $up['key'];
                $row->s3_bucket = get_config('local_mysqlbackuppro', 's3_bucket');
                $row->s3_endpoint = get_config('local_mysqlbackuppro', 's3_endpoint');
                $DB->update_record('local_mbpro_backups', $row);
                $response = ['success' => true, 'data' => ['message' => 'File uploaded to S3', 's3_key' => $up['key'], 'bucket' => $up['bucket']]];
            } else {
                $response = ['success' => false, 'message' => 'S3 Error: ' . ($up['message'] ?? 'Unknown')];
            }
            break;

        case 'get_backups':
            global $DB;
            $backups = $DB->get_records('local_mbpro_backups', null, 'created_at DESC', '*', 0, 100);
            $response = ['success' => true, 'data' => ['backups' => array_values($backups)]];
            break;

        /* ============ S3 OPERATIONS ============ */

        case 's3_list_objects':
            if (!s3native::configured()) {
                $response = ['success' => false, 'message' => 'S3 is not configured.'];
                break;
            }

            $prefix = optional_param('prefix', '', PARAM_RAW_TRIMMED);
            $search = optional_param('search', '', PARAM_RAW_TRIMMED);
            $filter_ext = optional_param('filter_ext', '', PARAM_RAW_TRIMMED);
            $page = optional_param('page', 1, PARAM_INT);
            $per_page = min(max(optional_param('per_page', 50, PARAM_INT), 10), 200);
            $requested_token = optional_param('continuation_token', '', PARAM_RAW_TRIMMED);

            $s3 = new s3native();

            // CASE 1: Active search.
            if ($search !== '') {
                $result = $s3->search_objects($search, $prefix, $per_page * 2, $filter_ext);
                if (!$result['success']) {
                    $response = ['success' => false, 'message' => $result['message']];
                    break;
                }

                $all_items = array_merge(
                    array_map(fn($f) => array_merge($f, ['type' => 'folder']), $result['folders']),
                    array_map(fn($f) => array_merge($f, ['type' => 'file']), $result['files'])
                );

                $total_items = count($all_items);
                $total_pages = max(1, ceil($total_items / $per_page));
                $page = min($page, $total_pages);
                $offset = ($page - 1) * $per_page;
                $paged_items = array_slice($all_items, $offset, $per_page);

                $folders = array_values(array_filter($paged_items, fn($i) => $i['type'] === 'folder'));
                $files = array_values(array_filter($paged_items, fn($i) => $i['type'] === 'file'));

                $response = ['success' => true, 'data' => [
                    'folders'            => array_values(array_map(fn($f) => ['name' => $f['name'], 'prefix' => $f['prefix']], $folders)),
                    'files'              => array_values(array_map(fn($f) => ['name' => $f['name'], 'key' => $f['key'], 'size' => $f['size'], 'modified' => $f['modified']], $files)),
                    'prefix'             => $prefix,
                    'search'             => $search,
                    'filter_ext'         => $filter_ext,
                    'page'               => $page,
                    'per_page'           => $per_page,
                    'total_pages'        => $total_pages,
                    'total_items'        => $total_items,
                    'has_next'           => $page < $total_pages,
                    'has_prev'           => $page > 1,
                    'is_search'          => true,
                    'continuation_token' => '',
                ]];
                break;
            }

            // CASE 2: Normal navigation with S3 pagination.
            $continuation_token = '';
            if ($page > 1 && $requested_token !== '') {
                $continuation_token = $requested_token;
            }

            $result = $s3->list_objects($prefix, '/', $per_page, $continuation_token);
            if (!$result['success']) {
                $response = ['success' => false, 'message' => $result['message']];
                break;
            }

            $files = $result['files'];
            if ($filter_ext !== '') {
                $files = array_values(array_filter($files, fn($f) => str_ends_with(strtolower($f['name']), strtolower($filter_ext))));
            }

            $response = ['success' => true, 'data' => [
                'folders'            => $result['folders'],
                'files'              => $files,
                'prefix'             => $prefix,
                'search'             => '',
                'filter_ext'         => $filter_ext,
                'page'               => $page,
                'per_page'           => $per_page,
                'total_items'        => $result['key_count'],
                'has_next'           => $result['is_truncated'],
                'has_prev'           => $page > 1,
                'is_search'          => false,
                'continuation_token' => $result['next_token'] ?? '',
                'key_count_s3'       => $result['key_count'],
                'max_keys_s3'        => $result['max_keys'],
            ]];
            break;

        case 's3_create_folder':
            if (!s3native::configured()) {
                $response = ['success' => false, 'message' => 'S3 is not configured.'];
                break;
            }
            $folder = required_param('folder', PARAM_RAW_TRIMMED);
            $prefix = optional_param('prefix', '', PARAM_RAW_TRIMMED);
            if (empty($folder)) {
                $response = ['success' => false, 'message' => 'Folder name cannot be empty.'];
                break;
            }
            $folder = preg_replace('/[^a-zA-Z0-9_-]/', '-', $folder);
            $full_path = $prefix . $folder;
            $s3 = new s3native();
            $result = $s3->create_folder($full_path);
            $response = $result;
            break;

        case 's3_delete_object':
            if (!s3native::configured()) {
                $response = ['success' => false, 'message' => 'S3 is not configured.'];
                break;
            }
            $key = required_param('key', PARAM_RAW_TRIMMED);
            if (empty($key)) {
                $response = ['success' => false, 'message' => 'Invalid key.'];
                break;
            }
            $s3 = new s3native();
            $result = $s3->delete($key);
            $response = $result;
            break;

        case 'test_s3':
            $endpoint = required_param('s3_endpoint', PARAM_RAW_TRIMMED);
            $region   = optional_param('s3_region', 'default', PARAM_RAW_TRIMMED);
            $bucket   = required_param('s3_bucket', PARAM_RAW_TRIMMED);
            $access   = optional_param('s3_access_key', '', PARAM_RAW_TRIMMED);
            $secret   = optional_param('s3_secret_key', '', PARAM_RAW_TRIMMED);
            $pathstyle = optional_param('s3_path_style', '1', PARAM_RAW_TRIMMED);

            // Validate required fields.
            if (empty($endpoint) || empty($bucket)) {
                $response = ['success' => false, 'message' => 'Endpoint and bucket are required.'];
                break;
            }

            // Temporarily set credentials.
            $old_endpoint = get_config('local_mysqlbackuppro', 's3_endpoint');
            $old_region   = get_config('local_mysqlbackuppro', 's3_region');
            $old_bucket   = get_config('local_mysqlbackuppro', 's3_bucket');
            $old_access   = get_config('local_mysqlbackuppro', 's3_access_key');
            $old_secret   = get_config('local_mysqlbackuppro', 's3_secret_key');
            $old_path     = get_config('local_mysqlbackuppro', 's3_path_style');

            set_config('s3_endpoint', $endpoint, 'local_mysqlbackuppro');
            set_config('s3_region', $region, 'local_mysqlbackuppro');
            set_config('s3_bucket', $bucket, 'local_mysqlbackuppro');
            if (!empty($access)) {
                set_config('s3_access_key', crypto::encrypt($access), 'local_mysqlbackuppro');
            }
            if (!empty($secret)) {
                set_config('s3_secret_key', crypto::encrypt($secret), 'local_mysqlbackuppro');
            }
            set_config('s3_path_style', $pathstyle, 'local_mysqlbackuppro');

            try {
                $s3 = new s3native();
                $result = $s3->list_buckets();
            } catch (\Throwable $e) {
                $result = ['success' => false, 'message' => 'S3 Error: ' . $e->getMessage()];
            }

            // Restore original values.
            set_config('s3_endpoint', $old_endpoint, 'local_mysqlbackuppro');
            set_config('s3_region', $old_region, 'local_mysqlbackuppro');
            set_config('s3_bucket', $old_bucket, 'local_mysqlbackuppro');
            set_config('s3_access_key', $old_access, 'local_mysqlbackuppro');
            set_config('s3_secret_key', $old_secret, 'local_mysqlbackuppro');
            set_config('s3_path_style', $old_path, 'local_mysqlbackuppro');

            $response = $result;
            break;

        /* ============ SETTINGS ============ */

        case 'save_settings':
            // Checkboxes: use '0' as default since unchecked checkboxes are not submitted.
            $enabled = optional_param('enabled', '0', PARAM_RAW_TRIMMED);
            set_config('enabled', $enabled === '1' ? '1' : '0', 'local_mysqlbackuppro');

            $compress = optional_param('compress_backup', '0', PARAM_RAW_TRIMMED);
            set_config('compress_backup', $compress === '1' ? '1' : '0', 'local_mysqlbackuppro');

            $s3_path_style = optional_param('s3_path_style', '0', PARAM_RAW_TRIMMED);
            set_config('s3_path_style', $s3_path_style === '1' ? '1' : '0', 'local_mysqlbackuppro');

            // Text / select fields.
            $text_fields = [
                'frequency'       => PARAM_RAW_TRIMMED,
                'backup_time'     => PARAM_RAW_TRIMMED,
                's3_endpoint'     => PARAM_RAW_TRIMMED,
                's3_region'       => PARAM_RAW_TRIMMED,
                's3_bucket'       => PARAM_RAW_TRIMMED,
                's3_base_path'    => PARAM_RAW_TRIMMED,
            ];
            foreach ($text_fields as $field => $paramtype) {
                $value = optional_param($field, '', $paramtype);
                set_config($field, $value, 'local_mysqlbackuppro');
            }

            // Numeric field.
            $retention = optional_param('retention_count', 10, PARAM_INT);
            set_config('retention_count', $retention, 'local_mysqlbackuppro');

            // Email field (may be empty).
            $email = optional_param('notify_email', '', PARAM_RAW_TRIMMED);
            if (!empty($email) && !validate_email($email)) {
                $email = '';
            }
            set_config('notify_email', $email, 'local_mysqlbackuppro');

            // S3 credentials: encrypt if provided, otherwise keep existing.
            $access_key = optional_param('s3_access_key', '', PARAM_RAW_TRIMMED);
            if (!empty($access_key)) {
                set_config('s3_access_key', crypto::encrypt($access_key), 'local_mysqlbackuppro');
            }

            $secret_key = optional_param('s3_secret_key', '', PARAM_RAW_TRIMMED);
            if (!empty($secret_key)) {
                set_config('s3_secret_key', crypto::encrypt($secret_key), 'local_mysqlbackuppro');
            }

            // Reschedule task.
            local_mysqlbackuppro_reschedule();

            $response = ['success' => true, 'message' => get_string('settings_saved', 'local_mysqlbackuppro')];
            break;

        /* ============ EMAIL ============ */

        case 'test_email':
            global $CFG;
            $email = get_config('local_mysqlbackuppro', 'notify_email') ?: $CFG->noreplyaddress;
            if (empty($email) || !validate_email($email)) {
                $response = ['success' => false, 'message' => 'No notification email configured. Set it first in Settings.'];
                break;
            }

            $site = format_string($CFG->fullname);
            $subject = "[{$site}] Test Notification - MySQL Backup Pro";
            $body = "This is a test email from MySQL Backup Pro.\n\n";
            $body .= "If you receive this message, the email configuration is working correctly.\n\n";
            $body .= "Date: " . userdate(time(), '%Y-%m-%d %H:%M:%S') . "\n";
            $body .= "Site: {$CFG->wwwroot}\n";
            $body .= "---\nMySQL Backup Pro";

            $user = new \stdClass();
            $user->email = $email;
            $user->firstname = 'Admin';
            $user->lastname = '';
            $user->maildisplay = true;
            $user->mailformat = 0;
            $user->id = -99;

            $from = \core_user::get_support_user();
            $sent = email_to_user($user, $from, $subject, $body);

            if ($sent) {
                $response = ['success' => true, 'message' => "Test email sent to {$email}. Check your inbox (and spam folder)."];
            } else {
                $response = ['success' => false, 'message' => 'Could not send email. Ensure your Moodle email settings are configured correctly.'];
            }
            break;

        /* ============ LOGS ============ */

        case 'get_logs':
            $logs = logger::get_logs_array();
            $parsed = [];
            foreach ($logs as $line) {
                if (preg_match('/\[(.*?)\]\s\[(.*?)\]\s(.*)/', $line, $matches)) {
                    $parsed[] = [
                        'date'    => $matches[1],
                        'level'   => $matches[2],
                        'message' => $matches[3],
                    ];
                }
            }
            $response = ['success' => true, 'data' => ['logs' => $parsed]];
            break;

        case 'clear_logs':
            logger::clear_logs();
            $response = ['success' => true, 'message' => 'Logs cleared'];
            break;

        default:
            $response = ['success' => false, 'message' => 'Unknown action: ' . $action];
    }
} catch (\Throwable $e) {
    logger::error('AJAX error [' . $action . ']: ' . $e->getMessage());
    $response = ['success' => false, 'message' => $e->getMessage()];
}

echo json_encode($response);
exit;
