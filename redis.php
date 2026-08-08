<?php

declare(strict_types=1);

/**
 * AppleCMS OPS - Redis
 *
 * 自动读取：
 * ../application/extra/maccms.php
 *
 * PHP 7.4+
 */


/* =========================================================
 * 基础配置
 * ======================================================= */

$APP_ROOT = realpath(__DIR__ . '/..');

if ($APP_ROOT === false) {
    $APP_ROOT = dirname(__DIR__);
}

$MACCMS_CONFIG = $APP_ROOT . '/application/extra/maccms.php';


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
 * 读取 PHP 配置
 */
function loadRedisConfig(string $file): array
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
 * 隐藏密码
 */
function maskRedisSecret($value): string
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
 * 状态
 */
function redisStatus(
    bool $ok,
    string $success = '在线',
    string $failed = '离线'
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

    $units = [
        'B',
        'KB',
        'MB',
        'GB',
        'TB'
    ];

    $i = 0;

    while ($bytes >= 1024 && $i < count($units) - 1) {

        $bytes /= 1024;
        $i++;
    }

    return number_format($bytes, 2) . ' ' . $units[$i];
}


/**
 * 格式化秒
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
 * 读取 AppleCMS 配置
 * ======================================================= */

$maccmsConfig = loadRedisConfig(
    $MACCMS_CONFIG
);

$redisConfig = [];

if (
    isset($maccmsConfig['meilisearch'])
    && is_array($maccmsConfig['meilisearch'])
) {

    /*
     * 这里不使用 meilisearch 配置。
     */
}


/*
 * AppleCMS Redis 配置通常位于顶层：
 *
 * cache_host
 * cache_port
 * cache_username
 * cache_password
 * cache_db
 * cache_flag
 * cache_core
 * cache_time
 * cache_page
 * cache_time_page
 */


/* =========================================================
 * Redis 参数
 * ======================================================= */

$redisHost = '';

$redisPort = 6379;

$redisUsername = '';

$redisPassword = '';

$redisDb = 0;

$redisFlag = '';

$redisCore = '';

$redisCacheTime = '';

$redisCachePage = '';

$redisCacheTimePage = '';


if (array_key_exists('cache_host', $maccmsConfig)) {

    $redisHost = (string)$maccmsConfig['cache_host'];
}

if (array_key_exists('cache_port', $maccmsConfig)) {

    $redisPort = (int)$maccmsConfig['cache_port'];
}

if (array_key_exists('cache_username', $maccmsConfig)) {

    $redisUsername = (string)$maccmsConfig['cache_username'];
}

if (array_key_exists('cache_password', $maccmsConfig)) {

    $redisPassword = (string)$maccmsConfig['cache_password'];
}

if (array_key_exists('cache_db', $maccmsConfig)) {

    $redisDb = (int)$maccmsConfig['cache_db'];
}

if (array_key_exists('cache_flag', $maccmsConfig)) {

    $redisFlag = (string)$maccmsConfig['cache_flag'];
}

if (array_key_exists('cache_core', $maccmsConfig)) {

    $redisCore = (string)$maccmsConfig['cache_core'];
}

if (array_key_exists('cache_time', $maccmsConfig)) {

    $redisCacheTime = (string)$maccmsConfig['cache_time'];
}

if (array_key_exists('cache_page', $maccmsConfig)) {

    $redisCachePage = (string)$maccmsConfig['cache_page'];
}

if (array_key_exists('cache_time_page', $maccmsConfig)) {

    $redisCacheTimePage = (string)$maccmsConfig['cache_time_page'];
}


/* =========================================================
 * Redis 状态
 * ======================================================= */

$redisExtensionAvailable = class_exists('Redis');

$redisConnected = false;

$redisError = '';

$redis = null;


/* =========================================================
 * Redis 数据
 * ======================================================= */

$redisServerInfo = [];

$redisMemoryInfo = [];

$redisStats = [];

$redisClientsInfo = [];

$redisKeyspace = [];

$redisDatabaseCount = 0;

$redisTotalKeys = 0;

$redisHitRate = 0;

$redisHits = 0;

$redisMisses = 0;

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


/* =========================================================
 * 连接 Redis
 * ======================================================= */

