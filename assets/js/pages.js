/**
 * 功能页面 - 近期访问、收藏、分享列表、回收站、操作日志、系统设置
 */

function loadRecent() {
    api('recent_access', {}, 'GET').then(data => {
        if (data.success) {
            const container = document.getElementById('recentList');
            if (data.items.length === 0) {
                container.innerHTML = '<div class="empty-state"><div class="empty-icon"><i class="fas fa-clock"></i></div><h3>暂无最近访问</h3><p>打开文件后会自动记录</p></div>';
                return;
            }
            let html = '<div class="file-table-header"><div class="col-name">名称</div><div class="col-size">大小</div><div class="col-time">访问时间</div><div class="col-actions">操作</div></div>';
            data.items.forEach(f => {
                html += `<div class="file-row" onclick="handleFileRowClick(${f.file_id}, ${f.is_dir ? 'true' : 'false'})" ondblclick="${isTouchDevice ? '' : (f.is_dir ? `navigateTo(${f.file_id})` : `previewFile(${f.file_id})`)}">
                    <div class="col-name"><div class="file-name-wrap"><span class="file-icon icon-${f.icon}">${getFileIcon(f.icon)}</span><span class="file-name-text">${escapeHtml(f.filename)}</span></div></div>
                    <div class="col-size">${f.is_dir ? '-' : f.filesize_formatted}</div>
                    <div class="col-time">${f.accessed_at_formatted}</div>
                    <div class="col-actions" onclick="event.stopPropagation()">
                        <button class="btn-icon" style="width:30px;height:30px;font-size:13px" onclick="event.stopPropagation();downloadFile(${f.file_id})" title="下载"><i class="fas fa-download"></i></button>
                        <button class="btn-icon" style="width:30px;height:30px;font-size:13px" onclick="event.stopPropagation();deleteFile(${f.file_id})" title="删除"><i class="fas fa-trash-alt"></i></button>
                    </div>
                </div>`;
            });
            container.innerHTML = html;
        }
    });
}

function loadStorageInfo() {
    api('storage_info', {}, 'GET').then(data => {
        if (data.success) {
            const s = data.storage;
            const storageText = document.getElementById('storageText');
            const storageFill = document.getElementById('storageFill');
            if (storageText) storageText.textContent = s.used_formatted + ' / ' + s.total_formatted;
            if (storageFill) {
                storageFill.style.width = s.percentage + '%';
                storageFill.parentElement.classList.add('storage-updating');
                setTimeout(() => storageFill.parentElement.classList.remove('storage-updating'), 2000);
            }

            const currentStorageLimit = document.getElementById('currentStorageLimit');
            const currentStorageUsed = document.getElementById('currentStorageUsed');
            const currentStorageRemaining = document.getElementById('currentStorageRemaining');
            const lastUpdateTime = document.getElementById('lastUpdateTime');
            if (currentStorageLimit) currentStorageLimit.textContent = s.total_formatted;
            if (currentStorageUsed) currentStorageUsed.textContent = s.used_formatted;
            if (currentStorageRemaining) currentStorageRemaining.textContent = s.available_formatted || s.total_formatted;
            if (lastUpdateTime) lastUpdateTime.textContent = new Date().toLocaleString('zh-CN');
        }
    });
}

function loadSettings() {
    loadStorageInfo();
    loadConfig();
    loadCacheInfo();
    loadAIConfig();
    if (typeof updateThemeModeSelect === 'function') updateThemeModeSelect();
}

function switchSettingsTab(tabName, btn) {
    document.querySelectorAll('.settings-tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.settings-tab-content').forEach(c => c.classList.remove('active'));
    btn.classList.add('active');
    const tabMap = {
        'general': 'settingsTabGeneral',
        'upload': 'settingsTabUpload',
        'security': 'settingsTabSecurity',
        'share': 'settingsTabShare',
        'cache': 'settingsTabCache',
        'storage': 'settingsTabStorage',
        'account': 'settingsTabAccount',
        'about': 'settingsTabAbout'
    };
    document.getElementById(tabMap[tabName]).classList.add('active');
    
    if (tabName === 'cache') {
        loadCacheInfo();
    }
}

