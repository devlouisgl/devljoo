<?php
/**
 * Plugin Name: MySQL Backup Pro
 * Plugin URI:  https://github.com/gestlifedev/plugin-wp-backups
 * Description: Plugin para respaldos automaticos de MySQL a S3 (Contabo/AWS/MinIO). Incluye panel de administracion, configuracion flexible, tablero de pruebas, notificaciones por email y gestion de carpetas S3.
 * Version:     2.0
 * Author:      Louis Jhosimar Ocampo | GestLife Dev
 * License:     GPL v2 or later
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MBP_VERSION', '2.2.0');
define('MBP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MBP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MBP_PLUGIN_BASENAME', plugin_basename(__FILE__));

/* ============================================================
   AUTOLOADER
   ============================================================ */
spl_autoload_register(static function (string $class): void {
    $prefix = 'MBP\\';
    $base_dir = MBP_PLUGIN_DIR . 'includes/';

    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $relative = strtolower(str_replace('\\', '-', substr($class, strlen($prefix))));
    $file = $base_dir . 'class-' . $relative . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

/* ============================================================
   CLASE PRINCIPAL
   ============================================================ */
final class MySQL_Backup_Pro
{
    private static ?self $instance = null;

    private function __construct()
    {
        register_activation_hook(__FILE__, [MBP\Activator::class, 'activate']);
        register_deactivation_hook(__FILE__, [MBP\Deactivator::class, 'deactivate']);

        add_action('plugins_loaded', [$this, 'init'], 10);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        // AJAX handlers
        $this->register_ajax('mbp_run_backup',      [MBP\Backup::class, 'ajax_run_backup']);
        $this->register_ajax('mbp_delete_backup',   [MBP\Backup::class, 'ajax_delete_backup']);
        $this->register_ajax('mbp_download_backup', [MBP\Backup::class, 'ajax_download_backup']);
        $this->register_ajax('mbp_upload_to_s3',    [$this, 'ajax_upload_to_s3']);
        $this->register_ajax('mbp_test_s3',         [MBP\S3Native::class, 'ajax_test_connection']);
        $this->register_ajax('mbp_s3_list_objects', [$this, 'ajax_s3_list_objects']);
        $this->register_ajax('mbp_s3_create_folder',[$this, 'ajax_s3_create_folder']);
        $this->register_ajax('mbp_s3_delete_object',[$this, 'ajax_s3_delete_object']);
        $this->register_ajax('mbp_save_settings',   [$this, 'ajax_save_settings']);
        $this->register_ajax('mbp_get_backups',     [$this, 'ajax_get_backups']);
        $this->register_ajax('mbp_test_email',      [$this, 'ajax_test_email']);
    }

    public static function get_instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(): void
    {
        if (is_admin()) {
            MBP\AdminMenu::get_instance();
        }
        MBP\Scheduler::get_instance();
    }

    public function enqueue_assets(string $hook): void
    {
        if (strpos($hook, 'mysql-backup-pro') === false) {
            return;
        }

        wp_enqueue_style('mbp-admin-css', MBP_PLUGIN_URL . 'admin/css/admin-style.css', [], MBP_VERSION);
        wp_enqueue_script('mbp-admin-js',  MBP_PLUGIN_URL . 'admin/js/admin-script.js',  ['jquery'], MBP_VERSION, true);

        wp_localize_script('mbp-admin-js', 'mbp_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('mbp_nonce_action'),
            'strings'  => [
                'confirmDelete'      => __('Eliminar este backup permanentemente?', 'mysql-backup-pro'),
                'confirmBackup'      => __('Ejecutar backup ahora? Puede tardar varios minutos.', 'mysql-backup-pro'),
                'confirmUploadS3'    => __('Subir este backup a S3 ahora?', 'mysql-backup-pro'),
                'confirmDeleteS3'    => __('Eliminar este objeto de S3 permanentemente?', 'mysql-backup-pro'),
                'confirmDeleteFolder'=> __('Eliminar esta carpeta y TODO su contenido de S3?', 'mysql-backup-pro'),
                'backupStarted'      => __('Creando respaldo, por favor espere...', 'mysql-backup-pro'),
                'backupOk'           => __('Respaldo completado exitosamente!', 'mysql-backup-pro'),
                'backupErr'          => __('Error al realizar el respaldo', 'mysql-backup-pro'),
                'uploadS3Started'    => __('Subiendo a S3, por favor espere...', 'mysql-backup-pro'),
                'uploadS3Ok'         => __('Subido a S3 exitosamente!', 'mysql-backup-pro'),
                'uploadS3Err'        => __('Error al subir a S3', 'mysql-backup-pro'),
                'folderOk'           => __('Carpeta creada exitosamente!', 'mysql-backup-pro'),
                'folderErr'          => __('Error al crear carpeta', 'mysql-backup-pro'),
                'deleteS3Ok'         => __('Eliminado de S3 exitosamente!', 'mysql-backup-pro'),
                'deleteS3Err'        => __('Error al eliminar de S3', 'mysql-backup-pro'),
                'saved'              => __('Configuracion guardada.', 'mysql-backup-pro'),
                'connOk'             => __('Conexion S3 exitosa!', 'mysql-backup-pro'),
                'connErr'            => __('Error de conexion S3', 'mysql-backup-pro'),
                'emailOk'            => __('Email de prueba enviado!', 'mysql-backup-pro'),
                'emailErr'           => __('Error al enviar email', 'mysql-backup-pro'),
            ],
        ]);
    }

    private function register_ajax(string $action, callable $callback): void
    {
        add_action("wp_ajax_{$action}", static function () use ($callback): void {
            check_ajax_referer('mbp_nonce_action', 'nonce');
            if (!current_user_can('manage_options')) {
                wp_send_json_error(__('Permisos insuficientes.', 'mysql-backup-pro'));
            }
            $callback();
        });
    }

    /* ---------- Helpers de caché para S3 Explorer ---------- */

    /**
     * Generar clave de caché para listados S3
     */
    private function s3_cache_key(string $prefix, string $search, string $filter_ext, int $per_page): string
    {
        $bucket = get_option('mbp_s3_bucket', 'default');
        $token_key = 'mbp_s3ct_' . md5("{$bucket}|{$prefix}|{$search}|{$filter_ext}|{$per_page}");
        return $token_key;
    }

    /**
     * Limpiar caché de tokens de paginación S3
     */
    private function s3_clear_cache(string $prefix = ''): void
    {
        global $wpdb;
        // Limpiar todos los transients que empiecen con mbp_s3ct_
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_mbp_s3ct_%' OR option_name LIKE '_transient_timeout_mbp_s3ct_%'");
        // También limpiar caché de páginas específicas
        if ($prefix !== '') {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                '_transient_mbp_s3page_%',
                '_transient_timeout_mbp_s3page_%'
            ));
        }
    }

    /* ---------- AJAX: Guardar configuracion ---------- */
    public function ajax_save_settings(): void
    {
        $fields = [
            'mbp_enabled', 'mbp_backup_frequency', 'mbp_backup_time',
            'mbp_s3_endpoint', 'mbp_s3_region', 'mbp_s3_bucket',
            'mbp_s3_access_key', 'mbp_s3_secret_key', 'mbp_s3_path_style',
            'mbp_s3_base_path', 'mbp_retention_count', 'mbp_compress_backup', 'mbp_notify_email',
        ];

        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                $value = sanitize_text_field(wp_unslash($_POST[$field]));
                // Encriptar credenciales
                if (in_array($field, ['mbp_s3_access_key', 'mbp_s3_secret_key'], true)) {
                    $value = MBP\Crypto::encrypt($value);
                }
                update_option($field, $value);
            }
        }

        // Reprogramar cron
        MBP\Scheduler::reschedule();

        wp_send_json_success(['message' => __('Configuracion guardada correctamente.', 'mysql-backup-pro')]);
    }

    /* ---------- AJAX: Listar backups ---------- */
    public function ajax_get_backups(): void
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mbp_backups';
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $table_name
        )) === $table_name;

        if (!$table_exists) {
            wp_send_json_success(['backups' => [], 'warning' => 'La tabla de backups no existe. Desactiva y reactiva el plugin.']);
            return;
        }

        $rows = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}mbp_backups ORDER BY created_at DESC LIMIT 100",
            ARRAY_A
        );
        wp_send_json_success(['backups' => $rows ?: []]);
    }

    /* ---------- AJAX: Subir backup existente a S3 ---------- */
    public function ajax_upload_to_s3(): void
    {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if (!$id) {
            wp_send_json_error('ID de backup no valido');
        }

        if (!MBP\S3Native::configured()) {
            wp_send_json_error('S3 no esta configurado. Ve a Configuracion y configura tus credenciales S3.');
        }

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}mbp_backups WHERE id=%d", $id), ARRAY_A);
        if (!$row) {
            wp_send_json_error('Backup no encontrado');
        }

        $file_path = $row['file_path'] ?? '';
        if (!$file_path || !file_exists($file_path)) {
            wp_send_json_error('Archivo local no encontrado. Puede haber sido eliminado.');
        }

        $s3 = new MBP\S3Native();
        $key = MBP\Backup::generate_s3_key(basename($file_path));
        $up = $s3->upload($file_path, $key);

        if ($up['success']) {
            $wpdb->update(
                $wpdb->prefix . 'mbp_backups',
                [
                    's3_key'      => $up['key'],
                    's3_bucket'   => get_option('mbp_s3_bucket'),
                    's3_endpoint' => get_option('mbp_s3_endpoint'),
                ],
                ['id' => $id],
                ['%s', '%s', '%s'],
                ['%d']
            );

            // Invalidar caché del S3 Explorer
            $this->s3_clear_cache();

            wp_send_json_success([
                'message' => 'Archivo subido a S3 exitosamente',
                's3_key'  => $up['key'],
                'bucket'  => $up['bucket'],
            ]);
        } else {
            wp_send_json_error('Error S3: ' . ($up['message'] ?? 'Error desconocido'));
        }
    }

    /* ---------- AJAX: Listar objetos en S3 con paginacion, busqueda y filtros ---------- */
    public function ajax_s3_list_objects(): void
    {
        if (!MBP\S3Native::configured()) {
            wp_send_json_error('S3 no esta configurado.');
        }

        // Parámetros de la petición
        $prefix = isset($_POST['prefix']) ? sanitize_text_field(wp_unslash($_POST['prefix'])) : '';
        $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
        $filter_ext = isset($_POST['filter_ext']) ? sanitize_text_field(wp_unslash($_POST['filter_ext'])) : '';
        $page = isset($_POST['page']) ? max(1, (int) $_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? min(max((int) $_POST['per_page'], 10), 200) : 50;
        $requested_token = isset($_POST['continuation_token']) ? sanitize_text_field(wp_unslash($_POST['continuation_token'])) : '';

        $s3 = new MBP\S3Native();

        // CASO 1: Busqueda activa
        if ($search !== '') {
            $result = $s3->search_objects($search, $prefix, $per_page * 2, $filter_ext);

            if (!$result['success']) {
                wp_send_json_error($result['message']);
            }

            // Calcular paginacion manual para resultados de busqueda
            $all_items = array_merge(
                array_map(fn($f) => array_merge($f, ['type' => 'folder']), $result['folders']),
                array_map(fn($f) => array_merge($f, ['type' => 'file']), $result['files'])
            );

            $total_items = count($all_items);
            $total_pages = max(1, ceil($total_items / $per_page));
            $page = min($page, $total_pages);
            $offset = ($page - 1) * $per_page;
            $paged_items = array_slice($all_items, $offset, $per_page);

            $folders = array_filter($paged_items, fn($i) => $i['type'] === 'folder');
            $files = array_filter($paged_items, fn($i) => $i['type'] === 'file');

            wp_send_json_success([
                'folders'           => array_values(array_map(fn($f) => ['name' => $f['name'], 'prefix' => $f['prefix']], $folders)),
                'files'             => array_values(array_map(fn($f) => ['name' => $f['name'], 'key' => $f['key'], 'size' => $f['size'], 'modified' => $f['modified']], $files)),
                'prefix'            => $prefix,
                'search'            => $search,
                'filter_ext'        => $filter_ext,
                'page'              => $page,
                'per_page'          => $per_page,
                'total_pages'       => $total_pages,
                'total_items'       => $total_items,
                'has_next'          => $page < $total_pages,
                'has_prev'          => $page > 1,
                'is_search'         => true,
                'continuation_token'=> '',
            ]);
            return;
        }

        // CASO 2: Navegacion normal con paginacion via continuation-token
        // Usamos caché de transients para mapear page => continuation_token
        $cache_key = $this->s3_cache_key($prefix, '', $filter_ext, $per_page);
        $token_cache = get_transient($cache_key) ?: [];

        // Determinar el continuation token para esta pagina
        $continuation_token = '';
        if ($page > 1) {
            if (isset($token_cache[$page])) {
                $continuation_token = $token_cache[$page];
            } elseif (isset($token_cache['last_page']) && $token_cache['last_page'] >= $page - 1) {
                // Necesitamos navegar secuencialmente - el cliente debe manejar esto
                // Devolvemos lo que tenemos y pedimos al cliente que navegue pagina por pagina
                $continuation_token = $token_cache[$page - 1] ?? '';
            }
        }

        // Si el cliente envio un token explicito, usarlo (navegacion directa)
        if ($requested_token !== '') {
            $continuation_token = $requested_token;
        }

        // Realizar la peticion a S3
        $result = $s3->list_objects($prefix, '/', $per_page, $continuation_token);

        if (!$result['success']) {
            wp_send_json_error($result['message']);
        }

        // Aplicar filtro por extension
        $files = $result['files'];
        if ($filter_ext !== '') {
            $files = array_filter($files, fn($f) => str_ends_with(strtolower($f['name']), strtolower($filter_ext)));
            $files = array_values($files);
        }

        // Guardar el token para la siguiente pagina en caché
        if ($result['is_truncated'] && !empty($result['next_token'])) {
            $next_page = $page + 1;
            if (!isset($token_cache[$next_page])) {
                $token_cache[$next_page] = $result['next_token'];
                $token_cache['last_page'] = max($token_cache['last_page'] ?? 1, $page);
                set_transient($cache_key, $token_cache, 30); // Cache 30 segundos
            }
        }

        // Calcular total estimado
        $total_items = $result['key_count'];
        $has_next = $result['is_truncated'] || false;

        wp_send_json_success([
            'folders'           => $result['folders'],
            'files'             => $files,
            'prefix'            => $prefix,
            'search'            => '',
            'filter_ext'        => $filter_ext,
            'page'              => $page,
            'per_page'          => $per_page,
            'total_items'       => $total_items,
            'has_next'          => $has_next,
            'has_prev'          => $page > 1,
            'is_search'         => false,
            'continuation_token'=> $result['next_token'] ?? '',
            'key_count_s3'      => $result['key_count'],
            'max_keys_s3'       => $result['max_keys'],
        ]);
    }

    /* ---------- AJAX: Crear carpeta en S3 ---------- */
    public function ajax_s3_create_folder(): void
    {
        if (!MBP\S3Native::configured()) {
            wp_send_json_error('S3 no esta configurado.');
        }

        $folder = isset($_POST['folder']) ? sanitize_text_field(wp_unslash($_POST['folder'])) : '';
        $prefix = isset($_POST['prefix']) ? sanitize_text_field(wp_unslash($_POST['prefix'])) : '';

        if (empty($folder)) {
            wp_send_json_error('El nombre de la carpeta no puede estar vacio.');
        }

        // Sanitizar nombre de carpeta
        $folder = preg_replace('/[^a-zA-Z0-9_-]/', '-', $folder);
        $full_path = $prefix . $folder;

        $s3 = new MBP\S3Native();
        $result = $s3->create_folder($full_path);

        if ($result['success']) {
            // Invalidar caché
            $this->s3_clear_cache($prefix);
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    /* ---------- AJAX: Eliminar objeto de S3 ---------- */
    public function ajax_s3_delete_object(): void
    {
        if (!MBP\S3Native::configured()) {
            wp_send_json_error('S3 no esta configurado.');
        }

        $key = isset($_POST['key']) ? sanitize_text_field(wp_unslash($_POST['key'])) : '';
        if (empty($key)) {
            wp_send_json_error('Key no valida.');
        }

        $s3 = new MBP\S3Native();
        $result = $s3->delete($key);

        if ($result['success']) {
            // Invalidar caché
            $this->s3_clear_cache();
            wp_send_json_success(['message' => 'Objeto eliminado de S3.']);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    /* ---------- AJAX: Probar email ---------- */
    public function ajax_test_email(): void
    {
        $email = get_option('mbp_notify_email', get_option('admin_email', ''));
        if (empty($email) || !is_email($email)) {
            wp_send_json_error('No hay un email de notificacion configurado. Configuralo primero en la pestana de Configuracion.');
        }

        $site = get_bloginfo('name');
        $subject = "[{$site}] Prueba de notificacion - MySQL Backup Pro";
        $body = "Este es un email de prueba desde MySQL Backup Pro.\n\n";
        $body .= "Si recibes este mensaje, la configuracion de email esta funcionando correctamente.\n\n";
        $body .= "Fecha: " . wp_date('Y-m-d H:i:s') . "\n";
        $body .= "Sitio: " . home_url() . "\n";
        $body .= "---\nMySQL Backup Pro";

        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        $sent = wp_mail($email, $subject, $body, $headers);

        if ($sent) {
            wp_send_json_success(['message' => "Email de prueba enviado a {$email}. Revisa tu bandeja de entrada (y spam)."]);
        } else {
            wp_send_json_error('No se pudo enviar el email. Asegurate de tener configurado un plugin SMTP como WP Mail SMTP, o que tu servidor permita envio de correos.');
        }
    }
}

MySQL_Backup_Pro::get_instance();

/* ============================================================
   DESCARGA LOCAL DE ARCHIVOS  (admin-post.php)
   ============================================================ */
add_action('admin_post_mbp_download_file', static function (): void {
    if (!current_user_can('manage_options')) {
        wp_die('Acceso denegado');
    }
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'mbp_dl_' . $id)) {
        wp_die('Nonce invalido');
    }
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}mbp_backups WHERE id=%d", $id), ARRAY_A);
    if (!$row || empty($row['file_path']) || !file_exists($row['file_path'])) {
        wp_die('Archivo no encontrado');
    }
    $file = $row['file_path'];
    $filename = basename($file);
    $mime = (str_ends_with($file, '.gz')) ? 'application/gzip' : 'application/sql';

    header('Content-Description: File Transfer');
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: no-cache');
    readfile($file);
    exit;
});