if (!$redisExtensionAvailable) {

    $redisError = 'PHP Redis 扩展未安装';

} elseif (trim($redisHost) === '') {

    $redisError = '未读取到 Redis Host';

} else {

    try {

        $redis = new Redis();

        $connectStart = microtime(true);

        $redis->connect(
            $redisHost,
            $redisPort,
            1.5
        );

        if ($redisPassword !== '') {

            if ($redisUsername !== '') {

                $redis->auth([
                    $redisUsername,
                    $redisPassword
                ]);

            } else {

                $redis->auth(
                    $redisPassword
                );
            }
        }

        $redis->select(
            $redisDb
        );

        $pong = $redis->ping();

        if ($pong === false) {

            throw new RuntimeException(
                'Redis PING 失败'
            );
        }

        $redisConnected = true;

        /*
         * Server
         */
        $redisServerInfo = $redis->info(
            'server'
        );

        /*
         * Memory
         */
        $redisMemoryInfo = $redis->info(
            'memory'
        );

        /*
         * Stats
         */
        $redisStats = $redis->info(
            'stats'
        );

        /*
         * Clients
         */
        $redisClientsInfo = $redis->info(
            'clients'
        );

        /*
         * Keyspace
         */
        $redisKeyspace = $redis->info(
            'keyspace'
        );

        if (isset($redisServerInfo['redis_version'])) {

            $redisVersion =
                (string)$redisServerInfo['redis_version'];
        }

        if (isset($redisServerInfo['uptime_in_seconds'])) {

            $redisUptime =
                (int)$redisServerInfo['uptime_in_seconds'];
        }

        if (isset($redisServerInfo['redis_mode'])) {

            $redisMode =
                (string)$redisServerInfo['redis_mode'];

        } elseif (isset($redisServerInfo['role'])) {

            $redisMode =
                (string)$redisServerInfo['role'];
        }

        /*
         * Memory
         */

        if (isset($redisMemoryInfo['used_memory'])) {

            $redisUsedMemory =
                (int)$redisMemoryInfo['used_memory'];
        }

        if (isset($redisMemoryInfo['used_memory_peak'])) {

            $redisPeakMemory =
                (int)$redisMemoryInfo['used_memory_peak'];
        }

        if (isset($redisMemoryInfo['maxmemory'])) {

            $redisMaxMemory =
                (int)$redisMemoryInfo['maxmemory'];
        }

        /*
         * Clients
         */

        if (isset($redisClientsInfo['connected_clients'])) {

            $redisConnectedClients =
                (int)$redisClientsInfo['connected_clients'];
        }

        if (isset($redisClientsInfo['blocked_clients'])) {

            $redisBlockedClients =
                (int)$redisClientsInfo['blocked_clients'];
        }

        /*
         * Stats
         */

        if (isset($redisStats['keyspace_hits'])) {

            $redisHits =
                (int)$redisStats['keyspace_hits'];
        }

        if (isset($redisStats['keyspace_misses'])) {

            $redisMisses =
                (int)$redisStats['keyspace_misses'];
        }

        if (
            isset($redisStats['instantaneous_ops_per_sec'])
        ) {

            $redisOpsPerSecond =
                (int)$redisStats['instantaneous_ops_per_sec'];
        }

        if (
            isset($redisStats['total_commands_processed'])
        ) {

            $redisCommandsProcessed =
                (int)$redisStats['total_commands_processed'];
        }

        /*
         * Hit Rate
         */

        $totalHitsMisses =
            $redisHits + $redisMisses;

        if ($totalHitsMisses > 0) {

            $redisHitRate =
                ($redisHits / $totalHitsMisses) * 100;
        }

        /*
         * Keyspace
         */

        if (is_array($redisKeyspace)) {

            foreach ($redisKeyspace as $dbName => $dbInfo) {

                if (
                    strpos(
                        (string)$dbName,
                        'db'
                    ) !== 0
                ) {
                    continue;
                }

                if (
                    is_array($dbInfo)
                    && isset($dbInfo['keys'])
                ) {

                    $redisTotalKeys +=
                        (int)$dbInfo['keys'];

                    $redisDatabaseCount++;
                }
            }
        }

    } catch (Throwable $e) {

        $redisConnected = false;

        $redisError =
            $e->getMessage();

        if ($redisError === '') {

            $redisError =
                'Redis 连接异常';
        }
    }
}


/* =========================================================
 * 当前时间
 * ======================================================= */

$currentTime = date(
    'Y-m-d H:i:s'
);

?>
<!DOCTYPE html>
<html lang="zh-CN">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>Redis - AppleCMS OPS</title>

<style>

* {
    box-sizing: border-box;
}

