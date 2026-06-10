<?php
namespace MBP;

class Backup
{
    private string $dir;

    public function __construct()
    {
        $this->dir = trailingslashit(WP_CONTENT_DIR) . 'mysql-backup-pro/';
    }

    /* ---------- Helpers de ruta S3 con estructura por dominio ---------- */

    /**
     * Obtener el dominio sanitizado del sitio actual.
     * Ej: https://www.misitio.com → misitio-com
     */
    public static function get_site_domain(): string
    {
        $domain = parse_url(get_site_url(), PHP_URL_HOST);
        if (empty($domain)) {
            $domain = $_SERVER['HTTP_HOST'] ?? 'unknown';
        }
        // Quitar www.
        $domain = preg_replace('/^www\./', '', $domain);
        // Reemplazar caracteres no alfanuméricos por guiones
        $domain = preg_replace('/[^a-zA-Z0-9.-]/', '-', $domain);
        return strtolower($domain);
    }

    /**
     * Generar el nombre del archivo de backup.
     * Formato: backup_{DOMINIO}_{FECHA_HORA}.sql
     */
    public static function generate_backup_filename(): string
    {
        $domain = self::get_site_domain();
        $ts     = date('Y-m-d_H-i-s');
        return "backup_{$domain}_{$ts}.sql";
    }

    /**
     * Generar la ruta completa en S3 para un backup.
     * Estructura: {base_path}/{dominio}/{YYYY}/{MM}/{filename}
     *
     * @param string $filename Nombre del archivo (con extensión .sql o .sql.gz)
     */
    public static function generate_s3_key(string $filename): string
    {
        $base_path = get_option('mbp_s3_base_path', 'mysql-backups');
        $domain    = self::get_site_domain();
        $year      = date('Y');
        $month     = date('m');

        // Sanitizar base_path: sin slashes al inicio ni al final
        $base_path = trim($base_path, '/');

        $parts = [$base_path, $domain, $year, $month, $filename];
        $parts = array_filter($parts);

        return implode('/', $parts);
    }

