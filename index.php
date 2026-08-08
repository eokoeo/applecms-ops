<?php
/**
 * AppleCMS OPS
 * Dashboard / Redis / MySQL / Meilisearch
 *
 * PHP 7.4+
 *
 * 自动读取：
 *   ../application/extra/maccms.php
 *   ../application/database.php
 */

declare(strict_types=1);

session_start();

/* =========================================================
 * 基础路径
 * ======================================================= */

$APP_ROOT = realpath(__DIR__ . '/..');

if ($APP_ROOT === false) {
    $APP_ROOT = dirname(__DIR__);
}

$MACCMS_CONFIG = $APP_ROOT . '/application/extra/maccms.php';
$DATABASE_CONFIG = $APP_ROOT . '/application/database.php';


/* =========================================================
 * 工具函数
 * ======================================================= */

/**
 * HTML 转义
 */
function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}


/**
 * 安全读取 PHP 配置文件
 *
 * 支持：
 * return array(...);
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
 * 递归寻找配置项
 *
 * 例如：
 * cache_host
 * cache_port
 * cache_password
 */
function findConfigValue(array $data, string $targetKey, &$found = false)
{
    foreach ($data as $key => $value) {

        if ((string)$key === $targetKey) {
            $found = true;
            return $value;
        }

        if (is_array($value)) {
            $result = findConfigValue($value, $targetKey, $found);

            if ($found) {
                return $result;
            }
        }
    }

    return null;
}


/**
 * 从多个可能的配置文件中寻找配置
 */
function findConfigValueFromFiles(array $files, string $key, &$found = false)
{
    foreach ($files as $file) {

        $config = loadPhpConfig($file);

        if (!$config) {
            continue;
        }

        $value = findConfigValue($config, $key, $localFound);

        if ($localFound) {
            $found = true;
            return $value;
        }
    }

    return null;
}


/**
 * 隐藏敏感信息
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
function statusBadge(bool $ok, string $success = '成功链接', string $failed = '连接失败'): string
{
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
 * 当前页面
 */
$page = isset($_GET['page']) ? trim((string)$_GET['page']) : 'dashboard';

$allowedPages = [
    'dashboard',
    'redis',
    'mysql',
    'meilisearch'
];

if (!in_array($page, $allowedPages, true)) {
    $page = 'dashboard';
}


/* =========================================================
 * 读取配置
 * ======================================================= */

$maccmsConfig = loadPhpConfig($MACCMS_CONFIG);
$databaseConfig = loadPhpConfig($DATABASE_CONFIG);


/* =========================================================
 * Redis 配置
 * ======================================================= */

$redisHostFound = false;
$redisPortFound = false;
$redisUsernameFound = false;
$redisPasswordFound = false;
$redisDbFound = false;

$redisHost = findConfigValue(
    $maccmsConfig,
    'cache_host',
    $redisHostFound
);

$redisPort = findConfigValue(
    $maccmsConfig,
    'cache_port',
    $redisPortFound
);

$redisUsername = findConfigValue(
    $maccmsConfig,
    'cache_username',
    $redisUsernameFound
);

$redisPassword = findConfigValue(
    $maccmsConfig,
    'cache_password',
    $redisPasswordFound
);

$redisDb = findConfigValue(
    $maccmsConfig,
    'cache_db',
    $redisDbFound
);


/* 默认值 */

if (!$redisPortFound || !$redisPort) {
    $redisPort = 6379;
}

if (!$redisDbFound || $redisDb === '') {
    $redisDb = 0;
}


/* =========================================================
 * Redis 连接检测
 * ======================================================= */

$redisExtensionAvailable = class_exists('Redis');

$redisConnected = false;
$redisError = '';

$redisInfo = [
    'host' => $redisHost,
    'port' => $redisPort,
    'db'   => $redisDb,
];

