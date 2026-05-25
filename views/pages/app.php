<?php
use App\Core\Security;
?>
<div class="app-layout">
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-inner glass">
            <div class="sidebar-brand">
                <div class="sidebar-brand-icon"><i class="fas fa-cloud"></i></div>
                <span class="sidebar-brand-text"><?php echo Security::escape($config->get('app_name')); ?></span>
            </div>
            <nav class="sidebar-nav">
                <a href="javascript:;" class="nav-item active" data-page="files" onclick="switchPage('files', this)">
                    <span class="nav-icon"><i class="fas fa-folder"></i></span>
                    <span>全部文件</span>
                </a>
                <a href="javascript:;" class="nav-item" data-page="recent" onclick="switchPage('recent', this)">
                    <span class="nav-icon"><i class="fas fa-clock"></i></span>
                    <span>最近访问</span>
                </a>
                <a href="javascript:;" class="nav-item" data-page="favorites" onclick="switchPage('favorites', this)">
                    <span class="nav-icon"><i class="fas fa-star"></i></span>
                    <span>我的收藏</span>
                </a>
                <a href="javascript:;" class="nav-item" data-page="shares" onclick="switchPage('shares', this)">
                    <span class="nav-icon"><i class="fas fa-link"></i></span>
                    <span>我的分享</span>
                </a>
                <a href="javascript:;" class="nav-item" data-page="trash" onclick="switchPage('trash', this)">
                    <span class="nav-icon"><i class="fas fa-trash-alt"></i></span>
                    <span>回收站</span>
                </a>
                <a href="javascript:;" class="nav-item" data-page="logs" onclick="switchPage('logs', this)">
                    <span class="nav-icon"><i class="fas fa-history"></i></span>
                    <span>操作日志</span>
                </a>
                <a href="javascript:;" class="nav-item" data-page="ai" onclick="switchPage('ai', this)">
                    <span class="nav-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l2.4 7.2L21 12l-6.6 2.8L12 22l-2.4-7.2L3 12l6.6-2.8z"/></svg></span>
                    <span>AI 云助手</span>
                </a>
                <div class="nav-divider"></div>
                <a href="javascript:;" class="nav-item" data-page="settings" onclick="switchPage('settings', this)">
                    <span class="nav-icon"><i class="fas fa-cog"></i></span>
                    <span>系统设置</span>
                </a>
            </nav>
            <div class="sidebar-storage" id="sidebarStorage">
                <div class="storage-label">
                    <span>存储空间</span>
                    <span id="storageText">-- / --</span>
                </div>
                <div class="storage-bar">
                    <div class="storage-fill" id="storageFill" style="width:0%"></div>
                </div>
            </div>
        </div>
    </aside>

    <main class="main-content">
        <header class="top-bar glass">
            <button class="menu-toggle" id="menuToggle" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
            <div class="breadcrumb" id="breadcrumb">
                <span class="breadcrumb-item" onclick="navigateTo(0)">全部文件</span>
            </div>
            <div style="position:absolute;left:-9999px;top:-9999px;opacity:0;pointer-events:none;">
                <input type="text" id="fakeInput" autocomplete="off" tabindex="-1" readonly>
                <input type="password" id="fakePassword" autocomplete="off" tabindex="-1" readonly>
            </div>
            <div class="search-box">
                <input type="text" id="searchInput" placeholder="搜索文件..." onkeyup="handleSearch(event)" autocomplete="off" spellcheck="false" data-1p-ignore="true" data-lpignore="true" data-form-type="other">
                <button onclick="performSearch()" type="button"><i class="fas fa-search"></i></button>
            </div>
            <div class="user-menu">
                <button class="theme-toggle-btn" onclick="toggleTheme()" title="切换主题">
                    <i class="fas fa-moon" id="themeIcon"></i>
                </button>
                <div class="user-avatar"><?php echo Security::escape(mb_substr($user['username'] ?? '?', 0, 1)); ?></div>
                <span class="user-name"><?php echo Security::escape($user['username'] ?? ''); ?></span>
                <button class="btn-icon" onclick="handleLogout()" title="退出"><i class="fas fa-sign-out-alt"></i></button>
            </div>
        </header>

        <div class="content-area" id="contentArea">
            <div id="pageFiles" class="page active">
                <div class="toolbar">
                    <div class="toolbar-left">
                        <button class="btn btn-primary" onclick="showUploadDialog()"><i class="fas fa-cloud-upload-alt"></i> 上传</button>
                        <button class="btn btn-glass" onclick="showNewFolderDialog()"><i class="fas fa-folder-plus"></i> 新建</button>
                        <span class="toolbar-sep"></span>
                        <label class="select-all-check" id="selectAllCheck" style="display:none">
                            <input type="checkbox" onchange="toggleSelectAll(this)">
                            <span>全选</span>
                        </label>
                        <button class="btn btn-glass" id="batchDeleteBtn" style="display:none" onclick="batchDelete()"><i class="fas fa-trash-alt"></i> 删除</button>
                        <button class="btn btn-glass" id="batchRenameBtn" style="display:none" onclick="showBatchRenameDialog()"><i class="fas fa-font"></i> 重命名</button>
                        <button class="btn btn-glass" id="batchMoveBtn" style="display:none" onclick="showMoveDialog()"><i class="fas fa-arrows-alt"></i> 移动</button>
                        <button class="btn btn-glass" id="batchCopyBtn" style="display:none" onclick="showCopyDialog()"><i class="fas fa-copy"></i> 复制</button>
                    </div>
                    <div class="toolbar-right">
                        <select id="sortSelect" class="sort-select" onchange="changeSort(this.value)">
                            <option value="name">按名称</option>
                            <option value="size">按大小</option>
                            <option value="time">按时间</option>
                            <option value="type">按类型</option>
                            <option value="custom">自定义排序</option>
                        </select>
                        <div class="view-toggle" id="viewToggle">
                            <button class="view-toggle-btn" data-view="list" onclick="switchView('list')" title="列表视图"><i class="fas fa-list"></i></button>
                            <button class="view-toggle-btn" data-view="grid" onclick="switchView('grid')" title="网格视图"><i class="fas fa-th-large"></i></button>
                        </div>
                    </div>
                </div>
                <div class="file-table" id="fileList">
                    <div class="loading"><div class="spinner"></div>加载中...</div>
                </div>
            </div>

            <div id="pageRecent" class="page">
                <div class="page-header">
                    <h2 class="page-title"><i class="fas fa-clock" style="margin-right:10px;color:var(--accent-cyan)"></i>最近访问</h2>
                    <button class="btn btn-glass btn-sm" onclick="loadRecent()"><i class="fas fa-sync-alt"></i> 刷新</button>
                </div>
                <div class="file-table" id="recentList">
                    <div class="loading"><div class="spinner"></div>加载中...</div>
                </div>
            </div>

            <div id="pageFavorites" class="page">
                <div class="page-header">
                    <h2 class="page-title"><i class="fas fa-star" style="margin-right:10px;color:var(--accent-warning)"></i>我的收藏</h2>
                </div>
                <div class="file-table" id="favoriteList">
                    <div class="loading"><div class="spinner"></div>加载中...</div>
                </div>
            </div>

            <div id="pageShares" class="page">
                <div class="fluent-share-list-header">
                    <h2 class="fluent-share-list-title"><i class="fas fa-link"></i>我的分享</h2>
                </div>
                <div class="fluent-share-list" id="shareList">
                    <div class="loading"><div class="spinner"></div>加载中...</div>
                </div>
            </div>

            <div id="pageTrash" class="page">
                <div class="page-header">
                    <h2 class="page-title"><i class="fas fa-trash-alt" style="margin-right:10px;color:var(--accent-danger)"></i>回收站</h2>
                    <button class="btn btn-danger btn-sm" onclick="emptyTrash()"><i class="fas fa-broom"></i> 清空</button>
                </div>
                <div class="trash-list" id="trashList">
                    <div class="loading"><div class="spinner"></div>加载中...</div>
                </div>
            </div>

            <div id="pageLogs" class="page">
                <div class="page-header">
                    <h2 class="page-title"><i class="fas fa-history" style="margin-right:10px;color:var(--accent-cyan)"></i>操作日志</h2>
                    <div class="logs-header-actions">
                        <button class="btn btn-glass btn-sm" onclick="toggleLogStatistics()"><i class="fas fa-chart-bar"></i> 统计</button>
                        <button class="btn btn-glass btn-sm" onclick="exportLogs()"><i class="fas fa-file-export"></i> 导出</button>
                        <button class="btn btn-glass btn-sm" onclick="clearLogs()"><i class="fas fa-broom"></i> 清理</button>
                        <button class="btn btn-glass btn-sm" onclick="loadOperationLogs()"><i class="fas fa-sync-alt"></i> 刷新</button>
                    </div>
                </div>
                
                <div id="logStatisticsPanel" class="log-statistics-panel" style="display:none;">
                    <div class="stats-grid" id="statsGrid"></div>
                </div>

                <div class="log-filters">
                    <div class="filter-bar">
                        <div class="filter-group">
                            <select id="logFilterCategory" class="filter-select" onchange="applyLogFilters()">
                                <option value="all">所有类别</option>
                                <option value="file">文件操作</option>
                                <option value="auth">认证操作</option>
                                <option value="share">分享操作</option>
                                <option value="account">账户操作</option>
                                <option value="system">系统操作</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <select id="logFilterSeverity" class="filter-select" onchange="applyLogFilters()">
                                <option value="all">所有级别</option>
                                <option value="info">普通</option>
                                <option value="warning">警告</option>
                                <option value="critical">严重</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <input type="text" id="logSearchInput" class="filter-input" placeholder="搜索操作目标、详情或IP..." oninput="debounceLogSearch()">
                        </div>
                        <div class="filter-group">
                            <input type="date" id="logDateFrom" class="filter-input" onchange="applyLogFilters()">
                        </div>
                        <div class="filter-group">
                            <input type="date" id="logDateTo" class="filter-input" onchange="applyLogFilters()">
                        </div>
                        <button class="btn btn-sm btn-ghost" onclick="resetLogFilters()"><i class="fas fa-times"></i> 重置</button>
                    </div>
                </div>

                <div class="logs-container" id="logsContainer">
                    <div class="loading"><div class="spinner"></div>加载中...</div>
                </div>
            </div>

            <div id="pageAi" class="page">
                <div class="ai-page-layout">
                    <div class="ai-page-chat">
                        <div class="ai-page-header">
                            <div style="display:flex;align-items:center;gap:10px">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--accent-primary)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l2.4 7.2L21 12l-6.6 2.8L12 22l-2.4-7.2L3 12l6.6-2.8z"/></svg>
                                <h2 class="page-title" style="margin:0">AI 云助手</h2>
                            </div>
                            <button class="btn btn-glass btn-sm" onclick="clearAIChat()"><i class="fas fa-broom"></i> 清空对话</button>
                        </div>
                        <div id="aiChatMessages" class="ai-page-messages">
                            <div class="ai-msg ai-msg-assistant">
                                <div class="ai-msg-avatar"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--accent-primary)" stroke-width="2"><path d="M12 2l2.4 7.2L21 12l-6.6 2.8L12 22l-2.4-7.2L3 12l6.6-2.8z"/></svg></div>
                                <div class="ai-msg-content">你好！我是云助手，可以帮你管理文件、创建分享、查看存储信息等。有什么可以帮你的吗？</div>
                            </div>
                        </div>
                        <div class="ai-page-input-area">
                            <div style="display:flex;gap:8px;align-items:flex-end">
                                <textarea id="aiChatInput" placeholder="输入消息，Enter 发送，Shift+Enter 换行" onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();sendAIMessage()}" style="flex:1;padding:10px 14px;border-radius:10px;border:1px solid var(--bg-glass-border);background:var(--bg-surface);color:var(--text-primary);font-size:14px;outline:none;resize:none;min-height:40px;max-height:120px;line-height:1.5;font-family:inherit" rows="1"></textarea>
                                <button onclick="sendAIMessage()" class="ai-send-btn" id="aiSendBtn"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg></button>
                            </div>
                        </div>
                    </div>
                    <div class="ai-page-sidebar">
                        <div class="ai-quick-actions">
                            <h4>快捷操作</h4>
                            <button class="ai-quick-btn" onclick="sendAIQuick('查看当前存储空间使用情况')"><i class="fas fa-hdd"></i> 存储信息</button>
                            <button class="ai-quick-btn" onclick="sendAIQuick('查看我的所有分享链接')"><i class="fas fa-link"></i> 我的分享</button>
                            <button class="ai-quick-btn" onclick="sendAIQuick('搜索文件')"><i class="fas fa-search"></i> 搜索文件</button>
                        </div>
                        <div class="ai-tips">
                            <h4>使用提示</h4>
                            <p>你可以用自然语言与 AI 对话，例如：</p>
                            <ul>
                                <li>"帮我把报告文件夹创建一个分享链接"</li>
                                <li>"查看存储空间还剩多少"</li>
                                <li>"搜索所有 PDF 文件"</li>
                                <li>"把分享链接生成二维码"</li>
                            </ul>
                        </div>
                        <div class="ai-chat-history">
                            <h4>历史对话</h4>
                            <div id="chatHistoryList" class="chat-history-list">
                                <div class="chat-history-empty" style="text-align:center;padding:20px;color:var(--text-muted);font-size:13px">暂无历史对话</div>
                            </div>
                            <div class="chat-history-tip">最多保存最近 10 条对话记录</div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="pageSettings" class="page">
                <div class="page-header">
                    <h2 class="page-title"><i class="fas fa-cog" style="margin-right:10px;color:var(--text-muted)"></i>系统设置</h2>
                    <div class="settings-header-actions">
                        <button class="btn btn-glass btn-sm" onclick="loadConfig()"><i class="fas fa-sync-alt"></i> 刷新</button>
                        <button class="btn btn-glass btn-sm" onclick="importSettings()"><i class="fas fa-upload"></i> 导入设置</button>
                        <button class="btn btn-glass btn-sm" onclick="exportSettings()"><i class="fas fa-download"></i> 导出设置</button>
                    </div>
                </div>
                <div class="settings-tabs">
                    <button class="settings-tab-btn active" onclick="switchSettingsTab('general', this)"><span>基础设置</span></button>
                    <button class="settings-tab-btn" onclick="switchSettingsTab('upload', this)"><span>上传设置</span></button>
                    <button class="settings-tab-btn" onclick="switchSettingsTab('security', this)"><span>安全设置</span></button>
                    <button class="settings-tab-btn" onclick="switchSettingsTab('share', this)"><span>分享设置</span></button>
                    <button class="settings-tab-btn" onclick="switchSettingsTab('cache', this)"><span>缓存管理</span></button>
                    <button class="settings-tab-btn" onclick="switchSettingsTab('storage', this)"><span>存储管理</span></button>
                    <button class="settings-tab-btn" onclick="switchSettingsTab('account', this)"><span>账户设置</span></button>
                    <button class="settings-tab-btn" onclick="switchSettingsTab('about', this)"><span>关于</span></button>
                </div>
                <div class="settings-grid">
                    <?php require __DIR__ . '/settings/_general.php'; ?>
                    <?php require __DIR__ . '/settings/_upload.php'; ?>
                    <?php require __DIR__ . '/settings/_security.php'; ?>
                    <?php require __DIR__ . '/settings/_share.php'; ?>
                    <?php require __DIR__ . '/settings/_cache.php'; ?>
                    <?php require __DIR__ . '/settings/_storage.php'; ?>
                    <?php require __DIR__ . '/settings/_account.php'; ?>
                    <?php require __DIR__ . '/settings/_about.php'; ?>
                    </div>
                </div>
            </div>
