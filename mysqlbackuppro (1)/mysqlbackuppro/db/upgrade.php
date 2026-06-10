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
 * Upgrade function
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_mysqlbackuppro_upgrade($oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2024060900) {
        // Tabla creada via install.xml, no se necesita accion adicional.
        upgrade_plugin_savepoint(true, 2024060900, 'local', 'mysqlbackuppro');
    }

    return true;
}
