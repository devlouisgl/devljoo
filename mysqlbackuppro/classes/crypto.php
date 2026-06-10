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
 * Encryption/Decryption utilities for sensitive credentials
 *
 * @package    local_mysqlbackuppro
 * @copyright  2024 Louis Jhosimar Ocampo | GestLife Dev
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class crypto {

    /**
     * Get or generate encryption key stored in Moodle config.
     *
     * @return string
     */
    private static function key(): string {
        $key = get_config('local_mysqlbackuppro', 'crypto_key');
        if (empty($key)) {
            $key = random_string(32);
            set_config('crypto_key', $key, 'local_mysqlbackuppro');
        }
        return $key;
    }

    /**
     * Encrypt a plain text string using AES-256-CBC.
     *
     * @param string $plain
     * @return string
     */
    public static function encrypt(string $plain): string {
        if ($plain === '') {
            return '';
        }
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($plain, 'AES-256-CBC', self::key(), OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt an encrypted string.
     *
     * @param string $encoded
     * @return string
     */
    public static function decrypt(string $encoded): string {
        if ($encoded === '') {
            return '';
        }
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 17) {
            return $encoded; // Legacy plain text value.
        }
        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $decrypted = openssl_decrypt($cipher, 'AES-256-CBC', self::key(), OPENSSL_RAW_DATA, $iv);
        return ($decrypted !== false) ? $decrypted : $encoded;
    }
}