function loadFavorites() {
    api('get_favorites', {}, 'GET').then(data => {
        if (data.success) {
            const container = document.getElementById('favoriteList');
            if (data.files.length === 0) {
                container.innerHTML = '<div class="empty-state"><div class="empty-icon"><i class="fas fa-star"></i></div><h3>暂无收藏</h3><p>右键点击文件添加到收藏</p></div>';
                return;
            }
            let html = '<div class="file-table-header"><div class="col-name">名称</div><div class="col-size">大小</div><div class="col-time">收藏时间</div></div>';
            data.files.forEach(f => {
                html += `<div class="file-row" onclick="handleFileRowClick(${f.parent_id}, true)" ondblclick="${isTouchDevice ? '' : `navigateTo(${f.parent_id})`}">
                    <div class="col-name"><div class="file-name-wrap"><span class="file-icon icon-${f.icon}">${getFileIcon(f.icon)}</span><span class="file-name-text">${escapeHtml(f.filename)}</span></div></div>
                    <div class="col-size">${f.is_dir ? '-' : f.filesize_formatted}</div>
                    <div class="col-time">${f.created_at_formatted}</div>
                </div>`;
            });
            container.innerHTML = html;
        }
    });
}

function loadShares() {
    api('list_shares', {}, 'GET').then(data => {
        if (data.success) {
            const container = document.getElementById('shareList');
            if (data.shares.length === 0) {
                container.innerHTML = '<div class="fluent-empty-state"><div class="fluent-empty-icon"><i class="fas fa-link"></i></div><h3 class="fluent-empty-title">暂无分享</h3><p class="fluent-empty-desc">右键点击文件创建分享链接</p></div>';
                return;
            }
            let html = '';
            data.shares.forEach(s => {
                const iconClass = s.is_dir ? 'fa-folder' : 'fa-file';
                const statusBadge = s.is_expired 
                    ? '<span class="fluent-share-badge fluent-badge-expired"><i class="fas fa-clock"></i> 已过期</span>' 
                    : (!s.is_active 
                        ? '<span class="fluent-share-badge fluent-badge-expired"><i class="fas fa-ban"></i> 已禁用</span>' 
                        : '<span class="fluent-share-badge fluent-badge-active"><i class="fas fa-check"></i> 正常</span>');
                const passwordBadge = s.has_password 
                    ? '<span class="fluent-share-badge fluent-badge-password"><i class="fas fa-lock"></i> 有密码</span>' 
                    : '';
                
                html += `<div class="fluent-share-list-item ${s.is_expired ? 'expired' : ''} ${!s.is_active ? 'disabled' : ''}">
                    <div class="fluent-share-item-info">
                        <div class="fluent-share-item-icon">
                            <i class="fas ${iconClass}"></i>
                        </div>
                        <div class="fluent-share-item-content">
                            <div class="fluent-share-item-name">${escapeHtml(s.filename || '已删除')}</div>
                            <div class="fluent-share-item-meta">
                                <span><i class="fas fa-download" style="margin-right:4px"></i>${s.download_count}${s.max_downloads > 0 ? ' / ' + s.max_downloads : ''} 次</span>
                                <span>有效期至 ${s.expire_at_formatted}</span>
                                ${statusBadge}
                                ${passwordBadge}
                            </div>
                        </div>
                    </div>
                    <div class="fluent-share-item-actions">
                        <button class="fluent-action-btn" title="二维码" data-share-url="${escapeHtml(s.share_url)}" onclick="showShareQR(this.dataset.shareUrl)">
                            <i class="fas fa-qrcode"></i>
                        </button>
                        <button class="fluent-action-btn" title="复制链接" data-share-url="${escapeHtml(s.share_url)}" onclick="copyText(this.dataset.shareUrl)">
                            <i class="fas fa-link"></i>
                        </button>
                        <button class="fluent-action-btn" title="${s.is_active ? '禁用' : '启用'}" onclick="toggleShare(${s.id})">
                            <i class="fas ${s.is_active ? 'fa-pause' : 'fa-play'}"></i>
                        </button>
                        <button class="fluent-action-btn danger" title="删除" onclick="deleteShare(${s.id})">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>`;
            });
            container.innerHTML = html;
        }
    });
}

