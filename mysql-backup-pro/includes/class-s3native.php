<?php
/**
 * Cliente S3 nativo con Signature V4 -- NO requiere AWS SDK.
 * Compatible con Contabo, AWS, MinIO, Wasabi, DigitalOcean Spaces, etc.
 * 
 * v2.2 - Mejorado: Paginacion con ListObjectsV2, soporte para max-keys,
 *        continuation-token, start-after, y busqueda por prefix.
 */
namespace MBP;

class S3Native
{
    private string $endpoint;
    private string $region;
    private string $bucket;
    private string $access_key;
    private string $secret_key;
    private bool   $path_style;

    public function __construct()
    {
        $this->endpoint   = rtrim(get_option('mbp_s3_endpoint', ''), '/');
        $this->region     = get_option('mbp_s3_region', 'default');
        $this->bucket     = get_option('mbp_s3_bucket', '');
        $this->access_key = Crypto::decrypt(get_option('mbp_s3_access_key', ''));
        $this->secret_key = Crypto::decrypt(get_option('mbp_s3_secret_key', ''));
        $this->path_style = get_option('mbp_s3_path_style', '1') === '1';
    }

    public static function configured(): bool
    {
        return !empty(get_option('mbp_s3_bucket', ''))
            && !empty(Crypto::decrypt(get_option('mbp_s3_access_key', '')))
            && !empty(Crypto::decrypt(get_option('mbp_s3_secret_key', '')))
            && !empty(get_option('mbp_s3_endpoint', ''));
    }

    /* ---------- URL del bucket ---------- */
    private function base_url(string $key = ''): string
    {
        if ($this->path_style) {
            $url = "{$this->endpoint}/{$this->bucket}";
        } else {
            $url = str_replace('://', "://{$this->bucket}.", $this->endpoint);
        }
        if ($key !== '') {
            $url .= '/' . ltrim($key, '/');
        }
        return $url;
    }

    /* ---------- Calcular SHA256 de archivo ---------- */
    private function file_sha256(string $filepath): string
    {
        return hash_file('sha256', $filepath);
    }

