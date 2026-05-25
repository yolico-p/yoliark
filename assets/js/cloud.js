/**
 * 云端存储配置 + AI 模型配置 + 账户管理
 */

const cloudProviderFields = {
    aliyun_oss: [
        { key: 'access_key_id', label: 'AccessKey ID', type: 'text' },
        { key: 'access_key_secret', label: 'AccessKey Secret', type: 'password' },
        { key: 'endpoint', label: 'Endpoint', type: 'text', placeholder: 'oss-cn-hangzhou.aliyuncs.com' },
        { key: 'bucket', label: 'Bucket', type: 'text' },
        { key: 'prefix', label: '存储路径前缀', type: 'text', placeholder: 'yoliark/' },
    ],
    tencent_cos: [
        { key: 'secret_id', label: 'SecretId', type: 'text' },
        { key: 'secret_key', label: 'SecretKey', type: 'password' },
        { key: 'region', label: 'Region', type: 'text', placeholder: 'ap-guangzhou' },
        { key: 'bucket', label: 'Bucket', type: 'text', placeholder: 'bucket-1250000000' },
        { key: 'prefix', label: '存储路径前缀', type: 'text', placeholder: 'yoliark/' },
    ],
    aws_s3: [
        { key: 'access_key', label: 'Access Key', type: 'text' },
        { key: 'secret_key', label: 'Secret Key', type: 'password' },
        { key: 'region', label: 'Region', type: 'text', placeholder: 'us-east-1' },
        { key: 'bucket', label: 'Bucket', type: 'text' },
        { key: 'prefix', label: '存储路径前缀', type: 'text', placeholder: 'yoliark/' },
        { key: 'endpoint', label: '自定义 Endpoint (可选)', type: 'text', placeholder: '留空使用AWS默认' },
    ],
    qiniu_kodo: [
        { key: 'access_key', label: 'AccessKey', type: 'text' },
        { key: 'secret_key', label: 'SecretKey', type: 'password' },
        { key: 'bucket', label: 'Bucket', type: 'text' },
        { key: 'domain', label: '绑定域名', type: 'text', placeholder: 'https://cdn.example.com' },
        { key: 'prefix', label: '存储路径前缀', type: 'text', placeholder: 'yoliark/' },
    ],
};

function onCloudProviderChange() {
    const provider = document.getElementById('cloudProvider').value;
    const container = document.getElementById('cloudFieldsContainer');
    container.innerHTML = '';

    if (!provider || !cloudProviderFields[provider]) return;

    const fields = cloudProviderFields[provider];
    fields.forEach(f => {
        const row = document.createElement('div');
        row.className = 'settings-row';
        row.innerHTML = '<label>' + f.label + '</label><input type="' + f.type + '" id="cloud_' + f.key + '" placeholder="' + (f.placeholder || '') + '" style="flex:1;padding:8px 12px;border-radius:8px;border:1px solid var(--border-color);background:var(--card-bg);color:var(--text-primary);font-size:14px">';
        container.appendChild(row);
    });
}

function getCloudCredentials() {
    const provider = document.getElementById('cloudProvider').value;
    if (!provider || !cloudProviderFields[provider]) return {};
    const cred = {};
    cloudProviderFields[provider].forEach(f => {
        const el = document.getElementById('cloud_' + f.key);
        if (el) cred[f.key] = el.value;
    });
    return cred;
}

function testCloudConnection() {
    const provider = document.getElementById('cloudProvider').value;
    if (!provider) { showToast('请先选择服务商', 'error'); return; }
    const credentials = getCloudCredentials();
    api('cloud_storage_test', { provider, credentials }).then(data => {
        showToast(data.message, data.success ? 'success' : 'error');
    });
}

function saveCloudConfig() {
    const provider = document.getElementById('cloudProvider').value;
    if (!provider) { showToast('请先选择服务商', 'error'); return; }
    const credentials = getCloudCredentials();
    api('cloud_storage_save', { provider, credentials, enabled: true }).then(data => {
        showToast(data.message, data.success ? 'success' : 'error');
    });
}