html,
body {
    margin: 0;
    padding: 0;
}

body {

    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        Roboto,
        Arial,
        "PingFang SC",
        "Microsoft YaHei",
        sans-serif;

    background: #f5f7fb;

    color: #172033;
}

a {
    text-decoration: none;
    color: inherit;
}

.app {

    min-height: 100vh;

    display: flex;
}


/* =========================================================
 * Sidebar
 * ======================================================= */

.sidebar {

    width: 240px;

    position: fixed;

    left: 0;
    top: 0;
    bottom: 0;

    background: #111827;

    color: #fff;

    padding: 22px 14px;

    z-index: 10;
}

.logo {

    font-size: 20px;

    font-weight: 700;

    padding:
        8px
        12px
        24px;
}

.logo small {

    display: block;

    margin-top: 5px;

    color: #9ca3af;

    font-size: 11px;

    font-weight: 400;
}

.menu-title {

    color: #6b7280;

    font-size: 11px;

    padding:
        15px
        12px
        7px;

    text-transform: uppercase;
}

.menu-item {

    display: flex;

    align-items: center;

    gap: 10px;

    padding:
        11px
        12px;

    margin:
        3px
        0;

    border-radius: 9px;

    color: #cbd5e1;

    font-size: 14px;
}

.menu-item:hover {

    background: #1f2937;

    color: #fff;
}

.menu-item.active {

    background: #2563eb;

    color: #fff;
}

.menu-icon {

    width: 22px;

    text-align: center;
}


/* =========================================================
 * Main
 * ======================================================= */

.main {

    margin-left: 240px;

    width:
        calc(100% - 240px);

    min-height: 100vh;
}

.topbar {

    height: 70px;

    background: #fff;

    border-bottom:
        1px solid #e5e7eb;

    display: flex;

    align-items: center;

    justify-content: space-between;

    padding:
        0
        30px;
}

.top-title {

    font-size: 19px;

    font-weight: 700;
}

.top-right {

    color: #64748b;

    font-size: 13px;
}

.content {

    padding: 28px;

    max-width: 1500px;
}


/* =========================================================
 * Header
 * ======================================================= */

.page-header {

    margin-bottom: 24px;
}

.page-header h1 {

    margin: 0;

    font-size: 25px;
}

.page-header p {

    color: #64748b;

    margin:
        7px
        0
        0;

    font-size: 13px;
}


/* =========================================================
 * Cards
 * ======================================================= */

.grid {

    display: grid;

    grid-template-columns:
        repeat(
            auto-fit,
            minmax(
                250px,
                1fr
            )
        );

    gap: 18px;

    margin-bottom: 18px;
}

.card {

    background: #fff;

    border:
        1px solid #e5e7eb;

    border-radius: 14px;

    padding: 20px;

    box-shadow:
        0 2px 7px
        rgba(
            15,
            23,
            42,
            .03
        );
}

.card-title {

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 15px;

    margin-bottom: 18px;
}

.card-title h3 {

    margin: 0;

    font-size: 16px;
}

.big-value {

    font-size: 27px;

    font-weight: 700;

    margin-top: 5px;
}

.sub-value {

    margin-top: 5px;

    color: #64748b;

    font-size: 12px;
}

.info-row {

    display: flex;

    justify-content: space-between;

    align-items: center;

    gap: 20px;

    padding:
        9px
        0;

    border-bottom:
        1px solid #f1f5f9;

    font-size: 13px;
}

.info-row:last-child {

    border-bottom: 0;
}

.info-label {

    color: #64748b;
}

.info-value {

    font-weight: 600;

    text-align: right;

    word-break: break-all;
}


/* =========================================================
 * Status
 * ======================================================= */

.status {

    display: inline-flex;

    align-items: center;

    gap: 6px;

    font-size: 12px;

    font-weight: 700;
}

.status .dot {

    width: 7px;

    height: 7px;

    border-radius: 50%;
}

.status.success {

    color: #16a34a;
}

.status.success .dot {

    background: #22c55e;

    box-shadow:
        0 0 0 3px
        #dcfce7;
}

.status.danger {

    color: #dc2626;
}

.status.danger .dot {

    background: #ef4444;

    box-shadow:
        0 0 0 3px
        #fee2e2;
}


/* =========================================================
 * Alert
 * ======================================================= */

