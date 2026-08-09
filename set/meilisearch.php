<?php
/**
 * AppleCMS OPS
 * Meilisearch Management & Diagnostics with Smart Check Tool (ID & Title)
 *
 * PHP 7.4+
 */

declare(strict_types=1);

session_start();
require_once 'login.php';
/*
$APP_ROOT = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$MACCMS_CONFIG = $APP_ROOT . '/application/extra/maccms.php';
$DATABASE_CONFIG = $APP_ROOT . '/application/database.php';
*/
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

function maskSecret($value): string {
    if ($value === null || $value === '') return '';
    $value = (string)$value;
    $len = strlen($value);
    if ($len <= 4) return '****';
    return substr($value, 0, 2) . str_repeat('*', max(4, $len - 4)) . substr($value, -2);
}

function statusBadge(bool $ok, string $success = '成功链接', string $failed = '连接失败'): string {
    $cls = $ok ? 'success' : 'danger';
    return '<span class="status ' . $cls . '"><span class="dot"></span>' . h($ok ? $success : $failed) . '</span>';
}

$maccmsConfig = loadPhpConfig($MACCMS_CONFIG);
$databaseConfig = loadPhpConfig($DATABASE_CONFIG);

/* =========================================================
 * Meilisearch 配置提取
 * ======================================================= */

$meiliConfig = isset($maccmsConfig['meilisearch']) && is_array($maccmsConfig['meilisearch']) ? $maccmsConfig['meilisearch'] : [];
$meiliEnabled = $meiliConfig['enabled'] ?? findConfigValue($maccmsConfig, 'enabled', $meiliEnabledFound);
$meiliHost = $meiliConfig['host'] ?? '';
$meiliIndex = $meiliConfig['index_uid'] ?? '';
$meiliTimeout = $meiliConfig['timeout'] ?? 3;
$meiliApiKey = $meiliConfig['api_key'] ?? '';
$meiliHostFound = $meiliHost !== '';
$meiliIndexFound = $meiliIndex !== '';

/* =========================================================
 * cURL 请求辅助函数
 * ======================================================= */

function meiliRequest(string $host, string $apiKey, string $endpoint, string $method = 'GET', array $data = []) {
    $url = rtrim($host, '/') . '/' . ltrim($endpoint, '/');
    $ch = curl_init();
    $headers = [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json'
    ];
    
    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => $method,
    ];

    if (($method === 'POST' || $method === 'PUT' || $method === 'PATCH') && !empty($data)) {
        $options[CURLOPT_POSTFIELDS] = json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return ['success' => false, 'error' => $error ?: 'cURL Request Failed', 'code' => 0];
    }

    $decoded = json_decode($response, true);
    return [
        'success' => ($httpCode >= 200 && $httpCode < 300),
        'code' => $httpCode,
        'data' => $decoded ?? $response,
        'error' => ($httpCode >= 200 && $httpCode < 300) ? '' : ($decoded['message'] ?? 'HTTP Error ' . $httpCode)
    ];
}

/* =========================================================
 * 核心逻辑：基于总数偏移量的精准增量同步函数
 * ========================================================= */
