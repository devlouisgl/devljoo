<?php
namespace MBP;

class Activator
{
    public static function activate(): void
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $table = $wpdb->prefix . 'mbp_backups';

        // Forzar uso de upgrade.php para dbDelta
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id              bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            file_name       varchar(255) NOT NULL,
            file_path       varchar(500) DEFAULT NULL,
            s3_key          varchar(500) DEFAULT NULL,
            s3_bucket       varchar(255) DEFAULT NULL,
            s3_endpoint     varchar(255) DEFAULT NULL,
            file_size       bigint(20)   DEFAULT 0,
            tables_list     text         DEFAULT NULL,
            rows_count      bigint(20)   DEFAULT 0,
            status          varchar(50)  DEFAULT 'pending',
            backup_type     varchar(50)  DEFAULT 'manual',
            error_msg       text         DEFAULT NULL,
            created_at      datetime     DEFAULT CURRENT_TIMESTAMP,
            completed_at    datetime     DEFAULT NULL,
            PRIMARY KEY (id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset};";

        dbDelta($sql);

        // Verificar que la tabla se creó correctamente
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $table
        )) === $table;

        if (!$table_exists) {
            // Fallback: crear tabla directamente
            $wpdb->query("CREATE TABLE IF NOT EXISTS {$table} (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                file_name varchar(255) NOT NULL,
                file_path varchar(500) DEFAULT NULL,
                s3_key varchar(500) DEFAULT NULL,
                s3_bucket varchar(255) DEFAULT NULL,
                s3_endpoint varchar(255) DEFAULT NULL,
                file_size bigint(20) DEFAULT 0,
                tables_list text DEFAULT NULL,
                rows_count bigint(20) DEFAULT 0,
                status varchar(50) DEFAULT 'pending',
                backup_type varchar(50) DEFAULT 'manual',
                error_msg text DEFAULT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                completed_at datetime DEFAULT NULL,
                PRIMARY KEY (id),
                KEY status (status),
                KEY created_at (created_at)
            ) {$charset}");
        }

        // Defaults
        $defaults = [
            'mbp_enabled'           => '1',
            'mbp_backup_frequency'  => 'daily',
            'mbp_backup_time'       => '02:00',
            'mbp_s3_endpoint'       => '',
            'mbp_s3_region'         => 'default',
            'mbp_s3_bucket'         => '',
            'mbp_s3_access_key'     => '',
            'mbp_s3_secret_key'     => '',
            'mbp_s3_path_style'     => '1',
            'mbp_s3_base_path'      => 'mysql-backups',
            'mbp_retention_count'   => '10',
            'mbp_compress_backup'   => '1',
            'mbp_notify_email'      => get_option('admin_email', ''),
        ];
        foreach ($defaults as $k => $v) {
            if (get_option($k) === false) {
                add_option($k, $v);
            }
        }

        // Directorio local protegido
        $dir = WP_CONTENT_DIR . '/mysql-backup-pro';
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        // Proteger con .htaccess (Apache) o index.php
        if (!file_exists($dir . '/.htaccess')) {
            file_put_contents($dir . '/.htaccess', "Options -Indexes\nDeny from all\n<FilesMatch \"\\.(sql|gz)$\">\nDeny from all\n</FilesMatch>\n");
        }
        if (!file_exists($dir . '/index.php')) {
            file_put_contents($dir . '/index.php', '<?php // Silence is golden');
        }

        Scheduler::reschedule();

        // Limpiar caché de rewrite rules
        flush_rewrite_rules();
    }
}
