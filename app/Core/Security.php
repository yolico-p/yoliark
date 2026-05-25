<?php

namespace App\Core;

class Security
{
    public static function init($enableCompression = true)
    {
        $config = Config::getInstance();
        date_default_timezone_set($config->get('timezone'));
        
        // 注册统一错误处理器
        ErrorHandler::getInstance()->register();

        // 安全头信息
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
        header('Content-Security-Policy: default-src \'self\'; script-src \'self\' cdn.bootcdn.net cdnjs.cloudflare.com \'unsafe-inline\'; style-src \'self\' cdn.bootcdn.net cdnjs.cloudflare.com fonts.font.im cdn.jsdelivr.net \'unsafe-inline\'; font-src \'self\' at.alicdn.com cdn.bootcdn.net fonts.font.im cdn.jsdelivr.net data:; img-src \'self\' data: blob:; connect-src \'self\'; base-uri \'self\'; form-action \'self\';');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-site');
        header('Cross-Origin-Embedder-Policy: require-corp');

        // 移除敏感信息头
        if (function_exists('header_remove')) {
            header_remove('X-Powered-By');
            header_remove('Server');
        }
        
        // 隐藏 Server 信息（如果 header_remove 无效）
        header('Server: PanCloud');

        if ($enableCompression && function_exists('ob_start')) {
            self::enableCompression();
        }
    }

    private static function enableCompression()
    {
        if (isset($_SERVER['HTTP_ACCEPT_ENCODING'])) {
            $encoding = $_SERVER['HTTP_ACCEPT_ENCODING'];

            if (function_exists('brotli_compress') && strpos($encoding, 'br') !== false) {
                ob_start(function ($content) {
                    if (strlen($content) < 1024) {
                        return $content;
                    }
                    header('Content-Encoding: br');
                    header('Vary: Accept-Encoding');
                    return brotli_compress($content, 4);
                });
            } elseif (function_exists('gzencode') && strpos($encoding, 'gzip') !== false) {
                ob_start(function ($content) {
                    if (strlen($content) < 1024) {
                        return $content;
                    }
                    header('Content-Encoding: gzip');
                    header('Vary: Accept-Encoding');
                    return gzencode($content, 5);
                });
            } elseif (function_exists('gzdeflate') && strpos($encoding, 'deflate') !== false) {
                ob_start(function ($content) {
                    if (strlen($content) < 1024) {
                        return $content;
                    }
                    header('Content-Encoding: deflate');
                    header('Vary: Accept-Encoding');
                    return gzdeflate($content, 5);
                });
            }
        }
    }

    public static function generateCSRFToken()
    {
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf_token'];
    }

    public static function verifyCSRFToken($token)
    {
        if (empty($_SESSION['_csrf_token']) || empty($token)) {
            return false;
        }
        return hash_equals($_SESSION['_csrf_token'], $token);
    }

    public static function csrfField()
    {
        $token = self::generateCSRFToken();
        return '<input type="hidden" name="_csrf_token" value="' . self::escape($token) . '">';
    }