<!-- Modals and overlays -->
<div class="modal-overlay" id="modalOverlay" onclick="if(event.target===this)closeModal()">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="modalTitle"></h3>
            <button class="modal-close" onclick="closeModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" id="modalBody"></div>
    </div>
</div>
</div>

<div class="upload-overlay" id="uploadOverlay" style="display:none">
    <div class="upload-box glass-strong">
        <div class="upload-box-header">
            <h3>上传文件</h3>
            <button onclick="closeUploadDialog()"><i class="fas fa-times"></i></button>
        </div>
        <div class="upload-box-body">
            <div class="dropzone" id="uploadDropzone">
                <div class="dropzone-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                <p>拖拽文件到此处或点击选择</p>
                <input type="file" id="fileInput" multiple onchange="handleFileSelect(this.files)" style="display:none">
                <button class="btn btn-glass" onclick="document.getElementById('fileInput').click()"><i class="fas fa-folder-open"></i> 选择文件</button>
            </div>
            <div class="upload-queue" id="uploadQueue"></div>
        </div>
    </div>
</div>

<div class="upload-float-widget" id="uploadFloatWidget">
    <div class="upload-float-mini idle" id="uploadFloatMini">
        <i class="fas fa-cloud-upload-alt upload-float-icon"></i>
        <span class="upload-float-text" id="uploadFloatText">上传中 0%</span>
    </div>
    <div class="upload-float-panel" id="uploadFloatPanel" style="display:none">
        <div class="upload-float-panel-header">
            <h4>传输任务</h4>
            <button onclick="toggleFloatWidget()" title="收起"><i class="fas fa-chevron-down"></i></button>
        </div>
        <div class="upload-float-list" id="uploadFloatList"></div>
    </div>
