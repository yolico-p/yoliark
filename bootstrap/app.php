<?php

define('ROOT_PATH', dirname(__DIR__));
define('APP_PATH', ROOT_PATH . '/app');
define('FRAMEWORK_PATH', ROOT_PATH . '/framework');
define('CONFIG_PATH', ROOT_PATH . '/config');
define('STORAGE_PATH', ROOT_PATH . '/storage');
define('DATA_PATH', STORAGE_PATH . '/data');
define('FILES_PATH', STORAGE_PATH . '/files');
define('TRASH_PATH', STORAGE_PATH . '/trash');
define('UPLOAD_PATH', ROOT_PATH . '/uploads');
define('PANCLOUD_VERSION', '1.0.0');

$configFile = DATA_PATH . '/config.json';
$dbType = 'sqlite';
if (file_exists($configFile)) {
    $configData = json_decode(file_get_contents($configFile), true);
    if (isset($configData['database']['type'])) {
        $dbType = $configData['database']['type'];
    }
}

if ($dbType === 'sqlite') {
    define('DB_PATH', DATA_PATH . '/pancloud.db');
} else {
    define('DB_PATH', '');
}
define('CONFIG_FILE', $configFile);
define('DEBUG', filter_var(getenv('PANCLOUD_DEBUG') ?: false, FILTER_VALIDATE_BOOLEAN));

// 错误处理由 ErrorHandler 统一管理，这里只设置基础配置
if (!DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}
set_time_limit(0);

spl_autoload_register(function ($class) {
    $prefix = 'Framework\\';
    $baseDir = FRAMEWORK_PATH . '/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $baseDir = APP_PATH . '/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
        return;
    }

    $parts = explode('\\', $relativeClass);
    $className = array_pop($parts);
    $searchDir = $baseDir;
    foreach ($parts as $part) {
        $found = false;
        if (is_dir($searchDir . $part)) {
            $searchDir .= $part . '/';
            $found = true;
        } else {
            $entries = scandir($searchDir);
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                if (strtolower($entry) === strtolower($part) && is_dir($searchDir . $entry)) {
                    $searchDir .= $entry . '/';
                    $found = true;
                    break;
                }
            }
        }
        if (!$found) return;
    }

    $entries = scandir($searchDir);
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $entryName = pathinfo($entry, PATHINFO_FILENAME);
        if (strtolower($entryName) === strtolower($className) && pathinfo($entry, PATHINFO_EXTENSION) === 'php') {
            require $searchDir . $entry;
            return;
        }
    }
});

require_once FRAMEWORK_PATH . '/Support/helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    // 安全的 Session 配置
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', 1);
    ini_set('session.gc_maxlifetime', 7200);
    ini_set('session.gc_probability', 1);
    ini_set('session.gc_divisor', 1000);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.use_trans_sid', 0);
    
    // 防止 Session 固定攻击的关键配置
    ini_set('session.cookie_secure', 0); // 如果启用 HTTPS 改为 1
    ini_set('session.lazy_write', 0); // 禁用延迟写入，确保立即更新

    $isSecure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    if ($isSecure) {
        ini_set('session.cookie_secure', 1);
    }

    if (extension_loaded('redis')) {
        @ini_set('session.save_handler', 'redis');
        @ini_set('session.save_path', 'tcp://127.0.0.1:6379?database=0');
    } elseif (extension_loaded('memcached')) {
        @ini_set('session.save_handler', 'memcached');
        @ini_set('session.save_path', 'localhost:11211');
    }

    session_name('PANCLOUD_SID');
    if (!@session_start()) {
        // Redis/Memcached 不可用，回退到文件 session
        @ini_set('session.save_handler', 'files');
        @ini_set('session.save_path', '');
        session_name('PANCLOUD_SID');
        @session_start();
    }

    if (empty($_SESSION['created_at'])) {
        $_SESSION['created_at'] = time();
    }
}

if (!is_dir(DATA_PATH)) {
    mkdir(DATA_PATH, 0755, true);
}
if (!is_dir(FILES_PATH)) {
    mkdir(FILES_PATH, 0755, true);
}
if (!is_dir(TRASH_PATH)) {
    mkdir(TRASH_PATH, 0755, true);
}
if (!is_dir(UPLOAD_PATH)) {
    mkdir(UPLOAD_PATH, 0755, true);
}

$htaccessContent = "Deny from all\n";
foreach ([FILES_PATH, TRASH_PATH, UPLOAD_PATH, DATA_PATH] as $dir) {
    $htFile = $dir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!file_exists($htFile)) {
        file_put_contents($htFile, $htaccessContent);
    }
}
