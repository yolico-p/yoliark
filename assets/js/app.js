/**
 * Harmony OS + Fluent Design 移动端交互逻辑
 * 对接现有的 ID-based API
 */

const API_BASE = 'index.php?action=';
const Config = window.APP_CONFIG || {};
const DEBUG_MODE = Config.debug || window.DEBUG_MODE || false;

const Debug = {
    log(...args) {
        if (DEBUG_MODE) {
            console.log('[DEBUG]', new Date().toLocaleTimeString(), ...args);
        }
    },
    error(...args) {
        if (DEBUG_MODE) {
            console.error('[DEBUG ERROR]', new Date().toLocaleTimeString(), ...args);
        }
    },
    warn(...args) {
        if (DEBUG_MODE) {
            console.warn('[DEBUG WARN]', new Date().toLocaleTimeString(), ...args);
        }
    },
    time(label) {
        if (DEBUG_MODE) {
            console.time(`[DEBUG] ${label}`);
        }
    },
    timeEnd(label) {
        if (DEBUG_MODE) {
            console.timeEnd(`[DEBUG] ${label}`);
        }
    }
};

const State = {
    currentPage: 'files',
    currentParentId: Config.initialParentId || 0,
    selectedFiles: new Set(),
    breadcrumb: [],
    uploads: [],
    uploadActive: false,
    audioElement: null,
    isPlaying: false,
    currentMusicFileId: null
};

function api(action, methodOrBody = 'GET', bodyOrSignal = null, isFormDataOrSignal = false, signal = null) {
    // ── 兼容多种调用方式 ──
    // 方式1: api('action', 'GET', body, false, signal)     ← 新规范
    // 方式2: api('action', {key: val})                      ← 旧规范（默认POST）
    // 方式3: api('action', 'GET', null, false, signal)      ← 带signal的GET
    // 方式4: api('action', {key: val}, 'GET', signal)       ← 旧规范带signal

    let method = 'GET';
    let body = null;
    let isFormData = false;

    if (typeof methodOrBody === 'string') {
        // 方式1、3
        method = methodOrBody;
        body = bodyOrSignal;
        if (typeof isFormDataOrSignal === 'boolean') {
            isFormData = isFormDataOrSignal;
        } else if (typeof AbortSignal !== 'undefined' && isFormDataOrSignal instanceof AbortSignal) {
            signal = isFormDataOrSignal;
        }
    } else if (methodOrBody && typeof methodOrBody === 'object') {
        // 方式2、4、5（FormData）
        body = methodOrBody;
        // FormData 实例直接作为 body，标记 isFormData
        if (typeof FormData !== 'undefined' && methodOrBody instanceof FormData) {
            isFormData = true;
            method = 'POST';
        } else {
            method = 'POST';
        }
        if (typeof bodyOrSignal === 'string') {
            method = bodyOrSignal;
        } else if (typeof bodyOrSignal === 'boolean') {
            isFormData = bodyOrSignal;
        }
        if (typeof AbortSignal !== 'undefined' && isFormDataOrSignal instanceof AbortSignal) {
            signal = isFormDataOrSignal;
        }
    }

    Debug.log(`API Request: ${action}`, { method, body, isFormData });
    Debug.time(`api-${action}`);

    const url = `${API_BASE}${action}`;
    const opts = { method, headers: {} };
    if (!isFormData && body) {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(body);
    } else if (isFormData && body) {
        opts.body = body;
    }
    opts.headers['X-CSRF-TOKEN'] = Config.csrfToken || '';
    if (signal instanceof AbortSignal) {
        opts.signal = signal;
    }

    return fetch(url, opts)
        .then(response => {
            Debug.timeEnd(`api-${action}`);
            Debug.log(`API Response: ${action}`, { status: response.status });
            return response.json();
        })
        .then(data => {
            Debug.log(`API Data: ${action}`, data);
            return data;
        })
        .catch(error => {
            Debug.timeEnd(`api-${action}`);
            Debug.error(`API Error: ${action}`, error);
            throw error;
        });
}

const Toast = {
    container: null,
    init() {
        this.container = document.getElementById('toastContainer');
    },
    show(message, type = 'info') {
        if (!this.container) this.init();
        const icons = {
            success: 'fa-circle-check',
            error: 'fa-circle-xmark',
            warning: 'fa-triangle-exclamation',
            info: 'fa-circle-info'
        };
        const toast = document.createElement('div');
        toast.className = `toast-item toast-${type}`;
        toast.innerHTML = `<i class="fas ${icons[type] || icons.info}"></i><span>${escapeHtml(message)}</span>`;
        this.container.appendChild(toast);
        requestAnimationFrame(() => {
            toast.classList.add('show');
        });
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 400);
        }, 3000);
    }
};

const Confirm = {
    overlay: null,
    title: null,
    message: null,
    okBtn: null,
    cancelBtn: null,
    callback: null,
    init() {
        this.overlay = document.getElementById('confirmDialog');
        this.title = document.getElementById('confirmTitle');
        this.message = document.getElementById('confirmMessage');
        this.okBtn = document.getElementById('confirmOk');
        this.cancelBtn = document.getElementById('confirmCancel');
        this.cancelBtn.onclick = () => this.hide();
        this.okBtn.onclick = () => {
            this.hide();
            if (this.callback) this.callback();
        };
        this.overlay.onclick = (e) => {
            if (e.target === this.overlay) this.hide();
        };
    },
    show(title, message, callback) {
        if (!this.overlay) this.init();
        this.title.textContent = title;
        this.message.textContent = message;
        this.callback = callback;
        this.overlay.classList.add('active');
    },
    hide() {
        this.overlay.classList.remove('active');
        this.callback = null;
    }
};

const Modal = {
    backdrop: null,
    title: null,
    body: null,
    closeBtn: null,
    init() {
        this.backdrop = document.getElementById('actionModal');
        this.title = document.getElementById('modalTitle');
        this.body = document.getElementById('modalBody');
        this.closeBtn = document.getElementById('modalClose');
        this.closeBtn.onclick = () => this.hide();
        this.backdrop.onclick = (e) => {
            if (e.target === this.backdrop) this.hide();
        };
    },
    show(title, html) {
        if (!this.backdrop) this.init();
        this.title.textContent = title;
        this.body.innerHTML = html;
        this.backdrop.classList.add('active');
    },
    hide() {
        this.backdrop.classList.remove('active');
    }
};

