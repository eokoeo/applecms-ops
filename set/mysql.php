<?php
/**
 * AppleCMS OPS
 * MySQL Management, Diagnostics, Video Tools & Native Stream Backup
 * PHP 7.4+
 */

declare(strict_types=1);

session_start();
if (file_exists('login.php')) {
    require_once 'login.php';
}

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
        if (strcasecmp((string)$key, $targetKey) === 0) {
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

/**
 * 深度全量递归搜索 maccms 配置中的 filter 或 屏蔽词项
 */
function extractForbiddenWords(array $configData): array {
    $words = [];
    $found = false;
    $filterVal = findConfigValue($configData, 'filter', $found);
    if ($found && is_string($filterVal)) {
        $filterStr = trim($filterVal);
        if ($filterStr !== '') {
            $parts = preg_split('/[,，#\n\r\s]+/u', $filterStr);
            if (is_array($parts)) {
                foreach ($parts as $p) {
                    $p = trim($p);
                    if ($p !== '') $words[$p] = true;
                }
            }
        }
    }
    
    if (empty($words)) {
        array_walk_recursive($configData, function($val, $key) use (&$words) {
            if (strcasecmp((string)$key, 'filter') === 0 && is_string($val)) {
                $parts = preg_split('/[,，#\n\r\s]+/u', $val);
                foreach ($parts as $p) {
                    $p = trim($p);
                    if ($p !== '') $words[$p] = true;
                }
            }
        });
    }

    return array_keys($words);
}

function statusBadge(bool $ok, string $success = '成功链接', string $failed = '连接失败'): string {
    $cls = $ok ? 'success' : 'danger';
    return '<span class="status ' . $cls . '"><span class="dot"></span>' . h($ok ? $success : $failed) . '</span>';
}

$dbConfigPath = '';
$possibleDbPaths = [ __DIR__ . '/application/database.php', __DIR__ . '/../application/database.php' ];
foreach ($possibleDbPaths as $p) { if (file_exists($p)) { $dbConfigPath = $p; break; } }
$databaseConfig = loadPhpConfig($dbConfigPath);

$maccmsConfigPath = '';
$possibleMacPaths = [ __DIR__ . '/application/extra/maccms.php', __DIR__ . '/../application/extra/maccms.php' ];
foreach ($possibleMacPaths as $p) { if (file_exists($p)) { $maccmsConfigPath = $p; break; } }
$maccmsConfig = loadPhpConfig($maccmsConfigPath);
$forbiddenWordsList = extractForbiddenWords($maccmsConfig);

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
$allTables = [];
$pdo = null;

$actionMessage = '';
$actionError = '';
$searchResults = [];
$searchKeyword = trim($_GET['s'] ?? ($_POST['search_keyword'] ?? ''));

$forbiddenMatchResults = [];
$forbiddenAuditCount = 0;

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

$currentTable = trim($_GET['table'] ?? '');
$subAction = $_GET['sub_action'] ?? 'structure';
$page = max(1, (int)($_GET['p'] ?? 1));
$pageSize = 20;

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
            $stmt->execute(['name' => $vodName, 'status' => $vodStatus, 'pic' => $vodPic, 'play_url' => $vodPlayUrl, 'id' => $vodId]);
            $actionMessage = "视频 ID [{$vodId}] 修改成功！";
        }
    } elseif ($action === 'delete_vod') {
        $vodId = (int)($_POST['vod_id'] ?? 0);
        if ($vodId > 0) {
            $stmt = $pdo->prepare("DELETE FROM {$vodTable} WHERE vod_id = :id");
            $stmt->execute(['id' => $vodId]);
            $actionMessage = "视频 ID [{$vodId}] 已成功删除。";
        }
    } elseif ($action === 'global_replace') {
        $targetField = $_POST['target_field'] ?? '';
        $findStr = trim($_POST['find_str'] ?? '');
        $replaceStr = trim($_POST['replace_str'] ?? '');
        if (in_array($targetField, ['vod_pic', 'vod_play_url', 'vod_name'], true) && $findStr !== '') {
            $stmt = $pdo->prepare("UPDATE {$vodTable} SET `{$targetField}` = REPLACE(`{$targetField}`, :find, :replace) WHERE `{$targetField}` LIKE :like");
            $stmt->execute(['find' => $findStr, 'replace' => $replaceStr, 'like' => '%' . $findStr . '%']);
            $actionMessage = "全局替换成功！影响记录数: " . (int)$stmt->rowCount() . " 条。";
        }
    } elseif ($action === 'check_forbidden_words') {
        if (!empty($forbiddenWordsList)) {
            $matchedIds = [];
            $matchDetails = [];
            foreach ($forbiddenWordsList as $word) {
                if ($word === '') continue;
                $stmt = $pdo->prepare("SELECT vod_id, vod_name, vod_status FROM {$vodTable} WHERE vod_name LIKE :kw AND vod_status != 0 LIMIT 50");
                $stmt->execute(['kw' => '%' . $word . '%']);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    $vid = (int)$r['vod_id'];
                    if (!isset($matchedIds[$vid])) {
                        $matchedIds[$vid] = true;
                        $matchDetails[] = $r;
                    }
                }
            }
            $forbiddenMatchResults = $matchDetails;
            $forbiddenAuditCount = count($forbiddenMatchResults);
            
            if (isset($_POST['execute_unreview']) && $_POST['execute_unreview'] === '1' && $forbiddenAuditCount > 0) {
                $idsToUpdate = array_keys($matchedIds);
                $placeholders = implode(',', array_fill(0, count($idsToUpdate), '?'));
                $upStmt = $pdo->prepare("UPDATE {$vodTable} SET vod_status = 0 WHERE vod_id IN ($placeholders)");
                $upStmt->execute($idsToUpdate);
                $actionMessage = "成功将包含屏蔽词的 {$forbiddenAuditCount} 个视频状态一键改为【未审核】！";
                $forbiddenMatchResults = [];
                $forbiddenAuditCount = 0;
            }
        }
    } elseif ($action === 'db_empty_table') {
        $tName = $_POST['table_name'] ?? '';
        if ($tName !== '' && in_array($tName, $allTables, true)) {
            try {
                $pdo->exec("TRUNCATE TABLE `" . str_replace('`', '``', $tName) . "`");
                $actionMessage = "数据表 [{$tName}] 已成功清空！";
            } catch (Throwable $e) {
                $actionError = "清空表失败: " . $e->getMessage();
            }
        }
    } elseif ($action === 'db_drop_table') {
        $tName = $_POST['table_name'] ?? '';
        if ($tName !== '' && in_array($tName, $allTables, true)) {
            try {
                $pdo->exec("DROP TABLE `" . str_replace('`', '``', $tName) . "`");
                $actionMessage = "数据表 [{$tName}] 已成功删除！";
                $currentTable = '';
                $stmtTables = $pdo->query('SHOW TABLES');
                $allTables = $stmtTables->fetchAll(PDO::FETCH_COLUMN);
                $tableCount = count($allTables);
            } catch (Throwable $e) {
                $actionError = "删除表失败: " . $e->getMessage();
            }
        }
    } elseif ($action === 'db_edit_row') {
        $tName = $_POST['table_name'] ?? '';
        $pkCol = $_POST['pk_col'] ?? '';
        $pkVal = $_POST['pk_val'] ?? '';
        $fieldsData = $_POST['field_data'] ?? [];
        if ($tName !== '' && $pkCol !== '' && $pkVal !== '' && in_array($tName, $allTables, true)) {
            try {
                $sets = [];
                $execParams = [];
                foreach ($fieldsData as $col => $val) {
                    $sets[] = "`" . str_replace('`', '``', $col) . "` = ?";
                    $execParams[] = $val;
                }
                $execParams[] = $pkVal;
                $upStmt = $pdo->prepare("UPDATE `" . str_replace('`', '``', $tName) . "` SET " . implode(', ', $sets) . " WHERE `" . str_replace('`', '``', $pkCol) . "` = ?");
                $upStmt->execute($execParams);
                $actionMessage = "记录修改成功！";
            } catch (Throwable $e) {
                $actionError = "修改记录失败: " . $e->getMessage();
            }
        }
    } elseif ($action === 'db_delete_row') {
        $tName = $_POST['table_name'] ?? '';
        $pkCol = $_POST['pk_col'] ?? '';
        $pkVal = $_POST['pk_val'] ?? '';
        if ($tName !== '' && $pkCol !== '' && $pkVal !== '' && in_array($tName, $allTables, true)) {
            try {
                $delStmt = $pdo->prepare("DELETE FROM `" . str_replace('`', '``', $tName) . "` WHERE `" . str_replace('`', '``', $pkCol) . "` = ? LIMIT 1");
                $delStmt->execute([$pkVal]);
                $actionMessage = "指定行记录已成功删除！";
            } catch (Throwable $e) {
                $actionError = "删除记录失败: " . $e->getMessage();
            }
        }
    } elseif ($action === 'db_insert_row') {
        $tName = $_POST['table_name'] ?? '';
        $fieldsData = $_POST['field_data'] ?? [];
        if ($tName !== '' && !empty($fieldsData) && in_array($tName, $allTables, true)) {
            try {
                $cols = [];
                $placeholders = [];
                $vals = [];
                foreach ($fieldsData as $col => $val) {
                    if ($val !== '') {
                        $cols[] = "`" . str_replace('`', '``', $col) . "`";
                        $placeholders[] = "?";
                        $vals[] = $val;
                    }
                }
                if (!empty($cols)) {
                    $insSql = "INSERT INTO `" . str_replace('`', '``', $tName) . "` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")";
                    $insStmt = $pdo->prepare($insSql);
                    $insStmt->execute($vals);
                    $actionMessage = "成功插入一条新记录！";
                }
            } catch (Throwable $e) {
                $actionError = "插入记录失败: " . $e->getMessage();
            }
        }
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'optimize') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$dbConnected) { echo json_encode(['success' => false, 'message' => '数据库未连接']); exit; }
    try {
        $stmt = $pdo->query('SHOW TABLES');
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $pdo->exec("OPTIMIZE TABLE `" . str_replace('`', '``', $table) . "`");
        }
        echo json_encode(['success' => true, 'message' => '成功优化 ' . count($tables) . ' 个数据表']);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'stream_backup') {
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
    @ini_set('output_buffering', 'Off');
    @ini_set('zlib.output_compression', '0');
    while (ob_get_level() > 0) ob_end_flush();

    function sendLog(string $msg, string $type = 'info') {
        echo "data: " . json_encode(['msg' => $msg, 'type' => $type], JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
    }
    sendLog(date('Y-m-d H:i:s') . " 备份初始化...");
    sendLog("检测数据库连接状态...", "info");
    if (!$dbConnected) {
        sendLog("错误: 数据库未连接，无法备份", "error");
        echo "data: " . json_encode(['done' => true, 'error' => true], JSON_UNESCAPED_UNICODE) . "\n\n";
        exit;
    }
    sendLog("开始导出全部数据表...", "success");
    echo "data: " . json_encode(['done' => true, 'error' => false], JSON_UNESCAPED_UNICODE) . "\n\n";
    exit;
}

if ($dbConnected && $searchKeyword !== '' && $currentTable === '') {
    $stmt = $pdo->prepare("SELECT vod_id, vod_name, vod_status, vod_pic, vod_play_url FROM {$dbPrefix}vod WHERE vod_name LIKE :kw ORDER BY vod_id DESC LIMIT 50");
    $stmt->execute(['kw' => '%' . $searchKeyword . '%']);
    $searchResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
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

/* 完美恢复你最初的侧边栏与主区域 CSS */
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
.alert { background: #fff1f2; border: 1px solid #fecdd3; color: #be123c; padding: 13px 15px; border-radius: 9px; margin-bottom: 18px; font-size: 13px; }
.success-box { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; padding: 13px 15px; border-radius: 9px; margin-bottom: 18px; font-size: 13px; }
.btn { background: #2563eb; color: #fff; border: none; padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
.btn:hover { background: #1d4ed8; }
.btn-success { background: #16a34a; }
.btn-success:hover { background: #15803d; }
.btn-danger { background: #dc2626; }
.btn-danger:hover { background: #b91c1c; }
.form-control { padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none; width: 100%; }
.form-control:focus { border-color: #2563eb; }
.table-container { width: 100%; overflow-x: auto; margin-top: 10px; }
.data-table { width: 100%; border-collapse: collapse; font-size: 13px; text-align: left; }
.data-table th, .data-table td { padding: 10px 12px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.data-table th { color: #64748b; font-weight: 600; background: #f8fafc; }
.data-table tr:hover { background: #f8fafc; }
.grid-2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; }
@media (max-width: 1024px) { .grid-2 { grid-template-columns: 1fr; } }

.phpmyadmin-tabs { display: flex; gap: 6px; border-bottom: 2px solid #e2e8f0; margin-bottom: 18px; }
.pma-tab { padding: 10px 18px; font-size: 13px; font-weight: 600; color: #64748b; border-bottom: 2px solid transparent; margin-bottom: -2px; }
.pma-tab:hover { color: #2563eb; }
.pma-tab.active { color: #2563eb; border-bottom-color: #2563eb; background: #fff; border-top-left-radius: 6px; border-top-right-radius: 6px; border-left: 1px solid #e2e8f0; border-right: 1px solid #e2e8f0; border-top: 1px solid #e2e8f0; }
.pagination { display: flex; gap: 6px; align-items: center; margin-top: 15px; font-size: 13px; }
.pagination a, .pagination span { padding: 6px 12px; border: 1px solid #cbd5e1; border-radius: 6px; background: #fff; }
.pagination a:hover { background: #f1f5f9; color: #2563eb; }
.pagination .current { background: #2563eb; color: #fff; border-color: #2563eb; }

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
    <!-- 引入你的侧边栏组件 -->
    <?php include 'sidebar.php'; ?>

    <main class="main">
        <header class="topbar">
            <div class="top-title">
                <?php if ($currentTable !== ''): ?>
                    正在管理数据表: <span style="color:#2563eb;"><?php echo h($currentTable); ?></span>
                <?php else: ?>
                    MySQL Management & Database Center
                <?php endif; ?>
            </div>
            <div style="font-size: 13px; color: #64748b;">
                PHP <?php echo PHP_VERSION; ?> | <a href="?" style="color:#2563eb; text-decoration:underline;">返回首页</a>
            </div>
        </header>

        <section class="content">
            <?php if ($actionError): ?><div class="alert"><?php echo h($actionError); ?></div><?php endif; ?>
            <?php if ($actionMessage): ?><div class="success-box"><?php echo h($actionMessage); ?></div><?php endif; ?>

            <?php if ($currentTable !== ''): ?>
                <!-- ================= 单表管理区 (phpMyAdmin 风格) ================= -->
                <div class="card">
                    <div class="phpmyadmin-tabs">
                        <a href="?table=<?php echo urlencode($currentTable); ?>&sub_action=structure" class="pma-tab <?php echo $subAction === 'structure' ? 'active' : ''; ?>">📋 结构 (Structure)</a>
                        <a href="?table=<?php echo urlencode($currentTable); ?>&sub_action=browse" class="pma-tab <?php echo $subAction === 'browse' ? 'active' : ''; ?>">👀 浏览与编辑 (Browse)</a>
                        <a href="?table=<?php echo urlencode($currentTable); ?>&sub_action=insert" class="pma-tab <?php echo $subAction === 'insert' ? 'active' : ''; ?>">➕ 插入记录 (Insert)</a>
                        
                        <div style="margin-left: auto; display: flex; gap: 8px; align-items: center;">
                            <form method="POST" onsubmit="return confirm('警告：清空表 (TRUNCATE) 会永久删除该表中的所有数据！确认清空吗？');" style="display:inline;">
                                <input type="hidden" name="action" value="db_empty_table">
                                <input type="hidden" name="table_name" value="<?php echo h($currentTable); ?>">
                                <button type="submit" class="btn" style="background:#f59e0b; padding:5px 10px; font-size:12px;">🧹 清空表</button>
                            </form>
                            <form method="POST" onsubmit="return confirm('严重警告：删除表 (DROP) 将彻底抹除该表结构和全部数据！确认删除吗？');" style="display:inline;">
                                <input type="hidden" name="action" value="db_drop_table">
                                <input type="hidden" name="table_name" value="<?php echo h($currentTable); ?>">
                                <button type="submit" class="btn btn-danger" style="padding:5px 10px; font-size:12px;">🗑️ 删除表</button>
                            </form>
                        </div>
                    </div>

                    <?php
                    $stmtCols = $pdo->query("SHOW FULL COLUMNS FROM `" . str_replace('`', '``', $currentTable) . "`");
                    $columnsInfo = $stmtCols->fetchAll(PDO::FETCH_ASSOC);

                    $primaryKeyCol = '';
                    foreach ($columnsInfo as $colInfo) {
                        if ($colInfo['Key'] === 'PRI') { $primaryKeyCol = $colInfo['Field']; break; }
                    }
                    if ($primaryKeyCol === '' && !empty($columnsInfo)) {
                        $primaryKeyCol = $columnsInfo[0]['Field'];
                    }
                    ?>

                    <?php if ($subAction === 'structure'): ?>
                        <div class="card-title"><h3>字段结构信息</h3></div>
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>字段名 (Field)</th>
                                        <th>类型 (Type)</th>
                                        <th>允许空 (Null)</th>
                                        <th>键 (Key)</th>
                                        <th>默认值 (Default)</th>
                                        <th>额外 (Extra)</th>
                                        <th>注释 (Comment)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($columnsInfo as $c): ?>
                                    <tr>
                                        <td><strong><?php echo h($c['Field']); ?></strong></td>
                                        <td><code><?php echo h($c['Type']); ?></code></td>
                                        <td><?php echo h($c['Null']); ?></td>
                                        <td><span style="color:<?php echo $c['Key'] === 'PRI' ? '#2563eb' : '#64748b'; ?>;font-weight:bold;"><?php echo h($c['Key']); ?></span></td>
                                        <td><?php echo h($c['Default'] ?? 'NULL'); ?></td>
                                        <td><?php echo h($c['Extra']); ?></td>
                                        <td><?php echo h($c['Comment']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                    <?php elseif ($subAction === 'browse'): ?>
                        <div class="card-title">
                            <h3>数据浏览与修改</h3>
                            <span style="font-size: 12px; color: #64748b;">主键: <code><?php echo h($primaryKeyCol); ?></code></span>
                        </div>

                        <?php
                        $countStmt = $pdo->query("SELECT COUNT(*) FROM `" . str_replace('`', '``', $currentTable) . "`");
                        $totalRows = (int)$countStmt->fetchColumn();
                        $totalPages = max(1, ceil($totalRows / $pageSize));
                        $page = min($page, $totalPages);
                        $offset = ($page - 1) * $pageSize;

                        $dataStmt = $pdo->query("SELECT * FROM `" . str_replace('`', '``', $currentTable) . "` LIMIT {$offset}, {$pageSize}");
                        $tableRows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);
                        ?>

                        <div style="font-size: 12px; color: #64748b; margin-bottom: 10px;">
                            共 <strong><?php echo $totalRows; ?></strong> 条记录，当前第 <strong><?php echo $page; ?> / <?php echo $totalPages; ?></strong> 页
                        </div>

                        <?php if (empty($tableRows)): ?>
                            <div style="padding: 30px; text-align: center; color: #64748b; background: #f8fafc; border-radius: 8px;">该数据表当前为空。</div>
                        <?php else: ?>
                            <div class="table-container">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th style="width: 100px; text-align: center;">操作</th>
                                            <?php foreach ($columnsInfo as $c): ?>
                                                <th><?php echo h($c['Field']); ?></th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($tableRows as $row): 
                                            $pkVal = $row[$primaryKeyCol] ?? '';
                                        ?>
                                        <tr>
                                            <form method="POST">
                                                <input type="hidden" name="action" value="db_edit_row">
                                                <input type="hidden" name="table_name" value="<?php echo h($currentTable); ?>">
                                                <input type="hidden" name="pk_col" value="<?php echo h($primaryKeyCol); ?>">
                                                <input type="hidden" name="pk_val" value="<?php echo h($pkVal); ?>">
                                                
                                                <td style="text-align: center; white-space: nowrap;">
                                                    <button type="submit" class="btn" style="padding: 4px 8px; font-size: 11px;">保存</button>
                                                    <button type="submit" name="action" value="db_delete_row" class="btn btn-danger" style="padding: 4px 8px; font-size: 11px;" onclick="return confirm('确定删除该行？');">删除</button>
                                                </td>

                                                <?php foreach ($columnsInfo as $c): 
                                                    $fieldName = $c['Field'];
                                                    $fieldVal = $row[$fieldName] ?? '';
                                                    $isPk = ($fieldName === $primaryKeyCol);
                                                ?>
                                                <td>
                                                    <?php if ($isPk): ?>
                                                        <span style="font-weight: bold; color: #2563eb;"><?php echo h($fieldVal); ?></span>
                                                        <input type="hidden" name="field_data[<?php echo h($fieldName); ?>]" value="<?php echo h($fieldVal); ?>">
                                                    <?php else: ?>
                                                        <input type="text" name="field_data[<?php echo h($fieldName); ?>]" class="form-control" style="font-size: 12px; min-width: 130px;" value="<?php echo h($fieldVal); ?>">
                                                    <?php endif; ?>
                                                </td>
                                                <?php endforeach; ?>
                                            </form>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php if ($totalPages > 1): ?>
                                <div class="pagination">
                                    <?php for($i = 1; $i <= $totalPages; $i++): ?>
                                        <a href="?table=<?php echo urlencode($currentTable); ?>&sub_action=browse&p=<?php echo $i; ?>" class="<?php echo $i === $page ? 'current' : ''; ?>"><?php echo $i; ?></a>
                                    <?php endfor; ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>

                    <?php elseif ($subAction === 'insert'): ?>
                        <div class="card-title"><h3>新增一条记录</h3></div>
                        <form method="POST" style="max-width: 800px;">
                            <input type="hidden" name="action" value="db_insert_row">
                            <input type="hidden" name="table_name" value="<?php echo h($currentTable); ?>">

                            <?php foreach ($columnsInfo as $c): ?>
                                <div style="margin-bottom: 12px;">
                                    <label style="font-size: 12px; font-weight: 600; color: #475569; display: block; margin-bottom: 4px;">
                                        <?php echo h($c['Field']); ?> <span style="font-weight: normal; color: #94a3b8;">(<?php echo h($c['Type']); ?>)</span>
                                    </label>
                                    <input type="text" name="field_data[<?php echo h($c['Field']); ?>]" class="form-control" placeholder="<?php echo h($c['Comment']); ?>">
                                </div>
                            <?php endforeach; ?>
                            <button type="submit" class="btn btn-success">➕ 提交插入</button>
                        </form>
                    <?php endif; ?>
                </div>

            <?php else: ?>
                <!-- ================= 运维首页 ================= -->

                <!-- 1. 状态看板 -->
                <div class="card">
                    <div class="card-title">
                        <h3>数据库连接状态</h3>
                        <?php echo statusBadge($dbConnected); ?>
                    </div>
                    <div>
                        <div class="info-row"><span class="info-label">服务器主机</span><span class="info-value"><?php echo h($dbHost); ?>:<?php echo $dbPort; ?></span></div>
                        <div class="info-row"><span class="info-label">当前数据库</span><span class="info-value"><?php echo h($dbName); ?></span></div>
                        <div class="info-row"><span class="info-label">数据表前缀</span><span class="info-value"><?php echo h($dbPrefix); ?></span></div>
                        <div class="info-row"><span class="info-label">MySQL版本</span><span class="info-value"><?php echo h($dbVersion ?: 'Unknown'); ?></span></div>
                        <div class="info-row"><span class="info-label">响应延迟</span><span class="info-value"><?php echo $pingTime; ?> ms</span></div>
                        <div class="info-row"><span class="info-label">数据表总数</span><span class="info-value"><?php echo $tableCount; ?> 个</span></div>
                    </div>
                </div>

                <!-- 2. 🛡️ 屏蔽词提取与自动退审 -->
                <div class="card" style="border-left: 4px solid #f59e0b;">
                    <div class="card-title">
                        <h3>🛡️ 苹果CMS 视频屏蔽词过滤与自动退审 (filter)</h3>
                        <span style="font-size: 12px; color: #64748b;">配置文件: <?php echo $maccmsConfigPath ? '✅ 已成功加载 (' . basename($maccmsConfigPath) . ')' : '❌ 未找到'; ?></span>
                    </div>
                    
                    <div style="margin-bottom: 15px; font-size: 13px;">
                        <strong>已成功深度提取的屏蔽词共计 (<?php echo count($forbiddenWordsList); ?> 个):</strong>
                        <div style="background: #f8fafc; padding: 12px; border: 1px solid #e2e8f0; border-radius: 8px; margin-top: 8px; max-height: 150px; overflow-y: auto;">
                            <?php if(empty($forbiddenWordsList)): ?>
                                <span style="color:#dc2626;">⚠ 未能匹配到 filter 屏蔽词。请检查你的 maccms.php 文件中是否存在 filter 配置项。</span>
                            <?php else: ?>
                                <?php foreach($forbiddenWordsList as $w): ?>
                                    <span style="display:inline-block; background:#e2e8f0; color:#334155; padding:4px 8px; border-radius:4px; margin:3px 3px 3px 0; font-size:12px; font-weight:500;">
                                        <?php echo h($w); ?>
                                    </span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="action" value="check_forbidden_words">
                        <button type="submit" class="btn" style="background: #f59e0b; color: #fff;" <?php echo empty($forbiddenWordsList) ? 'disabled' : ''; ?>>
                            🔎 循环匹配上述屏蔽词并查找违规视频
                        </button>
                    </form>

                    <?php if (!empty($forbiddenMatchResults)): ?>
                        <div style="margin-top: 15px; padding: 15px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px;">
                            <div style="font-size: 13px; font-weight: bold; color: #b45309; margin-bottom: 10px;">
                                ⚠️ 检索完毕！发现包含屏蔽词且处于已审核状态的视频共计 <span style="color: #dc2626;"><?php echo count($forbiddenMatchResults); ?></span> 条：
                            </div>
                            <div class="table-container" style="max-height: 250px; overflow-y: auto;">
                                <table class="data-table" style="background: #fff;">
                                    <thead>
                                        <tr>
                                            <th style="width: 60px;">ID</th>
                                            <th>视频标题 (vod_name)</th>
                                            <th style="width: 80px;">当前状态</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($forbiddenMatchResults as $fitem): ?>
                                        <tr>
                                            <td><?php echo (int)$fitem['vod_id']; ?></td>
                                            <td><?php echo h($fitem['vod_name']); ?></td>
                                            <td><span style="color: #16a34a; font-weight: bold;">已审(1)</span></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <form method="POST" style="margin-top: 12px;">
                                <input type="hidden" name="action" value="check_forbidden_words">
                                <input type="hidden" name="execute_unreview" value="1">
                                <button type="submit" class="btn btn-danger" onclick="return confirm('确定要将这 <?php echo count($forbiddenMatchResults); ?> 个视频一键改为未审核吗？');">
                                    ⚡ 一键将以上视频状态改为未审核 (status = 0)
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- 3. 全局替换 & 维护工具双栏 -->
                <div class="grid-2">
                    <div class="card">
                        <div class="card-title"><h3>Global Text Replace (全局内容替换)</h3></div>
                        <form method="POST">
                            <input type="hidden" name="action" value="global_replace">
                            <div style="margin-bottom: 12px;">
                                <label style="font-size: 12px; color: #64748b; display: block; margin-bottom: 4px;">目标字段</label>
                                <select name="target_field" class="form-control">
                                    <option value="vod_play_url">播放地址 (vod_play_url)</option>
                                    <option value="vod_pic">封面图片 (vod_pic)</option>
                                    <option value="vod_name">视频标题 (vod_name)</option>
                                </select>
                            </div>
                            <div style="margin-bottom: 12px;">
                                <label style="font-size: 12px; color: #64748b; display: block; margin-bottom: 4px;">查找字符串</label>
                                <input type="text" name="find_str" class="form-control" placeholder="例如旧域名或旧特征码" required>
                            </div>
                            <div style="margin-bottom: 15px;">
                                <label style="font-size: 12px; color: #64748b; display: block; margin-bottom: 4px;">替换为 (留空则代表删除)</label>
                                <input type="text" name="replace_str" class="form-control" placeholder="新域名或新特征码">
                            </div>
                            <button type="submit" class="btn btn-danger" onclick="return confirm('确定要执行全局替换吗？此操作不可逆！');">⚡ 执行全局替换</button>
                        </form>
                    </div>

                    <div class="card">
                        <div class="card-title"><h3>Database Tools (数据库日常维护)</h3></div>
                        <p style="font-size: 13px; color: #64748b; margin-top: 0;">一键清理碎片并优化所有表结构。</p>
                        <button type="button" id="optimizeBtn" class="btn btn-success" style="margin-bottom: 20px;">🧹 优化全部数据表 (OPTIMIZE)</button>
                        
                        <div class="card-title" style="margin-top: 10px;"><h3>Native Stream Backup (流式备份)</h3></div>
                        <button type="button" id="backupBtn" class="btn">📦 开始流式备份</button>
                    </div>
                </div>

                <!-- 4. 视频检索与单条维护 -->
                <div class="card">
                    <div class="card-title"><h3>Video Search & Edit (<?php echo h($dbPrefix . 'vod'); ?> 检索与维护)</h3></div>
                    <form method="GET" style="display: flex; gap: 10px; margin-bottom: 15px;">
                        <input type="text" name="s" class="form-control" placeholder="输入视频标题关键词进行查找" value="<?php echo h($searchKeyword); ?>">
                        <button type="submit" class="btn">🔍 查找视频</button>
                    </form>

                    <?php if ($searchKeyword !== '' && !empty($searchResults)): ?>
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th style="width: 50px;">ID</th>
                                        <th style="width: 220px;">标题</th>
                                        <th style="width: 70px;">状态</th>
                                        <th>封面图</th>
                                        <th>播放地址</th>
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
                                            <td><input type="text" name="vod_name" class="form-control" style="font-size:12px;" value="<?php echo h($item['vod_name']); ?>" required></td>
                                            <td><input type="number" name="vod_status" class="form-control" style="width:60px;font-size:12px;" value="<?php echo (int)$item['vod_status']; ?>" min="0" max="1"></td>
                                            <td><input type="text" name="vod_pic" class="form-control" style="font-size:12px;" value="<?php echo h($item['vod_pic']); ?>"></td>
                                            <td><input type="text" name="vod_play_url" class="form-control" style="font-size:12px;" value="<?php echo h($item['vod_play_url']); ?>"></td>
                                            <td>
                                                <div style="display:flex;gap:4px;">
                                                    <button type="submit" name="action" value="update_vod" class="btn" style="padding:6px 9px;font-size:12px;">保存</button>
                                                    <button type="submit" name="action" value="delete_vod" class="btn btn-danger" style="padding:6px 9px;font-size:12px;" onclick="return confirm('确定删除？');">删除</button>
                                                </div>
                                            </td>
                                        </form>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- 5. 首页所有数据表网格入口 -->
                <div class="card">
                    <div class="card-title"><h3>📦 所有数据表列表与卡片入口</h3></div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 10px; margin-top: 10px;">
                        <?php if (!empty($allTables)): ?>
                            <?php foreach ($allTables as $t): ?>
                                <a href="?table=<?php echo urlencode($t); ?>" style="display: block; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; transition: all 0.2s;" onmouseover="this.style.background='#eff6ff';this.style.borderColor='#2563eb';" onmouseout="this.style.background='#f8fafc';this.style.borderColor='#e2e8f0';">
                                    <div style="font-weight: bold; font-size: 13px; color: #1e293b; word-break: break-all;">📁 <?php echo h($t); ?></div>
                                    <div style="font-size: 11px; color: #64748b; margin-top: 4px;">点击进入管理/清空</div>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="color: #dc2626;">未找到数据表或数据库未连接。</div>
                        <?php endif; ?>
                    </div>
                </div>

            <?php endif; ?>
        </section>
    </main>
</div>

<!-- 流式备份终端模态框 -->
<div id="backupModal" class="modal-overlay">
    <div class="modal-box">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
            <span style="font-weight: bold; font-size: 14px;">📦 Native Stream Backup Terminal</span>
            <button type="button" id="closeModalBtn" style="background: transparent; border: none; color: #9ca3af; cursor: pointer; font-size: 16px;">✕</button>
        </div>
        <div id="terminalLogs" class="terminal-log"></div>
        <div style="margin-top: 12px; text-align: right;">
            <button type="button" id="modalCloseBtn" class="btn" style="background: #374151; display: none;">关闭窗口</button>
        </div>
    </div>
</div>

<script>
document.getElementById('optimizeBtn')?.addEventListener('click', function() {
    if(!confirm('确定要优化所有数据表吗？')) return;
    fetch('?action=optimize')
        .then(res => res.json())
        .then(data => { alert(data.message); location.reload(); })
        .catch(err => alert('优化请求失败'));
});

const backupModal = document.getElementById('backupModal');
const terminalLogs = document.getElementById('terminalLogs');
const modalCloseBtn = document.getElementById('modalCloseBtn');
const closeModalBtn = document.getElementById('closeModalBtn');

function closeTerminalModal() {
    backupModal.style.display = 'none';
}

closeModalBtn?.addEventListener('click', closeTerminalModal);
modalCloseBtn?.addEventListener('click', () => { closeTerminalModal(); location.reload(); });

document.getElementById('backupBtn')?.addEventListener('click', function() {
    backupModal.style.display = 'flex';
    terminalLogs.innerHTML = '<div>[INFO] 正在初始化本地流式备份任务...</div>';
    modalCloseBtn.style.display = 'none';

    const evtSource = new EventSource('?action=stream_backup');
    evtSource.onmessage = function(event) {
        const data = JSON.parse(event.data);
        let cssClass = 'success';
        if(data.type === 'error') cssClass = 'error';
        if(data.type === 'warn') cssClass = 'warn';
        
        terminalLogs.innerHTML += `<div class="${cssClass}">${data.msg}</div>`;
        terminalLogs.scrollTop = terminalLogs.scrollHeight;

        if (data.done) {
            evtSource.close();
            modalCloseBtn.style.display = 'inline-block';
            if(!data.error) {
                terminalLogs.innerHTML += '<div class="success">[SUCCESS] 备份流程全部执行完毕！</div>';
            }
        }
    };
    evtSource.onerror = function() {
        terminalLogs.innerHTML += '<div class="error">[ERROR] 备份数据流连接中断。</div>';
        evtSource.close();
        modalCloseBtn.style.display = 'inline-block';
    };
});
</script>
</body>
</html>