</div>

<div class="upload-interrupt-overlay" id="uploadInterruptOverlay" style="display:none">
    <div class="upload-interrupt-box glass-strong">
        <div class="upload-interrupt-header">
            <h3><i class="fas fa-exclamation-triangle" style="color:var(--accent-warning);margin-right:8px"></i>上传中断</h3>
            <button onclick="closeInterruptDialog()"><i class="fas fa-times"></i></button>
        </div>
        <div class="upload-interrupt-body">
            <p class="upload-interrupt-hint" id="interruptHint"></p>
            <div class="upload-interrupt-file-list" id="interruptFileList"></div>
        </div>
        <div class="upload-interrupt-footer">
            <button class="btn btn-primary" style="width:100%" onclick="closeInterruptDialog()">知道了</button>
        </div>
    </div>
</div>

<div class="context-menu glass-strong" id="contextMenu" style="display:none">
    <a href="javascript:;" onclick="contextAction('download')"><i class="fas fa-download"></i> 下载</a>
    <a href="javascript:;" onclick="contextAction('preview')"><i class="fas fa-eye"></i> 预览</a>
    <a href="javascript:;" onclick="contextAction('share')"><i class="fas fa-link"></i> 分享</a>
    <a href="javascript:;" onclick="contextAction('favorite')"><i class="fas fa-star"></i> 收藏</a>
    <a href="javascript:;" onclick="contextAction('lock')"><i class="fas fa-lock"></i> 锁定</a>
    <a href="javascript:;" onclick="contextAction('encrypt')"><i class="fas fa-shield-alt"></i> 加密</a>
    <a href="javascript:;" onclick="contextAction('tags')"><i class="fas fa-tags"></i> 标签</a>
    <a href="javascript:;" onclick="contextAction('rename')"><i class="fas fa-edit"></i> 重命名</a>
    <a href="javascript:;" onclick="contextAction('move')"><i class="fas fa-arrows-alt"></i> 移动</a>
    <a href="javascript:;" onclick="contextAction('copy')"><i class="fas fa-copy"></i> 复制</a>
    <a href="javascript:;" onclick="contextAction('info')"><i class="fas fa-info-circle"></i> 详情</a>
    <div class="context-divider"></div>
    <a href="javascript:;" onclick="contextAction('delete')" class="danger"><i class="fas fa-trash-alt"></i> 删除</a>
