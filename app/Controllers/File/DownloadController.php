<?php

namespace App\Controllers\File;

use App\Controllers\BaseController;
use App\Core\Security;
use App\Core\Config;
use App\Services\ThumbnailService;
use App\Services\AudioCoverService;

class DownloadController extends BaseController
{
    public function download()
    {
        $this->requireAuth();
        $this->rateLimit('download', 30, 60);

        $fileId = intval($this->input('file_id', 0));

        $result = $this->fileManager()->downloadFile($fileId);

        if (!$result['success']) {
            Security::jsonOutput($result, 404);
        }

        $fullPath = $result['path'];
        $filename = $result['filename'];
        $mimeType = $result['mime'];
        $fileSize = $result['size'];
        $contentHash = $result['content_hash'] ?? '';

        $this->fileManager()->recordAccess($fileId);

        // 大文件下载前清除输出缓冲，避免二进制流经过压缩层
        self::cleanOutputBuffer();
        $this->sendFile($fullPath, $filename, $mimeType, $fileSize, !empty($result['temp']), $contentHash);
    }

    public function preview()
    {
        $this->requireAuth();

        $fileId = intval($this->input('file_id', 0));
        $file = $this->fileManager()->getFileById($fileId);

        if (!$file) {
            Security::jsonOutput(['success' => false, 'message' => '文件不存在或无权访问'], 404);
        }

        $fullPath = FILES_PATH . DIRECTORY_SEPARATOR . $file['filepath'];

        if (!Security::isSafeFilePath($fullPath) || !file_exists($fullPath)) {
            Security::jsonOutput(['success' => false, 'message' => '文件访问被拒绝'], 403);
        }

        if (!empty($file['is_encrypted'])) {
            $tempResult = $this->fileManager()->decryptFileToTemp($fileId);
            if ($tempResult) {
                $fullPath = $tempResult['path'];
            } else {
                Security::jsonOutput(['success' => false, 'message' => '加密文件解密失败，请重新登录'], 400);
            }
        }

        $this->fileManager()->recordAccess($fileId);

        $config = Config::getInstance();
        $ext = strtolower($file['file_type']);
        $previewType = $this->detectPreviewType($ext);
        $sizeLimit = $this->getSizeLimit($config, $previewType);

        if ($file['filesize'] > $sizeLimit) {
            Security::jsonOutput(['success' => false, 'message' => '文件过大，无法预览']);
        }

        if (in_array($previewType, ['text', 'markdown', 'csv'])) {
            // 只读前 500KB，避免大文本文件全量加载到内存
            $fp = fopen($fullPath, 'rb');
            if ($fp === false) {
                Security::jsonOutput(['success' => false, 'message' => '无法读取文件内容']);
            }
            $content = fread($fp, 512000);
            fclose($fp);
            if ($content === false || $content === '') {
                Security::jsonOutput(['success' => false, 'message' => '无法读取文件内容']);
            }
            $detectedEncoding = mb_detect_encoding($content, ['UTF-8', 'GB2312', 'GBK', 'GB18030', 'BIG5', 'EUC-CN', 'ISO-8859-1', 'ASCII'], true);
            if ($detectedEncoding === false) {
                $detectedEncoding = 'UTF-8';
            }
            $sanitized = mb_convert_encoding($content, 'UTF-8', $detectedEncoding);
            Security::jsonOutput([
                'success' => true,
                'preview_type' => $previewType,
                'content' => $sanitized,
                'filename' => Security::escape($file['filename']),
            ]);
        }

        if ($previewType === 'excel' || $previewType === 'word') {
            Security::jsonOutput([
                'success' => true,
                'preview_type' => $previewType,
                'download_url' => "index.php?action=download&file_id={$fileId}",
                'filename' => Security::escape($file['filename']),
            ]);
        }

        if (in_array($previewType, ['image', 'video', 'audio', 'pdf'])) {
            // 对预览资源启用浏览器缓存
            $cacheMaxAge = $previewType === 'image' ? 604800 : 3600;
            header('Cache-Control: public, max-age=' . $cacheMaxAge);
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $cacheMaxAge) . ' GMT');
            $this->sendFile($fullPath, null, $file['mime_type'], $file['filesize'], !empty($file['is_encrypted']));
        }

        Security::jsonOutput([
            'success' => true,
            'preview_type' => $previewType,
            'file_id' => $fileId,
            'filename' => Security::escape($file['filename']),
            'mime_type' => $file['mime_type'],
        ]);
    }

    public function thumbnail()
    {
        $this->requireAuth();

        $fileId = intval($this->input('file_id', 0));
        $file = $this->fileManager()->getFileById($fileId);

        if (!$file || $file['is_dir']) {
            http_response_code(404);
            exit;
        }

        $fullPath = FILES_PATH . DIRECTORY_SEPARATOR . $file['filepath'];

        if (!Security::isSafeFilePath($fullPath) || !file_exists($fullPath)) {
            http_response_code(404);
            exit;
        }

        // 清除输出压缩缓冲，防止二进制内容被 gzip/brotli 改变长度
        self::cleanOutputBuffer();

        $ext = strtolower($file['file_type']);
        $audioTypes = ['mp3', 'wav', 'flac', 'aac', 'ogg', 'wma', 'm4a', 'aiff', 'aif', 'opus', 'ape', 'alac', 'ra', 'ram', 'ac3', 'amr', 'mid', 'midi'];

        if (in_array($ext, $audioTypes)) {
            $coverService = new AudioCoverService();
            $cacheKey = intval($fileId) . '_' . md5($file['filepath'] . '_' . $file['updated_at']);
            $coverData = $coverService->extract($fullPath, $ext, $cacheKey);
            if ($coverData === null) {
                http_response_code(404);
                exit;
            }
            header('Content-Type: image/jpeg');
            header('Content-Length: ' . strlen($coverData));
            header('Cache-Control: public, max-age=604800');
            echo $coverData;
            exit;
        }

        $thumbnailService = new ThumbnailService();
        $cacheKey = intval($fileId) . '_' . md5($file['filepath'] . '_' . $file['updated_at']);
        $thumbnailPath = $thumbnailService->generate($fullPath, $ext, $cacheKey);

        if ($thumbnailPath !== null) {
            // 根据实际缓存文件扩展名决定 Content-Type
            $thumbExt = strtolower(pathinfo($thumbnailPath, PATHINFO_EXTENSION));
            $thumbMime = $thumbExt === 'webp' ? 'image/webp' : 'image/jpeg';
            header('Content-Type: ' . $thumbMime);
            header('Content-Length: ' . filesize($thumbnailPath));
            header('Cache-Control: public, max-age=2592000');
            readfile($thumbnailPath);
            exit;
        }

        header('Content-Type: ' . $file['mime_type']);
        header('Content-Length: ' . $file['filesize']);
        readfile($fullPath);
        exit;
    }

    public function recordAccess()
    {
        $this->requireAuth();

        $fileId = intval($this->input('file_id', 0));
        if ($fileId > 0) {
            $this->fileManager()->recordAccess($fileId);
        }

        Security::jsonOutput(['success' => true]);
    }

    private function sendFile($fullPath, $filename, $mimeType, $fileSize, $isTemp = false, $contentHash = '')
    {
        // 清除输出压缩缓冲，防止二进制内容被 gzip/brotli 改变长度
        self::cleanOutputBuffer();

        if (!file_exists($fullPath)) {
            http_response_code(404);
            exit('文件不存在');
        }

        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . $fileSize);
        header('Accept-Ranges: bytes');

        if (!empty($contentHash)) {
            header('X-Content-SHA256: ' . $contentHash);
        }

        if ($filename !== null) {
            header('Content-Disposition: attachment; filename="' . Security::escape($filename) . '"');
        }

        $range = $_SERVER['HTTP_RANGE'] ?? '';
        if (!empty($range)) {
            $this->sendRange($fullPath, $range, $fileSize);
        } else {
            $fp = fopen($fullPath, 'rb');
            if ($fp !== false) {
                while (!feof($fp)) {
                    echo fread($fp, 65536);
                    flush();
                }
                fclose($fp);
            }
        }

        if ($isTemp) {
            @unlink($fullPath);
        }

        exit;
    }

    private function sendRange($fullPath, $range, $fileSize)
    {
        if (!preg_match('/bytes=(\d+)-(\d*)/', $range, $matches)) {
            readfile($fullPath);
            return;
        }

        $start = intval($matches[1]);
        $end = !empty($matches[2]) ? intval($matches[2]) : $fileSize - 1;

        if ($start > $end || $start >= $fileSize) {
            http_response_code(416);
            header('Content-Range: bytes */' . $fileSize);
            exit;
        }

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

    private function detectPreviewType($ext)
    {
        $imageTypes = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'ico', 'tiff', 'tif'];
        $videoTypes = ['mp4', 'webm', 'avi', 'mkv', 'mov', 'wmv', 'flv', 'm4v', '3gp', 'mpg', 'mpeg', 'ts', 'f4v', 'ogv', 'rm', 'rmvb', 'vob', 'mts', 'm2ts'];
        $audioTypes = ['mp3', 'wav', 'ogg', 'flac', 'aac', 'wma', 'aiff', 'aif', 'm4a', 'opus', 'ape', 'alac', 'ra', 'ram', 'ac3', 'amr', 'mid', 'midi'];
        $markdownTypes = ['md'];
        $textTypes = ['txt', 'json', 'xml', 'html', 'css', 'js', 'log', 'ini', 'cfg', 'yml', 'yaml', 'py', 'rb', 'java', 'c', 'cpp', 'h', 'go', 'rs', 'sql', 'ts', 'jsx', 'tsx', 'vue', 'sh', 'bash', 'bat', 'ps1', 'r', 'm', 'swift', 'kt', 'scala', 'php'];
        $csvTypes = ['csv'];
        $pdfTypes = ['pdf'];
        $officeExcelTypes = ['xlsx', 'xls'];
        $officeWordTypes = ['docx'];

        if (in_array($ext, $imageTypes)) return 'image';
        if (in_array($ext, $videoTypes)) return 'video';
        if (in_array($ext, $audioTypes)) return 'audio';
        if (in_array($ext, $markdownTypes)) return 'markdown';
        if (in_array($ext, $textTypes)) return 'text';
        if (in_array($ext, $csvTypes)) return 'csv';
        if (in_array($ext, $pdfTypes)) return 'pdf';
        if (in_array($ext, $officeExcelTypes)) return 'excel';
        if (in_array($ext, $officeWordTypes)) return 'word';

        return 'unknown';
    }

    private function getSizeLimit($config, $previewType)
    {
        $limits = [
            'image' => $config->get('preview_max_size_image', 10485760),
            'video' => $config->get('preview_max_size_media', 157286400),
            'audio' => $config->get('preview_max_size_media', 157286400),
            'markdown' => $config->get('preview_max_size_text', 1048576),
            'text' => $config->get('preview_max_size_text', 1048576),
            'csv' => $config->get('preview_max_size_text', 1048576),
            'pdf' => $config->get('preview_max_size_pdf', 52428800),
            'excel' => $config->get('preview_max_size_office', 52428800),
            'word' => $config->get('preview_max_size_office', 52428800),
        ];
        return $limits[$previewType] ?? $config->get('preview_max_size', 157286400);
    }
}
