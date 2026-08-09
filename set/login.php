<?php
// login.php - 统一登录鉴权与环境初始化中心
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. 统一且绝对安全的根目录及配置文件路径计算
global $APP_ROOT, $DATABASE_CONFIG, $MACCMS_CONFIG, $pdo;

$APP_ROOT = realpath(__DIR__ . '/..');
if ($APP_ROOT === false) {
    $APP_ROOT = dirname(__DIR__);
}

$DATABASE_CONFIG = $APP_ROOT . '/application/database.php';
$MACCMS_CONFIG = $APP_ROOT . '/application/extra/maccms.php';

// 2. 自动初始化数据库连接
if (!isset($pdo)) {
    if (file_exists($DATABASE_CONFIG)) {
        $db_config = include $DATABASE_CONFIG;
        $db = $db_config['connections']['mysql'] ?? $db_config;
        $dsn = "mysql:host=" . ($db['hostname'] ?? '127.0.0.1') . ";port=" . ($db['hostport'] ?? 3306) . ";dbname=" . ($db['database'] ?? '') . ";charset=utf8mb4";
        
        try {
            $pdo = new PDO($dsn, $db['username'] ?? '', $db['password'] ?? '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 2
            ]);
        } catch (Throwable $e) {
            die("login.php 数据库连接失败: " . $e->getMessage());
        }
    } else {
        die("未找到数据库配置文件: " . $DATABASE_CONFIG);
    }
}

// ==========================================
// 3. 【核心新增】针对 API 触发、命令行或特殊参数的放行机制
// ==========================================
$is_api_or_cron = false;

// 方式 A：如果 URL 中带有 ?add 或其他特定的自动化参数，允许放行
// （你可以根据需要增减参数，比如 isset($_GET['add']) 或 isset($_GET['token'])）
if (isset($_GET['add']) || isset($_GET['api_action']) || php_sapi_name() === 'cli') {
    $is_api_or_cron = true;
}

// 方式 B：如果你希望更安全，可以加一个密码 Token 校验（例如 ?add=你的通信密钥）
// if (isset($_GET['add']) && $_GET['add'] === '你的安全密钥') { $is_api_or_cron = true; }

if ($is_api_or_cron) {
    return; // 直接放行，不拦截，不显示登录框
}
// ==========================================

$login_error = '';

// 4. 处理登录表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_action'])) {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!empty($username) && !empty($password)) {
        try {
            $stmt = $pdo->prepare("SELECT admin_name, admin_pwd, admin_random FROM mac_admin WHERE admin_name = ? LIMIT 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                $salted_password = md5(md5($password) . $user['admin_random']);
                if ($user['admin_pwd'] === $salted_password ||$user['admin_pwd'] === md5($password) || $user['admin_pwd'] === $password) {
                    $_SESSION['admin_logged'] = true;
                    $_SESSION['admin_name'] = $user['admin_name'];
                    
                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit;
                } else {
                    $login_error = "密码不正确";
                }
            } else {
                $login_error = "管理员账号不存在";
            }
        } catch (Throwable $e) {
            $login_error = "验证出错: " . $e->getMessage();
        }
    } else {
        $login_error = "账号和密码不能为空";
    }
}

// 5. 已登录则直接放行
if (isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true) {
    return; 
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>系统登录 - AppleCMS OPS</title>
    <style>
        body { display: flex; justify-content: center; align-items: center; height: 100vh; background: #111827; font-family: sans-serif; margin: 0; }
        .box { background: #fff; padding: 2rem; border-radius: 10px; width: 300px; box-shadow: 0 4px 6px rgba(0,0,0,0.3); }
        h2 { text-align: center; margin-top: 0; color: #333; }
        .error { color: red; text-align: center; font-size: 14px; margin-bottom: 10px; }
        input { width: 100%; padding: 10px; margin: 10px 0; box-sizing: border-box; border: 1px solid #ccc; border-radius: 4px; }
        button { width: 100%; padding: 10px; background: #2563eb; color: #fff; border: none; cursor: pointer; border-radius: 4px; }
        button:hover { background: #1d4ed8; }
    </style>
</head>
<body>
    <form class="box" method="POST">
        <h2>系统登录</h2>
        <?php if (!empty($login_error)): ?>
            <div class="error"><?php echo htmlspecialchars($login_error); ?></div>
        <?php endif; ?>
        <input type="text" name="username" placeholder="管理员账号" required>
        <input type="password" name="password" placeholder="密码" required>
        <input type="hidden" name="login_action" value="1">
        <button type="submit">登录</button>
    </form>
</body>
</html>
<?php exit; ?>