const Drawer = {
    overlay: null,
    drawer: null,
    menuToggle: null,
    init() {
        this.overlay = document.getElementById('drawerOverlay');
        this.drawer = document.getElementById('sideDrawer');
        this.menuToggle = document.getElementById('menuToggle');
        this.menuToggle.onclick = () => this.open();
        this.overlay.onclick = () => this.close();
    },
    open() {
        this.overlay.classList.add('active');
        this.drawer.classList.add('open');
    },
    close() {
        this.overlay.classList.remove('active');
        this.drawer.classList.remove('open');
    }
};

const Navigation = {
    tabs: null,
    navItems: null,
    headerTitle: null,
    pageTitles: {
        files: '我的文件',
        shares: '我的分享',
        storage: '存储信息',
        settings: '设置'
    },
    init() {
        this.tabs = document.querySelectorAll('.bottom-nav-tab');
        this.navItems = document.querySelectorAll('.drawer-nav-item[data-page]');
        this.headerTitle = document.getElementById('headerTitle');
        this.tabs.forEach(tab => {
            tab.onclick = () => this.goTo(tab.dataset.page);
        });
        this.navItems.forEach(item => {
            item.onclick = () => {
                this.goTo(item.dataset.page);
                Drawer.close();
            };
        });
        const logoutBtn = document.getElementById('logoutBtn');
        if (logoutBtn) {
            logoutBtn.onclick = () => {
                Confirm.show('退出登录', '确定要退出登录吗？', () => {
                    window.location.href = 'index.php?action=logout';
                });
            };
        }
    },
    goTo(page) {
        State.currentPage = page;
        document.querySelectorAll('.page').forEach(s => s.classList.remove('active'));
        const pageSection = document.getElementById(`page-${page}`);
        if (pageSection) pageSection.classList.add('active');
        this.tabs.forEach(t => t.classList.toggle('active', t.dataset.page === page));
        this.navItems.forEach(n => n.classList.toggle('active', n.dataset.page === page));
        this.headerTitle.textContent = this.pageTitles[page] || '我的文件';
        if (page === 'files') FileManager.load(State.currentParentId);
        else if (page === 'shares') renderShares();
        else if (page === 'storage') renderStats();
    }
};

const Breadcrumb = {
    container: null,
    init() {
        this.container = document.getElementById('breadcrumb');
    },
    async render(parentId) {
        if (!this.container) this.init();
        try {
            const result = await api(`breadcrumb&parent_id=${parentId}`);
            if (result.success && result.breadcrumb) {
                State.breadcrumb = result.breadcrumb;
                let html = '';
                result.breadcrumb.forEach((item, index) => {
                    const isLast = index === result.breadcrumb.length - 1;
                    if (isLast) {
                        html += `<span class="breadcrumb-sep" style="color: var(--text-muted)">${escapeHtml(item.name)}</span>`;
                    } else {
                        html += `<div class="breadcrumb-item" data-parent-id="${item.id}">${escapeHtml(item.name)}</div>`;
                        if (index < result.breadcrumb.length - 1) {
                            html += `<span class="breadcrumb-sep"><i class="fas fa-chevron-right"></i></span>`;
                        }
                    }
                });
                this.container.innerHTML = html;
                this.container.querySelectorAll('.breadcrumb-item').forEach(item => {
                    item.onclick = () => FileManager.load(parseInt(item.dataset.parentId));
                });
            }
        } catch (error) {
        }
    }
};

const FileManager = {
    async load(parentId) {
        Debug.log('[FileManager] load', { parentId });
        Debug.time('load-files');
        try {
            const result = await api('list_files', {parent_id: parentId, sort_by: 'name', sort_order: 'asc', page_size: 0}, 'GET');
            Debug.timeEnd('load-files');
            if (result.success) {
                Debug.log('[FileManager] files loaded', { count: result.files?.length || 0 });
                State.currentParentId = parentId;
                renderFileList(result.files);
                Breadcrumb.render(parentId);
            } else {
                Debug.warn('[FileManager] load failed', result);
                Toast.show(result.message || '加载失败', 'error');
            }
        } catch (error) {
            Debug.error('[FileManager] load error', error);
            Toast.show('网络错误', 'error');
        }
    },
    async newFolder() {
        Debug.log('[FileManager] newFolder');
        const folderName = prompt('请输入文件夹名称：');
        if (!folderName || !folderName.trim()) return;
        Debug.log('[FileManager] creating folder', folderName);
        try {
            const fd = new FormData();
            fd.append('action', 'create_folder');
            fd.append('parent_id', State.currentParentId);
            fd.append('folder_name', folderName.trim());
            fd.append('_csrf_token', Config.csrfToken || '');
            const result = await fetch('index.php?action=create_folder', {
                method: 'POST', body: fd
            }).then(r => r.json());
            if (result.success) {
                Debug.log('[FileManager] folder created', result);
                Toast.show('文件夹创建成功', 'success');
                this.load(State.currentParentId);
            } else {
                Debug.warn('[FileManager] create failed', result);
                Toast.show(result.message || '创建失败', 'error');
            }
        } catch (error) {
            Debug.error('[FileManager] create error', error);
            Toast.show('网络错误', 'error');
        }
    },
    async deleteFiles(fileIds) {
        Debug.log('[FileManager] deleteFiles', { count: fileIds.length });
        if (fileIds.length === 0) return;
        Confirm.show('删除确认', `确定要删除选中的 ${fileIds.length} 个文件吗？此操作不可恢复！`, async () => {
            Debug.log('[FileManager] delete confirmed');
            Debug.time('delete-files');
            try {
                const fd = new FormData();
                fd.append('action', 'batch_delete');
                fileIds.forEach(id => fd.append('file_ids[]', id));
                fd.append('_csrf_token', Config.csrfToken || '');
                const result = await fetch('index.php?action=batch_delete', {
                    method: 'POST', body: fd
                }).then(r => r.json());
                Debug.timeEnd('delete-files');
                if (result.success) {
                    Debug.log('[FileManager] delete success', result);
                    Toast.show('删除成功', 'success');
                    State.selectedFiles.clear();
                    this.load(State.currentParentId);
                } else {
                    Debug.warn('[FileManager] delete failed', result);
                    Toast.show(result.message || '删除失败', 'error');
                }
            } catch (error) {
                Debug.timeEnd('delete-files');
                Debug.error('[FileManager] delete error', error);
                Toast.show('网络错误', 'error');
            }
        });
    },
    showActions(file) {
        Debug.log('[FileManager] showActions', file);
        const fileName = file.filename;
        let html = `<div style="display: flex; flex-direction: column; gap: 8px;">`;
        html += `<button class="action-btn" onclick="handleAction('rename', ${file.id})"><i class="fas fa-pen"></i> 重命名</button>`;
        html += `<button class="action-btn" onclick="handleAction('move', ${file.id})"><i class="fas fa-arrows-to-dot"></i> 移动</button>`;
        html += `<button class="action-btn" onclick="handleAction('copy', ${file.id})"><i class="fas fa-copy"></i> 复制</button>`;
        if (file.is_dir) {
            html += `<button class="action-btn" onclick="handleAction('downloadFolder', ${file.id})"><i class="fas fa-download"></i> 下载文件夹</button>`;
        } else {
            html += `<button class="action-btn" onclick="handleAction('download', ${file.id})"><i class="fas fa-download"></i> 下载</button>`;
        }
        html += `<button class="action-btn" onclick="handleAction('share', ${file.id})"><i class="fas fa-share"></i> 分享</button>`;
        html += `<button class="action-btn" style="color: #ef4444;" onclick="handleAction('delete', ${file.id})"><i class="fas fa-trash-can"></i> 删除</button>`;
        html += `</div>`;
        Modal.show(fileName, html);
    }
};

