<?php
/**
 * AppleCMS OPS
 * MySQL Management, Diagnostics, Video Tools & Native Stream Backup
 *
 * PHP 7.4+
 */

declare(strict_types=1);

session_start();
require_once 'login.php';

@ini_set('max_execution_time', '0');
@ini_set('memory_limit', '1024M');

 

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

$dbHost = findConfigValue($databaseConfig, 'hostname', $dbHostFound) ?: '1Panel-mysql-FuPN';
$dbName = findConfigValue($databaseConfig, 'database', $dbNameFound) ?: 'ajavrom';
$dbUser = findConfigValue($databaseConfig, 'username', $dbUserFound) ?: 'root';
$dbPassword = findConfigValue($databaseConfig, 'password', $dbPasswordFound) ?: '';
$dbPort = (int)(findConfigValue($databaseConfig, 'hostport', $dbPortFound) ?: 3306);
$dbPrefix = findConfigValue($databaseConfig, 'prefix', $dbPrefixFound) ?: 'mac_';

$dbConnected = false;
$dbError = '';
$dbVersion = '';
$pingTime = 0;
$tableCount = 0;
$pdo = null;

$actionMessage = '';
$actionError = '';
$searchResults = [];
$searchError = '';
$searchKeyword = trim($_GET['s'] ?? ($_POST['search_keyword'] ?? ''));

try {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName);
    $timeStart = microtime(true);
    $pdo = new PDO($dsn, (string)$dbUser, (string)$dbPassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 3,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
    ]);
    $pingTime = round((microtime(true) - $timeStart) * 1000, 2);
    $dbConnected = true;

    $dbVersion = $pdo->query('SELECT VERSION()')->fetchColumn();
    $stmtTables = $pdo->query('SHOW TABLES');
    $allTables = $stmtTables->fetchAll(PDO::FETCH_COLUMN);
    $tableCount = count($allTables);
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