    public static function escape($string)
    {
        return htmlspecialchars($string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public static function sanitizeFilename($filename)
    {
        $filename = trim($filename);
        $filename = str_replace(['\\', '/', "\0", '%00'], '', $filename);
        while (strpos($filename, '..') !== false) {
            $filename = str_replace('..', '', $filename);
        }
        $filename = preg_replace('/[^\p{L}\p{N}\.\-_ ]/u', '_', $filename);
        $filename = preg_replace('/\.+/', '.', $filename);
        $filename = trim($filename, '. ');

        $reservedNames = ['CON', 'PRN', 'AUX', 'NUL', 'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9', 'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9'];
        $nameOnly = strtoupper(pathinfo($filename, PATHINFO_FILENAME));
        if (in_array($nameOnly, $reservedNames)) {
            $filename = 'invalid_' . time();
        }

        if (empty($filename)) {
            $filename = 'unnamed_' . time();
        }

        if (strlen($filename) > 255) {
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $name = pathinfo($filename, PATHINFO_FILENAME);
            $suffix = '_' . substr(md5($filename), 0, 6);
            if ($ext) {
                $maxNameLen = 250 - strlen($ext) - strlen($suffix);
                $filename = substr($name, 0, $maxNameLen) . $suffix . '.' . $ext;
            } else {
                $filename = substr($filename, 0, 255 - strlen($suffix)) . $suffix;
            }
        }

        return $filename;
    }

    public static function validateFileExtension($filename)
    {
        $config = Config::getInstance();
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // 仅黑名单模式：不在黑名单中的扩展名均可上传
        if (in_array($ext, $config->get('blocked_extensions'))) {
            return false;
        }

        return true;
    }

    public static function validateFileContent($filePath, $filename)
    {
        if (!file_exists($filePath)) {
            return false;
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        $safeExtensions = [
            'jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'ico', 'tiff', 'tif',
            'mp3', 'wav', 'flac', 'aac', 'ogg', 'wma', 'm4a', 'mid', 'midi', 'aiff', 'aif', 'opus', 'ape', 'alac', 'ra', 'ram', 'ac3', 'amr',
            'mp4', 'avi', 'mkv', 'mov', 'wmv', 'flv', 'webm', 'rmvb', 'rm', '3gp', 'm4v',
            'zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz',
            'ttf', 'otf', 'woff', 'woff2', 'eot',
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp',
        ];

        if (in_array($ext, $safeExtensions)) {
            $config = Config::getInstance();
            return !in_array($ext, $config->get('blocked_extensions'));
        }

        $dangerousSignatures = [
            '<?php',
            '<?=',
            '<script language="php">',
            'PHAR',
        ];

        $content = file_get_contents($filePath, false, null, 0, 4096);
        if ($content === false) {
            return false;
        }

        foreach ($dangerousSignatures as $signature) {
            if (stripos($content, $signature) !== false) {
                return false;
            }
        }

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mimeType = finfo_file($finfo, $filePath);
                finfo_close($finfo);

                $dangerousMimeTypes = [
                    'application/x-php',
                    'application/x-executable',
                    'application/x-sharedlib',
                    'application/x-msdos-program',
                    'application/x-java',
                ];

                if (in_array($mimeType, $dangerousMimeTypes)) {
                    return false;
                }
            }
        }

        return true;
    }

    public static function getSafeExtension($filename)
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $config = Config::getInstance();
        if (in_array($ext, $config->get('blocked_extensions'))) {
            return $ext . '.txt';
        }
        return $ext;
    }

    public static function hashPassword($password)
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function verifyPassword($password, $hash)
    {
        return password_verify($password, $hash);
    }

    public static function generateToken($length = 32)
    {
        return bin2hex(random_bytes($length));
    }

    public static function getClientIP()
    {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP']) && !empty($_SERVER['HTTP_CF_RAY'])) {
            $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    public static function getUAScore()
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $score = 100;

        if (empty($ua)) return 0;

        $suspicious = ['python-requests', 'curl/', 'wget/', 'go-http-client', 'scrapy', 'httpclient', 'libwww-perl', 'php/', 'java/', 'axios/'];
        foreach ($suspicious as $s) {
            if (stripos($ua, $s) !== false) return 10;
        }

        $normal = ['Mozilla/', 'Chrome/', 'Safari/', 'Firefox/', 'Edge/', 'Opera/', 'OPR/'];
        foreach ($normal as $n) {
            if (stripos($ua, $n) !== false) { $score += 20; break; }
        }

        if (strlen($ua) < 50) $score -= 30;

        $mobileNormal = ['Mobile/', 'Android', 'iPhone', 'iPad'];
        foreach ($mobileNormal as $m) {
            if (stripos($ua, $m) !== false) { $score += 10; break; }
        }

        return max(0, min($score, 100));
    }

    public static function botChallenge()
    {
        if (!empty($_SESSION['user_id'])) {
            return;
        }

        $currentPage = $_GET['page'] ?? '';
        if ($currentPage === 'share') {
            return;
        }

        $challenge = $_COOKIE['pancloud_bc'] ?? '';
        if ($challenge === '') {
            http_response_code(503);
            echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>安全检查中...</title><style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f5f5f5;color:#333}.container{text-align:center;padding:40px;background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.08);max-width:360px;width:90%}.spinner{width:40px;height:40px;border:3px solid #e5e5e5;border-top-color:#007DFF;border-radius:50%;animation:spin .8s linear infinite;margin:0 auto 20px}@keyframes spin{to{transform:rotate(360deg)}}h2{font-size:16px;margin-bottom:8px;font-weight:500}p{font-size:14px;color:#666}.fallback{display:none}@media(scripting:none),(scripting:off){.fallback{display:block}.spinner{display:none}}</style><noscript><meta http-equiv="refresh" content="3;url=?"></noscript></head><body><div class="container"><noscript><div class="fallback"><h2>需要启用 JavaScript</h2><p>安全检查需要浏览器支持 JavaScript。<br><a href="?">点击这里手动刷新</a></p></div></noscript><div class="spinner"></div><h2>正在进行安全检查</h2><p>请稍候，页面将自动刷新</p></div><script>document.cookie="pancloud_bc=1;path=/;max-age=3600;samesite=strict";setTimeout(function(){location.reload()},1000);</script></body></html>';
            exit;
        }
    }

