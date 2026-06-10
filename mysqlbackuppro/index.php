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
 * Main page for MySQL Backup Pro
 *
 * @package    local_mysqlbackuppro
 * @copyright  2024 Louis Jhosimar Ocampo | GestLife Dev
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/mysqlbackuppro/lib.php');

use local_mysqlbackuppro\backup;
use local_mysqlbackuppro\s3native;

require_login();
require_capability('local/mysqlbackuppro:manage', context_system::instance());

$page = optional_param('page', 'dashboard', PARAM_ALPHANUMEXT);
$valid_pages = ['dashboard', 'backups', 's3', 'settings', 'logs'];
if (!in_array($page, $valid_pages, true)) {
    $page = 'dashboard';
}

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/mysqlbackuppro/index.php', ['page' => $page]));
$PAGE->set_title(get_string('pluginname', 'local_mysqlbackuppro') . ' - ' . get_string($page, 'local_mysqlbackuppro'));
$PAGE->set_heading(get_string('pluginname', 'local_mysqlbackuppro'));
$PAGE->set_pagelayout('admin');
$PAGE->requires->css(new moodle_url('/local/mysqlbackuppro/styles.css'));
$PAGE->requires->js_call_amd('local_mysqlbackuppro/admin', 'init', [
    'ajaxurl'  => (new moodle_url('/local/mysqlbackuppro/ajax/ajax_handler.php'))->out(false),
    'sesskey'  => sesskey(),
    'page'     => $page,
]);

// Navigation.
$PAGE->navbar->add(get_string('pluginname', 'local_mysqlbackuppro'), new moodle_url('/local/mysqlbackuppro/index.php', ['page' => 'dashboard']));
$PAGE->navbar->add(get_string($page, 'local_mysqlbackuppro'));

echo $OUTPUT->header();

// Prepare template context based on page.
$context = [];

switch ($page) {
    case 'dashboard':
        $stats = local_mysqlbackuppro_get_stats();
        $cfg = local_mysqlbackuppro_get_settings();
        $context = [
            'stats' => [
                'total_backups' => (int)$stats['total_backups'],
                'successful'    => (int)$stats['successful'],
                'failed'        => (int)$stats['failed'],
                'total_size'    => $stats['total_size'],
                'db_name'       => $stats['db_name'],
                'db_size'       => $stats['db_size'],
                'table_count'   => (int)$stats['table_count'],
                'next_backup'   => $stats['next_backup'],
                'enabled'       => $cfg['enabled'] === '1',
                'frequency'     => ucfirst($cfg['frequency']),
                's3_configured' => s3native::configured(),
                's3_bucket'     => $cfg['s3_bucket'],
            ],
            'last_backup' => $stats['last_backup'] ? [
                'file_name'    => $stats['last_backup']->file_name,
                'file_size'    => backup::fmt_bytes((int)$stats['last_backup']->file_size),
                'status'       => $stats['last_backup']->status,
                'status_ok'    => $stats['last_backup']->status === 'completed',
                'completed_at' => $stats['last_backup']->completed_at ? userdate($stats['last_backup']->completed_at, '%Y-%m-%d %H:%M:%S') : '',
                'has_s3'       => !empty($stats['last_backup']->s3_key),
            ] : null,
        ];
        echo $OUTPUT->render_from_template('local_mysqlbackuppro/dashboard', $context);
        break;

    case 'backups':
        global $DB;
        $records = $DB->get_records('local_mbpro_backups', null, 'created_at DESC', '*', 0, 100);
        $backups = [];
        foreach ($records as $b) {
            $tables = $b->tables_list ? count(explode(', ', $b->tables_list)) : 0;
            $has_s3 = !empty($b->s3_key);
            $has_file = !empty($b->file_path) && file_exists($b->file_path);
            $backups[] = [
                'id'          => $b->id,
                'file_name'   => $b->file_name,
                'file_size'   => backup::fmt_bytes((int)$b->file_size),
                'tables'      => $tables,
                'rows_count'  => number_format((int)$b->rows_count),
                'backup_type' => $b->backup_type,
                'status'      => $b->status,
                'status_ok'   => $b->status === 'completed',
                'status_failed' => $b->status === 'failed',
                'has_s3'      => $has_s3,
                'has_file'    => $has_file,
                's3_key'      => $b->s3_key,
                'error_msg'   => $b->error_msg,
                'date'        => $b->completed_at ? userdate($b->completed_at, '%Y-%m-%d %H:%M:%S') : userdate($b->created_at, '%Y-%m-%d %H:%M:%S'),
                's3_configured' => s3native::configured(),
            ];
        }
        $context = ['backups' => $backups, 'has_backups' => !empty($backups), 's3_configured' => s3native::configured()];
        echo $OUTPUT->render_from_template('local_mysqlbackuppro/backups', $context);
        break;

    case 's3':
        $cfg = local_mysqlbackuppro_get_settings();
        $context = [
            's3_configured' => s3native::configured(),
            's3_bucket'     => $cfg['s3_bucket'],
            's3_host'       => parse_url($cfg['s3_endpoint'], PHP_URL_HOST) ?: '',
        ];
        echo $OUTPUT->render_from_template('local_mysqlbackuppro/s3_explorer', $context);
        break;

    case 'settings':
        $cfg = local_mysqlbackuppro_get_settings();
        $frequencies = [
            ['value' => 'hourly',      'label' => get_string('freq_hourly', 'local_mysqlbackuppro'),      'selected' => $cfg['frequency'] === 'hourly'],
            ['value' => 'twicedaily',  'label' => get_string('freq_twicedaily', 'local_mysqlbackuppro'),  'selected' => $cfg['frequency'] === 'twicedaily'],
            ['value' => 'daily',       'label' => get_string('freq_daily', 'local_mysqlbackuppro'),       'selected' => $cfg['frequency'] === 'daily'],
            ['value' => 'weekly',      'label' => get_string('freq_weekly', 'local_mysqlbackuppro'),      'selected' => $cfg['frequency'] === 'weekly'],
            ['value' => 'monthly',     'label' => get_string('freq_monthly', 'local_mysqlbackuppro'),     'selected' => $cfg['frequency'] === 'monthly'],
        ];
        $context = [
            'enabled'        => $cfg['enabled'] === '1',
            'frequencies'    => $frequencies,
            'backup_time'    => $cfg['backup_time'],
            'compress'       => $cfg['compress'] === '1',
            'retention'      => $cfg['retention'],
            'notify_email'   => $cfg['notify_email'],
            'has_email'      => !empty($cfg['notify_email']) && validate_email($cfg['notify_email']),
            's3_endpoint'    => $cfg['s3_endpoint'],
            's3_region'      => $cfg['s3_region'],
            's3_bucket'      => $cfg['s3_bucket'],
            'has_access_key' => !empty($cfg['s3_access_key']),
            'has_secret_key' => !empty($cfg['s3_secret_key']),
            's3_path_style'  => $cfg['s3_path_style'] === '1',
            's3_base_path'   => $cfg['s3_base_path'],
            'domain_example' => parse_url($CFG->wwwroot, PHP_URL_HOST) ?: 'yoursite.com',
        ];
        echo $OUTPUT->render_from_template('local_mysqlbackuppro/settings_page', $context);
        break;

    case 'logs':
        echo $OUTPUT->render_from_template('local_mysqlbackuppro/logs', []);
        break;
}

echo $OUTPUT->footer();