// -------------------------------------------------------------------------
// 纯 PHP 高性能主键游标（Keyset Pagination）流式日志备份（带精细进度回显）
// -------------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'stream_backup') {
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');

    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
    }
    @ini_set('output_buffering', 'Off');
    @ini_set('zlib.output_compression', '0');
    while (ob_get_level() > 0) {
        ob_end_flush();
    }

    function sendLog(string $msg, string $type = 'info') {
        echo "data: " . json_encode(['msg' => $msg, 'type' => $type], JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
    }

    $timeStr = date('Y-m-d H:i:s');
    sendLog("{$timeStr} 备份 [mysql - {$dbName}] 任务开始 [START]");

    try {
        if (!class_exists('ZipArchive')) {
            throw new Exception("PHP ZipArchive 扩展未安装");
        }

        $stmt = $pdo->query('SHOW TABLES');
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $totalTables = count($tables);

        $zipName = ($dbName ?: 'database') . '_' . date('Y-m-d_H-i-s') . '.zip';
        $localTmpZip = __DIR__ . '/backup_' . uniqid() . '.zip';
        $localSqlFile = __DIR__ . '/db_' . uniqid() . '.sql';

        sendLog("正在初始化高性能主键游标导出引擎...");
        $fp = fopen($localSqlFile, 'w');
        if (!$fp) {
            throw new Exception("无法创建临时 SQL 文件");
        }

        fwrite($fp, "-- AppleCMS OPS Backup for {$dbName}\n");
        fwrite($fp, "-- Date: " . date('Y-m-d H:i:s') . "\n\n");
        fwrite($fp, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        foreach ($tables as $i => $table) {
            $num = $i + 1;
            sendLog("正在打包表 [ {$num}/{$totalTables} ]: `{$table}` ...");

            // 写入表结构
            $createStmt = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
            fwrite($fp, "DROP TABLE IF EXISTS `{$table}`;\n");
            fwrite($fp, $createStmt['Create Table'] . ";\n\n");

            // 查找表的主键或唯一索引作为游标字段
            $pkCol = '';
            $pkStmt = $pdo->query("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'");
            $pkRow = $pkStmt->fetch(PDO::FETCH_ASSOC);
            if ($pkRow && isset($pkRow['Column_name'])) {
                $pkCol = $pkRow['Column_name'];
            }

            $batchSize = 2000;
            $rowCountTotal = 0;
            if ($pkCol) {
                $lastPk = null;
                while (true) {
                    if ($lastPk === null) {
                        $rowStmt = $pdo->query("SELECT * FROM `{$table}` ORDER BY `{$pkCol}` ASC LIMIT {$batchSize}");
                    } else {
                        $stmtPrep = $pdo->prepare("SELECT * FROM `{$table}` WHERE `{$pkCol}` > ? ORDER BY `{$pkCol}` ASC LIMIT {$batchSize}");
                        $stmtPrep->execute([$lastPk]);
                        $rowStmt = $stmtPrep;
                    }
                    $rows = $rowStmt->fetchAll(PDO::FETCH_ASSOC);
                    if (empty($rows)) {
                        break;
                    }

                    foreach ($rows as $row) {
                        $lastPk = $row[$pkCol];
                        $rowCountTotal++;
                        $fields = array_keys($row);
                        $values = array_values($row);
                        $escFields = array_map(function($f){ return '`' . $f . '`'; }, $fields);
                        $escValues = array_map(function($v) use ($pdo) {
                            if ($v === null) return 'NULL';
                            return $pdo->quote((string)$v);
                        }, $values);

                        $insertLine = "INSERT INTO `{$table}` (" . implode(', ', $escFields) . ") VALUES (" . implode(', ', $escValues) . ");\n";
                        fwrite($fp, $insertLine);
                    }

                    // 如果数据量较大，每批次汇报一次进度，防止浏览器端因长时间无输出产生焦虑
                    if ($rowCountTotal > 5000 && ($rowCountTotal % 10000 === 0)) {
                        sendLog("  -> `{$table}` 已导出 {$rowCountTotal} 行数据...", "warn");
                    }

                    if (count($rows) < $batchSize) {
                        break;
                    }
                }
            } else {
                $rowStmt = $pdo->query("SELECT * FROM `{$table}`");
                while ($row = $rowStmt->fetch(PDO::FETCH_ASSOC)) {
                    $rowCountTotal++;
                    $fields = array_keys($row);
                    $values = array_values($row);
                    $escFields = array_map(function($f){ return '`' . $f . '`'; }, $fields);
                    $escValues = array_map(function($v) use ($pdo) {
                        if ($v === null) return 'NULL';
                        return $pdo->quote((string)$v);
                    }, $values);

                    $insertLine = "INSERT INTO `{$table}` (" . implode(', ', $escFields) . ") VALUES (" . implode(', ', $escValues) . ");\n";
                    fwrite($fp, $insertLine);
                }
            }
            fwrite($fp, "\n");
        }

        fwrite($fp, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fp);

        $sqlFileSize = filesize($localSqlFile);
        $sqlFileMB = round($sqlFileSize / 1024 / 1024, 2);
        sendLog("数据表全部转储完毕 (SQL 大小约 {$sqlFileMB} MB)，正在创建 ZIP 压缩包...");

        $zip = new ZipArchive();
        if ($zip->open($localTmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
            sendLog("正在向 ZIP 中写入数据库文件...");
            $zip->addFile($localSqlFile, "{$dbName}.sql");
            sendLog("正在执行最终压缩归档...");
            $zip->close();
        } else {
            throw new Exception("无法创建 ZIP 压缩包");
        }

        @unlink($localSqlFile);
        $_SESSION['ready_download_zip'] = $localTmpZip;
        $_SESSION['ready_download_name'] = $zipName;

        $endStr = date('Y-m-d H:i:s');
        sendLog("{$endStr} 备份 [mysql - {$dbName}] 成功 [TASK-END]", "success");
        echo "data: " . json_encode(['done' => true, 'file' => $zipName], JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();

    } catch (Throwable $e) {
        if (isset($fp) && is_resource($fp)) {
            fclose($fp);
        }
        @unlink($localSqlFile);
        sendLog("备份失败: " . $e->getMessage(), "error");
        echo "data: " . json_encode(['done' => true, 'error' => true], JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
    }
    exit;
}

// 触发最终下载
if (isset($_GET['action']) && $_GET['action'] === 'download_ready_zip') {
    $path = $_SESSION['ready_download_zip'] ?? '';
    $name = $_SESSION['ready_download_name'] ?? 'database.zip';
    if ($path && file_exists($path)) {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        @unlink($path);
        unset($_SESSION['ready_download_zip'], $_SESSION['ready_download_name']);
        exit;
    }
    die('文件已失效');
}

// 处理 AJAX 请求：优化表
if (isset($_GET['action']) && $_GET['action'] === 'optimize') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$dbConnected) {
        echo json_encode(['success' => false, 'message' => '数据库未连接']);
        exit;
    }
    try {
        $stmt = $pdo->query('SHOW TABLES');
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $escapedTable = '`' . str_replace('`', '``', $table) . '`';
            $pdo->exec("OPTIMIZE TABLE {$escapedTable}");
        }
        echo json_encode(['success' => true, 'message' => '成功优化 ' . count($tables) . ' 个数据表']);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// 处理 POST 业务操作
if ($dbConnected && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $vodTable = $dbPrefix . 'vod';

    if ($action === 'update_vod') {
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
.btn { background: #2563eb; color: #fff; border: none; padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
.btn:hover { background: #1d4ed8; }
.btn-success { background: #16a34a; }
.btn-success:hover { background: #15803d; }
.btn-danger { background: #dc2626; }
.btn-danger:hover { background: #b91c1c; }
.form-control { padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none; }
.form-control:focus { border-color: #2563eb; }
.table-container { width: 100%; overflow-x: auto; margin-top: 10px; }
.data-table { width: 100%; border-collapse: collapse; font-size: 13px; text-align: left; }
.data-table th, .data-table td { padding: 10px 12px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.data-table th { color: #64748b; font-weight: 600; background: #f8fafc; }
.data-table tr:hover { background: #f8fafc; }
.actions-bar { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }

/* 1Panel 风格控制台弹窗样式 */
.modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); display: none; align-items: center; justify-content: center; z-index: 999; }
.modal-box { background: #111827; color: #f3f4f6; padding: 20px; border-radius: 12px; width: 600px; max-width: 90%; box-shadow: 0 10px 25px rgba(0,0,0,0.3); font-family: monospace; }
.terminal-log { background: #030712; color: #22c55e; padding: 15px; border-radius: 8px; height: 260px; overflow-y: auto; font-size: 12px; line-height: 1.5; border: 1px solid #1f2937; }
.terminal-log div { margin-bottom: 4px; word-break: break-all; }
.terminal-log .error { color: #ef4444; }
.terminal-log .warn { color: #f59e0b; }
.terminal-log .success { color: #38bdf8; font-weight: bold; }
</style>
</head>
<body>
<div class="app">
    <aside class="sidebar">
        <div class="logo">AppleCMS OPS<small>Server Management</small></div>
        <div class="menu-title">Overview</div>
        <a class="menu-item" href="index.php"><span class="menu-icon">⌂</span><span class="menu-text">Dashboard</span></a>
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
                <p>AppleCMS 视频表数据检索、单条标题/状态/封面/播放地址维护、全局字段替换与 1Panel 实时流式备份</p>
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

            <!-- 1. 视频检索与单条维护区块 -->
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

            <!-- 2. 全局字段替换区块 -->
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

            <!-- 3. 数据库连接状态与 ZIP 下载区块 -->
            <div class="card">
                <div class="card-title">
                    <h3>Connection Status & Tables</h3>
                    <?php echo statusBadge($dbConnected); ?>
                </div>

                <div class="info-row">
                    <span class="info-label">连接状态描述</span>
                    <span class="info-value">
                        <?php if ($dbConnected): ?>
                            MySQL 数据库连接成功，响应延迟: <span style="color:#16a34a;"><?php echo $pingTime; ?> ms</span>
                        <?php else: ?>
                            <span style="color:#dc2626;"><?php echo h($dbError); ?></span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="info-row"><span class="info-label">Host / Port</span><span class="info-value"><?php echo h($dbHost . ':' . $dbPort); ?></span></div>
                <div class="info-row"><span class="info-label">Database Name</span><span class="info-value"><?php echo h($dbName); ?></span></div>
                <div class="info-row"><span class="info-label">Username</span><span class="info-value"><?php echo h($dbUser); ?></span></div>
                <div class="info-row"><span class="info-label">MySQL Version</span><span class="info-value"><?php echo h($dbVersion ?: 'Unknown'); ?></span></div>
                <div class="info-row"><span class="info-label">数据表总数</span><span class="info-value"><?php echo (int)$tableCount; ?> 个</span></div>

                <?php if ($dbConnected): ?>
                <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #f1f5f9;" class="actions-bar">
                    <button class="btn" onclick="optimizeTables()">🧹 优化全部数据表</button>
                    <button class="btn btn-success" onclick="start1PanelBackup()">📦 1Panel 实时流式备份下载 (.zip)</button>
                </div>
                <?php endif; ?>
            </div>
        </section>
    </main>
</div>

<!-- 1Panel 风格终端日志弹窗 -->
<div id="backupModal" class="modal-overlay">
    <div class="modal-box">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
            <h3 style="margin: 0; font-size: 15px; color: #fff;">数据库备份任务实时日志</h3>
            <button id="closeModalBtn" onclick="closeBackupModal()" style="background: transparent; border: none; color: #9ca3af; font-size: 16px; cursor: pointer; display: none;">✕</button>
        </div>
        <div id="terminalLog" class="terminal-log">
            <div>等待发起备份任务...</div>
        </div>
        <div id="modalFooter" style="margin-top: 15px; text-align: right; display: none;">
            <button class="btn btn-success" onclick="downloadBackupFile()" style="font-size: 12px;">⬇️ 下载打包好的压缩包</button>
        </div>
    </div>
</div>

<script>
function optimizeTables() {
    if (!confirm('确定要对全部数据表执行 OPTIMIZE TABLE 优化吗？')) return;
    fetch('mysql.php?action=optimize')
        .then(res => res.json())
        .then(data => {
            alert(data.message);
            if (data.success) {
                location.reload();
            }
        })
        .catch(err => {
            alert('优化请求失败: ' + err);
        });
}

let downloadUrl = '';

function start1PanelBackup() {
    const modal = document.getElementById('backupModal');
    const logBox = document.getElementById('terminalLog');
    const footer = document.getElementById('modalFooter');
    const closeBtn = document.getElementById('closeModalBtn');

    modal.style.display = 'flex';
    const nowStr = new Date().toLocaleString();
    logBox.innerHTML = `<div>${nowStr} 备份 [mysql - ajavrom] 任务开始 [START]</div>`;
    footer.style.display = 'none';
    closeBtn.style.display = 'none';

    // 使用 Server-Sent Events (EventSource) 接收实时流式日志
    const eventSource = new EventSource('mysql.php?action=stream_backup');

    eventSource.onmessage = function(event) {
        const data = JSON.parse(event.data);
        
        if (data.msg) {
            let cssClass = '';
            if (data.type === 'error') cssClass = 'error';
            if (data.type === 'warn') cssClass = 'warn';
            if (data.type === 'success') cssClass = 'success';
            
            logBox.innerHTML += `<div class="${cssClass}">${data.msg}</div>`;
            logBox.scrollTop = logBox.scrollHeight;
        }

        if (data.done) {
            eventSource.close();
            closeBtn.style.display = 'block';
            if (data.error) {
                logBox.innerHTML += `<div class="error">[ERROR] 备份任务异常终止。</div>`;
            } else {
                logBox.innerHTML += `<div class="success">[SUCCESS] 备份压缩包已准备就绪！</div>`;
                footer.style.display = 'block';
                downloadUrl = 'mysql.php?action=download_ready_zip';
            }
        }
    };

    eventSource.onerror = function() {
        eventSource.close();
        logBox.innerHTML += `<div class="error">[ERROR] 连接中断或脚本超时。</div>`;
        closeBtn.style.display = 'block';
    };
}

function closeBackupModal() {
    document.getElementById('backupModal').style.display = 'none';
}

function downloadBackupFile() {
    if (downloadUrl) {
        window.location.href = downloadUrl;
    }
}
</script>
</body>
</html>
