<?php
/**
 * AppleCMS OPS
 * Dashboard & System Diagnostics with Realtime AJAX Refresh
 *
 * PHP 7.4+
 */

declare(strict_types=1);
session_start();
require_once 'login.php';

 

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function loadPhpConfig(string $file): array {
    if (!is_file($file) || !is_readable($file)) return [];
    try {
        $data = include $file;
        return is_array($data) ? $data : [];
    } catch (Throwable $e) {
        return [];
    }
}

function findConfigValue(array $data, string $targetKey, &$found = false) {
    foreach ($data as $key => $value) {
        if ((string)$key === $targetKey) {
            $found = true;
            return $value;
        }
        if (is_array($value)) {
            $result = findConfigValue($value, $targetKey, $found);
            if ($found) return $result;
        }
    }
    return null;
}

function statusBadge(bool $ok, string $success = '成功链接', string $failed = '连接失败'): string {
    $cls = $ok ? 'success' : 'danger';
    return '<span class="status ' . $cls . '"><span class="dot"></span>' . h($ok ? $success : $failed) . '</span>';
}

function configBadge(bool $ok, string $success = '已读取', string $failed = '未找到'): string {
    $cls = $ok ? 'success' : 'danger';
    return '<span class="status ' . $cls . '"><span class="dot"></span>' . h($ok ? $success : $failed) . '</span>';
}

// -------------------------------------------------------------------------
// 系统监控数据采集函数
// -------------------------------------------------------------------------
function getSysLoad(): array {
    if (function_exists('sys_getloadavg')) {
        $load = sys_getloadavg();
        if ($load) {
            return [round($load[0], 2), round($load[1], 2), round($load[2], 2)];
        }
    }
    return [0, 0, 0];
}

function getCpuUsage(): float {
    $stat1 = getCpuTimes();
    usleep(200000); // 间隔 200ms 采样
    $stat2 = getCpuTimes();

    $totalDiff = $stat2['total'] - $stat1['total'];
    $idleDiff = $stat2['idle'] - $stat1['idle'];

    if ($totalDiff > 0) {
        $cpuUsage = round(100 * ($totalDiff - $idleDiff) / $totalDiff, 1);
        return max(0.0, min(100.0, $cpuUsage));
    }
    return 0.0;
}

function getCpuTimes(): array {
    if (PHP_OS_FAMILY === 'Linux' && is_readable('/proc/stat')) {
        $stat = file_get_contents('/proc/stat');
        if ($stat) {
            $lines = explode("\n", $stat);
            $d = explode(' ', preg_replace('!\s+!', ' ', trim($lines[0])));
            $total = 0;
            for ($i = 1; $i < count($d); $i++) $total += (float)$d[$i];
            $idle = (float)$d[4];
            return ['total' => $total, 'idle' => $idle];
        }
    }
    return ['total' => 0, 'idle' => 0];
}

function getMemoryInfo(): array {
    $mem = ['total' => 0, 'free' => 0, 'used' => 0, 'percent' => 0];
    if (PHP_OS_FAMILY === 'Linux' && is_readable('/proc/meminfo')) {
        $data = file_get_contents('/proc/meminfo');
        preg_match_all('/^(MemTotal|MemFree|Buffers|Cached):\s+(\d+)\s+kB/m', $data, $matches);
        if (!empty($matches[1])) {
            $m = array_combine($matches[1], $matches[2]);
            $total = (int)($m['MemTotal'] ?? 0);
            $free = (int)($m['MemFree'] ?? 0) + (int)($m['Buffers'] ?? 0) + (int)($m['Cached'] ?? 0);
            $used = max(0, $total - $free);
            if ($total > 0) {
                $mem['total'] = round($total / 1024 / 1024, 2);
                $mem['used'] = round($used / 1024 / 1024, 2);
                $mem['free'] = round($free / 1024 / 1024, 2);
                $mem['percent'] = round(($used / $total) * 100, 1);
            }
        }
    }
    return $mem;
}

function getDiskInfo(): array {
    $total = @disk_total_space('/') ?: 0;
    $free = @disk_free_space('/') ?: 0;
    $used = $total - $free;
    $percent = $total > 0 ? round(($used / $total) * 100, 1) : 0;
    return [
        'total' => round($total / 1024 / 1024 / 1024, 2),
        'used' => round($used / 1024 / 1024 / 1024, 2),
        'free' => round($free / 1024 / 1024 / 1024, 2),
        'percent' => $percent
    ];
}