if (!$redisHostFound || trim((string)$redisHost) === '') {

    $redisError = '未读取到 Redis 配置';

} elseif (!$redisExtensionAvailable) {

    $redisError = 'PHP Redis 扩展未安装';

} else {

    try {

        $redis = new Redis();

        $redisTimeout = 1.5;

        $redis->connect(
            (string)$redisHost,
            (int)$redisPort,
            $redisTimeout
        );

        /*
         * 如果存在密码才认证
         */
        if ($redisPasswordFound && trim((string)$redisPassword) !== '') {

            /*
             * Redis ACL 用户名
             */
            if ($redisUsernameFound && trim((string)$redisUsername) !== '') {

                $redis->auth([
                    (string)$redisUsername,
                    (string)$redisPassword
                ]);

            } else {

                $redis->auth((string)$redisPassword);
            }
        }

        /*
         * 选择 DB
         */
        if ((int)$redisDb >= 0) {
            $redis->select((int)$redisDb);
        }

        /*
         * 最终 PING
         */
        $pong = $redis->ping();

        if ($pong !== false) {

            $redisConnected = true;

        } else {

            $redisError = 'Redis PING 失败';
        }

        $redis->close();

    } catch (Throwable $e) {

        $redisError = $e->getMessage();

        if ($redisError === '') {
            $redisError = 'Redis 连接异常';
        }
    }
}


/* =========================================================
 * MySQL 配置
 * ======================================================= */

$dbHostFound = false;
$dbNameFound = false;
$dbUserFound = false;
$dbPasswordFound = false;
$dbPortFound = false;

$dbHost = findConfigValue(
    $databaseConfig,
    'hostname',
    $dbHostFound
);

$dbName = findConfigValue(
    $databaseConfig,
    'database',
    $dbNameFound
);

$dbUser = findConfigValue(
    $databaseConfig,
    'username',
    $dbUserFound
);

$dbPassword = findConfigValue(
    $databaseConfig,
    'password',
    $dbPasswordFound
);

$dbPort = findConfigValue(
    $databaseConfig,
    'hostport',
    $dbPortFound
);

if (!$dbPortFound || !$dbPort) {
    $dbPort = 3306;
}


/* =========================================================
 * MySQL 连接检测
 * ======================================================= */

$pdoAvailable = class_exists('PDO');
$pdoMysqlAvailable = false;

if ($pdoAvailable) {

    try {

        $drivers = PDO::getAvailableDrivers();

        $pdoMysqlAvailable = in_array(
            'mysql',
            $drivers,
            true
        );

    } catch (Throwable $e) {

        $pdoMysqlAvailable = false;
    }
}

$mysqlConnected = false;
$mysqlError = '';

if (
    !$dbHostFound ||
    !$dbNameFound ||
    !$dbUserFound
) {

    $mysqlError = '未读取到完整 MySQL 配置';

} elseif (!$pdoAvailable) {

    $mysqlError = 'PDO 未安装';

} elseif (!$pdoMysqlAvailable) {

    $mysqlError = 'PDO MySQL 扩展未安装';

} else {

    try {

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $dbHost,
            (int)$dbPort,
            $dbName
        );

        $pdo = new PDO(
            $dsn,
            (string)$dbUser,
            (string)$dbPassword,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 2,
            ]
        );

        $pdo->query('SELECT 1');

        $mysqlConnected = true;

    } catch (Throwable $e) {

        $mysqlError = $e->getMessage();

        if ($mysqlError === '') {
            $mysqlError = 'MySQL 连接异常';
        }
    }
}


/* =========================================================
 * Meilisearch 配置
 * ======================================================= */

$meiliEnabledFound = false;
$meiliHostFound = false;
$meiliIndexFound = false;
$meiliTimeoutFound = false;
$meiliApiKeyFound = false;

$meiliEnabled = findConfigValue(
    $maccmsConfig,
    'enabled',
    $meiliEnabledFound
);

/*
 * enabled 这个 key 可能来自其他配置。
 *
 * 因此优先从 meilisearch 子数组中读取。
 */
$meiliConfig = [];

if (isset($maccmsConfig['meilisearch'])
    && is_array($maccmsConfig['meilisearch'])) {

    $meiliConfig = $maccmsConfig['meilisearch'];
}

