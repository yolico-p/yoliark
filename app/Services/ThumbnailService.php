<?php

namespace App\Services;

use App\Core\Security;

class ThumbnailService
{
    protected $cacheDir;
    protected $maxWidth = 128;
    protected $maxHeight = 128;
    protected $jpegQuality = 80;
    protected $useWebP = true;
    protected $lazyGenerate = true;

    public function __construct()
    {
        $this->cacheDir = STORAGE_PATH . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'thumbnails';
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }

        $this->useWebP = function_exists('imagewebp');
    }

    public function generate($sourcePath, $ext, $cacheKey)
    {
        $extension = $this->useWebP ? 'webp' : 'jpg';
        $cachePath = $this->cacheDir . DIRECTORY_SEPARATOR . 'thumb_' . $cacheKey . '.' . $extension;

        if (file_exists($cachePath) && $this->isCacheValid($cachePath)) {
            return $cachePath;
        }

        if (!function_exists('imagecreatefromstring')) {
            return null;
        }

        $imageData = @file_get_contents($sourcePath);
        if ($imageData === false) {
            return null;
        }

        $image = @imagecreatefromstring($imageData);
        if ($image === false) {
            return null;
        }

        $originalWidth = imagesx($image);
        $originalHeight = imagesy($image);

        $ratio = min($this->maxWidth / $originalWidth, $this->maxHeight / $originalHeight);
        if ($ratio >= 1) {
            // 小图：直接使用原图尺寸创建缓存，避免回退到无缓存的原始文件服务
            $ratio = 1;
        }

        $newWidth = intval($originalWidth * $ratio);
        $newHeight = intval($originalHeight * $ratio);
        $thumbnail = imagecreatetruecolor($newWidth, $newHeight);

        if (in_array($ext, ['png', 'webp'])) {
            imagealphablending($thumbnail, false);
            imagesavealpha($thumbnail, true);
        }

        imagecopyresampled($thumbnail, $image, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);

        if ($this->useWebP) {
            ob_start();
            imagewebp($thumbnail, null, $this->jpegQuality);
            $thumbnailData = ob_get_clean();
        } else {
            ob_start();
            imagejpeg($thumbnail, null, $this->jpegQuality);
            $thumbnailData = ob_get_clean();
        }

        try {
            file_put_contents($cachePath, $thumbnailData, LOCK_EX);
        } catch (\Exception $e) {
            return null;
        }

        imagedestroy($image);
        imagedestroy($thumbnail);

        return $cachePath;
    }

    public function setLazyMode($enabled)
    {
        $this->lazyGenerate = $enabled;
    }

    public function shouldGenerate($requestType = 'auto')
    {
        if (!$this->lazyGenerate) {
            return true;
        }

        if ($requestType === 'explicit') {
            return true;
        }

        return false;
    }

    public function getCachePath($cacheKey)
    {
        $extension = $this->useWebP ? 'webp' : 'jpg';
        return $this->cacheDir . DIRECTORY_SEPARATOR . 'thumb_' . $cacheKey . '.' . $extension;
    }

    public function isCacheValid($cachePath)
    {
        if (!file_exists($cachePath)) {
            return false;
        }
        return time() - filemtime($cachePath) <= 30 * 86400;
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

    public function getCacheSize()
    {
        $totalSize = 0;
        $fileCount = 0;
        if (is_dir($this->cacheDir)) {
            $files = glob($this->cacheDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    $totalSize += filesize($file);
                    $fileCount++;
                }
            }
        }
        return ['size' => $totalSize, 'count' => $fileCount];
    }
}
