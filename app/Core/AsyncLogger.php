<?php

namespace App\Core;

class AsyncLogger
{
    private static $instance = null;
    private $logBuffer = [];
    private $bufferSize = 50;
    private $logFile;
    private $asyncMode = true;

    private function __construct()
    {
        $this->logFile = STORAGE_PATH . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'app.log';
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        register_shutdown_function([$this, 'flush']);
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new static();
        }
        return self::$instance;
    }

    public function setAsyncMode($enabled)
    {
        $this->asyncMode = $enabled;
    }

    public function log($message, $level = 'info', $context = [])
    {
        $entry = [
            'timestamp' => microtime(true),
            'datetime' => date('Y-m-d H:i:s'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'user_id' => $_SESSION['user_id'] ?? 0,
            'ip' => Security::getClientIP(),
            'memory' => memory_get_usage(true),
        ];

        if ($this->asyncMode) {
            $this->logBuffer[] = $entry;

            if (count($this->logBuffer) >= $this->bufferSize) {
                $this->flush();
            }
        } else {
            $this->writeLog($entry);
        }
    }

    public function info($message, $context = [])
    {
        $this->log($message, 'info', $context);
    }

    public function warning($message, $context = [])
    {
        $this->log($message, 'warning', $context);
    }

    public function error($message, $context = [])
    {
        $this->log($message, 'error', $context);
    }

    public function debug($message, $context = [])
    {
        if (defined('DEBUG') && DEBUG) {
            $this->log($message, 'debug', $context);
        }
    }

    public function flush()
    {
        if (empty($this->logBuffer)) {
            return;
        }

        $logsToWrite = $this->logBuffer;
        $this->logBuffer = [];

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        foreach ($logsToWrite as $entry) {
            $this->writeLog($entry);
        }
    }

    private function writeLog($entry)
    {
        $formatted = sprintf(
            "[%s] [%s] [UID:%d] [IP:%s] [MEM:%d] %s %s\n",
            $entry['datetime'],
            strtoupper($entry['level']),
            $entry['user_id'],
            $entry['ip'],
            $entry['memory'],
            $entry['message'],
            !empty($entry['context']) ? json_encode($entry['context'], JSON_UNESCAPED_UNICODE) : ''
        );

        $fp = fopen($this->logFile, 'a');
        if ($fp) {
            if (flock($fp, LOCK_EX)) {
                fwrite($fp, $formatted);
                fflush($fp);
                flock($fp, LOCK_UN);
            }
            fclose($fp);
        }

        $this->rotateLogs();
    }

    private function rotateLogs()
    {
        $maxSize = 10 * 1024 * 1024;
        $maxFiles = 10;

        if (file_exists($this->logFile) && filesize($this->logFile) > $maxSize) {
            $timestamp = date('Ymd_His');
            $rotatedFile = dirname($this->logFile) . DIRECTORY_SEPARATOR . 'app_' . $timestamp . '.log';

            if (file_exists($this->logFile)) {
                rename($this->logFile, $rotatedFile);
            }

            $this->cleanupOldLogs($maxFiles);
        }
    }

    private function cleanupOldLogs($maxFiles)
    {
        $logDir = dirname($this->logFile);
        $logFiles = glob($logDir . DIRECTORY_SEPARATOR . 'app_*.log');

        if (count($logFiles) > $maxFiles) {
            usort($logFiles, function($a, $b) {
                return filemtime($b) - filemtime($a);
            });

            $toDelete = array_slice($logFiles, $maxFiles);
            foreach ($toDelete as $file) {
                @unlink($file);
            }
        }
    }

    public function getRecentLogs($lines = 100)
    {
        if (!file_exists($this->logFile)) {
            return [];
        }

        $file = new \SplFileObject($this->logFile);
        $file->seek(PHP_INT_MAX);
        $total = $file->key();

        $start = max(0, $total - $lines);
        $logs = [];

        for ($i = $start; $i < $total; $i++) {
            $file->seek($i);
            $line = $file->current();
            if ($line) {
                $logs[] = $line;
            }
        }

        return array_reverse($logs);
    }

    public function getLogStats()
    {
        $stats = [
            'total_logs' => 0,
            'file_size' => 0,
            'buffer_size' => count($this->logBuffer),
            'by_level' => [
                'info' => 0,
                'warning' => 0,
                'error' => 0,
                'debug' => 0,
            ],
        ];

        if (file_exists($this->logFile)) {
            $stats['file_size'] = filesize($this->logFile);
            $stats['total_logs'] = count(file($this->logFile));

            $content = file_get_contents($this->logFile);
            foreach (['info', 'warning', 'error', 'debug'] as $level) {
                $stats['by_level'][$level] = substr_count($content, '[' . strtoupper($level) . ']');
            }
        }

        return $stats;
    }
}