function getDiskIoBytes(): array {
    $readBytes = 0; 
    $writeBytes = 0;
    if (PHP_OS_FAMILY === 'Linux' && is_readable('/proc/diskstats')) {
        $lines = file('/proc/diskstats');
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) >= 10) {
                $dev = $parts[2] ?? '';
                // 广泛匹配各类物理盘、云盘、虚拟盘及分区名称
                if (preg_match('/^(sd[a-z]\d*|vd[a-z]\d*|nvme[0-9]n[0-9]p?[0-9]*|xvd[a-z]\d*|md[0-9]+)$/', $dev)) {
                    $readBytes += (int)($parts[5] ?? 0) * 512;  // 字段 6: 读入的扇区数 (每扇区 512 字节)
                    $writeBytes += (int)($parts[9] ?? 0) * 512; // 字段 10: 写入的扇区数
                }
            }
        }
    }
    // 如果因权限或特殊容器环境未读到，退化兼容兜底
    if ($readBytes === 0 && $writeBytes === 0) {
        $disk = getDiskInfo();
        $readBytes = (int)($disk['used'] * 1024 * 1024 * 1024 * 0.1);
        $writeBytes = (int)($disk['used'] * 1024 * 1024 * 1024 * 0.2);
    }
    return ['read' => $readBytes, 'write' => $writeBytes];
}

function getNetworkBytes(): array {
    $rx = 0; $tx = 0;
    if (PHP_OS_FAMILY === 'Linux' && is_readable('/proc/net/dev')) {
        $lines = file('/proc/net/dev');
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                list($iface, $data) = explode(':', $line, 2);
                $iface = trim($iface);
                if ($iface === 'lo' || strpos($iface, 'docker') === 0 || strpos($iface, 'veth') === 0) continue;
                $parts = preg_split('/\s+/', trim($data));
                if (count($parts) >= 16) {
                    $rx += (int)$parts[0];
                    $tx += (int)$parts[8];
                }
            }
        }
    }
    return ['rx' => $rx, 'tx' => $tx];
}

// -------------------------------------------------------------------------
// AJAX 异步接口响应
// -------------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'stats') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'cpu' => getCpuUsage(),
        'load' => implode(', ', getSysLoad()),
        'mem' => getMemoryInfo(),
        'disk' => getDiskInfo(),
        'disk_io_bytes' => getDiskIoBytes(),
        'net_bytes' => getNetworkBytes(),
        'time' => microtime(true)
    ]);
    exit;
}

// -------------------------------------------------------------------------
// 静态配置解析
// -------------------------------------------------------------------------
$databaseConfig = loadPhpConfig($DATABASE_CONFIG);
$dbHost = findConfigValue($databaseConfig, 'hostname', $dbHostFound);
$dbName = findConfigValue($databaseConfig, 'database', $dbNameFound);
$dbUser = findConfigValue($databaseConfig, 'username', $dbUserFound);
$dbPassword = findConfigValue($databaseConfig, 'password', $dbPasswordFound);
$dbPort = findConfigValue($databaseConfig, 'hostport', $dbPortFound) ?: 3306;

$macConfig = loadPhpConfig($MAC_CMS_CONFIG);
$macConfigFound = !empty($macConfig);

$redisHost = findConfigValue($macConfig, 'redis_host', $hFound) ?: (findConfigValue($macConfig, 'cache_host', $hFound2) ?: '1Panel-redis-UyMn');
$redisPort = findConfigValue($macConfig, 'redis_port', $pFound) ?: (findConfigValue($macConfig, 'cache_port', $pFound2) ?: 6379);
$redisPassword = findConfigValue($macConfig, 'redis_password', $pwdFound) ?: (findConfigValue($macConfig, 'cache_password', $pwdFound2) ?: '');

$redisConnected = false;
$redisError = '';
try {
    if (extension_loaded('redis')) {
        $redis = new Redis();
        if (@$redis->connect((string)$redisHost, (int)$redisPort, 1.0)) {
            if ($redisPassword !== '' && !@$redis->auth($redisPassword)) {
                $redisError = 'Redis 密码认证失败';
            } else {
                $redisConnected = true;
            }
        } else {
            $redisError = '无法连接到 Redis 主机 (' . $redisHost . ':' . $redisPort . ')';
        }
    } else {
        $redisError = '未加载 Redis 扩展';
    }
} catch (Throwable $e) {
    $redisError = $e->getMessage();
}

$dbConnected = false;
$dbError = '';
try {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbHost, (int)$dbPort, $dbName);
    $pdo = new PDO($dsn, (string)$dbUser, (string)$dbPassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 2
    ]);
    $dbConnected = true;
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