function toggleShare(shareId) {
    api('toggle_share', {share_id: shareId}).then(data => {
        if (data.success) { loadShares(); showToast(data.message); }
    });
}

function deleteShare(shareId) {
    showConfirm('确定要删除此分享吗？', () => {
        api('delete_share', {share_id: shareId}).then(data => {
            if (data.success) { loadShares(); showToast('分享已删除'); }
        });
    });
}

function loadTrash() {
    api('list_trash', {}, 'GET').then(data => {
        if (data.success) {
            const container = document.getElementById('trashList');
            if (data.items.length === 0) {
                container.innerHTML = '<div class="empty-state"><div class="empty-icon"><i class="fas fa-trash-alt"></i></div><h3>回收站为空</h3><p>删除的文件会在这里保留30天</p></div>';
                return;
            }
            let html = '';
            data.items.forEach(item => {
                html += `<div class="trash-row">
                    <div class="trash-info">
                        <span class="file-icon icon-${item.is_dir ? 'folder' : 'file'}">${item.is_dir ? '<i class="fas fa-folder"></i>' : '<i class="fas fa-file"></i>'}</span>
                        <span style="font-weight:600">${escapeHtml(item.filename)}</span>
                        <span class="trash-meta">${item.filesize_formatted} · 删除于 ${item.deleted_at_formatted} · 剩余 ${item.remaining_days} 天</span>
                    </div>
                    <div class="trash-actions">
                        <button class="btn btn-glass btn-sm" onclick="restoreFile(${item.id})">恢复</button>
                        <button class="btn btn-danger btn-sm" onclick="permanentDelete(${item.id})">永久删除</button>
                    </div>
                </div>`;
            });
            container.innerHTML = html;
        }
    });
}

function restoreFile(trashId) {
    api('restore', {trash_id: trashId}).then(data => {
        if (data.success) { loadTrash(); showToast('文件已恢复'); loadStorageInfo(); }
        else { showToast(data.message, 'error'); }
    });
}

function permanentDelete(trashId) {
    showConfirm('永久删除后将无法恢复，确定吗？', () => {
        api('permanent_delete', {trash_id: trashId}).then(data => {
            if (data.success) { loadTrash(); showToast('已永久删除'); loadStorageInfo(); }
            else { showToast(data.message, 'error'); }
        });
    });
}

function emptyTrash() {
    showConfirm('确定要清空回收站吗？此操作不可恢复！', () => {
        api('empty_trash').then(data => {
            if (data.success) { loadTrash(); showToast('回收站已清空'); loadStorageInfo(); }
            else { showToast(data.message, 'error'); }
        });
    });
}

