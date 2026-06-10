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
 * Download backup file
 *
 * @package    local_mysqlbackuppro
 * @copyright  2024 Louis Jhosimar Ocampo | GestLife Dev
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/mysqlbackuppro/lib.php');

require_login();
require_capability('local/mysqlbackuppro:manage', context_system::instance());
require_sesskey();

$id = required_param('id', PARAM_INT);

$record = $DB->get_record('local_mbpro_backups', ['id' => $id]);
if (!$record || empty($record->file_path) || !file_exists($record->file_path)) {
    throw new moodle_exception('filenotfound');
}

$file = $record->file_path;
$filename = basename($file);
$mime = local_mysqlbackuppro_ends_with($file, '.gz') ? 'application/gzip' : 'application/sql';

header('Content-Description: File Transfer');
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($file));
header('Cache-Control: no-cache, must-revalidate');
readfile($file);
exit;