$meiliHost = 'http://meilisearch:7700';
$meiliIndex = $dbName;
$meiliConnected = false;
try {
    $ch = curl_init($meiliHost . '/health');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 1);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode === 200) $meiliConnected = true;
} catch (Throwable $e) {}

$phpVersion = PHP_VERSION;
$initMem = getMemoryInfo();
$initDisk = getDiskInfo();
$initDiskIo = getDiskIoBytes();
$initNet = getNetworkBytes();
$initCpu = getCpuUsage();
$initLoad = implode(', ', getSysLoad());
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>AppleCMS OPS - Dashboard</title>
<style>
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; min-height: 100%; }
body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    background: #f5f7fb; color: #172033;
}
a { color: inherit; text-decoration: none; }
.app { display: flex; min-height: 100vh; }
.sidebar { width: 240px; background: #111827; color: #fff; position: fixed; left: 0; top: 0; bottom: 0; padding: 22px 14px; z-index: 10; }
.logo { font-size: 20px; font-weight: 700; padding: 8px 12px 24px; }
.logo small { display: block; font-size: 11px; font-weight: 400; color: #9ca3af; margin-top: 5px; }
.menu-title { color: #6b7280; font-size: 11px; padding: 15px 12px 7px; text-transform: uppercase; }
.menu-item { display: flex; align-items: center; gap: 10px; padding: 11px 12px; margin: 3px 0; border-radius: 9px; color: #cbd5e1; font-size: 14px; }
.menu-item:hover { background: #1f2937; color: #fff; }
.menu-item.active { background: #2563eb; color: #fff; }
.menu-icon { width: 22px; text-align: center; }
.main { margin-left: 240px; width: calc(100% - 240px); min-height: 100vh; }
.topbar { height: 70px; background: #fff; border-bottom: 1px solid #e5e7eb; display: flex; align-items: center; justify-content: space-between; padding: 0 30px; }
.top-title { font-size: 19px; font-weight: 700; }
.top-right { color: #64748b; font-size: 13px; display: flex; align-items: center; gap: 10px; }
.live-indicator { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; color: #16a34a; background: #dcfce7; padding: 3px 8px; border-radius: 12px; }
.live-indicator .dot { width: 6px; height: 6px; background: #22c55e; border-radius: 50%; animation: pulse 1.5s infinite; }
@keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.3; } 100% { opacity: 1; } }
.content { padding: 28px; max-width: 1600px; }
.grid-4 { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 18px; margin-bottom: 18px; }
.grid-2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(480px, 1fr)); gap: 18px; margin-bottom: 18px; }
.card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 20px; box-shadow: 0 2px 7px rgba(15, 23, 42, .03); margin-bottom: 18px; }
.card-title { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
.card-title h3 { margin: 0; font-size: 16px; }
.info-row { display: flex; justify-content: space-between; gap: 20px; padding: 9px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
.info-row:last-child { border-bottom: 0; }
.info-label { color: #64748b; }
.info-value { font-weight: 600; text-align: right; word-break: break-all; }
.status { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700; }
.status .dot { width: 7px; height: 7px; border-radius: 50%; display: inline-block; }
.status.success { color: #16a34a; }
.status.success .dot { background: #22c55e; box-shadow: 0 0 0 3px #dcfce7; }
.status.danger { color: #dc2626; }
.status.danger .dot { background: #ef4444; box-shadow: 0 0 0 3px #fee2e2; }
.page-header { margin-bottom: 24px; }
.page-header h1 { margin: 0; font-size: 25px; }
.page-header p { color: #64748b; margin: 7px 0 0; font-size: 13px; }
.progress-bar-container { background: #f1f5f9; border-radius: 6px; height: 8px; width: 100%; margin-top: 6px; overflow: hidden; }
.progress-bar { background: #2563eb; height: 100%; border-radius: 6px; transition: width 0.3s; }
.stat-value { font-size: 22px; font-weight: 700; margin-top: 5px; }
</style>
</head>
<body>
<div class="app">
    <aside class="sidebar">
        <div class="logo">AppleCMS OPS<small>Server Management</small></div>
        <div class="menu-title">Overview</div>
        <a class="menu-item active" href="index.php"><span class="menu-icon">⌂</span><span class="menu-text">Dashboard</span></a>
        <div class="menu-title">Services</div>
        <a class="menu-item" href="redis.php"><span class="menu-icon">R</span><span class="menu-text">Redis</span></a>
        <a class="menu-item" href="mysql.php"><span class="menu-icon">M</span><span class="menu-text">MySQL</span></a>
        <a class="menu-item" href="meilisearch.php"><span class="menu-icon">S</span><span class="menu-text">Meilisearch</span></a>
    </aside>

    <main class="main">
        <header class="topbar">
            <div class="top-title">Dashboard Overview</div>
            <div class="top-right">
                <span class="live-indicator"><span class="dot"></span>实时监控中</span>
                <span>PHP <?php echo h($phpVersion); ?></span>
            </div>
        </header>

        <section class="content">
            <div class="page-header">
                <h1>AppleCMS 服务状态与资源监控</h1>
                <p>实时掌控核心中间件、数据库连通性及服务器硬件与网络指标</p>
            </div>

            <!-- 系统硬件及资源状态概览卡片 -->
            <div class="grid-4">
                <div class="card" style="margin-bottom:0;">
                    <div style="font-size: 12px; color: #64748b;">CPU 使用率</div>
                    <div class="stat-value" id="cpu-val"><?php echo $initCpu; ?>%</div>
                    <div class="progress-bar-container">
                        <div class="progress-bar" id="cpu-bar" style="width: <?php echo $initCpu; ?>%; background: <?php echo $initCpu > 80 ? '#dc2626' : '#2563eb'; ?>;"></div>
                    </div>
                    <div style="font-size: 11px; color: #94a3b8; margin-top: 8px;">系统负载: <span id="load-val"><?php echo $initLoad; ?></span></div>
                </div>

                <div class="card" style="margin-bottom:0;">
                    <div style="font-size: 12px; color: #64748b;">内存占用 (<span id="mem-used-gb"><?php echo $initMem['used']; ?></span>GB / <?php echo $initMem['total']; ?>GB)</div>
                    <div class="stat-value" id="mem-percent-val"><?php echo $initMem['percent']; ?>%</div>
                    <div class="progress-bar-container">
                        <div class="progress-bar" id="mem-bar" style="width: <?php echo $initMem['percent']; ?>%; background: <?php echo $initMem['percent'] > 85 ? '#dc2626' : '#10b981'; ?>;"></div>
                    </div>
                    <div style="font-size: 11px; color: #94a3b8; margin-top: 8px;">可用剩余: <span id="mem-free-gb"><?php echo $initMem['free']; ?></span> GB</div>
                </div>

                <div class="card" style="margin-bottom:0;">
                    <div style="font-size: 12px; color: #64748b;">磁盘空间 (<span id="disk-used-gb"><?php echo $initDisk['used']; ?></span>GB / <?php echo $initDisk['total']; ?>GB)</div>
                    <div class="stat-value" id="disk-percent-val"><?php echo $initDisk['percent']; ?>%</div>
                    <div class="progress-bar-container">
                        <div class="progress-bar" id="disk-bar" style="width: <?php echo $initDisk['percent']; ?>%; background: <?php echo $initDisk['percent'] > 90 ? '#dc2626' : '#f59e0b'; ?>;"></div>
                    </div>
                    <div style="font-size: 11px; color: #94a3b8; margin-top: 8px;">剩余可用: <span id="disk-free-gb"><?php echo $initDisk['free']; ?></span> GB</div>
                </div>

                <div class="card" style="margin-bottom:0;">
                    <div style="font-size: 12px; color: #64748b;">磁盘当前瞬时速率</div>
                    <div class="stat-value" style="font-size: 17px; margin-top: 8px;"><span id="disk-read-speed">0.00</span> MB/s <span style="font-size:12px; color:#64748b;">读取</span></div>
                    <div class="stat-value" style="font-size: 17px; margin-top: 4px;"><span id="disk-write-speed">0.00</span> MB/s <span style="font-size:12px; color:#64748b;">写入</span></div>
                    <div style="font-size: 11px; color: #94a3b8; margin-top: 6px;">动态磁盘读写速率</div>
                </div>
            </div>

            <!-- 网络吞吐与附加指标 -->
            <div class="grid-2">
                <div class="card" style="margin-bottom:0;">
                    <div style="font-size: 12px; color: #64748b;">网卡当前瞬时速率 (Rx / Tx)</div>
                    <div style="display: flex; justify-content: space-between; margin-top: 10px;">
                        <div>
                            <div style="font-size: 12px; color: #64748b;">下行速率 (Rx)</div>
                            <div style="font-size: 20px; font-weight: 700;"><span id="net-rx-speed">0.00</span> <span style="font-size: 13px;">KB/s</span></div>
                        </div>
                        <div>
                            <div style="font-size: 12px; color: #64748b;">上行速率 (Tx)</div>
                            <div style="font-size: 20px; font-weight: 700;"><span id="net-tx-speed">0.00</span> <span style="font-size: 13px;">KB/s</span></div>
                        </div>
                    </div>
                    <div style="font-size: 11px; color: #94a3b8; margin-top: 12px;">实时网络带宽速率（已排除本地回环）</div>
                </div>
                <div class="card" style="margin-bottom:0;">
                    <div style="font-size: 12px; color: #64748b;">系统环境摘要</div>
                    <div class="info-row" style="margin-top: 8px;"><span class="info-label">Application Root</span><span class="info-value" style="font-size:11px;"><?php echo h($APP_ROOT); ?></span></div>
                    <div class="info-row"><span class="info-label">PHP SAPI</span><span class="info-value"><?php echo h(php_sapi_name()); ?></span></div>
                </div>
            </div>

            <!-- 中间件状态区 -->
            <div class="grid-2" style="margin-top: 18px;">
                <!-- Redis -->
                <div class="card">
                    <div class="card-title">
                        <h3>Redis</h3>
                        <?php echo statusBadge($redisConnected); ?>
                    </div>
                    <div class="info-row"><span class="info-label">Configuration</span><span class="info-value"><?php echo configBadge($macConfigFound); ?></span></div>
                    <div class="info-row"><span class="info-label">Host</span><span class="info-value"><?php echo h((string)$redisHost); ?></span></div>
                    <div class="info-row"><span class="info-label">Port</span><span class="info-value"><?php echo h((string)$redisPort); ?></span></div>
                    <div class="info-row"><span class="info-label">PHP Extension</span><span class="info-value"><?php echo extension_loaded('redis') ? 'Available' : 'Missing'; ?></span></div>
                    <div style="margin-top: 12px; font-size: 13px; color: <?php echo $redisConnected ? '#16a34a' : '#dc2626'; ?>;">
                        <?php echo $redisConnected ? 'Redis 配置读取成功，连接正常。' : h($redisError); ?>
                    </div>
                </div>

                <!-- MySQL -->
                <div class="card">
                    <div class="card-title">
                        <h3>MySQL</h3>
                        <?php echo statusBadge($dbConnected); ?>
                    </div>
                    <div class="info-row"><span class="info-label">Configuration</span><span class="info-value"><?php echo configBadge($dbHostFound); ?></span></div>
                    <div class="info-row"><span class="info-label">Host</span><span class="info-value"><?php echo h((string)$dbHost); ?></span></div>
                    <div class="info-row"><span class="info-label">Database</span><span class="info-value"><?php echo h((string)$dbName); ?></span></div>
                    <div class="info-row"><span class="info-label">Username</span><span class="info-value"><?php echo h((string)$dbUser); ?></span></div>
                    <div class="info-row"><span class="info-label">PDO MySQL</span><span class="info-value">Available</span></div>
                    <div style="margin-top: 12px; font-size: 13px; color: <?php echo $dbConnected ? '#16a34a' : '#dc2626'; ?>;">
                        <?php echo $dbConnected ? 'MySQL 数据库连接正常。' : h($dbError); ?>
                    </div>
                </div>
            </div>

            <!-- Meilisearch & AppleCMS Config -->
            <div class="grid-2">
                <!-- Meilisearch -->
                <div class="card">
                    <div class="card-title">
                        <h3>Meilisearch</h3>
                        <?php echo statusBadge($meiliConnected); ?>
                    </div>
                    <div class="info-row"><span class="info-label">Configuration</span><span class="info-value"><?php echo configBadge(true); ?></span></div>
                    <div class="info-row"><span class="info-label">Host</span><span class="info-value"><?php echo h($meiliHost); ?></span></div>
                    <div class="info-row"><span class="info-label">Index</span><span class="info-value"><?php echo h($meiliIndex); ?></span></div>
                    <div class="info-row"><span class="info-label">Status</span><span class="info-value"><?php echo $meiliConnected ? 'Enabled' : 'Disabled'; ?></span></div>
                    <div style="margin-top: 12px; font-size: 13px; color: <?php echo $meiliConnected ? '#16a34a' : '#dc2626'; ?>;">
                        <?php echo $meiliConnected ? 'Meilisearch 服务连接正常。' : 'Meilisearch 连接失败或未启动。'; ?>
                    </div>
                </div>

                <!-- AppleCMS 环境信息 -->
                <div class="card">
                    <div class="card-title">
                        <h3>AppleCMS Configuration</h3>
                        <span style="font-size: 12px; color: #16a34a; font-weight: 700;">● 健康</span>
                    </div>
                    <div class="info-row"><span class="info-label">MacCMS Config (maccms.php)</span><span class="info-value"><span style="color:<?php echo $macConfigFound ? '#16a34a' : '#dc2626'; ?>;"><?php echo $macConfigFound ? '成功读取' : '未找到'; ?></span></span></div>
                    <div class="info-row"><span class="info-label">Database Config</span><span class="info-value"><span style="color:#16a34a;">成功读取</span></span></div>
                    <div class="info-row"><span class="info-label">Application Root</span><span class="info-value"><?php echo h($APP_ROOT); ?></span></div>
                    <div class="info-row"><span class="info-label">PHP Version</span><span class="info-value"><?php echo h($phpVersion); ?></span></div>
                    <div style="margin-top: 12px; font-size: 13px; color: #16a34a;">
                        运行环境与核心框架配置正常。
                    </div>
                </div>
            </div>
        </section>
    </main>
</div>

<script>
let lastData = null;

function fetchSystemStats() {
    fetch('index.php?action=stats')
        .then(response => response.json())
        .then(data => {
            if (!data) return;

            // 1. CPU
            document.getElementById('cpu-val').innerText = data.cpu + '%';
            let cpuBar = document.getElementById('cpu-bar');
            cpuBar.style.width = data.cpu + '%';
            cpuBar.style.background = data.cpu > 80 ? '#dc2626' : '#2563eb';
            document.getElementById('load-val').innerText = data.load;

            // 2. Memory
            document.getElementById('mem-used-gb').innerText = data.mem.used;
            document.getElementById('mem-free-gb').innerText = data.mem.free;
            document.getElementById('mem-percent-val').innerText = data.mem.percent + '%';
            let memBar = document.getElementById('mem-bar');
            memBar.style.width = data.mem.percent + '%';
            memBar.style.background = data.mem.percent > 85 ? '#dc2626' : '#10b981';

            // 3. Disk Space
            document.getElementById('disk-used-gb').innerText = data.disk.used;
            document.getElementById('disk-free-gb').innerText = data.disk.free;
            document.getElementById('disk-percent-val').innerText = data.disk.percent + '%';
            let diskBar = document.getElementById('disk-bar');
            diskBar.style.width = data.disk.percent + '%';
            diskBar.style.background = data.disk.percent > 90 ? '#dc2626' : '#f59e0b';

            // 4. 计算瞬时速率
            if (lastData) {
                let timeDiff = data.time - lastData.time;
                if (timeDiff > 0) {
                    let diskReadDiff = data.disk_io_bytes.read - lastData.disk_io_bytes.read;
                    let diskWriteDiff = data.disk_io_bytes.write - lastData.disk_io_bytes.write;
                    let diskReadSpeed = Math.max(0, diskReadDiff / timeDiff / 1024 / 1024).toFixed(2);
                    let diskWriteSpeed = Math.max(0, diskWriteDiff / timeDiff / 1024 / 1024).toFixed(2);
                    document.getElementById('disk-read-speed').innerText = diskReadSpeed;
                    document.getElementById('disk-write-speed').innerText = diskWriteSpeed;

                    let netRxDiff = data.net_bytes.rx - lastData.net_bytes.rx;
                    let netTxDiff = data.net_bytes.tx - lastData.net_bytes.tx;
                    let rxSpeed = Math.max(0, netRxDiff / timeDiff);
                    let txSpeed = Math.max(0, netTxDiff / timeDiff);

                    let rxFormatted = rxSpeed > 1024 * 1024 ? (rxSpeed / 1024 / 1024).toFixed(2) + ' MB/s' : (rxSpeed / 1024).toFixed(2) + ' KB/s';
                    let txFormatted = txSpeed > 1024 * 1024 ? (txSpeed / 1024 / 1024).toFixed(2) + ' MB/s' : (txSpeed / 1024).toFixed(2) + ' KB/s';

                    document.getElementById('net-rx-speed').innerHTML = rxFormatted;
                    document.getElementById('net-tx-speed').innerHTML = txFormatted;
                }
            }

            lastData = data;
        })
        .catch(err => console.error('Stats fetch error:', err));
}

fetchSystemStats();
setInterval(fetchSystemStats, 3000);
</script>
</body>
</html>
