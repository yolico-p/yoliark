<?php

namespace App\Services;

use App\Core\Database;
use App\Core\Security;
use App\Core\Config;

class TrashService
{
    private $db;
    private $auth;
    private $config;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->auth = new AuthService();
        $this->config = Config::getInstance();
    }

    public function listTrash()
    {
        $userId = $this->auth->getUserId();
        $items = $this->db->fetchAll(
            "SELECT * FROM trash WHERE user_id = ? ORDER BY deleted_at DESC",
            [$userId]
        );

        foreach ($items as &$item) {
            $item['filesize_formatted'] = Security::formatSize($item['filesize']);
            $item['deleted_at_formatted'] = Security::formatTime($item['deleted_at']);
            $item['expire_at_formatted'] = Security::formatTime($item['expire_at']);
            $item['remaining_days'] = max(0, ceil(($item['expire_at'] - time()) / 86400));
        }

        return $items;
    }

    public function restore($trashId)
    {
        $userId = $this->auth->getUserId();
        $item = $this->db->fetch("SELECT * FROM trash WHERE id = ? AND user_id = ?", [$trashId, $userId]);

        if (!$item) {
            return ['success' => false, 'message' => '回收站项目不存在'];
        }

        $trashPath = TRASH_PATH . DIRECTORY_SEPARATOR . $item['file_id'] . '_' . basename($item['filepath']);
        $originalPath = FILES_PATH . DIRECTORY_SEPARATOR . $item['original_path'];

        if (!file_exists($trashPath)) {
            return ['success' => false, 'message' => '文件物理路径不存在，无法恢复'];
        }

        $restorePath = $originalPath;
        if (file_exists($originalPath)) {
            $uniquePath = $this->getUniqueRestorePath($item['original_path']);
            $restorePath = $uniquePath;
        }

        $dir = dirname($restorePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (!rename($trashPath, $restorePath)) {
            return ['success' => false, 'message' => '恢复文件失败'];
        }

        $relativePath = str_replace(FILES_PATH . DIRECTORY_SEPARATOR, '', $restorePath);

        $parentId = $item['parent_id'];
        if ($parentId > 0) {
            $parentExists = $this->db->fetch("SELECT id FROM files WHERE id = ? AND user_id = ? AND is_dir = 1", [$parentId, $userId]);
            if (!$parentExists) {
                $parentId = 0;
            }
        }

        $now = time();
        $this->db->insert('files', [
            'user_id' => $userId,
            'filename' => $item['filename'],
            'filepath' => $relativePath,
            'filesize' => $item['filesize'],
            'file_type' => $item['file_type'],
            'mime_type' => $item['mime_type'],
            'is_dir' => $item['is_dir'],
            'parent_id' => $parentId,
            'path_hash' => md5($relativePath),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $newFileId = (int)$this->db->getPdo()->lastInsertId();

        $this->db->delete('trash', 'id = ? AND user_id = ?', [$trashId, $userId]);

        // 如果是目录，级联恢复子文件/子目录
        if ($item['is_dir']) {
            $this->restoreChildren($item, (int)$newFileId, $userId);
        }

        $this->logOperation('restore', $item['filename']);

        return ['success' => true, 'message' => '文件已恢复'];
    }

    /**
     * 级联恢复目录的子文件。
     *
     * 级联删除时子文件的物理存储随父目录一并移入回收站（rename），
     * 子文件在 trash 表中只有记录、没有独立物理文件。恢复时只需
     * 插入 files 记录并清理 trash 记录，物理文件随父目录一起恢复。
     */
    private function restoreChildren(array $parentItem, int $newParentId, int $userId): void
    {
        $prefix = $parentItem['original_path'] . DIRECTORY_SEPARATOR;
        $all = $this->db->fetchAll(
            "SELECT * FROM trash WHERE user_id = ? ORDER BY LENGTH(original_path) ASC",
            [$userId]
        );

        foreach ($all as $child) {
            if (!str_starts_with($child['original_path'], $prefix)) {
                continue;
            }

            // 子文件的物理位置由其父目录的 rename 一同恢复，无需移动
            $childParentId = 0;
            $childDir = dirname($child['original_path']);
            if ($childDir !== '.') {
                $parentRecord = $this->db->fetch(
                    "SELECT id FROM files WHERE user_id = ? AND filepath = ?",
                    [$userId, $childDir]
                );
                if ($parentRecord) {
                    $childParentId = (int)$parentRecord['id'];
                }
            }

            $now = time();
            $this->db->insert('files', [
                'user_id' => $userId,
                'filename' => $child['filename'],
                'filepath' => $child['original_path'],
                'filesize' => $child['filesize'],
                'file_type' => $child['file_type'],
                'mime_type' => $child['mime_type'],
                'is_dir' => $child['is_dir'],
                'parent_id' => $childParentId,
                'path_hash' => md5($child['original_path']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->db->delete('trash', 'id = ? AND user_id = ?', [$child['id'], $userId]);
        }
    }

    public function permanentDelete($trashId)
    {
        $userId = $this->auth->getUserId();
        $item = $this->db->fetch("SELECT * FROM trash WHERE id = ? AND user_id = ?", [$trashId, $userId]);

        if (!$item) {
            return ['success' => false, 'message' => '回收站项目不存在'];
        }

        $trashPath = TRASH_PATH . DIRECTORY_SEPARATOR . $item['file_id'] . '_' . basename($item['filepath']);

        if ($item['is_dir']) {
            $this->removeDir($trashPath);
            // 级联删除子文件的回收站记录（物理文件已随父目录一同删除）
            $this->db->delete('trash', 'user_id = ? AND original_path LIKE ?',
                [$userId, $item['original_path'] . '/%']);
        } else {
            if (file_exists($trashPath)) {
                unlink($trashPath);
            }
        }

        $this->auth->updateStorageUsed($item['filesize'], false);

        $this->db->delete('trash', 'id = ? AND user_id = ?', [$trashId, $userId]);

        $this->logOperation('permanent_delete', $item['filename']);

        return ['success' => true, 'message' => '文件已永久删除'];
    }

    public function emptyTrash()
    {
        $userId = $this->auth->getUserId();
        $items = $this->db->fetchAll("SELECT * FROM trash WHERE user_id = ?", [$userId]);

        foreach ($items as $item) {
            $trashPath = TRASH_PATH . DIRECTORY_SEPARATOR . $item['file_id'] . '_' . basename($item['filepath']);

            if ($item['is_dir']) {
                $this->removeDir($trashPath);
            } else {
                if (file_exists($trashPath)) {
                    unlink($trashPath);
                }
            }

            $this->auth->updateStorageUsed($item['filesize'], false);
        }

        $this->db->delete('trash', 'user_id = ?', [$userId]);

        $this->logOperation('empty_trash', '清空回收站');

        return ['success' => true, 'message' => '回收站已清空'];
    }

    public function cleanExpired()
    {
        $items = $this->db->fetchAll("SELECT * FROM trash WHERE expire_at > 0 AND expire_at < ?", [time()]);

        $count = 0;
        foreach ($items as $item) {
            $trashPath = TRASH_PATH . DIRECTORY_SEPARATOR . $item['file_id'] . '_' . basename($item['filepath']);

            if ($item['is_dir']) {
                $this->removeDir($trashPath);
            } else {
                if (file_exists($trashPath)) {
                    unlink($trashPath);
                }
            }

            if ($item['filesize'] > 0 && $item['user_id']) {
                $this->db->query(
                    'UPDATE users SET storage_used = MAX(0, storage_used - ?), updated_at = ? WHERE id = ?',
                    [$item['filesize'], time(), $item['user_id']]
                );
            }

            $this->db->delete('trash', 'id = ?', [$item['id']]);
            $count++;
        }

        return $count;
    }

    private function removeDir($dir)
    {
        if (!is_dir($dir)) return;

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function getUniqueRestorePath($originalPath)
    {
        $fullPath = FILES_PATH . DIRECTORY_SEPARATOR . $originalPath;
        $dir = dirname($fullPath);
        $base = pathinfo($fullPath, PATHINFO_FILENAME);
        $ext = pathinfo($fullPath, PATHINFO_EXTENSION);

        $counter = 1;
        while (file_exists($fullPath)) {
            $newName = $ext ? "{$base} ({$counter}).{$ext}" : "{$base} ({$counter})";
            $fullPath = $dir . DIRECTORY_SEPARATOR . $newName;
            $counter++;
        }

        return $fullPath;
    }

    private function logOperation($action, $target = '')
    {
        $userId = $this->auth->getUserId();
        if (!$userId) return;

        $category = $this->getLogCategory($action);
        $severity = $this->getLogSeverity($action);

        $this->db->insert('operation_logs', [
            'user_id' => $userId,
            'action' => $action,
            'category' => $category,
            'severity' => $severity,
            'target' => $target,
            'ip' => Security::getClientIP(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'created_at' => time(),
        ]);
    }

    private function getLogCategory($action)
    {
        $categories = [
            'restore' => 'file', 'permanent_delete' => 'file', 'empty_trash' => 'file',
        ];
        return $categories[$action] ?? 'other';
    }

    private function getLogSeverity($action)
    {
        $critical = ['permanent_delete', 'empty_trash'];
        if (in_array($action, $critical)) return 'critical';
        return 'info';
    }
}
