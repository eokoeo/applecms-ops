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

// 2. 自动初始化数据库连接（如果全局还没有 $pdo）
if (!isset($pdo)) {
    if (file_exists($DATABASE_CONFIG)) {
        $db_config = include $DATABASE_CONFIG;
        // 兼容处理不同版本苹果CMS的配置数组格式
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

$login_error = '';

// 3. 处理登录表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_action'])) {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!empty($username) && !empty($password)) {
        try {
            $stmt = $pdo->prepare("SELECT admin_name, admin_pwd, admin_random FROM mac_admin WHERE admin_name = ? LIMIT 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                // 苹果CMS标准的双重MD5加盐校验
                $salted_password = md5(md5($password) . $user['admin_random']);
                
                if ($user['admin_pwd'] === $salted_password || $user['admin_pwd'] === md5($password) || $user['admin_pwd'] === $password) {
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

// 4. 已登录则直接放行，返回让主页面继续向下执行
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
<?php 
// 5. 未登录拦截，彻底阻断主页面输出
exit; 
?>
