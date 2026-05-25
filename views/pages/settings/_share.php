                    <div id="settingsTabShare" class="settings-tab-content">
                        <div class="settings-card glass">
                            <h3>分享设置</h3>
                            <div class="settings-row">
                                <label>默认过期时间（天）</label>
                                <input type="number" id="cfg_share_default_expire" value="<?php echo $config->get('share_default_expire') / 86400; ?>" min="0" max="365" placeholder="0-365，0为永久">
                                <small>0 表示永不过期</small>
                            </div>
                            <div class="settings-row">
                                <label>分享链接长度</label>
                                <input type="number" id="cfg_share_link_length" value="<?php echo $config->get('share_link_length'); ?>" min="6" max="20" placeholder="6-20">
                            </div>
                            <button class="btn btn-primary" onclick="saveConfig()"><i class="fas fa-save"></i> 保存设置</button>
                        </div>
                    </div>