function loadOperationLogs(page = 1) {
    const filter = document.getElementById('logFilterCategory')?.value || 'all';
    const severity = document.getElementById('logFilterSeverity')?.value || 'all';
    const search = document.getElementById('logSearchInput')?.value || '';
    const dateFrom = document.getElementById('logDateFrom')?.value || '';
    const dateTo = document.getElementById('logDateTo')?.value || '';

    const params = new URLSearchParams({
        page,
        category: filter === 'all' ? '' : filter,
        severity: severity === 'all' ? '' : severity,
        keyword: search,
        start_date: dateFrom,
        end_date: dateTo
    });

    api('operation_logs', params, 'GET').then(data => {
        if (data.success) {
            const container = document.getElementById('logsContainer');
            if (data.logs.length === 0) {
                container.innerHTML = '<div class="empty-state"><div class="empty-icon"><i class="fas fa-history"></i></div><h3>暂无操作日志</h3><p>您的操作记录将在这里显示</p></div>';
                return;
            }
            
            const actionInfo = {
                login: { label: '登录', icon: 'fa-sign-in-alt' },
                logout: { label: '登出', icon: 'fa-sign-out-alt' },
                upload: { label: '上传文件', icon: 'fa-cloud-upload-alt' },
                upload_chunk: { label: '分片上传', icon: 'fa-cloud-upload-alt' },
                download: { label: '下载文件', icon: 'fa-cloud-download-alt' },
                download_folder: { label: '下载文件夹', icon: 'fa-cloud-download-alt' },
                delete: { label: '删除文件', icon: 'fa-trash-alt' },
                batch_delete: { label: '批量删除', icon: 'fa-trash-alt' },
                batch_rename: { label: '批量重命名', icon: 'fa-font' },
                toggle_lock: { label: '切换锁定', icon: 'fa-lock' },
                toggle_encryption: { label: '切换加密', icon: 'fa-shield-alt' },
                rename: { label: '重命名文件', icon: 'fa-edit' },
                move: { label: '移动文件', icon: 'fa-arrows-alt' },
                copy: { label: '复制文件', icon: 'fa-copy' },
                create_folder: { label: '创建文件夹', icon: 'fa-folder-plus' },
                toggle_favorite: { label: '切换收藏', icon: 'fa-star' },
                create_share: { label: '创建分享', icon: 'fa-link' },
                delete_share: { label: '删除分享', icon: 'fa-unlink' },
                toggle_share: { label: '切换分享', icon: 'fa-toggle-on' },
                restore: { label: '恢复文件', icon: 'fa-trash-restore' },
                permanent_delete: { label: '永久删除', icon: 'fa-bomb' },
                empty_trash: { label: '清空回收站', icon: 'fa-broom' },
                change_password: { label: '修改密码', icon: 'fa-key' },
                update_profile: { label: '更新资料', icon: 'fa-user-edit' },
                update_config: { label: '更新配置', icon: 'fa-cogs' },
                clear_cache: { label: '清理缓存', icon: 'fa-brush' },
                clear_logs: { label: '清理日志', icon: 'fa-broom' },
                update_tags: { label: '更新标签', icon: 'fa-tags' },
                update_description: { label: '更新描述', icon: 'fa-comment-dots' },
                register: { label: '注册', icon: 'fa-user-plus' }
            };

            const categoryInfo = {
                file: { label: '文件', icon: 'fa-file' },
                auth: { label: '认证', icon: 'fa-shield-alt' },
                share: { label: '分享', icon: 'fa-share-alt' },
                account: { label: '账户', icon: 'fa-user' },
                system: { label: '系统', icon: 'fa-server' },
                other: { label: '其他', icon: 'fa-ellipsis-h' }
            };

            const severityIcon = {
                info: { icon: 'fa-info-circle', color: '#2563eb' },
                warning: { icon: 'fa-exclamation-triangle', color: '#f59e0b' },
                critical: { icon: 'fa-radiation', color: '#ef4444' }
            };

            const groupedByDate = {};
            data.logs.forEach(log => {
                const date = new Date(log.created_at * 1000);
                const dateKey = date.toISOString().split('T')[0];
                if (!groupedByDate[dateKey]) {
                    groupedByDate[dateKey] = [];
                }
                groupedByDate[dateKey].push(log);
            });

            let html = '';
            
            for (const [date, logs] of Object.entries(groupedByDate)) {
                const dateObj = new Date(date);
                const today = new Date();
                const yesterday = new Date(today);
                yesterday.setDate(yesterday.getDate() - 1);
                
                let dateLabel;
                if (date === today.toISOString().split('T')[0]) {
                    dateLabel = '今天';
                } else if (date === yesterday.toISOString().split('T')[0]) {
                    dateLabel = '昨天';
                } else {
                    dateLabel = dateObj.toLocaleDateString('zh-CN', { year: 'numeric', month: 'long', day: 'numeric', weekday: 'long' });
                }

                html += `<div class="fluent-date-header">
                    <span class="fluent-date-header-text">${dateLabel}</span>
                    <span class="fluent-date-count">${logs.length} 条记录</span>
                </div>`;

                html += '<div class="fluent-logs-list">';
                logs.forEach((log, index) => {
                    const info = actionInfo[log.action] || { label: log.action, icon: 'fa-circle' };
                    const cat = categoryInfo[log.category || 'other'] || categoryInfo.other;
                    const sev = severityIcon[log.severity || 'info'] || severityIcon.info;
                    const severityClass = `severity-${log.severity || 'info'}`;

                    html += `<div class="fluent-log-item ${severityClass}" style="animation-delay: ${Math.min(index * 0.04, 0.4)}s">
                        <div class="fluent-log-icon">
                            <i class="fas ${info.icon}"></i>
                        </div>
                        <div class="fluent-log-content">
                            <div class="fluent-log-header">
                                <div class="fluent-log-title-row">
                                    <span class="fluent-log-title">${escapeHtml(info.label)}</span>
                                    <span class="fluent-log-category category-${escapeHtml(log.category || 'other')}">${escapeHtml(cat.label)}</span>
                                    <span class="fluent-log-severity severity-dot-${log.severity || 'info'}"></span>
                                </div>
                                <span class="fluent-log-time">${log.created_at_formatted}</span>
                            </div>
                            <div class="fluent-log-detail">${escapeHtml(log.target || log.detail || '无详细信息')}</div>
                            <div class="fluent-log-meta">
                                <span class="fluent-log-meta-item"><i class="fas fa-globe"></i>${escapeHtml(log.ip || '未知')}</span>
                                <span class="fluent-log-meta-item"><i class="fas fa-clock"></i>${log.created_at_relative || ''}</span>
                            </div>
                            <div class="fluent-log-expanded">
                                <div class="fluent-log-expanded-grid">
                                    <div class="fluent-log-expanded-item">
                                        <label><i class="fas fa-calendar-alt"></i>时间</label>
                                        <span>${log.created_at_formatted} (${log.created_at_relative || ''})</span>
                                    </div>
                                    <div class="fluent-log-expanded-item">
                                        <label><i class="fas fa-globe"></i>IP 地址</label>
                                        <span>${escapeHtml(log.ip || '未知')}</span>
                                    </div>
                                    <div class="fluent-log-expanded-item">
                                        <label><i class="fas fa-shield-alt"></i>严重级别</label>
                                        <span>${log.severity || 'info'}</span>
                                    </div>
                                    <div class="fluent-log-expanded-item">
                                        <label><i class="fas fa-folder"></i>操作类别</label>
                                        <span>${escapeHtml(cat.label)}</span>
                                    </div>
                                    ${log.user_agent ? '<div class="fluent-log-expanded-item full-width"><label><i class="fas fa-laptop"></i>用户代理</label><span>' + escapeHtml(log.user_agent) + '</span></div>' : ''}
                                </div>
                            </div>
                            <button class="fluent-log-expand-btn" onclick="event.stopPropagation(); toggleLogDetail(this.closest('.fluent-log-item'))">
                                <i class="fas fa-chevron-down"></i>
                            </button>
                        </div>
                    </div>`;
                });
                html += '</div>';
            }

            container.innerHTML = html;
        }
    });
}