if ($meiliConfig) {

    if (array_key_exists('enabled', $meiliConfig)) {
        $meiliEnabled = $meiliConfig['enabled'];
        $meiliEnabledFound = true;
    }

    if (array_key_exists('host', $meiliConfig)) {
        $meiliHost = $meiliConfig['host'];
        $meiliHostFound = true;
    } else {
        $meiliHost = '';
    }

    if (array_key_exists('index_uid', $meiliConfig)) {
        $meiliIndex = $meiliConfig['index_uid'];
        $meiliIndexFound = true;
    } else {
        $meiliIndex = '';
    }

    if (array_key_exists('timeout', $meiliConfig)) {
        $meiliTimeout = $meiliConfig['timeout'];
        $meiliTimeoutFound = true;
    } else {
        $meiliTimeout = 3;
    }

    if (array_key_exists('api_key', $meiliConfig)) {
        $meiliApiKey = $meiliConfig['api_key'];
        $meiliApiKeyFound = true;
    } else {
        $meiliApiKey = '';
    }

} else {

    $meiliHost = '';
    $meiliIndex = '';
    $meiliTimeout = 3;
    $meiliApiKey = '';
}


/* =========================================================
 * Meilisearch 连接检测
 * ======================================================= */

$meiliConnected = false;
$meiliError = '';

if (!$meiliHostFound || trim((string)$meiliHost) === '') {

    $meiliError = '未读取到 Meilisearch 配置';

} elseif ((string)$meiliEnabled !== '1'
    && $meiliEnabled !== 1
    && $meiliEnabled !== true) {

    $meiliError = 'Meilisearch 未启用';

} else {

    try {

        $url = rtrim((string)$meiliHost, '/')
            . '/health';

        $ch = curl_init();

        curl_setopt_array(
            $ch,
            [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 1,
                CURLOPT_TIMEOUT => (int)$meiliTimeout,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . (string)$meiliApiKey
                ],
            ]
        );

        $response = curl_exec($ch);

        $curlError = curl_error($ch);

        $httpCode = (int)curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

        curl_close($ch);

        if ($response !== false && $httpCode >= 200 && $httpCode < 300) {

            $health = json_decode(
                $response,
                true
            );

            if (
                is_array($health) &&
                isset($health['status']) &&
                $health['status'] === 'available'
            ) {

                $meiliConnected = true;

            } else {

                /*
                 * 某些版本返回 200 但结构略有不同。
                 * HTTP 200 也认为服务正常。
                 */
                $meiliConnected = true;
            }

        } else {

            $meiliError = $curlError !== ''
                ? $curlError
                : 'HTTP ' . $httpCode;
        }

    } catch (Throwable $e) {

        $meiliError = $e->getMessage();

        if ($meiliError === '') {
            $meiliError = 'Meilisearch 连接异常';
        }
    }
}


/* =========================================================
 * PHP / 系统信息
 * ======================================================= */

$phpVersion = PHP_VERSION;

$phpSapi = PHP_SAPI;

$redisExtensionText = $redisExtensionAvailable
    ? 'Available'
    : 'Missing';

$pdoMysqlText = $pdoMysqlAvailable
    ? 'Available'
    : 'Missing';


/* =========================================================
 * 页面
 * ======================================================= */
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width,initial-scale=1.0">

<title>AppleCMS OPS</title>

<style>

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
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        Roboto,
        "Helvetica Neue",
        Arial,
        "PingFang SC",
        "Microsoft YaHei",
        sans-serif;

    background: #f5f7fb;
    color: #172033;
}

a {
    color: inherit;
    text-decoration: none;
}

.app {
    display: flex;
    min-height: 100vh;
}


/* =========================================================
 * Sidebar
 * ======================================================= */

.sidebar {
    width: 240px;
    background: #111827;
    color: #fff;
    position: fixed;
    left: 0;
    top: 0;
    bottom: 0;
    padding: 22px 14px;
    z-index: 10;
}

.logo {
    font-size: 20px;
    font-weight: 700;
    padding: 8px 12px 24px;
}

.logo small {
    display: block;
    font-size: 11px;
    font-weight: 400;
    color: #9ca3af;
    margin-top: 5px;
}

.menu-title {
    color: #6b7280;
    font-size: 11px;
    padding: 15px 12px 7px;
    text-transform: uppercase;
}

