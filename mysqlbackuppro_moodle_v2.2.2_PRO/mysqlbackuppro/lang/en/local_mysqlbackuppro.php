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
 * English strings for MySQL Backup Pro
 *
 * @package    local_mysqlbackuppro
 * @copyright  2024 Louis Jhosimar Ocampo | GestLife Dev
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'MySQL Backup Pro';

// Navigation.
$string['dashboard'] = 'Dashboard';
$string['backups'] = 'Backups';
$string['s3explorer'] = 'S3 Explorer';
$string['settings'] = 'Settings';
$string['logs'] = 'Logs';

// Scheduled task.
$string['scheduledtask_name'] = 'MySQL Backup Pro - Scheduled Backup';

// Dashboard.
$string['total_backups'] = 'Total Backups';
$string['successful'] = 'Successful';
$string['failed'] = 'Failed';
$string['total_size'] = 'Total Size';
$string['db_info'] = 'Database Information';
$string['db_name'] = 'Database';
$string['db_size'] = 'Size';
$string['tables'] = 'Tables';
$string['next_backup'] = 'Next Backup';
$string['auto_backups'] = 'Automatic Backups';
$string['enabled'] = 'Enabled';
$string['disabled'] = 'Disabled';
$string['frequency'] = 'Frequency';
$string['s3_status'] = 'S3 Status';
$string['configured'] = 'Configured';
$string['not_configured'] = 'Not Configured';
$string['last_backup'] = 'Last Backup';
$string['file'] = 'File';
$string['size'] = 'Size';
$string['status'] = 'Status';
$string['date'] = 'Date';
$string['uploaded_s3'] = 'Uploaded to S3';
$string['no_backups_yet'] = 'No backups yet. Create your first one!';
$string['quick_actions'] = 'Quick Actions';
$string['run_backup_now'] = 'Run Backup Now';
$string['view_backups'] = 'View Backups';
$string['not_scheduled'] = 'Not scheduled';

// Backups page.
$string['refresh'] = 'Refresh';
$string['type'] = 'Type';
$string['rows'] = 'Rows';
$string['actions'] = 'Actions';
$string['download'] = 'Download';
$string['upload_s3'] = 'Upload to S3';
$string['delete'] = 'Delete';
$string['local'] = 'Local';
$string['pending'] = 'Pending';
$string['no_backups'] = 'No backups registered. Click "Run Backup Now" to create the first one.';

// S3 Explorer.
$string['root'] = 'Root';
$string['new_folder'] = 'New Folder';
$string['search_files'] = 'Search files...';
$string['clear'] = 'Clear';
$string['all_files'] = 'All files';
$string['per_page'] = '/ page';
$string['loading'] = 'Loading...';
$string['name'] = 'Name';
$string['first_page'] = 'First page';
$string['prev_page'] = 'Previous page';
$string['next_page'] = 'Next page';
$string['create'] = 'Create';
$string['cancel'] = 'Cancel';
$string['s3_explorer_info'] = 'Browse your S3 bucket folders. Use the search to find files quickly. Pagination is handled automatically to avoid server overload.';
$string['s3_not_configured'] = 'S3 not configured.';
$string['s3_go_settings'] = 'Go to';
$string['searching'] = 'Searching';
$string['folder_created'] = 'Folder created successfully';
$string['folder_create_error'] = 'Error creating folder';
$string['object_deleted'] = 'Object deleted from S3';
$string['object_delete_error'] = 'Error deleting from S3';

// Settings.
$string['general_settings'] = 'General Settings';
$string['auto_backups_desc'] = 'Enable or disable automatic backups.';
$string['frequency_desc'] = 'How often automatic backups will run.';
$string['backup_time'] = 'Backup Time';
$string['gzip_compression'] = 'Gzip Compression';
$string['gzip_compression_desc'] = 'Compress SQL files to reduce size.';
$string['retention'] = 'Retention (backups count)';
$string['retention_desc'] = 'Maximum number of backups to keep. Oldest are deleted automatically.';
$string['email_notifications'] = 'Email Notifications';
$string['notify_email'] = 'Notification Email';
$string['notify_email_desc'] = 'Receive notifications when a backup completes or fails. Leave empty to disable.';
$string['test_email'] = 'Test Email';
$string['send_test'] = 'Send Test Email';
$string['email_configured'] = 'Email configured';
$string['email_not_configured'] = 'No email configured. Enter one above and save.';
$string['s3_settings'] = 'S3 Configuration (Contabo / AWS / MinIO)';
$string['for_contabo'] = 'For Contabo:';
$string['contabo_endpoint'] = 'Endpoint = https://eu2.contabostorage.com (adjust region).';
$string['path_style'] = 'Path Style';
$string['path_style_required'] = 'must be enabled for Contabo and MinIO.';
$string['endpoint_url'] = 'Endpoint URL';
$string['endpoint_desc'] = 'S3 service URL. Contabo: https://eu2.contabostorage.com';
$string['region'] = 'Region';
$string['region_desc'] = 'Bucket region. Contabo uses "default".';
$string['bucket_name'] = 'Bucket Name';
$string['access_key'] = 'Access Key';
$string['secret_key'] = 'Secret Key';
$string['saved'] = 'Saved';
$string['path_style_desc'] = 'Required for Contabo and MinIO. Disable only for pure AWS.';
$string['base_path'] = 'Base Path in S3';
$string['base_path_desc'] = 'Root folder where backups will be stored. Auto structure:';
$string['test_s3'] = 'Test S3 Connection';
$string['save_settings'] = 'Save Settings';
$string['settings_saved'] = 'Settings saved successfully.';

// Frequencies.
$string['freq_hourly'] = 'Every hour';
$string['freq_twicedaily'] = 'Twice a day';
$string['freq_daily'] = 'Daily';
$string['freq_weekly'] = 'Weekly';
$string['freq_monthly'] = 'Monthly';

// Logs.
$string['level'] = 'Level';
$string['message'] = 'Message';
$string['all_levels'] = 'All levels';
$string['clear_logs'] = 'Clear Logs';
$string['confirm_clear_logs'] = 'Are you sure you want to clear all logs?';
$string['confirm_delete_backup'] = 'Delete this backup permanently?';
$string['confirm_run_backup'] = 'Run backup now? This may take several minutes.';
$string['confirm_upload_s3'] = 'Upload this backup to S3 now?';
$string['confirm_delete_s3'] = 'Delete this S3 object permanently?';
$string['confirm_delete_folder'] = 'Delete this folder and ALL its contents from S3?';

// Modal / Progress.
$string['running_backup'] = 'Running Backup...';
$string['backup_started'] = 'Creating backup, please wait...';
$string['backup_ok'] = 'Backup completed successfully!';
$string['backup_error'] = 'Error running backup';
$string['upload_s3_started'] = 'Uploading to S3, please wait...';
$string['upload_s3_ok'] = 'Uploaded to S3 successfully!';
$string['upload_s3_error'] = 'Error uploading to S3';
$string['please_wait'] = 'Please wait...';
$string['test_email_sent'] = 'Test email sent!';
$string['test_email_error'] = 'Error sending email';

// S3 connection messages.
$string['s3_conn_ok'] = 'Connection successful! Bucket "{$a}" found.';
$string['s3_conn_bucket_not_found'] = 'Connected, but bucket "{$a->bucket}" not found. Available: {$a->buckets}';

// Capabilities.
$string['mysqlbackuppro:manage'] = 'Manage MySQL Backup Pro';
$string['mysqlbackuppro:view'] = 'View MySQL Backup Pro';
