<?php

namespace App\Core;

/**
 * 统一错误处理器
 * 
 * 确保生产环境不泄露敏感信息，同时保留完整的错误日志
 */
class ErrorHandler
{
    private static $instance = null;
    private $isProduction = true;
    private $logFile = null;
    
    private function __construct()
    {
        $config = Config::getInstance();
        $this->isProduction = !(defined('DEBUG') && DEBUG);
        
        if (defined('DATA_PATH')) {
            $this->logFile = DATA_PATH . DIRECTORY_SEPARATOR . 'error.log';
        }
    }
    
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 注册错误处理器
     */
    public function register()
    {
        // 设置错误显示
        if ($this->isProduction) {
            ini_set('display_errors', 0);
            error_reporting(E_ALL);
        } else {
            ini_set('display_errors', 1);
            error_reporting(E_ALL);
        }
        
        // 注册错误处理函数
        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'handleShutdown']);
    }
    
    /**
     * 处理 PHP 错误
     */
    public function handleError($errno, $errstr, $errfile, $errline)
    {
        // 忽略被 @ 抑制的错误
        if (error_reporting() === 0) {
            return false;
        }
        
        $error = [
            'type' => $this->getErrorType($errno),
            'message' => $errstr,
            'file' => $errfile,
            'line' => $errline,
            'time' => time(),
            'ip' => Security::getClientIP(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'user_id' => $_SESSION['user_id'] ?? null,
        ];
        
        $this->logError($error);
        
        // 在生产环境，不显示详细错误信息
        if ($this->isProduction) {
            return true;
        }
        
        return false;
    }
    
    /**
     * 处理未捕获的异常
     */
    public function handleException($exception)
    {
        $error = [
            'type' => 'Uncaught Exception',
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'time' => time(),
            'ip' => Security::getClientIP(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'user_id' => $_SESSION['user_id'] ?? null,
        ];
        
        $this->logError($error);
        
        // 显示友好的错误页面
        $this->showErrorPage($this->isProduction);
    }
    
    /**
     * 处理致命错误
     */
    public function handleShutdown()
    {
        $error = error_get_last();
        
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $this->handleError(
                $error['type'],
                $error['message'],
                $error['file'],
                $error['line']
            );
            
            $this->showErrorPage($this->isProduction);
        }
    }
    
    /**
     * 记录错误到日志
     */
    private function logError($error)
    {
        $logEntry = sprintf(
            "[%s] %s: %s in %s:%d\n",
            date('Y-m-d H:i:s', $error['time']),
            $error['type'],
            $error['message'],
            $error['file'],
            $error['line']
        );
        
        // 添加额外信息（仅日志中）
        $logEntry .= sprintf(
            "  IP: %s\n  UA: %s\n  User: %s\n",
            $error['ip'] ?? 'unknown',
            $error['user_agent'] ?? 'unknown',
            $error['user_id'] ?? 'anonymous'
        );
        
        if (isset($error['trace'])) {
            $logEntry .= "  Trace:\n" . $error['trace'] . "\n";
        }
        
        $logEntry .= str_repeat('-', 80) . "\n\n";
        
        if ($this->logFile) {
            $dir = dirname($this->logFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($this->logFile, $logEntry, FILE_APPEND);
        }
    }
    
    /**
     * 显示错误页面
     */
    private function showErrorPage($isProduction = true)
    {
        // 清除之前的输出
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        // 设置 HTTP 状态码
        http_response_code(500);
        
        if ($isProduction) {
            // 生产环境：显示友好的错误页面
            echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>出错了</title><style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f5f5f5;color:#333}.container{text-align:center;padding:40px;background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.08);max-width:400px;width:90%}h2{font-size:18px;margin-bottom:12px;font-weight:600}p{font-size:14px;color:#666;margin-bottom:20px}.btn{display:inline-block;padding:10px 20px;background:#007DFF;color:#fff;border-radius:6px;text-decoration:none;font-size:14px}.btn:hover{background:#0066d9}</style></head><body><div class="container"><h2>出错了</h2><p>服务器遇到了一些问题，请稍后再试。</p><a href="index.php" class="btn">返回首页</a></div></body></html>';
        } else {
            // 开发环境：显示详细错误信息
            global $error;
            if (isset($error)) {
                echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><style>body{font-family:monospace;padding:20px;background:#f5f5f5}.error{background:#ffebee;padding:20px;border-radius:4px;margin-bottom:20px}h2{color:#c62828}.info{background:#e3f2fd;padding:15px;border-radius:4px;margin:10px 0}pre{background:#f5f5f5;padding:10px;overflow-x:auto}</style></head><body>';
                echo '<div class="error"><h2>' . htmlspecialchars($error['type']) . '</h2>';
                echo '<p><strong>Message:</strong> ' . htmlspecialchars($error['message']) . '</p>';
                echo '<p><strong>File:</strong> ' . htmlspecialchars($error['file']) . ':' . $error['line'] . '</p></div>';
                
                if (isset($error['trace'])) {
                    echo '<div class="info"><h3>Stack Trace:</h3><pre>' . htmlspecialchars($error['trace']) . '</pre></div>';
                }
                
                echo '</body></html>';
            }
        }
        
        exit;
    }
    
    /**
     * 获取错误类型名称
     */
    private function getErrorType($errno)
    {
        $types = [
            E_ERROR => 'Fatal Error',
            E_WARNING => 'Warning',
            E_PARSE => 'Parse Error',
            E_NOTICE => 'Notice',
            E_CORE_ERROR => 'Core Error',
            E_COMPILE_ERROR => 'Compile Error',
            E_USER_ERROR => 'User Error',
            E_USER_WARNING => 'User Warning',
            E_USER_NOTICE => 'User Notice',
            E_STRICT => 'Strict Notice',
            E_DEPRECATED => 'Deprecated',
            E_RECOVERABLE_ERROR => 'Recoverable Error',
        ];
        
        return $types[$errno] ?? 'Unknown Error (' . $errno . ')';
    }
}
