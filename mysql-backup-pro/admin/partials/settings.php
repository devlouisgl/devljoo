<?php
if (!defined('ABSPATH')) exit;
$cfg = MBP\AdminMenu::all_settings();

$frequencies = [
    'hourly'      => 'Cada hora',
    'twicedaily'  => 'Dos veces al dia',
    'daily'       => 'Diario',
    'weekly'      => 'Semanal',
    'monthly'     => 'Mensual',
];
?>
<div class="wrap mbp-wrap mbp-settings">
    <h1>Configuracion</h1>

    <form id="mbp-settings-form">

        <!-- === GENERAL === -->
        <div class="mbp-section">
            <h2>Configuracion General</h2>
            <table class="form-table">
                <tr>
                    <th>Backups Automaticos</th>
                    <td>
                        <label class="mbp-toggle">
                            <input type="checkbox" name="mbp_enabled" value="1" <?php checked($cfg['enabled'], '1'); ?>>
                            <span class="mbp-toggle-slider"></span>
                        </label>
                        <p class="description">Activa o desactiva los backups automaticos.</p>
                    </td>
                </tr>
                <tr>
                    <th>Frecuencia</th>
                    <td>
                        <select name="mbp_backup_frequency" class="regular-text">
                            <?php foreach ($frequencies as $v => $l) : ?>
                                <option value="<?php echo esc_attr($v); ?>" <?php selected($cfg['frequency'], $v); ?>><?php echo esc_html($l); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Con que frecuencia se ejecutaran los backups automaticos.</p>
                    </td>
                </tr>
                <tr>
                    <th>Hora del Backup</th>
                    <td><input type="time" name="mbp_backup_time" value="<?php echo esc_attr($cfg['backup_time']); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th>Compresion Gzip</th>
                    <td>
                        <label class="mbp-toggle">
                            <input type="checkbox" name="mbp_compress_backup" value="1" <?php checked($cfg['compress'], '1'); ?>>
                            <span class="mbp-toggle-slider"></span>
                        </label>
                        <p class="description">Comprime los archivos SQL para reducir tamano.</p>
                    </td>
                </tr>
                <tr>
                    <th>Retencion (nº backups)</th>
                    <td>
                        <input type="number" name="mbp_retention_count" value="<?php echo esc_attr($cfg['retention']); ?>" min="1" max="100" class="small-text">
                        <p class="description">Numero maximo de backups a conservar. Los mas antiguos se eliminan automaticamente.</p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- === EMAIL NOTIFICATIONS === -->
        <div class="mbp-section mbp-section-highlight">
            <h2><span class="dashicons dashicons-email"></span> Notificaciones por Email</h2>

            <div class="mbp-notice mbp-notice-info">
                <strong>Importante:</strong> Muchos servidores requieren un plugin SMTP (como <strong>WP Mail SMTP</strong>) para enviar emails correctamente.
                <a href="https://wordpress.org/plugins/wp-mail-smtp/" target="_blank">Descargar WP Mail SMTP</a>
            </div>

            <table class="form-table">
                <tr>
                    <th>Email de Notificacion</th>
                    <td>
                        <input type="email" name="mbp_notify_email" value="<?php echo esc_attr($cfg['notify_email']); ?>" class="regular-text" placeholder="admin@tusitio.com">
                        <p class="description">Recibe notificaciones cuando un backup se complete o falle. Deja vacio para desactivar.</p>
                    </td>
                </tr>
                <tr>
                    <th>Probar Email</th>
                    <td>
                        <button type="button" id="mbp-test-email" class="button button-secondary">
                            <span class="dashicons dashicons-email-alt"></span> Enviar Email de Prueba
                        </button>
                        <span id="mbp-email-result" style="margin-left:12px;font-weight:600;"></span>
                        <p class="description">
                            <?php
                            $email = $cfg['notify_email'];
                            if (empty($email) || !is_email($email)) {
                                echo '<span style="color:#d63638;">⚠ No hay email configurado. Ingresa uno arriba y guarda.</span>';
                            } else {
                                echo 'Email configurado: <code>' . esc_html($email) . '</code>';
                            }
                            ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- === S3 / CONTABO === -->
        <div class="mbp-section mbp-section-highlight">
            <h2><span class="dashicons dashicons-cloud"></span> Configuracion S3 (Contabo / AWS / MinIO)</h2>

            <div class="mbp-notice mbp-notice-info">
                <strong>Para Contabo:</strong> Endpoint = <code>https://eu2.contabostorage.com</code> (ajusta la region).<br>
                <strong>Path Style</strong> debe estar <strong>activado</strong> para Contabo y MinIO.
            </div>

            <table class="form-table">
                <tr>
                    <th>Endpoint URL</th>
                    <td>
                        <input type="url" name="mbp_s3_endpoint" value="<?php echo esc_attr($cfg['s3_endpoint']); ?>" class="regular-text" placeholder="https://eu2.contabostorage.com">
                        <p class="description">URL del servicio S3. <strong>Contabo:</strong> <code>https://eu2.contabostorage.com</code></p>
                    </td>
                </tr>
                <tr>
                    <th>Region</th>
                    <td>
                        <input type="text" name="mbp_s3_region" value="<?php echo esc_attr($cfg['s3_region']); ?>" class="regular-text" placeholder="default">
                        <p class="description">Region del bucket. Contabo usa <code>default</code>.</p>
                    </td>
                </tr>
                <tr>
                    <th>Nombre del Bucket</th>
                    <td>
                        <input type="text" name="mbp_s3_bucket" value="<?php echo esc_attr($cfg['s3_bucket']); ?>" class="regular-text" placeholder="mi-bucket">
                    </td>
                </tr>
                <tr>
                    <th>Access Key</th>
                    <td>
                        <input type="password" name="mbp_s3_access_key" value="<?php echo esc_attr($cfg['s3_access_key']); ?>" class="regular-text" autocomplete="off" placeholder="Tu Access Key">
                        <?php if (!empty($cfg['s3_access_key'])) : ?>
                        <span class="mbp-badge mbp-badge-green" style="margin-left:8px;">Guardada</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Secret Key</th>
                    <td>
                        <input type="password" name="mbp_s3_secret_key" value="<?php echo esc_attr($cfg['s3_secret_key']); ?>" class="regular-text" autocomplete="off" placeholder="Tu Secret Key">
                        <?php if (!empty($cfg['s3_secret_key'])) : ?>
                        <span class="mbp-badge mbp-badge-green" style="margin-left:8px;">Guardada</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Path Style</th>
                    <td>
                        <label class="mbp-toggle">
                            <input type="checkbox" name="mbp_s3_path_style" value="1" <?php checked($cfg['s3_path_style'], '1'); ?>>
                            <span class="mbp-toggle-slider"></span>
                        </label>
                        <p class="description">Obligatorio para Contabo y MinIO. Desactivalo solo para AWS puro.</p>
                    </td>
                </tr>
                <tr>
                    <th>Carpeta Base en S3</th>
                    <td>
                        <input type="text" name="mbp_s3_base_path" value="<?php echo esc_attr($cfg['s3_base_path']); ?>" class="regular-text" placeholder="mysql-backups">
                        <p class="description">
                            Carpeta raiz donde se almacenaran los backups.
                            Se creara automaticamente la siguiente estructura dentro de esta carpeta:
                            <br><code><?php echo esc_html(parse_url(get_site_url(), PHP_URL_HOST) ?: 'tusitio.com'); ?>/<?php echo date('Y'); ?>/<?php echo date('m'); ?>/backup_<?php echo esc_html(parse_url(get_site_url(), PHP_URL_HOST) ?: 'tusitio'); ?>_<?php echo date('Y-m-d_H-i-s'); ?>.sql.gz</code>
                        </p>
                    </td>
                </tr>
            </table>

            <p>
                <button type="button" id="mbp-test-s3" class="button button-secondary">
                    <span class="dashicons dashicons-admin-site-alt3"></span> Probar Conexion S3
                </button>
                <span id="mbp-test-result" style="margin-left:12px;font-weight:600;"></span>
            </p>
        </div>

        <p class="submit">
            <button type="submit" class="button button-primary button-lg">
                <span class="dashicons dashicons-saved"></span> Guardar Configuracion
            </button>
        </p>
    </form>
</div>
