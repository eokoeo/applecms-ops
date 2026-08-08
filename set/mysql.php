<?php
/**
 * AppleCMS OPS
 * MySQL Management, Diagnostics & Batch Tools
 *
 * PHP 7.4+
 */

declare(strict_types=1);

session_start();

$APP_ROOT = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$DATABASE_CONFIG = $APP_ROOT . '/application/database.php';

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

$databaseConfig = loadPhpConfig($DATABASE_CONFIG);

$dbHost = findConfigValue($databaseConfig, 'hostname', $dbHostFound);
$dbName = findConfigValue($databaseConfig, 'database', $dbNameFound);
$dbUser = findConfigValue($databaseConfig, 'username', $dbUserFound);
$dbPassword = findConfigValue($databaseConfig, 'password', $dbPasswordFound);
$dbPort = findConfigValue($databaseConfig, 'hostport', $dbPortFound) ?: 3306;
$dbPrefix = findConfigValue($databaseConfig, 'prefix', $dbPrefixFound) ?: 'mac_';

$dbConnected = false;
$dbError = '';
$pdo = null;
$dbServerInfo = [];
$tableStats = [];
$actionMessage = '';
$actionError = '';
$searchResults = [];
$searchError = '';
$searchKeyword = trim($_GET['s'] ?? ($_POST['search_keyword'] ?? ''));

if (!$dbHostFound || !$dbName) {
    $dbError = '未能在 database.php 中读取到完整的 MySQL 配置信息。';
} else {
    try {
        $startTime = microtime(true);
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbHost, (int)$dbPort, $dbName);
        $pdo = new PDO($dsn, (string)$dbUser, (string)$dbPassword, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 3,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
        ]);
        $pingTime = round((microtime(true) - $startTime) * 1000, 2);
        $dbConnected = true;

        $versionStmt = $pdo->query("SELECT VERSION() as version, @@version_comment as comment");
        $vData = $versionStmt->fetch(PDO::FETCH_ASSOC);
        $dbServerInfo['version'] = $vData['version'] ?? 'Unknown';
        $dbServerInfo['comment'] = $vData['comment'] ?? '';
        $dbServerInfo['ping'] = $pingTime;

        $tablesQuery = $pdo->query("SHOW TABLE STATUS");
        while ($row = $tablesQuery->fetch(PDO::FETCH_ASSOC)) {
            $tableStats[] = [
                'name' => $row['Name'],
                'rows' => $row['Rows'] ?? 0,
                'data_length' => round(($row['Data_Length'] ?? 0) / 1024 / 1024, 2),
                'index_length' => round(($row['Index_Length'] ?? 0) / 1024 / 1024, 2),
                'engine' => $row['Engine'] ?? '',
                'update_time' => $row['Update_Time'] ?? '-'
            ];
        }

    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}

if ($dbConnected && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $vodTable = $dbPrefix . 'vod';

    if ($action === 'optimize_tables') {
        try {
            foreach ($tableStats as $t) {
                $tName = $t['name'];
                $pdo->exec("OPTIMIZE TABLE `{$tName}`");
            }
            $actionMessage = "成功对所有数据库表进行了碎片优化 (OPTIMIZE TABLE)。";
        } catch (Throwable $e) {
            $actionError = "优化表失败: " . $e->getMessage();
        }
    } elseif ($action === 'update_vod') {
        $vodId = (int)($_POST['vod_id'] ?? 0);
        $vodName = trim($_POST['vod_name'] ?? '');
        $vodStatus = (int)($_POST['vod_status'] ?? 1);
        $vodPic = trim($_POST['vod_pic'] ?? '');
        $vodPlayUrl = trim($_POST['vod_play_url'] ?? '');
        
        if ($vodId > 0 && $vodName !== '') {
            $stmt = $pdo->prepare("UPDATE {$vodTable} SET vod_name = :name, vod_status = :status, vod_pic = :pic, vod_play_url = :play_url WHERE vod_id = :id");
            $stmt->execute([
                'name' => $vodName,
                'status' => $vodStatus,
                'pic' => $vodPic,
                'play_url' => $vodPlayUrl,
                'id' => $vodId
            ]);
            $actionMessage = "视频表 [{$vodTable}] 中的 ID [{$vodId}] 修改成功！";
        } else {
            $actionError = "修改失败：视频标题不能为空。";
        }
    } elseif ($action === 'delete_vod') {
        $vodId = (int)($_POST['vod_id'] ?? 0);
        if ($vodId > 0) {
            $stmt = $pdo->prepare("DELETE FROM {$vodTable} WHERE vod_id = :id");
            $stmt->execute(['id' => $vodId]);
            $actionMessage = "视频表 [{$vodTable}] 中的 ID [{$vodId}] 已成功删除。";
        }
    } elseif ($action === 'global_replace') {
        $targetField = $_POST['target_field'] ?? '';
        $findStr = trim($_POST['find_str'] ?? '');
        $replaceStr = trim($_POST['replace_str'] ?? '');

        if (in_array($targetField, ['vod_pic', 'vod_play_url', 'vod_name'], true) && $findStr !== '') {
            $sql = "UPDATE {$vodTable} SET `{$targetField}` = REPLACE(`{$targetField}`, :find, :replace) WHERE `{$targetField}` LIKE :like";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'find' => $findStr,
                'replace' => $replaceStr,
                'like' => '%' . $findStr . '%'
            ]);
            $rowCount = (int)$stmt->rowCount();
            $actionMessage = "表 [{$vodTable}] 全局替换成功！受影响的记录数共计：{$rowCount} 条。";
        } else {
            $actionError = "全局替换参数不完整或字段不合法。";
        }
    }
}