.alert {

    background: #fff1f2;

    border:
        1px solid #fecdd3;

    color: #be123c;

    padding:
        13px
        15px;

    border-radius: 9px;

    margin-bottom: 18px;

    font-size: 13px;
}

.success-box {

    background: #f0fdf4;

    border:
        1px solid #bbf7d0;

    color: #15803d;

    padding:
        13px
        15px;

    border-radius: 9px;

    margin-bottom: 18px;

    font-size: 13px;
}


/* =========================================================
 * Progress
 * ======================================================= */

.progress {

    height: 8px;

    background: #e5e7eb;

    border-radius: 20px;

    overflow: hidden;

    margin-top: 12px;
}

.progress-bar {

    height: 100%;

    background: #2563eb;

    border-radius: 20px;

    transition: width .3s;
}


/* =========================================================
 * Refresh
 * ======================================================= */

.refresh {

    display: inline-flex;

    align-items: center;

    gap: 7px;

    padding:
        8px
        12px;

    background: #fff;

    border:
        1px solid #e5e7eb;

    border-radius: 8px;

    font-size: 12px;

    color: #475569;

    cursor: pointer;
}

.refresh:hover {

    background: #f8fafc;
}


/* =========================================================
 * Responsive
 * ======================================================= */

@media (max-width: 800px) {

    .sidebar {

        width: 68px;

        padding:
            15px
            8px;
    }

    .logo {

        font-size: 0;

        text-align: center;
    }

    .logo small,
    .menu-title,
    .menu-text {

        display: none;
    }

    .menu-item {

        justify-content: center;
    }

    .main {

        margin-left: 68px;

        width:
            calc(100% - 68px);
    }

    .content {

        padding: 18px;
    }
}

</style>

</head>

<body>

<div class="app">


<!-- =====================================================
     Sidebar
====================================================== -->

<aside class="sidebar">

    <div class="logo">

        AppleCMS OPS

        <small>
            Server Management
        </small>

    </div>


    <div class="menu-title">
        Overview
    </div>


    <a
        class="menu-item"
        href="index.php?page=dashboard"
    >

        <span class="menu-icon">
            ⌂
        </span>

        <span class="menu-text">
            Dashboard
        </span>

    </a>


    <div class="menu-title">
        Services
    </div>


    <a
        class="menu-item active"
        href="redis.php"
    >

        <span class="menu-icon">
            R
        </span>

        <span class="menu-text">
            Redis
        </span>

    </a>


    <a
        class="menu-item"
        href="index.php?page=mysql"
    >

        <span class="menu-icon">
            M
        </span>

        <span class="menu-text">
            MySQL
        </span>

    </a>


    <a
        class="menu-item"
        href="index.php?page=meilisearch"
    >

        <span class="menu-icon">
            S
        </span>

        <span class="menu-text">
            Meilisearch
        </span>

    </a>

</aside>


<!-- =====================================================
     Main
====================================================== -->

<main class="main">


<header class="topbar">

    <div class="top-title">
        Redis
    </div>

    <div class="top-right">

        PHP <?php echo h(PHP_VERSION); ?>

    </div>

</header>


<section class="content">


<div class="page-header">

    <div
        style="
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:15px;
        "
    >

        <div>

            <h1>
                Redis
            </h1>

            <p>
                Redis 服务状态、性能与缓存信息
            </p>

        </div>


        <button
            class="refresh"
            onclick="location.reload();"
        >
            ↻ 刷新
        </button>

    </div>

</div>


<?php if (!$redisConnected): ?>


<div class="alert">

    <strong>
        Redis 连接失败
    </strong>

    <br>

    <?php echo h($redisError); ?>

</div>


<div class="card">

    <div class="card-title">

        <h3>
            Redis Configuration
        </h3>

        <?php

        echo redisStatus(
            $redisHost !== '',
            '已读取',
            '未读取'
        );

        ?>

    </div>


    <div class="info-row">

        <span class="info-label">
            Host
        </span>

        <span class="info-value">
            <?php echo h($redisHost ?: '-'); ?>
        </span>

    </div>


    <div class="info-row">

        <span class="info-label">
            Port
        </span>

        <span class="info-value">
            <?php echo h($redisPort); ?>
        </span>

    </div>


    <div class="info-row">

        <span class="info-label">
            Database
        </span>

        <span class="info-value">
            <?php echo h($redisDb); ?>
        </span>

    </div>


    <div class="info-row">

        <span class="info-label">
            PHP Redis Extension
        </span>

        <span class="info-value">

            <?php

            echo redisStatus(
                $redisExtensionAvailable,
                'Available',
                'Missing'
            );

            ?>

        </span>

    </div>

