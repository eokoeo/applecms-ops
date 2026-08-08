<?php
/**
 * AppleCMS OPS - Redis Manager (Fixed & Optimized)
 * 
 * 修复重点：
 * 1. 默认无参访问时自动使用 AppleCMS 的 cache_flag 作为初始搜索关键字。
 * 2. 优化 SCAN 匹配逻辑，采用全包围通配符 (*keyword*)，确保能顺利搜到 xgplayer4_page 等缓存。
 * 3. 完善批量删除、全库清空及防重刷跳转（PRG 模式）。
 */
declare(strict_types=1);

session_start();
require_once 'login.php';
 
/*
$APP_ROOT = realpath(__DIR__ . '/..');

if ($APP_ROOT === false) {
    $APP_ROOT = dirname(__DIR__);
}

$MACCMS_CONFIG = $APP_ROOT . '/application/extra/maccms.php';
*/
/* =========================================================
 * 工具函数
 * ======================================================= */

function h($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}


/**
 * 安全读取 PHP 配置
 */
function loadPhpConfig(string $file): array
{
    if (!is_file($file) || !is_readable($file)) {
        return [];
    }

    try {
        $data = include $file;
        return is_array($data) ? $data : [];
    } catch (Throwable $e) {
        return [];
    }
}


/**
 * 递归寻找配置
 */
function findConfigValue(
    array $data,
    string $targetKey,
    &$found = false
) {
    foreach ($data as $key => $value) {
        if ((string)$key === $targetKey) {
            $found = true;
            return $value;
        }

        if (is_array($value)) {
            $result = findConfigValue(
                $value,
                $targetKey,
                $found
            );

            if ($found) {
                return $result;
            }
        }
    }

    return null;
}


/**
 * 隐藏密码
 */
function maskSecret($value): string
{
    if ($value === null || $value === '') {
        return '';
    }

    $value = (string)$value;
    $length = strlen($value);

    if ($length <= 4) {
        return '****';
    }

    return substr($value, 0, 2)
        . str_repeat('*', max(4, $length - 4))
        . substr($value, -2);
}


/**
 * 状态标签
 */
function statusBadge(
    bool $ok,
    string $success = '成功连接',
    string $failed = '连接失败'
): string {
    if ($ok) {
        return '<span class="status success">'
            . '<span class="dot"></span>'
            . h($success)
            . '</span>';
    }

    return '<span class="status danger">'
        . '<span class="dot"></span>'
        . h($failed)
        . '</span>';
}


/**
 * 格式化字节
 */
function formatBytes($bytes): string
{
    $bytes = (float)$bytes;

    if ($bytes <= 0) {
        return '0 B';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;

    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }

    return number_format($bytes, 2) . ' ' . $units[$i];
}


/**
 * 格式化时间
 */
function formatSeconds($seconds): string
{
    $seconds = (int)$seconds;

    if ($seconds <= 0) {
        return '0 秒';
    }

    $days = intdiv($seconds, 86400);
    $seconds %= 86400;
    $hours = intdiv($seconds, 3600);
    $seconds %= 3600;
    $minutes = intdiv($seconds, 60);
    $seconds %= 60;

    $parts = [];

    if ($days > 0) {
        $parts[] = $days . ' 天';
    }
    if ($hours > 0) {
        $parts[] = $hours . ' 小时';
    }
    if ($minutes > 0) {
        $parts[] = $minutes . ' 分钟';
    }
    if ($seconds > 0 && count($parts) < 2) {
        $parts[] = $seconds . ' 秒';
    }

    return implode(' ', $parts);
}


/* =========================================================
 * AppleCMS 配置加载
 * ======================================================= */

$maccmsConfig = loadPhpConfig($MACCMS_CONFIG);

$redisHostFound = false;
$redisPortFound = false;
$redisUsernameFound = false;
$redisPasswordFound = false;
$redisDbFound = false;
$redisFlagFound = false;
$redisCoreFound = false;
$redisTimeFound = false;
$redisPageFound = false;
$redisTimePageFound = false;

