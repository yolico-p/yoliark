<?php

namespace App\Services;

use App\Core\Database;
use App\Core\Security;
use App\Core\Config;

class ShareService
{
    private $db;
    private $auth;
    private $config;
    private $folderSizeCache = [];
    private $folderSizeCacheTime = [];
    private $cacheTTL = 500;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->auth = new AuthService();
        $this->config = Config::getInstance();
    }

    private function generateQRCodeSVG($data)
    {
        $size = 200;
        $modules = 25;
        $cellSize = $size / $modules;

        $hash = md5($data);
        $grid = [];
        for ($y = 0; $y < $modules; $y++) {
            for ($x = 0; $x < $modules; $x++) {
                $idx = ($y * $modules + $x) % strlen($hash);
                $grid[$y][$x] = (hexdec($hash[$idx]) % 3 === 0) ? 1 : 0;
            }
        }

        for ($y = 0; $y < 7; $y++) {
            for ($x = 0; $x < 7; $x++) {
                $isBorder = ($y === 0 || $y === 6 || $x === 0 || $x === 6);
                $isInner = ($y >= 2 && $y <= 4 && $x >= 2 && $x <= 4);
                $grid[$y][$x] = ($isBorder || $isInner) ? 1 : 0;
                $grid[$y][$modules - 7 + $x] = ($isBorder || $isInner) ? 1 : 0;
                $grid[$modules - 7 + $y][$x] = ($isBorder || $isInner) ? 1 : 0;
            }
        }

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $size . ' ' . $size . '" width="' . $size . '" height="' . $size . '">';
        $svg .= '<rect width="' . $size . '" height="' . $size . '" fill="white" rx="8"/>';

        for ($y = 0; $y < $modules; $y++) {
            for ($x = 0; $x < $modules; $x++) {
                if ($grid[$y][$x]) {
                    $px = $x * $cellSize;
                    $py = $y * $cellSize;
                    $svg .= '<rect x="' . $px . '" y="' . $py . '" width="' . $cellSize . '" height="' . $cellSize . '" fill="#1e293b" rx="1"/>';
                }
            }
        }

        $svg .= '</svg>';
        return base64_encode($svg);
    }

    public function createShare($fileId, $options = [])
    {
        $userId = $this->auth->getUserId();
        $file = $this->db->fetch("SELECT * FROM files WHERE id = ? AND user_id = ?", [$fileId, $userId]);

        if (!$file) {
            return ['success' => false, 'message' => '文件不存在'];
        }

        $token = Security::generateToken($this->config->get('share_link_length'));
        $password = isset($options['password']) && !empty($options['password']) ? Security::hashPassword($options['password']) : '';
        $maxDownloads = intval($options['max_downloads'] ?? 0);
        $expireAt = 0;

        if (isset($options['expire_days'])) {
            $expireDays = intval($options['expire_days']);
            if ($expireDays > 0) {
                $expireAt = time() + ($expireDays * 24 * 3600);
            } else {
                $expireAt = 0;
            }
        } elseif ($this->config->get('share_default_expire') > 0) {
            $expireAt = time() + $this->config->get('share_default_expire');
        }

        $now = time();
        $this->db->insert('shares', [
            'user_id' => $userId,
            'file_id' => $fileId,
            'share_token' => $token,
            'share_password' => $password,
            'download_count' => 0,
            'max_downloads' => $maxDownloads,
            'expire_at' => $expireAt,
            'created_at' => $now,
            'is_active' => 1,
        ]);

        $shareUrl = $this->getShareUrl($token);

        $this->logOperation('create_share', $file['filename']);

        return [
            'success' => true,
            'message' => '分享链接已创建',
            'share_token' => $token,
            'share_url' => $shareUrl,
            'expire_at' => $expireAt,
            'has_password' => !empty($password),
        ];
    }

    public function createShareWithQRCode($fileId, $options = [])
    {
        $result = $this->createShare($fileId, $options);
        if (!$result['success']) {
            return $result;
        }

        $qrSvg = $this->generateQRCodeSVG($result['share_url']);
        $result['qrcode_svg'] = $qrSvg;
        return $result;
    }

    public function getShareByToken($token)
    {
        $share = $this->db->fetch("SELECT * FROM shares WHERE share_token = ? AND is_active = 1", [$token]);

        if (!$share) {
            return null;
        }

        if ($share['expire_at'] > 0 && $share['expire_at'] < time()) {
            $this->db->update('shares', ['is_active' => 0], 'id = ?', [$share['id']]);
            return null;
        }

        if ($share['max_downloads'] > 0 && $share['download_count'] >= $share['max_downloads']) {
            $this->db->update('shares', ['is_active' => 0], 'id = ?', [$share['id']]);
            return null;
        }

        return $share;
    }

    public function getShareInfo($token)
    {
        $share = $this->getShareByToken($token);
        if (!$share) {
            return null;
        }

        $file = $this->db->fetch("SELECT * FROM files WHERE id = ?", [$share['file_id']]);
        if (!$file) {
            return null;
        }

        if ($file['is_dir']) {
            $totalSize = $this->calculateFolderSize($file['id']);
            $file['filesize'] = $totalSize;
        }

        return [
            'share' => $share,
            'file' => $file,
            'has_password' => !empty($share['share_password']),
        ];
    }

    private function calculateFolderSize($folderId)
    {
        $now = time();
        if (isset($this->folderSizeCache[$folderId]) && 
            isset($this->folderSizeCacheTime[$folderId]) && 
            ($now - $this->folderSizeCacheTime[$folderId]) < $this->cacheTTL) {
            return $this->folderSizeCache[$folderId];
        }
        
        $totalSize = 0;
        
        $files = $this->db->fetchAll(
            "SELECT id, filesize, is_dir FROM files WHERE parent_id = ?",
            [$folderId]
        );
        
        foreach ($files as $file) {
            if ($file['is_dir']) {
                $totalSize += $this->calculateFolderSize($file['id']);
            } else {
                $totalSize += $file['filesize'];
            }
        }
        
        $this->folderSizeCache[$folderId] = $totalSize;
        $this->folderSizeCacheTime[$folderId] = $now;
        return $totalSize;
    }

    public function verifySharePassword($shareId, $password)
    {
        $share = $this->db->fetch("SELECT * FROM shares WHERE id = ?", [$shareId]);

        if (!$share || empty($share['share_password'])) {
            return true;
        }

        return Security::verifyPassword($password, $share['share_password']);
    }

    public function downloadSharedFile($token, $password = '')
    {
        $share = $this->getShareByToken($token);

        if (!$share) {
            return ['success' => false, 'message' => '分享链接无效或已过期'];
        }

        if (!empty($share['share_password'])) {
            if (empty($password)) {
                return ['success' => false, 'message' => '需要提取密码', 'need_password' => true];
            }
            if (!Security::verifyPassword($password, $share['share_password'])) {
                return ['success' => false, 'message' => '提取码错误', 'need_password' => true];
            }
        }

        $file = $this->db->fetch("SELECT * FROM files WHERE id = ?", [$share['file_id']]);

        if (!$file) {
            return ['success' => false, 'message' => '文件不存在或已被删除'];
        }

        if ($file['is_dir']) {
            $result = $this->downloadSharedFolder($file);
            if ($result['success']) {
                // 原子更新：一次 SQL 完成计数增加和 max_downloads 检查
                $this->db->query(
                    'UPDATE shares SET download_count = download_count + 1, is_active = CASE WHEN max_downloads > 0 AND (download_count + 1) >= max_downloads THEN 0 ELSE is_active END WHERE id = ?',
                    [$share['id']]
                );
            }
            return $result;
        }

        $fullPath = FILES_PATH . DIRECTORY_SEPARATOR . $file['filepath'];

        if (!Security::isSafeFilePath($fullPath)) {
            return ['success' => false, 'message' => '文件路径异常'];
        }

        if (!file_exists($fullPath)) {
            return ['success' => false, 'message' => '文件已被删除'];
        }

        // 原子更新：一次 SQL 完成计数增加和 max_downloads 到达检查
        $this->db->query(
            'UPDATE shares SET download_count = download_count + 1, is_active = CASE WHEN max_downloads > 0 AND (download_count + 1) >= max_downloads THEN 0 ELSE is_active END WHERE id = ?',
            [$share['id']]
        );

        // 直接返回原文件，不添加水印
        return [
            'success' => true,
            'path' => $fullPath,
            'filename' => $file['filename'],
            'mime' => $file['mime_type'],
            'size' => $file['filesize'],
            'content_hash' => $file['content_hash'] ?? '',
        ];
    }

    private function downloadSharedFolder($file)
    {
        $fullPath = FILES_PATH . DIRECTORY_SEPARATOR . $file['filepath'];

        if (!is_dir($fullPath)) {
            return ['success' => false, 'message' => '文件夹不存在'];
        }

        $safeName = Security::sanitizeFilename($file['filename']);
        $zipFile = UPLOAD_PATH . DIRECTORY_SEPARATOR . $safeName . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.zip';

        if (file_exists($zipFile)) {
            return ['success' => false, 'message' => '临时文件创建失败，请稍后再试'];
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipFile, \ZipArchive::CREATE) !== true) {
            return ['success' => false, 'message' => '无法创建压缩文件'];
        }

        $this->addDirToZip($zip, $fullPath, $file['filename']);
        $zip->close();

        return [
            'success' => true,
            'path' => $zipFile,
            'filename' => $file['filename'] . '.zip',
            'mime' => 'application/zip',
            'size' => filesize($zipFile),
            'temp' => true,
        ];
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

    public function listShares($page = 1, $pageSize = 20)
    {
        $userId = $this->auth->getUserId();
        $offset = ($page - 1) * $pageSize;

        $shares = $this->db->fetchAll(
            "SELECT s.*, f.filename, f.filesize, f.file_type, f.is_dir
             FROM shares s
             LEFT JOIN files f ON s.file_id = f.id
             WHERE s.user_id = ?
             ORDER BY s.created_at DESC
             LIMIT ? OFFSET ?",
            [$userId, $pageSize, $offset]
        );

        foreach ($shares as &$share) {
            $share['share_url'] = $this->getShareUrl($share['share_token']);
            $share['created_at_formatted'] = Security::formatTime($share['created_at']);
            $share['expire_at_formatted'] = $share['expire_at'] > 0 ? Security::formatTime($share['expire_at']) : '永久';
            $share['is_expired'] = ($share['expire_at'] > 0 && $share['expire_at'] < time()) || ($share['max_downloads'] > 0 && $share['download_count'] >= $share['max_downloads']);
            $share['has_password'] = !empty($share['share_password']);
        }

        return $shares;
    }

    public function getSharesCount()
    {
        $userId = $this->auth->getUserId();
        $result = $this->db->fetch(
            "SELECT COUNT(*) as count FROM shares WHERE user_id = ?",
            [$userId]
        );
        return $result['count'];
    }

    public function deleteShare($shareId)
    {
        $userId = $this->auth->getUserId();
        $share = $this->db->fetch("SELECT * FROM shares WHERE id = ? AND user_id = ?", [$shareId, $userId]);

        if (!$share) {
            return ['success' => false, 'message' => '分享不存在'];
        }

        $this->db->delete('shares', 'id = ? AND user_id = ?', [$shareId, $userId]);

        $this->logOperation('delete_share', $share['share_token']);

        return ['success' => true, 'message' => '分享已删除'];
    }

    public function toggleShare($shareId)
    {
        $userId = $this->auth->getUserId();
        $share = $this->db->fetch("SELECT * FROM shares WHERE id = ? AND user_id = ?", [$shareId, $userId]);

        if (!$share) {
            return ['success' => false, 'message' => '分享不存在'];
        }

        $newStatus = $share['is_active'] ? 0 : 1;
        $this->db->update('shares', ['is_active' => $newStatus], 'id = ? AND user_id = ?', [$shareId, $userId]);

        return ['success' => true, 'message' => $newStatus ? '分享已启用' : '分享已禁用', 'is_active' => $newStatus];
    }

    public function recordVisit($shareId, $visitType = 'view')
    {
        $ip = Security::getClientIP();
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
        $referer = substr($_SERVER['HTTP_REFERER'] ?? '', 0, 500);

        $this->db->insert('share_visits', [
            'share_id' => intval($shareId),
            'ip' => $ip,
            'user_agent' => $userAgent,
            'referer' => $referer,
            'visit_type' => in_array($visitType, ['view', 'download']) ? $visitType : 'view',
            'country' => '',
            'city' => '',
            'created_at' => time(),
        ]);
    }

    public function getShareStats($shareId)
    {
        $userId = $this->auth->getUserId();
        $share = $this->db->fetch("SELECT * FROM shares WHERE id = ? AND user_id = ?", [$shareId, $userId]);
        if (!$share) {
            return ['success' => false, 'message' => '分享不存在'];
        }

        $totalViews = $this->db->fetch(
            "SELECT COUNT(*) as count FROM share_visits WHERE share_id = ? AND visit_type = 'view'",
            [$shareId]
        );
        $totalDownloads = $this->db->fetch(
            "SELECT COUNT(*) as count FROM share_visits WHERE share_id = ? AND visit_type = 'download'",
            [$shareId]
        );

        $sevenDaysAgo = time() - 7 * 86400;
        $dailyStats = $this->db->fetchAll(
            "SELECT DATE(created_at, 'unixepoch') as date,
                    SUM(CASE WHEN visit_type = 'view' THEN 1 ELSE 0 END) as views,
                    SUM(CASE WHEN visit_type = 'download' THEN 1 ELSE 0 END) as downloads
             FROM share_visits
             WHERE share_id = ? AND created_at >= ?
             GROUP BY date ORDER BY date",
            [$shareId, $sevenDaysAgo]
        );

        $uniqueIps = $this->db->fetch(
            "SELECT COUNT(DISTINCT ip) as count FROM share_visits WHERE share_id = ? AND ip != ''",
            [$shareId]
        );

        $recentVisits = $this->db->fetchAll(
            "SELECT * FROM share_visits WHERE share_id = ? ORDER BY created_at DESC LIMIT 50",
            [$shareId]
        );

        foreach ($recentVisits as &$visit) {
            $visit['created_at_formatted'] = Security::formatTime($visit['created_at']);
        }

        return [
            'success' => true,
            'stats' => [
                'total_views' => $totalViews['count'],
                'total_downloads' => $totalDownloads['count'],
                'unique_visitors' => $uniqueIps['count'],
                'daily_stats' => $dailyStats,
                'recent_visits' => $recentVisits,
            ]
        ];
    }

    private function getShareUrl($token)
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/';
        $scriptDir = dirname($scriptName);
        $scriptDir = $scriptDir === '\\' || $scriptDir === '/' ? '' : $scriptDir;
        return $protocol . '://' . $host . $scriptDir . '/index.php?page=share&token=' . $token;
    }

    private function addImageWatermark($imagePath, $ext, $share)
    {
        $imageData = @file_get_contents($imagePath);
        if ($imageData === false) return null;

        $image = @imagecreatefromstring($imageData);
        if ($image === false) return null;

        $width = imagesx($image);
        $height = imagesy($image);

        if ($width < 200 || $height < 200) {
            imagedestroy($image);
            return null;
        }

        $watermarkText = date('Y-m-d');
        $fontSize = max(12, intval(min($width, $height) / 25));

        $fontPath = null;
        $fontDirs = [
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/noto/NotoSansCJK-Regular.ttc',
            '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc',
            'C:/Windows/Fonts/msyh.ttc',
            'C:/Windows/Fonts/arial.ttf',
        ];
        foreach ($fontDirs as $dir) {
            if (file_exists($dir)) {
                $fontPath = $dir;
                break;
            }
        }

        $textColor = imagecolorallocatealpha($image, 128, 128, 128, 80);

        if ($fontPath && function_exists('imagettftext')) {
            $angle = -30;
            $padding = $fontSize * 2;
            $x = $width - $padding - $fontSize * strlen($watermarkText) * 0.5;
            $y = $height - $padding;
            imagettftext($image, $fontSize, $angle, $x, $y, $textColor, $fontPath, $watermarkText);
        } else {
            $padding = 10;
            $x = $width - imagefontwidth(5) * strlen($watermarkText) - $padding;
            $y = $height - imagefontheight(5) - $padding;
            imagestring($image, 5, $x, $y, $watermarkText, $textColor);
        }

        $tempDir = UPLOAD_PATH;
        if (!is_dir($tempDir)) {
            imagedestroy($image);
            return null;
        }
        $tempPath = $tempDir . DIRECTORY_SEPARATOR . 'wm_' . $share['id'] . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);

        $saveResult = false;
        switch ($ext) {
            case 'png':
                imagesavealpha($image, true);
                $saveResult = imagepng($image, $tempPath, 8);
                break;
            case 'gif':
                $saveResult = imagegif($image, $tempPath);
                break;
            case 'webp':
                $saveResult = imagewebp($image, $tempPath, 80);
                break;
            default:
                $saveResult = imagejpeg($image, $tempPath, 85);
                break;
        }

        imagedestroy($image);

        if (!$saveResult) {
            if (file_exists($tempPath)) @unlink($tempPath);
            return null;
        }

        return $tempPath;
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
            'create_share' => 'share', 'delete_share' => 'share', 'toggle_share' => 'share',
        ];
        return $categories[$action] ?? 'other';
    }

    private function getLogSeverity($action)
    {
        return 'info';
    }
}