</div>


<?php else: ?>


<div class="success-box">

    <strong>
        Redis 连接正常
    </strong>

    <br>

    已成功读取 AppleCMS Redis 配置并连接 Redis 服务。

</div>


<!-- =====================================================
     第一行：核心指标
====================================================== -->

<div class="grid">


    <!-- Redis Status -->

    <div class="card">

        <div class="card-title">

            <h3>
                Status
            </h3>

            <?php

            echo redisStatus(
                true,
                '在线',
                '离线'
            );

            ?>

        </div>

        <div class="big-value">

            Redis
            <?php echo h($redisVersion); ?>

        </div>

        <div class="sub-value">

            <?php echo h($redisMode); ?>

        </div>

    </div>


    <!-- Keys -->

    <div class="card">

        <div class="card-title">

            <h3>
                Keys
            </h3>

        </div>

        <div class="big-value">

            <?php echo number_format($redisTotalKeys); ?>

        </div>

        <div class="sub-value">

            <?php echo h($redisDatabaseCount); ?>
            个数据库正在使用

        </div>

    </div>


    <!-- Memory -->

    <div class="card">

        <div class="card-title">

            <h3>
                Memory
            </h3>

        </div>

        <div class="big-value">

            <?php echo h(formatBytes($redisUsedMemory)); ?>

        </div>

        <div class="sub-value">

            Peak：
            <?php echo h(formatBytes($redisPeakMemory)); ?>

        </div>

    </div>


    <!-- Hit Rate -->

    <div class="card">

        <div class="card-title">

            <h3>
                Hit Rate
            </h3>

        </div>

        <div class="big-value">

            <?php

            echo number_format(
                $redisHitRate,
                2
            );

            ?>%

        </div>

        <div class="sub-value">

            Hits：
            <?php echo number_format($redisHits); ?>

            /
            Miss：
            <?php echo number_format($redisMisses); ?>

        </div>

    </div>

</div>


<!-- =====================================================
     第二行
====================================================== -->

<div class="grid">


    <!-- Server -->

    <div class="card">

        <div class="card-title">

            <h3>
                Server
            </h3>

        </div>


        <div class="info-row">

            <span class="info-label">
                Version
            </span>

            <span class="info-value">

                <?php echo h($redisVersion); ?>

            </span>

        </div>


        <div class="info-row">

            <span class="info-label">
                Mode
            </span>

            <span class="info-value">

                <?php echo h($redisMode); ?>

            </span>

        </div>


        <div class="info-row">

            <span class="info-label">
                Uptime
            </span>

            <span class="info-value">

                <?php echo h(
                    formatSeconds(
                        $redisUptime
                    )
                ); ?>

            </span>

        </div>


        <div class="info-row">

            <span class="info-label">
                Commands
            </span>

            <span class="info-value">

                <?php echo number_format(
                    $redisCommandsProcessed
                ); ?>

            </span>

        </div>

    </div>


    <!-- Clients -->

    <div class="card">

        <div class="card-title">

            <h3>
                Clients
            </h3>

        </div>


        <div class="info-row">

            <span class="info-label">
                Connected
            </span>

            <span class="info-value">

                <?php echo number_format(
                    $redisConnectedClients
                ); ?>

            </span>

        </div>


        <div class="info-row">

            <span class="info-label">
                Blocked
            </span>

            <span class="info-value">

                <?php echo number_format(
                    $redisBlockedClients
                ); ?>

            </span>

        </div>


        <div class="info-row">

            <span class="info-label">
                Operations / sec
            </span>

            <span class="info-value">

                <?php echo number_format(
                    $redisOpsPerSecond
                ); ?>

            </span>

        </div>

    </div>


    <!-- Memory -->

    <div class="card">

        <div class="card-title">

            <h3>
                Memory
            </h3>

        </div>


        <div class="info-row">

            <span class="info-label">
                Used
            </span>

            <span class="info-value">

                <?php echo h(
                    formatBytes(
                        $redisUsedMemory
                    )
                ); ?>

            </span>

        </div>


        <div class="info-row">

            <span class="info-label">
                Peak
            </span>

            <span class="info-value">

                <?php echo h(
                    formatBytes(
                        $redisPeakMemory
                    )
                ); ?>

            </span>

        </div>


        <div class="info-row">

            <span class="info-label">
                Max Memory
            </span>

            <span class="info-value">

                <?php

                if ($redisMaxMemory > 0) {

                    echo h(
                        formatBytes(
                            $redisMaxMemory
                        )
                    );

                } else {

                    echo 'Unlimited';

                }

                ?>

            </span>

        </div>


        <?php if ($redisMaxMemory > 0): ?>

            <div class="progress">

                <div
                    class="progress-bar"
                    style="
                        width:
                        <?php

                        echo min(
                            100,
                            max(
                                0,
                                ($redisUsedMemory / $redisMaxMemory) * 100
                            )
                        );

                        ?>%;
                    "
                ></div>

            </div>

        <?php endif; ?>

    </div>


    <!-- Connection -->

    <div class="card">

        <div class="card-title">

            <h3>
                Connection
            </h3>

        </div>


        <div class="info-row">

            <span class="info-label">
                Host
            </span>

            <span class="info-value">

                <?php echo h(
                    $redisHost
                ); ?>

            </span>

        </div>


        <div class="info-row">

            <span class="info-label">
                Port
            </span>

            <span class="info-value">

                <?php echo h(
                    $redisPort
                ); ?>

            </span>

        </div>


        <div class="info-row">

            <span class="info-label">
                Database
            </span>

            <span class="info-value">

                <?php echo h(
                    $redisDb
                ); ?>

            </span>

        </div>


        <div class="info-row">

            <span class="info-label">
                Password
            </span>

            <span class="info-value">

                <?php

                echo $redisPassword !== ''
                    ? h(
                        maskRedisSecret(
                            $redisPassword
                        )
                    )
                    : '未设置';

                ?>

            </span>

        </div>

    </div>