function toggleLogDetail(row) {
    row.classList.toggle('expanded');
    const btn = row.querySelector('.fluent-log-expand-btn i');
    if (btn) {
        btn.classList.toggle('fa-chevron-down');
        btn.classList.toggle('fa-chevron-up');
    }
}

function applyLogFilters() {
    loadOperationLogs(1);
}

function resetLogFilters() {
    document.getElementById('logFilterCategory').value = 'all';
    document.getElementById('logFilterSeverity').value = 'all';
    document.getElementById('logSearchInput').value = '';
    document.getElementById('logDateFrom').value = '';
    document.getElementById('logDateTo').value = '';
    loadOperationLogs(1);
}

let logSearchTimeout;
function debounceLogSearch() {
    clearTimeout(logSearchTimeout);
    logSearchTimeout = setTimeout(() => {
        loadOperationLogs(1);
    }, 500);
}

function toggleLogStatistics() {
    const panel = document.getElementById('logStatisticsPanel');
    if (panel.style.display === 'none') {
        panel.style.display = 'block';
        loadLogStatistics();
    } else {
        panel.style.display = 'none';
    }
}

function loadLogStatistics() {
    api('log_statistics', { days: 7 }, 'GET').then(data => {
        if (data.success) {
            const grid = document.getElementById('statsGrid');

            let html = `
                <div class="stat-card">
                    <div class="stat-value">${data.total || 0}</div>
                    <div class="stat-label">总操作数</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value">${data.recent || 0}</div>
                    <div class="stat-label">近 7 天操作数</div>
                </div>
            `;

            if (data.by_severity && data.by_severity.length > 0) {
                data.by_severity.forEach(s => {
                    html += `<div class="stat-card severity-${s.severity}">
                        <div class="stat-value">${s.count}</div>
                        <div class="stat-label">${s.severity.toUpperCase()} 操作</div>
                    </div>`;
                });
            }

            if (data.by_category && data.by_category.length > 0) {
                data.by_category.slice(0, 4).forEach(s => {
                    const categoryLabels = { file: '文件', auth: '认证', share: '分享', account: '账户', system: '系统', other: '其他' };
                    html += `<div class="stat-card">
                        <div class="stat-value">${s.count}</div>
                        <div class="stat-label">${categoryLabels[s.category] || s.category} 操作</div>
                    </div>`;
                });
            }

            grid.innerHTML = html;
        }
    });
}