.menu-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 11px 12px;
    margin: 3px 0;
    border-radius: 9px;
    color: #cbd5e1;
    font-size: 14px;
    transition: .15s;
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
    width: calc(100% - 240px);
    min-height: 100vh;
}

.topbar {
    height: 70px;
    background: #fff;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 30px;
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
 * Cards
 * ======================================================= */

.grid {
    display: grid;
    grid-template-columns:
        repeat(auto-fit, minmax(260px, 1fr));

    gap: 18px;
}

.card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    padding: 20px;
    box-shadow:
        0 2px 7px rgba(15, 23, 42, .03);
}

.card-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 18px;
}

.card-title h3 {
    margin: 0;
    font-size: 16px;
}

.info-row {
    display: flex;
    justify-content: space-between;
    gap: 20px;
    padding: 9px 0;
    border-bottom: 1px solid #f1f5f9;
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
    display: inline-block;
}

.status.success {
    color: #16a34a;
}

.status.success .dot {
    background: #22c55e;
    box-shadow: 0 0 0 3px #dcfce7;
}

.status.danger {
    color: #dc2626;
}

.status.danger .dot {
    background: #ef4444;
    box-shadow: 0 0 0 3px #fee2e2;
}

.status.warning {
    color: #d97706;
}

.status.warning .dot {
    background: #f59e0b;
}


/* =========================================================
 * Page
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
    margin: 7px 0 0;
    font-size: 13px;
}

.alert {
    background: #fff1f2;
    border: 1px solid #fecdd3;
    color: #be123c;
    padding: 13px 15px;
    border-radius: 9px;
    margin-top: 15px;
    font-size: 13px;
}

.success-box {
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    color: #15803d;
    padding: 13px 15px;
    border-radius: 9px;
    margin-top: 15px;
    font-size: 13px;
}


/* =========================================================
 * Responsive
 * ======================================================= */

