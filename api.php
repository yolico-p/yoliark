<?php

require_once __DIR__ . '/bootstrap/app.php';

use App\Core\Security;
use App\Core\Config;

Security::init();

$config = Config::getInstance();

if (!$config->isInstalled()) {
    Security::jsonOutput(['error' => '系统未安装，请先完成安装'], 503);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if (empty($action)) {
    Security::jsonOutput(['error' => '缺少 action 参数'], 400);
}

$publicActions = [
    'login', 'register', 'share_info', 'share_download', 'share_direct', 'record_share_visit', 'license',
    'forgot_password', 'verify_reset',
];

if (!in_array($action, $publicActions) && empty($_SESSION['user_id'])) {
    Security::jsonOutput(['error' => '请先登录', 'code' => 401], 401);
}

if (!in_array($action, $publicActions)) {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
        $token = $_POST['_csrf_token'] ??
            $_SERVER['HTTP_X_CSRF_TOKEN'] ??
            $_SERVER['HTTP_X_XSRF_TOKEN'] ??
            '';

        if (empty($token)) {
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            if (strpos($contentType, 'application/json') !== false) {
                $rawInput = file_get_contents('php://input');
                if (!empty($rawInput)) {
                    $jsonData = json_decode($rawInput, true);
                    if (is_array($jsonData) && !empty($jsonData['_csrf_token'])) {
                        $token = $jsonData['_csrf_token'];
                    }
                    $GLOBALS['_PANCLOUD_JSON_BODY'] = $jsonData ?: [];
                }
            }
        }

        // ── CSRF 验证需要在 session_write_close 之前 ──
        if (!Security::verifyCSRFToken($token)) {
            Security::jsonOutput(['error' => 'CSRF 验证失败', 'code' => 403], 403);
        }
    }

    // ── CSRF 验证通过后再关闭 session，避免阻塞并发请求 ──
    session_write_close();
}

// 许可协议内容（公开接口，无需登录）
if ($action === 'license') {
    $licenseFile = ROOT_PATH . DIRECTORY_SEPARATOR . 'LICENSE';
    if (file_exists($licenseFile)) {
        $content = file_get_contents($licenseFile);
        Security::jsonOutput(['success' => true, 'content' => $content]);
    }
    Security::jsonOutput(['success' => false, 'message' => '许可协议文件不存在'], 404);
}

// 背景图案（公开接口，从 PHP 可写缓存读取）
if ($action === 'bg_pattern') {
    $bgFile = DATA_PATH . DIRECTORY_SEPARATOR . 'bg-pattern.png';
    if (file_exists($bgFile)) {
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=86400');
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 86400) . ' GMT');
        readfile($bgFile);
        exit;
    }
    http_response_code(404);
    exit;
}