    /* ---------- Ejecutar backup ---------- */
    public function run(string $type = 'manual'): array
    {
        $start = microtime(true);
        $name  = self::generate_backup_filename();
        $path  = $this->dir . $name;

        // Asegurar que el directorio existe
        if (!is_dir($this->dir)) {
            wp_mkdir_p($this->dir);
        }

        try {
            ini_set('max_execution_time', '600');
            ini_set('memory_limit', '512M');

            // 1. Registrar en BD
            $id = $this->db_insert($name, $path, $type);

            // 2. Generar SQL
            $info = $this->dump_sql($path);

            // 3. Comprimir
            $final_path = $path;
            if (get_option('mbp_compress_backup', '1') === '1' && function_exists('gzopen')) {
                $zipped = $this->gzip($path);
                if ($zipped !== $path && file_exists($zipped)) {
                    @unlink($path);
                    $final_path = $zipped;
                }
            }

            $size = file_exists($final_path) ? filesize($final_path) : 0;

            // 4. Subir a S3
            $s3_ok  = false;
            $s3_key = '';
            $s3_error = '';
            if (S3Native::configured()) {
                try {
                    $s3     = new S3Native();
                    $key    = self::generate_s3_key(basename($final_path));
                    $up     = $s3->upload($final_path, $key);
                    $s3_ok  = $up['success'];
                    $s3_key = $up['key'] ?? '';
                    if (!$s3_ok) {
                        $s3_error = $up['message'] ?? 'Error desconocido al subir a S3';
                    }
                } catch (\Throwable $s3e) {
                    $s3_error = $s3e->getMessage();
                }
            }

            // 5. Actualizar registro
            $this->db_update($id, [
                'file_path'    => $final_path,
                'file_size'    => $size,
                'tables_list'  => $info['tables'],
                'rows_count'   => $info['rows'],
                'status'       => 'completed',
                'completed_at' => current_time('mysql'),
                's3_key'       => $s3_key,
                's3_bucket'    => S3Native::configured() ? get_option('mbp_s3_bucket') : null,
                's3_endpoint'  => S3Native::configured() ? get_option('mbp_s3_endpoint') : null,
                'error_msg'    => $s3_error ?: null,
            ]);

            // 6. Limpiar viejos
            $this->cleanup();

            // 7. Notificar
            $this->notify(true, [
                'file'     => basename($final_path),
                'size'     => AdminMenu::fmt_bytes($size),
                'tables'   => $info['tables'],
                'rows'     => $info['rows'],
                's3'       => $s3_ok,
                's3_error' => $s3_error,
                'duration' => round(microtime(true) - $start, 2),
            ]);

            return [
                'success'   => true,
                'id'        => $id,
                'filename'  => basename($final_path),
                'file_size' => AdminMenu::fmt_bytes($size),
                'tables'    => $info['tables'],
                'rows'      => $info['rows'],
                's3'        => $s3_ok,
                's3_error'  => $s3_error,
                'duration'  => round(microtime(true) - $start, 2),
            ];

        } catch (\Throwable $e) {
            if (isset($id)) {
                $this->db_update($id, ['status' => 'failed', 'error_msg' => $e->getMessage()]);
            }
            $this->notify(false, ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /* ---------- Dump SQL ---------- */
    private function dump_sql(string $filepath): array
    {
        global $wpdb;

        $fp = fopen($filepath, 'w');
        if (!$fp) {
            throw new \RuntimeException('No se pudo crear archivo de backup. Verifica permisos de escritura en: ' . dirname($filepath));
        }

        $tables_list = [];
        $total_rows  = 0;

        fwrite($fp, "-- MySQL Backup Pro v" . MBP_VERSION . "\n");
        fwrite($fp, "-- DB: " . DB_NAME . " | " . wp_date('Y-m-d H:i:s') . "\n");
        fwrite($fp, "SET FOREIGN_KEY_CHECKS=0;\nSET AUTOCOMMIT=0;\nSTART TRANSACTION;\n\n");

        $rows = $wpdb->get_results('SHOW TABLES', ARRAY_N);
        if (empty($rows)) {
            fclose($fp);
            throw new \RuntimeException('No se encontraron tablas en la base de datos');
        }

        foreach ($rows as $r) {
            $tbl = $r[0];
            $tables_list[] = $tbl;

            // Estructura
            $create = $wpdb->get_row("SHOW CREATE TABLE `{$tbl}`", ARRAY_N);
            if (!$create) {
                continue;
            }
            fwrite($fp, "\nDROP TABLE IF EXISTS `{$tbl}`;\n");
            fwrite($fp, $create[1] . ";\n\n");

            // Datos en lotes
            $cnt = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$tbl}`");
            if ($cnt === 0) {
                continue;
            }

            $cols = $wpdb->get_results("SHOW COLUMNS FROM `{$tbl}`", ARRAY_A);
            $col_names = implode(',', array_map(static fn($c) => '`' . $c['Field'] . '`', $cols));

            $batch = 1000;
            for ($off = 0; $off < $cnt; $off += $batch) {
                $data = $wpdb->get_results(
                    $wpdb->prepare("SELECT * FROM `{$tbl}` LIMIT %d OFFSET %d", $batch, $off),
                    ARRAY_A
                );
                if (empty($data)) {
                    break;
                }

                $vals = [];
                foreach ($data as $row) {
                    $v = [];
                    foreach ($row as $val) {
                        $v[] = ($val === null) ? 'NULL' : "'" . $wpdb->_real_escape((string) $val) . "'";
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

    /* ---------- Gzip ---------- */
    private function gzip(string $file): string
    {
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

    /* ---------- DB helpers ---------- */
    private function db_insert(string $name, string $path, string $type): int
    {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'mbp_backups',
            [
                'file_name'   => $name,
                'file_path'   => $path,
                'status'      => 'pending',
                'backup_type' => $type,
            ],
            ['%s', '%s', '%s', '%s']
        );
        return (int) $wpdb->insert_id;
    }

    private function db_update(int $id, array $data): void
    {
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'mbp_backups', $data, ['id' => $id], null, ['%d']);
    }

    private function get_row(int $id): ?array
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}mbp_backups WHERE id=%d", $id), ARRAY_A);
    }

    /* ---------- Cleanup ---------- */
    private function cleanup(): void
    {
        $max = (int) get_option('mbp_retention_count', '10');
        if ($max <= 0) {
            return;
        }
        global $wpdb;
        $t = $wpdb->prefix . 'mbp_backups';
        $old = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM {$t} WHERE status='completed' ORDER BY completed_at DESC LIMIT 99999 OFFSET %d",
            $max
        ));
        foreach ($old as $o) {
            $this->delete((int) $o->id);
        }
    }

    /* ---------- Eliminar ---------- */
    public function delete(int $id): array
    {
        $row = $this->get_row($id);
        if (!$row) {
            return ['success' => false, 'message' => 'Backup no encontrado'];
        }
        if (!empty($row['file_path']) && file_exists($row['file_path'])) {
            @unlink($row['file_path']);
        }
        if (!empty($row['s3_key']) && S3Native::configured()) {
            try {
                (new S3Native())->delete($row['s3_key']);
            } catch (\Throwable $e) {
                // Silenciar error de eliminación S3
            }
        }
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'mbp_backups', ['id' => $id], ['%d']);
        return ['success' => true];
    }

    /* ---------- Notificación email ---------- */
    private function notify(bool $ok, array $data): void
    {
        $email = get_option('mbp_notify_email', get_option('admin_email', ''));
        if (empty($email) || !is_email($email)) {
            return;
        }

        $site = get_bloginfo('name');
        $to = $email;
        $headers = ['Content-Type: text/plain; charset=UTF-8'];

        if ($ok) {
            $subject = "[{$site}] Backup OK - MySQL Backup Pro";
            $body = "Hola,\n\n";
            $body .= "El backup de MySQL se ha completado correctamente.\n\n";
            $body .= "=== DETALLES DEL BACKUP ===\n";
            $body .= "Archivo:   {$data['file']}\n";
            $body .= "Tamaño:    {$data['size']}\n";
            $body .= "Tablas:    {$data['tables']}\n";
            $body .= "Filas:     " . number_format($data['rows']) . "\n";
            $body .= "S3:        " . ($data['s3'] ? 'Subido correctamente' : 'No configurado o fallo la subida') . "\n";
            if (!empty($data['s3_error'])) {
                $body .= "Error S3:  {$data['s3_error']}\n";
            }
            $body .= "Duración:  {$data['duration']}s\n";
        } else {
            $subject = "[{$site}] Backup FALLIDO - MySQL Backup Pro";
            $body = "Hola,\n\n";
            $body .= "El backup de MySQL ha fallado.\n\n";
            $body .= "=== ERROR ===\n";
            $body .= "Error: {$data['error']}\n\n";
            $body .= "Por favor, revisa la configuracion del plugin.\n";
        }
        $body .= "\n---\n";
        $body .= "MySQL Backup Pro v" . MBP_VERSION . "\n";
        $body .= "Sitio: " . home_url() . "\n";
        $body .= "Fecha: " . wp_date('Y-m-d H:i:s') . "\n";

        $sent = wp_mail($to, $subject, $body, $headers);

        // Log silencioso del resultado del envio
        if (!$sent) {
            error_log('[MySQL Backup Pro] ERROR: No se pudo enviar notificacion email a ' . $email);
        } else {
            error_log('[MySQL Backup Pro] Notificacion enviada a ' . $email);
        }
    }

    /* ---------- AJAX handlers ---------- */
    public static function ajax_run_backup(): void
    {
        $b = new self();
        $r = $b->run('manual');
        if ($r['success']) {
            wp_send_json_success($r);
        }
        wp_send_json_error($r['message'] ?? 'Error desconocido');
    }

    public static function ajax_delete_backup(): void
    {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $b  = new self();
        $r  = $b->delete($id);
        if ($r['success']) {
            wp_send_json_success($r);
        }
        wp_send_json_error($r['message']);
    }

    public static function ajax_download_backup(): void
    {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $b  = new self();
        $row = $b->get_row($id);
        if (!$row) {
            wp_send_json_error('Backup no encontrado');
        }

        // Si está en S3, URL presignada
        if (!empty($row['s3_key']) && S3Native::configured()) {
            try {
                $s3 = new S3Native();
                $url = $s3->presigned_url($row['s3_key']);
                if ($url['success']) {
                    wp_send_json_success(['type' => 's3', 'url' => $url['url']]);
                }
            } catch (\Throwable $e) {
                // Falla silenciosa, intentar local
            }
        }

        // Descarga local via PHP
        if (!empty($row['file_path']) && file_exists($row['file_path'])) {
            wp_send_json_success([
                'type'     => 'local',
                'filename' => basename($row['file_path']),
                'url'      => admin_url("admin-post.php?action=mbp_download_file&id={$id}&_wpnonce=" . wp_create_nonce('mbp_dl_' . $id)),
            ]);
        }

        wp_send_json_error('Archivo no disponible (no existe localmente ni en S3)');
    }
}
