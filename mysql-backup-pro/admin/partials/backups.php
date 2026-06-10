<?php
if (!defined('ABSPATH')) exit;

/** @var wpdb $wpdb */
global $wpdb;

// Verificar que la tabla existe antes de consultar
$table_name = $wpdb->prefix . 'mbp_backups';
$table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name;

$backups = [];
if ($table_exists) {
    $backups = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}mbp_backups ORDER BY created_at DESC LIMIT 100", ARRAY_A);
}

$s3_configured = MBP\S3Native::configured();
?>
<div class="wrap mbp-wrap">
    <h1>Backups Realizados</h1>

    <p>
        <button id="mbp-run-backup" class="button button-primary">
            <span class="dashicons dashicons-cloud-upload"></span> Ejecutar Backup Ahora
        </button>
        <button id="mbp-refresh-list" class="button button-secondary">
            <span class="dashicons dashicons-update"></span> Actualizar Lista
        </button>
    </p>

    <?php if (!$table_exists) : ?>
    <div class="mbp-notice mbp-notice-error">
        <strong>Error:</strong> La tabla de backups no existe. Por favor desactiva y reactiva el plugin.
    </div>
    <?php endif; ?>

    <table class="widefat mbp-table" id="mbp-backups-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Archivo</th>
                <th>Tamano</th>
                <th>Tablas</th>
                <th>Filas</th>
                <th>Tipo</th>
                <th>Estado</th>
                <th>S3</th>
                <th>Fecha</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody id="mbp-backups-tbody">
            <?php if ($backups) : foreach ($backups as $b) :
                $tables = $b['tables_list'] ? count(explode(', ', $b['tables_list'])) : 0;
                $has_s3 = !empty($b['s3_key']);
                $has_file = !empty($b['file_path']) && file_exists($b['file_path']);
            ?>
            <tr data-id="<?php echo (int)$b['id']; ?>">
                <td><?php echo (int)$b['id']; ?></td>
                <td><code><?php echo esc_html($b['file_name']); ?></code></td>
                <td><?php echo MBP\AdminMenu::fmt_bytes((int)$b['file_size']); ?></td>
                <td><?php echo $tables; ?></td>
                <td><?php echo number_format((int)$b['rows_count']); ?></td>
                <td><span class="mbp-type mbp-type-<?php echo esc_attr($b['backup_type']); ?>"><?php echo esc_html($b['backup_type']); ?></span></td>
                <td>
                    <?php if ($b['status'] === 'completed') : ?>
                        <span class="mbp-badge mbp-badge-green">OK</span>
                    <?php elseif ($b['status'] === 'failed') : ?>
                        <span class="mbp-badge mbp-badge-red" title="<?php echo esc_attr($b['error_msg'] ?? ''); ?>">Fallido</span>
                    <?php else : ?>
                        <span class="mbp-badge mbp-badge-orange">Pendiente</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($has_s3) : ?>
                        <span class="mbp-s3-badge mbp-s3-yes" title="<?php echo esc_attr($b['s3_key']); ?>">
                            <span class="dashicons dashicons-cloud"></span> S3
                        </span>
                    <?php elseif ($s3_configured && $has_file) : ?>
                        <span class="mbp-s3-badge mbp-s3-no">
                            <span class="dashicons dashicons-cloud"></span> Local
                        </span>
                    <?php else : ?>
                        <span class="mbp-s3-badge mbp-s3-none">
                            <span class="dashicons dashicons-minus"></span>
                        </span>
                    <?php endif; ?>
                </td>
                <td><?php echo esc_html($b['completed_at'] ?: $b['created_at']); ?></td>
                <td class="mbp-actions">
                    <?php if ($has_file || $has_s3) : ?>
                    <button class="button mbp-btn-download" data-id="<?php echo (int)$b['id']; ?>" title="Descargar">
                        <span class="dashicons dashicons-download"></span>
                    </button>
                    <?php endif; ?>
                    <?php if (!$has_s3 && $s3_configured && $has_file) : ?>
                    <button class="button mbp-btn-upload-s3" data-id="<?php echo (int)$b['id']; ?>" title="Subir a S3">
                        <span class="dashicons dashicons-cloud-upload"></span>
                    </button>
                    <?php endif; ?>
                    <button class="button mbp-btn-delete" data-id="<?php echo (int)$b['id']; ?>" title="Eliminar">
                        <span class="dashicons dashicons-trash"></span>
                    </button>
                </td>
            </tr>
            <?php endforeach; else: ?>
            <tr id="mbp-no-backups">
                <td colspan="10" class="mbp-empty">No hay backups registrados. Haz clic en "Ejecutar Backup Ahora" para crear el primero.</td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Modal de progreso -->
    <div id="mbp-modal" style="display:none;">
        <div class="mbp-modal-bg"></div>
        <div class="mbp-modal-box">
            <h3 id="mbp-modal-title">Ejecutando Backup...</h3>
            <div class="mbp-progress"><div class="mbp-progress-bar"></div></div>
            <p id="mbp-modal-msg">Por favor espere...</p>
        </div>
    </div>
</div>
