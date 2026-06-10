<?php
if (!defined('ABSPATH')) exit;

$s3_configured = MBP\S3Native::configured();
$cfg = MBP\AdminMenu::all_settings();
?>
<div class="wrap mbp-wrap mbp-s3-explorer">
    <h1>
        <span class="dashicons dashicons-cloud"></span> S3 Explorer
        <?php if ($s3_configured) : ?>
        <span class="mbp-badge mbp-badge-green" style="font-size:0.6em;vertical-align:middle;margin-left:8px;">
            <?php echo esc_html($cfg['s3_bucket']); ?> @ <?php echo esc_html(parse_url($cfg['s3_endpoint'], PHP_URL_HOST)); ?>
        </span>
        <?php endif; ?>
    </h1>

    <?php if (!$s3_configured) : ?>
    <div class="mbp-notice mbp-notice-error">
        <p><strong>S3 no configurado.</strong> Ve a <a href="<?php echo admin_url('admin.php?page=mysql-backup-pro-settings'); ?>">Configuracion</a> para configurar tus credenciales S3.</p>
    </div>
    <?php else : ?>

    <!-- Toolbar principal -->
    <div class="mbp-s3-toolbar">
        <div class="mbp-s3-toolbar-row">
            <!-- Breadcrumb -->
            <div class="mbp-s3-breadcrumb" id="mbp-s3-breadcrumb">
                <button class="button button-small mbp-s3-nav-root" data-prefix="">
                    <span class="dashicons dashicons-cloud"></span> Raiz
                </button>
                <span id="mbp-s3-breadcrumb-path"></span>
            </div>

            <!-- Acciones -->
            <div class="mbp-s3-actions">
                <button id="mbp-s3-create-folder-btn" class="button button-secondary">
                    <span class="dashicons dashicons-plus"></span> Nueva Carpeta
                </button>
                <button id="mbp-s3-refresh" class="button button-secondary">
                    <span class="dashicons dashicons-update"></span> Actualizar
                </button>
            </div>
        </div>

        <div class="mbp-s3-toolbar-row mbp-s3-toolbar-filters">
            <!-- Buscador -->
            <div class="mbp-s3-search-box">
                <span class="dashicons dashicons-search"></span>
                <input type="text" id="mbp-s3-search" placeholder="Buscar archivos..." class="regular-text">
                <button id="mbp-s3-search-clear" class="button button-small" title="Limpiar busqueda" style="display:none;">
                    <span class="dashicons dashicons-no-alt"></span>
                </button>
            </div>

            <!-- Filtros -->
            <div class="mbp-s3-filter-group">
                <select id="mbp-s3-filter-ext" class="mbp-s3-select">
                    <option value="">Todos los archivos</option>
                    <option value=".sql">SQL (.sql)</option>
                    <option value=".gz">Gzip (.gz)</option>
                    <option value=".zip">ZIP (.zip)</option>
                    <option value=".tar">TAR (.tar)</option>
                    <option value=".log">Log (.log)</option>
                    <option value=".txt">Texto (.txt)</option>
                    <option value=".php">PHP (.php)</option>
                    <option value=".json">JSON (.json)</option>
                    <option value=".xml">XML (.xml)</option>
                    <option value=".jpg">Imagenes (.jpg/.jpeg)</option>
                    <option value=".png">PNG (.png)</option>
                </select>

                <select id="mbp-s3-per-page" class="mbp-s3-select">
                    <option value="25">25 / pag</option>
                    <option value="50" selected>50 / pag</option>
                    <option value="100">100 / pag</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Info de resultados -->
    <div class="mbp-s3-results-info" id="mbp-s3-results-info" style="display:none;">
        <span id="mbp-s3-results-text"></span>
        <span id="mbp-s3-search-badge" class="mbp-s3-search-badge" style="display:none;">
            <span class="dashicons dashicons-search"></span>
            Buscando: <strong id="mbp-s3-search-term"></strong>
            <a href="#" id="mbp-s3-clear-search-link">(limpiar)</a>
        </span>
    </div>

    <!-- Tabla de objetos -->
    <table class="widefat mbp-table" id="mbp-s3-table">
        <thead>
            <tr>
                <th width="40"></th>
                <th>Nombre</th>
                <th width="120">Tamano</th>
                <th width="180">Fecha</th>
                <th width="80">Acciones</th>
            </tr>
        </thead>
        <tbody id="mbp-s3-tbody">
            <tr>
                <td colspan="5" class="mbp-empty">Cargando...</td>
            </tr>
        </tbody>
    </table>

    <!-- Paginacion -->
    <div class="mbp-s3-pagination" id="mbp-s3-pagination" style="display:none;">
        <div class="mbp-s3-pagination-info">
            <span id="mbp-s3-page-info"></span>
        </div>
        <div class="mbp-s3-pagination-controls">
            <button class="button" id="mbp-s3-first-page" title="Primera pagina">
                <span class="dashicons dashicons-controls-skipback"></span>
            </button>
            <button class="button" id="mbp-s3-prev-page" title="Pagina anterior">
                <span class="dashicons dashicons-controls-back"></span>
            </button>
            <span class="mbp-s3-page-number" id="mbp-s3-current-page">1</span>
            <button class="button" id="mbp-s3-next-page" title="Pagina siguiente">
                <span class="dashicons dashicons-controls-forward"></span>
            </button>
        </div>
    </div>

    <div class="mbp-s3-info" style="margin-top:15px;color:#646970;font-size:.9em;">
        <span class="dashicons dashicons-info-outline" style="font-size:16px;width:16px;height:16px;vertical-align:middle;"></span>
        Navega por las carpetas de tu bucket S3. Usa la busqueda para encontrar archivos rapidamente. La paginacion se gestiona automaticamente para no sobrecargar el servidor.
    </div>

    <!-- Modal crear carpeta -->
    <div id="mbp-s3-folder-modal" style="display:none;">
        <div class="mbp-modal-bg"></div>
        <div class="mbp-modal-box mbp-modal-small">
            <h3>Crear Nueva Carpeta</h3>
            <p>
                <input type="text" id="mbp-s3-new-folder" class="regular-text" placeholder="nombre-de-carpeta" style="width:100%;">
            </p>
            <p>
                <button id="mbp-s3-folder-confirm" class="button button-primary">Crear</button>
                <button id="mbp-s3-folder-cancel" class="button button-secondary">Cancelar</button>
            </p>
        </div>
    </div>

    <?php endif; ?>
</div>