// 关键词检索逻辑
if ($dbConnected && $searchKeyword !== '') {
    try {
        $vodTable = $dbPrefix . 'vod';
        $stmt = $pdo->prepare("SELECT vod_id, vod_name, vod_status, vod_pic, vod_play_url, vod_time FROM {$vodTable} WHERE vod_name LIKE :kw ORDER BY vod_id DESC LIMIT 50");
        $stmt->execute(['kw' => '%' . $searchKeyword . '%']);
        $searchResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $searchError = "检索执行出错: " . $e->getMessage();
    }
}

$phpVersion = PHP_VERSION;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>AppleCMS OPS - MySQL Management</title>
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
.top-right { color: #64748b; font-size: 13px; }
.content { padding: 28px; max-width: 1600px; }
.card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 20px; box-shadow: 0 2px 7px rgba(15, 23, 42, .03); margin-bottom: 18px; }
.card-title { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; }
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
.alert { background: #fff1f2; border: 1px solid #fecdd3; color: #be123c; padding: 13px 15px; border-radius: 9px; margin-bottom: 18px; font-size: 13px; }
.success-box { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; padding: 13px 15px; border-radius: 9px; margin-bottom: 18px; font-size: 13px; }
.btn { background: #2563eb; color: #fff; border: none; padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn:hover { background: #1d4ed8; }
.btn-danger { background: #dc2626; }
.btn-danger:hover { background: #b91c1c; }
.form-control { padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none; }
.form-control:focus { border-color: #2563eb; }
.table-container { width: 100%; overflow-x: auto; margin-top: 10px; }
.data-table { width: 100%; border-collapse: collapse; font-size: 13px; text-align: left; }
.data-table th, .data-table td { padding: 10px 12px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.data-table th { color: #64748b; font-weight: 600; background: #f8fafc; }
.data-table tr:hover { background: #f8fafc; }
</style>
</head>
<body>
<div class="app">
    <aside class="sidebar">
        <div class="logo">AppleCMS OPS<small>Server Management</small></div>
        <div class="menu-title">Overview</div>
        <a class="menu-item" href="index.php?page=dashboard"><span class="menu-icon">⌂</span><span class="menu-text">Dashboard</span></a>
        <div class="menu-title">Services</div>
        <a class="menu-item" href="redis.php"><span class="menu-icon">R</span><span class="menu-text">Redis</span></a>
        <a class="menu-item active" href="mysql.php"><span class="menu-icon">M</span><span class="menu-text">MySQL</span></a>
        <a class="menu-item" href="meilisearch.php"><span class="menu-icon">S</span><span class="menu-text">Meilisearch</span></a>
    </aside>

    <main class="main">
        <header class="topbar">
            <div class="top-title">MySQL Management</div>
            <div class="top-right">PHP <?php echo h($phpVersion); ?></div>
        </header>

        <section class="content">
            <div class="page-header">
                <h1>MySQL Database (<?php echo h($dbPrefix . 'vod'); ?>)</h1>
                <p>AppleCMS 视频表数据检索、单条标题/状态/封面/播放地址维护与全局字段替换</p>
            </div>

            <?php if ($actionError): ?>
                <div class="alert"><?php echo h($actionError); ?></div>
            <?php endif; ?>

            <?php if ($actionMessage): ?>
                <div class="success-box"><?php echo h($actionMessage); ?></div>
            <?php endif; ?>

            <?php if ($searchError): ?>
                <div class="alert"><?php echo h($searchError); ?></div>
            <?php endif; ?>

            <div class="card">
                <div class="card-title">
                    <h3>Video Search & Edit (<?php echo h($dbPrefix . 'vod'); ?> 检索与单条维护)</h3>
                </div>
                <form method="GET" style="display: flex; gap: 10px; margin-bottom: 15px;">
                    <input type="text" name="s" class="form-control" style="flex:1;" placeholder="输入视频标题 (vod_name) 关键词进行查找（例如：破洞）" value="<?php echo h($searchKeyword); ?>">
                    <button type="submit" class="btn">🔍 查找视频</button>
                </form>

                <?php if ($searchKeyword !== ''): ?>
                    <div style="font-size: 13px; color: #64748b; margin-bottom: 10px;">在 <b><?php echo h($dbPrefix . 'vod'); ?></b> 中找到包含 “<b><?php echo h($searchKeyword); ?></b>” 的结果（最多显示50条）：</div>
                    <?php if (empty($searchResults)): ?>
                        <div style="color: #dc2626; font-size: 13px; padding: 10px 0;">未找到相关视频数据。</div>
                    <?php else: ?>
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th style="width: 50px;">ID</th>
                                        <th style="width: 220px;">标题 (vod_name)</th>
                                        <th style="width: 70px;">状态(status)</th>
                                        <th>封面图 (vod_pic)</th>
                                        <th>播放地址 (vod_play_url)</th>
                                        <th style="width: 130px;">操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($searchResults as $item): ?>
                                    <tr>
                                        <form method="POST">
                                            <input type="hidden" name="vod_id" value="<?php echo (int)$item['vod_id']; ?>">
                                            <input type="hidden" name="search_keyword" value="<?php echo h($searchKeyword); ?>">
                                            <td><?php echo (int)$item['vod_id']; ?></td>
                                            <td>
                                                <input type="text" name="vod_name" class="form-control" style="width: 100%; font-size: 12px;" value="<?php echo h($item['vod_name']); ?>" required>
                                            </td>
                                            <td>
                                                <input type="number" name="vod_status" class="form-control" style="width: 60px; font-size: 12px;" value="<?php echo (int)$item['vod_status']; ?>" min="0" max="1" title="0:未审, 1:已审">
                                            </td>
                                            <td>
                                                <input type="text" name="vod_pic" class="form-control" style="width: 100%; font-size: 12px;" value="<?php echo h($item['vod_pic']); ?>">
                                            </td>
                                            <td>
                                                <input type="text" name="vod_play_url" class="form-control" style="width: 100%; font-size: 12px;" value="<?php echo h($item['vod_play_url']); ?>">
                                            </td>
                                            <td>
                                                <div style="display: flex; gap: 4px;">
                                                    <button type="submit" name="action" value="update_vod" class="btn" style="padding: 6px 9px; font-size: 12px;">保存</button>
                                                    <button type="submit" name="action" value="delete_vod" class="btn btn-danger" style="padding: 6px 9px; font-size: 12px;" onclick="return confirm('确定要从 <?php echo h($dbPrefix . 'vod'); ?> 彻底删除该视频吗？');">删除</button>
                                                </div>
                                            </td>
                                        </form>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="card">
                <div class="card-title">
                    <h3>Global Batch Replace (<?php echo h($dbPrefix . 'vod'); ?> 全局字段替换工具)</h3>
                </div>
                <form method="POST" onsubmit="return confirm('⚠️ 警告：该操作将直接修改 <?php echo h($dbPrefix . 'vod'); ?> 表中匹配的所有记录，是否继续？');">
                    <input type="hidden" name="action" value="global_replace">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 15px;">
                        <div>
                            <label style="display:block; font-size:12px; color:#64748b; margin-bottom:5px;">选择目标字段</label>
                            <select name="target_field" class="form-control" style="width: 100%;">
                                <option value="vod_pic">vod_pic (视频封面图地址)</option>
                                <option value="vod_play_url">vod_play_url (视频播放地址)</option>
                                <option value="vod_name">vod_name (视频标题文本)</option>
                            </select>
                        </div>
                        <div>
                            <label style="display:block; font-size:12px; color:#64748b; margin-bottom:5px;">被替换的旧字符串 (Find)</label>
                            <input type="text" name="find_str" class="form-control" style="width: 100%;" required>
                        </div>
                        <div>
                            <label style="display:block; font-size:12px; color:#64748b; margin-bottom:5px;">替换后的新字符串 (Replace)</label>
                            <input type="text" name="replace_str" class="form-control" style="width: 100%;">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-danger">⚡ 执行 <?php echo h($dbPrefix . 'vod'); ?> 全局批量替换</button>
                </form>
            </div>

            <div class="card">
                <div class="card-title">
                    <h3>Connection Status & Tables</h3>
                    <?php echo statusBadge($dbConnected); ?>
                </div>

                <?php if (!$dbConnected): ?>
                    <div class="alert" style="margin-bottom:0;"><?php echo h($dbError); ?></div>
                <?php else: ?>
                    <div class="success-box" style="margin-bottom:15px;">MySQL 数据库连接成功，响应延迟: <b><?php echo h($dbServerInfo['ping']); ?> ms</b></div>
                <?php endif; ?>

                <div class="info-row" style="margin-top: 10px;">
                    <span class="info-label">Host / Port</span>
                    <span class="info-value"><?php echo h($dbHost . ':' . $dbPort); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Database Name</span>
                    <span class="info-value"><?php echo h($dbName); ?></span>
                </div>
                <?php if ($dbConnected): ?>
                <div class="info-row">
                    <span class="info-label">MySQL Version</span>
                    <span class="info-value"><?php echo h($dbServerInfo['version']); ?></span>
                </div>
                <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center;">
                    <div style="font-size: 13px; color: #64748b;">数据表总数：<?php echo count($tableStats); ?> 个</div>
                    <form method="POST" style="margin: 0;" onsubmit="return confirm('确定要对所有数据表执行碎片优化吗？');">
                        <input type="hidden" name="action" value="optimize_tables">
                        <button type="submit" class="btn">🧹 优化全部数据表</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </section>
    </main>
</div>
</body>
</html>