function getFileIcon(file) {
    if (file.is_dir) return { icon: 'fas fa-folder', cls: 'folder' };
    const ext = (file.file_type || '').toLowerCase();
    const map = {
        jpg: { icon: 'fas fa-image', cls: 'image' }, jpeg: { icon: 'fas fa-image', cls: 'image' },
        png: { icon: 'fas fa-image', cls: 'image' }, gif: { icon: 'fas fa-image', cls: 'image' },
        webp: { icon: 'fas fa-image', cls: 'image' }, bmp: { icon: 'fas fa-image', cls: 'image' },
        mp4: { icon: 'fas fa-video', cls: 'video' }, webm: { icon: 'fas fa-video', cls: 'video' },
        mov: { icon: 'fas fa-video', cls: 'video' }, avi: { icon: 'fas fa-video', cls: 'video' },
        mkv: { icon: 'fas fa-video', cls: 'video' },
        mp3: { icon: 'fas fa-music', cls: 'audio' }, wav: { icon: 'fas fa-music', cls: 'audio' },
        flac: { icon: 'fas fa-music', cls: 'audio' }, aac: { icon: 'fas fa-music', cls: 'audio' },
        pdf: { icon: 'fas fa-file-pdf', cls: 'pdf' },
        doc: { icon: 'fas fa-file-word', cls: 'word' }, docx: { icon: 'fas fa-file-word', cls: 'word' },
        xls: { icon: 'fas fa-file-excel', cls: 'excel' }, xlsx: { icon: 'fas fa-file-excel', cls: 'excel' },
        ppt: { icon: 'fas fa-file-powerpoint', cls: 'ppt' }, pptx: { icon: 'fas fa-file-powerpoint', cls: 'ppt' },
        txt: { icon: 'fas fa-file-lines', cls: 'text' }, log: { icon: 'fas fa-file-lines', cls: 'text' },
        zip: { icon: 'fas fa-file-zipper', cls: 'archive' }, rar: { icon: 'fas fa-file-zipper', cls: 'archive' },
        '7z': { icon: 'fas fa-file-zipper', cls: 'archive' }, tar: { icon: 'fas fa-file-zipper', cls: 'archive' },
        js: { icon: 'fas fa-file-code', cls: 'code' }, ts: { icon: 'fas fa-file-code', cls: 'code' },
        py: { icon: 'fas fa-file-code', cls: 'code' }, html: { icon: 'fas fa-file-code', cls: 'code' },
        css: { icon: 'fas fa-file-code', cls: 'code' }, json: { icon: 'fas fa-file-code', cls: 'code' },
    };
    return map[ext] || { icon: 'fas fa-file', cls: 'file' };
}

function getFileTypeIcon(file) {
    const info = getFileIcon(file);
    return `<i class="${info.icon}"></i>`;
}

function renderFileList(files) {
    const container = document.getElementById('fileList');
    State.selectedFiles.clear();
    if (!files || files.length === 0) {
        container.innerHTML = `
            <div class="empty-state-container">
                <div class="empty-icon-wrap"><i class="fas fa-folder-open"></i></div>
                <div class="empty-title">此文件夹为空</div>
                <div class="empty-desc">点击上方"上传"或"新建"按钮添加内容</div>
            </div>
        `;
        updateHeaderTitle([]);
        return;
    }
    let html = '';
    files.forEach(file => {
        const iconInfo = getFileIcon(file);
        const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'].includes((file.file_type || '').toLowerCase());
        const thumbnailUrl = isImage ? `index.php?action=thumbnail&file_id=${file.id}` : '';
        html += `
            <div class="file-card" data-id="${file.id}" data-is-folder="${file.is_dir ? '1' : '0'}">
                <input type="checkbox" class="file-checkbox" data-id="${file.id}">
                <div class="file-icon-wrap file-icon-${iconInfo.cls}">
                    ${isImage ? `<img src="${thumbnailUrl}" alt="" loading="lazy">` : getFileTypeIcon(file)}
                </div>
                <div class="file-details" onclick="handleFileClick(${file.id}, ${file.is_dir ? 'true' : 'false'})">
                    <div class="file-name-text">${escapeHtml(file.filename)}</div>
                    <div class="file-meta-text">
                        <span>${file.filesize_formatted || formatSize(file.filesize)}</span>
                        ${file.created_at_formatted ? `<span>${file.created_at_formatted}</span>` : ''}
                    </div>
                </div>
                <button class="file-action-btn" onclick='FileManager.showActions(${JSON.stringify(file).replace(/'/g, "&#39;")})'>
                    <i class="fas fa-ellipsis-vertical"></i>
                </button>
            </div>
        `;
    });
    container.innerHTML = html;
    container.querySelectorAll('.file-checkbox').forEach(cb => {
        cb.onchange = (e) => {
            const id = parseInt(e.target.dataset.id);
            if (e.target.checked) {
                State.selectedFiles.add(id);
                e.target.closest('.file-card').classList.add('selected');
            } else {
                State.selectedFiles.delete(id);
                e.target.closest('.file-card').classList.remove('selected');
            }
        };
    });
    updateHeaderTitle(files);
}