    /* ---------- cabeceras firmadas ---------- */
    private function headers(string $method, string $url, array $extra = []): array
    {
        $date_short = gmdate('Ymd');
        $date_full  = gmdate('Ymd\THis\Z');
        $parsed     = parse_url($url);
        $host       = $parsed['host'] ?? '';
        $path       = $parsed['path'] ?? '/';
        $query      = $parsed['query'] ?? '';

        // Headers canonicos
        $canonical_headers = "host:{$host}\n";
        $signed_headers    = 'host';

        // El payload_hash DEBE ser el hash real del contenido que se envia.
        if (isset($extra['payload_hash'])) {
            $payload_hash = $extra['payload_hash'];
        } elseif (isset($extra['body'])) {
            $payload_hash = hash('sha256', $extra['body']);
        } else {
            $payload_hash = hash('sha256', '');
        }

        // Canonical request
        $canonical_query = $query ? $this->canonical_query($query) : '';
        $canonical_request = implode("\n", [
            strtoupper($method),
            $path,
            $canonical_query,
            $canonical_headers,
            $signed_headers,
            $payload_hash,
        ]);

        // String to sign
        $credential = "{$date_short}/{$this->region}/s3/aws4_request";
        $string_to_sign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $date_full,
            $credential,
            hash('sha256', $canonical_request),
        ]);

        // Firma
        $k_date    = hash_hmac('sha256', $date_short, 'AWS4' . $this->secret_key, true);
        $k_region  = hash_hmac('sha256', $this->region, $k_date, true);
        $k_service = hash_hmac('sha256', 's3', $k_region, true);
        $k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);
        $signature = hash_hmac('sha256', $string_to_sign, $k_signing);

        $auth = "AWS4-HMAC-SHA256 Credential={$this->access_key}/{$credential},SignedHeaders={$signed_headers},Signature={$signature}";

        $h = [
            'Host'                 => $host,
            'X-Amz-Date'           => $date_full,
            'X-Amz-Content-Sha256' => $payload_hash,
            'Authorization'        => $auth,
        ];

        if ($method === 'PUT') {
            $h['Content-Type'] = $extra['content_type'] ?? 'application/octet-stream';
            if (isset($extra['content_length'])) {
                $h['Content-Length'] = $extra['content_length'];
            }
        }

        return $h;
    }

    private function canonical_query(string $query): string
    {
        if ($query === '') {
            return '';
        }
        parse_str($query, $params);
        ksort($params);
        $parts = [];
        foreach ($params as $k => $v) {
            $parts[] = rawurlencode($k) . '=' . rawurlencode($v);
        }
        return implode('&', $parts);
    }

    /* ---------- cURL ---------- */
    private function request(string $method, string $url, array $opts = []): array
    {
        $headers = $this->headers($method, $url, $opts);
        $header_lines = [];
        foreach ($headers as $k => $v) {
            $header_lines[] = "{$k}: {$v}";
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_HTTPHEADER     => $header_lines,
            CURLOPT_CUSTOMREQUEST  => $method,
        ]);

        if ($method === 'PUT' && isset($opts['file'])) {
            $fh = fopen($opts['file'], 'rb');
            if (!$fh) {
                curl_close($ch);
                return ['success' => false, 'code' => 0, 'error' => 'No se pudo abrir archivo local: ' . $opts['file'], 'body' => ''];
            }
            $filesize = filesize($opts['file']);
            curl_setopt($ch, CURLOPT_PUT, true);
            curl_setopt($ch, CURLOPT_INFILE, $fh);
            curl_setopt($ch, CURLOPT_INFILESIZE, $filesize);
        }
        if ($method === 'PUT' && isset($opts['body'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);
        }
        if ($method === 'POST' && isset($opts['body'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);
        }
        if (isset($opts['curl_opts'])) {
            foreach ($opts['curl_opts'] as $k => $v) {
                curl_setopt($ch, $k, $v);
            }
        }

        $response = curl_exec($ch);

        // OBTENER INFO ANTES de cerrar el handle
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $total_time = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        $error = curl_error($ch);

        curl_close($ch);
        if (isset($fh) && $fh) {
            fclose($fh);
        }

        if ($response === false) {
            return ['success' => false, 'code' => 0, 'error' => $error, 'body' => ''];
        }

        $body = substr($response, (int) $header_size);

        // Log para debug
        if (!$response) {
            error_log('[MySQL Backup Pro S3] Request failed: ' . $error);
        } elseif ($http_code < 200 || $http_code >= 300) {
            error_log("[MySQL Backup Pro S3] HTTP {$http_code} | URL: {$url} | Time: {$total_time}s | Body: " . strip_tags($body));
        }

        return [
            'success'  => $http_code >= 200 && $http_code < 300,
            'code'     => $http_code,
            'error'    => $error,
            'body'     => $body,
            'response' => $response,
        ];
    }

    /* ==================== OPERACIONES PUBLICAS ==================== */

    /**
     * Listar buckets (test de conectividad)
     */
    public function list_buckets(): array
    {
        $url = $this->endpoint . '/';
        $res = $this->request('GET', $url);
        if (!$res['success']) {
            $error_detail = strip_tags($res['body']);
            if (empty($error_detail)) {
                $error_detail = $res['error'] ?: "HTTP {$res['code']}";
            }
            return ['success' => false, 'message' => "Error: {$error_detail}"];
        }
        // Parsear XML
        preg_match_all('/<Name>([^<]+)<\/Name>/', $res['body'], $m);
        $buckets = $m[1] ?? [];
        $found = in_array($this->bucket, $buckets, true);

        return [
            'success' => $found,
            'message' => $found
                ? sprintf(__('Conexion exitosa! Bucket "%s" encontrado.', 'mysql-backup-pro'), $this->bucket)
                : sprintf(__('Conectado, pero el bucket "%s" no existe. Disponibles: %s', 'mysql-backup-pro'), $this->bucket, implode(', ', $buckets)),
        ];
    }

    /**
     * Subir archivo
     */
    public function upload(string $local_path, string $s3_key): array
    {
        if (!file_exists($local_path)) {
            return ['success' => false, 'message' => 'Archivo local no existe: ' . $local_path];
        }

        if (!is_readable($local_path)) {
            return ['success' => false, 'message' => 'Archivo local no legible: ' . $local_path];
        }

        // Calcular hash SHA256 del archivo
        $file_hash = $this->file_sha256($local_path);
        $file_size = filesize($local_path);

        $url = $this->base_url($s3_key);
        $res = $this->request('PUT', $url, [
            'file'           => $local_path,
            'content_type'   => 'application/octet-stream',
            'content_length' => $file_size,
            'payload_hash'   => $file_hash,
        ]);

        if ($res['success']) {
            return [
                'success' => true,
                'key'     => $s3_key,
                'bucket'  => $this->bucket,
                'url'     => $url,
                'size'    => $file_size,
            ];
        }
        return [
            'success' => false,
            'message' => "HTTP {$res['code']}: " . (strip_tags($res['body']) ?: $res['error']),
        ];
    }

    /**
     * Crear "carpeta" en S3 (objeto vacio con key terminando en /)
     */
    public function create_folder(string $folder_path): array
    {
        $folder_path = rtrim($folder_path, '/') . '/';
        $url = $this->base_url($folder_path);

        // Carpeta vacia: body vacio, hash de cadena vacia
        $res = $this->request('PUT', $url, [
            'body'           => '',
            'content_type'   => 'application/x-directory',
            'content_length' => 0,
        ]);

        if ($res['success']) {
            return [
                'success' => true,
                'key'     => $folder_path,
                'message' => 'Carpeta "' . $folder_path . '" creada exitosamente.',
            ];
        }
        return [
            'success' => false,
            'message' => "HTTP {$res['code']}: " . (strip_tags($res['body']) ?: $res['error']),
        ];
    }

    /**
     * Listar objetos con paginacion nativa de S3 ListObjectsV2.
     * 
     * @param string $prefix Prefijo del "directorio" actual
     * @param string $delimiter Delimitador ('/' para carpetas, '' para busqueda plana)
     * @param int $max_keys Maximo de objetos por pagina (default 50, max 1000)
     * @param string $continuation_token Token para la siguiente pagina
     * @param string $start_after Key despues de la cual empezar a listar
     * @return array Datos con folders, files, y metadata de paginacion
     */
    public function list_objects(
        string $prefix = '',
        string $delimiter = '/',
        int $max_keys = 50,
        string $continuation_token = '',
        string $start_after = ''
    ): array {
        $url = $this->base_url('');
        $params = [
            'list-type' => '2',
            'max-keys'  => min(max($max_keys, 1), 1000),
        ];

        if ($prefix !== '') {
            $params['prefix'] = $prefix;
        }
        if ($delimiter !== '') {
            $params['delimiter'] = $delimiter;
        }
        if ($continuation_token !== '') {
            $params['continuation-token'] = $continuation_token;
        }
        if ($start_after !== '') {
            $params['start-after'] = $start_after;
        }

        $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        $res = $this->request('GET', $url);
        if (!$res['success']) {
            return ['success' => false, 'message' => "HTTP {$res['code']}: " . strip_tags($res['body'])];
        }

        // Parsear XML
        $xml = simplexml_load_string($res['body']);
        if ($xml === false) {
            return ['success' => false, 'message' => 'Error al parsear respuesta XML de S3'];
        }

        $folders = [];
        $files = [];

        // Extraer metadata de paginacion
        $is_truncated = isset($xml->IsTruncated) && ((string)$xml->IsTruncated) === 'true';
        $next_token = isset($xml->NextContinuationToken) ? (string)$xml->NextContinuationToken : '';
        $key_count = isset($xml->KeyCount) ? (int)$xml->KeyCount : 0;
        $max_keys_returned = isset($xml->MaxKeys) ? (int)$xml->MaxKeys : $max_keys;

        // Carpetas (CommonPrefixes)
        if (isset($xml->CommonPrefixes)) {
            foreach ($xml->CommonPrefixes as $cp) {
                $prefix_str = (string) $cp->Prefix;
                $name = rtrim(str_replace($prefix, '', $prefix_str), '/');
                if ($name !== '') {
                    $folders[] = [
                        'name'   => $name,
                        'prefix' => $prefix_str,
                    ];
                }
            }
        }

        // Archivos (Contents)
        if (isset($xml->Contents)) {
            foreach ($xml->Contents as $content) {
                $key = (string) $content->Key;
                // Ignorar el propio prefijo si es carpeta vacia
                if (substr($key, -1) === '/') {
                    continue;
                }
                $name = basename($key);
                $files[] = [
                    'name'      => $name,
                    'key'       => $key,
                    'size'      => (int) $content->Size,
                    'modified'  => (string) $content->LastModified,
                ];
            }
        }

        return [
            'success'         => true,
            'folders'         => $folders,
            'files'           => $files,
            'prefix'          => $prefix,
            'is_truncated'    => $is_truncated,
            'next_token'      => $next_token,
            'key_count'       => $key_count,
            'max_keys'        => $max_keys_returned,
        ];
    }

    /**
     * Buscar objetos por nombre usando prefix matching de S3.
     * Esta funcion lista recursivamente paginas hasta encontrar resultados
     * o hasta un limite seguro, aplicando un filtro de nombre adicional.
     * 
     * @param string $search Termino de busqueda (se usa como prefix cuando es eficiente)
     * @param string $base_prefix Prefijo base (directorio actual)
     * @param int $max_results Maximo de resultados a retornar
     * @param string $filter_ext Extension de archivo para filtrar (ej: '.sql', '.gz')
     * @return array Resultados de busqueda
     */
    public function search_objects(
        string $search = '',
        string $base_prefix = '',
        int $max_results = 100,
        string $filter_ext = ''
    ): array {
        $all_files = [];
        $all_folders = [];
        $token = '';
        $pages = 0;
        $max_pages = 20; // Limite de seguridad: max 20 paginas = 1000 resultados

        // Construir prefix de busqueda: base + search
        $search_prefix = $base_prefix;
        if ($search !== '') {
            $search_prefix = $base_prefix . $search;
        }

        do {
            $result = $this->list_objects(
                $search_prefix,
                '/',  // Siempre con delimiter para mantener estructura de carpetas
                50,   // 50 por pagina durante busqueda
                $token
            );

            if (!$result['success']) {
                return $result;
            }

            // Agregar carpetas (solo de la primera pagina para no duplicar)
            if ($pages === 0) {
                $all_folders = $result['folders'];
            }

            // Filtrar archivos por nombre y extension
            foreach ($result['files'] as $file) {
                // Filtro por extension
                if ($filter_ext !== '' && !str_ends_with(strtolower($file['name']), strtolower($filter_ext))) {
                    continue;
                }

                // Filtro por nombre (si no se pudo usar prefix de S3)
                if ($search !== '' && stripos($file['name'], $search) === false) {
                    continue;
                }

                $all_files[] = $file;

                if (count($all_files) >= $max_results) {
                    break 2;
                }
            }

            $token = $result['next_token'] ?? '';
            $pages++;
        } while (!empty($token) && $pages < $max_pages);

        return [
            'success'       => true,
            'folders'       => $all_folders,
            'files'         => $all_files,
            'prefix'        => $base_prefix,
            'is_truncated'  => !empty($token),
            'next_token'    => $token,
            'key_count'     => count($all_files) + count($all_folders),
            'max_keys'      => $max_results,
            'search'        => $search,
        ];
    }

    /**
     * Eliminar objeto
     */
    public function delete(string $s3_key): array
    {
        $url = $this->base_url($s3_key);
        $res = $this->request('DELETE', $url);
        return ['success' => $res['success'], 'message' => $res['body']];
    }

    /**
     * URL presignada para descarga (valida ~15 min)
     */
    public function presigned_url(string $s3_key, int $expires = 900): array
    {
        $date_short = gmdate('Ymd');
        $date_full  = gmdate('Ymd\THis\Z');
        $url        = $this->base_url($s3_key);
        $parsed     = parse_url($url);
        $host       = $parsed['host'] ?? '';
        $path       = $parsed['path'] ?? '/';

        // Crear query string con parametros de firma
        $credential = "{$this->access_key}/{$date_short}/{$this->region}/s3/aws4_request";
        $params = [
            'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential'    => $credential,
            'X-Amz-Date'          => $date_full,
            'X-Amz-Expires'       => (string) $expires,
            'X-Amz-SignedHeaders' => 'host',
        ];
        ksort($params);
        $canonical_query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        $canonical_request = implode("\n", [
            'GET',
            $path,
            $canonical_query,
            "host:{$host}\n",
            'host',
            'UNSIGNED-PAYLOAD',
        ]);

        $string_to_sign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $date_full,
            "{$date_short}/{$this->region}/s3/aws4_request",
            hash('sha256', $canonical_request),
        ]);

        $k_date    = hash_hmac('sha256', $date_short, 'AWS4' . $this->secret_key, true);
        $k_region  = hash_hmac('sha256', $this->region, $k_date, true);
        $k_service = hash_hmac('sha256', 's3', $k_region, true);
        $k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);
        $signature = hash_hmac('sha256', $string_to_sign, $k_signing);

        $final_url = $url . '?' . $canonical_query . '&X-Amz-Signature=' . $signature;

        return ['success' => true, 'url' => $final_url];
    }

    /* ==================== AJAX HANDLERS ==================== */

    public static function ajax_test_connection(): void
    {
        // Usar credenciales del formulario si se enviaron (para probar antes de guardar)
        $use_form = isset($_POST['mbp_s3_endpoint']) && isset($_POST['mbp_s3_bucket']);

        if ($use_form) {
            // Guardar temporalmente las credenciales del formulario
            $old_endpoint = get_option('mbp_s3_endpoint');
            $old_region = get_option('mbp_s3_region');
            $old_bucket = get_option('mbp_s3_bucket');
            $old_access = get_option('mbp_s3_access_key');
            $old_secret = get_option('mbp_s3_secret_key');
            $old_path = get_option('mbp_s3_path_style');

            update_option('mbp_s3_endpoint', sanitize_text_field(wp_unslash($_POST['mbp_s3_endpoint'])));
            update_option('mbp_s3_region', sanitize_text_field(wp_unslash($_POST['mbp_s3_region'] ?? 'default')));
            update_option('mbp_s3_bucket', sanitize_text_field(wp_unslash($_POST['mbp_s3_bucket'])));

            $access = sanitize_text_field(wp_unslash($_POST['mbp_s3_access_key'] ?? ''));
            $secret = sanitize_text_field(wp_unslash($_POST['mbp_s3_secret_key'] ?? ''));
            if ($access) update_option('mbp_s3_access_key', Crypto::encrypt($access));
            if ($secret) update_option('mbp_s3_secret_key', Crypto::encrypt($secret));
            update_option('mbp_s3_path_style', sanitize_text_field(wp_unslash($_POST['mbp_s3_path_style'] ?? '1')));

            $s3 = new self();
            $result = $s3->list_buckets();

            // Restaurar valores originales
            update_option('mbp_s3_endpoint', $old_endpoint);
            update_option('mbp_s3_region', $old_region);
            update_option('mbp_s3_bucket', $old_bucket);
            update_option('mbp_s3_access_key', $old_access);
            update_option('mbp_s3_secret_key', $old_secret);
            update_option('mbp_s3_path_style', $old_path);
        } else {
            if (!self::configured()) {
                wp_send_json_error(__('Faltan datos de configuracion S3.', 'mysql-backup-pro'));
            }
            $s3 = new self();
            $result = $s3->list_buckets();
        }

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }
}
