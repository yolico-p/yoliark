<?php

namespace App\Controllers\System;

use App\Controllers\BaseController;
use App\Core\Security;
use App\Core\Config;
use App\Core\Database;

class ConfigController extends BaseController
{
    public function get()
    {
        $this->requireAuth();

        $config = Config::getInstance();
        $allConfig = $config->getAll();

        Security::jsonOutput(['success' => true, 'config' => $allConfig]);
    }

    public function update()
    {
        $this->requireAuth();
        $this->validateCSRF();

        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data) || !is_array($data)) {
            $this->error('无效的配置数据');
        }

        $config = Config::getInstance();

        $allowedKeys = [
            'app_name', 'max_upload_size', 'chunk_size', 'allowed_extensions',
            'session_lifetime', 'share_link_length', 'share_default_expire',
            'trash_retention_days', 'thumbnail_size', 'preview_max_size',
            'preview_max_size_text', 'preview_max_size_image', 'preview_max_size_media',
            'preview_max_size_office', 'preview_max_size_pdf',
            'login_max_attempts', 'login_lockout_time', 'password_min_length',
            'download_rate_limit', 'download_rate_window', 'delete_rate_limit', 'delete_rate_window',
            'blocked_extensions',
            'storage_reserve_mb', 'storage_update_threshold',
        ];

        // 修改黑名单需要验证当前登录密码
        if (isset($data['blocked_extensions'])) {
            $password = $data['_password'] ?? '';
            if (empty($password)) {
                $this->error('修改文件类型限制需要输入当前密码确认');
            }
            $user = $this->db->fetch("SELECT password_hash FROM users WHERE id = ?", [$this->getUserId()]);
            if (!$user || !\App\Core\Security::verifyPassword($password, $user['password_hash'])) {
                $this->error('密码验证失败，修改已拒绝');
            }
        }

        foreach ($data as $key => $value) {
            if (in_array($key, $allowedKeys)) {
                $config->set($key, $value);
            }
        }

        $result = $config->save();

        if ($result) {
            $this->logOperation('update_config', json_encode($data, JSON_UNESCAPED_UNICODE));
            $this->success('配置已更新');
        } else {
            $this->error('配置保存失败');
        }
    }

    public function importConfig()
    {
        return $this->importSettings();
    }

    public function getCacheSize()
    {
        $this->requireAuth();

        $dir = $this->input('dir', '');
        $cacheSize = 0;

        if (!empty($dir)) {
            if ($dir === 'thumbnails') {
                $dir = STORAGE_PATH . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'thumbnails';
            } elseif ($dir === 'covers') {
                $dir = STORAGE_PATH . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'covers';
            }
            
            if (is_dir($dir)) {
                $cacheSize = $this->getDirSize($dir);
            }
        } else {
            $cacheDir = STORAGE_PATH . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'thumbnails';
            if (is_dir($cacheDir)) {
                $cacheSize = $this->getDirSize($cacheDir);
            }
            $coverDir = STORAGE_PATH . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'covers';
            if (is_dir($coverDir)) {
                $cacheSize += $this->getDirSize($coverDir);
            }
        }

        Security::jsonOutput([
            'success' => true,
            'cache_size' => $cacheSize,
            'cache_size_formatted' => Security::formatSize($cacheSize),
            'size' => Security::formatSize($cacheSize),
        ]);
    }

    public function clearCache()
    {
        $this->requireAuth();
        $this->validateCSRF();

        $type = $this->input('type', '');

        $typeDirMap = [
            'thumbnails' => STORAGE_PATH . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'thumbnails',
            'covers' => STORAGE_PATH . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'covers',
            'cache' => STORAGE_PATH . DIRECTORY_SEPARATOR . 'cache',
        ];

        if (!empty($type) && isset($typeDirMap[$type])) {
            $this->clearDir($typeDirMap[$type]);
        } else {
            $this->clearDir(STORAGE_PATH . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'thumbnails');
            $this->clearDir(STORAGE_PATH . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'covers');
        }

        $db = Database::getInstance();
        $db->clearAllCache();

        $this->logOperation('clear_cache', '清除缓存' . (!empty($type) ? " ({$type})" : ''));

        $this->success('缓存已清除');
    }

    public function exportSettings()
    {
        $this->requireAuth();
        $this->validateCSRF();

        $config = Config::getInstance();

        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="yoliark_settings_' . date('Ymd_His') . '.json"');
        echo json_encode($config->getAll(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public function importSettings()
    {
        $this->requireAuth();
        $this->validateCSRF();

        if (empty($_FILES['settings_file']) && empty($_FILES['config_file'])) {
            $this->error('请选择配置文件');
        }

        $uploadedFile = $_FILES['settings_file'] ?? $_FILES['config_file'];
        $content = file_get_contents($uploadedFile['tmp_name']);
        $data = json_decode($content, true);

        if (empty($data) || !is_array($data)) {
            $this->error('无效的配置文件格式');
        }

        $config = Config::getInstance();

        $allowedKeys = [
            'app_name', 'max_upload_size', 'chunk_size', 'allowed_extensions',
            'session_lifetime', 'share_link_length', 'share_default_expire',
            'trash_retention_days', 'thumbnail_size', 'preview_max_size',
            'preview_max_size_text', 'preview_max_size_image', 'preview_max_size_media',
            'preview_max_size_office', 'preview_max_size_pdf',
            'login_max_attempts', 'login_lockout_time', 'password_min_length',
            'download_rate_limit', 'download_rate_window', 'delete_rate_limit', 'delete_rate_window',
            'blocked_extensions',
            'storage_reserve_mb', 'storage_update_threshold',
        ];

        foreach ($data as $key => $value) {
            if (in_array($key, $allowedKeys)) {
                $config->set($key, $value);
            }
        }

        $result = $config->save();

        if ($result) {
            $this->success('配置已导入');
        } else {
            $this->error('配置导入失败');
        }
    }

    private function getDirSize($dir)
    {
        $size = 0;
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') as $item) {
            if (is_dir($item)) {
                $size += $this->getDirSize($item);
            } else {
                $size += filesize($item);
            }
        }
        return $size;
    }

    private function clearDir($dir)
    {
        if (!is_dir($dir)) return;

        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') as $item) {
            if (is_dir($item)) {
                $this->clearDir($item);
                rmdir($item);
            } else {
                unlink($item);
            }
        }
    }

    private function logOperation($action, $detail = '')
    {
        $userId = $this->getUserId();
        if (!$userId) return;

        $db = Database::getInstance();
        $db->insert('operation_logs', [
            'user_id' => $userId,
            'action' => $action,
            'category' => 'system',
            'severity' => 'warning',
            'detail' => $detail,
            'ip' => Security::getClientIP(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'created_at' => time(),
        ]);
    }

}