    public static function rateLimit($key, $maxAttempts, $lockoutTime)
    {
        $limitFile = DATA_PATH . DIRECTORY_SEPARATOR . 'rate_limit_' . md5($key) . '.json';

        $fp = fopen($limitFile, 'c+');
        if (!$fp) {
            return true;
        }

        if (flock($fp, LOCK_EX)) {
            $data = ['attempts' => 0, 'last_attempt' => 0];
            $content = stream_get_contents($fp);
            if ($content) {
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }

            if ($data['attempts'] >= $maxAttempts) {
                if (time() - $data['last_attempt'] < $lockoutTime) {
                    flock($fp, LOCK_UN);
                    fclose($fp);
                    return false;
                }
                $data = ['attempts' => 0, 'last_attempt' => 0];
            }

            $data['attempts']++;
            $data['last_attempt'] = time();

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data));
            fflush($fp);

            flock($fp, LOCK_UN);
        }
        fclose($fp);

        return true;
    }

    public static function adaptiveRateLimit($action, $userId, $fileSize = 0)
    {
        $limiter = \App\Core\AdaptiveRateLimiter::getInstance();

        if ($action === 'upload') {
            return $limiter->checkUpload($userId, $fileSize);
        }
        if ($action === 'upload_chunk') {
            return $limiter->checkUploadChunk($userId);
        }

        return $limiter->adaptiveCheck("{$action}_{$userId}", 1, $fileSize, $action);
    }

    public static function clearRateLimit($key)
    {
        $limitFile = DATA_PATH . DIRECTORY_SEPARATOR . 'rate_limit_' . md5($key) . '.json';
        if (file_exists($limitFile)) {
            $fp = fopen($limitFile, 'c+');
            if ($fp) {
                if (flock($fp, LOCK_EX)) {
                    ftruncate($fp, 0);
                    fflush($fp);
                    flock($fp, LOCK_UN);
                }
                fclose($fp);
            }
            unlink($limitFile);
        }
    }

    public static function formatSize($bytes)
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }

    public static function formatTime($timestamp)
    {
        return date('Y-m-d H:i:s', $timestamp);
    }

    public static function isAjax()
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    public static function jsonOutput($data, $statusCode = 200)
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');

        // JSON 响应无需经过压缩层，移除全部输出缓冲区
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function redirect($url)
    {
        header('Location: ' . $url);
        exit;
    }

    /**
     * 验证并净化文件路径，确保不超出 FILES_PATH 范围。
     *
     * 同时承担 validatePath 和 isSafeFilePath 两个旧方法的职责：
     * - 返回 string（有效绝对路径）或 false（无效）。
     * - 调用方用 if (!Security::resolvePath($p)) 取代两套逻辑。
     */
    public static function resolvePath($path)
    {
        $realBase = realpath(FILES_PATH);
        if ($realBase === false) {
            return false;
        }

        // 空字节攻击
        if (strpos($path, "\0") !== false) {
            return false;
        }

        // 标准化目录分隔符并剥离目录穿越
        $normalized = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);
        $normalized = preg_replace('/\.\.[\/\\\\]?/', '', $normalized);
        $normalized = preg_replace('/\.\./', '', $normalized);

        // 裁剪前导分隔符（仅对相对路径，绝对路径保留）
        if (!str_starts_with($normalized, $realBase)) {
            $normalized = ltrim($normalized, DIRECTORY_SEPARATOR);
        }

        // 情况 A：已经以 FILES_PATH 开头
        $candidate = str_starts_with($normalized, $realBase)
            ? $normalized
            : $realBase . DIRECTORY_SEPARATOR . $normalized;

        $realPath = realpath($candidate);
        if ($realPath !== false) {
            return str_starts_with($realPath, $realBase) ? $realPath : false;
        }

        // 路径可能尚未创建，退而检查所在目录
        $dirPath = dirname($candidate);
        $realDir = realpath($dirPath);
        if ($realDir === false) {
            return false;
        }
        return str_starts_with($realDir, $realBase) ? $candidate : false;
    }

    /**
     * 旧方法兼容层。
     *
     * @deprecated 改用 Security::resolvePath()
     */
    public static function validatePath($path)
    {
        return self::resolvePath($path);
    }

    /**
     * 旧方法兼容层。
     *
     * @deprecated 改用 Security::resolvePath()
     */
    public static function isSafeFilePath($path)
    {
        return self::resolvePath($path) !== false;
    }
}