</div>

<!-- ===== UNIFIED PREVIEW OVERLAY ===== -->
<div class="preview-overlay" id="previewOverlay">
    <div class="preview-header">
        <div class="preview-header-left">
            <span class="preview-file-icon" id="previewFileIcon"></span>
            <span class="preview-file-name" id="previewFileName"></span>
            <span class="preview-file-size" id="previewFileSize"></span>
        </div>
        <div class="preview-header-right">
            <button class="preview-nav-btn" id="previewPrevBtn" title="上一个 (←)"><i class="fas fa-chevron-left"></i></button>
            <button class="preview-nav-btn" id="previewNextBtn" title="下一个 (→)"><i class="fas fa-chevron-right"></i></button>
            <button class="preview-action-btn" id="previewDownloadBtn" title="下载"><i class="fas fa-download"></i></button>
            <button class="preview-action-btn preview-close-btn" id="previewCloseBtn" title="关闭 (Esc)"><i class="fas fa-times"></i></button>
        </div>
    </div>
    <div class="preview-body" id="previewBody">
        <div class="preview-loading" id="previewLoading">
            <div class="preview-loading-spinner"><i class="fas fa-spinner fa-spin"></i></div>
            <p>加载中...</p>
        </div>
        <div class="preview-content" id="previewContent"></div>
        <div class="preview-error" id="previewError" style="display:none">
            <i class="fas fa-exclamation-circle"></i>
            <p id="previewErrorMessage"></p>
        </div>
    </div>
</div>

<div class="toast-container" id="toastContainer"></div>