$redisHost = findConfigValue($maccmsConfig, 'cache_host', $redisHostFound);
$redisPort = findConfigValue($maccmsConfig, 'cache_port', $redisPortFound);
$redisUsername = findConfigValue($maccmsConfig, 'cache_username', $redisUsernameFound);
$redisPassword = findConfigValue($maccmsConfig, 'cache_password', $redisPasswordFound);
$redisDb = findConfigValue($maccmsConfig, 'cache_db', $redisDbFound);
$redisFlag = findConfigValue($maccmsConfig, 'cache_flag', $redisFlagFound);
$redisCore = findConfigValue($maccmsConfig, 'cache_core', $redisCoreFound);
$redisCacheTime = findConfigValue($maccmsConfig, 'cache_time', $redisTimeFound);
$redisCachePage = findConfigValue($maccmsConfig, 'cache_page', $redisPageFound);
$redisCacheTimePage = findConfigValue($maccmsConfig, 'cache_time_page', $redisTimePageFound);

if (!$redisPortFound || !$redisPort) {
    $redisPort = 6379;
}

if (!$redisDbFound || $redisDb === '') {
    $redisDb = 0;
}

$redisHost = (string)($redisHost ?? '');
$redisUsername = (string)($redisUsername ?? '');
$redisPassword = (string)($redisPassword ?? '');
$redisFlag = (string)($redisFlag ?? '');
$redisCore = (string)($redisCore ?? '');
$redisCacheTime = (string)($redisCacheTime ?? '');
$redisCachePage = (string)($redisCachePage ?? '');
$redisCacheTimePage = (string)($redisCacheTimePage ?? '');

$redisPort = (int)$redisPort;
$redisDb = (int)$redisDb;


/* =========================================================
 * Redis 基础变量初始化
 * ======================================================= */

$redisExtensionAvailable = class_exists('Redis');
$redisConnected = false;
$redisError = '';
$redis = null;

$redisServerInfo = [];
$redisMemoryInfo = [];
$redisStats = [];
$redisClientsInfo = [];

$redisVersion = '-';
$redisMode = '-';
$redisUptime = 0;

$redisUsedMemory = 0;
$redisPeakMemory = 0;
$redisMaxMemory = 0;

$redisConnectedClients = 0;
$redisBlockedClients = 0;

$redisCommandsProcessed = 0;
$redisOpsPerSecond = 0;

$redisHits = 0;
$redisMisses = 0;
$redisHitRate = 0;

$redisTotalKeys = 0;
$redisDatabaseCount = 0;
$redisDatabaseTotalKeys = 0;

$redisKeyList = [];
$redisKeySearch = '';
$redisKeyPattern = '';

$redisActionMessage = '';
$redisActionType = '';

$redisSelectedKey = '';
$redisSelectedValue = null;


/* =========================================================
 * 连接 Redis 与核心逻辑处理
 * ======================================================= */