function executeIncrementalSync($databaseConfig, $meiliHost, $meiliApiKey, $meiliIndex) {
    try {
        $dbHost = findConfigValue($databaseConfig, 'hostname', $dbHostFound);
        $dbName = findConfigValue($databaseConfig, 'database', $dbNameFound);
        $dbUser = findConfigValue($databaseConfig, 'username', $dbUserFound);
        $dbPassword = findConfigValue($databaseConfig, 'password', $dbPasswordFound);
        $dbPort = findConfigValue($databaseConfig, 'hostport', $dbPortFound) ?: 3306;
        $dbPrefix = findConfigValue($databaseConfig, 'prefix', $dbPrefixFound) ?: 'mac_';

        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbHost, (int)$dbPort, $dbName),
            (string)$dbUser,
            (string)$dbPassword,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $table = $dbPrefix . 'vod';

        // 1. 获取 Meilisearch 当前索引里的文档总数
        $statsRes = meiliRequest($meiliHost, $meiliApiKey, "indexes/{$meiliIndex}/stats");
        $remoteCount = $statsRes['success'] ? (int)($statsRes['data']['numberOfDocuments'] ?? 0) : 0;

        // 2. 直接从 MySQL 中跳过已有数量，取后面的新数据（单次最多 2000 条）
        $stmt = $pdo->prepare("SELECT * FROM {$table} ORDER BY vod_id ASC LIMIT 2000 OFFSET :offset");
        $stmt->bindValue(':offset', $remoteCount, PDO::PARAM_INT);
        $stmt->execute();
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($documents)) {
            return ['status' => true, 'message' => '当前已经是最新状态，没有检测到需要新增的数据。'];
        }

        // 3. 将苹果CMS的 vod_id 映射为 Meilisearch 必需的 id 字段
        foreach ($documents as &$doc) {
            if (isset($doc['vod_id'])) {
                $doc['id'] = (int)$doc['vod_id'];
            }
        }
        unset($doc);

        // 4. 推送到 Meilisearch
        $pushRes = meiliRequest($meiliHost, $meiliApiKey, "indexes/{$meiliIndex}/documents", 'POST', $documents);
        
        if ($pushRes['success']) {
            $taskUid = $pushRes['data']['taskUid'] ?? '未知';
            return ['status' => true, 'message' => "精准增量同步触发成功！检测到 " . count($documents) . " 条新数据并已推送。Task UID: {$taskUid}"];
        } else {
            return ['status' => false, 'message' => '推送至 Meilisearch 失败: ' . $pushRes['error']];
        }

    } catch (Throwable $e) {
        return ['status' => false, 'message' => '同步异常: ' . $e->getMessage()];
    }
}

/* =========================================================
 * 初始化与状态检测
 * ======================================================= */

$meiliConnected = false;
$meiliError = '';
$indexStats = null;
$actionMessage = '';
$actionError = '';
$checkResult = null;

if (!$meiliHostFound) {
    $meiliError = '未读取到 Meilisearch 配置';
} elseif (!in_array($meiliEnabled, ['1', 1, true], true)) {
    $meiliError = 'Meilisearch 未启用';
} else {
    $healthCheck = meiliRequest($meiliHost, $meiliApiKey, 'health');
    if ($healthCheck['success']) {
        $meiliConnected = true;
        if ($meiliIndexFound) {
            $statsCheck = meiliRequest($meiliHost, $meiliApiKey, "indexes/{$meiliIndex}/stats");
            if ($statsCheck['success']) {
                $indexStats = $statsCheck['data'];
            }
        }
    } else {
        $meiliError = $healthCheck['error'];
    }
}

/* =========================================================
 * 响应 ?add 参数 或 POST 提交动作
 * ======================================================= */

if (isset($_GET['add'])) {
    if (!$meiliConnected) {
        die("同步失败: Meilisearch 服务未连接或配置错误。");
    }
    $syncResult = executeIncrementalSync($databaseConfig, $meiliHost, $meiliApiKey, $meiliIndex);
    header('Content-Type: text/html; charset=utf-8');
    echo '<div style="margin:20px;font-family:sans-serif;padding:20px;border-radius:8px;background:' . ($syncResult['status'] ? '#f0fdf4;color:#15803d;border:1px solid #bbf7d0' : '#fff1f2;color:#be123c;border:1px solid #fecdd3') . ';">';
    echo '<h2>' . ($syncResult['status'] ? '✅ 增量同步执行成功' : '❌ 增量同步执行失败') . '</h2>';
    echo '<p>' . h($syncResult['message']) . '</p>';
    echo '<p><a href="meilisearch.php">返回 Meilisearch 管理面板</a></p>';
    echo '</div>';
    exit;
}

