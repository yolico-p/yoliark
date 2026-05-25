<?php

namespace App\Controllers;

use App\Core\Database;
use App\Core\Security;
use App\Core\Config;
use App\Services\AuthService;
use App\Services\FileManagerService;
use App\Services\ShareService;
use App\Services\TrashService;
use App\Services\ThumbnailService;
use App\Services\AudioCoverService;
use App\Services\CloudSyncService;

abstract class BaseController
{
    protected $db;
    protected $config;
    protected $userId;
    protected $auth;
    protected $fileManager;
    protected $shareService;
    protected $trashService;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->config = Config::getInstance();
        $this->userId = $_SESSION['user_id'] ?? null;
    }

    protected function auth()
    {
        if ($this->auth === null) {
            $this->auth = new AuthService();
        }
        return $this->auth;
    }

    protected function fileManager()
    {
        if ($this->fileManager === null) {
            $this->fileManager = new FileManagerService();
        }
        return $this->fileManager;
    }

    protected function shareService()
    {
        if ($this->shareService === null) {
            $this->shareService = new ShareService();
        }
        return $this->shareService;
    }

    protected function trashService()
    {
        if ($this->trashService === null) {
            $this->trashService = new TrashService();
        }
        return $this->trashService;
    }

    protected function getUserId()
    {
        if (!$this->userId) {
            $this->unauthorized('请先登录');
        }
        return $this->userId;
    }

    protected function requireAuth()
    {
        if (!$this->userId) {
            $this->unauthorized('请先登录');
        }
        return $this;
    }

    protected function success($message = '操作成功', $data = [])
    {
        Security::jsonOutput(array_merge(['success' => true, 'message' => $message], $data));
    }

    protected function error($message = '操作失败', $status = 400)
    {
        Security::jsonOutput(['success' => false, 'message' => $message], $status);
    }

    protected function json($data, $status = 200)
    {
        Security::jsonOutput($data, $status);
    }

    protected function unauthorized($message = '未授权')
    {
        Security::jsonOutput(['success' => false, 'message' => $message], 401);
    }

    protected function rateLimit($action, $maxAttempts, $decaySeconds)
    {
        $ip = Security::getClientIP();
        $key = "{$action}_{$ip}";

        if (!Security::rateLimit($key, $maxAttempts, $decaySeconds)) {
            Security::jsonOutput(['success' => false, 'message' => '操作过于频繁，请稍后再试'], 429);
        }
    }

    protected function adaptiveRateLimit($action, $fileSize = 0)
    {
        $userId = $this->getUserId();
        $result = Security::adaptiveRateLimit($action, $userId, $fileSize);

        if (!$result['allowed']) {
            $retryAfter = $result['retry_after'] ?? 30;
            header("Retry-After: {$retryAfter}");
            header('X-RateLimit-Tokens-Left: 0');
            header('X-RateLimit-Pattern: ' . ($result['pattern'] ?? 'unknown'));
            Security::jsonOutput([
                'success' => false,
                'message' => "请求过于频繁，请 {$retryAfter} 秒后再试",
                'retry_after' => $retryAfter,
            ], 429);
        }

        if (!empty($result['warning'])) {
            header('X-RateLimit-Warning: approaching limit');
        }
        if (!empty($result['slowdown_ms'])) {
            usleep($result['slowdown_ms'] * 1000);
        }

        header('X-RateLimit-Tokens-Left: ' . $result['tokens_left']);
        header('X-RateLimit-Pattern: ' . ($result['pattern'] ?? 'unknown'));
        header('X-RateLimit-Usage: ' . round(($result['usage_ratio'] ?? 0) * 100) . '%');
    }

    protected function validateCSRF()
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            return;
        }

        $token = $_POST['_csrf_token'] ??
            $_SERVER['HTTP_X_CSRF_TOKEN'] ??
            $_SERVER['HTTP_X_XSRF_TOKEN'] ??
            '';

        if (!Security::verifyCSRFToken($token)) {
            Security::jsonOutput(['success' => false, 'message' => 'CSRF验证失败，请刷新页面重试'], 403);
        }
    }

    private $jsonBody = null;

    protected function input($key, $default = null)
    {
        if (isset($_POST[$key])) {
            return $_POST[$key];
        }
        if (isset($_GET[$key])) {
            return $_GET[$key];
        }
        if ($this->jsonBody === null) {
            if (isset($GLOBALS['_PANCLOUD_JSON_BODY'])) {
                $this->jsonBody = $GLOBALS['_PANCLOUD_JSON_BODY'];
            } else {
                $raw = file_get_contents('php://input');
                $this->jsonBody = json_decode($raw, true) ?: [];
            }
        }
        if (is_array($this->jsonBody) && isset($this->jsonBody[$key])) {
            return $this->jsonBody[$key];
        }
        return $default;
    }

    /**
     * 清除所有输出缓冲层，用于大文件二进制下载前调用。
     * 避免 Security::init() 注册的压缩 ob_start 缓冲二进制流。
     */
    protected static function cleanOutputBuffer()
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }

    /**
     * 解析批量操作的 ID 列表参数，统一转为 int[]。
     * 支持 JSON 字符串和数组两种传入方式。
     */
    protected function parseIdList($paramName)
    {
        $raw = $this->input($paramName, []);
        if (is_array($raw)) {
            return array_map('intval', array_filter($raw, 'is_numeric'));
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return array_map('intval', array_filter($decoded, 'is_numeric'));
            }
        }
        return [];
    }
}
