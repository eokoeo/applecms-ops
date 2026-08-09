<?php
// sidebar.php
// 获取当前正在访问的文件名（例如 mysql.php 或 meilisearch.php），用来自动判断哪个菜单应该高亮显示
$currentScript = basename($_SERVER['SCRIPT_NAME']);
?>
<aside class="sidebar">
    <div class="logo">AppleCMS OPS<small>Server Management</small></div>
    
    <div class="menu-title">Overview</div>
    <a class="menu-item <?php echo ($currentScript === 'index.php') ? 'active' : ''; ?>" href="index.php?page=dashboard">
        <span class="menu-icon">⌂</span><span class="menu-text">Dashboard</span>
    </a>
    
    <div class="menu-title">Services</div>
        <a class="menu-item <?php echo ($currentScript === 'site.php') ? 'active' : ''; ?>" href="site.php">
        <span class="menu-icon">S</span><span class="menu-text">Site</span>
    </a> 
    <a class="menu-item <?php echo ($currentScript === 'redis.php') ? 'active' : ''; ?>" href="redis.php">
        <span class="menu-icon">R</span><span class="menu-text">Redis</span>
    </a>
    <a class="menu-item <?php echo ($currentScript === 'mysql.php') ? 'active' : ''; ?>" href="mysql.php">
        <span class="menu-icon">M</span><span class="menu-text">MySQL</span>
    </a>
    <a class="menu-item <?php echo ($currentScript === 'meilisearch.php') ? 'active' : ''; ?>" href="meilisearch.php">
        <span class="menu-icon">M</span><span class="menu-text">Meilisearch</span>
    </a>
    <a class="menu-item <?php echo ($currentScript === 'php.php') ? 'active' : ''; ?>" href="php.php">
        <span class="menu-icon">P</span><span class="menu-text">php</span>
    </a> 
    <!-- 以后如果你新增了 site.php，只需要在这里加一行即可： -->
    <!-- 
    <a class="menu-item <?php echo ($currentScript === 'site.php') ? 'active' : ''; ?>" href="site.php">
        <span class="menu-icon">W</span><span class="menu-text">Site</span>
    </a> 
    -->
</aside>