if ($meiliConnected && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'incremental_sync') {
        $syncResult = executeIncrementalSync($databaseConfig, $meiliHost, $meiliApiKey, $meiliIndex);
        if ($syncResult['status']) {
            $actionMessage = $syncResult['message'];
            $statsCheck = meiliRequest($meiliHost, $meiliApiKey, "indexes/{$meiliIndex}/stats");
            if ($statsCheck['success']) $indexStats = $statsCheck['data'];
        } else {
            $actionError = $syncResult['message'];
        }
    } elseif ($action === 'smart_check') {
        $keyword = trim($_POST['check_keyword'] ?? '');
        if ($keyword !== '') {
            // 判断输入的是否为纯数字（当作 ID 精确查）
            if (ctype_digit($keyword)) {
                $checkRes = meiliRequest($meiliHost, $meiliApiKey, "indexes/{$meiliIndex}/documents/{$keyword}");
                if ($checkRes['success']) {
                    $checkResult = $checkRes['data'];
                    $actionMessage = "检测成功：ID [{$keyword}] 已被收录。";
                } else {
                    $checkResult = $checkRes['data'];
                    $actionError = "检测结果：ID [{$keyword}] 尚未被收录 (Document not found)。";
                }
            } else {
                // 输入的是文字/标题（当作关键词搜索查）
                $searchRes = meiliRequest($meiliHost, $meiliApiKey, "indexes/{$meiliIndex}/search", 'POST', [
                    'q' => $keyword,
                    'limit' => 50
                ]);
                if ($searchRes['success']) {
                    $checkResult = $searchRes['data'];
                    $totalHits = $checkResult['estimatedTotalHits'] ?? count($checkResult['hits'] ?? []);
                    $actionMessage = "关键词检索完成，共找到包含 [{$keyword}] 的相关视频约 {$totalHits} 条。";
                } else {
                    $actionError = "检索失败: " . $searchRes['error'];
                }
            }
        } else {
            $actionError = "请输入需要检测的视频 ID 或 标题关键词。";
        }
    }
}

