<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_mysqlbackuppro;

defined('MOODLE_INTERNAL') || die();

/**
 * Native S3 client with Signature V4 -- NO external dependencies.
 * Compatible with Contabo, AWS, MinIO, Wasabi, DigitalOcean Spaces, etc.
 *
 * @package    local_mysqlbackuppro
 * @copyright  2024 Louis Jhosimar Ocampo | GestLife Dev
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class s3native {

    /** @var string */
    private string $endpoint;

    /** @var string */
    private string $region;

    /** @var string */
    private string $bucket;

    /** @var string */
    private string $access_key;

    /** @var string */
    private string $secret_key;

    /** @var bool */
    private bool $path_style;

    /**
     * Constructor reads config from Moodle config_plugins.
     */
    public function __construct() {
        $this->endpoint   = rtrim(get_config('local_mysqlbackuppro', 's3_endpoint') ?: '', '/');
        $this->region     = get_config('local_mysqlbackuppro', 's3_region') ?: 'default';
        $this->bucket     = get_config('local_mysqlbackuppro', 's3_bucket') ?: '';
        $this->access_key = crypto::decrypt(get_config('local_mysqlbackuppro', 's3_access_key') ?: '');
        $this->secret_key = crypto::decrypt(get_config('local_mysqlbackuppro', 's3_secret_key') ?: '');
        $this->path_style = (get_config('local_mysqlbackuppro', 's3_path_style') ?: '1') === '1';
    }

    /**
     * Check if S3 is fully configured.
     *
     * @return bool
     */
    public static function configured(): bool {
        $bucket   = get_config('local_mysqlbackuppro', 's3_bucket') ?: '';
        $access   = crypto::decrypt(get_config('local_mysqlbackuppro', 's3_access_key') ?: '');
        $secret   = crypto::decrypt(get_config('local_mysqlbackuppro', 's3_secret_key') ?: '');
        $endpoint = get_config('local_mysqlbackuppro', 's3_endpoint') ?: '';
        return !empty($bucket) && !empty($access) && !empty($secret) && !empty($endpoint);
    }

    /* ============ URL BUILDERS ============ */

    /**
     * Build the base URL for a given S3 key.
     *
     * @param string $key
     * @return string
     */
    private function base_url(string $key = ''): string {
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

    /* ============ SIGNATURE V4 ============ */

    /**
     * Build AWS Signature V4 headers.
     *
     * @param string $method
     * @param string $url
     * @param array $extra
     * @return array
     */
    private function headers(string $method, string $url, array $extra = []): array {
        $date_short = gmdate('Ymd');
        $date_full  = gmdate('Ymd\THis\Z');
        $parsed     = parse_url($url);
        $host       = $parsed['host'] ?? '';
        $path       = $parsed['path'] ?? '/';
        $query      = $parsed['query'] ?? '';

        $canonical_headers = "host:{$host}\n";
        $signed_headers    = 'host';

        if (isset($extra['payload_hash'])) {
            $payload_hash = $extra['payload_hash'];
        } elseif (isset($extra['body'])) {
            $payload_hash = hash('sha256', $extra['body']);
        } else {
            $payload_hash = hash('sha256', '');
        }

        $canonical_query = $query ? $this->canonical_query($query) : '';
        $canonical_request = implode("\n", [
            strtoupper($method),
            $path,
            $canonical_query,
            $canonical_headers,
            $signed_headers,
            $payload_hash,
        ]);

        $credential = "{$date_short}/{$this->region}/s3/aws4_request";
        $string_to_sign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $date_full,
            $credential,
            hash('sha256', $canonical_request),
        ]);

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

    /**
     * Canonicalize query string.
     *
     * @param string $query
     * @return string
     */
    private function canonical_query(string $query): string {
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

    /* ============ HTTP REQUEST ============ */

    /**
     * Execute HTTP request via cURL.
     *
     * @param string $method
     * @param string $url
     * @param array $opts
     * @return array
     */
    private function request(string $method, string $url, array $opts = []): array {
        $headers = $this->headers($method, $url, $opts);
        $header_lines = [];
        foreach ($headers as $k => $v) {
            $header_lines[] = "{$k}: {$v}";
        }

        $ch = curl_init($url);
        $curl_opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_HTTPHEADER     => $header_lines,
            CURLOPT_CUSTOMREQUEST  => $method,
        ];

        if ($method === 'PUT' && isset($opts['file'])) {
            $fh = fopen($opts['file'], 'rb');
            if (!$fh) {
                curl_close($ch);
                return ['success' => false, 'code' => 0, 'error' => 'Cannot open local file: ' . $opts['file'], 'body' => ''];
            }
            $filesize = filesize($opts['file']);
            $curl_opts[CURLOPT_PUT] = true;
            $curl_opts[CURLOPT_INFILE] = $fh;
            $curl_opts[CURLOPT_INFILESIZE] = $filesize;
        }
        if ($method === 'PUT' && isset($opts['body'])) {
            $curl_opts[CURLOPT_POSTFIELDS] = $opts['body'];
        }
        if ($method === 'POST' && isset($opts['body'])) {
            $curl_opts[CURLOPT_POSTFIELDS] = $opts['body'];
        }
        if (isset($opts['curl_opts'])) {
            foreach ($opts['curl_opts'] as $k => $v) {
                $curl_opts[$k] = $v;
            }
        }

        curl_setopt_array($ch, $curl_opts);

        $response = curl_exec($ch);
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

        if ($http_code < 200 || $http_code >= 300) {
            logger::error("[S3] HTTP {$http_code} | URL: {$url} | Time: {$total_time}s | Body: " . strip_tags($body));
        }

        return [
            'success'  => $http_code >= 200 && $http_code < 300,
            'code'     => $http_code,
            'error'    => $error,
            'body'     => $body,
            'response' => $response,
        ];
    }

    /* ============ PUBLIC OPERATIONS ============ */

    /**
     * List buckets (connectivity test).
     *
     * @return array
     */
    public function list_buckets(): array {
        $url = $this->endpoint . '/';
        $res = $this->request('GET', $url);
        if (!$res['success']) {
            $detail = strip_tags($res['body']);
            if (empty($detail)) {
                $detail = $res['error'] ?: "HTTP {$res['code']}";
            }
            logger::error('S3 connection failed: ' . $detail);
            return ['success' => false, 'message' => "Error: {$detail}"];
        }
        preg_match_all('/<Name>([^<]+)<\/Name>/', $res['body'], $m);
        $buckets = $m[1] ?? [];
        $found = in_array($this->bucket, $buckets, true);

        if ($found) {
            logger::info('S3 connection successful! Bucket found: ' . $this->bucket);
            return [
                'success' => true,
                'message' => get_string('s3_conn_ok', 'local_mysqlbackuppro', $this->bucket),
            ];
        } else {
            logger::warning('S3 connected but bucket not found: ' . $this->bucket);
            return [
                'success' => false,
                'message' => get_string('s3_conn_bucket_not_found', 'local_mysqlbackuppro',
                    ['bucket' => $this->bucket, 'buckets' => implode(', ', $buckets)]),
            ];
        }
    }

    /**
     * Upload a file to S3.
     *
     * @param string $local_path
     * @param string $s3_key
     * @return array
     */
    public function upload(string $local_path, string $s3_key): array {
        if (!file_exists($local_path)) {
            return ['success' => false, 'message' => 'Local file does not exist: ' . $local_path];
        }
        if (!is_readable($local_path)) {
            return ['success' => false, 'message' => 'Local file is not readable: ' . $local_path];
        }

        $file_hash = hash_file('sha256', $local_path);
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
     * Create a folder in S3 (empty object with key ending in /).
     *
     * @param string $folder_path
     * @return array
     */
    public function create_folder(string $folder_path): array {
        $folder_path = rtrim($folder_path, '/') . '/';
        $url = $this->base_url($folder_path);

        $res = $this->request('PUT', $url, [
            'body'           => '',
            'content_type'   => 'application/x-directory',
            'content_length' => 0,
        ]);

        if ($res['success']) {
            return [
                'success' => true,
                'key'     => $folder_path,
                'message' => 'Folder "' . $folder_path . '" created successfully.',
            ];
        }
        return [
            'success' => false,
            'message' => "HTTP {$res['code']}: " . (strip_tags($res['body']) ?: $res['error']),
        ];
    }

    /**
     * List objects with native S3 ListObjectsV2 pagination.
     *
     * @param string $prefix
     * @param string $delimiter
     * @param int $max_keys
     * @param string $continuation_token
     * @param string $start_after
     * @return array
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

        $xml = simplexml_load_string($res['body']);
        if ($xml === false) {
            return ['success' => false, 'message' => 'Error parsing S3 XML response'];
        }

        $folders = [];
        $files = [];

        $is_truncated = isset($xml->IsTruncated) && ((string)$xml->IsTruncated) === 'true';
        $next_token = isset($xml->NextContinuationToken) ? (string)$xml->NextContinuationToken : '';
        $key_count = isset($xml->KeyCount) ? (int)$xml->KeyCount : 0;
        $max_keys_returned = isset($xml->MaxKeys) ? (int)$xml->MaxKeys : $max_keys;

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

        if (isset($xml->Contents)) {
            foreach ($xml->Contents as $content) {
                $key = (string) $content->Key;
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
     * Search objects by name using S3 prefix matching.
     *
     * @param string $search
     * @param string $base_prefix
     * @param int $max_results
     * @param string $filter_ext
     * @return array
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
        $max_pages = 20;

        $search_prefix = $base_prefix;
        if ($search !== '') {
            $search_prefix = $base_prefix . $search;
        }

        do {
            $result = $this->list_objects(
                $search_prefix,
                '/',
                50,
                $token
            );

            if (!$result['success']) {
                return $result;
            }

            if ($pages === 0) {
                $all_folders = $result['folders'];
            }

            foreach ($result['files'] as $file) {
                if ($filter_ext !== '' && !str_ends_with(strtolower($file['name']), strtolower($filter_ext))) {
                    continue;
                }
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
     * Delete an object from S3.
     *
     * @param string $s3_key
     * @return array
     */
    public function delete(string $s3_key): array {
        $url = $this->base_url($s3_key);
        $res = $this->request('DELETE', $url);
        return ['success' => $res['success'], 'message' => $res['body']];
    }

    /**
     * Generate a presigned URL for download (valid ~15 min).
     *
     * @param string $s3_key
     * @param int $expires
     * @return array
     */
    public function presigned_url(string $s3_key, int $expires = 900): array {
        $date_short = gmdate('Ymd');
        $date_full  = gmdate('Ymd\THis\Z');
        $url        = $this->base_url($s3_key);
        $parsed     = parse_url($url);
        $host       = $parsed['host'] ?? '';
        $path       = $parsed['path'] ?? '/';

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
}
