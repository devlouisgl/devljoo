/**
 * MySQL Backup Pro - Admin JS v2.2
 * S3 Explorer con paginacion, busqueda y filtros
 */
(function($) {

    // Estado global del S3 Explorer
    var s3State = {
        prefix: '',
        page: 1,
        per_page: 50,
        search: '',
        filter_ext: '',
        continuation_token: '',
        is_search: false,
        loading: false
    };

    // Cache en memoria para paginas ya visitadas: clave = "prefix|page|search|filter"
    var s3Cache = {};
    var searchDebounceTimer = null;

    $(function() {
        initBackup();
        initUploadS3();
        initDownloads();
        initDeletes();
        initSettings();
        initTestS3();
        initTestEmail();
        initSearch();
        initRefresh();
        initS3Explorer();
    });

    /* ===== UTILS ===== */
    function showModal(title, msg) {
        var $modal = $('#mbp-modal').show();
        if (title) $('#mbp-modal-title').text(title);
        if (msg) $('#mbp-modal-msg').text(msg);
        $modal.find('.mbp-progress-bar').removeClass('success error').addClass('active');
        return $modal;
    }

    function hideModal(success, msg) {
        var $modal = $('#mbp-modal');
        var $bar = $modal.find('.mbp-progress-bar').removeClass('active');
        if (success) {
            $bar.addClass('success');
        } else {
            $bar.addClass('error');
        }
        if (msg) $('#mbp-modal-msg').text(msg);
        setTimeout(function() { $modal.hide(); }, success ? 600 : 1200);
    }

    function fmtBytes(bytes) {
        if (bytes <= 0) return '-';
        var units = ['B','KB','MB','GB','TB'];
        var pow = Math.floor(Math.log(bytes) / Math.log(1024));
        pow = Math.min(pow, units.length - 1);
        return (bytes / Math.pow(1024, pow)).toFixed(2) + ' ' + units[pow];
    }

    function fmtDate(dateStr) {
        if (!dateStr || dateStr === '-') return '-';
        try {
            var d = new Date(dateStr);
            if (isNaN(d.getTime())) return dateStr;
            return d.toLocaleString('es-ES', {
                year: 'numeric', month: '2-digit', day: '2-digit',
                hour: '2-digit', minute: '2-digit'
            });
        } catch(e) {
            return dateStr;
        }
    }

    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function numberFormat(n) {
        return parseInt(n || 0).toLocaleString();
    }

    function cacheKey(state) {
        return state.prefix + '|' + state.page + '|' + state.search + '|' + state.filter_ext + '|' + state.per_page;
    }

    /* ===== EJECUTAR BACKUP ===== */
    function initBackup() {
        $(document).on('click', '#mbp-run-backup', function(e) {
            e.preventDefault();
            if (!confirm(mbp_ajax.strings.confirmBackup)) return;

            showModal('Ejecutando Backup...', mbp_ajax.strings.backupStarted);

            $.post(mbp_ajax.ajax_url, {
                action: 'mbp_run_backup',
                nonce : mbp_ajax.nonce,
            }, function(res) {
                if (res.success) {
                    hideModal(true, mbp_ajax.strings.backupOk);
                    var d = res.data;
                    var s3Msg = d.s3 ? 'S3: Si' : (d.s3_error ? 'S3: Fallo - ' + d.s3_error : 'S3: No configurado');
                    setTimeout(function() {
                        alert(mbp_ajax.strings.backupOk + '\n\nArchivo: ' + d.filename + '\nTamano: ' + d.file_size + '\n' + s3Msg + '\nDuracion: ' + d.duration + 's');
                        refreshBackupsList();
                        if ($('.mbp-wrap h1').text().indexOf('Dashboard') > -1) {
                            setTimeout(function() { location.reload(); }, 500);
                        }
                    }, 300);
                } else {
                    hideModal(false, mbp_ajax.strings.backupErr);
                    alert(mbp_ajax.strings.backupErr + '\n' + (res.data || ''));
                }
            }).fail(function() {
                hideModal(false, 'Error de comunicacion');
                alert('Error de comunicacion con el servidor.');
            });
        });
    }

    /* ===== SUBIR A S3 MANUALMENTE ===== */
    function initUploadS3() {
        $(document).on('click', '.mbp-btn-upload-s3', function(e) {
            e.preventDefault();
            var id = $(this).data('id');
            if (!confirm(mbp_ajax.strings.confirmUploadS3)) return;

            var $btn = $(this).prop('disabled', true);
            var $row = $('tr[data-id="' + id + '"]');

            showModal('Subiendo a S3...', mbp_ajax.strings.uploadS3Started);

            $.post(mbp_ajax.ajax_url, {
                action: 'mbp_upload_to_s3',
                nonce : mbp_ajax.nonce,
                id    : id,
            }, function(res) {
                hideModal(res.success, res.success ? mbp_ajax.strings.uploadS3Ok : mbp_ajax.strings.uploadS3Err);
                if (res.success) {
                    $row.find('.mbp-s3-no, .mbp-s3-none').replaceWith(
                        '<span class="mbp-s3-badge mbp-s3-yes"><span class="dashicons dashicons-cloud"></span> S3</span>'
                    );
                    $btn.fadeOut(300, function() { $(this).remove(); });
                    alert(mbp_ajax.strings.uploadS3Ok + '\n\nArchivo subido a:\n' + (res.data.s3_key || ''));
                } else {
                    alert(mbp_ajax.strings.uploadS3Err + '\n' + (res.data || 'Error desconocido'));
                }
            }).fail(function() {
                hideModal(false, 'Error de comunicacion');
                alert('Error de comunicacion con S3.');
            }).always(function() {
                $btn.prop('disabled', false);
            });
        });
    }

    /* ===== DESCARGAR ===== */
    function initDownloads() {
        $(document).on('click', '.mbp-btn-download', function(e) {
            e.preventDefault();
            var id = $(this).data('id');
            var $btn = $(this).prop('disabled', true);

            $.post(mbp_ajax.ajax_url, {
                action: 'mbp_download_backup',
                nonce : mbp_ajax.nonce,
                id    : id,
            }, function(res) {
                if (res.success) {
                    var d = res.data;
                    if (d.type === 's3') {
                        window.open(d.url, '_blank');
                    } else if (d.type === 'local') {
                        var a = $('<a>').attr({ href: d.url, download: d.filename || '' }).hide().appendTo('body');
                        a[0].click();
                        a.remove();
                    }
                } else {
                    alert('Error: ' + (res.data || 'No se pudo generar enlace'));
                }
            }).fail(function() {
                alert('Error de comunicacion');
            }).always(function() {
                $btn.prop('disabled', false);
            });
        });
    }

    /* ===== ELIMINAR BACKUP ===== */
    function initDeletes() {
        $(document).on('click', '.mbp-btn-delete', function(e) {
            e.preventDefault();
            var id = $(this).data('id');
            if (!confirm(mbp_ajax.strings.confirmDelete)) return;

            var $btn = $(this).prop('disabled', true);
            $.post(mbp_ajax.ajax_url, {
                action: 'mbp_delete_backup',
                nonce : mbp_ajax.nonce,
                id    : id,
            }, function(res) {
                if (res.success) {
                    $('tr[data-id="' + id + '"]').fadeOut(300, function() {
                        $(this).remove();
                        if ($('#mbp-backups-tbody tr').length === 0) {
                            $('#mbp-backups-tbody').html('<tr id="mbp-no-backups"><td colspan="10" class="mbp-empty">No hay backups registrados. Haz clic en "Ejecutar Backup Ahora" para crear el primero.</td></tr>');
                        }
                    });
                } else {
                    alert('Error: ' + (res.data || ''));
                }
            }).always(function() {
                $btn.prop('disabled', false);
            });
        });
    }

    /* ===== GUARDAR CONFIGURACION ===== */
    function initSettings() {
        $('#mbp-settings-form').on('submit', function(e) {
            e.preventDefault();
            var $btn = $(this).find('button[type="submit"]').prop('disabled', true);

            var data = $(this).serialize();
            data += '&action=mbp_save_settings&nonce=' + encodeURIComponent(mbp_ajax.nonce);

            $.post(mbp_ajax.ajax_url, data, function(res) {
                if (res.success) {
                    alert(mbp_ajax.strings.saved);
                    location.reload();
                } else {
                    alert('Error: ' + (res.data || ''));
                }
            }).fail(function() {
                alert('Error de comunicacion al guardar.');
            }).always(function() {
                $btn.prop('disabled', false);
            });
        });
    }

    /* ===== PROBAR CONEXION S3 ===== */
    function initTestS3() {
        $('#mbp-test-s3').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this).prop('disabled', true);
            var $res = $('#mbp-test-result').text('Probando...').show().css('color', '#666');

            var formData = $('#mbp-settings-form').serialize();
            formData += '&action=mbp_test_s3&nonce=' + encodeURIComponent(mbp_ajax.nonce);

            $.post(mbp_ajax.ajax_url, formData, function(r) {
                if (r.success) {
                    $res.text(r.data.message).css('color', '#46b450');
                } else {
                    $res.text(r.data || mbp_ajax.strings.connErr).css('color', '#dc3232');
                }
            }).fail(function(xhr) {
                $res.text('Error HTTP ' + xhr.status).css('color', '#dc3232');
            }).always(function() {
                $btn.prop('disabled', false);
            });
        });
    }

    /* ===== PROBAR EMAIL ===== */
    function initTestEmail() {
        $('#mbp-test-email').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this).prop('disabled', true);
            var $res = $('#mbp-email-result').text('Enviando...').show().css('color', '#666');

            $.post(mbp_ajax.ajax_url, {
                action: 'mbp_test_email',
                nonce : mbp_ajax.nonce,
            }, function(r) {
                if (r.success) {
                    $res.text(r.data.message).css('color', '#46b450');
                } else {
                    $res.text(r.data || mbp_ajax.strings.emailErr).css('color', '#dc3232');
                }
            }).fail(function(xhr) {
                $res.text('Error HTTP ' + xhr.status).css('color', '#dc3232');
            }).always(function() {
                $btn.prop('disabled', false);
            });
        });
    }

    /* ===== BUSCAR USUARIOS ===== */
    function initSearch() {
        $('#mbp-user-search').on('input', function() {
            var q = $(this).val().toLowerCase();
            $('#mbp-users-table tbody tr').each(function() {
                $(this).toggle($(this).text().toLowerCase().indexOf(q) > -1);
            });
        });
    }

    /* ===== REFRESCAR LISTA ===== */
    function initRefresh() {
        $('#mbp-refresh-list').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this).prop('disabled', true);
            $btn.find('.dashicons').addClass('dashicons-spin');
            refreshBackupsList(function() {
                $btn.prop('disabled', false);
                $btn.find('.dashicons').removeClass('dashicons-spin');
            });
        });
    }

    /* ===== ACTUALIZAR LISTA VIA AJAX ===== */
    function refreshBackupsList(callback) {
        $.post(mbp_ajax.ajax_url, {
            action: 'mbp_get_backups',
            nonce : mbp_ajax.nonce,
        }, function(res) {
            if (res.success && res.data.backups) {
                renderBackupsTable(res.data.backups);
            }
            if (typeof callback === 'function') callback();
        }).fail(function() {
            if (typeof callback === 'function') callback();
        });
    }

    function renderBackupsTable(backups) {
        var $tbody = $('#mbp-backups-tbody');
        if (!backups || backups.length === 0) {
            $tbody.html('<tr id="mbp-no-backups"><td colspan="10" class="mbp-empty">No hay backups registrados. Haz clic en "Ejecutar Backup Ahora" para crear el primero.</td></tr>');
            return;
        }

        var html = '';

        backups.forEach(function(b) {
            var tables = b.tables_list ? b.tables_list.split(', ').length : 0;
            var hasS3 = b.s3_key && b.s3_key.length > 0;
            var hasFile = b.file_path && b.file_path.length > 0;

            var statusBadge = '';
            if (b.status === 'completed') {
                statusBadge = '<span class="mbp-badge mbp-badge-green">OK</span>';
            } else if (b.status === 'failed') {
                statusBadge = '<span class="mbp-badge mbp-badge-red" title="' + escapeHtml(b.error_msg || '') + '">Fallido</span>';
            } else {
                statusBadge = '<span class="mbp-badge mbp-badge-orange">Pendiente</span>';
            }

            var s3Badge = '';
            if (hasS3) {
                s3Badge = '<span class="mbp-s3-badge mbp-s3-yes" title="' + escapeHtml(b.s3_key) + '"><span class="dashicons dashicons-cloud"></span> S3</span>';
            } else if (hasFile) {
                s3Badge = '<span class="mbp-s3-badge mbp-s3-no"><span class="dashicons dashicons-cloud"></span> Local</span>';
            } else {
                s3Badge = '<span class="mbp-s3-badge mbp-s3-none"><span class="dashicons dashicons-minus"></span></span>';
            }

            var actions = '';
            if (hasFile || hasS3) {
                actions += '<button class="button mbp-btn-download" data-id="' + b.id + '" title="Descargar"><span class="dashicons dashicons-download"></span></button>';
            }
            if (!hasS3 && hasFile) {
                actions += '<button class="button mbp-btn-upload-s3" data-id="' + b.id + '" title="Subir a S3"><span class="dashicons dashicons-cloud-upload"></span></button>';
            }
            actions += '<button class="button mbp-btn-delete" data-id="' + b.id + '" title="Eliminar"><span class="dashicons dashicons-trash"></span></button>';

            html += '<tr data-id="' + b.id + '">';
            html += '<td>' + b.id + '</td>';
            html += '<td><code>' + escapeHtml(b.file_name) + '</code></td>';
            html += '<td>' + fmtBytes(parseInt(b.file_size || 0)) + '</td>';
            html += '<td>' + tables + '</td>';
            html += '<td>' + numberFormat(b.rows_count || 0) + '</td>';
            html += '<td><span class="mbp-type mbp-type-' + escapeHtml(b.backup_type) + '">' + escapeHtml(b.backup_type) + '</span></td>';
            html += '<td>' + statusBadge + '</td>';
            html += '<td>' + s3Badge + '</td>';
            html += '<td>' + escapeHtml(b.completed_at || b.created_at) + '</td>';
            html += '<td class="mbp-actions">' + actions + '</td>';
            html += '</tr>';
        });

        $tbody.html(html);
    }

    /* ============================================================
       S3 EXPLORER v2.2 - Paginacion, Busqueda y Filtros
       ============================================================ */

    function initS3Explorer() {
        if (!$('#mbp-s3-table').length) return;

        // Cargar raiz
        s3Navigate({ prefix: '', page: 1, search: '', continuation_token: '' });

        // Navegar a raiz
        $(document).on('click', '.mbp-s3-nav-root', function(e) {
            e.preventDefault();
            s3Navigate({ prefix: '', page: 1, search: '', continuation_token: '', filter_ext: '' });
            $('#mbp-s3-search').val('');
            $('#mbp-s3-filter-ext').val('');
            s3UpdateSearchUI();
        });

        // Navegar a carpeta
        $(document).on('click', '.mbp-s3-folder-link', function(e) {
            e.preventDefault();
            var prefix = $(this).data('prefix');
            s3Navigate({ prefix: prefix, page: 1, search: '', continuation_token: '' });
        });

        // Breadcrumb
        $(document).on('click', '.mbp-s3-breadcrumb-link', function(e) {
            e.preventDefault();
            var prefix = $(this).data('prefix');
            s3Navigate({ prefix: prefix, page: 1, search: '', continuation_token: '' });
        });

        // Refresh
        $('#mbp-s3-refresh').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this).prop('disabled', true);
            $btn.find('.dashicons').addClass('dashicons-spin');
            s3ClearCache();
            s3Load(s3State, function() {
                $btn.prop('disabled', false);
                $btn.find('.dashicons').removeClass('dashicons-spin');
            });
        });

        // Busqueda con debounce
        $('#mbp-s3-search').on('input', function() {
            var q = $(this).val().trim();

            // Mostrar/ocultar boton de limpiar
            $('#mbp-s3-search-clear').toggle(q !== '');

            clearTimeout(searchDebounceTimer);
            searchDebounceTimer = setTimeout(function() {
                s3Navigate({
                    prefix: s3State.prefix,
                    page: 1,
                    search: q,
                    continuation_token: ''
                });
            }, 350);
        });

        // Limpiar busqueda
        $('#mbp-s3-search-clear, #mbp-s3-clear-search-link').on('click', function(e) {
            e.preventDefault();
            $('#mbp-s3-search').val('');
            $('#mbp-s3-search-clear').hide();
            s3Navigate({
                prefix: s3State.prefix,
                page: 1,
                search: '',
                continuation_token: ''
            });
        });

        // Filtro por extension
        $('#mbp-s3-filter-ext').on('change', function() {
            s3Navigate({
                prefix: s3State.prefix,
                page: 1,
                filter_ext: $(this).val(),
                continuation_token: ''
            });
        });

        // Items por pagina
        $('#mbp-s3-per-page').on('change', function() {
            s3Navigate({
                prefix: s3State.prefix,
                page: 1,
                per_page: parseInt($(this).val()),
                continuation_token: ''
            });
        });

        // Paginacion
        $('#mbp-s3-prev-page').on('click', function(e) {
            e.preventDefault();
            if (s3State.page <= 1 || s3State.loading) return;
            s3Navigate({ page: s3State.page - 1 });
        });

        $('#mbp-s3-next-page').on('click', function(e) {
            e.preventDefault();
            if (s3State.loading) return;
            s3Navigate({ page: s3State.page + 1, continuation_token: s3State.continuation_token });
        });

        $('#mbp-s3-first-page').on('click', function(e) {
            e.preventDefault();
            if (s3State.page <= 1 || s3State.loading) return;
            s3Navigate({ page: 1, continuation_token: '' });
        });

        // Crear carpeta - mostrar modal
        $('#mbp-s3-create-folder-btn').on('click', function(e) {
            e.preventDefault();
            $('#mbp-s3-new-folder').val('');
            $('#mbp-s3-folder-modal').show();
            setTimeout(function() { $('#mbp-s3-new-folder').focus(); }, 100);
        });

        // Cancelar crear carpeta
        $('#mbp-s3-folder-cancel').on('click', function(e) {
            e.preventDefault();
            $('#mbp-s3-folder-modal').hide();
        });

        // Confirmar crear carpeta
        $('#mbp-s3-folder-confirm').on('click', function(e) {
            e.preventDefault();
            var folder = $('#mbp-s3-new-folder').val().trim();
            if (!folder) {
                alert('Ingresa un nombre para la carpeta.');
                return;
            }

            var $btn = $(this).prop('disabled', true);
            $.post(mbp_ajax.ajax_url, {
                action: 'mbp_s3_create_folder',
                nonce : mbp_ajax.nonce,
                folder: folder,
                prefix: s3State.prefix,
            }, function(res) {
                if (res.success) {
                    $('#mbp-s3-folder-modal').hide();
                    alert(mbp_ajax.strings.folderOk + '\n' + (res.data.message || ''));
                    s3ClearCache();
                    s3Load(s3State);
                } else {
                    alert(mbp_ajax.strings.folderErr + '\n' + (res.data || ''));
                }
            }).fail(function() {
                alert(mbp_ajax.strings.folderErr + '\nError de comunicacion');
            }).always(function() {
                $btn.prop('disabled', false);
            });
        });

        // Eliminar objeto de S3
        $(document).on('click', '.mbp-s3-delete', function(e) {
            e.preventDefault();
            var key = $(this).data('key');
            var isFolder = key.endsWith('/');
            var msg = isFolder ? mbp_ajax.strings.confirmDeleteFolder : mbp_ajax.strings.confirmDeleteS3;
            if (!confirm(msg)) return;

            var $btn = $(this).prop('disabled', true);
            $.post(mbp_ajax.ajax_url, {
                action: 'mbp_s3_delete_object',
                nonce : mbp_ajax.nonce,
                key   : key,
            }, function(res) {
                if (res.success) {
                    s3ClearCache();
                    s3Load(s3State);
                } else {
                    alert(mbp_ajax.strings.deleteS3Err + '\n' + (res.data || ''));
                }
            }).fail(function() {
                alert(mbp_ajax.strings.deleteS3Err + '\nError de comunicacion');
            }).always(function() {
                $btn.prop('disabled', false);
            });
        });
    }

    // Navegar a un nuevo estado (actualiza estado y carga datos)
    function s3Navigate(newState) {
        // Merge con estado actual
        if (newState.prefix !== undefined) s3State.prefix = newState.prefix;
        if (newState.page !== undefined) s3State.page = newState.page;
        if (newState.per_page !== undefined) s3State.per_page = newState.per_page;
        if (newState.search !== undefined) s3State.search = newState.search;
        if (newState.filter_ext !== undefined) s3State.filter_ext = newState.filter_ext;
        if (newState.continuation_token !== undefined) s3State.continuation_token = newState.continuation_token;
        s3Load(s3State);
    }

    // Cargar datos del servidor (con cache)
    function s3Load(state, callback) {
        if (s3State.loading) return;
        s3State.loading = true;

        var $tbody = $('#mbp-s3-tbody');
        $tbody.html('<tr><td colspan="5" class="mbp-empty"><span class="dashicons dashicons-update dashicons-spin"></span> Cargando...</td></tr>');

        var cacheKeyStr = cacheKey(state);
        var now = Date.now();

        // Revisar cache en memoria (TTL: 25 segundos)
        if (s3Cache[cacheKeyStr] && (now - s3Cache[cacheKeyStr].ts) < 25000) {
            s3Render(s3Cache[cacheKeyStr].data);
            s3State.loading = false;
            s3UpdateSearchUI();
            if (typeof callback === 'function') callback();
            return;
        }

        // Llamada AJAX
        $.post(mbp_ajax.ajax_url, {
            action: 'mbp_s3_list_objects',
            nonce : mbp_ajax.nonce,
            prefix: state.prefix,
            search: state.search,
            filter_ext: state.filter_ext,
            page: state.page,
            per_page: state.per_page,
            continuation_token: state.continuation_token,
        }, function(res) {
            s3State.loading = false;
            if (res.success) {
                // Guardar en cache
                s3Cache[cacheKeyStr] = { data: res.data, ts: Date.now() };
                // Actualizar continuation token para siguiente pagina
                s3State.continuation_token = res.data.continuation_token || '';
                s3Render(res.data);
            } else {
                $tbody.html('<tr><td colspan="5" class="mbp-empty" style="color:#dc3232;"><span class="dashicons dashicons-warning"></span> Error: ' + escapeHtml(res.data || 'No se pudo cargar') + '</td></tr>');
                s3HidePagination();
            }
            s3UpdateSearchUI();
            if (typeof callback === 'function') callback();
        }).fail(function() {
            s3State.loading = false;
            $tbody.html('<tr><td colspan="5" class="mbp-empty" style="color:#dc3232;"><span class="dashicons dashicons-warning"></span> Error de comunicacion con S3</td></tr>');
            s3HidePagination();
            if (typeof callback === 'function') callback();
        });
    }

    // Renderizar resultados
    function s3Render(data) {
        var folders = data.folders || [];
        var files = data.files || [];
        var $tbody = $('#mbp-s3-tbody');

        if (folders.length === 0 && files.length === 0) {
            var emptyMsg = data.is_search
                ? 'No se encontraron archivos que coincidan con la busqueda.'
                : 'Carpeta vacia.';
            $tbody.html('<tr><td colspan="5" class="mbp-empty"><span class="dashicons dashicons-media-document" style="font-size:40px;color:#c3c4c7;display:block;margin-bottom:10px;"></span>' + emptyMsg + '</td></tr>');
            s3HidePagination();
            s3UpdateBreadcrumb(data.prefix || '');
            return;
        }

        var html = '';

        // Carpetas primero
        folders.forEach(function(f) {
            html += '<tr>';
            html += '<td><span class="dashicons dashicons-category" style="color:#ffb900;font-size:20px;"></span></td>';
            html += '<td><a href="#" class="mbp-s3-folder-link" data-prefix="' + escapeHtml(f.prefix) + '"><strong>' + escapeHtml(f.name) + '</strong>/</a></td>';
            html += '<td>-</td>';
            html += '<td>-</td>';
            html += '<td><button class="button mbp-s3-delete" data-key="' + escapeHtml(f.prefix) + '" title="Eliminar carpeta"><span class="dashicons dashicons-trash"></span></button></td>';
            html += '</tr>';
        });

        // Archivos
        files.forEach(function(f) {
            var ext = (f.name.lastIndexOf('.') > -1) ? f.name.substring(f.name.lastIndexOf('.')).toLowerCase() : '';
            var iconClass = 'dashicons-media-document';
            var iconColor = '#2271b1';

            // Icono segun extension
            if (['.sql','.gz','.zip','.tar'].indexOf(ext) !== -1) {
                iconClass = 'dashicons-archive';
                iconColor = '#7c3aed';
            } else if (['.jpg','.jpeg','.png','.gif','.webp'].indexOf(ext) !== -1) {
                iconClass = 'dashicons-format-image';
                iconColor = '#d63638';
            } else if (ext === '.log' || ext === '.txt') {
                iconClass = 'dashicons-text-page';
                iconColor = '#646970';
            }

            html += '<tr>';
            html += '<td><span class="dashicons ' + iconClass + '" style="color:' + iconColor + ';font-size:20px;"></span></td>';
            html += '<td><code title="' + escapeHtml(f.key) + '">' + escapeHtml(f.name) + '</code></td>';
            html += '<td>' + fmtBytes(f.size) + '</td>';
            html += '<td>' + fmtDate(f.modified) + '</td>';
            html += '<td><button class="button mbp-s3-delete" data-key="' + escapeHtml(f.key) + '" title="Eliminar archivo"><span class="dashicons dashicons-trash"></span></button></td>';
            html += '</tr>';
        });

        $tbody.html(html);
        s3UpdateBreadcrumb(data.prefix || '');
        s3UpdatePagination(data);
    }

    // Actualizar breadcrumb
    function s3UpdateBreadcrumb(prefix) {
        var $path = $('#mbp-s3-breadcrumb-path');
        if (!prefix) {
            $path.html('');
            return;
        }

        var parts = prefix.replace(/\/$/, '').split('/');
        var accumulated = '';
        var html = '';

        parts.forEach(function(part, index) {
            accumulated += part + '/';
            var isLast = index === parts.length - 1;
            if (isLast) {
                html += ' <span class="mbp-s3-breadcrumb-sep">/</span> <strong>' + escapeHtml(part) + '</strong>';
            } else {
                html += ' <span class="mbp-s3-breadcrumb-sep">/</span> <a href="#" class="mbp-s3-breadcrumb-link" data-prefix="' + escapeHtml(accumulated) + '">' + escapeHtml(part) + '</a>';
            }
        });

        $path.html(html);
    }

    // Actualizar controles de paginacion
    function s3UpdatePagination(data) {
        var $pagination = $('#mbp-s3-pagination');
        var page = data.page || 1;
        var hasNext = data.has_next || false;
        var hasPrev = data.has_prev || page > 1;
        var totalItems = data.total_items || data.key_count_s3 || 0;

        // En modo busqueda, mostrar info diferente
        if (data.is_search) {
            var totalPages = data.total_pages || 1;
            $('#mbp-s3-page-info').text(
                'Pagina ' + page + ' de ' + totalPages +
                ' (' + numberFormat(totalItems) + ' resultados)'
            );
            $('#mbp-s3-current-page').text(page + '/' + totalPages);

            $pagination.show();
            $('#mbp-s3-prev-page').prop('disabled', page <= 1);
            $('#mbp-s3-next-page').prop('disabled', page >= totalPages);
            $('#mbp-s3-first-page').prop('disabled', page <= 1);
            return;
        }

        // Modo navegacion normal
        if (!hasNext && !hasPrev && totalItems === 0) {
            s3HidePagination();
            return;
        }

        $('#mbp-s3-page-info').text(
            'Pagina ' + page +
            (totalItems > 0 ? ' (' + numberFormat(totalItems) + ' items mostrados' + (hasNext ? '+' : '') + ')' : '')
        );
        $('#mbp-s3-current-page').text(page);

        $pagination.show();
        $('#mbp-s3-prev-page').prop('disabled', !hasPrev);
        $('#mbp-s3-next-page').prop('disabled', !hasNext);
        $('#mbp-s3-first-page').prop('disabled', !hasPrev);
    }

    function s3HidePagination() {
        $('#mbp-s3-pagination').hide();
    }

    // Actualizar UI de busqueda
    function s3UpdateSearchUI() {
        var $resultsInfo = $('#mbp-s3-results-info');
        var $searchBadge = $('#mbp-s3-search-badge');

        if (s3State.search !== '') {
            $resultsInfo.show();
            $searchBadge.show();
            $('#mbp-s3-search-term').text(s3State.search);
        } else {
            $searchBadge.hide();
            if (s3State.filter_ext !== '') {
                $resultsInfo.show();
            } else {
                $resultsInfo.hide();
            }
        }
    }

    // Limpiar cache
    function s3ClearCache() {
        s3Cache = {};
    }

})(jQuery);
