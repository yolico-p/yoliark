<?php

namespace App\Controllers\System;

use App\Controllers\BaseController;
use App\Core\Security;
use App\Services\AIAgentService;

class AIAgentController extends BaseController
{
    public function getConfig()
    {
        $this->requireAuth();

        $service = new AIAgentService();
        $config = $service->getAIConfig();

        Security::jsonOutput(['success' => true, 'config' => $config]);
    }

    public function saveConfig()
    {
        $this->requireAuth();
        $this->validateCSRF();

        $apiKey = $this->input('api_key', '');
        $baseUrl = $this->input('base_url', '');
        $model = $this->input('model', '');
        $provider = $this->input('provider', 'custom');

        $service = new AIAgentService();
        $result = $service->saveConfig($apiKey, $baseUrl, $model, $provider);

        Security::jsonOutput($result);
    }

    public function fetchModels()
    {
        $this->requireAuth();

        $apiKey = $this->input('api_key', '');
        $baseUrl = $this->input('base_url', '');

        if (empty($baseUrl)) {
            $this->error('请提供 API 地址');
        }

        $service = new AIAgentService();

        if (empty($apiKey) || strpos($apiKey, '*') !== false) {
            $configFile = DATA_PATH . DIRECTORY_SEPARATOR . 'ai_agent.json';
            if (file_exists($configFile)) {
                $existing = json_decode(file_get_contents($configFile), true) ?: [];
                if (strpos($apiKey, '*') !== false || empty($apiKey)) {
                    $apiKey = $existing['api_key'] ?? '';
                }
            }
        }

        $result = $service->fetchModels($apiKey, $baseUrl);
        Security::jsonOutput($result);
    }

    public function testConnection()
    {
        $this->requireAuth();

        $apiKey = $this->input('api_key', '');
        $baseUrl = $this->input('base_url', '');

        if (empty($baseUrl)) {
            $this->error('请提供 API 地址');
        }

        $service = new AIAgentService();

        if (empty($apiKey) || strpos($apiKey, '*') !== false) {
            $configFile = DATA_PATH . DIRECTORY_SEPARATOR . 'ai_agent.json';
            if (file_exists($configFile)) {
                $existing = json_decode(file_get_contents($configFile), true) ?: [];
                if (strpos($apiKey, '*') !== false || empty($apiKey)) {
                    $apiKey = $existing['api_key'] ?? '';
                }
            }
        }

        $result = $service->testConnection($apiKey, $baseUrl);
        Security::jsonOutput($result);
    }

    public function chat()
    {
        $this->requireAuth();

        $messages = $this->input('messages', []);
        if (!is_array($messages)) {
            $messages = json_decode($messages, true) ?: [];
        }

        if (empty($messages)) {
            $content = $this->input('message', '');
            if (empty($content)) {
                $this->error('消息不能为空');
            }
            $messages = [['role' => 'user', 'content' => $content]];
        }

        $service = new AIAgentService();
        $result = $service->chat($messages);

        Security::jsonOutput($result);
    }

    public function chatStream()
    {
        if (empty($_SESSION['user_id'])) {
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            echo "data: " . json_encode(['type' => 'error', 'message' => '请先登录'], JSON_UNESCAPED_UNICODE) . "\n\n";
            flush();
            return;
        }

        $messages = $this->input('messages', []);
        if (!is_array($messages)) {
            $messages = json_decode($messages, true) ?: [];
        }

        if (empty($messages)) {
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            echo "data: " . json_encode(['type' => 'error', 'message' => '消息不能为空'], JSON_UNESCAPED_UNICODE) . "\n\n";
            flush();
            return;
        }

        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', false);
        while (ob_get_level()) ob_end_clean();

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        $service = new AIAgentService();
        $service->chatStream($messages, function($type, $data) {
            echo "data: " . json_encode(array_merge(['type' => $type], $data), JSON_UNESCAPED_UNICODE) . "\n\n";
            if (ob_get_level()) ob_flush();
            flush();
        });
    }

    /**
     * 生成对话标题（异步调用）
     * 基于用户首条消息和 AI 首次回复生成简洁标题
     */
    public function generateTitle()
    {
        $this->requireAuth();

        $firstUserMsg = $this->input('firstUserMsg', '');
        $firstAiMsg = $this->input('firstAiMsg', '');

        if (empty($firstUserMsg)) {
            Security::jsonOutput(['success' => false, 'error' => '缺少用户消息']);
            return;
        }

        $service = new AIAgentService();
        $result = $service->generateTitle($firstUserMsg, $firstAiMsg);

        Security::jsonOutput($result);
    }
}