function updateHeaderTitle(files) {
    const folderCount = files.filter(f => f.is_dir).length;
    const fileCount = files.filter(f => !f.is_dir).length;
    const headerTitle = document.getElementById('headerTitle');
    if (State.currentPage === 'files') {
        headerTitle.textContent = `${folderCount} 个文件夹, ${fileCount} 个文件`;
    }
}

function handleFileClick(fileId, isFolder) {
    if (isFolder) {
        FileManager.load(fileId);
    } else {
        const file = State.breadcrumb.length > 0 ? null : null;
        window.open(`index.php?action=download&file_id=${fileId}`, '_blank');
    }
}

const MusicPlayer = {
    audio: null,
    container: null,
    cover: null,
    titleEl: null,
    metaEl: null,
    barFill: null,
    currentTimeEl: null,
    durationEl: null,
    playPauseBtn: null,
    init() {
        this.audio = document.getElementById('musicAudio');
        this.container = document.getElementById('musicPlayer');
        this.cover = document.getElementById('musicCover');
        this.titleEl = document.getElementById('musicTitle');
        this.metaEl = document.getElementById('musicMeta');
        this.barFill = document.getElementById('musicBarFill');
        this.currentTimeEl = document.getElementById('musicCurrentTime');
        this.durationEl = document.getElementById('musicDuration');
        this.playPauseBtn = document.getElementById('musicPlayPause');
        document.getElementById('musicClose').onclick = () => this.close();
        this.playPauseBtn.onclick = () => this.toggle();
        document.getElementById('musicPrev').onclick = () => this.prev();
        document.getElementById('musicNext').onclick = () => this.next();
        this.audio.ontimeupdate = () => this.updateProgress();
        this.audio.onended = () => this.next();
    },
    play(fileId, fileName, fileUrl) {
        Debug.log('[MusicPlayer] play', { fileId, fileName, fileUrl });
        Debug.time('music-load');
        this.audio.src = fileUrl;
        this.audio.onloadedmetadata = () => {
            Debug.timeEnd('music-load');
            Debug.log('[MusicPlayer] metadata loaded', { 
                duration: this.audio.duration,
                currentTime: this.audio.currentTime 
            });
        };
        this.audio.play();
        State.isPlaying = true;
        State.currentMusicFileId = fileId;
        this.titleEl.textContent = fileName || '未知曲目';
        this.metaEl.textContent = '正在播放';
        this.cover.classList.add('playing');
        this.playPauseBtn.innerHTML = '<i class="fas fa-pause"></i>';
        this.container.style.display = 'flex';
    },
    toggle() {
        Debug.log('[MusicPlayer] toggle', { paused: this.audio.paused });
        if (this.audio.paused) {
            this.audio.play();
            State.isPlaying = true;
            this.playPauseBtn.innerHTML = '<i class="fas fa-pause"></i>';
            this.cover.classList.add('playing');
        } else {
            this.audio.pause();
            State.isPlaying = false;
            this.playPauseBtn.innerHTML = '<i class="fas fa-play"></i>';
            this.cover.classList.remove('playing');
        }
    },
    close() {
        Debug.log('[MusicPlayer] close');
        this.audio.pause();
        this.audio.src = '';
        State.isPlaying = false;
        this.container.style.display = 'none';
        this.cover.classList.remove('playing');
    },
    updateProgress() {
        const percent = (this.audio.currentTime / this.audio.duration) * 100;
        this.barFill.style.width = `${percent}%`;
        this.currentTimeEl.textContent = this.formatTime(this.audio.currentTime);
        this.durationEl.textContent = this.formatTime(this.audio.duration);
    },
    prev() {
        Toast.show('上一首', 'info');
    },
    next() {
        Toast.show('下一首', 'info');
    },
    formatTime(seconds) {
        if (!seconds || isNaN(seconds)) return '0:00';
        const mins = Math.floor(seconds / 60);
        const secs = Math.floor(seconds % 60);
        return `${mins}:${secs.toString().padStart(2, '0')}`;
    }
};

function openPdfViewer(fileId, fileName) {
    const viewer = document.createElement('div');
    viewer.className = 'pdf-viewer-container';
    viewer.innerHTML = `
        <div class="pdf-viewer-toolbar">
            <div class="pdf-viewer-title">${escapeHtml(fileName) || 'PDF查看器'}</div>
            <a href="index.php?action=download&file_id=${fileId}" class="pdf-viewer-download">
                <i class="fas fa-download"></i>
            </a>
            <button class="pdf-viewer-close" onclick="this.closest('.pdf-viewer-container').remove()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <iframe class="pdf-viewer-iframe" src="index.php?action=preview&file_id=${fileId}"></iframe>
    `;
    document.body.appendChild(viewer);
}

function handleAction(action, fileId) {
    Modal.hide();
    switch (action) {
        case 'download':
        case 'downloadFolder':
            window.open(`index.php?action=download&file_id=${fileId}`, '_blank');
            break;
        case 'share':
            openShareDialog(fileId);
            break;
        case 'delete':
            FileManager.deleteFiles([fileId]);
            break;
        case 'rename':
            openRenameDialog(fileId);
            break;
        case 'move':
            openBatchMoveDialog([fileId]);
            break;
        case 'copy':
            openBatchCopyDialog([fileId]);
            break;
    }
}

