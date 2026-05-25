<?php

namespace App\Services;

use App\Core\Database;
use App\Core\Security;
use App\Core\Config;

class CloudStorageService
{
    private $db;
    private $config;
    private $storageConfig;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->config = Config::getInstance();
        $this->loadStorageConfig();
    }

    private function loadStorageConfig()
    {
        $configFile = DATA_PATH . DIRECTORY_SEPARATOR . 'cloud_storage.json';
        if (file_exists($configFile)) {
            $data = json_decode(file_get_contents($configFile), true);
            if (is_array($data)) {
                $this->storageConfig = $data;
                return;
            }
        }
        $this->storageConfig = [
            'provider' => '',
            'enabled' => false,
            'credentials' => [],
            'migration_status' => 'none',
            'local_files_pending' => false,
        ];
    }

    private function saveStorageConfig()
    {
        $configFile = DATA_PATH . DIRECTORY_SEPARATOR . 'cloud_storage.json';
        file_put_contents($configFile, json_encode($this->storageConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function getConfig()
    {
        $cfg = $this->storageConfig;
        if (!empty($cfg['credentials'])) {
            foreach ($cfg['credentials'] as $key => $value) {
                if (in_array($key, ['secret_key', 'access_key_secret', 'secret']) && strlen($value) > 8) {
                    $cfg['credentials'][$key] = substr($value, 0, 4) . str_repeat('*', strlen($value) - 8) . substr($value, -4);
                }
            }
        }
        return $cfg;
    }

    public function getProviders()
    {
        return [
            [
                'id' => 'aliyun_oss',
                'name' => '阿里云 OSS',
                'icon' => 'aliyun',
                'fields' => [
                    ['key' => 'access_key_id', 'label' => 'AccessKey ID', 'type' => 'text'],
                    ['key' => 'access_key_secret', 'label' => 'AccessKey Secret', 'type' => 'password'],
                    ['key' => 'endpoint', 'label' => 'Endpoint', 'type' => 'text', 'placeholder' => 'oss-cn-hangzhou.aliyuncs.com'],
                    ['key' => 'bucket', 'label' => 'Bucket', 'type' => 'text'],
                    ['key' => 'prefix', 'label' => '存储路径前缀', 'type' => 'text', 'placeholder' => 'yoliark/'],
                ],
            ],
            [
                'id' => 'tencent_cos',
                'name' => '腾讯云 COS',
                'icon' => 'tencent',
                'fields' => [
                    ['key' => 'secret_id', 'label' => 'SecretId', 'type' => 'text'],
                    ['key' => 'secret_key', 'label' => 'SecretKey', 'type' => 'password'],
                    ['key' => 'region', 'label' => 'Region', 'type' => 'text', 'placeholder' => 'ap-guangzhou'],
                    ['key' => 'bucket', 'label' => 'Bucket', 'type' => 'text', 'placeholder' => 'bucket-1250000000'],
                    ['key' => 'prefix', 'label' => '存储路径前缀', 'type' => 'text', 'placeholder' => 'yoliark/'],
                ],
            ],
            [
                'id' => 'aws_s3',
                'name' => 'AWS S3',
                'icon' => 'aws',
                'fields' => [
                    ['key' => 'access_key', 'label' => 'Access Key', 'type' => 'text'],
                    ['key' => 'secret_key', 'label' => 'Secret Key', 'type' => 'password'],
                    ['key' => 'region', 'label' => 'Region', 'type' => 'text', 'placeholder' => 'us-east-1'],
                    ['key' => 'bucket', 'label' => 'Bucket', 'type' => 'text'],
                    ['key' => 'prefix', 'label' => '存储路径前缀', 'type' => 'text', 'placeholder' => 'yoliark/'],
                    ['key' => 'endpoint', 'label' => '自定义 Endpoint (可选)', 'type' => 'text', 'placeholder' => '留空使用AWS默认'],
                ],
            ],
            [
                'id' => 'qiniu_kodo',
                'name' => '七牛云 Kodo',
                'icon' => 'qiniu',
                'fields' => [
                    ['key' => 'access_key', 'label' => 'AccessKey', 'type' => 'text'],
                    ['key' => 'secret_key', 'label' => 'SecretKey', 'type' => 'password'],
                    ['key' => 'bucket', 'label' => 'Bucket', 'type' => 'text'],
                    ['key' => 'domain', 'label' => '绑定域名', 'type' => 'text', 'placeholder' => 'https://cdn.example.com'],
                    ['key' => 'prefix', 'label' => '存储路径前缀', 'type' => 'text', 'placeholder' => 'yoliark/'],
                ],
            ],
        ];
    }

    public function saveConfig($provider, $credentials, $enabled = true)
    {
        $validProviders = ['aliyun_oss', 'tencent_cos', 'aws_s3', 'qiniu_kodo'];
        if (!in_array($provider, $validProviders)) {
            return ['success' => false, 'message' => '不支持的云存储服务商'];
        }

        $this->storageConfig['provider'] = $provider;
        $this->storageConfig['credentials'] = $credentials;
        $this->storageConfig['enabled'] = $enabled;
        $this->storageConfig['updated_at'] = time();

        $this->saveStorageConfig();

        return ['success' => true, 'message' => '云存储配置已保存'];
    }

    public function testConnection($provider, $credentials)
    {
        switch ($provider) {
            case 'aliyun_oss':
                return $this->testAliyunOSS($credentials);
            case 'tencent_cos':
                return $this->testTencentCOS($credentials);
            case 'aws_s3':
                return $this->testAWSS3($credentials);
            case 'qiniu_kodo':
                return $this->testQiniuKodo($credentials);
            default:
                return ['success' => false, 'message' => '不支持的服务商'];
        }
    }

    private function testAliyunOSS($cred)
    {
        $accessKeyId = $cred['access_key_id'] ?? '';
        $accessKeySecret = $cred['access_key_secret'] ?? '';
        $endpoint = $cred['endpoint'] ?? '';
        $bucket = $cred['bucket'] ?? '';

        if (empty($accessKeyId) || empty($accessKeySecret) || empty($endpoint) || empty($bucket)) {
            return ['success' => false, 'message' => '请填写完整的配置信息'];
        }

        $url = "https://{$bucket}.{$endpoint}/?max-keys=1";
        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $stringToSign = "GET\n\n\n{$date}\n/{$bucket}/";
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $accessKeySecret, true));
        $authorization = "OSS {$accessKeyId}:{$signature}";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Date: ' . $date,
                'Authorization: ' . $authorization,
            ],
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'message' => '连接失败: ' . $error];
        }

        if ($httpCode === 200 || $httpCode === 204) {
            return ['success' => true, 'message' => '阿里云 OSS 连接成功'];
        }

        return ['success' => false, 'message' => '连接失败 (HTTP ' . $httpCode . ')，请检查配置'];
    }

    private function testTencentCOS($cred)
    {
        $secretId = $cred['secret_id'] ?? '';
        $secretKey = $cred['secret_key'] ?? '';
        $region = $cred['region'] ?? '';
        $bucket = $cred['bucket'] ?? '';

        if (empty($secretId) || empty($secretKey) || empty($region) || empty($bucket)) {
            return ['success' => false, 'message' => '请填写完整的配置信息'];
        }

        $url = "https://{$bucket}.cos.{$region}.myqcloud.com/?max-keys=1";
        $timestamp = time();
        $startTime = $timestamp - 60;
        $endTime = $timestamp + 3600;
        $keyTime = "{$startTime};{$endTime}";
        $signKey = hash_hmac('sha1', $keyTime, $secretKey);
        $httpString = "get\n/\n\n\n";
        $stringToSign = "sha1\n{$keyTime}\n" . sha1($httpString) . "\n";
        $signature = hash_hmac('sha1', $stringToSign, $signKey);

        $authorization = "q-sign-algorithm=sha1&q-ak={$secretId}&q-sign-time={$keyTime}&q-key-time={$keyTime}&q-header-list=&q-url-param-list=&q-signature={$signature}";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Authorization: ' . $authorization],
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'message' => '连接失败: ' . $error];
        }

        if ($httpCode === 200) {
            return ['success' => true, 'message' => '腾讯云 COS 连接成功'];
        }

        return ['success' => false, 'message' => '连接失败 (HTTP ' . $httpCode . ')，请检查配置'];
    }

    private function testAWSS3($cred)
    {
        $accessKey = $cred['access_key'] ?? '';
        $secretKey = $cred['secret_key'] ?? '';
        $region = $cred['region'] ?? 'us-east-1';
        $bucket = $cred['bucket'] ?? '';
        $endpoint = $cred['endpoint'] ?? '';

        if (empty($accessKey) || empty($secretKey) || empty($bucket)) {
            return ['success' => false, 'message' => '请填写完整的配置信息'];
        }

        if (!empty($endpoint)) {
            $url = rtrim($endpoint, '/') . "/{$bucket}?max-keys=1";
        } else {
            $url = "https://{$bucket}.s3.{$region}.amazonaws.com?max-keys=1";
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'message' => '连接失败: ' . $error];
        }

        if ($httpCode === 200) {
            return ['success' => true, 'message' => 'AWS S3 连接成功'];
        }

        return ['success' => false, 'message' => '连接失败 (HTTP ' . $httpCode . ')，请检查配置'];
    }

    private function testQiniuKodo($cred)
    {
        $accessKey = $cred['access_key'] ?? '';
        $secretKey = $cred['secret_key'] ?? '';
        $bucket = $cred['bucket'] ?? '';

        if (empty($accessKey) || empty($secretKey) || empty($bucket)) {
            return ['success' => false, 'message' => '请填写完整的配置信息'];
        }

        $url = 'https://rs.qiniu.com/buckets';
        $accessToken = $this->qiniuSign($accessKey, $secretKey, 'https://rs.qiniu.com/buckets', null, 'application/json');

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Authorization: Qiniu ' . $accessToken, 'Content-Type: application/json'],
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'message' => '连接失败: ' . $error];
        }

        if ($httpCode === 200) {
            $buckets = json_decode($response, true);
            if (is_array($buckets) && in_array($bucket, $buckets)) {
                return ['success' => true, 'message' => '七牛云 Kodo 连接成功'];
            }
            return ['success' => true, 'message' => '七牛云认证成功，但未找到指定 Bucket'];
        }

        return ['success' => false, 'message' => '连接失败 (HTTP ' . $httpCode . ')，请检查配置'];
    }

    private function qiniuSign($accessKey, $secretKey, $url, $body = null, $contentType = null)
    {
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? '/';
        $query = $parsed['query'] ?? '';

        $data = $path;
        if (!empty($query)) {
            $data .= '?' . $query;
        }
        if ($body !== null && $contentType !== null && strpos($contentType, 'application/json') !== false) {
            $data .= "\n" . $body;
        }

        $sign = hash_hmac('sha1', $data, $secretKey, true);
        return $accessKey . ':' . \base64_encode($sign);
    }

    public function migrateFiles($action = 'migrate')
    {
        $userId = $_SESSION['user_id'] ?? 0;
        if (!$userId) {
            return ['success' => false, 'message' => '未登录'];
        }

        if (empty($this->storageConfig['provider']) || !$this->storageConfig['enabled']) {
            return ['success' => false, 'message' => '云存储未配置或未启用'];
        }

        if ($action === 'skip') {
            $this->storageConfig['migration_status'] = 'skipped';
            $this->storageConfig['local_files_pending'] = true;
            $this->saveStorageConfig();
            return ['success' => true, 'message' => '已跳过迁移，本地文件保持不变'];
        }

        $this->storageConfig['migration_status'] = 'pending';
        $this->saveStorageConfig();

        return ['success' => true, 'message' => '迁移任务已创建，将在后台执行'];
    }

    public function getMigrationStatus()
    {
        return [
            'status' => $this->storageConfig['migration_status'] ?? 'none',
            'local_files_pending' => $this->storageConfig['local_files_pending'] ?? false,
            'provider' => $this->storageConfig['provider'] ?? '',
            'enabled' => $this->storageConfig['enabled'] ?? false,
        ];
    }

    public function disableCloudStorage()
    {
        $this->storageConfig['enabled'] = false;
        $this->saveStorageConfig();
        return ['success' => true, 'message' => '云存储已禁用，文件将存储在本地'];
    }

    public function enableCloudStorage()
    {
        if (empty($this->storageConfig['provider'])) {
            return ['success' => false, 'message' => '请先配置云存储'];
        }
        $this->storageConfig['enabled'] = true;
        $this->saveStorageConfig();
        return ['success' => true, 'message' => '云存储已启用'];
    }

    public function uploadToCloud($localPath, $remotePath)
    {
        if (!$this->storageConfig['enabled']) {
            return ['success' => false, 'message' => '云存储未启用'];
        }

        if (!file_exists($localPath)) {
            return ['success' => false, 'message' => '本地文件不存在'];
        }

        $provider = $this->storageConfig['provider'];
        $cred = $this->storageConfig['credentials'];
        $prefix = rtrim($cred['prefix'] ?? '', '/') . '/';
        $fullRemotePath = $prefix . ltrim($remotePath, '/');

        switch ($provider) {
            case 'aliyun_oss':
                return $this->uploadToAliyunOSS($localPath, $fullRemotePath, $cred);
            case 'tencent_cos':
                return $this->uploadToTencentCOS($localPath, $fullRemotePath, $cred);
            case 'aws_s3':
                return $this->uploadToAWSS3($localPath, $fullRemotePath, $cred);
            case 'qiniu_kodo':
                return $this->uploadToQiniuKodo($localPath, $fullRemotePath, $cred);
            default:
                return ['success' => false, 'message' => '不支持的服务商'];
        }
    }

    private function uploadToAliyunOSS($localPath, $remotePath, $cred)
    {
        $endpoint = $cred['endpoint'] ?? '';
        $bucket = $cred['bucket'] ?? '';
        $accessKeyId = $cred['access_key_id'] ?? '';
        $accessKeySecret = $cred['access_key_secret'] ?? '';

        $url = "https://{$bucket}.{$endpoint}/" . ltrim($remotePath, '/');
        $content = file_get_contents($localPath);
        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $contentType = mime_content_type($localPath) ?: 'application/octet-stream';
        $contentMd5 = base64_encode(md5($content, true));

        $stringToSign = "PUT\n{$contentMd5}\n{$contentType}\n{$date}\n/{$bucket}/" . ltrim($remotePath, '/');
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $accessKeySecret, true));
        $authorization = "OSS {$accessKeyId}:{$signature}";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $content,
            CURLOPT_HTTPHEADER => [
                'Date: ' . $date,
                'Authorization: ' . $authorization,
                'Content-Type: ' . $contentType,
                'Content-MD5: ' . $contentMd5,
            ],
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            return ['success' => true, 'message' => '上传成功'];
        }
        return ['success' => false, 'message' => '上传失败 (HTTP ' . $httpCode . ')'];
    }

    private function uploadToTencentCOS($localPath, $remotePath, $cred)
    {
        $region = $cred['region'] ?? '';
        $bucket = $cred['bucket'] ?? '';
        $secretId = $cred['secret_id'] ?? '';
        $secretKey = $cred['secret_key'] ?? '';

        $url = "https://{$bucket}.cos.{$region}.myqcloud.com/" . ltrim($remotePath, '/');
        $content = file_get_contents($localPath);
        $contentType = mime_content_type($localPath) ?: 'application/octet-stream';

        $timestamp = time();
        $startTime = $timestamp - 60;
        $endTime = $timestamp + 3600;
        $keyTime = "{$startTime};{$endTime}";
        $signKey = hash_hmac('sha1', $keyTime, $secretKey);
        $httpString = "put\n/" . ltrim($remotePath, '/') . "\n\ncontent-type=" . $contentType . "\n";
        $stringToSign = "sha1\n{$keyTime}\n" . sha1($httpString) . "\n";
        $signature = hash_hmac('sha1', $stringToSign, $signKey);

        $authorization = "q-sign-algorithm=sha1&q-ak={$secretId}&q-sign-time={$keyTime}&q-key-time={$keyTime}&q-header-list=content-type&q-url-param-list=&q-signature={$signature}";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $content,
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $authorization,
                'Content-Type: ' . $contentType,
            ],
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            return ['success' => true, 'message' => '上传成功'];
        }
        return ['success' => false, 'message' => '上传失败 (HTTP ' . $httpCode . ')'];
    }

    private function uploadToAWSS3($localPath, $remotePath, $cred)
    {
        return ['success' => false, 'message' => 'AWS S3 上传需要配置 AWS SDK，请使用 composer 安装 aws-sdk-php'];
    }

    private function uploadToQiniuKodo($localPath, $remotePath, $cred)
    {
        $accessKey = $cred['access_key'] ?? '';
        $secretKey = $cred['secret_key'] ?? '';
        $bucket = $cred['bucket'] ?? '';
        $domain = $cred['domain'] ?? '';

        $uploadUrl = 'https://up.qiniup.com/putb64/-1/key/' . \base64_encode($remotePath);
        $content = file_get_contents($localPath);
        $encodedContent = base64_encode($content);

        $uploadToken = $this->qiniuUploadToken($accessKey, $secretKey, $bucket);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $uploadUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $encodedContent,
            CURLOPT_HTTPHEADER => [
                'Authorization: UpToken ' . $uploadToken,
                'Content-Type: application/octet-stream',
            ],
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            return ['success' => true, 'message' => '上传成功'];
        }
        return ['success' => false, 'message' => '上传失败 (HTTP ' . $httpCode . ')'];
    }

    private function qiniuUploadToken($accessKey, $secretKey, $bucket)
    {
        $deadline = time() + 3600;
        $policy = ['scope' => $bucket, 'deadline' => $deadline];
        $encodedPolicy = \base64_encode(json_encode($policy));
        $encodedPolicy = str_replace(['+', '/'], ['-', '_'], $encodedPolicy);
        $sign = hash_hmac('sha1', $encodedPolicy, $secretKey, true);
        $encodedSign = str_replace(['+', '/'], ['-', '_'], \base64_encode($sign));
        return $accessKey . ':' . $encodedSign . ':' . $encodedPolicy;
    }
}
