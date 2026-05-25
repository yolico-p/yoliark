<?php

namespace App\Controllers\Share;

use App\Controllers\BaseController;
use App\Core\Security;
use App\Services\ShareService;

class SharePublicController extends BaseController
{
    public function info()
    {
        $token = $this->input('token', '');
        if (empty($token)) {
            Security::jsonOutput(['success' => false, 'message' => '分享链接无效']);
        }

        $shareService = new ShareService();
        $shareInfo = $shareService->getShareInfo($token);

        if (!$shareInfo) {
            Security::jsonOutput(['success' => false, 'message' => '分享链接无效或已过期']);
        }

        $shareService->recordVisit($shareInfo['share']['id'], 'view');

        Security::jsonOutput([
            'success' => true,
            'file' => [
                'filename' => Security::escape($shareInfo['file']['filename']),
                'filesize' => $shareInfo['file']['filesize'],
                'filesize_formatted' => Security::formatSize($shareInfo['file']['filesize']),
                'file_type' => $shareInfo['file']['file_type'],
                'is_dir' => $shareInfo['file']['is_dir'],
            ],
            'has_password' => $shareInfo['has_password'],
            'share_token' => $shareInfo['share']['share_token'],
        ]);
    }

    public function download()
    {
        try {
            $token = $this->input('token', '');
            $password = $this->input('password', '');

            if (empty($token)) {
                Security::jsonOutput(['success' => false, 'message' => '分享链接无效']);
            }

            $shareService = new ShareService();
            $result = $shareService->downloadSharedFile($token, $password);

            if (!$result['success']) {
                Security::jsonOutput($result);
            }

            $shareInfo = $shareService->getShareInfo($token);
            if ($shareInfo) {
                $shareService->recordVisit($shareInfo['share']['id'], 'download');
            }

            $fullPath = $result['path'];
            $filename = $result['filename'];
            $mimeType = $result['mime'];
            $fileSize = $result['size'];
            $isTemp = !empty($result['temp']);
            $contentHash = $result['content_hash'] ?? '';

            if (!file_exists($fullPath)) {
                Security::jsonOutput(['success' => false, 'message' => '文件不存在']);
            }

            header('Content-Type: ' . $mimeType);
            header('Content-Length: ' . $fileSize);
            header('Content-Disposition: attachment; filename="' . Security::escape($filename) . '"');
            header('Accept-Ranges: bytes');

            if (!empty($contentHash)) {
                header('X-Content-SHA256: ' . $contentHash);
            }

            // 大文件下载前清除输出缓冲，避免二进制流经过压缩层
            \App\Controllers\BaseController::cleanOutputBuffer();

            $range = $_SERVER['HTTP_RANGE'] ?? '';
            if (!empty($range) && preg_match('/bytes=(\d+)-(\d*)/', $range, $matches)) {
                $start = intval($matches[1]);
                $end = !empty($matches[2]) ? intval($matches[2]) : $fileSize - 1;

                if ($start <= $end && $start < $fileSize) {
                    http_response_code(206);
                    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
                    header('Content-Length: ' . ($end - $start + 1));

                    $fp = fopen($fullPath, 'rb');
                    fseek($fp, $start);
                    $remaining = $end - $start + 1;
                    while ($remaining > 0 && !feof($fp)) {
                        $chunk = fread($fp, min(65536, $remaining));
                        echo $chunk;
                        $remaining -= strlen($chunk);
                    }
                    fclose($fp);
                }
            } else {
                readfile($fullPath);
            }

            if ($isTemp) {
                @unlink($fullPath);
            }

            exit;
        } catch (\Throwable $e) {
            Security::jsonOutput(['success' => false, 'message' => '下载失败：' . $e->getMessage()], 500);
        }
    }

    public function directAccess()
    {
        $token = $this->input('token', '');
        if (empty($token)) {
            http_response_code(404);
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>分享不存在</title><style>body{font-family:sans-serif;text-align:center;padding:50px;color:#666}h1{color:#333}</style></head><body><h1>分享链接无效</h1><p>该分享链接不存在或已过期</p></body></html>';
            exit;
        }

        $shareService = new ShareService();
        $shareInfo = $shareService->getShareInfo($token);

        if (!$shareInfo) {
            http_response_code(404);
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>分享不存在</title><style>body{font-family:sans-serif;text-align:center;padding:50px;color:#666}h1{color:#333}</style></head><body><h1>分享链接无效</h1><p>该分享链接不存在或已过期</p></body></html>';
            exit;
        }

        Security::jsonOutput([
            'success' => true,
            'file' => [
                'filename' => Security::escape($shareInfo['file']['filename']),
                'filesize' => $shareInfo['file']['filesize'],
                'filesize_formatted' => Security::formatSize($shareInfo['file']['filesize']),
                'file_type' => $shareInfo['file']['file_type'],
                'is_dir' => $shareInfo['file']['is_dir'],
            ],
            'has_password' => $shareInfo['has_password'],
            'share_token' => $shareInfo['share']['share_token'],
        ]);
    }

    public function recordShareVisit()
    {
        $token = $this->input('token', '');
        if (empty($token)) {
            Security::jsonOutput(['success' => false]);
        }

        $this->rateLimit('share_visit_' . $token, 1, 10);

        $shareService = new ShareService();
        $shareInfo = $shareService->getShareByToken($token);

        if ($shareInfo) {
            $shareService->recordVisit($shareInfo['id'], 'view');
        }

        Security::jsonOutput(['success' => true]);
    }
}