function openShareDialog(fileId) {
    const html = `
        <div style="display: flex; flex-direction: column; gap: 12px;">
            <div class="settings-form-item">
                <label class="settings-form-label">有效期</label>
                <select class="settings-form-input" id="shareExpiry">
                    <option value="1">1 天</option>
                    <option value="7">7 天</option>
                    <option value="30" selected>30 天</option>
                    <option value="365">1 年</option>
                </select>
            </div>
            <button class="settings-submit-btn" id="generateShareLink">
                <i class="fas fa-link"></i> 生成链接
            </button>
            <div id="shareResult" style="display: none;">
                <div class="settings-form-item">
                    <label class="settings-form-label">分享链接</label>
                    <input type="text" class="settings-form-input" id="shareLinkInput" readonly>
                </div>
                <button class="settings-submit-btn" id="copyShareLink">
                    <i class="fas fa-copy"></i> 复制链接
                </button>
            </div>
        </div>
    `;
    Modal.show('分享文件', html);
    document.getElementById('generateShareLink').onclick = async () => {
        const expireDays = parseInt(document.getElementById('shareExpiry').value);
        try {
            const fd = new FormData();
            fd.append('action', 'create_share');
            fd.append('file_id', fileId);
            fd.append('expire_days', expireDays);
            fd.append('_csrf_token', Config.csrfToken || '');
            const result = await fetch('index.php?action=create_share', {
                method: 'POST', body: fd
            }).then(r => r.json());
            if (result.success) {
                const shareLink = `${window.location.origin}/index.php?page=share&token=${result.token}`;
                document.getElementById('shareLinkInput').value = shareLink;
                document.getElementById('shareResult').style.display = 'block';
                document.getElementById('copyShareLink').onclick = () => {
                    navigator.clipboard.writeText(shareLink);
                    Toast.show('链接已复制', 'success');
                };
            } else {
                Toast.show(result.message || '分享失败', 'error');
            }
        } catch (error) {
            Toast.show('网络错误', 'error');
        }
    };
}

function openRenameDialog(fileId) {
    const html = `
        <div class="settings-form-item">
            <label class="settings-form-label">新名称</label>
            <input type="text" class="settings-form-input" id="newNameInput" placeholder="输入新名称">
        </div>
        <button class="settings-submit-btn" id="confirmRename">
            <i class="fas fa-check"></i> 确认
        </button>
    `;
    Modal.show('重命名', html);
    document.getElementById('confirmRename').onclick = async () => {
        const newName = document.getElementById('newNameInput').value.trim();
        if (!newName) {
            Toast.show('名称不能为空', 'warning');
            return;
        }
        try {
            const fd = new FormData();
            fd.append('action', 'rename');
            fd.append('file_id', fileId);
            fd.append('new_name', newName);
            fd.append('_csrf_token', Config.csrfToken || '');
            const result = await fetch('index.php?action=rename', {
                method: 'POST', body: fd
            }).then(r => r.json());
            if (result.success) {
                Toast.show('重命名成功', 'success');
                Modal.hide();
                FileManager.load(State.currentParentId);
            } else {
                Toast.show(result.message || '重命名失败', 'error');
            }
        } catch (error) {
            Toast.show('网络错误', 'error');
        }
    };
}

function openBatchMoveDialog(fileIds) {
    if (!fileIds || fileIds.length === 0) {
        fileIds = Array.from(State.selectedFiles);
    }
    if (fileIds.length === 0) return;

    const html = `
        <div style="margin-bottom: 12px; padding: 12px; background: var(--bg-tertiary); border-radius: 8px;">
            <div style="display: flex; align-items: center; gap: 8px; color: var(--text-secondary); font-size: 14px;">
                <i class="fas fa-check-circle" style="color: var(--accent-primary)"></i>
                <span>将移动 <strong style="color: var(--accent-primary); font-size: 16px">${fileIds.length}</strong> 个文件</span>
            </div>
        </div>
        <p style="margin-bottom: 8px; color: var(--text-secondary); font-size: 14px;">
            <i class="fas fa-folder"></i> 选择目标文件夹：
        </p>
        <div class="move-tree-container" id="moveTree"></div>
        <input type="hidden" id="moveTargetId" value="0">
        <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 12px;">
            <button class="settings-submit-btn" style="background: var(--bg-tertiary); color: var(--text-primary);" onclick="Modal.hide();">
                <i class="fas fa-times"></i> 取消
            </button>
            <button class="settings-submit-btn" id="confirmMove">
                <i class="fas fa-arrows-to-dot"></i> 移动
            </button>
        </div>
    `;
    Modal.show('批量移动', html);
    loadBatchMoveTree(fileIds);
}

async function loadBatchMoveTree(fileIds) {
    try {
        const result = await api('list_all_folders');
        if (result.success) {
            const treeContainer = document.getElementById('moveTree');
            let html = `<div class="move-tree-node selected" data-parent-id="0" onclick="selectMoveTarget(0, this)">
                <i class="fas fa-folder" style="color: var(--accent-warning)"></i>
                <span>根目录</span>
            </div>`;
            if (result.folders && result.folders.length > 0) {
                result.folders.forEach(folder => {
                    if (fileIds.includes(folder.id)) return;
                    html += `<div class="move-tree-node" data-parent-id="${folder.id}" onclick="selectMoveTarget(${folder.id}, this)">
                        <i class="fas fa-folder" style="color: var(--accent-warning)"></i>
                        <span>${escapeHtml(folder.filename)}</span>
                    </div>`;
                });
            }
            treeContainer.innerHTML = html;
            document.getElementById('confirmMove').onclick = async () => {
                const targetParentId = parseInt(document.getElementById('moveTargetId').value);
                const btn = document.getElementById('confirmMove');
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 处理中...';
                try {
                    const fd = new FormData();
                    fd.append('action', 'batch_move');
                    fileIds.forEach(id => fd.append('file_ids[]', id));
                    fd.append('target_parent_id', targetParentId);
                    fd.append('_csrf_token', Config.csrfToken || '');
                    const result = await fetch('index.php?action=batch_move', {
                        method: 'POST', body: fd
                    }).then(r => r.json());
                    if (result.success) {
                        Toast.show(result.message || '移动成功', 'success');
                        Modal.hide();
                        State.selectedFiles.clear();
                        FileManager.load(State.currentParentId);
                    } else {
                        Toast.show(result.message || '移动失败', 'error');
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-arrows-to-dot"></i> 移动';
                    }
                } catch (error) {
                    Toast.show('网络错误', 'error');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-arrows-to-dot"></i> 移动';
                }
            };
        }
    } catch (error) {
        Toast.show('加载目录失败', 'error');
    }
}

