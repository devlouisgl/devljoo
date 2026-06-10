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
 * Settings page registration for MySQL Backup Pro
 *
 * @package    local_mysqlbackuppro
 * @copyright  2024 Louis Jhosimar Ocampo | GestLife Dev
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    // Add category for this plugin. We use _category suffix to avoid collision with the auto-created settings page.
    $ADMIN->add('server', new admin_category('local_mysqlbackuppro_category', get_string('pluginname', 'local_mysqlbackuppro')));

    $ADMIN->add('local_mysqlbackuppro_category', new admin_externalpage(
        'local_mysqlbackuppro_dashboard',
        get_string('dashboard', 'local_mysqlbackuppro'),
        new moodle_url('/local/mysqlbackuppro/index.php', ['page' => 'dashboard']),
        'local/mysqlbackuppro:manage'
    ));

    $ADMIN->add('local_mysqlbackuppro_category', new admin_externalpage(
        'local_mysqlbackuppro_backups',
        get_string('backups', 'local_mysqlbackuppro'),
        new moodle_url('/local/mysqlbackuppro/index.php', ['page' => 'backups']),
        'local/mysqlbackuppro:manage'
    ));

    $ADMIN->add('local_mysqlbackuppro_category', new admin_externalpage(
        'local_mysqlbackuppro_s3',
        get_string('s3explorer', 'local_mysqlbackuppro'),
        new moodle_url('/local/mysqlbackuppro/index.php', ['page' => 's3']),
        'local/mysqlbackuppro:manage'
    ));

    $ADMIN->add('local_mysqlbackuppro_category', new admin_externalpage(
        'local_mysqlbackuppro_settings',
        get_string('settings', 'local_mysqlbackuppro'),
        new moodle_url('/local/mysqlbackuppro/index.php', ['page' => 'settings']),
        'local/mysqlbackuppro:manage'
    ));

    $ADMIN->add('local_mysqlbackuppro_category', new admin_externalpage(
        'local_mysqlbackuppro_logs',
        get_string('logs', 'local_mysqlbackuppro'),
        new moodle_url('/local/mysqlbackuppro/index.php', ['page' => 'logs']),
        'local/mysqlbackuppro:manage'
    ));

    // Prevent Moodle from adding an empty settings page.
    $settings = null;
}