$routeMap = [
    'login' => ['controller' => 'App\Controllers\Auth\LoginController', 'method' => 'login'],
    'logout' => ['controller' => 'App\Controllers\Auth\LoginController', 'method' => 'logout'],
    'register' => ['controller' => 'App\Controllers\Auth\LoginController', 'method' => 'register'],
    'change_password' => ['controller' => 'App\Controllers\Auth\ProfileController', 'method' => 'changePassword'],
    'update_profile' => ['controller' => 'App\Controllers\Auth\ProfileController', 'method' => 'updateProfile'],

    'list_files' => ['controller' => 'App\Controllers\File\FileListController', 'method' => 'listFiles'],
    'get_favorites' => ['controller' => 'App\Controllers\File\FileListController', 'method' => 'getFavorites'],
    'search' => ['controller' => 'App\Controllers\File\FileListController', 'method' => 'search'],
    'file_info' => ['controller' => 'App\Controllers\File\FileListController', 'method' => 'fileInfo'],
    'breadcrumb' => ['controller' => 'App\Controllers\File\FileListController', 'method' => 'breadcrumb'],
    'storage_info' => ['controller' => 'App\Controllers\File\FileListController', 'method' => 'storageInfo'],
    'file_stats' => ['controller' => 'App\Controllers\File\FileListController', 'method' => 'fileStats'],
    'list_folders' => ['controller' => 'App\Controllers\File\FileListController', 'method' => 'listAllFolders'],
    'list_all_folders' => ['controller' => 'App\Controllers\File\FileListController', 'method' => 'listAllFolders'],
    'recent_access' => ['controller' => 'App\Controllers\File\FileListController', 'method' => 'recentAccess'],

    'upload' => ['controller' => 'App\Controllers\File\UploadController', 'method' => 'upload'],
    'upload_chunk' => ['controller' => 'App\Controllers\File\UploadController', 'method' => 'uploadChunk'],
    'cancel_upload' => ['controller' => 'App\Controllers\File\UploadController', 'method' => 'cancelUpload'],
    'resolve_upload_conflict' => ['controller' => 'App\Controllers\File\UploadController', 'method' => 'resolveUploadConflict'],
    'get_uploaded_chunks' => ['controller' => 'App\Controllers\File\UploadController', 'method' => 'getUploadedChunks'],
    'cleanup_expired_uploads' => ['controller' => 'App\Controllers\File\UploadController', 'method' => 'cleanupExpiredUploadTasks'],

    'create_folder' => ['controller' => 'App\Controllers\File\FileOpController', 'method' => 'createFolder'],
    'delete' => ['controller' => 'App\Controllers\File\FileOpController', 'method' => 'delete'],
    'batch_delete' => ['controller' => 'App\Controllers\File\FileOpController', 'method' => 'batchDelete'],
    'rename' => ['controller' => 'App\Controllers\File\FileOpController', 'method' => 'rename'],
    'move' => ['controller' => 'App\Controllers\File\FileOpController', 'method' => 'move'],
    'copy' => ['controller' => 'App\Controllers\File\FileOpController', 'method' => 'copy'],
    'toggle_favorite' => ['controller' => 'App\Controllers\File\FileOpController', 'method' => 'toggleFavorite'],
    'toggle_lock' => ['controller' => 'App\Controllers\File\FileOpController', 'method' => 'toggleLock'],
    'toggle_encryption' => ['controller' => 'App\Controllers\File\FileOpController', 'method' => 'toggleEncryption'],
    'update_sort_order' => ['controller' => 'App\Controllers\File\FileOpController', 'method' => 'updateSortOrder'],
    'batch_rename' => ['controller' => 'App\Controllers\File\FileOpController', 'method' => 'batchRename'],
    'batch_move' => ['controller' => 'App\Controllers\File\FileOpController', 'method' => 'batchMove'],
    'batch_copy' => ['controller' => 'App\Controllers\File\FileOpController', 'method' => 'batchCopy'],
    'update_description' => ['controller' => 'App\Controllers\File\FileOpController', 'method' => 'updateDescription'],
    'update_tags' => ['controller' => 'App\Controllers\File\FileOpController', 'method' => 'updateTags'],

    'download' => ['controller' => 'App\Controllers\File\DownloadController', 'method' => 'download'],
    'preview' => ['controller' => 'App\Controllers\File\DownloadController', 'method' => 'preview'],
    'thumbnail' => ['controller' => 'App\Controllers\File\DownloadController', 'method' => 'thumbnail'],
    'record_access' => ['controller' => 'App\Controllers\File\DownloadController', 'method' => 'recordAccess'],

    'create_share' => ['controller' => 'App\Controllers\Share\ShareManageController', 'method' => 'create'],
    'list_shares' => ['controller' => 'App\Controllers\Share\ShareManageController', 'method' => 'list'],
    'delete_share' => ['controller' => 'App\Controllers\Share\ShareManageController', 'method' => 'delete'],
    'toggle_share' => ['controller' => 'App\Controllers\Share\ShareManageController', 'method' => 'toggle'],
    'share_stats' => ['controller' => 'App\Controllers\Share\ShareManageController', 'method' => 'stats'],

    'share_info' => ['controller' => 'App\Controllers\Share\SharePublicController', 'method' => 'info'],
    'share_download' => ['controller' => 'App\Controllers\Share\SharePublicController', 'method' => 'download'],
    'share_direct' => ['controller' => 'App\Controllers\Share\SharePublicController', 'method' => 'directAccess'],
    'record_share_visit' => ['controller' => 'App\Controllers\Share\SharePublicController', 'method' => 'recordShareVisit'],

    'list_trash' => ['controller' => 'App\Controllers\Trash\TrashController', 'method' => 'list'],
    'restore' => ['controller' => 'App\Controllers\Trash\TrashController', 'method' => 'restore'],
    'permanent_delete' => ['controller' => 'App\Controllers\Trash\TrashController', 'method' => 'permanentDelete'],
    'empty_trash' => ['controller' => 'App\Controllers\Trash\TrashController', 'method' => 'emptyTrash'],

    'get_config' => ['controller' => 'App\Controllers\System\ConfigController', 'method' => 'get'],
    'update_config' => ['controller' => 'App\Controllers\System\ConfigController', 'method' => 'update'],
    'get_cache_size' => ['controller' => 'App\Controllers\System\ConfigController', 'method' => 'getCacheSize'],
    'clear_cache' => ['controller' => 'App\Controllers\System\ConfigController', 'method' => 'clearCache'],
    'export_settings' => ['controller' => 'App\Controllers\System\ConfigController', 'method' => 'exportSettings'],
    'import_settings' => ['controller' => 'App\Controllers\System\ConfigController', 'method' => 'importSettings'],
    'import_config' => ['controller' => 'App\Controllers\System\ConfigController', 'method' => 'importConfig'],

    'list_logs' => ['controller' => 'App\Controllers\System\LogController', 'method' => 'list'],
    'log_stats' => ['controller' => 'App\Controllers\System\LogController', 'method' => 'stats'],
    'operation_logs' => ['controller' => 'App\Controllers\System\LogController', 'method' => 'operationLogs'],
    'log_statistics' => ['controller' => 'App\Controllers\System\LogController', 'method' => 'logStatistics'],
    'clear_logs' => ['controller' => 'App\Controllers\System\LogController', 'method' => 'clear'],

    'system_info' => ['controller' => 'App\Controllers\System\MonitorController', 'method' => 'systemInfo'],
    'disk_info' => ['controller' => 'App\Controllers\System\MonitorController', 'method' => 'diskInfo'],
    'get_disk_info' => ['controller' => 'App\Controllers\System\MonitorController', 'method' => 'getDiskInfo'],
    'storage_settings' => ['controller' => 'App\Controllers\System\MonitorController', 'method' => 'storageSettings'],
    'update_storage' => ['controller' => 'App\Controllers\System\MonitorController', 'method' => 'updateStorage'],
    'update_storage_settings' => ['controller' => 'App\Controllers\System\MonitorController', 'method' => 'updateStorageSettings'],
    'manual_update_storage' => ['controller' => 'App\Controllers\System\MonitorController', 'method' => 'manualUpdateStorage'],

    'cloud_storage_config' => ['controller' => 'App\Controllers\System\CloudStorageController', 'method' => 'getConfig'],
    'cloud_storage_save' => ['controller' => 'App\Controllers\System\CloudStorageController', 'method' => 'saveConfig'],
    'cloud_storage_test' => ['controller' => 'App\Controllers\System\CloudStorageController', 'method' => 'testConnection'],
    'cloud_storage_migrate' => ['controller' => 'App\Controllers\System\CloudStorageController', 'method' => 'migrate'],
    'cloud_storage_disable' => ['controller' => 'App\Controllers\System\CloudStorageController', 'method' => 'disable'],
    'cloud_storage_enable' => ['controller' => 'App\Controllers\System\CloudStorageController', 'method' => 'enable'],

    'forgot_password' => ['controller' => 'App\Controllers\Auth\LoginController', 'method' => 'forgotPassword'],
    'verify_reset' => ['controller' => 'App\Controllers\Auth\LoginController', 'method' => 'verifyReset'],

    'ai_agent_config' => ['controller' => 'App\Controllers\System\AIAgentController', 'method' => 'getConfig'],
    'ai_agent_save' => ['controller' => 'App\Controllers\System\AIAgentController', 'method' => 'saveConfig'],
    'ai_agent_fetch_models' => ['controller' => 'App\Controllers\System\AIAgentController', 'method' => 'fetchModels'],
    'ai_agent_test_connection' => ['controller' => 'App\Controllers\System\AIAgentController', 'method' => 'testConnection'],
    'ai_agent_chat' => ['controller' => 'App\Controllers\System\AIAgentController', 'method' => 'chat'],
    'ai_agent_chat_stream' => ['controller' => 'App\Controllers\System\AIAgentController', 'method' => 'chatStream'],
    'ai_generate_title' => ['controller' => 'App\Controllers\System\AIAgentController', 'method' => 'generateTitle'],
    'ai_list_models' => ['controller' => 'App\Controllers\System\AIAgentController', 'method' => 'listModels'],
    'ai_test_connection' => ['controller' => 'App\Controllers\System\AIAgentController', 'method' => 'testConnection'],
];

if (!isset($routeMap[$action])) {
    error_log("[PANCLOUD] Unknown action attempted: " . $action);
    Security::jsonOutput(['error' => '无效的操作请求'], 400);
}

$route = $routeMap[$action];
$controllerClass = $route['controller'];
$method = $route['method'];

try {
    $controller = new $controllerClass();
    if (defined('DEBUG') && DEBUG) {
        error_log("[DEBUG] API Action: {$action}, Controller: {$controllerClass}, Method: {$method}");
    }
    $controller->$method();
} catch (\Throwable $e) {
    // 记录详细错误到日志（不返回给用户）
    error_log("[PANCLOUD ERROR] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    
    // 根据调试模式决定返回信息的详细程度
    if (defined('DEBUG') && DEBUG) {
        Security::jsonOutput([
            'error' => '服务器内部错误',
            'debug' => $e->getMessage()
        ], 500);
    } else {
        // 生产环境只返回通用错误消息
        Security::jsonOutput(['error' => '服务器内部错误，请稍后重试'], 500);
    }
}