function selectMoveTarget(id, el) {
    document.getElementById('moveTargetId').value = id;
    document.querySelectorAll('.move-tree-node').forEach(e => e.classList.remove('selected'));
    el.classList.add('selected');
}

function openBatchCopyDialog(fileIds) {
    if (!fileIds || fileIds.length === 0) {
        fileIds = Array.from(State.selectedFiles);
    }
    if (fileIds.length === 0) return;

    const html = `
        <div style="margin-bottom: 12px; padding: 12px; background: var(--bg-tertiary); border-radius: 8px;">
            <div style="display: flex; align-items: center; gap: 8px; color: var(--text-secondary); font-size: 14px;">
                <i class="fas fa-check-circle" style="color: var(--accent-primary)"></i>
                <span>将复制 <strong style="color: var(--accent-primary); font-size: 16px">${fileIds.length}</strong> 个文件</span>
            </div>
        </div>
        <p style="margin-bottom: 8px; color: var(--text-secondary); font-size: 14px;">
            <i class="fas fa-folder"></i> 选择目标文件夹：
        </p>
        <div class="move-tree-container" id="copyTree"></div>
        <input type="hidden" id="copyTargetId" value="0">
        <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 12px;">
            <button class="settings-submit-btn" style="background: var(--bg-tertiary); color: var(--text-primary);" onclick="Modal.hide();">
                <i class="fas fa-times"></i> 取消
            </button>
            <button class="settings-submit-btn" id="confirmCopy">
                <i class="fas fa-copy"></i> 复制
            </button>
        </div>
    `;
    Modal.show('批量复制', html);
    loadBatchCopyTree(fileIds);
}

async function loadBatchCopyTree(fileIds) {
    try {
        const result = await api('list_all_folders');
        if (result.success) {
            const treeContainer = document.getElementById('copyTree');
            let html = `<div class="move-tree-node selected" data-parent-id="0" onclick="selectCopyTarget(0, this)">
                <i class="fas fa-folder" style="color: var(--accent-warning)"></i>
                <span>根目录</span>
            </div>`;
            if (result.folders && result.folders.length > 0) {
                result.folders.forEach(folder => {
                    if (fileIds.includes(folder.id)) return;
                    html += `<div class="move-tree-node" data-parent-id="${folder.id}" onclick="selectCopyTarget(${folder.id}, this)">
                        <i class="fas fa-folder" style="color: var(--accent-warning)"></i>
                        <span>${escapeHtml(folder.filename)}</span>
                    </div>`;
                });
            }
            treeContainer.innerHTML = html;
            document.getElementById('confirmCopy').onclick = async () => {
                const targetParentId = parseInt(document.getElementById('copyTargetId').value);
                const btn = document.getElementById('confirmCopy');
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 处理中...';
                try {
                    const fd = new FormData();
                    fd.append('action', 'batch_copy');
                    fileIds.forEach(id => fd.append('file_ids[]', id));
                    fd.append('target_parent_id', targetParentId);
                    fd.append('_csrf_token', Config.csrfToken || '');
                    const result = await fetch('index.php?action=batch_copy', {
                        method: 'POST', body: fd
                    }).then(r => r.json());
                    if (result.success) {
                        Toast.show(result.message || '复制成功', 'success');
                        Modal.hide();
                        State.selectedFiles.clear();
                        FileManager.load(State.currentParentId);
                    } else {
                        Toast.show(result.message || '复制失败', 'error');
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-copy"></i> 复制';
                    }
                } catch (error) {
                    Toast.show('网络错误', 'error');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-copy"></i> 复制';
                }
            };
        }
    } catch (error) {
        Toast.show('加载目录失败', 'error');
    }
}

function selectCopyTarget(id, el) {
    document.getElementById('copyTargetId').value = id;
    document.querySelectorAll('.move-tree-node').forEach(e => e.classList.remove('selected'));
    el.classList.add('selected');
}

const Search = {
    bar: null,
    toggle: null,
    closeBtn: null,
    input: null,
    init() {
        this.bar = document.getElementById('searchBar');
        this.toggle = document.getElementById('searchToggle');
        this.closeBtn = document.getElementById('searchClose');
        this.input = document.getElementById('searchInput');
        this.toggle.onclick = () => this.open();
        this.closeBtn.onclick = () => this.close();
        let searchTimeout = null;
        this.input.oninput = (e) => {
            clearTimeout(searchTimeout);
            const query = e.target.value.trim();
            if (query.length >= 2) {
                searchTimeout = setTimeout(() => this.search(query), 400);
            }
        };
    },
    open() {
        this.bar.classList.add('active');
        this.input.focus();
    },
    close() {
        this.bar.classList.remove('active');
        this.input.value = '';
        FileManager.load(State.currentParentId);
    },
    async search(query) {
        try {
            const result = await api(`search&keyword=${encodeURIComponent(query)}&type=all`);
            if (result.success) {
                renderFileList(result.files);
            }
        } catch (error) {
            Toast.show('搜索失败', 'error');
        }
    }
};

