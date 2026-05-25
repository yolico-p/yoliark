<?php
use App\Core\Security;
?>
                    <div id="settingsTabGeneral" class="settings-tab-content active">
                        <div class="settings-card glass">
                            <h3><i class="fas fa-cloud" style="margin-right:8px"></i>云存储对接</h3>
                            <div class="settings-row">
                                <label>存储服务商</label>
                                <select id="cloudProvider" onchange="onCloudProviderChange()">
                                    <option value="">未配置</option>
                                    <option value="aliyun_oss">阿里云 OSS</option>
                                    <option value="tencent_cos">腾讯云 COS</option>
                                    <option value="aws_s3">AWS S3</option>
                                    <option value="qiniu_kodo">七牛云 Kodo</option>
                                </select>
                            </div>
                            <div id="cloudFieldsContainer"></div>
                            <div class="settings-row" style="margin-top:12px">
                                <button class="btn btn-secondary" onclick="testCloudConnection()" style="flex:1"><i class="fas fa-plug" style="margin-right:4px"></i>测试连接</button>
                                <button class="btn btn-primary" onclick="saveCloudConfig()" style="flex:1"><i class="fas fa-save" style="margin-right:4px"></i>保存配置</button>
                            </div>
                            <div id="cloudMigrationNotice" style="display:none;margin-top:12px;padding:12px;background:var(--hover-bg);border-radius:8px;font-size:13px;color:var(--text-secondary)">
                                <i class="fas fa-exclamation-triangle" style="color:var(--warning-color);margin-right:4px"></i>
                                检测到本地已有文件，是否迁移到云存储？
                                <div style="margin-top:8px;display:flex;gap:8px">
                                    <button class="btn btn-primary" onclick="migrateCloud('migrate')" style="font-size:12px;padding:6px 12px">迁移文件</button>
                                    <button class="btn btn-secondary" onclick="migrateCloud('skip')" style="font-size:12px;padding:6px 12px">保留本地</button>
                                </div>
                            </div>
                        </div>

                        <div class="settings-card glass">
                            <h3><i class="fas fa-wand-magic-sparkles" style="margin-right:8px"></i>AI 云助手</h3>
                            <div class="settings-row">
                                <label>API 服务商</label>
                                <select id="aiProvider" onchange="onAIProviderChange()">
                                    <option value="zhipu">智谱AI (GLM)</option>
                                    <option value="deepseek">DeepSeek</option>
                                    <option value="siliconflow">硅基流动</option>
                                    <option value="moonshot">Moonshot (Kimi)</option>
                                    <option value="qwen">通义千问</option>
                                    <option value="aiping">AI Ping</option>
                                    <option value="yi">零一万物 (Yi)</option>
                                    <option value="ollama">Ollama (本地)</option>
                                    <option value="custom">自定义 (OpenAI 兼容)</option>
                                </select>
                            </div>
                            <small id="aiProviderDesc">GLM 系列模型，GLM-4-Flash 免费使用</small>
                            <div class="settings-row">
                                <label>API Key</label>
                                <input type="password" id="aiApiKey" placeholder="输入 API Key">
                            </div>
                            <div id="aiCustomUrl" style="display:none" class="settings-row">
                                <label>API 地址</label>
                                <input type="text" id="aiCustomBaseUrl" placeholder="https://api.example.com/v1">
                            </div>
                            <div class="settings-row">
                                <label>模型</label>
                                <div style="display:flex;gap:8px;flex:1">
                                    <select id="aiModel">
                                        <option value="">请先获取模型列表</option>
                                    </select>
                                    <button class="btn btn-glass btn-sm" onclick="fetchAIModels()" id="fetchModelsBtn"><i class="fas fa-sync-alt"></i> 获取模型</button>
                                </div>
                            </div>
                            <small id="aiModelHint">填写 API Key 后点击「获取模型」查询可用模型</small>
                            <div class="settings-row" style="gap:8px">
                                <button class="btn btn-glass btn-sm" onclick="testAIConnection()" id="testConnBtn" style="flex:1"><i class="fas fa-plug"></i> 测试连接</button>
                                <button class="btn btn-primary" onclick="saveAIConfig()" style="flex:2"><i class="fas fa-save" style="margin-right:4px"></i>保存 AI 配置</button>
                            </div>
                        </div>

                        <div class="settings-card glass">
                            <h3><i class="fas fa-info-circle" style="margin-right:8px"></i>应用信息</h3>
                            <div class="settings-row">
                                <label>应用名称</label>
                                <input type="text" id="cfg_app_name" value="<?php echo Security::escape($config->get('app_name')); ?>" placeholder="输入应用名称">
                            </div>
                            <div class="settings-row">
                                <label>调试模式</label>
                                <div class="toggle-switch">
                                    <input type="checkbox" id="cfg_debug" <?php echo $config->get('debug') ? 'checked' : ''; ?>>
                                    <label for="cfg_debug"><span></span></label>
                                </div>
                            </div>
                            <button class="btn btn-primary" onclick="saveConfig()"><i class="fas fa-save" style="margin-right:4px"></i>保存设置</button>
                        </div>
                        <div class="settings-card glass">
                            <h3><i class="fas fa-clock" style="margin-right:8px"></i>会话设置</h3>
                            <div class="settings-row">
                                <label>会话有效期（秒）</label>
                                <input type="number" id="cfg_session_lifetime" value="<?php echo $config->get('session_lifetime'); ?>" min="300" max="86400" step="300" placeholder="300-86400">
                                <small>默认 7200 秒（2小时），在此期间内无需重新登录</small>
                            </div>
                            <button class="btn btn-primary" onclick="saveConfig()"><i class="fas fa-save" style="margin-right:4px"></i>保存设置</button>
                        </div>
                        <div class="settings-card glass">
                            <h3><i class="fas fa-palette" style="margin-right:8px"></i>外观设置</h3>
                            <div class="settings-row">
                                <label>主题模式</label>
                                <select id="themeModeSelect" onchange="onThemeModeChange(this.value)">
                                    <option value="auto">跟随系统</option>
                                    <option value="light">始终亮色</option>
                                    <option value="dark">始终暗色</option>
                                </select>
                            </div>
                            <small>选择「跟随系统」将根据系统偏好自动切换亮色/暗色主题</small>
                        </div>
                    </div>
