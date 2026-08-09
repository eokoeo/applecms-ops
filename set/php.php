<?php
declare(strict_types=1);
session_start();
require_once 'login.php'; // 自动引入登录拦截

// 获取所有 php.ini 配置项
$all_ini = ini_get_all(null, true);

// 简易并发压测逻辑 (使用 curl_multi 模拟并发请求)
$benchmark_result = null;
if (isset($_POST['action']) && $_POST['action'] === 'run_benchmark') {
    $concurrency = min((int)($_POST['concurrency'] ?? 5), 20); // 限制最大20并发
    $target_url = "http://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $_SERVER['PHP_SELF'];
    
    $start_time = microtime(true);
    $mh = curl_multi_init();
    $handles = [];
    
    for ($i = 0; $i < $concurrency; $i++) {
        $ch = curl_init($target_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_multi_add_handle($mh, $ch);
        $handles[] = $ch;
    }
    
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh);
    } while ($running > 0);
    
    $total_time = microtime(true) - $start_time;
    
    $success_count = 0;
    foreach ($handles as $ch) {
        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) {
            $success_count++;
        }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    
    $benchmark_result = [
        'concurrency' => $concurrency,
        'total_time' => round($total_time, 4),
        'success_count' => $success_count
    ];
}

// 获取系统负载 (Linux)
$load_avg = function_exists('sys_getloadavg') ? sys_getloadavg() : [0, 0, 0];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>PHP 站点运维与配置总览 - AppleCMS OPS</title>
    <style>
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; min-height: 100%; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; 
            background: #f5f7fb; 
            color: #172033; 
        }
        a { color: inherit; text-decoration: none; }
        .app { display: flex; min-height: 100vh; position: relative; }

        .sidebar { width: 240px; background: #111827; color: #fff; position: fixed; left: 0; top: 0; bottom: 0; padding: 22px 14px; z-index: 10; }
        .logo { font-size: 20px; font-weight: 700; padding: 8px 12px 24px; color: #f8fafc; }
        .logo small { display: block; font-size: 11px; font-weight: 400; color: #9ca3af; margin-top: 5px; }
        .menu-title { color: #6b7280; font-size: 11px; padding: 15px 12px 7px; text-transform: uppercase; font-weight: 600; }
        .menu-item { display: flex; align-items: center; gap: 10px; padding: 11px 12px; margin: 3px 0; border-radius: 9px; color: #cbd5e1; font-size: 14px; transition: .15s; }
        .menu-item:hover { background: #1f2937; color: #fff; }
        .menu-item.active { background: #2563eb; color: #fff; }

        .main { margin-left: 240px; width: calc(100% - 240px); min-height: 100vh; display: flex; flex-direction: column; }
        .topbar { height: 70px; background: #fff; border-bottom: 1px solid #e5e7eb; display: flex; align-items: center; justify-content: space-between; padding: 0 32px; flex-shrink: 0; }
        .top-title { font-size: 18px; font-weight: 600; color: #0f172a; }
        .top-right { color: #64748b; font-size: 14px; display: flex; align-items: center; gap: 8px; }
        .top-right strong { color: #0f172a; background: #f1f5f9; padding: 4px 12px; border-radius: 20px; }

        .content { padding: 32px; max-width: 1400px; margin: 0 auto; width: 100%; flex: 1; }
        
        .grid-2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(450px, 1fr)); gap: 24px; margin-bottom: 24px; }
        
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(15, 23, 42, 0.02); margin-bottom: 24px; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #f1f5f9; padding-bottom: 12px; }
        .card-header h3 { margin: 0; font-size: 16px; font-weight: 600; color: #0f172a; display: flex; align-items: center; gap: 8px; }
        .card-header .subtitle { font-size: 13px; color: #64748b; font-weight: 400; }

        .info-list { display: flex; flex-direction: column; gap: 12px; }
        .info-row { display: flex; justify-content: space-between; align-items: center; font-size: 14px; padding: 6px 0; border-bottom: 1px dashed #e2e8f0; }
        .info-row:last-child { border-bottom: none; }
        .info-label { color: #64748b; }
        .info-value { font-weight: 600; color: #1e293b; background: #f8fafc; padding: 4px 8px; border-radius: 6px; border: 1px solid #f1f5f9; }

        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; color: #475569; margin-bottom: 6px; }
        .form-control { width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; outline: none; }
        .form-control:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }

        .btn { background: #2563eb; color: #fff; border: none; padding: 9px 18px; border-radius: 6px; cursor: pointer; font-weight: 500; font-size: 13px; transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px; }
        .btn:hover { background: #1d4ed8; box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.2); }
        
        .badge-container { display: flex; flex-wrap: wrap; gap: 8px; line-height: 1; max-height: 220px; overflow-y: auto; padding-right: 4px; }
        .badge { background: #f1f5f9; color: #334155; padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: 500; border: 1px solid #e2e8f0; }

        /* 表格样式 */
        .table-container { max-height: 400px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 8px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; text-align: left; }
        th { background: #f8fafc; color: #475569; font-weight: 600; padding: 10px 14px; border-bottom: 1px solid #e2e8f0; position: sticky; top: 0; }
        td { padding: 10px 14px; border-bottom: 1px solid #f1f5f9; color: #334155; word-break: break-all; }
        tr:hover td { background: #f8fafc; }
    </style>
</head>
<body>

<div class="app">
    <?php include 'sidebar.php'; ?>

    <main class="main">
        <header class="topbar">
            <div class="top-title">PHP 站点运维与配置总览</div>
            <div class="top-right">
                当前管理员: <strong><?php echo htmlspecialchars($_SESSION['admin_name'] ?? 'Admin'); ?></strong>
            </div>
        </header>

        <div class="content">

            <div class="grid-2">
                <!-- 环境与负载信息 -->
                <div class="card">
                    <div class="card-header">
                        <h3><span>📊</span> 系统负载与运行环境</h3>
                    </div>
                    <div class="info-list">
                        <div class="info-row">
                            <span class="info-label">CPU 负载 (1/5/15分钟)</span>
                            <span class="info-value"><?php echo "{$load_avg[0]} / {$load_avg[1]} / {$load_avg[2]}"; ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">PHP 版本</span>
                            <span class="info-value"><?php echo phpversion(); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">服务器软件</span>
                            <span class="info-value"><?php echo htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">当前内存占用</span>
                            <span class="info-value" style="color: #059669;"><?php echo round(memory_get_usage(true) / 1024 / 1024, 2); ?> MB</span>
                        </div>
                    </div>
                </div>

                <!-- 简易并发压测工具 -->
                <div class="card">
                    <div class="card-header">
                        <h3><span>⚡</span> 站点简易并发压测 (Curl 多线程)</h3>
                        <span class="subtitle">模拟多并发请求</span>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="run_benchmark">
                        <div class="form-group">
                            <label>并发线程数 (最大 20)</label>
                            <input type="number" name="concurrency" class="form-control" value="5" min="1" max="20">
                        </div>
                        <button type="submit" class="btn">开始并发测试</button>
                    </form>

                    <?php if ($benchmark_result): ?>
                        <div style="margin-top: 15px; background: #f8fafc; padding: 12px; border-radius: 6px; border: 1px solid #e2e8f0; font-size: 13px;">
                            <p style="margin: 0 0 5px 0;"><strong>测试结果：</strong></p>
                            <p style="margin: 0 0 3px 0;">并发线程: <?php echo $benchmark_result['concurrency']; ?></p>
                            <p style="margin: 0 0 3px 0;">成功响应: <?php echo $benchmark_result['success_count']; ?> / <?php echo $benchmark_result['concurrency']; ?></p>
                            <p style="margin: 0;">总耗时: <strong><?php echo $benchmark_result['total_time']; ?></strong> 秒</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 全量 php.ini 参数实时总览表格 -->
            <div class="card">
                <div class="card-header">
                    <div>
                        <h3><span>📋</span> 全部 php.ini 核心参数实时总览</h3>
                        <div class="subtitle" style="margin-top: 4px;">支持在下方实时输入关键字过滤搜索配置项</div>
                    </div>
                    <div>
                        <input type="text" id="iniSearch" placeholder="搜索配置项名称..." class="form-control" style="width: 240px; padding: 6px 10px;" onkeyup="filterIniTable()">
                    </div>
                </div>

                <div class="table-container">
                    <table id="iniTable">
                        <thead>
                            <tr>
                                <th style="width: 35%;">配置项名称 (Directive)</th>
                                <th style="width: 35%;">当前生效值 (Local Value)</th>
                                <th style="width: 30%;">全局默认值 (Global Value)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_ini as $name => $meta): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($name); ?></strong></td>
                                    <td><code style="color: #2563eb;"><?php echo htmlspecialchars(var_export($meta['local_value'] ?? '', true)); ?></code></td>
                                    <td><code style="color: #64748b;"><?php echo htmlspecialchars(var_export($meta['global_value'] ?? '', true)); ?></code></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 扩展组件 -->
            <div class="card">
                <div class="card-header">
                    <h3><span>🧩</span> 已加载的 PHP 扩展模块</h3>
                    <span class="subtitle">共 <?php echo count(get_loaded_extensions()); ?> 个</span>
                </div>
                <div class="badge-container">
                    <?php 
                    $extensions = get_loaded_extensions();
                    sort($extensions); 
                    $core_extensions = ['mysqli', 'pdo_mysql', 'pdo', 'redis', 'curl', 'mbstring', 'openssl', 'swoole', 'gd', 'json', 'session', 'imagick', 'bcmath'];
                    
                    foreach ($extensions as $ext) {
                        $ext_lower = strtolower($ext);
                        $isCore = in_array($ext_lower, $core_extensions, true);
                        $style = $isCore ? 'background: #e0f2fe; color: #0369a1; border-color: #bae6fd; font-weight: 600;' : '';
                        echo '<span class="badge" style="' . $style . '">' . htmlspecialchars($ext) . '</span>';
                    }
                    ?>
                </div>
            </div>

        </div>
    </main>
</div>

<script>
// 实时搜索过滤表格中的配置项
function filterIniTable() {
    let input = document.getElementById('iniSearch');
    let filter = input.value.toLowerCase();
    let table = document.getElementById('iniTable');
    let tr = table.getElementsByTagName('tr');

    for (let i = 1; i < tr.length; i++) {
        let td = tr[i].getElementsByTagName('td')[0];
        if (td) {
            let txtValue = td.textContent || td.innerText;
            if (txtValue.toLowerCase().indexOf(filter) > -1) {
                tr[i].style.display = "";
            } else {
                tr[i].style.display = "none";
            }
        }
    }
}
</script>
</body>
</html>