$phpVersion = PHP_VERSION;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>AppleCMS OPS - Meilisearch</title>
<style>
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; min-height: 100%; }
body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "PingFang SC", "Microsoft YaHei", sans-serif;
    background: #f5f7fb; color: #172033;
}
a { color: inherit; text-decoration: none; }
.app { display: flex; min-height: 100vh; }
.sidebar {
    width: 240px; background: #111827; color: #fff; position: fixed; left: 0; top: 0; bottom: 0; padding: 22px 14px; z-index: 10;
}
.logo { font-size: 20px; font-weight: 700; padding: 8px 12px 24px; }
.logo small { display: block; font-size: 11px; font-weight: 400; color: #9ca3af; margin-top: 5px; }
.menu-title { color: #6b7280; font-size: 11px; padding: 15px 12px 7px; text-transform: uppercase; }
.menu-item {
    display: flex; align-items: center; gap: 10px; padding: 11px 12px; margin: 3px 0; border-radius: 9px; color: #cbd5e1; font-size: 14px; transition: .15s;
}
.menu-item:hover { background: #1f2937; color: #fff; }
.menu-item.active { background: #2563eb; color: #fff; }
.menu-icon { width: 22px; text-align: center; }
.main { margin-left: 240px; width: calc(100% - 240px); min-height: 100vh; }
.topbar {
    height: 70px; background: #fff; border-bottom: 1px solid #e5e7eb; display: flex; align-items: center; justify-content: space-between; padding: 0 30px;
}
.top-title { font-size: 19px; font-weight: 700; }
.top-right { color: #64748b; font-size: 13px; }
.content { padding: 28px; max-width: 1500px; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 18px; }
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
.alert { background: #fff1f2; border: 1px solid #fecdd3; color: #be123c; padding: 13px 15px; border-radius: 9px; margin-top: 15px; font-size: 13px; }
.success-box { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; padding: 13px 15px; border-radius: 9px; margin-top: 15px; font-size: 13px; }
.form-group { display: flex; gap: 10px; margin-top: 12px; }
.form-control { flex: 1; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none; }
.form-control:focus { border-color: #2563eb; }
.btn { background: #2563eb; color: #fff; border: none; padding: 9px 18px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; transition: background .15s; }
.btn:hover { background: #1d4ed8; }
.btn-success { background: #16a34a; }
.btn-success:hover { background: #15803d; }
.code-block { background: #0f172a; color: #38bdf8; padding: 14px; border-radius: 8px; font-family: monospace; font-size: 12px; overflow-x: auto; margin-top: 10px; max-height: 300px; }
@media (max-width: 800px) {
    .sidebar { width: 68px; padding: 15px 8px; }
    .logo { font-size: 0; text-align: center; }
    .logo:first-letter { font-size: 20px; }
    .logo small, .menu-title, .menu-text { display: none; }
    .menu-item { justify-content: center; }
    .main { margin-left: 68px; width: calc(100% - 68px); }
    .content { padding: 18px; }
}
</style>
</head>
<body>
<div class="app">
    <?php include 'sidebar.php'; ?>

    <main class="main">
        <header class="topbar">
            <div class="top-title">Meilisearch Management</div>
            <div class="top-right">PHP <?php echo h($phpVersion); ?></div>
        </header>

        <section class="content">
            <div class="page-header">
                <h1>Meilisearch</h1>
                <p>AppleCMS 全文搜索引擎状态、索引统计、增量同步与智能收录检测</p>
            </div>

            <?php if ($actionError): ?>
                <div class="alert" style="margin-bottom: 18px;"><?php echo h($actionError); ?></div>
            <?php endif; ?>

            <?php if ($actionMessage): ?>
                <div class="success-box" style="margin-bottom: 18px;"><?php echo h($actionMessage); ?></div>
            <?php endif; ?>

            <!-- 状态与增量同步 -->
            <div class="card">
                <div class="card-title">
                    <h3>Connection & Sync Management</h3>
                    <?php echo statusBadge($meiliConnected); ?>
                </div>

                <?php if (!$meiliConnected): ?>
                    <div class="alert"><?php echo h($meiliError); ?></div>
                <?php else: ?>
                    <div class="success-box">Meilisearch 服务连接正常，API 鉴权成功。</div>
                <?php endif; ?>

                <div class="info-row" style="margin-top: 15px;">
                    <span class="info-label">Host</span>
                    <span class="info-value"><?php echo h($meiliHost ?: '-'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Index UID</span>
                    <span class="info-value"><?php echo h($meiliIndex ?: '-'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Indexed Documents (当前索引文档数)</span>
                    <span class="info-value"><?php echo isset($indexStats['numberOfDocuments']) ? h($indexStats['numberOfDocuments']) : '0'; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Enabled Status</span>
                    <span class="info-value">
                        <?php echo in_array($meiliEnabled, ['1', 1, true], true) ? '<span class="status success">Enabled</span>' : '<span class="status danger">Disabled</span>'; ?>
                    </span>
                </div>

                <?php if ($meiliConnected): ?>
                <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <div style="font-size: 13px; color: #64748b;">
                        提示：支持通过浏览器直接访问 <code style="background:#f1f5f9;padding:2px 6px;border-radius:4px;color:#2563eb;">?add</code> 触发精准增量。
                    </div>
                    <form method="POST" style="margin: 0;">
                        <input type="hidden" name="action" value="incremental_sync">
                        <button type="submit" class="btn btn-success">⚡ 立即执行精准增量同步</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($meiliConnected && $meiliIndexFound): ?>
            <!-- 智能收录状态与关键词检测工具 -->
            <div class="card">
                <div class="card-title">
                    <h3>Smart Diagnostics (智能收录与标题关键词检测)</h3>
                </div>
                <p style="font-size: 13px; color: #64748b; margin-top: 0;">输入<strong>纯数字 ID</strong>（如 514156）则精准匹配单条文档；输入<strong>视频标题关键词</strong>（如 斗破苍穹）则检索对应的内容列表。</p>
                
                <form method="POST">
                    <input type="hidden" name="action" value="smart_check">
                    <div class="form-group">
                        <input type="text" name="check_keyword" class="form-control" placeholder="输入视频 ID (如 514156) 或 视频标题关键词" value="<?php echo h($_POST['check_keyword'] ?? ''); ?>">
                        <button type="submit" class="btn">立即智能检测</button>
                    </div>
                </form>

                <?php if ($checkResult !== null): ?>
                    <div style="margin-top: 15px; font-size: 13px; font-weight: 600;">检测详情 (JSON):</div>
                    <pre class="code-block"><?php echo h(json_encode($checkResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </section>
    </main>
</div>
</body>
</html>
