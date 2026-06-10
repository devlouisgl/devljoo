<?php
namespace MBP;

class AdminMenu
{
    private static ?self $instance = null;

    private function __construct()
    {
        add_action('admin_menu', [$this, 'register']);
        add_action('admin_notices', [$this, 'show_notices']);
    }

    public static function get_instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function register(): void
    {
        add_menu_page(
            'MySQL Backup Pro',
            'MySQL Backup Pro',
            'manage_options',
            'mysql-backup-pro',
            [$this, 'page_dashboard'],
            'dashicons-database',
            76
        );

        add_submenu_page('mysql-backup-pro', 'Dashboard', 'Dashboard',
            'manage_options', 'mysql-backup-pro', [$this, 'page_dashboard']);

        add_submenu_page('mysql-backup-pro', 'Backups', 'Backups',
            'manage_options', 'mysql-backup-pro-backups', [$this, 'page_backups']);

        add_submenu_page('mysql-backup-pro', 'S3 Explorer', 'S3 Explorer',
            'manage_options', 'mysql-backup-pro-s3', [$this, 'page_s3']);

        add_submenu_page('mysql-backup-pro', 'Configuracion', 'Configuracion',
            'manage_options', 'mysql-backup-pro-settings', [$this, 'page_settings']);

        add_submenu_page('mysql-backup-pro', 'WP Users (Test)', 'WP Users (Test)',
            'manage_options', 'mysql-backup-pro-users', [$this, 'page_users']);
    }

    public function page_dashboard(): void
    {
        include MBP_PLUGIN_DIR . 'admin/partials/dashboard.php';
    }
    public function page_backups(): void
    {
        include MBP_PLUGIN_DIR . 'admin/partials/backups.php';
    }
    public function page_s3(): void
    {
        include MBP_PLUGIN_DIR . 'admin/partials/s3-explorer.php';
    }
    public function page_settings(): void
    {
        include MBP_PLUGIN_DIR . 'admin/partials/settings.php';
    }
    public function page_users(): void
    {
        include MBP_PLUGIN_DIR . 'admin/partials/users.php';
    }

    /* ---------- Notices de advertencia ---------- */
    public function show_notices(): void
    {
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'mysql-backup-pro') === false) {
            return;
        }

        // Verificar tabla
        global $wpdb;
        $table_name = $wpdb->prefix . 'mbp_backups';
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name;

        if (!$table_exists) {
            echo '<div class="notice notice-error"><p><strong>MySQL Backup Pro Error:</strong> La tabla de backups no existe. Por favor <a href="' . wp_nonce_url(admin_url('plugins.php?action=deactivate&plugin=' . MBP_PLUGIN_BASENAME), 'deactivate-plugin_' . MBP_PLUGIN_BASENAME) . '">desactiva y reactiva el plugin</a>.</p></div>';
        }

        // Verificar directorio
        $dir = WP_CONTENT_DIR . '/mysql-backup-pro';
        if (!is_dir($dir) || !is_writable($dir)) {
            echo '<div class="notice notice-warning"><p><strong>MySQL Backup Pro Advertencia:</strong> El directorio <code>' . esc_html($dir) . '</code> no existe o no tiene permisos de escritura. Los backups pueden fallar.</p></div>';
        }

        // Verificar S3
        if (!S3Native::configured()) {
            echo '<div class="notice notice-warning is-dismissible"><p><strong>MySQL Backup Pro:</strong> S3 no esta configurado. Los backups se guardaran solo localmente. <a href="' . admin_url('admin.php?page=mysql-backup-pro-settings') . '">Configurar S3</a></p></div>';
        }

        // Verificar email
        $email = get_option('mbp_notify_email', get_option('admin_email', ''));
        if (empty($email) || !is_email($email)) {
            echo '<div class="notice notice-warning is-dismissible"><p><strong>MySQL Backup Pro:</strong> No hay email de notificacion configurado. <a href="' . admin_url('admin.php?page=mysql-backup-pro-settings') . '">Configurar email</a></p></div>';
        }
    }

    /* ---------- helpers para vistas ---------- */
    public static function all_settings(): array
    {
        return [
            'enabled'          => get_option('mbp_enabled', '1'),
            'frequency'        => get_option('mbp_backup_frequency', 'daily'),
            'backup_time'      => get_option('mbp_backup_time', '02:00'),
            's3_endpoint'      => get_option('mbp_s3_endpoint', ''),
            's3_region'        => get_option('mbp_s3_region', 'default'),
            's3_bucket'        => get_option('mbp_s3_bucket', ''),
            's3_access_key'    => Crypto::decrypt(get_option('mbp_s3_access_key', '')),
            's3_secret_key'    => Crypto::decrypt(get_option('mbp_s3_secret_key', '')),
            's3_path_style'    => get_option('mbp_s3_path_style', '1'),
            's3_base_path'     => get_option('mbp_s3_base_path', 'mysql-backups'),
            'retention'        => get_option('mbp_retention_count', '10'),
            'compress'         => get_option('mbp_compress_backup', '1'),
            'notify_email'     => get_option('mbp_notify_email', get_option('admin_email', '')),
        ];
    }

    public static function stats(): array
    {
        global $wpdb;
        $t = $wpdb->prefix . 'mbp_backups';

        // Verificar tabla
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t)) === $t;

        if (!$table_exists) {
            return [
                'total_backups' => 0,
                'successful'    => 0,
                'failed'        => 0,
                'total_size'    => '0 B',
                'last_backup'   => null,
                'db_name'       => DB_NAME,
                'db_size'       => '0 B',
                'table_count'   => 0,
                'next_backup'   => self::next_backup_human(),
            ];
        }

        $total  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t}");
        $ok     = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE status='completed'");
        $fail   = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE status='failed'");
        $size   = (int) $wpdb->get_var("SELECT COALESCE(SUM(file_size),0) FROM {$t} WHERE status='completed'");
        $last   = $wpdb->get_row("SELECT * FROM {$t} WHERE status='completed' ORDER BY completed_at DESC LIMIT 1");

        $db_name = DB_NAME;
        $db_size = (int) $wpdb->get_var("SELECT SUM(data_length+index_length) FROM information_schema.tables WHERE table_schema='{$db_name}'");
        $tbl_cnt = (int) $wpdb->get_var("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='{$db_name}' AND table_type='BASE TABLE'");

        return [
            'total_backups' => $total,
            'successful'    => $ok,
            'failed'        => $fail,
            'total_size'    => self::fmt_bytes($size),
            'last_backup'   => $last,
            'db_name'       => $db_name,
            'db_size'       => self::fmt_bytes($db_size),
            'table_count'   => $tbl_cnt,
            'next_backup'   => self::next_backup_human(),
        ];
    }

    public static function fmt_bytes(int $bytes, int $prec = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        if ($bytes <= 0) {
            return '0 B';
        }
        $pow = min((int) floor(log($bytes, 1024)), count($units) - 1);
        return round($bytes / (1024 ** $pow), $prec) . ' ' . $units[$pow];
    }

    private static function next_backup_human(): string
    {
        $ts = wp_next_scheduled('mbp_run_cron_backup');
        return $ts ? wp_date('Y-m-d H:i:s', $ts) : __('No programado', 'mysql-backup-pro');
    }
}
