<?php
/**
 * AppleCMS OPS
 * Site Files Manager, Editor, Batch Replace & Stream Archive with Exclusions
 *
 * PHP 7.4+
 */

declare(strict_types=1);

session_start();

// 初始化修改计数器
if (!isset($_SESSION['edit_counter'])) {
    $_SESSION['edit_counter'] = 0;
}

@ini_set('max_execution_time', '300');
@ini_set('memory_limit', '1024M');

$APP_ROOT = realpath(__DIR__ . '/..') ?: dirname(__DIR__);

// 获取当前请求的相对路径并规范化
$relativePath = trim($_GET['path'] ?? '', '/\\');
$currentDir = $APP_ROOT;
if ($relativePath !== '') {
    $safeRelPath = str_replace(['..\\', '../', '..'], '', $relativePath);
    $resolvedPath = realpath($APP_ROOT . '/' . $safeRelPath);
    if ($resolvedPath && strpos($resolvedPath, $APP_ROOT) === 0 && is_dir($resolvedPath)) {
        $currentDir = $resolvedPath;
        $relativePath = trim(str_replace($APP_ROOT, '', $currentDir), '/\\');
    } else {
        $relativePath = '';
    }
}

$actionMessage = '';
$actionError = '';

// 处理文件保存、新建、删除请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $op = $_POST['op'] ?? '';

    if ($op === 'save_file') {
        $targetFile = trim($_POST['file_path'] ?? '');
        $realFile = realpath($targetFile);
        if ($realFile && strpos($realFile, $APP_ROOT) === 0 && is_file($realFile)) {
            $content = $_POST['file_content'] ?? '';
            @chmod($realFile, 0777);
            $res = @file_put_contents($realFile, $content);
            if ($res !== false) {
                @chmod($realFile, 0755); // 保存后自动恢复 0755
                $_SESSION['edit_counter']++; // 仅在真正执行保存时自增
                $countNum = $_SESSION['edit_counter'];
                $actionMessage = "文件 [" . basename($realFile) . "] 保存成功，权限已自动恢复为 0755！ (这是你的第 <b>{$countNum}</b> 次修改)";
            } else {
                $actionError = "文件保存失败。当前文件权限不足，且 PHP 进程非属主无法直接修改。";
            }
        } else {
            $actionError = "目标文件不合法或不存在。";
        }
    } elseif ($op === 'save_as_new') {
        $targetFile = trim($_POST['file_path'] ?? '');
        $newFileName = trim($_POST['new_target_name'] ?? '');
        $content = $_POST['file_content'] ?? '';
        
        $realFile = realpath($targetFile);
        if ($realFile && strpos($realFile, $APP_ROOT) === 0 && is_file($realFile)) {
            $targetDir = dirname($realFile);
            if ($newFileName !== '') {
                $safeNewName = str_replace(['/', '\\', '..'], '', $newFileName);
                $destinationPath = $targetDir . '/' . $safeNewName;
                
                @chmod($targetDir, 0777);

                $tmpFileName = $targetDir . '/.tmp_' . uniqid() . '_' . $safeNewName;
                $writeRes = @file_put_contents($tmpFileName, $content);

                if ($writeRes !== false) {
                    @chmod($tmpFileName, 0777);
                    $renameRes = @rename($tmpFileName, $destinationPath);
                    if ($renameRes) {
                        @chmod($destinationPath, 0755);
                        $_SESSION['edit_counter']++; // 仅在另存/覆盖成功时自增
                        $countNum = $_SESSION['edit_counter'];
                        $actionMessage = "操作成功！文件 [{$safeNewName}] 已生成/覆盖，权限已设为 0755。 (这是你的第 <b>{$countNum}</b> 次修改)";
                    } else {
                        @unlink($tmpFileName);
                        $actionError = "覆盖失败：目标父目录无写权限，或者底层内核拒绝替换。";
                    }
                } else {
                    $actionError = "生成临时文件失败，目标目录无写权限。";
                }
            } else {
                $actionError = "请输入有效的文件名。";
            }
        } else {
            $actionError = "源文件上下文失效。";
        }
    } elseif ($op === 'create_file') {
        $fileName = trim($_POST['new_file_name'] ?? '');
        if ($fileName !== '') {
            $safeFileName = str_replace(['/', '\\', '..'], '', $fileName);
            $newFilePath = $currentDir . '/' . $safeFileName;
            if (!file_exists($newFilePath)) {
                @chmod($currentDir, 0777);
                if (@file_put_contents($newFilePath, "<?php\n// Created by AppleCMS OPS\n") !== false) {
                    @chmod($newFilePath, 0755);
                    $actionMessage = "文件 [{$safeFileName}] 创建成功（权限已设为 0755）！";
                } else {
                    $actionError = "创建文件失败，目录可能无写权限。";
                }
            } else {
                $actionError = "同名文件已存在。";
            }
        }
    } elseif ($op === 'delete_item') {
        $targetItem = trim($_POST['item_path'] ?? '');
        $realItem = realpath($targetItem);
        if ($realItem && strpos($realItem, $APP_ROOT) === 0 && $realItem !== $APP_ROOT) {
            if (is_file($realItem)) {
                @unlink($realItem);
                $actionMessage = "文件已成功删除。";
            } elseif (is_dir($realItem)) {
                @rmdir($realItem);
                $actionMessage = "空文件夹已删除。";
            }
        } else {
            $actionError = "无法删除根目录或受保护的路径。";
        }
    }
}