function exportLogs() {
    const filter = document.getElementById('logFilterCategory')?.value || 'all';
    const severity = document.getElementById('logFilterSeverity')?.value || 'all';
    const search = document.getElementById('logSearchInput')?.value || '';
    const dateFrom = document.getElementById('logDateFrom')?.value || '';
    const dateTo = document.getElementById('logDateTo')?.value || '';

    const params = new URLSearchParams({
        page: 1,
        page_size: 200,
        category: filter === 'all' ? '' : filter,
        severity: severity === 'all' ? '' : severity,
        keyword: search,
        start_date: dateFrom,
        end_date: dateTo
    });

    api('operation_logs', params, 'GET').then(data => {
        if (data.success && data.logs.length > 0) {
            let csv = '时间,操作,类别,严重级别,目标,IP,详情,用户代理\n';
            data.logs.forEach(log => {
                csv += `"${log.created_at_formatted}","${log.action}","${log.category || ''}","${log.severity || 'info'}","${(log.target || '').replace(/"/g, '""')}","${log.ip || ''}","${(log.detail || '').replace(/"/g, '""')}","${(log.user_agent || '').replace(/"/g, '""')}"\n`;
            });

            const blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = `operation_logs_${new Date().toISOString().split('T')[0]}.csv`;
            link.click();
            URL.revokeObjectURL(url);
            showToast('日志已导出', 'success');
        } else {
            showToast('没有可导出的日志', 'error');
        }
    });
}

function clearLogs() {
    showConfirm('确定要清理所有操作日志吗？此操作不可撤销！').then(confirmed => {
        if (confirmed) {
            api('clear_logs', {}).then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    loadOperationLogs();
                } else {
                    showToast(data.message, 'error');
                }
            });
        }
    });
}