</div>


<!-- =====================================================
     AppleCMS Redis 配置
====================================================== -->

<div class="card">

    <div class="card-title">

        <h3>
            AppleCMS Redis Configuration
        </h3>

        <?php

        echo redisStatus(
            $redisHost !== '',
            '成功读取',
            '读取失败'
        );

        ?>

    </div>


    <div class="grid"
         style="margin-bottom:0;">


        <div>

            <div class="info-row">

                <span class="info-label">
                    cache_host
                </span>

                <span class="info-value">

                    <?php echo h(
                        $redisHost ?: '-'
                    ); ?>

                </span>

            </div>


            <div class="info-row">

                <span class="info-label">
                    cache_port
                </span>

                <span class="info-value">

                    <?php echo h(
                        $redisPort
                    ); ?>

                </span>

            </div>


            <div class="info-row">

                <span class="info-label">
                    cache_username
                </span>

                <span class="info-value">

                    <?php

                    echo $redisUsername !== ''
                        ? h($redisUsername)
                        : '空';

                    ?>

                </span>

            </div>


            <div class="info-row">

                <span class="info-label">
                    cache_password
                </span>

                <span class="info-value">

                    <?php

                    echo $redisPassword !== ''
                        ? h(
                            maskRedisSecret(
                                $redisPassword
                            )
                        )
                        : '空';

                    ?>

                </span>

            </div>

        </div>


        <div>

            <div class="info-row">

                <span class="info-label">
                    cache_db
                </span>

                <span class="info-value">

                    <?php echo h(
                        $redisDb
                    ); ?>

                </span>

            </div>


            <div class="info-row">

                <span class="info-label">
                    cache_flag
                </span>

                <span class="info-value">

                    <?php echo h(
                        $redisFlag ?: '-'
                    ); ?>

                </span>

            </div>


            <div class="info-row">

                <span class="info-label">
                    cache_core
                </span>

                <span class="info-value">

                    <?php echo h(
                        $redisCore ?: '-'
                    ); ?>

                </span>

            </div>


            <div class="info-row">

                <span class="info-label">
                    cache_time
                </span>

                <span class="info-value">

                    <?php echo h(
                        $redisCacheTime ?: '-'
                    ); ?>

                </span>

            </div>

        </div>

    </div>

</div>


<br>


<div
    style="
        text-align:right;
        color:#94a3b8;
        font-size:12px;
    "
>

    最后检测：
    <?php echo h($currentTime); ?>

</div>


<?php endif; ?>


</section>

</main>

</div>


<script>

/*
 * Redis 页面不使用高频 AJAX。
 *
 * 后面增加实时监控时，
 * 再改成局部 AJAX 更新，
 * 避免整个页面刷新。
 */

</script>

</body>

</html>
