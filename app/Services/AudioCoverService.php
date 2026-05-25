<?php

namespace App\Services;

class AudioCoverService
{
    protected $cacheDir;
    protected $maxWidth = 64;
    protected $maxHeight = 64;
    protected $jpegQuality = 85;
    protected $cacheTtl = 90 * 86400;

    public function __construct()
    {
        $this->cacheDir = STORAGE_PATH . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'covers';
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    public function extract($audioPath, $ext, $cacheKey)
    {
        $cachePath = $this->cacheDir . DIRECTORY_SEPARATOR . 'cover_' . $cacheKey . '.jpg';

        if (file_exists($cachePath) && $this->isCacheValid($cachePath)) {
            return file_get_contents($cachePath);
        }

        $coverData = $this->parseCoverFromAudio($audioPath, $ext);
        if ($coverData === null || strlen($coverData) === 0) {
            return null;
        }

        if (strlen($coverData) > 200 * 1024 && function_exists('imagecreatefromstring')) {
            $coverData = $this->resizeCover($coverData);
        }

        if ($coverData && strlen($coverData) > 0) {
            try {
                file_put_contents($cachePath, $coverData);
            } catch (\Exception $e) {
                // 缓存保存失败不影响返回
            }
        }

        return $coverData;
    }

    protected function parseCoverFromAudio($audioPath, $ext)
    {
        $handle = fopen($audioPath, 'rb');
        if (!$handle) return null;

        $data = '';
        $maxRead = 2 * 1024 * 1024;
        while (!feof($handle) && strlen($data) < $maxRead) {
            $chunk = fread($handle, 8192);
            if ($chunk === false) break;
            $data .= $chunk;
        }
        fclose($handle);

        $coverData = null;

        if ($ext === 'mp3') {
            $coverData = $this->extractID3v2Cover($data);
        }

        if ($coverData === null) {
            $coverData = $this->extractGenericCover($data);
        }

        return $coverData;
    }

    protected function extractID3v2Cover($data)
    {
        $pos = strpos($data, 'APIC');
        if ($pos === false) {
            return null;
        }

        $imageStart = strpos($data, "\xFF\xD8\xFF", $pos);
        if ($imageStart === false) {
            $imageStart = strpos($data, "\x89PNG", $pos);
        }

        if ($imageStart === false) {
            return null;
        }

        $imageEndJpeg = strpos($data, "\xFF\xD9", $imageStart);
        $imageEndPng = strpos($data, "IEND", $imageStart);

        $imageEnd = false;
        if ($imageEndJpeg !== false && $imageEndPng !== false) {
            $imageEnd = min($imageEndJpeg, $imageEndPng);
        } elseif ($imageEndJpeg !== false) {
            $imageEnd = $imageEndJpeg + 2;
        } elseif ($imageEndPng !== false) {
            $imageEnd = $imageEndPng + 8;
        }

        if ($imageEnd === false) {
            return null;
        }

        return substr($data, $imageStart, $imageEnd - $imageStart);
    }

    protected function extractGenericCover($data)
    {
        $imageStart = strpos($data, "\xFF\xD8\xFF");
        if ($imageStart !== false) {
            $imageEnd = strpos($data, "\xFF\xD9", $imageStart);
            if ($imageEnd !== false) {
                return substr($data, $imageStart, $imageEnd - $imageStart + 2);
            }
        }

        $imageStart = strpos($data, "\x89PNG");
        if ($imageStart !== false) {
            $imageEnd = strpos($data, "IEND", $imageStart);
            if ($imageEnd !== false) {
                return substr($data, $imageStart, $imageEnd - $imageStart + 8);
            }
        }

        return null;
    }

    protected function resizeCover($coverData)
    {
        $image = @imagecreatefromstring($coverData);
        if ($image === false) {
            return $coverData;
        }

        $originalWidth = imagesx($image);
        $originalHeight = imagesy($image);

        $ratio = min($this->maxWidth / $originalWidth, $this->maxHeight / $originalHeight);
        if ($ratio >= 1) {
            imagedestroy($image);
            return $coverData;
        }

        $newWidth = intval($originalWidth * $ratio);
        $newHeight = intval($originalHeight * $ratio);
        $thumbnail = imagecreatetruecolor($newWidth, $newHeight);

        imagecopyresampled($thumbnail, $image, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);

        ob_start();
        imagejpeg($thumbnail, null, $this->jpegQuality);
        $thumbnailData = ob_get_clean();

        imagedestroy($image);
        imagedestroy($thumbnail);

        return $thumbnailData;
    }

    protected function isCacheValid($cachePath)
    {
        return time() - filemtime($cachePath) <= $this->cacheTtl;
    }

    public function clearCache()
    {
        if (is_dir($this->cacheDir)) {
            $files = glob($this->cacheDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }
}