function loadConfig() {
    api('get_config', {}, 'GET').then(data => {
        if (data.success) {
            const c = data.config;
            document.getElementById('cfg_app_name').value = c.app_name || '';
            document.getElementById('cfg_session_lifetime').value = c.session_lifetime || 7200;
            document.getElementById('cfg_max_upload_size').value = c.max_upload_size || 524288000;
            document.getElementById('cfg_chunk_size').value = c.chunk_size || 5242880;
            document.getElementById('cfg_trash_retention_days').value = c.trash_retention_days || 30;
            document.getElementById('cfg_thumbnail_size').value = c.thumbnail_size || 64;
            document.getElementById('cfg_share_default_expire').value = (c.share_default_expire || 604800) / 86400;
            document.getElementById('cfg_share_link_length').value = c.share_link_length || 12;
            document.getElementById('cfg_login_max_attempts').value = c.login_max_attempts || 5;
            document.getElementById('cfg_login_lockout_time').value = c.login_lockout_time || 900;
            document.getElementById('cfg_password_min_length').value = c.password_min_length || 8;
            document.getElementById('cfg_download_rate_limit').value = c.download_rate_limit || 30;
            document.getElementById('cfg_download_rate_window').value = c.download_rate_window || 60;
            document.getElementById('cfg_delete_rate_limit').value = c.delete_rate_limit || 20;
            document.getElementById('cfg_delete_rate_window').value = c.delete_rate_window || 60;
            document.getElementById('cfg_blocked_extensions').value = (c.blocked_extensions || []).join(' ');
        }
    });
}

function saveConfig() {
    if (APP_CONFIG.debug) {
        console.log('[DEBUG] saveConfig called');
    }
    
    const data = {
        app_name: document.getElementById('cfg_app_name').value,
        debug: document.getElementById('cfg_debug').checked,
        session_lifetime: document.getElementById('cfg_session_lifetime').value,
        max_upload_size: document.getElementById('cfg_max_upload_size').value,
        chunk_size: document.getElementById('cfg_chunk_size').value,
        trash_retention_days: document.getElementById('cfg_trash_retention_days').value,
        thumbnail_size: document.getElementById('cfg_thumbnail_size').value,
        share_default_expire: document.getElementById('cfg_share_default_expire').value * 86400,
        share_link_length: document.getElementById('cfg_share_link_length').value,
        password_min_length: document.getElementById('cfg_password_min_length').value,
        login_max_attempts: document.getElementById('cfg_login_max_attempts').value,
        login_lockout_time: document.getElementById('cfg_login_lockout_time').value,
        download_rate_limit: document.getElementById('cfg_download_rate_limit').value,
        download_rate_window: document.getElementById('cfg_download_rate_window').value,
        delete_rate_limit: document.getElementById('cfg_delete_rate_limit').value,
        delete_rate_window: document.getElementById('cfg_delete_rate_window').value,
    };

    if (APP_CONFIG.debug) {
        console.log('[DEBUG] saveConfig data:', data);
    }

    api('update_config', data).then(res => {
        if (APP_CONFIG.debug) {
            console.log('[DEBUG] saveConfig response:', res);
        }
        if (res.success) {
            showToast('设置已保存');
        } else {
            showToast(res.message || '保存失败', 'error');
        }
    });
}

function saveBlockedExtensions() {
    var blocked = document.getElementById('cfg_blocked_extensions').value.trim().split(/\s+/).filter(function(e) { return e; });
    var password = document.getElementById('cfg_blocked_password').value;
    if (!password) {
        showToast('请输入当前密码确认身份', 'warning');
        return;
    }
    api('update_config', {
        blocked_extensions: blocked,
        _password: password
    }).then(function(res) {
        if (res.success) {
            showToast('黑名单已更新');
            document.getElementById('cfg_blocked_password').value = '';
        } else {
            showToast(res.message || '保存失败', 'error');
        }
    });
}