@media (max-width: 800px) {

    .sidebar {
        width: 68px;
        padding: 15px 8px;
    }

    .logo {
        font-size: 0;
        text-align: center;
    }

    .logo:first-letter {
        font-size: 20px;
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
        width: calc(100% - 68px);
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
            <small>Server Management</small>
        </div>

        <div class="menu-title">
            Overview
        </div>

        <a
            class="menu-item <?php echo $page === 'dashboard' ? 'active' : ''; ?>"
            href="?page=dashboard"
        >
            <span class="menu-icon">⌂</span>
            <span class="menu-text">Dashboard</span>
        </a>


        <div class="menu-title">
            Services
        </div>

        <a
            class="menu-item <?php echo $page === 'redis' ? 'active' : ''; ?>"
            href="redis.php"
        >
            <span class="menu-icon">R</span>
            <span class="menu-text">Redis</span>
        </a>

        <a
            class="menu-item <?php echo $page === 'mysql' ? 'active' : ''; ?>"
            href="?page=mysql"
        >
            <span class="menu-icon">M</span>
            <span class="menu-text">MySQL</span>
        </a>

        <a
            class="menu-item <?php echo $page === 'meilisearch' ? 'active' : ''; ?>"
            href="?page=meilisearch"
        >
            <span class="menu-icon">S</span>
            <span class="menu-text">Meilisearch</span>
        </a>

    </aside>


    <!-- =====================================================
         Main
    ====================================================== -->

    <main class="main">

        <header class="topbar">

            <div class="top-title">
                <?php

                if ($page === 'redis') {
                    echo 'Redis';
                } elseif ($page === 'mysql') {
                    echo 'MySQL';
                } elseif ($page === 'meilisearch') {
                    echo 'Meilisearch';
                } else {
                    echo 'Dashboard';
                }

                ?>
            </div>

            <div class="top-right">
                PHP <?php echo h($phpVersion); ?>
            </div>

        </header>


        <section class="content">


<?php
/* =========================================================
 * DASHBOARD
 * ======================================================= */

if ($page === 'dashboard'):
?>

<div class="page-header">

    <h1>Dashboard</h1>

    <p>
        AppleCMS 服务状态与配置检测
    </p>

</div>


<div class="grid">

    <!-- Redis -->

    <div class="card">

        <div class="card-title">

            <h3>Redis</h3>

            <?php
            echo statusBadge(
                $redisConnected,
                '成功链接',
                '连接失败'
            );
            ?>

        </div>

        <div class="info-row">
            <span class="info-label">
                Configuration
            </span>

            <span class="info-value">

                <?php

                echo $redisHostFound
                    ? '<span class="status success">已读取</span>'
                    : '<span class="status danger">未读取</span>';

                ?>

            </span>
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
                PHP Extension
            </span>

            <span class="info-value">
                <?php echo h($redisExtensionText); ?>
            </span>
        </div>

        <?php if (!$redisConnected): ?>

            <div class="alert">
                <?php echo h($redisError); ?>
            </div>

        <?php else: ?>

            <div class="success-box">
                Redis 连接正常，可以进行后续管理。
            </div>

        <?php endif; ?>

    </div>


    <!-- MySQL -->

    <div class="card">

        <div class="card-title">

            <h3>MySQL</h3>

            <?php
            echo statusBadge(
                $mysqlConnected,
                '成功链接',
                '连接失败'
            );
            ?>

        </div>

        <div class="info-row">
            <span class="info-label">
                Configuration
            </span>

            <span class="info-value">

                <?php

                echo (
                    $dbHostFound &&
                    $dbNameFound &&
                    $dbUserFound
                )
                    ? '<span class="status success">已读取</span>'
                    : '<span class="status danger">未读取</span>';

                ?>

            </span>
        </div>

        <div class="info-row">
            <span class="info-label">
                Host
            </span>

            <span class="info-value">
                <?php echo h($dbHost ?: '-'); ?>
            </span>
        </div>

        <div class="info-row">
            <span class="info-label">
                Database
            </span>

            <span class="info-value">
                <?php echo h($dbName ?: '-'); ?>
            </span>
        </div>

        <div class="info-row">
            <span class="info-label">
                Username
            </span>

            <span class="info-value">
                <?php echo h($dbUser ?: '-'); ?>
            </span>
        </div>

        <div class="info-row">
            <span class="info-label">
                PDO MySQL
            </span>

            <span class="info-value">
                <?php echo h($pdoMysqlText); ?>
            </span>
        </div>

        <?php if (!$mysqlConnected): ?>

            <div class="alert">
                <?php echo h($mysqlError); ?>
            </div>

        <?php else: ?>

            <div class="success-box">
                MySQL 数据库连接正常。
            </div>

        <?php endif; ?>

    </div>


    <!-- Meilisearch -->

    <div class="card">

        <div class="card-title">

            <h3>Meilisearch</h3>

            <?php
            echo statusBadge(
                $meiliConnected,
                '成功链接',
                '连接失败'
            );
            ?>

        </div>

        <div class="info-row">
            <span class="info-label">
                Configuration
            </span>

            <span class="info-value">

                <?php

                echo (
                    $meiliHostFound &&
                    $meiliIndexFound
                )
                    ? '<span class="status success">已读取</span>'
                    : '<span class="status danger">未读取</span>';

                ?>

            </span>
        </div>

        <div class="info-row">
            <span class="info-label">
                Host
            </span>

            <span class="info-value">
                <?php echo h($meiliHost ?: '-'); ?>
            </span>
        </div>

        <div class="info-row">
            <span class="info-label">
                Index
            </span>

            <span class="info-value">
                <?php echo h($meiliIndex ?: '-'); ?>
            </span>
        </div>

        <div class="info-row">
            <span class="info-label">
                Enabled
            </span>

            <span class="info-value">

                <?php

                echo (
                    (string)$meiliEnabled === '1' ||
                    $meiliEnabled === true ||
                    $meiliEnabled === 1
                )
                    ? '<span class="status success">Enabled</span>'
                    : '<span class="status danger">Disabled</span>';

                ?>

            </span>
        </div>

        <div class="info-row">
            <span class="info-label">
                API Key
            </span>

            <span class="info-value">

                <?php

                echo $meiliApiKeyFound
                    ? h(maskSecret($meiliApiKey))
                    : '-';

                ?>

            </span>
        </div>

        <?php if (!$meiliConnected): ?>

            <div class="alert">
                <?php echo h($meiliError); ?>
            </div>

        <?php else: ?>

            <div class="success-box">
                Meilisearch 服务连接正常。
            </div>

        <?php endif; ?>

    </div>

</div>


<br>


<div class="grid">

    <div class="card">

        <div class="card-title">

            <h3>AppleCMS Configuration</h3>

        </div>

        <div class="info-row">

            <span class="info-label">
                AppleCMS Config
            </span>

            <span class="info-value">

                <?php

                echo $maccmsConfig
                    ? '<span class="status success">成功读取</span>'
                    : '<span class="status danger">读取失败</span>';

                ?>

            </span>

        </div>

        <div class="info-row">

            <span class="info-label">
                Database Config
            </span>

            <span class="info-value">

                <?php

                echo $databaseConfig
                    ? '<span class="status success">成功读取</span>'
                    : '<span class="status danger">读取失败</span>';

                ?>

            </span>

        </div>

        <div class="info-row">

            <span class="info-label">
                Application Root
            </span>

            <span class="info-value">
                <?php echo h($APP_ROOT); ?>
            </span>

        </div>

        <div class="info-row">

            <span class="info-label">
                PHP SAPI
            </span>

            <span class="info-value">
                <?php echo h($phpSapi); ?>
            </span>

        </div>

    </div>


    <div class="card">

        <div class="card-title">

            <h3>Runtime</h3>

        </div>

        <div class="info-row">

            <span class="info-label">
                PHP Version
            </span>

            <span class="info-value">
                <?php echo h($phpVersion); ?>
            </span>

        </div>

        <div class="info-row">

            <span class="info-label">
                Redis Extension
            </span>

            <span class="info-value">

                <?php

                echo $redisExtensionAvailable
                    ? '<span class="status success">Available</span>'
                    : '<span class="status danger">Missing</span>';

                ?>

            </span>

        </div>

        <div class="info-row">

            <span class="info-label">
                PDO MySQL
            </span>

            <span class="info-value">

                <?php

                echo $pdoMysqlAvailable
                    ? '<span class="status success">Available</span>'
                    : '<span class="status danger">Missing</span>';

                ?>

            </span>

        </div>

        <div class="info-row">

            <span class="info-label">
                cURL
            </span>

            <span class="info-value">

                <?php

                echo function_exists('curl_init')
                    ? '<span class="status success">Available</span>'
                    : '<span class="status danger">Missing</span>';

                ?>

            </span>

        </div>

    </div>

</div>


<?php
/* =========================================================
 * REDIS PAGE
 * ======================================================= */

elseif ($page === 'redis'):
?>

<div class="page-header">

    <h1>Redis</h1>

    <p>
        Redis 配置与连接状态
    </p>

</div>


<div class="card">

    <div class="card-title">

        <h3>Connection Status</h3>

        <?php
        echo statusBadge(
            $redisConnected,
            '成功链接',
            '连接失败'
        );
        ?>

    </div>


    <?php if (!$redisConnected): ?>

        <div class="alert">

            <?php echo h($redisError); ?>

        </div>

    <?php else: ?>

        <div class="success-box">

            Redis 已成功连接。

        </div>

    <?php endif; ?>


    <div class="info-row">

        <span class="info-label">
            Configuration
        </span>

        <span class="info-value">

            <?php

            echo $redisHostFound
                ? '<span class="status success">成功读取</span>'
                : '<span class="status danger">未读取</span>';

            ?>

        </span>

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
            Username
        </span>

        <span class="info-value">

            <?php

            echo $redisUsername !== ''
                ? h($redisUsername)
                : '-';

            ?>

        </span>

    </div>


    <div class="info-row">

        <span class="info-label">
            Password
        </span>

        <span class="info-value">

            <?php

            echo $redisPasswordFound
                ? h(maskSecret($redisPassword))
                : '-';

            ?>

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

            echo $redisExtensionAvailable
                ? '<span class="status success">Available</span>'
                : '<span class="status danger">Missing</span>';

            ?>

        </span>

    </div>

</div>


<?php
/* =========================================================
 * MYSQL PAGE
 * ======================================================= */

elseif ($page === 'mysql'):
?>

<div class="page-header">

    <h1>MySQL</h1>

    <p>
        AppleCMS 数据库连接检测
    </p>

</div>


<div class="card">

    <div class="card-title">

        <h3>Connection Status</h3>

        <?php
        echo statusBadge(
            $mysqlConnected,
            '成功链接',
            '连接失败'
        );
        ?>

    </div>


    <?php if (!$mysqlConnected): ?>

        <div class="alert">

            <?php echo h($mysqlError); ?>

        </div>

    <?php else: ?>

        <div class="success-box">

            MySQL 已成功连接。

        </div>

    <?php endif; ?>


    <div class="info-row">

        <span class="info-label">
            Host
        </span>

        <span class="info-value">
            <?php echo h($dbHost ?: '-'); ?>
        </span>

    </div>


    <div class="info-row">

        <span class="info-label">
            Port
        </span>

        <span class="info-value">
            <?php echo h($dbPort); ?>
        </span>

    </div>


    <div class="info-row">

        <span class="info-label">
            Database
        </span>

        <span class="info-value">
            <?php echo h($dbName ?: '-'); ?>
        </span>

    </div>


    <div class="info-row">

        <span class="info-label">
            Username
        </span>

        <span class="info-value">
            <?php echo h($dbUser ?: '-'); ?>
        </span>

    </div>


    <div class="info-row">

        <span class="info-label">
            Password
        </span>

        <span class="info-value">

            <?php

            echo $dbPasswordFound
                ? h(maskSecret($dbPassword))
                : '-';

            ?>

        </span>

    </div>


    <div class="info-row">

        <span class="info-label">
            PDO
        </span>

        <span class="info-value">

            <?php

            echo $pdoAvailable
                ? '<span class="status success">Available</span>'
                : '<span class="status danger">Missing</span>';

            ?>

        </span>

    </div>


    <div class="info-row">

        <span class="info-label">
            PDO MySQL
        </span>

        <span class="info-value">

            <?php

            echo $pdoMysqlAvailable
                ? '<span class="status success">Available</span>'
                : '<span class="status danger">Missing</span>';

            ?>

        </span>

    </div>

</div>


<?php
/* =========================================================
 * MEILISEARCH PAGE
 * ======================================================= */

elseif ($page === 'meilisearch'):
?>

<div class="page-header">

    <h1>Meilisearch</h1>

    <p>
        AppleCMS Meilisearch 配置与服务检测
    </p>

</div>


<div class="card">

    <div class="card-title">

        <h3>Connection Status</h3>

        <?php
        echo statusBadge(
            $meiliConnected,
            '成功链接',
            '连接失败'
        );
        ?>

    </div>


    <?php if (!$meiliConnected): ?>

        <div class="alert">

            <?php echo h($meiliError); ?>

        </div>

    <?php else: ?>

        <div class="success-box">

            Meilisearch 已成功连接。

        </div>

    <?php endif; ?>


    <div class="info-row">

        <span class="info-label">
            Host
        </span>

        <span class="info-value">

            <?php echo h($meiliHost ?: '-'); ?>

        </span>

    </div>


    <div class="info-row">

        <span class="info-label">
            Index UID
        </span>

        <span class="info-value">

            <?php echo h($meiliIndex ?: '-'); ?>

        </span>

    </div>


    <div class="info-row">

        <span class="info-label">
            Enabled
        </span>

        <span class="info-value">

            <?php

            echo (
                (string)$meiliEnabled === '1' ||
                $meiliEnabled === true ||
                $meiliEnabled === 1
            )
                ? '<span class="status success">Enabled</span>'
                : '<span class="status danger">Disabled</span>';

            ?>

        </span>

    </div>


    <div class="info-row">

        <span class="info-label">
            Timeout
        </span>

        <span class="info-value">

            <?php echo h($meiliTimeout); ?> s

        </span>

    </div>


    <div class="info-row">

        <span class="info-label">
            API Key
        </span>

        <span class="info-value">

            <?php

            echo $meiliApiKeyFound
                ? h(maskSecret($meiliApiKey))
                : '-';

            ?>

        </span>

    </div>

</div>

<?php endif; ?>


        </section>

    </main>

</div>

</body>
</html> 