const Uploader = {
    modal: null,
    dropZone: null,
    fileInput: null,
    selectBtn: null,
    closeBtn: null,
    queue: null,
    floatWidget: null,
    floatMini: null,
    floatPanel: null,
    floatPercent: null,
    floatList: null,
    refreshTimer: null,
    init() {
        this.modal = document.getElementById('uploadModal');
        this.dropZone = document.getElementById('uploadDropZone');
        this.fileInput = document.getElementById('fileInput');
        this.selectBtn = document.getElementById('uploadSelectBtn');
        this.closeBtn = document.getElementById('uploadClose');
        this.queue = document.getElementById('uploadQueue');
        this.floatWidget = document.getElementById('uploadFloatWidget');
        this.floatMini = document.getElementById('uploadFloatMini');
        this.floatPanel = document.getElementById('uploadFloatPanel');
        this.floatPercent = document.getElementById('uploadFloatPercent');
        this.floatList = document.getElementById('uploadFloatList');
        this.selectBtn.onclick = () => this.fileInput.click();
        this.closeBtn.onclick = () => this.hide();
        this.modal.onclick = (e) => {
            if (e.target === this.modal) this.hide();
        };
        this.fileInput.onchange = (e) => {
            this.addFiles(e.target.files);
            this.fileInput.value = '';
        };
        this.dropZone.ondragover = (e) => {
            e.preventDefault();
            this.dropZone.style.borderColor = 'var(--accent-primary)';
            this.dropZone.style.background = 'var(--accent-glow)';
        };
        this.dropZone.ondragleave = () => {
            this.dropZone.style.borderColor = '';
            this.dropZone.style.background = '';
        };
        this.dropZone.ondrop = (e) => {
            e.preventDefault();
            this.dropZone.style.borderColor = '';
            this.dropZone.style.background = '';
            this.addFiles(e.dataTransfer.files);
        };
        this.floatMini.onclick = () => {
            this.floatPanel.style.display = this.floatPanel.style.display === 'block' ? 'none' : 'block';
        };
        document.getElementById('uploadFloatClose').onclick = () => {
            this.floatPanel.style.display = 'none';
        };
    },
    show() {
        this.modal.classList.add('active');
    },
    hide() {
        this.modal.classList.remove('active');
    },
    addFiles(files) {
        const uploadParentId = State.currentParentId;
        const seen = new Set();
        Array.from(files).forEach(file => {
            // 去重：同一次选择中避免重复添加相同文件
            const key = file.name + '|' + file.size + '|' + file.lastModified;
            if (seen.has(key)) return;
            seen.add(key);
            const upload = {
                name: file.name,
                size: file.size,
                file: file,
                progress: 0,
                status: 'pending',
                parentId: uploadParentId
            };
            State.uploads.push(upload);
            this.renderQueue();
            this.uploadFile(upload);
        });
        if (!State.uploadActive) {
            State.uploadActive = true;
            this.floatWidget.style.display = 'block';
        }
    },
    uploadFile(upload) {
        upload.status = 'uploading';
        upload.retryCount = upload.retryCount || 0;
        const formData = new FormData();
        formData.append('action', 'upload');
        formData.append('file', upload.file);
        formData.append('parent_id', upload.parentId);
        formData.append('_csrf_token', Config.csrfToken || '');
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'index.php?action=upload', true);
        xhr.upload.onprogress = (e) => {
            if (e.lengthComputable) {
                upload.progress = Math.round((e.loaded / e.total) * 100);
                this.renderQueue();
                this.updateFloatWidget();
            }
        };
        xhr.onload = () => {
            if (xhr.status === 429) {
                upload.status = 'rate_limited';
                upload.retryCount++;
                let retryAfter = 5;
                let message = '请求过于频繁';

                try {
                    const r = JSON.parse(xhr.responseText);
                    if (r.retry_after && r.retry_after > 0) retryAfter = r.retry_after;
                    if (r.message) message = r.message;
                } catch (e) {}

                upload.retryAfter = retryAfter;
                upload.error = message;

                if (upload.retryCount <= 5) {
                    const waitTime = Math.min(retryAfter * upload.retryCount, 60);
                    Toast.show(`限速中，${waitTime}秒后自动重试(第${upload.retryCount}次)...`, 'warning');
                    upload.retryTimer = setTimeout(() => {
                        upload.status = 'pending';
                        this.uploadFile(upload);
                    }, waitTime * 1000);
                } else {
                    upload.status = 'error';
                    Toast.show(`${upload.name} 重试次数过多，请稍后再试`, 'error');
                }
                this.renderQueue();
                this.updateFloatWidget();
                return;
            }

            let result;
            try {
                result = JSON.parse(xhr.responseText);
            } catch (e) {
                upload.status = 'error';
                upload.error = '服务器响应异常';
                this.renderQueue();
                this.updateFloatWidget();
                return;
            }

            if (result.success) {
                upload.status = 'success';
                Toast.show(`${upload.name} 上传成功`, 'success');
                if (this.refreshTimer) clearTimeout(this.refreshTimer);
                this.refreshTimer = setTimeout(() => {
                    FileManager.load(State.currentParentId);
                    this.refreshTimer = null;
                }, 500);
            } else {
                upload.status = 'error';
                upload.error = result.message;
                Toast.show(`${upload.name} 上传失败`, 'error');
            }
            this.renderQueue();
            this.updateFloatWidget();
        };
        xhr.onerror = () => {
            upload.status = 'error';
            upload.error = '网络错误';
            this.renderQueue();
            this.updateFloatWidget();
        };
        xhr.send(formData);
    },
    renderQueue() {
        if (!this.queue) return;
        let html = '';
        State.uploads.forEach(upload => {
            html += `
                <div class="upload-queue-item">
                    <div class="upload-queue-info">
                        <span class="upload-queue-name">${escapeHtml(upload.name)}</span>
                        <span class="upload-queue-size">${formatSize(upload.size)}</span>
                    </div>
                    <div class="upload-queue-bar">
                        <div class="upload-queue-fill" style="width: ${upload.progress}%"></div>
                    </div>
                    <div class="upload-queue-status">${getStatusText(upload)}</div>
                </div>
            `;
        });
        this.queue.innerHTML = html;
    },
    updateFloatWidget() {
        if (State.uploads.length === 0) {
            this.floatWidget.style.display = 'none';
            State.uploadActive = false;
            return;
        }
        const totalProgress = State.uploads.reduce((sum, u) => sum + u.progress, 0) / State.uploads.length;
        this.floatPercent.textContent = `${Math.round(totalProgress)}%`;
        let listHtml = '';
        State.uploads.forEach(upload => {
            listHtml += `
                <div class="upload-float-item">
                    <div class="upload-float-item-header">
                        <span class="upload-float-item-name">${escapeHtml(upload.name)}</span>
                        <span class="upload-float-item-percent">${upload.progress}%</span>
                    </div>
                    <div class="upload-float-item-bar">
                        <div class="upload-float-item-fill" style="width: ${upload.progress}%"></div>
                    </div>
                    <div class="upload-float-item-status ${upload.status}">${getStatusText(upload)}</div>
                </div>
            `;
        });
        this.floatList.innerHTML = listHtml;
    }
};