function loadCacheInfo() {
    const dirs = [
        { dir: 'thumbnails', id: 'thumbCacheSize' },
        { dir: 'covers', id: 'coverCacheSize' }
    ];

    dirs.forEach(d => {
        api('get_cache_size', {dir: d.dir})
            .then(data => {
                const el = document.getElementById(d.id);
                if (el && data.size) {
                    el.textContent = data.size;
                } else if (el) {
                    el.textContent = '0 B';
                }
            })
            .catch(err => {
                const el = document.getElementById(d.id);
                if (el) el.textContent = '加载失败';
            });
    });
}

function clearCache(type) {
    showConfirm('确定要清理此缓存吗？', () => {
        api('clear_cache', {type: type}).then(data => {
            if (data.success) {
                showToast(data.message);
                loadCacheInfo();
            } else {
                showToast(data.message || '清理失败', 'error');
            }
        });
    });
}

function clearAllCache() {
    showConfirm('确定要清理所有缓存吗？', () => {
        api('clear_cache').then(data => {
            if (data.success) {
                showToast(data.message);
                loadCacheInfo();
            } else {
                showToast(data.message || '清理失败', 'error');
            }
        });
    });
}

function loadDiskInfo() {
    fetch('index.php?action=get_disk_info')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const disk = data.disk;
                document.getElementById('storageTotalSpace').textContent = disk.total_formatted;
                document.getElementById('storageFreeSpace').textContent = disk.free_formatted;
                document.getElementById('storageUsagePercent').textContent = disk.usage_percentage + '%';
                
                if (disk.reserve_mb) {
                    document.getElementById('cfg_storage_reserve_mb').value = disk.reserve_mb;
                }
                if (disk.update_threshold) {
                    document.getElementById('cfg_storage_update_threshold').value = disk.update_threshold;
                }
            }
        })
        .catch(() => {
            showToast('获取磁盘信息失败', 'error');
        });
}

function saveStorageSettings() {
    const reserveMb = document.getElementById('cfg_storage_reserve_mb').value;
    const threshold = document.getElementById('cfg_storage_update_threshold').value;

    if (reserveMb < 100 || reserveMb > 10240) {
        showToast('预留空间应为 100-10240 MB', 'error');
        return;
    }
    if (threshold < 0.1 || threshold > 50) {
        showToast('更新阈值应为 0.1-50%', 'error');
        return;
    }

    api('update_storage_settings', {
        storage_reserve_mb: reserveMb,
        storage_update_threshold: threshold
    }).then(res => {
        if (res.success) {
            showToast(res.message);
            loadDiskInfo();
        } else {
            showToast(res.message || '保存失败', 'error');
        }
    });
}

function manualUpdateStorage() {
    showConfirm('确定要根据当前磁盘状态重新计算并更新存储限额吗？', () => {
        api('manual_update_storage').then(res => {
            if (res.success) {
                showToast(res.message);
                loadStorageInfo();
                loadDiskInfo();
            } else {
                showToast(res.message || '更新失败', 'error');
            }
        });
    });
}

function applyDefaultStorage() {
    showConfirm('确定要恢复默认 10GB 存储限额吗？', () => {
        api('update_storage_settings', {
            storage_reserve_mb: 500,
            storage_update_threshold: 1,
            reset_to_default: true
        }).then(res => {
            if (res.success) {
                showToast(res.message);
                loadStorageInfo();
                loadDiskInfo();
            } else {
                showToast(res.message || '操作失败', 'error');
            }
        });
    });
}

function exportSettings() {
    window.location.href = 'index.php?action=export_settings';
}

function importSettings() {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = '.json';
    input.onchange = function() {
        const file = input.files[0];
        if (!file) return;
        const formData = new FormData();
        formData.append('config_file', file);
        formData.append('_csrf_token', APP_CONFIG.csrfToken);
        showToast('正在导入设置...', 'info');
        fetch('index.php?action=import_config', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': APP_CONFIG.csrfToken },
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
                loadConfig();
            } else {
                showToast(data.message || '导入失败', 'error');
            }
        })
        .catch(() => showToast('导入失败，请重试', 'error'));
    };
    input.click();
}