if (!$redisExtensionAvailable) {
    $redisError = 'PHP Redis 扩展未安装';
} elseif (trim($redisHost) === '') {
    $redisError = '未读取到 Redis Host';
} else {
    try {
        $redis = new Redis();
        $redis->connect($redisHost, $redisPort, 1.5);

        if ($redisPassword !== '') {
            if ($redisUsername !== '') {
                $redis->auth([$redisUsername, $redisPassword]);
            } else {
                $redis->auth($redisPassword);
            }
        }

        $redis->select($redisDb);

        $pong = $redis->ping();
        if ($pong === false) {
            throw new RuntimeException('Redis PING 失败');
        }

        $redisConnected = true;

        // 获取系统信息
        $redisServerInfo = $redis->info('server');
        $redisMemoryInfo = $redis->info('memory');
        $redisStats = $redis->info('stats');
        $redisClientsInfo = $redis->info('clients');

        if (isset($redisServerInfo['redis_version'])) {
            $redisVersion = (string)$redisServerInfo['redis_version'];
        }
        if (isset($redisServerInfo['redis_mode'])) {
            $redisMode = (string)$redisServerInfo['redis_mode'];
        } elseif (isset($redisServerInfo['role'])) {
            $redisMode = (string)$redisServerInfo['role'];
        }
        if (isset($redisServerInfo['uptime_in_seconds'])) {
            $redisUptime = (int)$redisServerInfo['uptime_in_seconds'];
        }

        if (isset($redisMemoryInfo['used_memory'])) {
            $redisUsedMemory = (int)$redisMemoryInfo['used_memory'];
        }
        if (isset($redisMemoryInfo['used_memory_peak'])) {
            $redisPeakMemory = (int)$redisMemoryInfo['used_memory_peak'];
        }
        if (isset($redisMemoryInfo['maxmemory'])) {
            $redisMaxMemory = (int)$redisMemoryInfo['maxmemory'];
        }

        if (isset($redisClientsInfo['connected_clients'])) {
            $redisConnectedClients = (int)$redisClientsInfo['connected_clients'];
        }
        if (isset($redisClientsInfo['blocked_clients'])) {
            $redisBlockedClients = (int)$redisClientsInfo['blocked_clients'];
        }

        if (isset($redisStats['keyspace_hits'])) {
            $redisHits = (int)$redisStats['keyspace_hits'];
        }
        if (isset($redisStats['keyspace_misses'])) {
            $redisMisses = (int)$redisStats['keyspace_misses'];
        }
        if (isset($redisStats['total_commands_processed'])) {
            $redisCommandsProcessed = (int)$redisStats['total_commands_processed'];
        }
        if (isset($redisStats['instantaneous_ops_per_sec'])) {
            $redisOpsPerSecond = (int)$redisStats['instantaneous_ops_per_sec'];
        }

        $hitMissTotal = $redisHits + $redisMisses;
        if ($hitMissTotal > 0) {
            $redisHitRate = ($redisHits / $hitMissTotal) * 100;
        }

        $redisDatabaseTotalKeys = (int)$redis->dbSize();
        $redisTotalKeys = $redisDatabaseTotalKeys;

        try {
            $databaseConfig = $redis->config('GET', 'databases');
            if (is_array($databaseConfig) && isset($databaseConfig['databases'])) {
                $redisDatabaseCount = (int)$databaseConfig['databases'];
            }
        } catch (Throwable $e) {
            $redisDatabaseCount = 0;
        }

        // =========================================================
        // 核心优化：智能默认值兜底与全包围通配符处理
        // =========================================================
        if (isset($_GET['key'])) {
            $redisKeySearch = trim((string)$_GET['key']);
        } else {
            $redisKeySearch = trim($redisFlag); // 默认使用系统 cache_flag 兜底
        }

        // 自动前后加 * 实现全局精准模糊匹配（如搜 xgplayer4_page 实际匹配 *xgplayer4_page*）
        if ($redisKeySearch !== '') {
            $redisKeyPattern = '*' . $redisKeySearch . '*';
        } else {
            $redisKeyPattern = '*';
        }


        /* =================================================
         * 动作处理：删除单个 Key
         * ================================================= */
        if (
            $_SERVER['REQUEST_METHOD'] === 'POST'
            && isset($_POST['action'])
            && $_POST['action'] === 'delete_key'
        ) {
            $deleteKey = isset($_POST['key']) ? trim((string)$_POST['key']) : '';

            if ($deleteKey === '') {
                $redisActionMessage = '未指定 Key';
                $redisActionType = 'danger';
            } else {
                try {
                    $deleted = $redis->del($deleteKey);
                    if ($deleted > 0) {
                        $redisActionMessage = 'Key 已成功删除：' . $deleteKey;
                        $redisActionType = 'success';
                    } else {
                        $redisActionMessage = 'Key 不存在或已被删除：' . $deleteKey;
                        $redisActionType = 'warning';
                    }
                } catch (Throwable $e) {
                    $redisActionMessage = '删除失败：' . $e->getMessage();
                    $redisActionType = 'danger';
                }
            }

            // PRG 防重刷跳转
            $redirect = strtok($_SERVER['REQUEST_URI'], '?');
            $query = [];
            if ($redisKeySearch !== '') {
                $query['key'] = $redisKeySearch;
            }
            if ($redisActionMessage !== '') {
                $query['message'] = $redisActionMessage;
                $query['type'] = $redisActionType;
            }
            if (!empty($query)) {
                $redirect .= '?' . http_build_query($query);
            }
            header('Location: ' . $redirect);
            exit;
        }


        /* =================================================
         * 动作处理：批量删除搜索结果
         * ================================================= */
        if (
            $_SERVER['REQUEST_METHOD'] === 'POST'
            && isset($_POST['action'])
            && $_POST['action'] === 'delete_search'
        ) {
            $deletePattern = isset($_POST['pattern']) ? trim((string)$_POST['pattern']) : '';

            if ($deletePattern === '') {
                $redisActionMessage = '搜索条件为空';
                $redisActionType = 'danger';
            } else {
                $deleteCount = 0;
                $iterator = null;
                try {
                    $redis->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);
                    do {
                        $keys = $redis->scan($iterator, $deletePattern, 500);
                        if ($keys === false) {
                            break;
                        }
                        if (is_array($keys) && !empty($keys)) {
                            $deleteCount += (int)$redis->del($keys);
                        }
                    } while ($iterator !== 0 && $iterator !== null);

                    $redisActionMessage = '批量清理完成，已安全删除 ' . $deleteCount . ' 个匹配的 Key';
                    $redisActionType = 'success';
                } catch (Throwable $e) {
                    $redisActionMessage = '批量删除失败：' . $e->getMessage();
                    $redisActionType = 'danger';
                }
            }

            $redirect = strtok($_SERVER['REQUEST_URI'], '?');
            $query = [];
            if ($redisKeySearch !== '') {
                $query['key'] = $redisKeySearch;
            }
            if ($redisActionMessage !== '') {
                $query['message'] = $redisActionMessage;
                $query['type'] = $redisActionType;
            }
            if (!empty($query)) {
                $redirect .= '?' . http_build_query($query);
            }
            header('Location: ' . $redirect);
            exit;
        }


        if (isset($_GET['message'])) {
            $redisActionMessage = (string)$_GET['message'];
            $redisActionType = isset($_GET['type']) ? (string)$_GET['type'] : 'success';
        }


        /* =================================================
         * Key 搜索与列表获取（Scan 遍历）
         * ================================================= */
        if ($redisKeyPattern !== '') {
            $iterator = null;
            $maxKeys = 500;
            $redis->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);

            do {
                $keys = $redis->scan($iterator, $redisKeyPattern, 200);
                if ($keys === false) {
                    break;
                }

                if (is_array($keys)) {
                    foreach ($keys as $key) {
                        $redisKeyList[] = (string)$key;
                        if (count($redisKeyList) >= $maxKeys) {
                            break 2;
                        }
                    }
                }
            } while ($iterator !== 0 && $iterator !== null);

            sort($redisKeyList, SORT_STRING);
        }


        /* =================================================
         * 查看指定 Key 的详细内容
         * ================================================= */
        if (isset($_GET['view'])) {
            $redisSelectedKey = (string)$_GET['view'];

            if ($redisSelectedKey !== '') {
                try {
                    $type = $redis->type($redisSelectedKey);

                    switch ($type) {
                        case Redis::REDIS_STRING:
                            $redisSelectedValue = $redis->get($redisSelectedKey);
                            break;
                        case Redis::REDIS_LIST:
                            $redisSelectedValue = $redis->lRange($redisSelectedKey, 0, 99);
                            break;
                        case Redis::REDIS_SET:
                            $redisSelectedValue = $redis->sMembers($redisSelectedKey);
                            break;
                        case Redis::REDIS_ZSET:
                            $redisSelectedValue = $redis->zRange($redisSelectedKey, 0, 99, true);
                            break;
                        case Redis::REDIS_HASH:
                            $redisSelectedValue = $redis->hGetAll($redisSelectedKey);
                            break;
                        case Redis::REDIS_STREAM:
                            $redisSelectedValue = $redis->xRange($redisSelectedKey, '-', '+', 100);
                            break;
                        default:
                            $redisSelectedValue = null;
                            break;
                    }
                } catch (Throwable $e) {
                    $redisSelectedValue = '读取失败：' . $e->getMessage();
                }
            }
        }

    } catch (Throwable $e) {
        $redisConnected = false;
        $redisError = $e->getMessage();
        if ($redisError === '') {
            $redisError = 'Redis 连接异常';
        }
    }
}

