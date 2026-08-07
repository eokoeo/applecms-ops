```php
<?php
/**
 * AppleCMS OPS
 * ------------------------------------------------------------
 * AppleCMS Redis + Meilisearch Operations Center
 *
 * Version: 0.1.0
 * PHP:     7.4+
 *
 * V0.1.0:
 * - Automatic AppleCMS configuration detection
 * - Redis connection detection
 * - Redis dashboard
 * - Meilisearch health detection
 * - Meilisearch statistics
 * - AJAX dashboard refresh
 * - No full Redis SCAN on dashboard
 * - No manual Redis / Meilisearch configuration required
 *
 * Security:
 * - This file should NOT be exposed publicly without authentication.
 * - Authentication will be added in a later version.
 */

declare(strict_types=1);

/* ============================================================
 * 1. BASIC SETTINGS
 * ============================================================ */

define('OPS_VERSION', '0.1.0');
define('OPS_START_TIME', microtime(true));

/*
 * Prevent accidental PHP output before JSON responses.
 */
ob_start();

/* ============================================================
 * 2. REQUEST HELPERS
 * ============================================================ */

/**
 * Get request action.
 */
function ops_action(): string
{
    $action = isset($_REQUEST['action']) ? (string)$_REQUEST['action'] : '';

    return preg_replace('/[^a-zA-Z0-9_\-]/', '', $action);
}

/**
 * JSON response.
 */
function ops_json(array $data, int $status = 200): void
{
    if (ob_get_level() > 0) {
        ob_clean();
    }

    http_response_code($status);

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

/**
 * HTML escape.
 */
function ops_e(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/* ============================================================
 * 3. APP PATH DETECTION
 * ============================================================ */

/**
 * Find the probable AppleCMS root directory.
 *
 * ops.php may be placed:
 *
 * /ops.php
 *
 * or:
 *
 * /admin/ops.php
 *
 * Therefore we walk upwards and look for known AppleCMS files.
 */
function ops_find_root(): string
{
    $current = __DIR__;

    for ($i = 0; $i < 6; $i++) {

        $markers = array(
            $current . '/application',
            $current . '/thinkphp',
            $current . '/public',
            $current . '/index.php',
        );

        $score = 0;

        foreach ($markers as $marker) {
            if (file_exists($marker)) {
                $score++;
            }
        }

        if ($score >= 2) {
            return $current;
        }

        $parent = dirname($current);

        if ($parent === $current) {
            break;
        }

        $current = $parent;
    }

    return __DIR__;
}

/**
 * AppleCMS root.
 */
function ops_root(): string
{
    static $root = null;

    if ($root === null) {
        $root = ops_find_root();
    }

    return $root;
}

/* ============================================================
 * 4. APPLECMS CONFIG DETECTION
 * ============================================================ */

/**
 * Candidate configuration files.
 *
 * AppleCMS installations can differ slightly,
 * so we check several common locations.
 */
function ops_config_candidates(): array
{
    $root = ops_root();

    return array(
        $root . '/application/extra/maccms.php',
        $root . '/application/extra/config.php',
        $root . '/application/config.php',
        $root . '/config/maccms.php',
        $root . '/maccms.php',
    );
}

/**
 * Find existing configuration file.
 */
function ops_find_config_file(): ?string
{
    foreach (ops_config_candidates() as $file) {
        if (is_file($file) && is_readable($file)) {
            return $file;
        }
    }

    return null;
}

/**
 * Read configuration file safely as text.
 *
 * We intentionally do NOT include/execute the configuration
 * file here.
 *
 * This prevents arbitrary code in a configuration file
 * from being executed by the management panel.
 */
function ops_read_config_text(): string
{
    static $text = null;

    if ($text !== null) {
        return $text;
    }

    $file = ops_find_config_file();

    if (!$file) {
        $text = '';
        return $text;
    }

    $content = @file_get_contents($file);

    if ($content === false) {
        $text = '';
        return $text;
    }

    $text = $content;

    return $text;
}

/**
 * Extract a configuration value from PHP source.
 *
 * This supports common AppleCMS styles such as:
 *
 * 'cache_host' => 'redis'
 * "cache_host" => "127.0.0.1"
 *
 * $config['cache_host'] = 'redis';
 */
function ops_config_value(array $keys): ?string
{
    $text = ops_read_config_text();

    if ($text === '') {
        return null;
    }

    foreach ($keys as $key) {

        $quotedKey = preg_quote($key, '/');

        /*
         * Array style:
         *
         * 'cache_host' => '127.0.0.1'
         */
        $patterns = array(

            '/[\'"]' . $quotedKey . '[\'"]\s*=>\s*[\'"]([^\'"]*)[\'"]/i',

            '/[\'"]' . $quotedKey . '[\'"]\s*=>\s*([0-9]+)/i',

            '/\$[a-zA-Z0-9_]+\s*\[\s*[\'"]' .
            $quotedKey .
            '[\'"]\s*\]\s*=\s*[\'"]([^\'"]*)[\'"]/i',

            '/define\s*\(\s*[\'"]' .
            $quotedKey .
            '[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]/i',
        );

        foreach ($patterns as $pattern) {

            if (preg_match($pattern, $text, $matches)) {

                if (isset($matches[1])) {

                    $value = trim($matches[1]);

                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        }
    }

    return null;
}

/* ============================================================
 * 5. REDIS CONFIG
 * ============================================================ */

/**
 * Redis configuration.
 *
 * Automatically tries common AppleCMS Redis names.
 */
function ops_redis_config(): array
{
    $host = ops_config_value(array(
        'cache_host',
        'redis_host',
        'cache_redis_host',
        'redis_host_name',
    ));

    $port = ops_config_value(array(
        'cache_port',
        'redis_port',
        'cache_redis_port',
    ));

    $password = ops_config_value(array(
        'cache_password',
        'redis_password',
        'cache_redis_password',
    ));

    $database = ops_config_value(array(
        'cache_database',
        'redis_database',
        'cache_redis_database',
    ));

    /*
     * AppleCMS commonly uses Redis as cache.
     */
    if ($host === null || $host === '') {
        $host = '127.0.0.1';
    }

    if ($port === null || !is_numeric($port)) {
        $port = '6379';
    }

    if ($database === null || !is_numeric($database)) {
        $database = '0';
    }

    return array(
        'host'     => $host,
        'port'     => (int)$port,
        'password' => $password,
        'database' => (int)$database,
    );
}

/* ============================================================
 * 6. REDIS CONNECTION
 * ============================================================ */

/**
 * Connect Redis.
 *
 * Important:
 * - short timeout
 * - no persistent connection for V0.1
 * - connection errors are converted to status data
 */
function ops_redis_connect(): array
{
    if (!class_exists('Redis')) {

        return array(
            'ok'      => false,
            'redis'   => null,
            'error'   => 'PHP Redis extension is not installed.',
            'config'  => ops_redis_config(),
        );
    }

    $config = ops_redis_config();

    try {

        $redis = new Redis();

        $connected = @$redis->connect(
            $config['host'],
            $config['port'],
            1.5
        );

        if (!$connected) {

            return array(
                'ok'     => false,
                'redis'  => null,
                'error'  => 'Redis connection failed.',
                'config' => $config,
            );
        }

        if (
            isset($config['password']) &&
            $config['password'] !== ''
        ) {

            if (!@$redis->auth($config['password'])) {

                return array(
                    'ok'     => false,
                    'redis'  => null,
                    'error'  => 'Redis authentication failed.',
                    'config' => $config,
                );
            }
        }

        if (!@$redis->select($config['database'])) {

            return array(
                'ok'     => false,
                'redis'  => null,
                'error'  => 'Redis database selection failed.',
                'config' => $config,
            );
        }

        return array(
            'ok'     => true,
            'redis'  => $redis,
            'error'  => null,
            'config' => $config,
        );

    } catch (Throwable $e) {

        return array(
            'ok'     => false,
            'redis'  => null,
            'error'  => $e->getMessage(),
            'config' => $config,
        );
    }
}

/* ============================================================
 * 7. REDIS INFO
 * ============================================================ */

/**
 * Format bytes.
 */
function ops_format_bytes($bytes): string
{
    $bytes = (float)$bytes;

    if ($bytes < 1024) {
        return number_format($bytes, 0) . ' B';
    }

    if ($bytes < 1024 * 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }

    if ($bytes < 1024 * 1024 * 1024) {
        return number_format($bytes / 1024 / 1024, 1) . ' MB';
    }

    return number_format(
        $bytes / 1024 / 1024 / 1024,
        2
    ) . ' GB';
}

/**
 * Format seconds.
 */
function ops_format_uptime($seconds): string
{
    $seconds = (int)$seconds;

    $days = intdiv($seconds, 86400);

    $seconds %= 86400;

    $hours = intdiv($seconds, 3600);

    $seconds %= 3600;

    $minutes = intdiv($seconds, 60);

    $seconds %= 60;

    if ($days > 0) {
        return $days . 'd ' .
            $hours . 'h ' .
            $minutes . 'm';
    }

    if ($hours > 0) {
        return $hours . 'h ' .
            $minutes . 'm';
    }

    return $minutes . 'm ' .
        $seconds . 's';
}

/**
 * Get Redis dashboard information.
 *
 * This uses INFO only.
 *
 * IMPORTANT:
 * There is NO SCAN here.
 */
function ops_redis_info(): array
{
    $connection = ops_redis_connect();

    if (!$connection['ok']) {

        return array(
            'ok'     => false,
            'error'  => $connection['error'],
            'config' => $connection['config'],
        );
    }

    /** @var Redis $redis */
    $redis = $connection['redis'];

    try {

        $info = $redis->info();

        $server = isset($info['Server'])
            ? $info['Server']
            : array();

        $memory = isset($info['Memory'])
            ? $info['Memory']
            : array();

        $clients = isset($info['Clients'])
            ? $info['Clients']
            : array();

        $stats = isset($info['Stats'])
            ? $info['Stats']
            : array();

        $keyspace = isset($info['Keyspace'])
            ? $info['Keyspace']
            : array();

        /*
         * Redis sometimes returns the information in
         * a flat array depending on phpredis version.
         */
        $version = '';

        if (isset($server['redis_version'])) {
            $version = (string)$server['redis_version'];
        } elseif (isset($info['redis_version'])) {
            $version = (string)$info['redis_version'];
        }

        $uptime = 0;

        if (isset($server['uptime_in_seconds'])) {
            $uptime = (int)$server['uptime_in_seconds'];
        } elseif (isset($info['uptime_in_seconds'])) {
            $uptime = (int)$info['uptime_in_seconds'];
        }

        $usedMemory = 0;

        if (isset($memory['used_memory'])) {
            $usedMemory = (int)$memory['used_memory'];
        } elseif (isset($info['used_memory'])) {
            $usedMemory = (int)$info['used_memory'];
        }

        $peakMemory = 0;

        if (isset($memory['used_memory_peak'])) {
            $peakMemory = (int)$memory['used_memory_peak'];
        } elseif (isset($info['used_memory_peak'])) {
            $peakMemory = (int)$info['used_memory_peak'];
        }

        $connectedClients = 0;

        if (isset($clients['connected_clients'])) {
            $connectedClients = (int)$clients['connected_clients'];
        } elseif (isset($info['connected_clients'])) {
            $connectedClients = (int)$info['connected_clients'];
        }

        $totalCommands = 0;

        if (isset($stats['total_commands_processed'])) {
            $totalCommands = (int)$stats['total_commands_processed'];
        } elseif (isset($info['total_commands_processed'])) {
            $totalCommands = (int)$info['total_commands_processed'];
        }

        $hits = 0;

        if (isset($stats['keyspace_hits'])) {
            $hits = (int)$stats['keyspace_hits'];
        } elseif (isset($info['keyspace_hits'])) {
            $hits = (int)$info['keyspace_hits'];
        }

        $misses = 0;

        if (isset($stats['keyspace_misses'])) {
            $misses = (int)$stats['keyspace_misses'];
        } elseif (isset($info['keyspace_misses'])) {
            $misses = (int)$info['keyspace_misses'];
        }

        $hitRate = 0;

        if (($hits + $misses) > 0) {

            $hitRate =
                ($hits / ($hits + $misses)) * 100;
        }

        /*
         * Keyspace:
         *
         * db0 => keys=123,expires=100,avg_ttl=...
         */
        $databaseCount = 0;
        $databaseKeys = 0;
        $databaseExpires = 0;

        foreach ($keyspace as $dbName => $dbData) {

            if (!is_string($dbName)) {
                continue;
            }

            if (strpos($dbName, 'db') !== 0) {
                continue;
            }

            if (!is_array($dbData)) {
                continue;
            }

            $databaseCount++;

            if (isset($dbData['keys'])) {
                $databaseKeys += (int)$dbData['keys'];
            }

            if (isset($dbData['expires'])) {
                $databaseExpires += (int)$dbData['expires'];
            }
        }

        /*
         * Redis role.
         */
        $role = '';

        if (isset($info['Replication']['role'])) {
            $role = (string)$info['Replication']['role'];
        } elseif (isset($info['role'])) {
            $role = (string)$info['role'];
        }

        /*
         * Memory fragmentation ratio.
         */
        $fragmentation = 0;

        if (isset($memory['mem_fragmentation_ratio'])) {
            $fragmentation =
                (float)$memory['mem_fragmentation_ratio'];
        } elseif (isset($info['mem_fragmentation_ratio'])) {
            $fragmentation =
                (float)$info['mem_fragmentation_ratio'];
        }

        return array(
            'ok' => true,

            'version' => $version,

            'uptime' => $uptime,

            'uptime_text' =>
                ops_format_uptime($uptime),

            'memory' => $usedMemory,

            'memory_text' =>
                ops_format_bytes($usedMemory),

            'peak_memory' => $peakMemory,

            'peak_memory_text' =>
                ops_format_bytes($peakMemory),

            'clients' => $connectedClients,

            'commands' => $totalCommands,

            'hits' => $hits,

            'misses' => $misses,

            'hit_rate' =>
                round($hitRate, 2),

            'keys' => $databaseKeys,

            'expires' => $databaseExpires,

            'databases' => $databaseCount,

            'fragmentation' =>
                round($fragmentation, 3),

            'role' => $role !== ''
                ? $role
                : 'unknown',

            'config' => array(
                'host' =>
                    $connection['config']['host'],

                'port' =>
                    $connection['config']['port'],

                'database' =>
                    $connection['config']['database'],
            ),
        );

    } catch (Throwable $e) {

        return array(
            'ok' => false,
            'error' => $e->getMessage(),
            'config' => $connection['config'],
        );
    }
}

/* ============================================================
 * 8. MEILISEARCH CONFIG
 * ============================================================ */

/**
 * Meilisearch configuration.
 */
function ops_meili_config(): array
{
    $host = ops_config_value(array(
        'meilisearch_host',
        'meili_host',
        'search_host',
    ));

    $index = ops_config_value(array(
        'meilisearch_index_uid',
        'meili_index_uid',
        'index_uid',
        'meilisearch_uid',
    ));

    $key = ops_config_value(array(
        'meilisearch_api_key',
        'meili_api_key',
        'search_api_key',
    ));

    /*
     * Common default in your environment.
     */
    if ($host === null || $host === '') {
        $host = 'http://meilisearch:7700';
    }

    return array(
        'host'  => rtrim($host, '/'),
        'index' => $index ?: '',
        'key'   => $key ?: '',
    );
}

/* ============================================================
 * 9. MEILISEARCH REQUEST
 * ============================================================ */

/**
 * Make a small HTTP request to Meilisearch.
 *
 * cURL preferred.
 * stream fallback provided.
 */
function ops_meili_request(
    string $url,
    string $apiKey = '',
    string $method = 'GET',
    ?string $body = null,
    float $timeout = 2.0
): array {

    $headers = array(
        'Accept: application/json',
    );

    if ($apiKey !== '') {
        $headers[] =
            'Authorization: Bearer ' . $apiKey;
    }

    if (function_exists('curl_init')) {

        $ch = curl_init();

        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => false,
        ));

        if ($body !== null) {

            $headers[] =
                'Content-Type: application/json';

            curl_setopt(
                $ch,
                CURLOPT_HTTPHEADER,
                $headers
            );

            curl_setopt(
                $ch,
                CURLOPT_POSTFIELDS,
                $body
            );
        }

        $response = curl_exec($ch);

        $error = curl_error($ch);

        $status = (int)curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

        curl_close($ch);

        if ($response === false) {

            return array(
                'ok' => false,
                'status' => $status,
                'data' => null,
                'error' => $error ?: 'Meilisearch request failed.',
            );
        }

        $data = json_decode(
            $response,
            true
        );

        return array(
            'ok' =>
                $status >= 200 &&
                $status < 300,

            'status' => $status,

            'data' => is_array($data)
                ? $data
                : null,

            'error' =>
                ($status >= 200 && $status < 300)
                    ? null
                    : $response,
        );
    }

    /*
     * Fallback for servers without cURL.
     */
    $context = stream_context_create(array(
        'http' => array(
            'method' =>
                $method,

            'timeout' =>
                $timeout,

            'ignore_errors' =>
                true,

            'header' =>
                implode("\r\n", $headers),
        ),
    ));

    $response = @file_get_contents(
        $url,
        false,
        $context
    );

    if ($response === false) {

        return array(
            'ok' => false,
            'status' => 0,
            'data' => null,
            'error' =>
                'Unable to connect to Meilisearch.',
        );
    }

    $data = json_decode(
        $response,
        true
    );

    return array(
        'ok' => true,
        'status' => 200,
        'data' => is_array($data)
            ? $data
            : null,
        'error' => null,
    );
}

/* ============================================================
 * 10. MEILISEARCH INFO
 * ============================================================ */

/**
 * Get Meilisearch status.
 *
 * Only lightweight endpoints are called.
 *
 * No document search is performed.
 */
function ops_meili_info(): array
{
    $config = ops_meili_config();

    if ($config['host'] === '') {

        return array(
            'ok' => false,
            'configured' => false,
            'error' => 'Meilisearch host not found.',
        );
    }

    $health = ops_meili_request(
        $config['host'] . '/health',
        $config['key'],
        'GET',
        null,
        1.5
    );

    if (!$health['ok']) {

        return array(
            'ok' => false,
            'configured' => true,
            'online' => false,
            'host' => $config['host'],
            'index' => $config['index'],
            'error' => $health['error'],
        );
    }

    $result = array(
        'ok' => true,
        'configured' => true,
        'online' => true,
        'host' => $config['host'],
        'index' => $config['index'],
        'health' =>
            isset($health['data']['status'])
                ? $health['data']['status']
                : 'available',
    );

    /*
     * Get index stats only when an index UID exists.
     */
    if ($config['index'] !== '') {

        $indexUrl =
            $config['host'] .
            '/indexes/' .
            rawurlencode($config['index']) .
            '/stats';

        $stats = ops_meili_request(
            $indexUrl,
            $config['key'],
            'GET',
            null,
            2.0
        );

        if ($stats['ok'] && is_array($stats['data'])) {

            $data = $stats['data'];

            $result['documents'] =
                isset($data['numberOfDocuments'])
                    ? (int)$data['numberOfDocuments']
                    : null;

            $result['is_indexing'] =
                isset($data['isIndexing'])
                    ? (bool)$data['isIndexing']
                    : false;
        }
    }

    /*
     * Get version.
     */
    $version = ops_meili_request(
        $config['host'] . '/version',
        $config['key'],
        'GET',
        null,
        1.5
    );

    if ($version['ok'] && is_array($version['data'])) {

        if (
            isset($version['data']['pkgVersion'])
        ) {

            $result['version'] =
                (string)$version['data']['pkgVersion'];
        }
    }

    return $result;
}

/* ============================================================
 * 11. DASHBOARD API
 * ============================================================ */

/**
 * Complete dashboard payload.
 *
 * This function intentionally does not perform
 * expensive Redis SCAN operations.
 */
function ops_dashboard(): array
{
    $started = microtime(true);

    $redis = ops_redis_info();

    $meili = ops_meili_info();

    return array(
        'ok' => true,

        'version' => OPS_VERSION,

        'php' => PHP_VERSION,

        'server_time' =>
            date('Y-m-d H:i:s'),

        'root' => ops_root(),

        'config_file' =>
            ops_find_config_file(),

        'redis' => $redis,

        'meilisearch' => $meili,

        'response_ms' =>
            round(
                (microtime(true) - $started) * 1000,
                2
            ),
    );
}

/* ============================================================
 * 12. AJAX ROUTER
 * ============================================================ */

$action = ops_action();

if ($action !== '') {

    switch ($action) {

        case 'dashboard':

            ops_json(
                ops_dashboard()
            );

            break;

        case 'redis_info':

            ops_json(
                ops_redis_info()
            );

            break;

        case 'meili_info':

            ops_json(
                ops_meili_info()
            );

            break;

        case 'ping':

            ops_json(array(
                'ok' => true,
                'version' => OPS_VERSION,
                'time' => microtime(true),
            ));

            break;

        default:

            ops_json(array(
                'ok' => false,
                'error' => 'Unknown action.',
            ), 404);
    }
}

/* ============================================================
 * 13. HTML
 * ============================================================ */

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<meta
    name="robots"
    content="noindex,nofollow"
>

<title>AppleCMS OPS</title>

<style>

/* ============================================================
 * GLOBAL
 * ============================================================ */

:root {
    --bg: #0b0f14;
    --panel: #111720;
    --panel-2: #151d27;
    --panel-3: #1b2531;

    --border: rgba(255,255,255,.07);

    --text: #eef2f7;
    --muted: #8b98a8;

    --green: #38d996;
    --red: #ff5c70;
    --yellow: #f4c95d;
    --blue: #62a8ff;

    --radius: 14px;

    --sidebar-width: 230px;
}

/* ============================================================
 * RESET
 * ============================================================ */

* {
    box-sizing: border-box;
}

html,
body {
    margin: 0;
    padding: 0;
    min-height: 100%;
}

body {
    background: var(--bg);
    color: var(--text);

    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        Roboto,
        Helvetica,
        Arial,
        sans-serif;

    font-size: 14px;

    -webkit-font-smoothing: antialiased;
}

button,
input {
    font: inherit;
}

/* ============================================================
 * LAYOUT
 * ============================================================ */

.app {
    min-height: 100vh;
    display: flex;
}

/* ============================================================
 * SIDEBAR
 * ============================================================ */

.sidebar {
    width: var(--sidebar-width);

    position: fixed;
    left: 0;
    top: 0;
    bottom: 0;

    background:
        linear-gradient(
            180deg,
            #10161e 0%,
            #0d1218 100%
        );

    border-right: 1px solid var(--border);

    padding: 20px 14px;

    z-index: 20;
}

.logo {
    display: flex;
    align-items: center;
    gap: 11px;

    padding: 6px 9px 22px;
}

.logo-icon {
    width: 38px;
    height: 38px;

    border-radius: 11px;

    display: flex;
    align-items: center;
    justify-content: center;

    background:
        linear-gradient(
            135deg,
            #477bff,
            #8f5cff
        );

    color: #fff;

    font-weight: 800;

    box-shadow:
        0 8px 24px
        rgba(71,123,255,.25);
}

.logo-title {
    font-size: 15px;
    font-weight: 700;
}

.logo-version {
    color: var(--muted);
    font-size: 11px;
    margin-top: 2px;
}

.nav-section {
    margin: 14px 8px 7px;

    color: #5f6c7b;

    font-size: 10px;
    font-weight: 700;

    text-transform: uppercase;
    letter-spacing: .08em;
}

.nav-item {
    width: 100%;

    border: 0;
    background: transparent;

    color: #aeb8c4;

    text-align: left;

    padding: 10px 11px;

    margin-bottom: 3px;

    border-radius: 9px;

    cursor: pointer;

    transition:
        background .15s ease,
        color .15s ease;
}

.nav-item:hover {
    background: rgba(255,255,255,.045);
    color: #fff;
}

.nav-item.active {
    background: rgba(98,168,255,.12);
    color: #fff;
}

.nav-icon {
    display: inline-block;

    width: 22px;

    color: #718095;
}

.nav-item.active .nav-icon {
    color: var(--blue);
}

/* ============================================================
 * MAIN
 * ============================================================ */

.main {
    margin-left: var(--sidebar-width);

    width: calc(100% - var(--sidebar-width));

    min-height: 100vh;

    padding: 24px;
}

/* ============================================================
 * TOPBAR
 * ============================================================ */

.topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;

    margin-bottom: 24px;
}

.page-title {
    font-size: 23px;
    font-weight: 700;

    margin: 0;
}

.page-subtitle {
    color: var(--muted);

    font-size: 12px;

    margin-top: 5px;
}

.top-actions {
    display: flex;
    align-items: center;
    gap: 10px;
}

.refresh-time {
    color: var(--muted);
    font-size: 11px;
}

.refresh-btn {
    border: 1px solid var(--border);

    background: var(--panel);

    color: #c9d2dd;

    padding: 8px 12px;

    border-radius: 8px;

    cursor: pointer;
}

.refresh-btn:hover {
    background: var(--panel-2);
    color: #fff;
}

/* ============================================================
 * STATUS
 * ============================================================ */

.status-pill {
    display: inline-flex;

    align-items: center;

    gap: 7px;

    padding: 6px 10px;

    border-radius: 999px;

    background: rgba(255,255,255,.04);

    color: var(--muted);

    font-size: 11px;
}

.status-dot {
    width: 7px;
    height: 7px;

    border-radius: 50%;

    background: #657180;
}

.status-pill.online .status-dot {
    background: var(--green);

    box-shadow:
        0 0 0 3px
        rgba(56,217,150,.08);
}

.status-pill.online {
    color: #b8eedd;
}

/* ============================================================
 * GRID
 * ============================================================ */

.cards {
    display: grid;

    grid-template-columns:
        repeat(4, minmax(0, 1fr));

    gap: 14px;

    margin-bottom: 14px;
}

.card {
    background: var(--panel);

    border:
        1px solid var(--border);

    border-radius: var(--radius);

    padding: 17px;

    min-width: 0;
}

.card-label {
    color: var(--muted);

    font-size: 11px;

    margin-bottom: 9px;
}

.card-value {
    font-size: 23px;

    font-weight: 700;

    letter-spacing: -.02em;

    white-space: nowrap;

    overflow: hidden;

    text-overflow: ellipsis;
}

.card-extra {
    color: #697789;

    font-size: 10px;

    margin-top: 7px;
}

/* ============================================================
 * TWO COLUMN
 * ============================================================ */

.two-columns {
    display: grid;

    grid-template-columns:
        minmax(0, 1fr)
        minmax(0, 1fr);

    gap: 14px;

    margin-bottom: 14px;
}

/* ============================================================
 * PANEL
 * ============================================================ */

.panel {
    background: var(--panel);

    border:
        1px solid var(--border);

    border-radius: var(--radius);

    overflow: hidden;
}

.panel-header {
    padding: 15px 17px;

    border-bottom:
        1px solid var(--border);

    display: flex;

    align-items: center;

    justify-content: space-between;
}

.panel-title {
    font-size: 13px;
    font-weight: 700;
}

.panel-body {
    padding: 17px;
}

/* ============================================================
 * SERVICE
 * ============================================================ */

.service {
    display: flex;

    align-items: center;

    justify-content: space-between;

    padding: 11px 0;

    border-bottom:
        1px solid rgba(255,255,255,.045);
}

.service:last-child {
    border-bottom: 0;
}

.service-name {
    color: #cbd3dd;
}

.service-value {
    color: #8996a6;

    font-size: 12px;
}

.service-value.ok {
    color: var(--green);
}

.service-value.error {
    color: var(--red);
}

/* ============================================================
 * REDIS METRICS
 * ============================================================ */

.metric-grid {
    display: grid;

    grid-template-columns:
        repeat(2, minmax(0, 1fr));

    gap: 10px;
}

.metric {
    background: var(--panel-2);

    border-radius: 10px;

    padding: 12px;
}

.metric-label {
    color: var(--muted);

    font-size: 10px;

    margin-bottom: 6px;
}

.metric-value {
    font-size: 15px;

    font-weight: 650;
}

/* ============================================================
 * LOADING
 * ============================================================ */

.loading {
    color: var(--muted);
}

.skeleton {
    display: inline-block;

    width: 80px;
    height: 17px;

    border-radius: 5px;

    background:
        linear-gradient(
            90deg,
            #171f29,
            #202a36,
            #171f29
        );

    background-size: 200% 100%;

    animation:
        skeleton 1.3s infinite;
}

@keyframes skeleton {

    0% {
        background-position:
            200% 0;
    }

    100% {
        background-position:
            -200% 0;
    }
}

/* ============================================================
 * FOOTER
 * ============================================================ */

.footer {
    color: #596676;

    font-size: 10px;

    text-align: center;

    padding: 22px 0 8px;
}

/* ============================================================
 * RESPONSIVE
 * ============================================================ */

@media (max-width: 1100px) {

    .cards {
        grid-template-columns:
            repeat(2, minmax(0, 1fr));
    }

    .two-columns {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 720px) {

    :root {
        --sidebar-width: 0px;
    }

    .sidebar {
        display: none;
    }

    .main {
        margin-left: 0;

        width: 100%;

        padding: 15px;
    }

    .cards {
        grid-template-columns: 1fr;
    }

    .topbar {
        align-items: flex-start;

        gap: 12px;

        flex-direction: column;
    }
}

</style>
</head>

<body>

<div class="app">

    <!-- =====================================================
         SIDEBAR
         ===================================================== -->

    <aside class="sidebar">

        <div class="logo">

            <div class="logo-icon">
                A
            </div>

            <div>
                <div class="logo-title">
                    AppleCMS OPS
                </div>

                <div class="logo-version">
                    v<?= ops_e(OPS_VERSION) ?>
                </div>
            </div>

        </div>

        <div class="nav-section">
            Overview
        </div>

        <button
            class="nav-item active"
            type="button"
            data-page="dashboard"
        >
            <span class="nav-icon">⌂</span>
            Dashboard
        </button>

        <div class="nav-section">
            Services
        </div>

        <button
            class="nav-item"
            type="button"
            data-page="redis"
        >
            <span class="nav-icon">R</span>
            Redis
        </button>

        <button
            class="nav-item"
            type="button"
            data-page="meili"
        >
            <span class="nav-icon">M</span>
            Meilisearch
        </button>

        <div class="nav-section">
            AppleCMS
        </div>

        <button
            class="nav-item"
            type="button"
            data-page="applecms"
        >
            <span class="nav-icon">A</span>
            AppleCMS
        </button>

        <button
            class="nav-item"
            type="button"
            data-page="diagnosis"
        >
            <span class="nav-icon">✓</span>
            Diagnosis
        </button>

    </aside>


    <!-- =====================================================
         MAIN
         ===================================================== -->

    <main class="main">

        <div class="topbar">

            <div>

                <h1 class="page-title">
                    Dashboard
                </h1>

                <div class="page-subtitle">
                    AppleCMS infrastructure overview
                </div>

            </div>

            <div class="top-actions">

                <span
                    class="refresh-time"
                    id="refreshTime"
                >
                    Waiting...
                </span>

                <span
                    class="status-pill"
                    id="globalStatus"
                >
                    <span class="status-dot"></span>
                    Checking
                </span>

                <button
                    type="button"
                    class="refresh-btn"
                    id="refreshBtn"
                >
                    Refresh
                </button>

            </div>

        </div>


        <!-- =================================================
             TOP CARDS
             ================================================= -->

        <section class="cards">

            <div class="card">

                <div class="card-label">
                    Redis Memory
                </div>

                <div
                    class="card-value"
                    id="redisMemory"
                >
                    <span class="skeleton"></span>
                </div>

                <div
                    class="card-extra"
                    id="redisPeak"
                >
                    Peak: -
                </div>

            </div>


            <div class="card">

                <div class="card-label">
                    Redis Keys
                </div>

                <div
                    class="card-value"
                    id="redisKeys"
                >
                    <span class="skeleton"></span>
                </div>

                <div
                    class="card-extra"
                    id="redisExpires"
                >
                    Expiring: -
                </div>

            </div>


            <div class="card">

                <div class="card-label">
                    Redis Hit Rate
                </div>

                <div
                    class="card-value"
                    id="redisHitRate"
                >
                    <span class="skeleton"></span>
                </div>

                <div
                    class="card-extra"
                    id="redisClients"
                >
                    Clients: -
                </div>

            </div>


            <div class="card">

                <div class="card-label">
                    Meilisearch Documents
                </div>

                <div
                    class="card-value"
                    id="meiliDocuments"
                >
                    <span class="skeleton"></span>
                </div>

                <div
                    class="card-extra"
                    id="meiliVersion"
                >
                    Version: -
                </div>

            </div>

        </section>


        <!-- =================================================
             SERVICE STATUS
             ================================================= -->

        <section class="two-columns">


            <div class="panel">

                <div class="panel-header">

                    <div class="panel-title">
                        Services
                    </div>

                </div>

                <div class="panel-body">

                    <div class="service">

                        <div class="service-name">
                            PHP
                        </div>

                        <div
                            class="service-value ok"
                            id="phpVersion"
                        >
                            PHP <?= ops_e(PHP_VERSION) ?>
                        </div>

                    </div>


                    <div class="service">

                        <div class="service-name">
                            Redis
                        </div>

                        <div
                            class="service-value"
                            id="redisStatus"
                        >
                            Checking...
                        </div>

                    </div>


                    <div class="service">

                        <div class="service-name">
                            Meilisearch
                        </div>

                        <div
                            class="service-value"
                            id="meiliStatus"
                        >
                            Checking...
                        </div>

                    </div>


                    <div class="service">

                        <div class="service-name">
                            AppleCMS Config
                        </div>

                        <div
                            class="service-value"
                            id="configStatus"
                        >
                            Checking...
                        </div>

                    </div>

                </div>

            </div>


            <div class="panel">

                <div class="panel-header">

                    <div class="panel-title">
                        Redis
                    </div>

                </div>

                <div class="panel-body">

                    <div class="metric-grid">

                        <div class="metric">

                            <div class="metric-label">
                                Version
                            </div>

                            <div
                                class="metric-value"
                                id="redisVersion"
                            >
                                -
                            </div>

                        </div>


                        <div class="metric">

                            <div class="metric-label">
                                Uptime
                            </div>

                            <div
                                class="metric-value"
                                id="redisUptime"
                            >
                                -
                            </div>

                        </div>


                        <div class="metric">

                            <div class="metric-label">
                                Clients
                            </div>

                            <div
                                class="metric-value"
                                id="redisClientMetric"
                            >
                                -
                            </div>

                        </div>


                        <div class="metric">

                            <div class="metric-label">
                                Role
                            </div>

                            <div
                                class="metric-value"
                                id="redisRole"
                            >
                                -
                            </div>

                        </div>


                        <div class="metric">

                            <div class="metric-label">
                                Commands
                            </div>

                            <div
                                class="metric-value"
                                id="redisCommands"
                            >
                                -
                            </div>

                        </div>


                        <div class="metric">

                            <div class="metric-label">
                                Fragmentation
                            </div>

                            <div
                                class="metric-value"
                                id="redisFragmentation"
                            >
                                -
                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </section>


        <!-- =================================================
             MEILISEARCH
             ================================================= -->

        <section class="panel">

            <div class="panel-header">

                <div class="panel-title">
                    Meilisearch
                </div>

                <div
                    class="status-pill"
                    id="meiliPill"
                >
                    <span class="status-dot"></span>
                    Checking
                </div>

            </div>

            <div class="panel-body">

                <div class="metric-grid">

                    <div class="metric">

                        <div class="metric-label">
                            Host
                        </div>

                        <div
                            class="metric-value"
                            id="meiliHost"
                            style="font-size:12px;"
                        >
                            -
                        </div>

                    </div>


                    <div class="metric">

                        <div class="metric-label">
                            Index
                        </div>

                        <div
                            class="metric-value"
                            id="meiliIndex"
                        >
                            -
                        </div>

                    </div>


                    <div class="metric">

                        <div class="metric-label">
                            Documents
                        </div>

                        <div
                            class="metric-value"
                            id="meiliDocsMetric"
                        >
                            -
                        </div>

                    </div>


                    <div class="metric">

                        <div class="metric-label">
                            Indexing
                        </div>

                        <div
                            class="metric-value"
                            id="meiliIndexing"
                        >
                            -
                        </div>

                    </div>

                </div>

            </div>

        </section>


        <div class="footer">

            AppleCMS OPS
            ·
            <?= ops_e(OPS_VERSION) ?>
            ·
            Response:
            <span id="responseMs">-</span> ms

        </div>

    </main>

</div>


<script>

/* ============================================================
 * APPLECMS OPS FRONTEND
 * ============================================================ */

(function () {

    'use strict';


    const $ = function (selector) {
        return document.querySelector(selector);
    };


    const formatNumber = function (value) {

        if (
            value === null ||
            value === undefined ||
            value === ''
        ) {
            return '-';
        }

        const number = Number(value);

        if (!Number.isFinite(number)) {
            return String(value);
        }

        return number.toLocaleString();
    };


    const setText = function (
        selector,
        value
    ) {

        const element = $(selector);

        if (element) {
            element.textContent =
                value === null ||
                value === undefined ||
                value === ''
                    ? '-'
                    : String(value);
        }
    };


    const setStatus = function (
        selector,
        online,
        text
    ) {

        const element = $(selector);

        if (!element) {
            return;
        }

        element.classList.remove(
            'online'
        );

        if (online) {
            element.classList.add(
                'online'
            );
        }

        const textNode =
            element.querySelector(
                '.status-dot'
            );

        if (textNode) {
            /*
             * Keep dot element intact.
             */
        }

        /*
         * If the pill has text outside the dot,
         * update it safely.
         */
        if (
            element.childNodes.length > 1
        ) {

            for (
                let i = element.childNodes.length - 1;
                i >= 0;
                i--
            ) {

                const node =
                    element.childNodes[i];

                if (
                    node.nodeType ===
                    Node.TEXT_NODE
                ) {
                    node.remove();
                }
            }

            element.appendChild(
                document.createTextNode(
                    ' ' + text
                )
            );

        } else {

            element.appendChild(
                document.createTextNode(
                    ' ' + text
                )
            );
        }
    };


    const loadDashboard = async function () {

        const refresh =
            $('#refreshBtn');

        if (refresh) {
            refresh.disabled = true;
            refresh.textContent =
                'Loading...';
        }

        const started =
            performance.now();

        try {

            const response =
                await fetch(
                    '?action=dashboard',
                    {
                        method: 'GET',
                        cache: 'no-store',
                        credentials: 'same-origin'
                    }
                );

            if (!response.ok) {
                throw new Error(
                    'HTTP ' +
                    response.status
                );
            }

            const data =
                await response.json();

            if (!data.ok) {
                throw new Error(
                    data.error ||
                    'Dashboard request failed.'
                );
            }

            updateDashboard(data);

        } catch (error) {

            console.error(
                'AppleCMS OPS:',
                error
            );

            setStatus(
                '#globalStatus',
                false,
                'Error'
            );

            setText(
                '#redisStatus',
                'Request failed'
            );

            setText(
                '#meiliStatus',
                'Request failed'
            );

        } finally {

            if (refresh) {
                refresh.disabled = false;
                refresh.textContent =
                    'Refresh';
            }

            const elapsed =
                performance.now() -
                started;

            /*
             * Frontend request time.
             */
            setText(
                '#responseMs',
                elapsed.toFixed(1)
            );

            const now =
                new Date();

            setText(
                '#refreshTime',
                now.toLocaleTimeString()
            );
        }
    };


    const updateDashboard =
        function (data) {

            const redis =
                data.redis || {};

            const meili =
                data.meilisearch || {};


            /*
             * Global status.
             */
            const redisOnline =
                redis.ok === true;

            const meiliOnline =
                meili.online === true;

            const overall =
                redisOnline ||
                meiliOnline;

            setStatus(
                '#globalStatus',
                overall,
                overall
                    ? 'Online'
                    : 'Offline'
            );


            /*
             * Redis.
             */
            if (redisOnline) {

                setText(
                    '#redisMemory',
                    redis.memory_text
                );

                setText(
                    '#redisPeak',
                    'Peak: ' +
                    (
                        redis.peak_memory_text ||
                        '-'
                    )
                );

                setText(
                    '#redisKeys',
                    formatNumber(
                        redis.keys
                    )
                );

                setText(
                    '#redisExpires',
                    'Expiring: ' +
                    formatNumber(
                        redis.expires
                    )
                );

                setText(
                    '#redisHitRate',
                    (
                        redis.hit_rate !==
                        undefined
                            ? redis.hit_rate +
                              '%'
                            : '-'
                    )
                );

                setText(
                    '#redisClients',
                    'Clients: ' +
                    formatNumber(
                        redis.clients
                    )
                );

                setText(
                    '#redisStatus',
                    'Online'
                );

                $('#redisStatus')
                    .classList.add(
                        'ok'
                    );

                setText(
                    '#redisVersion',
                    redis.version
                );

                setText(
                    '#redisUptime',
                    redis.uptime_text
                );

                setText(
                    '#redisClientMetric',
                    formatNumber(
                        redis.clients
                    )
                );

                setText(
                    '#redisRole',
                    redis.role
                );

                setText(
                    '#redisCommands',
                    formatNumber(
                        redis.commands
                    )
                );

                setText(
                    '#redisFragmentation',
                    redis.fragmentation
                );

            } else {

                setText(
                    '#redisMemory',
                    'Offline'
                );

                setText(
                    '#redisKeys',
                    '-'
                );

                setText(
                    '#redisHitRate',
                    '-'
                );

                setText(
                    '#redisStatus',
                    redis.error ||
                    'Offline'
                );

                $('#redisStatus')
                    .classList.remove(
                        'ok'
                    );
            }


            /*
             * Meilisearch.
             */
            if (meiliOnline) {

                setText(
                    '#meiliDocuments',
                    meili.documents !==
                    undefined
                        ? formatNumber(
                            meili.documents
                        )
                        : '-'
                );

                setText(
                    '#meiliVersion',
                    'Version: ' +
                    (
                        meili.version ||
                        '-'
                    )
                );

                setText(
                    '#meiliStatus',
                    'Online'
                );

                $('#meiliStatus')
                    .classList.add(
                        'ok'
                    );

                setText(
                    '#meiliHost',
                    meili.host
                );

                setText(
                    '#meiliIndex',
                    meili.index ||
                    '-'
                );

                setText(
                    '#meiliDocsMetric',
                    meili.documents !==
                    undefined
                        ? formatNumber(
                            meili.documents
                        )
                        : '-'
                );

                setText(
                    '#meiliIndexing',
                    meili.is_indexing
                        ? 'Yes'
                        : 'No'
                );

                setStatus(
                    '#meiliPill',
                    true,
                    'Online'
                );

            } else {

                setText(
                    '#meiliDocuments',
                    'Offline'
                );

                setText(
                    '#meiliStatus',
                    meili.error ||
                    'Offline'
                );

                $('#meiliStatus')
                    .classList.remove(
                        'ok'
                    );

                setStatus(
                    '#meiliPill',
                    false,
                    'Offline'
                );
            }


            /*
             * AppleCMS configuration.
             */
            if (data.config_file) {

                setText(
                    '#configStatus',
                    'Detected'
                );

                $('#configStatus')
                    .classList.add(
                        'ok'
                    );

            } else {

                setText(
                    '#configStatus',
                    'Not detected'
                );

                $('#configStatus')
                    .classList.remove(
                        'ok'
                    );
            }


            /*
             * Backend response time.
             */
            setText(
                '#responseMs',
                data.response_ms
            );
        };


    /*
     * Refresh button.
     */
    const refreshButton =
        $('#refreshBtn');

    if (refreshButton) {

        refreshButton.addEventListener(
            'click',
            loadDashboard
        );
    }


    /*
     * Sidebar currently acts as navigation
     * placeholder.
     *
     * Explorer / Meilisearch / Diagnosis
     * will be connected in the next versions.
     */
    document
        .querySelectorAll(
            '.nav-item'
        )
        .forEach(function (item) {

            item.addEventListener(
                'click',
                function () {

                    document
                        .querySelectorAll(
                            '.nav-item'
                        )
                        .forEach(
                            function (nav) {
                                nav.classList
                                    .remove(
                                        'active'
                                    );
                            }
                        );

                    item.classList.add(
                        'active'
                    );

                    /*
                     * V0.1 only contains
                     * Dashboard.
                     *
                     * Other pages will be
                     * activated in later
                     * commits.
                     */
                }
            );
        });


    /*
     * Initial load.
     */
    loadDashboard();


})();

</script>

</body>
</html>
```