function migrateCloud(action) {
    api('cloud_storage_migrate', { action }).then(data => {
        showToast(data.message, data.success ? 'success' : 'error');
        document.getElementById('cloudMigrationNotice').style.display = 'none';
    });
}

const aiProviders = {
    zhipu: { name: '智谱AI (GLM)', base_url: 'https://open.bigmodel.cn/api/paas/v4', desc: 'GLM 系列模型，GLM-4-Flash 免费使用' },
    deepseek: { name: 'DeepSeek', base_url: 'https://api.deepseek.com', desc: 'DeepSeek-V4 系列，代码能力极强' },
    siliconflow: { name: '硅基流动', base_url: 'https://api.siliconflow.cn/v1', desc: '聚合多款开源模型，部分免费' },
    moonshot: { name: 'Moonshot (Kimi)', base_url: 'https://api.moonshot.cn/v1', desc: 'Kimi 系列模型，长上下文' },
    qwen: { name: '通义千问', base_url: 'https://dashscope.aliyuncs.com/compatible-mode/v1', desc: '阿里云百炼，通义千问系列' },
    aiping: { name: 'AI Ping', base_url: 'https://aiping.cn/api/v1', desc: '聚合平台，GLM/MiniMax 等免费模型' },
    yi: { name: '零一万物 (Yi)', base_url: 'https://api.lingyiwanwu.com/v1', desc: 'Yi 系列模型' },
    ollama: { name: 'Ollama (本地)', base_url: 'http://localhost:11434/v1', desc: '本地部署模型，无需 API Key' },
    custom: { name: '自定义 (OpenAI 兼容)', base_url: '', desc: '任何兼容 OpenAI API 的服务' },
};

function loadAIConfig() {
    api('ai_agent_config', {}, 'GET').then(data => {
        if (data.success && data.config) {
            const c = data.config;
            if (c.provider) {
                document.getElementById('aiProvider').value = c.provider;
                onAIProviderChange();
            }
            if (c.api_key_set) {
                document.getElementById('aiApiKey').value = c.api_key;
            }
            if (c.model) {
                const select = document.getElementById('aiModel');
                let found = false;
                for (let i = 0; i < select.options.length; i++) {
                    if (select.options[i].value === c.model) {
                        select.value = c.model;
                        found = true;
                        break;
                    }
                }
                if (!found) {
                    const opt = document.createElement('option');
                    opt.value = c.model;
                    opt.textContent = c.model;
                    select.appendChild(opt);
                    select.value = c.model;
                    document.getElementById('aiModelHint').textContent = '当前模型: ' + c.model + '（点击「获取模型」刷新列表）';
                }
            }
            if (c.provider === 'custom' && c.base_url) {
                document.getElementById('aiCustomBaseUrl').value = c.base_url;
            }
        }
    });
}

function onAIProviderChange() {
    const provider = document.getElementById('aiProvider').value;
    const info = aiProviders[provider];
    document.getElementById('aiCustomUrl').style.display = provider === 'custom' ? 'flex' : 'none';
    document.getElementById('aiProviderDesc').textContent = info ? info.desc : '';
    document.getElementById('aiModel').innerHTML = '<option value="">请先获取模型列表</option>';
    document.getElementById('aiModelHint').textContent = provider === 'ollama'
        ? '本地模型无需 API Key，直接获取模型列表'
        : '填写 API Key 后点击「获取模型」查询可用模型';
}