$redisStatusText = $redisConnected ? '在线' : '离线';
$redisConfigRead = $redisHostFound && trim($redisHost) !== '';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Redis - AppleCMS OPS</title>
<style>
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; min-height: 100%; }
body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "PingFang SC", "Microsoft YaHei", sans-serif;
    background: #f5f7fb; color: #172033;
}
a { color: inherit; text-decoration: none; }
button, input { font-family: inherit; }
.app { min-height: 100vh; display: flex; }
.sidebar {
    width: 240px; position: fixed; left: 0; top: 0; bottom: 0;
    background: #111827; color: #fff; padding: 22px 14px; z-index: 20;
}
.logo { font-size: 20px; font-weight: 700; padding: 8px 12px 24px; }
.logo small { display: block; margin-top: 5px; color: #9ca3af; font-size: 11px; font-weight: 400; }
.menu-title { color: #6b7280; font-size: 11px; padding: 15px 12px 7px; text-transform: uppercase; }
.menu-item {
    display: flex; align-items: center; gap: 10px; padding: 11px 12px; margin: 3px 0;
    border-radius: 9px; color: #cbd5e1; font-size: 14px; transition: background .15s, color .15s;
}
.menu-item:hover { background: #1f2937; color: #fff; }
.menu-item.active { background: #2563eb; color: #fff; }
.menu-icon { width: 22px; text-align: center; flex: 0 0 22px; }
.main { margin-left: 240px; width: calc(100% - 240px); min-height: 100vh; }
.topbar {
    height: 64px; background: #fff; border-bottom: 1px solid #e5e7eb;
    display: flex; align-items: center; justify-content: space-between; padding: 0 30px;
    position: sticky; top: 0; z-index: 10;
}
.top-title { font-size: 16px; font-weight: 600; }
.top-right { color: #6b7280; font-size: 13px; }
.content { padding: 30px; }
.page-head { display: flex; align-items: center; justify-content: space-between; gap: 20px; margin-bottom: 22px; }
.page-title h1 { margin: 0; font-size: 26px; line-height: 1.3; }
.page-title p { margin: 7px 0 0; color: #6b7280; font-size: 13px; }
.refresh {
    border: 0; background: #fff; color: #374151; border: 1px solid #dbe0e8;
    border-radius: 8px; padding: 9px 14px; cursor: pointer; font-size: 13px;
}
.refresh:hover { background: #f9fafb; }
.card {
    background: #fff; border: 1px solid #e6eaf0; border-radius: 12px; padding: 20px;
    box-shadow: 0 2px 8px rgba(15, 23, 42, .03); margin-bottom: 18px;
}
.card-title { display: flex; align-items: center; justify-content: space-between; gap: 15px; margin-bottom: 17px; }
.card-title h3 { margin: 0; font-size: 15px; font-weight: 600; }
.status { display: inline-flex; align-items: center; gap: 7px; font-size: 12px; white-space: nowrap; }
.status.success { color: #16a34a; }
.status.danger { color: #dc2626; }
.status.warning { color: #d97706; }
.dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
.connection-card { display: grid; grid-template-columns: minmax(220px, 1fr) minmax(300px, 1.5fr); gap: 25px; }
.connection-status { padding-right: 25px; border-right: 1px solid #edf0f4; }
.connection-status h2 { margin: 0 0 7px; font-size: 18px; }
.connection-status p { margin: 0; color: #6b7280; font-size: 13px; line-height: 1.7; }
.config-mini { display: grid; grid-template-columns: repeat(3, minmax(100px, 1fr)); gap: 12px 22px; }
.mini-item { min-width: 0; }
.mini-label { display: block; color: #8a94a6; font-size: 11px; margin-bottom: 4px; }
.mini-value { display: block; color: #172033; font-size: 13px; font-weight: 500; word-break: break-all; }
.stats-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 18px; margin-bottom: 18px; }
.stat-card { background: #fff; border: 1px solid #e6eaf0; border-radius: 12px; padding: 18px 20px; box-shadow: 0 2px 8px rgba(15, 23, 42, .03); }
.stat-title { color: #6b7280; font-size: 12px; margin-bottom: 10px; }
.big-value { font-size: 24px; font-weight: 700; line-height: 1.25; word-break: break-word; }
.sub-value { color: #8a94a6; font-size: 12px; margin-top: 6px; line-height: 1.6; }
.grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; margin-bottom: 18px; }
.info-row { min-height: 39px; display: flex; align-items: center; justify-content: space-between; gap: 20px; border-bottom: 1px solid #f0f2f5; }
.info-row:last-child { border-bottom: 0; }
.info-label { color: #7b8494; font-size: 12px; }
.info-value { color: #172033; font-size: 13px; font-weight: 500; text-align: right; word-break: break-all; }
.key-manager { margin-bottom: 20px; }
.key-search-row { display: flex; gap: 10px; align-items: center; }
.key-search {
    flex: 1; min-width: 0; height: 42px; border: 1px solid #d9dee7; border-radius: 8px;
    padding: 0 13px; outline: none; font-size: 13px; color: #172033; background: #fff;
}
.key-search:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37, 99, 235, .08); }
.btn { height: 42px; border: 0; border-radius: 8px; padding: 0 15px; cursor: pointer; font-size: 13px; white-space: nowrap; }
.btn-primary { background: #2563eb; color: #fff; }
.btn-primary:hover { background: #1d4ed8; }
.btn-danger { background: #dc2626; color: #fff; }
.btn-danger:hover { background: #b91c1c; }
.btn-light { background: #f3f4f6; color: #374151; }
.btn-light:hover { background: #e5e7eb; }
.key-hint { margin-top: 9px; color: #8a94a6; font-size: 11px; }
.key-actions { display: flex; gap: 8px; align-items: center; }
.alert { border-radius: 9px; padding: 12px 14px; margin-bottom: 16px; font-size: 13px; }
.alert.success { background: #ecfdf3; color: #166534; border: 1px solid #bbf7d0; }
.alert.danger { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.alert.warning { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
.key-table-wrap { overflow-x: auto; border: 1px solid #edf0f4; border-radius: 9px; }
.key-table { width: 100%; border-collapse: collapse; min-width: 650px; }
.key-table th { background: #f8fafc; color: #6b7280; font-size: 11px; font-weight: 600; text-align: left; padding: 11px 13px; border-bottom: 1px solid #e5e7eb; }
.key-table td { padding: 11px 13px; border-bottom: 1px solid #f0f2f5; font-size: 12px; vertical-align: middle; }
.key-name { color: #1f2937; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; word-break: break-all; }
.key-type { color: #64748b; }
.key-ttl { color: #64748b; white-space: nowrap; }
.empty { padding: 35px 20px; text-align: center; color: #9ca3af; font-size: 13px; }
.key-value {
    margin-top: 15px; background: #0f172a; color: #e2e8f0; border-radius: 9px; padding: 16px;
    overflow: auto; max-height: 420px; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 12px; line-height: 1.7; white-space: pre-wrap; word-break: break-word;
}
</style>
</head>
<body>

<div class="app">
    <aside class="sidebar">
        <div class="logo">AppleCMS OPS<small>Server Management</small></div>
        <div class="menu-title">Overview</div>
        <a class="menu-item <?php echo $page === 'dashboard' ? 'active' : ''; ?>" href="index.php"><span class="menu-icon">⌂</span><span class="menu-text">Dashboard</span></a>
        <div class="menu-title">Services</div>
        <a class="menu-item <?php echo $page === 'redis' ? 'active' : ''; ?>" href="redis.php"><span class="menu-icon">R</span><span class="menu-text">Redis</span></a>
        <a class="menu-item <?php echo $page === 'mysql' ? 'active' : ''; ?>" href="mysql.php"><span class="menu-icon">M</span><span class="menu-text">MySQL</span></a>
        <a class="menu-item <?php echo $page === 'meilisearch' ? 'active' : ''; ?>" href="meilisearch.php"><span class="menu-icon">S</span><span class="menu-text">Meilisearch</span></a>
    </aside>

<main class="main">
    <div class="topbar">
        <div class="top-title">Redis</div>
        <div class="top-right">PHP <?php echo h(PHP_VERSION); ?></div>
    </div>

    <div class="content">
        <div class="page-head">
            <div class="page-title">
                <h1>Redis</h1>
                <p>Redis 服务状态、性能与缓存管理</p>
            </div>
            <button class="refresh" onclick="location.reload();">↻ 刷新</button>
        </div>

        <?php if (!$redisConnected): ?>
            <div class="alert danger">
                <strong>Redis 连接失败</strong>
                <div style="margin-top:5px;"><?php echo h($redisError); ?></div>
            </div>
        <?php else: ?>
            <div class="card connection-card">
                <div class="connection-status">
                    <div style="margin-bottom:10px;"><?php echo statusBadge(true, '成功连接', '连接失败'); ?></div>
                    <h2>Redis 连接正常</h2>
                    <p>已成功读取 AppleCMS Redis 配置并连接 Redis 服务。</p>
                </div>
                <div>
                    <div class="card-title">
                        <h3>Configuration</h3>
                        <?php echo statusBadge($redisConfigRead, '成功读取', '读取失败'); ?>
                    </div>
                    <div class="config-mini">
                        <div class="mini-item">
                            <span class="mini-label">Host</span>
                            <span class="mini-value"><?php echo h($redisHost ?: '-'); ?></span>
                        </div>
                        <div class="mini-item">
                            <span class="mini-label">Port</span>
                            <span class="mini-value"><?php echo h($redisPort); ?></span>
                        </div>
                        <div class="mini-item">
                            <span class="mini-label">Database</span>
                            <span class="mini-value"><?php echo h($redisDb); ?></span>
                        </div>
                        <div class="mini-item">
                            <span class="mini-label">Username</span>
                            <span class="mini-value"><?php echo $redisUsername !== '' ? h($redisUsername) : '-'; ?></span>
                        </div>
                        <div class="mini-item">
                            <span class="mini-label">Password</span>
                            <span class="mini-value"><?php echo $redisPassword !== '' ? h(maskSecret($redisPassword)) : '未设置'; ?></span>
                        </div>
                        <div class="mini-item">
                            <span class="mini-label">PHP Redis</span>
                            <span class="mini-value">Available</span>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($redisConnected): ?>
            <div class="card key-manager">
                <div class="card-title">
                    <h3>Redis Key 管理</h3>
                    <span class="status success"><span class="dot"></span>DB <?php echo h($redisDb); ?></span>
                </div>
                <form method="get" action="">
                    <div class="key-search-row">
                        <input type="text" name="key" class="key-search" value="<?php echo h($redisKeySearch); ?>" placeholder="搜索 Key，例如：xgplayer4_page、vod*、maccms*" autocomplete="off">
                        <button type="submit" class="btn btn-primary">搜索</button>
                        <?php if ($redisKeySearch !== ''): ?>
                            <a href="redis.php" class="btn btn-light" style="display:flex;align-items:center;justify-content:center;">清空条件</a>
                        <?php endif; ?>
                    </div>
                </form>
                <div class="key-hint">
                    默认搜索范围：<strong><?php echo h($redisFlag !== '' ? $redisFlag : '全库'); ?></strong> &nbsp;·&nbsp; 
                    当前 DB：<strong><?php echo h($redisDb); ?></strong> &nbsp;·&nbsp; 
                    当前总 Key 数：<strong><?php echo number_format($redisTotalKeys); ?></strong>
                </div>

                <?php if ($redisKeySearch !== ''): ?>
                    <div style="margin-top:16px;display:flex;justify-content:space-between;align-items:center;gap:15px;flex-wrap:wrap;">
                        <div style="color:#64748b;font-size:12px;">
                            模糊匹配：<strong><?php echo h($redisKeyPattern); ?></strong> &nbsp;·&nbsp; 
                            当前展示：<strong><?php echo number_format(count($redisKeyList)); ?></strong> 个
                            <?php if (count($redisKeyList) >= 500): ?>
                                <span style="color:#d97706;">（最多显示 500 个）</span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($redisKeyList)): ?>
                            <form method="post" action="" onsubmit="return confirm('⚠️ 警告：确定要批量删除当前搜索条件匹配的所有 Key 吗？此操作不可逆！');">
                                <input type="hidden" name="action" value="delete_search">
                                <input type="hidden" name="pattern" value="<?php echo h($redisKeyPattern); ?>">
                                <button type="submit" class="btn btn-danger">删除当前搜索结果</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($redisActionMessage !== ''): ?>
                <div class="alert <?php echo in_array($redisActionType, ['success', 'danger', 'warning'], true) ? h($redisActionType) : 'success'; ?>">
                    <?php echo h($redisActionMessage); ?>
                </div>
            <?php endif; ?>

            <?php if ($redisKeySearch !== ''): ?>
                <div class="card">
                    <div class="card-title">
                        <h3>Key 列表</h3>
                    </div>
                    <div class="key-table-wrap">
                        <table class="key-table">
                            <thead>
                                <tr>
                                    <th style="width:55%;">Key</th>
                                    <th>Type</th>
                                    <th>TTL</th>
                                    <th style="width:150px;">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($redisKeyList)): ?>
                                <tr>
                                    <td colspan="4" class="empty">没有找到匹配的 Key</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($redisKeyList as $key): ?>
                                    <?php
                                    $keyType = '-';
                                    $keyTtl = '-';
                                    try {
                                        $typeValue = $redis->type($key);
                                        switch ($typeValue) {
                                            case Redis::REDIS_STRING: $keyType = 'string'; break;
                                            case Redis::REDIS_LIST: $keyType = 'list'; break;
                                            case Redis::REDIS_SET: $keyType = 'set'; break;
                                            case Redis::REDIS_ZSET: $keyType = 'zset'; break;
                                            case Redis::REDIS_HASH: $keyType = 'hash'; break;
                                            case Redis::REDIS_STREAM: $keyType = 'stream'; break;
                                            default: $keyType = 'unknown';
                                        }

                                        $ttl = $redis->ttl($key);
                                        if ($ttl === -1) {
                                            $keyTtl = '永久';
                                        } elseif ($ttl === -2) {
                                            $keyTtl = '不存在';
                                        } else {
                                            $keyTtl = formatSeconds($ttl);
                                        }
                                    } catch (Throwable $e) {
                                        $keyType = '-';
                                        $keyTtl = '-';
                                    }
                                    ?>
                                    <tr>
                                        <td><div class="key-name"><?php echo h($key); ?></div></td>
                                        <td><span class="key-type"><?php echo h($keyType); ?></span></td>
                                        <td><span class="key-ttl"><?php echo h($keyTtl); ?></span></td>
                                        <td>
                                            <div class="key-actions">
                                                <a href="?<?php echo http_build_query(['key' => $redisKeySearch, 'view' => $key]); ?>" class="btn btn-light" style="height:34px;padding:0 10px;display:flex;align-items:center;justify-content:center;">查看</a>
                                                <form method="post" action="" onsubmit="return confirm('确定删除这个 Key 吗？');" style="margin:0;">
                                                    <input type="hidden" name="action" value="delete_key">
                                                    <input type="hidden" name="key" value="<?php echo h($key); ?>">
                                                    <button type="submit" class="btn btn-danger" style="height:34px;padding:0 10px;">删除</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($redisSelectedKey !== ''): ?>
                <div class="card">
                    <div class="card-title">
                        <h3>Key 内容</h3>
                        <a href="?<?php echo http_build_query(['key' => $redisKeySearch]); ?>" class="btn btn-light" style="height:34px;display:flex;align-items:center;">返回列表</a>
                    </div>
                    <div class="info-row" style="border-bottom:0;">
                        <span class="info-label">Key</span>
                        <span class="info-value"><?php echo h($redisSelectedKey); ?></span>
                    </div>
                    <div class="key-value">
<?php
if (is_array($redisSelectedValue)) {
    echo h(json_encode($redisSelectedValue, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
} elseif ($redisSelectedValue === null) {
    echo 'NULL';
} else {
    echo h((string)$redisSelectedValue);
}
?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-title">Status</div>
                    <div class="big-value"><?php echo h($redisStatusText); ?></div>
                    <div class="sub-value">Redis <?php echo h($redisVersion); ?> · <?php echo h($redisMode); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-title">Keys</div>
                    <div class="big-value"><?php echo number_format($redisTotalKeys); ?></div>
                    <div class="sub-value">当前 DB <?php echo h($redisDb); ?> · <?php echo $redisDatabaseCount > 0 ? number_format($redisDatabaseCount) . ' 个数据库' : '数据库数量未知'; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-title">Memory</div>
                    <div class="big-value"><?php echo h(formatBytes($redisUsedMemory)); ?></div>
                    <div class="sub-value">Peak：<?php echo h(formatBytes($redisPeakMemory)); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-title">Hit Rate</div>
                    <div class="big-value"><?php echo number_format($redisHitRate, 2); ?>%</div>
                    <div class="sub-value">Hits：<?php echo number_format($redisHits); ?> / Miss：<?php echo number_format($redisMisses); ?></div>
                </div>
            </div>

            <div class="grid">
                <div class="card">
                    <div class="card-title"><h3>Server</h3></div>
                    <div class="info-row"><span class="info-label">Version</span><span class="info-value"><?php echo h($redisVersion); ?></span></div>
                    <div class="info-row"><span class="info-label">Mode</span><span class="info-value"><?php echo h($redisMode); ?></span></div>
                    <div class="info-row"><span class="info-label">Uptime</span><span class="info-value"><?php echo h(formatSeconds($redisUptime)); ?></span></div>
                    <div class="info-row"><span class="info-label">Commands</span><span class="info-value"><?php echo number_format($redisCommandsProcessed); ?></span></div>
                </div>
                <div class="card">
                    <div class="card-title"><h3>Clients</h3></div>
                    <div class="info-row"><span class="info-label">Connected</span><span class="info-value"><?php echo number_format($redisConnectedClients); ?></span></div>
                    <div class="info-row"><span class="info-label">Blocked</span><span class="info-value"><?php echo number_format($redisBlockedClients); ?></span></div>
                    <div class="info-row"><span class="info-label">Operations / sec</span><span class="info-value"><?php echo number_format($redisOpsPerSecond); ?></span></div>
                </div>
            </div>

        <?php endif; ?>
    </div>
</main>
</div>

</body>
</html>
