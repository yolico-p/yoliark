<?php

namespace App\Controllers\System;

use App\Controllers\BaseController;
use App\Core\Security;
use App\Services\CloudStorageService;

class CloudStorageController extends BaseController
{
    public function getConfig()
    {
        $this->requireAuth();

        $service = new CloudStorageService();
        $config = $service->getConfig();
        $providers = $service->getProviders();
        $migration = $service->getMigrationStatus();

        Security::jsonOutput([
            'success' => true,
            'config' => $config,
            'providers' => $providers,
            'migration' => $migration,
        ]);
    }

    public function saveConfig()
    {
        $this->requireAuth();
        $this->validateCSRF();

        $provider = $this->input('provider', '');
        $credentials = $this->input('credentials', []);
        $enabled = $this->input('enabled', true);

        if (!is_array($credentials)) {
            $credentials = json_decode($credentials, true) ?: [];
        }

        $service = new CloudStorageService();
        $result = $service->saveConfig($provider, $credentials, $enabled);

        Security::jsonOutput($result);
    }

    public function testConnection()
    {
        $this->requireAuth();
        $this->validateCSRF();

        $provider = $this->input('provider', '');
        $credentials = $this->input('credentials', []);

        if (!is_array($credentials)) {
            $credentials = json_decode($credentials, true) ?: [];
        }

        $service = new CloudStorageService();
        $result = $service->testConnection($provider, $credentials);

        Security::jsonOutput($result);
    }

    public function migrate()
    {
        $this->requireAuth();
        $this->validateCSRF();

        $action = $this->input('action', 'migrate');

        $service = new CloudStorageService();
        $result = $service->migrateFiles($action);

        Security::jsonOutput($result);
    }

    public function disable()
    {
        $this->requireAuth();
        $this->validateCSRF();

        $service = new CloudStorageService();
        $result = $service->disableCloudStorage();

        Security::jsonOutput($result);
    }

    public function enable()
    {
        $this->requireAuth();
        $this->validateCSRF();

        $service = new CloudStorageService();
        $result = $service->enableCloudStorage();

        Security::jsonOutput($result);
    }
}
