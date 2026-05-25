<?php

namespace App\Core;

class AsyncTaskQueue
{
    private static $instance = null;
    private $queue = [];
    private $queueFile;
    private $maxQueueSize = 1000;
    private $batchSize = 10;

    private function __construct()
    {
        $this->queueFile = DATA_PATH . DIRECTORY_SEPARATOR . 'async_queue.json';
        $this->loadQueue();
        register_shutdown_function([$this, 'saveQueue']);
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new static();
        }
        return self::$instance;
    }

    private function loadQueue()
    {
        if (file_exists($this->queueFile)) {
            $content = file_get_contents($this->queueFile);
            $this->queue = json_decode($content, true) ?: [];
        }
    }

    public function saveQueue()
    {
        $tempFile = $this->queueFile . '.tmp';
        $fp = fopen($tempFile, 'c+');
        if ($fp) {
            if (flock($fp, LOCK_EX)) {
                ftruncate($fp, 0);
                fwrite($fp, json_encode(array_slice($this->queue, 0, $this->maxQueueSize)));
                fflush($fp);
                flock($fp, LOCK_UN);
            }
            fclose($fp);
            rename($tempFile, $this->queueFile);
        }
    }

    public function enqueue($taskType, $data, $priority = 5)
    {
        $task = [
            'id' => uniqid('task_', true),
            'type' => $taskType,
            'data' => $data,
            'priority' => $priority,
            'created_at' => time(),
            'status' => 'pending',
            'retries' => 0,
        ];

        $this->queue[] = $task;
        usort($this->queue, function($a, $b) {
            if ($a['priority'] === $b['priority']) {
                return $a['created_at'] - $b['created_at'];
            }
            return $b['priority'] - $a['priority'];
        });

        if (count($this->queue) > $this->maxQueueSize) {
            array_shift($this->queue);
        }

        return $task['id'];
    }

    public function dequeue($count = 1)
    {
        $tasks = [];
        $remainingQueue = [];

        foreach ($this->queue as $task) {
            if ($task['status'] === 'pending' && count($tasks) < $count) {
                $task['status'] = 'processing';
                $tasks[] = $task;
            } else {
                $remainingQueue[] = $task;
            }
        }

        $this->queue = $remainingQueue;
        return $tasks;
    }

    public function complete($taskId, $result = null)
    {
        foreach ($this->queue as &$task) {
            if ($task['id'] === $taskId) {
                $task['status'] = 'completed';
                $task['completed_at'] = time();
                $task['result'] = $result;
                break;
            }
        }
    }

    public function fail($taskId, $error = null)
    {
        foreach ($this->queue as &$task) {
            if ($task['id'] === $taskId) {
                $task['retries']++;
                if ($task['retries'] >= 3) {
                    $task['status'] = 'failed';
                    $task['error'] = $error;
                } else {
                    $task['status'] = 'pending';
                }
                break;
            }
        }
    }

    public function process()
    {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        $tasks = $this->dequeue($this->batchSize);
        $logger = AsyncLogger::getInstance();

        foreach ($tasks as $task) {
            try {
                $result = $this->executeTask($task);
                $this->complete($task['id'], $result);
                $logger->info("Task {$task['id']} completed", ['type' => $task['type']]);
            } catch (\Exception $e) {
                $this->fail($task['id'], $e->getMessage());
                $logger->error("Task {$task['id']} failed: " . $e->getMessage());
            }
        }

        $this->saveQueue();
    }

    private function executeTask($task)
    {
        switch ($task['type']) {
            case 'hash_calculation':
                return $this->calculateHash($task['data']);

            case 'thumbnail_generation':
                return $this->generateThumbnail($task['data']);

            case 'file_compression':
                return $this->compressFile($task['data']);

            case 'log_flush':
                return AsyncLogger::getInstance()->flush();

            case 'cache_cleanup':
                return $this->cleanupCache($task['data']);

            default:
                throw new \Exception("Unknown task type: {$task['type']}");
        }
    }

    private function calculateHash($data)
    {
        $filePath = $data['file_path'];
        $fileId = $data['file_id'];

        if (!file_exists($filePath)) {
            return false;
        }

        $hash = hash_file('sha256', $filePath);
        if ($hash) {
            $db = Database::getInstance();
            $db->update('files', ['content_hash' => $hash, 'updated_at' => time()], 'id = ?', [$fileId]);
            return $hash;
        }

        return false;
    }

    private function generateThumbnail($data)
    {
        $fileId = $data['file_id'];
        $filePath = $data['file_path'];
        $ext = $data['ext'];

        $thumbnailService = new \App\Services\ThumbnailService();
        $cacheKey = md5($fileId . '_' . filesize($filePath));
        $result = $thumbnailService->generate($filePath, $ext, $cacheKey);

        return $result !== null;
    }

    private function compressFile($data)
    {
        $filePath = $data['file_path'];
        $outputPath = $data['output_path'];

        if (!file_exists($filePath)) {
            return false;
        }

        $zip = new \ZipArchive();
        if ($zip->open($outputPath, \ZipArchive::CREATE) === true) {
            $zip->addFile($filePath, basename($filePath));
            $zip->close();
            return true;
        }

        return false;
    }

    private function cleanupCache($data)
    {
        $cacheDir = STORAGE_PATH . DIRECTORY_SEPARATOR . 'cache';
        if (is_dir($cacheDir)) {
            $files = glob($cacheDir . DIRECTORY_SEPARATOR . '*');
            foreach ($files as $file) {
                if (is_file($file) && filemtime($file) < (time() - 86400 * 7)) {
                    @unlink($file);
                }
            }
        }
        return true;
    }

    public function getQueueStats()
    {
        $stats = [
            'total' => count($this->queue),
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0,
        ];

        foreach ($this->queue as $task) {
            $stats[$task['status']]++;
        }

        return $stats;
    }

    public function clearCompleted()
    {
        $this->queue = array_filter($this->queue, function($task) {
            return $task['status'] !== 'completed';
        });
        $this->queue = array_values($this->queue);
    }
}