// -------------------------------------------------------------------------
// 整站实时流式打包（支持排除指定目录）
// -------------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'stream_archive') {
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
    sendLog("{$timeStr} 任务开始: 整站源码打包 [START]");

    try {
        if (!class_exists('ZipArchive')) {
            throw new Exception("PHP ZipArchive 扩展未安装");
        }

        $excludeInput = trim($_GET['excludes'] ?? 'runtime,upload,.git,ops');
        $excludeDirs = array_filter(array_map('trim', explode(',', str_replace(["\r", "\n"], ',', $excludeInput))));
        
        sendLog("已设定的排除目录: " . (empty($excludeDirs) ? '无' : implode(', ', $excludeDirs)));

        $zipName = 'site_backup_' . date('Y-m-d_H-i-s') . '.zip';
        $localTmpZip = __DIR__ . '/site_backup_' . uniqid() . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($localTmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
            throw new Exception("无法创建临时 ZIP 文件");
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($APP_ROOT, RecursiveDirectoryIterator::SKIP_DOTS),
                function ($current, $key, $iterator) use ($APP_ROOT, $excludeDirs) {
                    $filename = $current->getFilename();
                    if ($filename[0] === '.' && $filename !== '.env') {
                        return false;
                    }
                    if ($current->isDir()) {
                        if (in_array($filename, $excludeDirs, true)) {
                            return false;
                        }
                    }
                    return true;
                }
            ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $count = 0;
        foreach ($files as $file) {
            $filePath = $file->getRealPath();
            $relativePathInZip = substr($filePath, strlen($APP_ROOT) + 1);

            if ($filePath === $localTmpZip) {
                continue;
            }

            if ($file->isDir()) {
                $zip->addEmptyDir($relativePathInZip);
            } else if ($file->isFile()) {
                $zip->addFile($filePath, $relativePathInZip);
                $count++;
                if ($count % 500 === 0) {
                    sendLog("已打包文件数: {$count}...", "warn");
                }
            }
        }

        $zip->close();
        $_SESSION['ready_download_zip'] = $localTmpZip;
        $_SESSION['ready_download_name'] = $zipName;

        $endStr = date('Y-m-d H:i:s');
        sendLog("{$endStr} 整站打包成功！共打包文件: {$count} 个 [TASK-END]", "success");
        echo "data: " . json_encode(['done' => true, 'file' => $zipName], JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();

    } catch (Throwable $e) {
        sendLog("打包失败: " . $e->getMessage(), "error");
        echo "data: " . json_encode(['done' => true, 'error' => true], JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'download_ready_zip') {
    $path = $_SESSION['ready_download_zip'] ?? '';
    $name = $_SESSION['ready_download_name'] ?? 'site_backup.zip';
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

// 如果是编辑文件模式
$editFileParam = trim($_GET['edit'] ?? '');
$isEditing = false;
$editFileReal = '';
$editFileContent = '';
if ($editFileParam !== '') {
    $resolvedEdit = realpath($editFileParam);
    if ($resolvedEdit && strpos($resolvedEdit, $APP_ROOT) === 0 && is_file($resolvedEdit)) {
        $isEditing = true;
        $editFileReal = $resolvedEdit;
        $editFileContent = file_get_contents($resolvedEdit) ?: '';
    }
}

// 扫描当前目录下的文件与文件夹
$items = [];
if (!$isEditing && is_dir($currentDir)) {
    $handle = opendir($currentDir);
    if ($handle) {
        while (($entry = readdir($handle)) !== false) {
            if ($entry === '.' || ($entry === '..' && $currentDir === $APP_ROOT)) {
                continue;
            }
            $fullPath = $currentDir . '/' . $entry;
            $isDir = is_dir($fullPath);
            $items[] = [
                'name' => $entry,
                'path' => $fullPath,
                'is_dir' => $isDir,
                'size' => $isDir ? '-' : filesize($fullPath),
                'mtime' => filemtime($fullPath)
            ];
        }
        closedir($handle);
    }
    usort($items, function($a, $b) {
        if ($a['is_dir'] !== $b['is_dir']) {
            return $a['is_dir'] ? -1 : 1;
        }
        return strcasecmp($a['name'], $b['name']);
    });
}

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function formatBytes(int $bytes): string {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    elseif ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    elseif ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

$phpVersion = PHP_VERSION;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>AppleCMS OPS - Site Files Manager</title>
<!-- 引入成熟的 CodeMirror 5 核心样式与 Dracula 主题，彻底告别双滚动条与错位重影 -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/theme/dracula.min.css">
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
.breadcrumb { display: flex; align-items: center; gap: 8px; font-size: 13px; margin-bottom: 15px; color: #475569; background: #f8fafc; padding: 10px 15px; border-radius: 8px; border: 1px solid #e2e8f0; }

/* 宝塔/1Panel同款成熟高亮编辑器容器配置 */
.CodeMirror {
    height: 550px;
    border-radius: 9px;
    border: 1px solid #333;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 13px;
    line-height: 1.5;
}

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
<?php include 'sidebar.php'; ?>

    <main class="main">
        <header class="topbar">
            <div class="top-title">Site Files Manager</div>
            <div class="top-right">PHP <?php echo h($phpVersion); ?></div>
        </header>

        <section class="content">
            <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                <div>
                    <h1>站点源码与文件管理</h1>
                    <p>浏览目录、代码编辑、原子覆盖、修改计数器、整站流式打包</p>
                </div>
                <div>
                    <button class="btn btn-success" onclick="openArchiveModal()">📦 整站打包下载 (.zip)</button>
                </div>
            </div>

            <?php if ($actionError): ?>
                <div class="alert"><?php echo h($actionError); ?></div>
            <?php endif; ?>

            <?php if ($actionMessage): ?>
                <div class="success-box"><?php echo $actionMessage; ?></div>
            <?php endif; ?>

            <?php if ($isEditing): ?>
                <!-- 文件编辑与查找替换、另存/覆盖界面 -->
                <div class="card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 10px;">
                        <h3 style="margin:0; font-size:15px;">正在编辑: <span style="color:#2563eb;"><?php echo h(str_replace($APP_ROOT, '', $editFileReal)); ?></span></h3>
                        <a href="site.php?path=<?php echo h(urlencode(dirname(str_replace($APP_ROOT, '', $editFileReal)))); ?>" class="btn" style="background:#64748b; padding:6px 12px; font-size:12px;">← 返回目录列表</a>
                    </div>

                    <!-- 查找替换工具栏 -->
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; padding: 10px 15px; border-radius: 8px; margin-bottom: 12px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <span style="font-size: 12px; font-weight: 600; color: #475569;">🔍 查找替换:</span>
                        <input type="text" id="findInput" class="form-control" placeholder="查找内容..." style="width: 160px; font-size: 12px;">
                        <input type="text" id="replaceInput" class="form-control" placeholder="替换为..." style="width: 160px; font-size: 12px;">
                        <button type="button" class="btn" style="padding: 6px 12px; font-size: 12px;" onclick="doFindReplace()">替换全部</button>
                    </div>

                    <!-- 顶部保存与原子覆盖操作区 -->
                    <form method="POST" id="editForm" onsubmit="syncEditorContent()">
                        <input type="hidden" name="op" value="save_file" id="formOpField">
                        <input type="hidden" name="file_path" value="<?php echo h($editFileReal); ?>">
                        
                        <div style="margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                            <div style="display: flex; gap: 8px; align-items: center;">
                                <input type="text" name="new_target_name" class="form-control" value="<?php echo h(basename($editFileReal)); ?>" placeholder="输入目标文件名 (支持同名覆盖)" style="width: 220px; font-size: 12px;">
                                <button type="button" class="btn" style="background: #7c3aed; padding: 6px 12px; font-size: 12px;" onclick="submitAsNew()">⚡ 另存/原子覆盖并设为0755</button>
                            </div>
                            <button type="submit" class="btn btn-success">💾 直接保存原文件</button>
                        </div>

                        <!-- 纯正 CodeMirror 单容器编辑器挂载点 -->
                        <div style="margin-bottom: 10px;">
                            <textarea name="file_content" id="codeEditor"><?php echo h($editFileContent); ?></textarea>
                        </div>
                        
                        <!-- 底部保存按钮 -->
                        <div style="margin-top: 15px; display: flex; justify-content: flex-end;">
                            <button type="submit" class="btn btn-success">💾 直接保存原文件</button>
                        </div>
                    </form>
                </div>
            <?php else: ?>
                <!-- 目录列表与管理界面 -->
                <div class="card">
                    <div class="breadcrumb">
                        <span>📍 当前目录: <b>/root<?php echo h('/' . $relativePath); ?></b></span>
                        <?php if ($relativePath !== ''): 
                            $parentPath = dirname($relativePath);
                            if ($parentPath === '.' || $parentPath === '/') $parentPath = '';
                        ?>
                            <a href="site.php?path=<?php echo h(urlencode($parentPath)); ?>" style="margin-left: auto; color: #2563eb; font-weight: 600;">⬆️ 返回上级目录</a>
                        <?php endif; ?>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;">
                        <form method="POST" style="display: flex; gap: 8px; align-items: center;">
                            <input type="hidden" name="op" value="create_file">
                            <input type="text" name="new_file_name" class="form-control" placeholder="输入新文件名 (如 test.php)" required style="width: 220px;">
                            <button type="submit" class="btn" style="padding: 8px 12px;">➕ 新建文件</button>
                        </form>
                    </div>

                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th style="width: 45%;">文件名</th>
                                    <th style="width: 15%;">类型</th>
                                    <th style="width: 15%;">大小</th>
                                    <th style="width: 15%;">修改时间</th>
                                    <th style="width: 10%; text-align: right;">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): 
                                    $itemRelPath = trim(str_replace($APP_ROOT, '', $item['path']), '/\\');
                                ?>
                                <tr>
                                    <td>
                                        <?php if ($item['is_dir']): ?>
                                            📁 <a href="site.php?path=<?php echo h(urlencode($itemRelPath)); ?>" style="font-weight: 600; color: #2563eb;"><?php echo h($item['name']); ?></a>
                                        <?php else: ?>
                                            📄 <a href="site.php?edit=<?php echo h(urlencode($item['path'])); ?>" style="font-weight: 600; color: #111827;"><?php echo h($item['name']); ?></a>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $item['is_dir'] ? '文件夹' : '文件'; ?></td>
                                    <td><?php echo $item['is_dir'] ? '-' : formatBytes($item['size']); ?></td>
                                    <td><?php echo date('Y-m-d H:i:s', $item['mtime']); ?></td>
                                    <td style="text-align: right;">
                                        <div style="display: flex; gap: 6px; justify-content: flex-end;">
                                            <?php if (!$item['is_dir']): ?>
                                                <a href="site.php?edit=<?php echo h(urlencode($item['path'])); ?>" class="btn" style="padding: 4px 8px; font-size: 11px;">编辑</a>
                                            <?php endif; ?>
                                            <form method="POST" onsubmit="return confirm('确定要删除 <?php echo h($item['name']); ?> 吗？');" style="display:inline;">
                                                <input type="hidden" name="op" value="delete_item">
                                                <input type="hidden" name="item_path" value="<?php echo h($item['path']); ?>">
                                                <button type="submit" class="btn btn-danger" style="padding: 4px 8px; font-size: 11px;">删除</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    </main>
</div>

<!-- 整站打包配置弹窗 -->
<div id="archiveModal" class="modal-overlay">
    <div class="modal-box">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
            <h3 style="margin: 0; font-size: 15px; color: #fff;">整站打包下载配置</h3>
            <button id="closeModalBtn" onclick="closeArchiveModal()" style="background: transparent; border: none; color: #9ca3af; font-size: 16px; cursor: pointer; display: none;">✕</button>
        </div>
        
        <div id="configFormArea" style="margin-bottom: 15px;">
            <label style="display:block; font-size:12px; color:#9ca3af; margin-bottom:6px;">输入要排除的目录（多个目录用逗号隔开）:</label>
            <textarea id="excludeDirsInput" class="form-control" style="width: 100%; height: 70px; font-size: 12px; background: #030712; color: #fff; border-color: #1f2937;">runtime, upload, .git, ops</textarea>
            <div style="margin-top: 12px; text-align: right;">
                <button class="btn btn-success" onclick="startStreamArchive()" style="font-size: 12px;">🚀 开始执行打包</button>
            </div>
        </div>

        <div id="terminalLog" class="terminal-log" style="display: none;">
            <div>等待发起打包任务...</div>
        </div>

        <div id="modalFooter" style="margin-top: 15px; text-align: right; display: none;">
            <button class="btn btn-success" onclick="downloadArchiveFile()" style="font-size: 12px;">⬇️ 下载整站打包压缩包</button>
        </div>
    </div>
</div>

<!-- 引入 CodeMirror 5 核心库与 PHP 模式支持 -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/php/php.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/clike/clike.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/xml/xml.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/script/script.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/edit/closebrackets.min.js"></script>

<script>
let codeMirrorInstance = null;

window.addEventListener('DOMContentLoaded', () => {
    const textarea = document.getElementById('codeEditor');
    if (textarea) {
        // 初始化 CodeMirror 编辑器，完全无重影、无双滚动条，达到宝塔/1Panel级别体验
        codeMirrorInstance = CodeMirror.fromTextArea(textarea, {
            lineNumbers: true,
            mode: "application/x-httpd-php",
            theme: "dracula",
            indentUnit: 4,
            lineWrapping: true,
            autoCloseBrackets: true
        });
    }
});

// 提交表单前将 CodeMirror 的内容同步回底层的 textarea
function syncEditorContent() {
    if (codeMirrorInstance) {
        codeMirrorInstance.save();
    }
}

function doFindReplace() {
    const findStr = document.getElementById('findInput').value;
    const replaceStr = document.getElementById('replaceInput').value;
    
    if (!findStr) {
        alert('请输入要查找的内容');
        return;
    }

    if (codeMirrorInstance) {
        const content = codeMirrorInstance.getValue();
        if (content.includes(findStr)) {
            const newContent = content.split(findStr).join(replaceStr);
            codeMirrorInstance.setValue(newContent);
            alert('替换完成！');
        } else {
            alert('未在当前文件中找到匹配的内容。');
        }
    }
}

function submitAsNew() {
    syncEditorContent();
    const form = document.getElementById('editForm');
    document.getElementById('formOpField').value = 'save_as_new';
    form.submit();
}

let archiveDownloadUrl = '';

function openArchiveModal() {
    document.getElementById('archiveModal').style.display = 'flex';
    document.getElementById('configFormArea').style.display = 'block';
    document.getElementById('terminalLog').style.display = 'none';
    document.getElementById('modalFooter').style.display = 'none';
    document.getElementById('closeModalBtn').style.display = 'block';
}

function closeArchiveModal() {
    document.getElementById('archiveModal').style.display = 'none';
}

function startStreamArchive() {
    const excludes = document.getElementById('excludeDirsInput').value;
    const configArea = document.getElementById('configFormArea');
    const logBox = document.getElementById('terminalLog');
    const footer = document.getElementById('modalFooter');
    const closeBtn = document.getElementById('closeModalBtn');

    configArea.style.display = 'none';
    logBox.style.display = 'block';
    closeBtn.style.display = 'none';

    const nowStr = new Date().toLocaleString();
    logBox.innerHTML = `<div>${nowStr} 任务开始: 整站源码打包 [START]</div>`;

    const eventSource = new EventSource('site.php?action=stream_archive&excludes=' + encodeURIComponent(excludes));

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
                logBox.innerHTML += `<div class="error">[ERROR] 打包任务异常终止。</div>`;
            } else {
                logBox.innerHTML += `<div class="success">[SUCCESS] 压缩包已准备就绪！</div>`;
                footer.style.display = 'block';
                archiveDownloadUrl = 'site.php?action=download_ready_zip';
            }
        }
    };

    eventSource.onerror = function() {
        eventSource.close();
        logBox.innerHTML += `<div class="error">[ERROR] 连接中断或脚本超时。</div>`;
        closeBtn.style.display = 'block';
    };
}

function downloadArchiveFile() {
    if (archiveDownloadUrl) {
        window.location.href = archiveDownloadUrl;
    }
}
</script>
</body>
</html>
