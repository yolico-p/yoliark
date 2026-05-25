<?php

namespace App\Services;

use App\Core\Database;
use App\Core\Security;
use App\Core\Config;
use App\Core\SearchService;

class FileManagerService
{
    private $db;
    private $auth;
    private $config;
    private $search;
    private $fileTypeCache = [];  // 文件类型缓存（基于文件名 + 内容哈希）

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->auth = new AuthService();
        $this->config = Config::getInstance();
        $this->search = new SearchService($this->db, $this->db->getQueryCache());

        $this->ensureDirectories();
    }

    public function getAuthService()
    {
        return $this->auth;
    }

    public function getPendingUploadSize($userId)
    {
        $pendingFile = DATA_PATH . DIRECTORY_SEPARATOR . 'pending_upload_' . $userId . '.json';
        if (!file_exists($pendingFile)) {
            return 0;
        }

        $fp = fopen($pendingFile, 'c+');
        if (!$fp) {
            return 0;
        }

        if (flock($fp, LOCK_SH)) {
            $content = stream_get_contents($fp);
            flock($fp, LOCK_UN);
            fclose($fp);

            if ($content) {
                $data = json_decode($content, true);
                if (is_array($data) && isset($data['size'])) {
                    $cleanupTime = $data['updated_at'] ?? 0;
                    if (time() - $cleanupTime > 3600) {
                        unlink($pendingFile);
                        return 0;
                    }
                    return $data['size'];
                }
            }
            return 0;
        }

        fclose($fp);
        return 0;
    }

    public function addPendingUpload($userId, $size)
    {
        $pendingFile = DATA_PATH . DIRECTORY_SEPARATOR . 'pending_upload_' . $userId . '.json';
        $fp = fopen($pendingFile, 'c+');
        if (!$fp) {
            return;
        }

        if (flock($fp, LOCK_EX)) {
            $data = ['size' => 0, 'updated_at' => time()];
            $content = stream_get_contents($fp);
            if ($content) {
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }

            $data['size'] += $size;
            $data['updated_at'] = time();

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data));
            fflush($fp);

            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }

    public function removePendingUpload($userId, $size)
    {
        $pendingFile = DATA_PATH . DIRECTORY_SEPARATOR . 'pending_upload_' . $userId . '.json';
        if (!file_exists($pendingFile)) {
            return;
        }

        $fp = fopen($pendingFile, 'c+');
        if (!$fp) {
            return;
        }

        if (flock($fp, LOCK_EX)) {
            $content = stream_get_contents($fp);
            if ($content) {
                $data = json_decode($content, true);
                if (is_array($data)) {
                    $data['size'] = max(0, ($data['size'] ?? 0) - $size);
                    $data['updated_at'] = time();

                    ftruncate($fp, 0);
                    rewind($fp);
                    fwrite($fp, json_encode($data));
                    fflush($fp);
                }
            }

            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }

    public function cleanupExpiredUploadTasks()
    {
        $expiredTime = time() - 86400;
        $cleanedCount = 0;

        // ── 清理文件记录的分片进度 ──
        $progressFiles = glob(UPLOAD_PATH . DIRECTORY_SEPARATOR . '*.json');
        foreach ($progressFiles as $progressFile) {
            $content = file_get_contents($progressFile);
            $task = json_decode($content, true);
            if ($task && isset($task['created_at']) && $task['created_at'] < $expiredTime) {
                $uploadId = $task['upload_id'] ?? basename($progressFile, '.json');
                $chunkDir = UPLOAD_PATH . DIRECTORY_SEPARATOR . $uploadId;
                $this->cleanChunkDir($chunkDir);
                @unlink($progressFile);
                $lockFile = UPLOAD_PATH . DIRECTORY_SEPARATOR . $uploadId . '.lock';
                @unlink($lockFile);
                $cleanedCount++;
            }
        }

        // ── 兼容旧版数据库记录 ──
        $expiredTasks = $this->db->fetchAll("SELECT * FROM upload_tasks WHERE created_at < ?", [$expiredTime]);
        foreach ($expiredTasks as $task) {
            $chunkDir = UPLOAD_PATH . DIRECTORY_SEPARATOR . $task['upload_id'];
            $this->cleanChunkDir($chunkDir);
            $this->db->delete('upload_tasks', 'id = ?', [$task['id']]);
            $cleanedCount++;
        }

        return $cleanedCount;
    }

    private function ensureDirectories()
    {
        $dirs = [FILES_PATH, TRASH_PATH, UPLOAD_PATH];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        $protectFiles = [
            FILES_PATH . DIRECTORY_SEPARATOR . '.htaccess',
            TRASH_PATH . DIRECTORY_SEPARATOR . '.htaccess',
            UPLOAD_PATH . DIRECTORY_SEPARATOR . '.htaccess',
            DATA_PATH . DIRECTORY_SEPARATOR . '.htaccess',
        ];

        $htaccessContent = "Deny from all\n";

        foreach ($protectFiles as $file) {
            if (!file_exists($file)) {
                file_put_contents($file, $htaccessContent);
            }
        }
    }

    public function listFiles($parentId = 0, $sortBy = 'name', $sortOrder = 'asc', $page = 1, $pageSize = 100)
    {
        $userId = $this->auth->getUserId();

        $allowedSorts = ['name' => 'filename', 'size' => 'filesize', 'time' => 'created_at', 'type' => 'file_type', 'custom' => 'sort_order'];
        $allowedDirs = ['asc', 'desc'];
        $sortColumn = isset($allowedSorts[$sortBy]) ? $allowedSorts[$sortBy] : 'filename';
        $sortDir = in_array(strtolower($sortOrder), $allowedDirs) ? strtoupper($sortOrder) : 'ASC';

        $secondarySort = $sortColumn === 'sort_order' ? ', filename ASC' : '';

        // 如果 pageSize <= 0，表示不分页，返回所有文件
        if ($pageSize <= 0) {
            $files = $this->db->fetchCached(
                "SELECT * FROM files WHERE user_id = ? AND parent_id = ?
                 ORDER BY is_dir DESC, {$sortColumn} {$sortDir}{$secondarySort}",
                [$userId, $parentId],
                ['files', 'user:' . $userId]
            );
        } else {
            $offset = ($page - 1) * $pageSize;
            $files = $this->db->fetchCached(
                "SELECT * FROM files WHERE user_id = ? AND parent_id = ?
                 ORDER BY is_dir DESC, {$sortColumn} {$sortDir}{$secondarySort}
                 LIMIT ? OFFSET ?",
                [$userId, $parentId, $pageSize, $offset],
                ['files', 'user:' . $userId]
            );
        }

        foreach ($files as &$file) {
            $file['filesize_formatted'] = Security::formatSize($file['filesize']);
            $file['created_at_formatted'] = Security::formatTime($file['created_at']);
            $file['updated_at_formatted'] = Security::formatTime($file['updated_at']);
            $file['icon'] = $this->getFileIcon($file);
            $file['tags'] = $this->parseTags($file['tags'] ?? '');

            if (!$file['is_dir'] && $this->hasThumbnailSupport($file['file_type'])) {
                $file['thumbnail_url'] = 'index.php?action=thumbnail&file_id=' . $file['id'];
            } else {
                $file['thumbnail_url'] = null;
            }
        }

        return $files;
    }

    public function getFileCount($parentId = 0)
    {
        $userId = $this->auth->getUserId();
        $result = $this->db->fetch(
            "SELECT COUNT(*) as count FROM files WHERE user_id = ? AND parent_id = ?",
            [$userId, $parentId]
        );
        return $result['count'];
    }

    public function createFolder($parentId, $folderName)
    {
        $userId = $this->auth->getUserId();
        $folderName = Security::sanitizeFilename($folderName);

        if (empty($folderName)) {
            return ['success' => false, 'message' => '文件夹名称不能为空'];
        }

        $parent = $this->getFileById($parentId);
        $parentPath = $parent ? $parent['filepath'] : '';

        $existing = $this->db->fetch(
            "SELECT id FROM files WHERE user_id = ? AND parent_id = ? AND filename = ? AND is_dir = 1",
            [$userId, $parentId, $folderName]
        );

        if ($existing) {
            return ['success' => false, 'message' => '同名文件夹已存在'];
        }

        $folderPath = $parentPath ? $parentPath . DIRECTORY_SEPARATOR . $folderName : $folderName;
        $fullPath = FILES_PATH . DIRECTORY_SEPARATOR . $folderPath;

        if (!is_dir($fullPath)) {
            if (!mkdir($fullPath, 0755, true)) {
                return ['success' => false, 'message' => '文件夹创建失败'];
            }
        }

        $now = time();
        $this->db->insert('files', [
            'user_id' => $userId,
            'filename' => $folderName,
            'filepath' => $folderPath,
            'filesize' => 0,
            'file_type' => 'folder',
            'mime_type' => '',
            'is_dir' => 1,
            'parent_id' => $parentId,
            'path_hash' => md5($folderPath),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->logOperation('create_folder', $folderName);

        $this->db->invalidateTableCache("files");

        return ['success' => true, 'message' => '文件夹创建成功'];
    }

    public function uploadFile($parentId, $fileInfo, $chunkInfo = null, $conflictResolution = null)
    {
        $userId = $this->auth->getUserId();

        if (!isset($fileInfo['tmp_name']) || !is_uploaded_file($fileInfo['tmp_name'])) {
            return ['success' => false, 'message' => '无效的上传文件'];
        }

        $filename = Security::sanitizeFilename($fileInfo['name']);

        if (!Security::validateFileExtension($filename)) {
            return ['success' => false, 'message' => '不允许上传此类型的文件'];
        }

        $fileSize = $fileInfo['size'];

        if ($fileSize > $this->config->get('max_upload_size')) {
            return ['success' => false, 'message' => '文件大小超过限制（最大' . Security::formatSize($this->config->get('max_upload_size')) . '）'];
        }

        $storageCheck = $this->auth->checkStorageLimit($fileSize);
        if (!$storageCheck['status']) {
            return ['success' => false, 'message' => $storageCheck['message']];
        }

        if (!Security::validateFileContent($fileInfo['tmp_name'], $filename)) {
            return ['success' => false, 'message' => '文件内容安全检查失败'];
        }

        $parent = $this->getFileById($parentId);
        $parentPath = $parent ? $parent['filepath'] : '';

        // 大文件（>100MB）跳过全量哈希计算以节省 I/O，放弃该环节的去重
        $contentHash = $fileSize > 104857600 ? '' : $this->calculateSHA256($fileInfo['tmp_name']);

        $duplicate = $this->db->fetch(
            "SELECT id, filename, filesize, filepath FROM files WHERE user_id = ? AND parent_id = ? AND content_hash = ? AND content_hash != ''",
            [$userId, $parentId, $contentHash]
        );

        if ($duplicate) {
            if ($conflictResolution === 'overwrite') {
                $this->deleteFileById($duplicate['id'], $userId);
            } elseif ($conflictResolution === 'keep_both') {
                $filename = $this->getUniqueFilename($userId, $parentId, $filename);
            } elseif ($conflictResolution === 'cancel') {
                return [
                    'success' => false,
                    'message' => '已取消上传',
                ];
            } else {
                return [
                    'success' => false,
                    'duplicate_conflict' => true,
                    'message' => '当前文件夹已存在相同内容的文件："' . $duplicate['filename'] . '"',
                    'duplicate_filename' => $duplicate['filename'],
                    'duplicate_id' => $duplicate['id'],
                ];
            }
        } else {
            $sameNameFile = $this->db->fetch(
                "SELECT id, filename FROM files WHERE user_id = ? AND parent_id = ? AND filename = ?",
                [$userId, $parentId, $filename]
            );
            if ($sameNameFile) {
                if ($conflictResolution === 'overwrite') {
                    $this->deleteFileById($sameNameFile['id'], $userId);
                } elseif ($conflictResolution === 'keep_both') {
                    $filename = $this->getUniqueFilename($userId, $parentId, $filename);
                } elseif ($conflictResolution === 'cancel') {
                    return [
                        'success' => false,
                        'message' => '已取消上传',
                    ];
                } else {
                    return [
                        'success' => false,
                        'duplicate_conflict' => true,
                        'message' => '当前文件夹已存在同名文件："' . $sameNameFile['filename'] . '"',
                        'duplicate_filename' => $sameNameFile['filename'],
                        'duplicate_id' => $sameNameFile['id'],
                    ];
                }
            }
        }

        $filePath = $parentPath ? $parentPath . DIRECTORY_SEPARATOR . $filename : $filename;
        $fullPath = FILES_PATH . DIRECTORY_SEPARATOR . $filePath;

        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // ── 文件I/O移出事务：先保存文件，再开事务写入数据库 ──
        if (!move_uploaded_file($fileInfo['tmp_name'], $fullPath)) {
            return ['success' => false, 'message' => '文件保存失败'];
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mimeType = $this->getMimeType($fullPath);
        $now = time();

        // ── 事务仅包裹最核心的 INSERT，缩短锁持有时间 ──
        $this->db->beginTransaction();

        try {
            // 在事务内重新检查重复（并发时上一个事务提交后数据已变）
            $dupInTx = $this->db->fetch(
                "SELECT id, filename FROM files WHERE user_id = ? AND parent_id = ? AND filename = ?",
                [$userId, $parentId, $filename]
            );
            if ($dupInTx) {
                $this->db->rollBack();
                @unlink($fullPath);
                if ($conflictResolution === 'overwrite') {
                    $this->deleteFileById($dupInTx['id'], $userId);
                    return $this->uploadFile($parentId, $fileInfo, null, $conflictResolution);
                } elseif ($conflictResolution === 'keep_both') {
                    $filename = $this->getUniqueFilename($userId, $parentId, $filename);
                } elseif ($conflictResolution === 'cancel') {
                    return ['success' => false, 'message' => '已取消上传'];
                } else {
                    return [
                        'success' => false,
                        'duplicate_conflict' => true,
                        'message' => '当前文件夹已存在同名文件："' . $dupInTx['filename'] . '"',
                        'duplicate_filename' => $dupInTx['filename'],
                        'duplicate_id' => $dupInTx['id'],
                    ];
                }
            }

            $fileId = $this->db->insert('files', [
                'user_id' => $userId,
                'filename' => $filename,
                'filepath' => $filePath,
                'filesize' => $fileSize,
                'file_type' => $ext,
                'mime_type' => $mimeType,
                'is_dir' => 0,
                'parent_id' => $parentId,
                'path_hash' => md5($filePath),
                'content_hash' => $contentHash,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }
            return ['success' => false, 'message' => '文件上传失败：' . $e->getMessage()];
        }

        // ── storage_used 移出事务，减少 users 表锁竞争 ──
        $this->auth->updateStorageUsed($fileSize, true);

        $this->logOperation('upload', $filename);

        $this->db->invalidateTableCache("files");

        return ['success' => true, 'message' => '文件上传成功', 'filename' => $filename, 'size' => $fileSize];
    }

    public function getUploadedChunks($uploadId)
    {
        $userId = $this->auth->getUserId();

        // ── 优先从文件记录读取分片进度 ──
        $progressFile = UPLOAD_PATH . DIRECTORY_SEPARATOR . $uploadId . '.json';
        if (file_exists($progressFile)) {
            $content = file_get_contents($progressFile);
            $task = json_decode($content, true);
            if ($task && $task['user_id'] == $userId) {
                return $task['uploaded_chunks'] ?: [];
            }
        }

        // ── 兼容旧版数据库记录 ──
        $task = $this->db->fetch("SELECT * FROM upload_tasks WHERE upload_id = ? AND user_id = ?", [$uploadId, $userId]);
        if ($task) {
            return json_decode($task['uploaded_chunks'], true) ?: [];
        }

        return [];
    }

    public function cancelUpload($uploadId)
    {
        $userId = $this->auth->getUserId();

        // ── 清理文件记录的分片进度 ──
        $progressFile = UPLOAD_PATH . DIRECTORY_SEPARATOR . $uploadId . '.json';
        $lockFile = UPLOAD_PATH . DIRECTORY_SEPARATOR . $uploadId . '.lock';
        $chunkDir = UPLOAD_PATH . DIRECTORY_SEPARATOR . $uploadId;

        if (file_exists($progressFile)) {
            $content = file_get_contents($progressFile);
            $task = json_decode($content, true);
            if ($task && $task['user_id'] == $userId) {
                $this->cleanChunkDir($chunkDir);
                @unlink($progressFile);
                @unlink($lockFile);
                return ['success' => true, 'message' => '上传已取消'];
            }
        }

        // ── 兼容旧版数据库记录 ──
        $task = $this->db->fetch("SELECT * FROM upload_tasks WHERE upload_id = ? AND user_id = ?", [$uploadId, $userId]);
        if ($task) {
            $chunkDir = UPLOAD_PATH . DIRECTORY_SEPARATOR . $task['upload_id'];
            $this->cleanChunkDir($chunkDir);
            $this->db->delete('upload_tasks', 'upload_id = ? AND user_id = ?', [$uploadId, $userId]);
        }

        $this->db->invalidateTableCache("files");

        return ['success' => true, 'message' => '上传已取消'];
    }

    public function resolveUploadConflict($uploadId, $conflictResolution)
    {
        $userId = $this->auth->getUserId();

        $task = $this->db->fetch("SELECT * FROM upload_tasks WHERE upload_id = ? AND user_id = ?", [$uploadId, $userId]);
        if (!$task) {
            return ['success' => false, 'message' => '上传任务不存在'];
        }

        $chunkDir = UPLOAD_PATH . DIRECTORY_SEPARATOR . $uploadId;
        if (!is_dir($chunkDir)) {
            $this->db->delete('upload_tasks', 'upload_id = ? AND user_id = ?', [$uploadId, $userId]);
            return ['success' => false, 'message' => '分片文件已过期，请重新上传'];
        }

        return $this->mergeChunks(
            $task['parent_id'],
            $task,
            $chunkDir,
            $task['filename'],
            $task['total_size'],
            $conflictResolution
        );
    }

    public function uploadChunk($parentId, $chunkInfo)
    {
        $userId = $this->auth->getUserId();
        $uploadId = $chunkInfo['upload_id'] ?? '';
        $chunkIndex = intval($chunkInfo['chunk_index'] ?? 0);
        $totalChunks = intval($chunkInfo['total_chunks'] ?? 0);
        $filename = Security::sanitizeFilename($chunkInfo['filename'] ?? '');
        $totalSize = intval($chunkInfo['total_size'] ?? 0);
        $chunkMd5 = $chunkInfo['chunk_md5'] ?? '';

        if (empty($uploadId) || empty($filename) || $totalChunks <= 0) {
            return ['success' => false, 'message' => '分片参数不完整'];
        }

        if (!Security::validateFileExtension($filename)) {
            return ['success' => false, 'message' => '不允许上传此类型的文件'];
        }

        if ($totalSize > $this->config->get('max_upload_size')) {
            return ['success' => false, 'message' => '文件大小超过限制'];
        }

        if ($chunkIndex === 0 && $totalSize > 0) {
            $storageCheck = $this->auth->checkStorageLimit($totalSize);
            if (!$storageCheck['status']) {
                return ['success' => false, 'message' => $storageCheck['message']];
            }
        }

        $chunkDir = UPLOAD_PATH . DIRECTORY_SEPARATOR . $uploadId;
        if (!is_dir($chunkDir)) {
            mkdir($chunkDir, 0755, true);
        }
        $chunkFile = $chunkDir . DIRECTORY_SEPARATOR . $chunkIndex;

        if (isset($_FILES['chunk_data']) && $_FILES['chunk_data']['error'] === UPLOAD_ERR_OK && is_uploaded_file($_FILES['chunk_data']['tmp_name'])) {
            $isFirstChunk = $chunkIndex === 0;
            $totalChunks = intval($_POST['total_chunks'] ?? 1);
            $isLastChunk = $chunkIndex === $totalChunks - 1;

            // 智能内容检查策略
            // 1. 首分片必须检查
            // 2. 小文件（<10MB）所有分片都检查
            // 3. 非安全文件类型所有分片都检查
            $needContentCheck = false;
            
            if ($isFirstChunk) {
                $needContentCheck = true;
            } elseif ($totalSize < 10 * 1024 * 1024) { // 10MB
                $needContentCheck = true;
            } elseif (!$this->isSafeFileType($filename, $_FILES['chunk_data']['tmp_name'])) {
                $needContentCheck = true;
            }
            
            // 执行内容检查
            if ($needContentCheck && !$this->isSafeFileType($filename, $_FILES['chunk_data']['tmp_name'])) {
                if (!Security::validateFileContent($_FILES['chunk_data']['tmp_name'], $filename)) {
                    return ['success' => false, 'message' => '文件内容安全检查失败'];
                }
            }

            if (!move_uploaded_file($_FILES['chunk_data']['tmp_name'], $chunkFile)) {
                return ['success' => false, 'message' => '分片文件保存失败'];
            }

            if (!empty($chunkMd5) && ($isFirstChunk || $isLastChunk)) {
                $actualMd5 = md5_file($chunkFile);
                if ($actualMd5 !== $chunkMd5) {
                    @unlink($chunkFile);
                    return ['success' => false, 'message' => '分片 MD5 校验失败'];
                }
            }
        } else {
            $errorMsg = isset($_FILES['chunk_data']) ? '分片文件接收失败（错误码：' . $_FILES['chunk_data']['error'] . '）' : '未接收到分片数据';
            return ['success' => false, 'message' => $errorMsg];
        }

        // 文件锁：重试 5 次，避免并发写入旁路
        $progressLockFile = $chunkDir . DIRECTORY_SEPARATOR . '.progress.lock';
        $lockFp = null;
        $lockAcquired = false;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $lockFp = @fopen($progressLockFile, 'c+');
            if ($lockFp && flock($lockFp, LOCK_EX | LOCK_NB)) {
                $lockAcquired = true;
                break;
            }
            if ($lockFp) {
                fclose($lockFp);
                $lockFp = null;
            }
            if ($attempt < 4) {
                usleep(50000 * ($attempt + 1)); // 50ms, 100ms, 150ms, 200ms
            }
        }

        if ($lockAcquired) {
            try {
                $result = $this->_saveChunkProgress($uploadId, $userId, $chunkIndex, $totalChunks, $parentId, $filename, $totalSize, $chunkInfo['file_md5'] ?? '');
                flock($lockFp, LOCK_UN);
                fclose($lockFp);
                return $result;
            } catch (\Exception $e) {
                flock($lockFp, LOCK_UN);
                fclose($lockFp);
                return ['success' => false, 'message' => '分片上传失败：' . $e->getMessage()];
            }
        }

        // 最后一次兜底：走数据库乐观锁
        if ($lockFp) {
            fclose($lockFp);
        }
        return $this->_saveChunkProgress($uploadId, $userId, $chunkIndex, $totalChunks, $parentId, $filename, $totalSize, $chunkInfo['file_md5'] ?? '');
    }

    private function _saveChunkProgress($uploadId, $userId, $chunkIndex, $totalChunks, $parentId, $filename, $totalSize, $fileMd5 = '')
    {
        // ── 使用文件记录分片进度，减少SQLite写入竞争 ──
        $progressFile = UPLOAD_PATH . DIRECTORY_SEPARATOR . $uploadId . '.json';
        $lockFile = UPLOAD_PATH . DIRECTORY_SEPARATOR . $uploadId . '.lock';

        $lockFp = fopen($lockFile, 'c+');
        if (!$lockFp || !flock($lockFp, LOCK_EX)) {
            if ($lockFp) fclose($lockFp);
            return ['success' => false, 'message' => '无法获取文件锁'];
        }

        try {
            $task = null;
            if (file_exists($progressFile)) {
                $content = file_get_contents($progressFile);
                $task = json_decode($content, true);
            }

            if (!$task) {
                $task = [
                    'user_id' => $userId,
                    'upload_id' => $uploadId,
                    'filename' => $filename,
                    'total_size' => $totalSize,
                    'total_chunks' => $totalChunks,
                    'uploaded_chunks' => [],
                    'file_md5' => $fileMd5,
                    'parent_id' => $parentId,
                    'created_at' => time(),
                    'updated_at' => time(),
                ];
            }

            $uploadedChunks = $task['uploaded_chunks'] ?: [];

            if (in_array($chunkIndex, $uploadedChunks)) {
                flock($lockFp, LOCK_UN);
                fclose($lockFp);
                return ['success' => true, 'message' => '分片已存在', 'uploaded_chunks' => count($uploadedChunks), 'total_chunks' => $totalChunks, 'skipped' => true];
            }

            $uploadedChunks[] = $chunkIndex;
            $task['uploaded_chunks'] = $uploadedChunks;
            $task['updated_at'] = time();

            file_put_contents($progressFile, json_encode($task), LOCK_EX);

            flock($lockFp, LOCK_UN);
            fclose($lockFp);

            if (count($uploadedChunks) >= $totalChunks) {
                $chunkDir = UPLOAD_PATH . DIRECTORY_SEPARATOR . $uploadId;
                return $this->mergeChunks($task['parent_id'] ?? $parentId, $task, $chunkDir, $task['filename'] ?? $filename, $task['total_size'] ?? $totalSize);
            }

            return ['success' => true, 'message' => '分片上传成功', 'uploaded_chunks' => count($uploadedChunks), 'total_chunks' => $totalChunks];
        } catch (\Exception $e) {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
            return ['success' => false, 'message' => '分片上传失败：' . $e->getMessage()];
        }
    }

    private function mergeChunks($parentId, $task, $chunkDir, $filename, $totalSize, $conflictResolution = null)
    {
        $userId = $this->auth->getUserId();

        $storageCheck = $this->auth->checkStorageLimit($totalSize);
        if (!$storageCheck['status']) {
            $this->cleanChunkDir($chunkDir);
            return ['success' => false, 'message' => $storageCheck['message']];
        }

        $parent = $this->getFileById($parentId);
        $parentPath = $parent ? $parent['filepath'] : '';

        $sanitizedFilename = Security::sanitizeFilename($filename);

        $duplicate = $this->db->fetch(
            "SELECT id, filename, filesize, filepath FROM files WHERE user_id = ? AND parent_id = ? AND filename = ?",
            [$userId, $parentId, $sanitizedFilename]
        );

        if ($duplicate) {
            if ($conflictResolution === 'overwrite') {
                $this->deleteFileById($duplicate['id'], $userId);
            } elseif ($conflictResolution === 'keep_both') {
                $sanitizedFilename = $this->getUniqueFilename($userId, $parentId, $sanitizedFilename);
            } elseif ($conflictResolution === 'cancel') {
                $this->cleanChunkDir($chunkDir);
                $this->db->delete('upload_tasks', 'upload_id = ? AND user_id = ?', [$task['upload_id'], $userId]);
                return [
                    'success' => false,
                    'message' => '已取消上传',
                ];
            } else {
                return [
                    'success' => false,
                    'duplicate_conflict' => true,
                    'message' => '当前文件夹已存在同名文件："' . $duplicate['filename'] . '"',
                    'duplicate_filename' => $duplicate['filename'],
                    'duplicate_id' => $duplicate['id'],
                    'upload_id' => $task['upload_id'],
                    'filename' => $sanitizedFilename,
                    'total_size' => $totalSize,
                    'total_chunks' => $task['total_chunks'],
                ];
            }
        }

        $filePath = $parentPath ? $parentPath . DIRECTORY_SEPARATOR . $sanitizedFilename : $sanitizedFilename;
        $fullPath = FILES_PATH . DIRECTORY_SEPARATOR . $filePath;

        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        try {
            $output = fopen($fullPath, 'wb');
            if ($output === false) {
                $this->cleanChunkDir($chunkDir);
                return ['success' => false, 'message' => '无法创建输出文件'];
            }

            $bufferSize = 65536;
            for ($i = 0; $i < $task['total_chunks']; $i++) {
                $chunkFile = $chunkDir . DIRECTORY_SEPARATOR . $i;
                if (!file_exists($chunkFile)) {
                    fclose($output);
                    if (file_exists($fullPath)) {
                        unlink($fullPath);
                    }
                    $this->cleanChunkDir($chunkDir);
                    return ['success' => false, 'message' => '分片文件缺失'];
                }

                $input = fopen($chunkFile, 'rb');
                if ($input === false) {
                    fclose($output);
                    if (file_exists($fullPath)) {
                        unlink($fullPath);
                    }
                    $this->cleanChunkDir($chunkDir);
                    return ['success' => false, 'message' => '无法读取分片文件'];
                }

                while (!feof($input)) {
                    $buffer = fread($input, $bufferSize);
                    if ($buffer === false || fwrite($output, $buffer) === false) {
                        fclose($input);
                        fclose($output);
                        if (file_exists($fullPath)) {
                            unlink($fullPath);
                        }
                        $this->cleanChunkDir($chunkDir);
                        return ['success' => false, 'message' => '分片写入失败'];
                    }
                }
                fclose($input);
                unlink($chunkFile);
            }
            fclose($output);

            $this->cleanChunkDir($chunkDir);

            $actualSize = filesize($fullPath);
            if ($actualSize === false) {
                if (file_exists($fullPath)) {
                    unlink($fullPath);
                }
                return ['success' => false, 'message' => '无法获取文件大小'];
            }

            $ext = strtolower(pathinfo($sanitizedFilename, PATHINFO_EXTENSION));
            $mimeType = $this->getMimeType($fullPath);

            // 大文件（>100MB）跳过全量哈希计算以节省 I/O
            $contentHash = $totalSize > 104857600 ? '' : $this->calculateSHA256($fullPath);

            // ── 文件I/O完成后再开事务，缩短锁持有时间 ──
            $this->db->beginTransaction();
            try {
                $now = time();
                $fileId = $this->db->insert('files', [
                    'user_id' => $userId,
                    'filename' => $sanitizedFilename,
                    'filepath' => $filePath,
                    'filesize' => $actualSize,
                    'file_type' => $ext,
                    'mime_type' => $mimeType,
                    'is_dir' => 0,
                    'parent_id' => $parentId,
                    'path_hash' => md5($filePath),
                    'content_hash' => $contentHash,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $this->auth->updateStorageUsed($actualSize, true);

                $this->db->commit();
            } catch (\Exception $e) {
                $this->db->rollBack();
                if (file_exists($fullPath)) {
                    @unlink($fullPath);
                }
                return ['success' => false, 'message' => '数据库写入失败：' . $e->getMessage()];
            }
        } catch (\Exception $e) {
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }
            $this->cleanChunkDir($chunkDir);
            return ['success' => false, 'message' => '分片合并失败：' . $e->getMessage()];
        }

        $this->logOperation('upload_chunk', $sanitizedFilename);

        $this->db->invalidateTableCache("files");

        return ['success' => true, 'message' => '文件上传成功', 'filename' => $sanitizedFilename, 'size' => $actualSize, 'merged' => true];
    }

    private function cleanChunkDir($dir)
    {
        if (is_dir($dir)) {
            $items = glob($dir . DIRECTORY_SEPARATOR . '*');
            foreach ($items as $item) {
                if (is_dir($item)) {
                    $this->removeDirRecursive($item);
                } else {
                    unlink($item);
                }
            }
            rmdir($dir);
        }
        $lockFile = $dir . '.lock';
        if (file_exists($lockFile)) {
            @unlink($lockFile);
        }
    }

    private function removeDirRecursive($dir)
    {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirRecursive($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    public function downloadFile($fileId)
    {
        $userId = $this->auth->getUserId();
        $file = $this->db->fetch("SELECT * FROM files WHERE id = ? AND user_id = ?", [$fileId, $userId]);

        if (!$file) {
            return ['success' => false, 'message' => '文件不存在'];
        }

        if ($file['is_dir']) {
            return $this->downloadFolder($file);
        }

        $fullPath = FILES_PATH . DIRECTORY_SEPARATOR . $file['filepath'];

        if (!file_exists($fullPath)) {
            return ['success' => false, 'message' => '文件已被删除或不存在'];
        }

        $this->logOperation('download', $file['filename']);

        return ['success' => true, 'path' => $fullPath, 'filename' => $file['filename'], 'mime' => $file['mime_type'], 'size' => $file['filesize'], 'content_hash' => $file['content_hash'] ?? ''];
    }

    private function downloadFolder($file)
    {
        $fullPath = FILES_PATH . DIRECTORY_SEPARATOR . $file['filepath'];

        if (!is_dir($fullPath)) {
            return ['success' => false, 'message' => '文件夹不存在'];
        }

        $zipFile = UPLOAD_PATH . DIRECTORY_SEPARATOR . $file['filename'] . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.zip';

        if (file_exists($zipFile)) {
            return ['success' => false, 'message' => '临时文件创建失败，请稍后再试'];
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipFile, \ZipArchive::CREATE) !== true) {
            return ['success' => false, 'message' => '无法创建压缩文件'];
        }

        $this->addDirToZip($zip, $fullPath, $file['filename']);
        $zip->close();

        $this->logOperation('download_folder', $file['filename']);

        return ['success' => true, 'path' => $zipFile, 'filename' => $file['filename'] . '.zip', 'mime' => 'application/zip', 'size' => filesize($zipFile), 'temp' => true];
    }

    private function addDirToZip($zip, $dir, $prefix)
    {
        $zip->addEmptyDir($prefix);
        $items = scandir($dir);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;

            $path = $dir . DIRECTORY_SEPARATOR . $item;

            $safeItem = str_replace(['..', '\\', '/'], '', $item);
            if ($safeItem !== $item || strpos($item, '..') !== false) {
                continue;
            }

            $zipPath = $prefix . DIRECTORY_SEPARATOR . $safeItem;

            if (is_dir($path)) {
                $this->addDirToZip($zip, $path, $zipPath);
            } else {
                $zip->addFile($path, $zipPath);
            }
        }
    }

    public function deleteFile($fileId)
    {
        $userId = $this->auth->getUserId();
        $file = $this->db->fetch("SELECT * FROM files WHERE id = ? AND user_id = ?", [$fileId, $userId]);

        if (!$file) {
            return ['success' => false, 'message' => '文件不存在'];
        }

        if (!empty($file['is_locked'])) {
            return ['success' => false, 'message' => '文件已锁定，无法删除'];
        }

        $this->db->beginTransaction();

        try {
            $moveResult = $this->moveToTrash($file);
            if (!$moveResult['success']) {
                throw new \Exception($moveResult['message']);
            }

            if ($file['is_dir']) {
                $this->deleteSubFiles($fileId, $userId);
            }

            $this->db->delete('files', 'id = ? AND user_id = ?', [$fileId, $userId]);

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => '删除失败：' . $e->getMessage()];
        }

        $this->logOperation('delete', $file['filename']);

        return ['success' => true, 'message' => '文件已移至回收站'];
    }

    private function deleteSubFiles($parentId, $userId)
    {
        $children = $this->db->fetchAll("SELECT * FROM files WHERE parent_id = ? AND user_id = ?", [$parentId, $userId]);

        foreach ($children as $child) {
            $trashResult = $this->moveToTrash($child);
            if (!$trashResult['success']) {
                throw new \Exception($trashResult['message']);
            }

            if ($child['is_dir']) {
                $this->deleteSubFiles($child['id'], $userId);
            }

            $this->db->delete('files', 'id = ? AND user_id = ?', [$child['id'], $userId]);
        }
    }

    private function moveToTrash($file)
    {
        $config = Config::getInstance();
        $now = time();
        $expireAt = $now + ($config->get('trash_retention_days') * 24 * 3600);

        $this->db->insert('trash', [
            'user_id' => $file['user_id'],
            'file_id' => $file['id'],
            'filename' => $file['filename'],
            'filepath' => $file['filepath'],
            'filesize' => $file['filesize'],
            'file_type' => $file['file_type'],
            'mime_type' => $file['mime_type'],
            'is_dir' => $file['is_dir'],
            'parent_id' => $file['parent_id'],
            'original_path' => $file['filepath'],
            'deleted_at' => $now,
            'expire_at' => $expireAt,
        ]);

        $fullPath = FILES_PATH . DIRECTORY_SEPARATOR . $file['filepath'];
        $trashBase = $file['id'] . '_' . basename($file['filepath']);
        // 避免文件名 + ID 前缀超过 255 字符的 filesystem 限制
        if (strlen($trashBase) > 255) {
            $trashBase = substr($trashBase, 0, 255);
        }
        $trashPath = TRASH_PATH . DIRECTORY_SEPARATOR . $trashBase;

        if ($file['is_dir']) {
            if (is_dir($fullPath)) {
                $trashDir = dirname($trashPath);
                if (!is_dir($trashDir)) {
                    mkdir($trashDir, 0755, true);
                }
                if (!rename($fullPath, $trashPath)) {
                    throw new \Exception('移动文件到回收站失败');
                }
            }
        } else {
            if (file_exists($fullPath)) {
                $trashDir = dirname($trashPath);
                if (!is_dir($trashDir)) {
                    mkdir($trashDir, 0755, true);
                }
                if (!rename($fullPath, $trashPath)) {
                    throw new \Exception('移动文件到回收站失败');
                }
            }
        }

        $this->db->invalidateTableCache("files");

        return ['success' => true, 'message' => '文件已移至回收站'];
    }

    public function renameFile($fileId, $newName)
    {
        $userId = $this->auth->getUserId();
        $file = $this->db->fetch("SELECT * FROM files WHERE id = ? AND user_id = ?", [$fileId, $userId]);

        if (!$file) {
            return ['success' => false, 'message' => '文件不存在'];
        }

        if (!empty($file['is_locked'])) {
            return ['success' => false, 'message' => '文件已锁定，无法重命名'];
        }

        $newName = Security::sanitizeFilename($newName);
        if (empty($newName)) {
            return ['success' => false, 'message' => '文件名不能为空'];
        }

        if ($newName === $file['filename']) {
            return ['success' => true, 'message' => '文件名未改变'];
        }

        $existing = $this->db->fetch(
            "SELECT id FROM files WHERE user_id = ? AND parent_id = ? AND filename = ? AND id != ?",
            [$userId, $file['parent_id'], $newName, $fileId]
        );

        if ($existing) {
            return ['success' => false, 'message' => '同名文件已存在'];
        }

        $oldPath = FILES_PATH . DIRECTORY_SEPARATOR . $file['filepath'];
        $parent = $this->getFileById($file['parent_id']);
        $parentPath = $parent ? $parent['filepath'] : '';
        $newFilePath = $parentPath ? $parentPath . DIRECTORY_SEPARATOR . $newName : $newName;
        $newPath = FILES_PATH . DIRECTORY_SEPARATOR . $newFilePath;

        if (file_exists($oldPath)) {
            rename($oldPath, $newPath);
        }

        $this->db->update('files', [
            'filename' => $newName,
            'filepath' => $newFilePath,
            'path_hash' => md5($newFilePath),
            'updated_at' => time(),
        ], 'id = ? AND user_id = ?', [$fileId, $userId]);

        if ($file['is_dir']) {
            $this->updateChildPaths($fileId, $file['filepath'], $newFilePath);
        }

        $this->logOperation('rename', $file['filename'] . ' -> ' . $newName);

        $this->db->invalidateTableCache("files");

        return ['success' => true, 'message' => '重命名成功'];
    }

    private function updateChildPaths($parentId, $oldBase, $newBase)
    {
        $userId = $this->auth->getUserId();
        $children = $this->db->fetchAll("SELECT * FROM files WHERE parent_id = ? AND user_id = ?", [$parentId, $userId]);

        foreach ($children as $child) {
            $oldChildPath = $child['filepath'];
            if (strpos($oldChildPath, $oldBase) === 0) {
                $newChildPath = $newBase . substr($oldChildPath, strlen($oldBase));
            } else {
                $newChildPath = $newBase . DIRECTORY_SEPARATOR . basename($oldChildPath);
            }

            $this->db->update('files', [
                'filepath' => $newChildPath,
                'path_hash' => md5($newChildPath),
                'updated_at' => time(),
            ], 'id = ? AND user_id = ?', [$child['id'], $userId]);

            $oldFullPath = FILES_PATH . DIRECTORY_SEPARATOR . $oldChildPath;
            $newFullPath = FILES_PATH . DIRECTORY_SEPARATOR . $newChildPath;
            if (file_exists($oldFullPath)) {
                $newDir = dirname($newFullPath);
                if (!is_dir($newDir)) {
                    mkdir($newDir, 0755, true);
                }
                rename($oldFullPath, $newFullPath);
            }

            if ($child['is_dir']) {
                $this->updateChildPaths($child['id'], $oldChildPath, $newChildPath);
            }
        }
    }

    public function moveFile($fileId, $targetParentId)
    {
        $userId = $this->auth->getUserId();
        $file = $this->db->fetch("SELECT * FROM files WHERE id = ? AND user_id = ?", [$fileId, $userId]);

        if (!$file) {
            return ['success' => false, 'message' => '文件不存在'];
        }

        if ($file['is_locked']) {
            return ['success' => false, 'message' => '文件已锁定，无法移动'];
        }

        if ($fileId == $targetParentId) {
            return ['success' => false, 'message' => '不能将文件夹移动到自身'];
        }

        if ($file['parent_id'] == $targetParentId) {
            return ['success' => false, 'message' => '文件已在目标目录中'];
        }

        $targetValid = $this->validateTargetDirectory($targetParentId);
        if (!$targetValid['success']) {
            return $targetValid;
        }

        if ($file['is_dir'] && $targetParentId > 0) {
            if ($this->isDescendantOf($targetParentId, $fileId, $userId)) {
                return ['success' => false, 'message' => '不能将文件夹移动到其子文件夹中'];
            }
        }

        $existing = $this->db->fetch(
            "SELECT id FROM files WHERE user_id = ? AND parent_id = ? AND filename = ? AND id != ?",
            [$userId, $targetParentId, $file['filename'], $fileId]
        );

        if ($existing) {
            return ['success' => false, 'message' => '目标文件夹中已存在同名文件'];
        }

        $targetParent = $targetParentId > 0 ? $this->getFileById($targetParentId) : null;
        $targetParentPath = $targetParent ? $targetParent['filepath'] : '';
        $newFilePath = $targetParentPath ? $targetParentPath . DIRECTORY_SEPARATOR . $file['filename'] : $file['filename'];

        $oldFullPath = FILES_PATH . DIRECTORY_SEPARATOR . $file['filepath'];
        $newFullPath = FILES_PATH . DIRECTORY_SEPARATOR . $newFilePath;

        if (file_exists($oldFullPath)) {
            $newDir = dirname($newFullPath);
            if (!is_dir($newDir)) {
                mkdir($newDir, 0755, true);
            }
            if (!rename($oldFullPath, $newFullPath)) {
                return ['success' => false, 'message' => '文件移动失败'];
            }
        }

        $oldFilePath = $file['filepath'];
        $this->db->update('files', [
            'parent_id' => $targetParentId,
            'filepath' => $newFilePath,
            'path_hash' => md5($newFilePath),
            'updated_at' => time(),
        ], 'id = ? AND user_id = ?', [$fileId, $userId]);

        if ($file['is_dir']) {
            $this->updateChildPaths($fileId, $oldFilePath, $newFilePath);
        }

        $this->logOperation('move', $file['filename']);
        $this->db->invalidateTableCache("files");

        return ['success' => true, 'message' => '移动成功'];
    }

    private function isDescendantOf($potentialDescendantId, $ancestorId, $userId)
    {
        $current = $potentialDescendantId;
        $maxDepth = 100;
        $depth = 0;
        while ($current > 0 && $depth < $maxDepth) {
            $parent = $this->db->fetch("SELECT parent_id FROM files WHERE id = ? AND user_id = ?", [$current, $userId]);
            if (!$parent) {
                break;
            }
            if ($parent['parent_id'] == $ancestorId) {
                return true;
            }
            $current = $parent['parent_id'];
            $depth++;
        }

        return false;
    }

    public function copyFile($fileId, $targetParentId)
    {
        $userId = $this->auth->getUserId();
        $file = $this->db->fetch("SELECT * FROM files WHERE id = ? AND user_id = ?", [$fileId, $userId]);

        if (!$file) {
            return ['success' => false, 'message' => '文件不存在'];
        }

        if ($file['is_locked']) {
            return ['success' => false, 'message' => '文件已锁定，无法复制'];
        }

        if ($fileId == $targetParentId) {
            return ['success' => false, 'message' => '不能将文件夹复制到自身'];
        }

        $targetValid = $this->validateTargetDirectory($targetParentId);
        if (!$targetValid['success']) {
            return $targetValid;
        }

        if ($file['is_dir']) {
            if ($targetParentId > 0 && $this->isDescendantOf($targetParentId, $fileId, $userId)) {
                return ['success' => false, 'message' => '不能将文件夹复制到其子文件夹中'];
            }
            return $this->copyFolderRecursive($file, $targetParentId, $userId);
        }

        return $this->copySingleFile($file, $targetParentId, $userId);
    }

    private function copySingleFile($file, $targetParentId, $userId)
    {
        $storageCheck = $this->auth->checkStorageLimit($file['filesize']);
        if (!$storageCheck['status']) {
            return ['success' => false, 'message' => $storageCheck['message']];
        }

        $newFilename = $this->getUniqueFilename($userId, $targetParentId, $file['filename']);
        $newFilePath = $this->buildTargetPath($targetParentId, $newFilename);

        $oldFullPath = FILES_PATH . DIRECTORY_SEPARATOR . $file['filepath'];
        $newFullPath = FILES_PATH . DIRECTORY_SEPARATOR . $newFilePath;

        if (!file_exists($oldFullPath)) {
            return ['success' => false, 'message' => '源文件不存在'];
        }

        $newDir = dirname($newFullPath);
        if (!is_dir($newDir)) {
            mkdir($newDir, 0755, true);
        }
        if (!@copy($oldFullPath, $newFullPath)) {
            return ['success' => false, 'message' => '文件复制失败'];
        }

        $now = time();
        $this->db->insert('files', [
            'user_id' => $userId,
            'filename' => $newFilename,
            'filepath' => $newFilePath,
            'filesize' => $file['filesize'],
            'file_type' => $file['file_type'],
            'mime_type' => $file['mime_type'],
            'is_dir' => 0,
            'parent_id' => $targetParentId,
            'path_hash' => md5($newFilePath),
            'content_hash' => $file['content_hash'] ?? '',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->auth->updateStorageUsed($file['filesize'], true);
        $this->logOperation('copy', $file['filename']);
        $this->db->invalidateTableCache("files");

        return ['success' => true, 'message' => '复制成功'];
    }

    private function copyFolderRecursive($folder, $targetParentId, $userId)
    {
        $totalFiles = $this->countFilesInFolder($folder['id'], $userId);
        $totalSize = $this->calculateFolderSize($folder['id'], $userId) + $folder['filesize'];

        $storageCheck = $this->auth->checkStorageLimit($totalSize);
        if (!$storageCheck['status']) {
            return ['success' => false, 'message' => $storageCheck['message']];
        }

        $newFolderName = $this->getUniqueFilename($userId, $targetParentId, $folder['filename']);
        $newFolderPath = $this->buildTargetPath($targetParentId, $newFolderName);
        $newFullPath = FILES_PATH . DIRECTORY_SEPARATOR . $newFolderPath;

        if (!mkdir($newFullPath, 0755, true) && !is_dir($newFullPath)) {
            return ['success' => false, 'message' => '创建目标文件夹失败'];
        }

        $now = time();
        $newFolderId = $this->db->insert('files', [
            'user_id' => $userId,
            'filename' => $newFolderName,
            'filepath' => $newFolderPath,
            'filesize' => 0,
            'file_type' => 'folder',
            'mime_type' => '',
            'is_dir' => 1,
            'parent_id' => $targetParentId,
            'path_hash' => md5($newFolderPath),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $result = $this->copyFolderContents($folder['id'], $newFolderId, $userId);
        if (!$result['success']) {
            if (is_dir($newFullPath)) {
                $this->removeDirRecursive($newFullPath);
            }
            $this->db->delete('files', 'id = ? AND user_id = ?', [$newFolderId, $userId]);
            return $result;
        }

        $this->auth->updateStorageUsed($result['total_size'], true);
        $this->logOperation('copy', $folder['filename'] . '（含' . $result['file_count'] . '个子项）');
        $this->db->invalidateTableCache("files");

        return ['success' => true, 'message' => '文件夹复制成功（' . $result['file_count'] . '个子项）'];
    }

    private function copyFolderContents($sourceFolderId, $targetFolderId, $userId)
    {
        $children = $this->db->fetchAll(
            "SELECT * FROM files WHERE parent_id = ? AND user_id = ?",
            [$sourceFolderId, $userId]
        );

        $totalSize = 0;
        $fileCount = 0;

        foreach ($children as $child) {
            if ($child['is_dir']) {
                $newChildName = $this->getUniqueFilename($userId, $targetFolderId, $child['filename']);
                $newChildPath = $this->buildTargetPath($targetFolderId, $newChildName);
                $newFullPath = FILES_PATH . DIRECTORY_SEPARATOR . $newChildPath;

                if (!mkdir($newFullPath, 0755, true) && !is_dir($newFullPath)) {
                    return ['success' => false, 'message' => '创建目标子文件夹失败'];
                }

                $now = time();
                $newChildId = $this->db->insert('files', [
                    'user_id' => $userId,
                    'filename' => $newChildName,
                    'filepath' => $newChildPath,
                    'filesize' => 0,
                    'file_type' => 'folder',
                    'mime_type' => '',
                    'is_dir' => 1,
                    'parent_id' => $targetFolderId,
                    'path_hash' => md5($newChildPath),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $subResult = $this->copyFolderContents($child['id'], $newChildId, $userId);
                if (!$subResult['success']) {
                    return $subResult;
                }
                $totalSize += $subResult['total_size'];
                $fileCount += $subResult['file_count'] + 1;
            } else {
                $newFilename = $this->getUniqueFilename($userId, $targetFolderId, $child['filename']);
                $newFilePath = $this->buildTargetPath($targetFolderId, $newFilename);
                $newFullPath = FILES_PATH . DIRECTORY_SEPARATOR . $newFilePath;
                $oldFullPath = FILES_PATH . DIRECTORY_SEPARATOR . $child['filepath'];

                if (!file_exists($oldFullPath)) {
                    continue;
                }

                $newDir = dirname($newFullPath);
                if (!is_dir($newDir)) {
                    mkdir($newDir, 0755, true);
                }
                if (!@copy($oldFullPath, $newFullPath)) {
                    return ['success' => false, 'message' => '复制文件失败：' . $child['filename']];
                }

                $now = time();
                $this->db->insert('files', [
                    'user_id' => $userId,
                    'filename' => $newFilename,
                    'filepath' => $newFilePath,
                    'filesize' => $child['filesize'],
                    'file_type' => $child['file_type'],
                    'mime_type' => $child['mime_type'],
                    'is_dir' => 0,
                    'parent_id' => $targetFolderId,
                    'path_hash' => md5($newFilePath),
                    'content_hash' => $child['content_hash'] ?? '',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $totalSize += $child['filesize'];
                $fileCount++;
            }
        }

        return ['success' => true, 'total_size' => $totalSize, 'file_count' => $fileCount];
    }

    private function buildTargetPath($targetParentId, $filename)
    {
        if ($targetParentId <= 0) {
            return $filename;
        }
        $targetParent = $this->getFileById($targetParentId);
        $targetParentPath = $targetParent ? $targetParent['filepath'] : '';
        return $targetParentPath ? $targetParentPath . DIRECTORY_SEPARATOR . $filename : $filename;
    }

    private function countFilesInFolder($folderId, $userId)
    {
        $count = 0;
        $children = $this->db->fetchAll(
            "SELECT id, is_dir FROM files WHERE parent_id = ? AND user_id = ?",
            [$folderId, $userId]
        );
        foreach ($children as $child) {
            $count++;
            if ($child['is_dir']) {
                $count += $this->countFilesInFolder($child['id'], $userId);
            }
        }
        return $count;
    }

    private function calculateFolderSize($folderId, $userId)
    {
        $size = 0;
        $children = $this->db->fetchAll(
            "SELECT id, is_dir, filesize FROM files WHERE parent_id = ? AND user_id = ?",
            [$folderId, $userId]
        );
        foreach ($children as $child) {
            if ($child['is_dir']) {
                $size += $this->calculateFolderSize($child['id'], $userId);
            } else {
                $size += $child['filesize'];
            }
        }
        return $size;
    }

    public function batchCopyItems($fileIds, $targetParentId)
    {
        $userId = $this->auth->getUserId();
        $targetValid = $this->validateTargetDirectory($targetParentId);
        if (!$targetValid['success']) {
            return ['success' => false, 'message' => $targetValid['message']];
        }

        $successCount = 0;
        $failCount = 0;
        $errors = [];
        $totalCopied = 0;

        foreach ($fileIds as $fileId) {
            $result = $this->copyFile(intval($fileId), $targetParentId);
            if ($result['success']) {
                $successCount++;
                $totalCopied += isset($result['file_count']) ? $result['file_count'] + 1 : 1;
            } else {
                $failCount++;
                $errors[] = $result['message'];
            }
        }

        return [
            'success' => $successCount > 0,
            'message' => $failCount === 0
                ? "批量复制完成：{$successCount} 项成功（共{$totalCopied}个文件）"
                : "批量复制完成：{$successCount} 项成功，{$failCount} 项失败",
            'succeeded' => $successCount,
            'failed' => $failCount,
            'total_files' => $totalCopied,
            'errors' => array_slice($errors, 0, 10),
        ];
    }

    public function batchMoveItems($fileIds, $targetParentId)
    {
        $userId = $this->auth->getUserId();
        $targetValid = $this->validateTargetDirectory($targetParentId);
        if (!$targetValid['success']) {
            return ['success' => false, 'message' => $targetValid['message']];
        }

        $successCount = 0;
        $failCount = 0;
        $errors = [];

        foreach ($fileIds as $fileId) {
            $result = $this->moveFile(intval($fileId), $targetParentId);
            if ($result['success']) {
                $successCount++;
            } else {
                $failCount++;
                $errors[] = $result['message'];
            }
        }

        return [
            'success' => $successCount > 0,
            'message' => $failCount === 0
                ? "批量移动完成：{$successCount} 项成功"
                : "批量移动完成：{$successCount} 项成功，{$failCount} 项失败",
            'succeeded' => $successCount,
            'failed' => $failCount,
            'errors' => array_slice($errors, 0, 10),
        ];
    }

    public function validateTargetDirectory($targetParentId)
    {
        if ($targetParentId <= 0) {
            return ['success' => true];
        }
        $userId = $this->auth->getUserId();
        $target = $this->db->fetch(
            "SELECT id, is_dir FROM files WHERE id = ? AND user_id = ?",
            [$targetParentId, $userId]
        );
        if (!$target) {
            return ['success' => false, 'message' => '目标文件夹不存在'];
        }
        if (!$target['is_dir']) {
            return ['success' => false, 'message' => '目标不是有效的文件夹'];
        }
        return ['success' => true];
    }

    public function toggleFavorite($fileId)
    {
        $userId = $this->auth->getUserId();
        $file = $this->db->fetch("SELECT * FROM files WHERE id = ? AND user_id = ?", [$fileId, $userId]);

        if (!$file) {
            return ['success' => false, 'message' => '文件不存在'];
        }

        $newStatus = $file['is_favorite'] ? 0 : 1;
        $this->db->update('files', ['is_favorite' => $newStatus, 'updated_at' => time()], 'id = ? AND user_id = ?', [$fileId, $userId]);

        $this->db->invalidateTableCache("files");

        return ['success' => true, 'message' => $newStatus ? '已添加收藏' : '已取消收藏', 'is_favorite' => $newStatus];
    }

    public function getFavorites($page = 1, $pageSize = 50)
    {
        $userId = $this->auth->getUserId();
        $offset = ($page - 1) * $pageSize;

        $files = $this->db->fetchCached(
            "SELECT * FROM files WHERE user_id = ? AND is_favorite = 1
             ORDER BY created_at DESC LIMIT ? OFFSET ?",
            [$userId, $pageSize, $offset],
            ['files', 'user:' . $userId]
        );

        foreach ($files as &$file) {
            $file['filesize_formatted'] = Security::formatSize($file['filesize']);
            $file['created_at_formatted'] = Security::formatTime($file['created_at']);
            $file['icon'] = $this->getFileIcon($file);
            $file['tags'] = $this->parseTags($file['tags'] ?? '');

            if (!$file['is_dir'] && $this->hasThumbnailSupport($file['file_type'])) {
                $file['thumbnail_url'] = 'index.php?action=thumbnail&file_id=' . $file['id'];
            } else {
                $file['thumbnail_url'] = null;
            }
        }

        return $files;
    }

    public function getFavoritesCount()
    {
        $userId = $this->auth->getUserId();
        $result = $this->db->fetch(
            "SELECT COUNT(*) as count FROM files WHERE user_id = ? AND is_favorite = 1",
            [$userId]
        );
        return $result['count'];
    }

    public function searchFiles($keyword, $type = 'all', $page = 1, $pageSize = 50, $sortBy = 'name', $sortOrder = 'asc')
    {
        $userId = $this->auth->getUserId();
        $offset = ($page - 1) * $pageSize;

        if (strlen($keyword) >= 3) {
            $files = $this->search->search($keyword, $userId, $pageSize, $offset);
        } else {
            $files = $this->searchFilesLegacy($keyword, $type, $userId, $pageSize, $offset);
        }

        if (empty($files)) {
            $files = $this->searchFilesLegacy($keyword, $type, $userId, $pageSize, $offset);
        }

        return $this->formatFilesResult($files);
    }

    public function getSearchCount($keyword, $type = 'all')
    {
        $userId = $this->auth->getUserId();

        if (strlen($keyword) >= 3) {
            $count = $this->search->searchCount($keyword, $userId);
            if ($count > 0) {
                return $count;
            }
        }

        return $this->getSearchCountLegacy($keyword, $type, $userId);
    }

    private function searchFilesLegacy($keyword, $type, $userId, $pageSize, $offset)
    {
        $keyword = '%' . $keyword . '%';
        $sql = "SELECT * FROM files WHERE user_id = ? AND filename LIKE ?";
        $params = [$userId, $keyword];

        if ($type !== 'all') {
            $typeMap = [
                'image' => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'ico', 'tiff', 'tif'],
                'video' => ['mp4', 'avi', 'mkv', 'mov', 'wmv', 'flv', 'webm'],
                'audio' => ['mp3', 'wav', 'flac', 'aac', 'ogg', 'wma', 'm4a', 'aiff', 'aif', 'opus', 'ape', 'alac', 'ra', 'ram', 'ac3', 'amr', 'mid', 'midi'],
                'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'md'],
                'archive' => ['zip', 'rar', '7z', 'tar', 'gz'],
            ];

            if (isset($typeMap[$type])) {
                $placeholders = implode(',', array_fill(0, count($typeMap[$type]), '?'));
                $sql .= " AND file_type IN ({$placeholders})";
                $params = array_merge($params, $typeMap[$type]);
            }
        }

        $sql .= " ORDER BY filename LIMIT ? OFFSET ?";
        $params = array_merge($params, [$pageSize, $offset]);

        return $this->db->fetchCached($sql, $params, ['files']);
    }

    private function getSearchCountLegacy($keyword, $type, $userId)
    {
        $keyword = '%' . $keyword . '%';
        $sql = "SELECT COUNT(*) as count FROM files WHERE user_id = ? AND filename LIKE ?";
        $params = [$userId, $keyword];

        if ($type !== 'all') {
            $typeMap = [
                'image' => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'ico', 'tiff', 'tif'],
                'video' => ['mp4', 'avi', 'mkv', 'mov', 'wmv', 'flv', 'webm'],
                'audio' => ['mp3', 'wav', 'flac', 'aac', 'ogg', 'wma', 'm4a', 'aiff', 'aif', 'opus', 'ape', 'alac', 'ra', 'ram', 'ac3', 'amr', 'mid', 'midi'],
                'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'md'],
                'archive' => ['zip', 'rar', '7z', 'tar', 'gz'],
            ];

            if (isset($typeMap[$type])) {
                $placeholders = implode(',', array_fill(0, count($typeMap[$type]), '?'));
                $sql .= " AND file_type IN ({$placeholders})";
                $params = array_merge($params, $typeMap[$type]);
            }
        }

        $result = $this->db->fetch($sql, $params);
        return $result['count'];
    }

    private function formatFilesResult($files)
    {
        foreach ($files as &$file) {
            $file['filesize_formatted'] = Security::formatSize($file['filesize']);
            $file['created_at_formatted'] = Security::formatTime($file['created_at']);
            $file['icon'] = $this->getFileIcon($file);
            $file['tags'] = $this->parseTags($file['tags'] ?? '');

            if (!$file['is_dir'] && $this->hasThumbnailSupport($file['file_type'])) {
                $file['thumbnail_url'] = 'index.php?action=thumbnail&file_id=' . $file['id'];
            } else {
                $file['thumbnail_url'] = null;
            }
        }

        return $files;
    }

    public function getFileById($fileId)
    {
        $userId = $this->auth->getUserId();
        $file = $this->db->fetch("SELECT * FROM files WHERE id = ? AND user_id = ?", [$fileId, $userId]);

        if ($file) {
            $file['filesize_formatted'] = Security::formatSize($file['filesize']);
            $file['created_at_formatted'] = Security::formatTime($file['created_at']);
            $file['updated_at_formatted'] = Security::formatTime($file['updated_at']);
            $file['icon'] = $this->getFileIcon($file);
            $file['tags'] = $this->parseTags($file['tags'] ?? '');
        }

        return $file;
    }

    public function getBreadcrumb($parentId)
    {
        if ($parentId <= 0) {
            return [];
        }

        $userId = $this->auth->getUserId();

        // 递归 CTE 一次查出完整面包屑路径，避免按层多次查询
        $rows = $this->db->fetchAll(
            "WITH RECURSIVE path(id, filename, parent_id, lvl) AS (
                SELECT id, filename, parent_id, 0 FROM files WHERE id = ? AND user_id = ?
                UNION ALL
                SELECT f.id, f.filename, f.parent_id, p.lvl + 1 FROM files f
                INNER JOIN path p ON f.id = p.parent_id
                WHERE f.user_id = ?
            )
            SELECT id, filename, parent_id FROM path WHERE id > 0 ORDER BY lvl DESC",
            [$parentId, $userId, $userId]
        );

        return $rows;
    }

    public function getAllFoldersTree()
    {
        $userId = $this->auth->getUserId();
        $folders = $this->db->fetchAll(
            "SELECT id, filename, parent_id FROM files WHERE user_id = ? AND is_dir = 1 ORDER BY parent_id, filename",
            [$userId]
        );

        $tree = [];
        $map = [];

        foreach ($folders as $folder) {
            $map[$folder['id']] = $folder;
            $map[$folder['id']]['children'] = [];
        }

        foreach ($map as $id => $folder) {
            if ($folder['parent_id'] == 0) {
                $tree[] = &$map[$id];
            } else {
                if (isset($map[$folder['parent_id']])) {
                    $map[$folder['parent_id']]['children'][] = &$map[$id];
                }
            }
        }

        return $tree;
    }

    public function getStorageInfo()
    {
        $user = $this->auth->getUser();
        if (!$user) return null;

        $used = $this->auth->getStorageUsed();
        $total = $user['storage_limit'];

        return [
            'used' => $used,
            'total' => $total,
            'used_formatted' => Security::formatSize($used),
            'total_formatted' => Security::formatSize($total),
            'percentage' => $total > 0 ? round(($used / $total) * 100, 1) : 0,
        ];
    }

    public function getFileStats()
    {
        $userId = $this->auth->getUserId();

        $totalFiles = $this->db->fetch("SELECT COUNT(*) as count FROM files WHERE user_id = ? AND is_dir = 0", [$userId]);
        $totalFolders = $this->db->fetch("SELECT COUNT(*) as count FROM files WHERE user_id = ? AND is_dir = 1", [$userId]);
        $totalShares = $this->db->fetch("SELECT COUNT(*) as count FROM shares WHERE user_id = ? AND is_active = 1", [$userId]);
        $trashCount = $this->db->fetch("SELECT COUNT(*) as count FROM trash WHERE user_id = ?", [$userId]);

        $typeStats = $this->db->fetchAll(
            "SELECT file_type, COUNT(*) as count, SUM(filesize) as total_size FROM files WHERE user_id = ? AND is_dir = 0 GROUP BY file_type ORDER BY total_size DESC LIMIT 10",
            [$userId]
        );

        return [
            'total_files' => $totalFiles['count'],
            'total_folders' => $totalFolders['count'],
            'total_shares' => $totalShares['count'],
            'trash_count' => $trashCount['count'],
            'type_stats' => $typeStats,
        ];
    }

    public function updateTags($fileId, $tags)
    {
        $userId = $this->auth->getUserId();
        $file = $this->db->fetch("SELECT * FROM files WHERE id = ? AND user_id = ?", [$fileId, $userId]);

        if (!$file) {
            return ['success' => false, 'message' => '文件不存在'];
        }

        $serializedTags = $this->serializeTags($tags);

        $this->db->update('files', [
            'tags' => $serializedTags,
            'updated_at' => time(),
        ], 'id = ? AND user_id = ?', [$fileId, $userId]);

        $this->logOperation('update_tags', $file['filename'] . ' [' . $serializedTags . ']');

        $this->db->invalidateTableCache("files");

        return ['success' => true, 'message' => '标签已更新', 'tags' => $this->parseTags($serializedTags)];
    }

    public function updateDescription($fileId, $description)
    {
        $userId = $this->auth->getUserId();
        $this->db->update('files', ['description' => $description, 'updated_at' => time()], 'id = ? AND user_id = ?', [$fileId, $userId]);
        return ['success' => true, 'message' => '描述已更新'];
    }

    public function encryptFile($fileId)
    {
        $userId = $this->auth->getUserId();
        $encKey = $this->auth->getEncryptionKey();
        if (!$encKey) return ['success' => false, 'message' => '加密密钥不可用，请重新登录'];

        $file = $this->db->fetch("SELECT * FROM files WHERE id = ? AND user_id = ?", [$fileId, $userId]);
        if (!$file) return ['success' => false, 'message' => '文件不存在'];
        if ($file['is_dir']) return ['success' => false, 'message' => '文件夹不支持加密'];
        if (!empty($file['is_encrypted'])) return ['success' => false, 'message' => '文件已加密'];

        $fullPath = FILES_PATH . DIRECTORY_SEPARATOR . $file['filepath'];
        if (!file_exists($fullPath)) return ['success' => false, 'message' => '文件不存在'];

        $tmpPath = $fullPath . '.enc_tmp';
        $fpIn = fopen($fullPath, 'rb');
        if ($fpIn === false) return ['success' => false, 'message' => '读取文件失败'];
        $fpOut = fopen($tmpPath, 'wb');
        if ($fpOut === false) {
            fclose($fpIn);
            return ['success' => false, 'message' => '创建临时文件失败'];
        }

        $iv = random_bytes(16);
        fwrite($fpOut, $iv);

        $oldSize = filesize($fullPath);
        $blockSize = 16; // AES block size
        $bufferSize = 65536;
        // 保证每次读取长度是 blockSize 的整数倍，避免 openssl_encrypt 内部填充导致流式不一致
        $readSize = (int) (floor($bufferSize / $blockSize) * $blockSize);

        while (!feof($fpIn)) {
            $chunk = fread($fpIn, $readSize);
            if ($chunk === false) break;
            if ($chunk === '') continue;

            $isLastChunk = feof($fpIn);
            $options = $isLastChunk ? (OPENSSL_RAW_DATA) : (OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
            
            $encrypted = openssl_encrypt($chunk, 'AES-256-CBC', $encKey, $options, $iv);
            if ($encrypted === false) {
                fclose($fpIn);
                fclose($fpOut);
                @unlink($tmpPath);
                return ['success' => false, 'message' => '加密失败'];
            }
            fwrite($fpOut, $encrypted);
            // CBC 模式下一轮的 IV 是上一块密文的最后 16 字节
            $iv = substr($encrypted, -$blockSize);
        }

        fclose($fpIn);
        fclose($fpOut);

        if (!rename($tmpPath, $fullPath)) {
            @unlink($tmpPath);
            return ['success' => false, 'message' => '写入加密文件失败'];
        }

        $newSize = filesize($fullPath);

        $this->db->update('files', [
            'is_encrypted' => 1,
            'filesize' => $newSize,
            'updated_at' => time(),
        ], 'id = ? AND user_id = ?', [$fileId, $userId]);

        $this->auth->updateStorageUsed($newSize - $oldSize, true);
        $this->db->invalidateTableCache("files");

        return ['success' => true, 'message' => '文件已加密'];
    }

    public function decryptFile($fileId)
    {
        $userId = $this->auth->getUserId();
        $encKey = $this->auth->getEncryptionKey();
        if (!$encKey) return ['success' => false, 'message' => '加密密钥不可用，请重新登录'];

        $file = $this->db->fetch("SELECT * FROM files WHERE id = ? AND user_id = ?", [$fileId, $userId]);
        if (!$file) return ['success' => false, 'message' => '文件不存在'];
        if (empty($file['is_encrypted'])) return ['success' => false, 'message' => '文件未加密'];

        $fullPath = FILES_PATH . DIRECTORY_SEPARATOR . $file['filepath'];
        if (!file_exists($fullPath)) return ['success' => false, 'message' => '文件不存在'];

        $tmpPath = $fullPath . '.dec_tmp';
        $fpIn = fopen($fullPath, 'rb');
        if ($fpIn === false) return ['success' => false, 'message' => '读取文件失败'];
        $fpOut = fopen($tmpPath, 'wb');
        if ($fpOut === false) {
            fclose($fpIn);
            return ['success' => false, 'message' => '创建临时文件失败'];
        }

        $iv = fread($fpIn, 16);
        if ($iv === false || strlen($iv) < 16) {
            fclose($fpIn);
            fclose($fpOut);
            @unlink($tmpPath);
            return ['success' => false, 'message' => '读取文件失败'];
        }

        $oldSize = filesize($fullPath);
        $blockSize = 16;
        $bufferSize = 65536;
        $readSize = (int) (floor($bufferSize / $blockSize) * $blockSize);

        while (!feof($fpIn)) {
            $chunk = fread($fpIn, $readSize);
            if ($chunk === false) break;
            if ($chunk === '') continue;

            $isLastChunk = feof($fpIn);
            $options = $isLastChunk ? (OPENSSL_RAW_DATA) : (OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
            
            $decrypted = openssl_decrypt($chunk, 'AES-256-CBC', $encKey, $options, $iv);
            if ($decrypted === false) {
                fclose($fpIn);
                fclose($fpOut);
                @unlink($tmpPath);
                return ['success' => false, 'message' => '解密失败，密钥可能不正确'];
            }
            fwrite($fpOut, $decrypted);
            // CBC 模式下一轮的 IV 是上一块密文的最后 16 字节
            $iv = substr($chunk, -$blockSize);
        }

        fclose($fpIn);
        fclose($fpOut);

        if (!rename($tmpPath, $fullPath)) {
            @unlink($tmpPath);
            return ['success' => false, 'message' => '写入解密文件失败'];
        }

        $newSize = filesize($fullPath);

        $this->db->update('files', [
            'is_encrypted' => 0,
            'filesize' => $newSize,
            'updated_at' => time(),
        ], 'id = ? AND user_id = ?', [$fileId, $userId]);

        $this->auth->updateStorageUsed($oldSize - $newSize, false);
        $this->db->invalidateTableCache("files");

        return ['success' => true, 'message' => '文件已解密'];
    }

    public function decryptFileToTemp($fileId)
    {
        $encKey = $this->auth->getEncryptionKey();
        if (!$encKey) return null;

        $file = $this->db->fetch("SELECT * FROM files WHERE id = ? AND user_id = ?", [$fileId, $this->auth->getUserId()]);
        if (!$file || empty($file['is_encrypted'])) return null;

        $fullPath = FILES_PATH . DIRECTORY_SEPARATOR . $file['filepath'];
        if (!file_exists($fullPath)) return null;

        $fpIn = fopen($fullPath, 'rb');
        if ($fpIn === false) return null;

        $iv = fread($fpIn, 16);
        if ($iv === false || strlen($iv) < 16) {
            fclose($fpIn);
            return null;
        }

        $tempPath = UPLOAD_PATH . DIRECTORY_SEPARATOR . 'dec_' . $fileId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $file['file_type'];
        $fpOut = fopen($tempPath, 'wb');
        if ($fpOut === false) {
            fclose($fpIn);
            return null;
        }

        $blockSize = 16;
        $bufferSize = 65536;
        $readSize = (int) (floor($bufferSize / $blockSize) * $blockSize);

        while (!feof($fpIn)) {
            $chunk = fread($fpIn, $readSize);
            if ($chunk === false) break;
            if ($chunk === '') continue;

            $isLastChunk = feof($fpIn);
            $options = $isLastChunk ? (OPENSSL_RAW_DATA) : (OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
            
            $decrypted = openssl_decrypt($chunk, 'AES-256-CBC', $encKey, $options, $iv);
            if ($decrypted === false) {
                fclose($fpIn);
                fclose($fpOut);
                @unlink($tempPath);
                return null;
            }
            fwrite($fpOut, $decrypted);
            // CBC 模式下一轮的 IV 是上一块密文的最后 16 字节
            $iv = substr($chunk, -$blockSize);
        }

        fclose($fpIn);
        fclose($fpOut);

        $newSize = filesize($tempPath);
        return ['path' => $tempPath, 'size' => $newSize, 'temp' => true];
    }

    public function recordAccess($fileId)
    {
        $userId = $this->auth->getUserId();
        if (!$userId) return;

        $file = $this->db->fetch("SELECT * FROM files WHERE id = ? AND user_id = ?", [$fileId, $userId]);
        if (!$file) return;

        $this->db->delete('recent_access', 'user_id = ? AND file_id = ?', [$userId, $fileId]);

        $this->db->insert('recent_access', [
            'user_id' => $userId,
            'file_id' => $fileId,
            'filename' => $file['filename'],
            'filepath' => $file['filepath'],
            'filesize' => $file['filesize'],
            'file_type' => $file['file_type'],
            'is_dir' => $file['is_dir'],
            'accessed_at' => time(),
        ]);

        $count = $this->db->fetch("SELECT COUNT(*) as count FROM recent_access WHERE user_id = ?", [$userId]);
        if ($count['count'] > 100) {
            $oldestIds = $this->db->fetchAll(
                "SELECT id FROM recent_access WHERE user_id = ? ORDER BY accessed_at ASC LIMIT ?",
                [$userId, $count['count'] - 100]
            );
            foreach ($oldestIds as $old) {
                $this->db->delete('recent_access', 'id = ?', [$old['id']]);
            }
        }
    }

    public function getRecentAccess()
    {
        $userId = $this->auth->getUserId();

        $items = $this->db->fetchAll(
            "SELECT * FROM recent_access WHERE user_id = ? ORDER BY accessed_at DESC LIMIT 100",
            [$userId]
        );

        foreach ($items as &$item) {
            $item['filesize_formatted'] = Security::formatSize($item['filesize']);
            $item['accessed_at_formatted'] = Security::formatTime($item['accessed_at']);
            $item['icon'] = $this->getFileIcon($item);
            $item['thumbnail_url'] = null;
            if (!$item['is_dir'] && $this->hasThumbnailSupport($item['file_type'])) {
                $item['thumbnail_url'] = 'index.php?action=thumbnail&file_id=' . $item['file_id'];
            }
        }

        return $items;
    }

    private function deleteFileById($fileId, $userId)
    {
        $file = $this->db->fetch("SELECT * FROM files WHERE id = ? AND user_id = ?", [$fileId, $userId]);
        if (!$file) return;

        $fullPath = FILES_PATH . DIRECTORY_SEPARATOR . $file['filepath'];
        if (file_exists($fullPath) && !$file['is_dir']) {
            @unlink($fullPath);
        }

        $this->db->delete('files', 'id = ? AND user_id = ?', [$fileId, $userId]);

        if (!$file['is_dir'] && $file['filesize'] > 0) {
            $this->auth->updateStorageUsed($file['filesize'], false);
        }
    }

    private function getUniqueFilename($userId, $parentId, $filename)
    {
        $lockKey = 'filename_lock_' . $userId . '_' . $parentId;
        $lockFile = DATA_PATH . DIRECTORY_SEPARATOR . md5($lockKey) . '.lock';
        $lockFp = fopen($lockFile, 'c+');

        if ($lockFp && flock($lockFp, LOCK_EX)) {
            try {
                $base = pathinfo($filename, PATHINFO_FILENAME);
                $ext = pathinfo($filename, PATHINFO_EXTENSION);

                // ── 批量查询所有冲突文件名，减少数据库往返 ──
                $pattern = $ext ? $base . ' (%).' . $ext : $base . ' (%)';
                $existingFiles = $this->db->fetchAll(
                    "SELECT filename FROM files WHERE user_id = ? AND parent_id = ? AND (filename = ? OR filename LIKE ?)",
                    [$userId, $parentId, $filename, $pattern]
                );
                $existingNames = array_column($existingFiles, 'filename');

                if (!in_array($filename, $existingNames)) {
                    flock($lockFp, LOCK_UN);
                    fclose($lockFp);
                    @unlink($lockFile);
                    return $filename;
                }

                $counter = 1;
                $newFilename = $filename;

                while (in_array($newFilename, $existingNames)) {
                    $newFilename = $ext ? "{$base} ({$counter}).{$ext}" : "{$base} ({$counter})";
                    $counter++;
                }

                flock($lockFp, LOCK_UN);
                fclose($lockFp);
                @unlink($lockFile);

                return $newFilename;
            } catch (\Exception $e) {
                flock($lockFp, LOCK_UN);
                fclose($lockFp);
                @unlink($lockFile);
                throw $e;
            }
        }

        return $filename . '_' . time() . '_' . bin2hex(random_bytes(4));
    }

    private function getMimeType($filePath)
    {
        if (function_exists('mime_content_type')) {
            $mime = mime_content_type($filePath);
            if ($mime !== false) return $mime;
        }

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $filePath);
            finfo_close($finfo);
            if ($mime) return $mime;
        }

        return 'application/octet-stream';
    }

    private function getFileIcon($file)
    {
        if ($file['is_dir']) return 'folder';

        $ext = strtolower($file['file_type']);
        $iconMap = [
            'folder' => 'folder',
            'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'gif' => 'image', 'bmp' => 'image', 'webp' => 'image', 'svg' => 'image', 'ico' => 'image', 'tiff' => 'image', 'tif' => 'image', 'raw' => 'image', 'cr2' => 'image', 'nef' => 'image', 'arw' => 'image', 'psd' => 'image', 'ai' => 'image', 'sketch' => 'image', 'fig' => 'image', 'xcf' => 'image', 'heic' => 'image', 'heif' => 'image', 'avif' => 'image',
            'mp4' => 'video', 'avi' => 'video', 'mkv' => 'video', 'mov' => 'video', 'wmv' => 'video', 'flv' => 'video', 'webm' => 'video', '3gp' => 'video', 'm4v' => 'video', 'mpg' => 'video', 'mpeg' => 'video', 'ts' => 'video', 'f4v' => 'video', 'ogv' => 'video', 'rm' => 'video', 'rmvb' => 'video', 'vob' => 'video', 'mts' => 'video', 'm2ts' => 'video',
            'mp3' => 'audio', 'wav' => 'audio', 'flac' => 'audio', 'aac' => 'audio', 'ogg' => 'audio', 'wma' => 'audio', 'aiff' => 'audio', 'aif' => 'audio', 'm4a' => 'audio', 'opus' => 'audio', 'ape' => 'audio', 'alac' => 'audio', 'ra' => 'audio', 'ram' => 'audio', 'ac3' => 'audio', 'amr' => 'audio', 'mid' => 'audio', 'midi' => 'audio',
            'pdf' => 'pdf',
            'doc' => 'word', 'docx' => 'word', 'odt' => 'word', 'rtf' => 'word', 'pages' => 'word',
            'xls' => 'excel', 'xlsx' => 'excel', 'ods' => 'excel', 'numbers' => 'excel', 'xlsm' => 'excel',
            'ppt' => 'ppt', 'pptx' => 'ppt', 'odp' => 'ppt', 'key' => 'ppt',
            'txt' => 'text', 'md' => 'text', 'csv' => 'text', 'log' => 'text', 'ini' => 'text', 'cfg' => 'text', 'conf' => 'text', 'srt' => 'text', 'ass' => 'text', 'ssa' => 'text', 'vtt' => 'text', 'nfo' => 'text',
            'json' => 'code', 'xml' => 'code', 'html' => 'code', 'htm' => 'code', 'css' => 'code', 'js' => 'code', 'ts' => 'code', 'jsx' => 'code', 'tsx' => 'code', 'vue' => 'code', 'py' => 'code', 'rb' => 'code', 'java' => 'code', 'c' => 'code', 'cpp' => 'code', 'h' => 'code', 'hpp' => 'code', 'go' => 'code', 'rs' => 'code', 'sql' => 'code', 'sh' => 'code', 'bash' => 'code', 'bat' => 'code', 'ps1' => 'code', 'r' => 'code', 'm' => 'code', 'swift' => 'code', 'kt' => 'code', 'scala' => 'code', 'php' => 'code', 'lua' => 'code', 'pl' => 'code', 'pm' => 'code', 'dart' => 'code', 'yaml' => 'code', 'yml' => 'code', 'toml' => 'code', 'env' => 'code', 'gitignore' => 'code', 'dockerfile' => 'code', 'mdx' => 'code', 'svelte' => 'code', 'astro' => 'code',
            'zip' => 'archive', 'rar' => 'archive', '7z' => 'archive', 'tar' => 'archive', 'gz' => 'archive', 'bz2' => 'archive', 'xz' => 'archive', 'zst' => 'archive', 'cab' => 'archive', 'iso' => 'archive', 'dmg' => 'archive', 'img' => 'archive', 'lz4' => 'archive',
            'exe' => 'app', 'msi' => 'app', 'deb' => 'app', 'rpm' => 'app', 'apk' => 'app', 'appimage' => 'app', 'pkg' => 'app',
            'ttf' => 'font', 'otf' => 'font', 'woff' => 'font', 'woff2' => 'font', 'eot' => 'font',
            'epub' => 'book', 'mobi' => 'book', 'azw3' => 'book', 'fb2' => 'book', 'cbz' => 'book', 'cbr' => 'book', 'djvu' => 'book',
            'torrent' => 'archive',
            '3ds' => 'archive', 'obj' => 'archive', 'stl' => 'archive', 'fbx' => 'archive', 'blend' => 'archive', 'gltf' => 'archive',
        ];

        return $iconMap[$ext] ?? 'file';
    }

    private function hasThumbnailSupport($fileType)
    {
        $imageTypes = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'ico', 'tiff', 'tif'];
        $audioTypes = ['mp3', 'wav', 'flac', 'aac', 'ogg', 'wma', 'm4a', 'aiff', 'aif', 'opus', 'ape', 'alac', 'ra', 'ram', 'ac3', 'amr', 'mid', 'midi'];
        return in_array(strtolower($fileType), array_merge($imageTypes, $audioTypes));
    }

    private function parseTags($tagsStr)
    {
        if (empty($tagsStr)) return [];
        return array_values(array_filter(array_map('trim', explode(',', $tagsStr))));
    }

    private function serializeTags($tags)
    {
        if (empty($tags) || !is_array($tags)) return '';
        return implode(',', array_values(array_filter(array_map('trim', $tags))));
    }

    private function isSafeFileType($filename, $filePath = null)
    {
        // 如果有文件路径，使用文件名 + 内容哈希作为缓存键（更安全）
        $cacheKey = $filename;
        if ($filePath && file_exists($filePath)) {
            // 计算文件前 8KB 的哈希作为缓存键
            $contentHash = hash_file('sha256', $filePath, false);
            if ($contentHash) {
                $cacheKey = $filename . '_' . substr($contentHash, 0, 16);
            }
        }
        
        if (isset($this->fileTypeCache[$cacheKey])) {
            return $this->fileTypeCache[$cacheKey];
        }
        
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $safeExtensions = [
            'jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'ico', 'tiff', 'tif',
            'mp3', 'wav', 'flac', 'aac', 'ogg', 'wma', 'm4a', 'mid', 'midi', 'aiff', 'aif', 'opus', 'ape', 'alac', 'ra', 'ram', 'ac3', 'amr',
            'mp4', 'avi', 'mkv', 'mov', 'wmv', 'flv', 'webm', 'rmvb', 'rm', '3gp', 'm4v', 'mpg', 'mpeg', 'ts', 'f4v', 'ogv', 'vob', 'mts', 'm2ts',
            'zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz',
            'ttf', 'otf', 'woff', 'woff2', 'eot',
            'pdf',
        ];
        
        $result = in_array($ext, $safeExtensions);
        $this->fileTypeCache[$cacheKey] = $result;  // 缓存结果
        
        return $result;
    }

    private function logOperation($action, $target = '')
    {
        // 跳过频繁操作：下载和分片上传不需要审计记录
        $skipActions = ['download', 'download_folder', 'upload_chunk'];
        if (in_array($action, $skipActions)) {
            return;
        }

        $userId = $this->auth->getUserId();
        if (!$userId) return;

        $category = $this->getLogCategory($action);
        $severity = $this->getLogSeverity($action);
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $this->db->insert('operation_logs', [
            'user_id' => $userId,
            'action' => $action,
            'category' => $category,
            'severity' => $severity,
            'target' => $target,
            'ip' => Security::getClientIP(),
            'user_agent' => $userAgent,
            'created_at' => time(),
        ]);
    }

    private function calculateSHA256($filePath)
    {
        $ctx = hash_init('sha256');
        $fp = fopen($filePath, 'rb');
        if (!$fp) {
            return hash_file('sha256', $filePath);
        }
        while (!feof($fp)) {
            $chunk = fread($fp, 65536);
            if ($chunk === false) break;
            hash_update($ctx, $chunk);
        }
        fclose($fp);
        return hash_final($ctx);
    }

    private function getLogCategory($action)
    {
        $categories = [
            'login' => 'auth', 'logout' => 'auth', 'change_password' => 'auth', 'register' => 'auth',
            'upload' => 'file', 'upload_chunk' => 'file', 'download' => 'file', 'download_folder' => 'file',
            'delete' => 'file', 'batch_delete' => 'file', 'rename' => 'file', 'move' => 'file',
            'copy' => 'file', 'create_folder' => 'file', 'restore' => 'file', 'permanent_delete' => 'file',
            'empty_trash' => 'file', 'update_description' => 'file', 'update_tags' => 'file', 'toggle_favorite' => 'file',
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
