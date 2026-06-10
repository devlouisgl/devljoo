<?php
if (!defined('ABSPATH')) exit;
$s = MBP\AdminMenu::stats();
$cfg = MBP\AdminMenu::all_settings();
?>
<div class="wrap mbp-wrap">
    <h1>MySQL Backup Pro - Dashboard</h1>

    <div class="mbp-grid">
        <div class="mbp-card mbp-card-blue">
            <span class="dashicons dashicons-database"></span>
            <div><h2><?php echo (int)$s['total_backups']; ?></h2><p>Total Backups</p></div>
        </div>
        <div class="mbp-card mbp-card-green">
            <span class="dashicons dashicons-yes-alt"></span>
            <div><h2><?php echo (int)$s['successful']; ?></h2><p>Exitosos</p></div>
        </div>
        <div class="mbp-card mbp-card-red">
            <span class="dashicons dashicons-warning"></span>
            <div><h2><?php echo (int)$s['failed']; ?></h2><p>Fallidos</p></div>
        </div>
        <div class="mbp-card mbp-card-orange">
            <span class="dashicons dashicons-media-document"></span>
            <div><h2><?php echo esc_html($s['total_size']); ?></h2><p>Tamano Total</p></div>
        </div>
    </div>

    <div class="mbp-section">
        <h2>Informacion de la Base de Datos</h2>
        <table class="widefat mbp-table">
            <tr><th width="200">Base de Datos</th><td><code><?php echo esc_html($s['db_name']); ?></code></td></tr>
            <tr><th>Tamano</th><td><?php echo esc_html($s['db_size']); ?></td></tr>
            <tr><th>Tablas</th><td><?php echo (int)$s['table_count']; ?></td></tr>
            <tr><th>Proximo Backup</th><td><?php echo esc_html($s['next_backup']); ?></td></tr>
            <tr><th>Backups Automaticos</th>
                <td><?php echo $cfg['enabled']==='1'
                    ? '<span class="mbp-badge mbp-badge-green">Activado</span>'
                    : '<span class="mbp-badge mbp-badge-red">Desactivado</span>'; ?></td>
            </tr>
            <tr><th>Frecuencia</th><td><?php echo esc_html(ucfirst($cfg['frequency'])); ?></td></tr>
            <tr><th>Bucket S3</th>
                <td><?php echo MBP\S3Native::configured()
                    ? '<span class="mbp-badge mbp-badge-green">Configurado</span> ('.esc_html($cfg['s3_bucket']).')'
                    : '<span class="mbp-badge mbp-badge-gray">No configurado</span>'; ?></td>
            </tr>
        </table>
    </div>

    <div class="mbp-section">
        <h2>Ultimo Backup</h2>
        <?php if ($s['last_backup']) : $lb = $s['last_backup']; ?>
        <table class="widefat mbp-table">
            <tr><th width="200">Archivo</th><td><code><?php echo esc_html($lb->file_name); ?></code></td></tr>
            <tr><th>Tamano</th><td><?php echo MBP\AdminMenu::fmt_bytes((int)$lb->file_size); ?></td></tr>
            <tr><th>Estado</th><td><span class="mbp-badge mbp-badge-<?php echo $lb->status==='completed'?'green':'red'; ?>"><?php echo esc_html(ucfirst($lb->status)); ?></span></td></tr>
            <tr><th>Fecha</th><td><?php echo esc_html($lb->completed_at ?: $lb->created_at); ?></td></tr>
            <?php if (!empty($lb->s3_key)) : ?>
            <tr><th>S3</th><td><span class="mbp-s3-badge mbp-s3-yes"><span class="dashicons dashicons-cloud"></span> Subido a S3</span></td></tr>
            <?php endif; ?>
        </table>
        <?php else: ?>
            <p class="mbp-empty">Aun no hay backups. <a href="<?php echo admin_url('admin.php?page=mysql-backup-pro-backups'); ?>">Realizar el primero</a>.</p>
        <?php endif; ?>
    </div>

    <div class="mbp-section">
        <h2>Acciones Rapidas</h2>
        <p>
            <a href="<?php echo admin_url('admin.php?page=mysql-backup-pro-backups'); ?>" class="button button-primary button-lg">
                <span class="dashicons dashicons-cloud-upload"></span> Ejecutar Backup Ahora
            </a>
            <a href="<?php echo admin_url('admin.php?page=mysql-backup-pro-settings'); ?>" class="button button-secondary">
                <span class="dashicons dashicons-admin-generic"></span> Configuracion
            </a>
            <a href="<?php echo admin_url('admin.php?page=mysql-backup-pro-s3'); ?>" class="button button-secondary">
                <span class="dashicons dashicons-cloud"></span> S3 Explorer
            </a>
            <a href="<?php echo admin_url('admin.php?page=mysql-backup-pro-backups'); ?>" class="button button-secondary">
                <span class="dashicons dashicons-list-view"></span> Ver Backups
            </a>
        </p>
    </div>
</div>
