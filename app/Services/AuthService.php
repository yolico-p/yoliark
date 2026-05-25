<?php

namespace App\Services;

use App\Core\Database;
use App\Core\Security;
use App\Core\Config;

class AuthService
{
    private $db;
    private $config;
    private $userCache = null;
    private $userIdCache = null;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->config = Config::getInstance();
    }

    public function login($username, $password)
    {
        $user = $this->db->fetch("SELECT * FROM users WHERE username = ?", [$username]);

        if (!$user) {
            return ['success' => false, 'message' => '用户名或密码错误'];
        }

        if (!Security::verifyPassword($password, $user['password_hash'])) {
            return ['success' => false, 'message' => '用户名或密码错误'];
        }

        // 关键修复：完全销毁旧 session 并重新生成
        // 1. 保存必要数据
        $oldSessionId = session_id();
        
        // 2. 清空并销毁当前 session
        if (session_id()) {
            $_SESSION = [];
            session_unset();
            session_destroy();
        }
        
        // 3. 删除旧的 session cookie
        if (isset($_COOKIE[session_name()])) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 3600,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
            unset($_COOKIE[session_name()]);
        }
        
        // 4. 重新生成新的 session ID
        session_start();
        session_regenerate_id(true);
        
        // 5. 设置安全的 Session 参数
        ini_set('session.use_strict_mode', 1);
        ini_set('session.use_only_cookies', 1);
        
        // 6. 保存用户数据到新 session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['login_time'] = time();
        $_SESSION['login_ip'] = Security::getClientIP();
        $_SESSION['fingerprint'] = $this->generateFingerprint();
        $_SESSION['created_at'] = time();
        $_SESSION['session_renewed'] = true; // 标记 session 已更新

        $this->initEncryptionKey($user, $password);

        $this->db->update('users', ['last_login' => time()], 'id = ?', [$user['id']]);
        Security::clearRateLimit('login_' . Security::getClientIP());

        $this->logOperation('login', '用户登录');

        return ['success' => true, 'message' => '登录成功'];
    }

    public function logout()
    {
        $this->logOperation('logout', '用户登出');

        $this->clearUserCache();

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }

        session_destroy();
    }

    public function isLoggedIn()
    {
        if (empty($_SESSION['user_id'])) {
            return false;
        }

        if (time() - ($_SESSION['login_time'] ?? 0) > $this->config->get('session_lifetime')) {
            $this->logout();
            return false;
        }

        if ($_SESSION['fingerprint'] !== $this->generateFingerprint()) {
            $this->logout();
            return false;
        }

        if (isset($_SESSION['created_at']) && (time() - $_SESSION['created_at']) > ($this->config->get('session_lifetime') * 2)) {
            $this->logout();
            return false;
        }

        return true;
    }

    public function requireAuth()
    {
        if (!$this->isLoggedIn()) {
            if (Security::isAjax()) {
                Security::jsonOutput(['error' => '未登录或会话已过期'], 401);
            }
            Security::redirect('index.php?page=login');
        }
    }

    public function getUser()
    {
        if ($this->userCache !== null) {
            return $this->userCache;
        }

        if (!$this->isLoggedIn()) {
            return null;
        }

        $this->userCache = $this->db->fetch("SELECT * FROM users WHERE id = ?", [$_SESSION['user_id']]);
        return $this->userCache;
    }

    public function getUserId()
    {
        if ($this->userIdCache !== null) {
            return $this->userIdCache;
        }

        $this->userIdCache = $_SESSION['user_id'] ?? null;
        return $this->userIdCache;
    }

    public function clearUserCache()
    {
        $this->userCache = null;
        $this->userIdCache = null;
    }

    public function changePassword($oldPassword, $newPassword)
    {
        $user = $this->getUser();
        if (!$user) {
            return ['success' => false, 'message' => '用户不存在'];
        }

        if (!Security::verifyPassword($oldPassword, $user['password_hash'])) {
            return ['success' => false, 'message' => '原密码错误'];
        }

        if (strlen($newPassword) < $this->config->get('password_min_length')) {
            return ['success' => false, 'message' => '新密码长度不能少于' . $this->config->get('password_min_length') . '位'];
        }

        $newHash = Security::hashPassword($newPassword);
        $this->db->update('users', ['password_hash' => $newHash, 'updated_at' => time()], 'id = ?', [$user['id']]);

        $this->logOperation('change_password', '修改密码');

        return ['success' => true, 'message' => '密码修改成功'];
    }

    public function updateProfile($data)
    {
        $user = $this->getUser();
        if (!$user) {
            return ['success' => false, 'message' => '用户不存在'];
        }

        $updateData = ['updated_at' => time()];

        if (isset($data['email'])) {
            $updateData['email'] = trim($data['email']);
        }

        if (isset($data['storage_limit'])) {
            $limit = intval($data['storage_limit']);
            if ($limit > 0) {
                $updateData['storage_limit'] = $limit;
            }
        }

        $this->db->update('users', $updateData, 'id = ?', [$user['id']]);

        $this->logOperation('update_profile', '更新个人资料');

        return ['success' => true, 'message' => '资料更新成功'];
    }

    public function createUser($username, $password, $email = '', $role = 'user')
    {
        $existing = $this->db->fetch("SELECT id FROM users WHERE username = ?", [$username]);
        if ($existing) {
            return ['success' => false, 'message' => '用户名已存在'];
        }

        if (strlen($password) < $this->config->get('password_min_length')) {
            return ['success' => false, 'message' => '密码长度不能少于' . $this->config->get('password_min_length') . '位'];
        }

        $now = time();
        $this->db->insert('users', [
            'username' => $username,
            'password_hash' => Security::hashPassword($password),
            'email' => $email,
            'role' => $role,
            'storage_limit' => $this->config->get('max_upload_size') * 20,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['success' => true, 'message' => '用户创建成功'];
    }

    public function hasRole($role)
    {
        $user = $this->getUser();
        if (!$user) return false;
        return ($user['role'] ?? '') === $role;
    }

    public function isAdmin()
    {
        return $this->hasRole('admin');
    }

    public function checkStorageLimit($additionalBytes = 0)
    {
        $userId = $this->getUserId();
        if (!$userId) {
            return ['status' => false, 'reason' => 'user_not_found', 'message' => '用户不存在'];
        }

        // ── 实时计算已用空间，避免 users 表热点行竞争 ──
        $result = $this->db->fetch(
            "SELECT COALESCE(SUM(filesize), 0) as total_used, (SELECT storage_limit FROM users WHERE id = ?) as storage_limit FROM files WHERE user_id = ? AND is_dir = 0",
            [$userId, $userId]
        );
        if (!$result || !isset($result['storage_limit'])) {
            return ['status' => false, 'reason' => 'user_not_found', 'message' => '用户不存在'];
        }
        if (($result['total_used'] + $additionalBytes) > $result['storage_limit']) {
            return ['status' => false, 'reason' => 'storage_exceeded', 'message' => '存储空间不足'];
        }
        return ['status' => true, 'reason' => 'ok', 'message' => ''];
    }

    public function updateStorageUsed($bytes, $increase = true)
    {
        // ── 已废弃实时维护 storage_used，改为实时查询 ──
        // 保留空方法以兼容旧代码调用
        return;
    }

    public function getRemainingStorage()
    {
        $userId = $this->getUserId();
        if (!$userId) {
            return 0;
        }

        $result = $this->db->fetch(
            "SELECT COALESCE(SUM(filesize), 0) as total_used, (SELECT storage_limit FROM users WHERE id = ?) as storage_limit FROM files WHERE user_id = ? AND is_dir = 0",
            [$userId, $userId]
        );
        if (!$result) {
            return 0;
        }
        return max(0, $result['storage_limit'] - $result['total_used']);
    }

    public function getStorageUsed()
    {
        $userId = $this->getUserId();
        if (!$userId) {
            return 0;
        }

        $result = $this->db->fetch(
            "SELECT COALESCE(SUM(filesize), 0) as total_used FROM files WHERE user_id = ? AND is_dir = 0",
            [$userId]
        );
        return $result ? intval($result['total_used']) : 0;
    }

    public function getEncryptionKey()
    {
        if (empty($_SESSION['enc_key'])) return null;
        $key = base64_decode($_SESSION['enc_key']);
        if ($key === false || strlen($key) !== 32) return null;
        return $key;
    }

    public function hasEncryptionKey()
    {
        return !empty($_SESSION['enc_key']);
    }

    private function generateFingerprint()
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $language = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        $ip = Security::getClientIP();

        // 不使用 session_id()：避免用户清除 Cookie 后因 ID 变更被误登出
        $fingerprintData = $ua . '|' . $language . '|' . $ip;
        return hash('sha256', $fingerprintData);
    }

    private function initEncryptionKey($user, $password)
    {
        if (empty($user['encryption_key'])) {
            $rawKey = random_bytes(32);
            $derivedKey = $this->deriveKeyFromPassword($password, $user['username']);
            $iv = random_bytes(16);
            $encryptedKey = openssl_encrypt($rawKey, 'AES-256-CBC', $derivedKey, OPENSSL_RAW_DATA, $iv);
            if ($encryptedKey === false) return;
            $this->db->update('users', [
                'encryption_key' => base64_encode($iv . $encryptedKey),
                'updated_at' => time(),
            ], 'id = ?', [$user['id']]);
            $_SESSION['enc_key'] = base64_encode($rawKey);
        } else {
            $derivedKey = $this->deriveKeyFromPassword($password, $user['username']);
            $data = base64_decode($user['encryption_key']);
            if ($data === false || strlen($data) < 32) return;
            $iv = substr($data, 0, 16);
            $encryptedKey = substr($data, 16);
            $rawKey = openssl_decrypt($encryptedKey, 'AES-256-CBC', $derivedKey, OPENSSL_RAW_DATA, $iv);
            if ($rawKey === false) return;
            $_SESSION['enc_key'] = base64_encode($rawKey);
        }
    }

    private function deriveKeyFromPassword($password, $salt)
    {
        if (!function_exists('hash_pbkdf2')) {
            return hash('sha256', $password . $salt, true);
        }
        return hash_pbkdf2('sha256', $password, $salt, 100000, 32, true);
    }

    private function deriveEncryptionKey($userId)
    {
        // 优先使用环境变量中的密钥
        $secret = getenv('APP_SECRET');
        
        // 如果没有环境变量，尝试从配置文件读取
        if ($secret === false) {
            $config = Config::getInstance();
            $secret = $config->get('app_secret', null);
        }
        
        // 如果还没有，生成一个新的并保存
        if ($secret === false || $secret === 'default_secret_change_in_production' || empty($secret)) {
            $secret = $this->generateSecureSecret();
            
            // 保存到配置文件
            $config = Config::getInstance();
            $config->set('app_secret', $secret);
            $config->save();
        }
        
        return hash('sha256', $secret . '_' . $userId);
    }
    
    private function generateSecureSecret()
    {
        // 生成 32 字节的随机密钥
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes(32));
        } elseif (function_exists('openssl_random_pseudo_bytes')) {
            return bin2hex(openssl_random_pseudo_bytes(32));
        } else {
            // 降级方案（不够安全，但比硬编码好）
            return bin2hex(pack('H*', md5(uniqid((string)mt_rand(), true))));
        }
    }

    private function logOperation($action, $detail = '')
    {
        if (!$this->getUserId()) {
            return;
        }

        $category = $this->getLogCategory($action);
        $severity = $this->getLogSeverity($action);
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $this->db->insert('operation_logs', [
            'user_id' => $this->getUserId(),
            'action' => $action,
            'category' => $category,
            'severity' => $severity,
            'detail' => $detail,
            'ip' => Security::getClientIP(),
            'user_agent' => $userAgent,
            'created_at' => time(),
        ]);
    }

    private function getLogCategory($action)
    {
        $categories = [
            'login' => 'auth', 'logout' => 'auth', 'change_password' => 'auth', 'register' => 'auth',
            'upload' => 'file', 'upload_chunk' => 'file', 'download' => 'file', 'delete' => 'file',
            'rename' => 'file', 'move' => 'file', 'copy' => 'file', 'create_folder' => 'file',
            'restore' => 'file', 'permanent_delete' => 'file', 'empty_trash' => 'file', 'toggle_favorite' => 'file',
            'create_share' => 'share', 'delete_share' => 'share', 'toggle_share' => 'share',
            'update_profile' => 'account', 'update_config' => 'system', 'clear_cache' => 'system', 'clear_logs' => 'system',
        ];
        return $categories[$action] ?? 'other';
    }

    private function getLogSeverity($action)
    {
        $critical = ['permanent_delete', 'empty_trash', 'change_password'];
        $warning = ['delete', 'batch_delete', 'login', 'update_config', 'clear_logs'];
        if (in_array($action, $critical)) return 'critical';
        if (in_array($action, $warning)) return 'warning';
        return 'info';
    }
}