function formatSize(bytes) {
    if (bytes === 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + units[i];
}

function getStatusText(upload) {
    switch (upload.status) {
        case 'pending': return '等待中...';
        case 'uploading': return `上传中 ${upload.progress}%`;
        case 'success': return '上传成功';
        case 'error': return `错误: ${upload.error || '未知'}`;
        case 'rate_limited': return `限速中，${upload.retryAfter || '?'}秒后重试`;
        default: return '';
    }
}

async function renderShares() {
    try {
        const result = await api('list_shares');
        if (result.success) {
            const container = document.getElementById('shareList');
            if (!result.shares || result.shares.length === 0) {
                container.innerHTML = `
                    <div class="empty-state-container">
                        <div class="empty-icon-wrap"><i class="fas fa-share-nodes"></i></div>
                        <div class="empty-title">暂无分享</div>
                        <div class="empty-desc">右键文件创建分享链接</div>
                    </div>
                `;
                return;
            }
            let html = '';
            result.shares.forEach(share => {
                const shareLink = `${window.location.origin}/index.php?page=share&token=${share.share_token}`;
                const expireTime = share.expire_at_formatted || '永久';
                html += `
                    <div class="share-card">
                        <div class="share-card-header">
                            <div class="share-card-icon"><i class="fas fa-file"></i></div>
                            <div class="share-card-info">
                                <div class="share-card-name">${escapeHtml(share.filename) || '未知文件'}</div>
                                <div class="share-card-meta">
                                    <span>过期: ${expireTime}</span>
                                    <span>下载: ${share.download_count || 0}</span>
                                </div>
                            </div>
                        </div>
                        <div class="share-card-actions">
                            <button class="action-btn" onclick="navigator.clipboard.writeText('${shareLink}'); Toast.show('已复制', 'success')">
                                <i class="fas fa-copy"></i> 复制链接
                            </button>
                            <button class="action-btn" onclick="cancelShare(${share.id})">
                                <i class="fas fa-trash-can"></i> 取消分享
                            </button>
                        </div>
                    </div>
                `;
            });
            container.innerHTML = html;
        }
    } catch (error) {
        Toast.show('加载分享失败', 'error');
    }
}

async function cancelShare(shareId) {
    Confirm.show('取消分享', '确定要取消这个分享吗？', async () => {
        try {
            const fd = new FormData();
            fd.append('action', 'delete_share');
            fd.append('share_id', shareId);
            fd.append('_csrf_token', Config.csrfToken || '');
            const result = await fetch('index.php?action=delete_share', {
                method: 'POST', body: fd
            }).then(r => r.json());
            if (result.success) {
                Toast.show('已取消分享', 'success');
                renderShares();
            } else {
                Toast.show(result.message || '取消失败', 'error');
            }
        } catch (error) {
            Toast.show('网络错误', 'error');
        }
    });
}

async function renderStats() {
    try {
        const result = await api('file_stats');
        if (result.success) {
            const container = document.getElementById('statsGrid');
            const stats = result.stats || {};
            container.innerHTML = `
                <div class="stat-card">
                    <div class="stat-value">${stats.total_files || 0}</div>
                    <div class="stat-label">文件数</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value">${stats.total_folders || 0}</div>
                    <div class="stat-label">文件夹</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value">${stats.total_size_formatted || formatSize(stats.total_size || 0)}</div>
                    <div class="stat-label">总大小</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value">${Config.storageTotal || '--'}</div>
                    <div class="stat-label">配额</div>
                </div>
            `;
        }
    } catch (error) {
        Toast.show('加载统计失败', 'error');
    }
}

function initPasswordChange() {
    const btn = document.getElementById('savePasswordBtn');
    if (!btn) return;
    btn.onclick = async () => {
        const oldPass = '';
        const newPass = document.getElementById('newPassword').value;
        const confirmPass = document.getElementById('confirmPassword').value;
        if (!newPass) {
            Toast.show('请输入新密码', 'warning');
            return;
        }
        if (newPass !== confirmPass) {
            Toast.show('两次密码不一致', 'error');
            return;
        }
        if (newPass.length < 6) {
            Toast.show('密码至少 6 位', 'warning');
            return;
        }
        try {
            const fd = new FormData();
            fd.append('action', 'change_password');
            fd.append('old_password', oldPass);
            fd.append('new_password', newPass);
            fd.append('confirm_password', confirmPass);
            fd.append('_csrf_token', Config.csrfToken || '');
            const result = await fetch('index.php?action=change_password', {
                method: 'POST', body: fd
            }).then(r => r.json());
            if (result.success) {
                Toast.show('密码修改成功', 'success');
                document.getElementById('newPassword').value = '';
                document.getElementById('confirmPassword').value = '';
            } else {
                Toast.show(result.message || '修改失败', 'error');
            }
        } catch (error) {
            Toast.show('网络错误', 'error');
        }
    };
}

document.addEventListener('DOMContentLoaded', () => {
    Navigation.init();
    Drawer.init();
    Modal.init();
    Confirm.init();
    Search.init();
    Uploader.init();
    Breadcrumb.init();
    initPasswordChange();
    document.getElementById('uploadBtn').onclick = () => Uploader.show();
    document.getElementById('newFolderBtn').onclick = () => FileManager.newFolder();
    document.getElementById('selectAllBtn').onclick = () => {
        document.querySelectorAll('.file-checkbox').forEach(cb => {
            cb.checked = true;
            State.selectedFiles.add(parseInt(cb.dataset.id));
            cb.closest('.file-card').classList.add('selected');
        });
    };
    document.getElementById('batchDeleteBtn').onclick = () => {
        FileManager.deleteFiles(Array.from(State.selectedFiles));
    };
    FileManager.load(Config.initialParentId || 0);
});