function fetchAIModels() {
    const provider = document.getElementById('aiProvider').value;
    const info = aiProviders[provider];
    const apiKey = document.getElementById('aiApiKey').value;
    let baseUrl = info ? info.base_url : '';
    if (provider === 'custom') {
        baseUrl = document.getElementById('aiCustomBaseUrl').value;
    }

    if (!baseUrl) {
        showToast('请先填写 API 地址', 'error');
        return;
    }

    const btn = document.getElementById('fetchModelsBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 获取中';

    api('ai_agent_fetch_models', { api_key: apiKey, base_url: baseUrl }).then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-sync-alt"></i> 获取模型';

        if (data.success && data.models) {
            const select = document.getElementById('aiModel');
            select.innerHTML = '';
            if (data.models.length === 0) {
                select.innerHTML = '<option value="">无可用模型</option>';
                document.getElementById('aiModelHint').textContent = '该服务未返回可用模型';
                return;
            }
            data.models.forEach(m => {
                const opt = document.createElement('option');
                opt.value = m.id;
                opt.textContent = m.id + (m.owned_by ? ' (' + m.owned_by + ')' : '');
                select.appendChild(opt);
            });
            document.getElementById('aiModelHint').textContent = '已获取 ' + data.models.length + ' 个模型，请选择';
            showToast('已获取 ' + data.models.length + ' 个可用模型', 'success');
        } else {
            showToast(data.message || '获取模型列表失败', 'error');
            document.getElementById('aiModelHint').textContent = data.message || '获取失败，请检查 API Key 和地址';
        }
    }).catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-sync-alt"></i> 获取模型';
        showToast('网络错误，请重试: ' + (err.message || ''), 'error');
    });
}

function testAIConnection() {
    const provider = document.getElementById('aiProvider').value;
    const info = aiProviders[provider];
    const apiKey = document.getElementById('aiApiKey').value;
    let baseUrl = info ? info.base_url : '';
    if (provider === 'custom') {
        baseUrl = document.getElementById('aiCustomBaseUrl').value;
    }

    if (!baseUrl) {
        showToast('请先填写 API 地址', 'error');
        return;
    }

    const btn = document.getElementById('testConnBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 测试中';

    api('ai_agent_test_connection', { api_key: apiKey, base_url: baseUrl }).then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-plug"></i> 测试连接';

        if (data.success) {
            showToast(data.message, 'success');
        } else {
            let msg = data.message || '连接失败';
            if (data.debug) {
                msg += '\nDNS: ' + (data.debug.dns_time || '-') + ' 连接: ' + (data.debug.connect_time || '-') + ' 总计: ' + (data.debug.total_time || '-');
            }
            showToast(msg, 'error');
        }
    }).catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-plug"></i> 测试连接';
        showToast('请求失败: ' + (err.message || ''), 'error');
    });
}

function saveAIConfig() {
    const provider = document.getElementById('aiProvider').value;
    const apiKey = document.getElementById('aiApiKey').value;
    const model = document.getElementById('aiModel').value;
    const info = aiProviders[provider];
    let baseUrl = info ? info.base_url : '';
    if (provider === 'custom') {
        baseUrl = document.getElementById('aiCustomBaseUrl').value;
    }
    if (!model) {
        showToast('请先选择模型', 'error');
        return;
    }
    api('ai_agent_save', { api_key: apiKey, base_url: baseUrl, model, provider }).then(data => {
        showToast(data.message, data.success ? 'success' : 'error');
    });
}

function updateProfile(e) {
    e.preventDefault();
    api('update_profile', {email: document.getElementById('settingEmail').value}).then(data => {
        showToast(data.success ? '资料已更新' : data.message, data.success ? 'success' : 'error');
    });
    return false;
}

function changePassword(e) {
    e.preventDefault();
    const oldPwd = document.getElementById('oldPassword').value;
    const newPwd = document.getElementById('newPassword').value;
    const confirmPwd = document.getElementById('confirmPassword').value;

    if (newPwd !== confirmPwd) {
        showToast('两次输入的密码不一致', 'error');
        return false;
    }

    api('change_password', {
        old_password: oldPwd,
        new_password: newPwd,
        confirm_password: confirmPwd,
    }).then(data => {
        showToast(data.success ? '密码已修改' : data.message, data.success ? 'success' : 'error');
        if (data.success) {
            document.getElementById('passwordForm').reset();
        }
    });
    return false;
}

function handleLogout() {
    api('logout').then(() => { window.location.href = 'index.php?page=login'; });
}
