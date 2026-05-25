<?php

namespace App\Services;

use App\Core\Security;

class CloudSyncService
{
    protected $apiUrl = 'https://api.hiyy.top/api/endpoint.php';
    protected $cacheTime = 600;

    public function syncConfig()
    {
        if (!empty($_SESSION['cloud_config_time']) && (time() - $_SESSION['cloud_config_time']) < $this->cacheTime) {
            return $_SESSION['cloud_config'] ?? null;
        }

        $config = $this->fetchCloudConfig();
        if ($config) {
            $_SESSION['cloud_config'] = $config;
            $_SESSION['cloud_config_time'] = time();
        }

        return $config;
    }

    protected function fetchCloudConfig()
    {
        $ctx = stream_context_create(['http' => ['timeout' => 3]]);
        $cloudData = @file_get_contents($this->apiUrl . '/CA', false, $ctx);
        if ($cloudData === false) {
            return null;
        }

        $cloudJson = json_decode($cloudData, true);
        if (!is_array($cloudJson)) {
            return null;
        }

        return [
            'site_name' => $cloudJson['site_name'] ?? '柚舟Cloud',
            'site_desc' => $cloudJson['site_desc'] ?? '',
            'notice' => $cloudJson['CA'] ?? '',
            'notice_hash' => md5($cloudJson['CA'] ?? ''),
            'max_upload_size' => intval($cloudJson['max_upload_size'] ?? 0),
            'maintenance' => ($cloudJson['maintenance'] ?? '0') === '1',
        ];
    }
}
