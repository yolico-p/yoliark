/**
 * 上传子系统 - 分片上传、并发控制、进度追踪、中断感知
 */

const MAX_CONCURRENT_UPLOADS = 1;
let currentUploadCount = 0;
let uploadQueue = [];
let activeUploads = {};
let pendingConflicts = [];
let batchConflictResolution = null;
var _uploadRefreshNeeded = false;

function _requestUploadRefresh() {
    _uploadRefreshNeeded = true;
}

function _removeQueueItem(id) {
    setTimeout(function () {
        var el = document.getElementById(id);
        if (el) el.remove();
    }, 3000);
}

const uploadSession = {
    totalFiles: 0,
    totalSize: 0,
    active: false,
    allFiles: [],

    start(files) {
        const filesArray = Array.from(files);
        this.totalFiles = filesArray.length;
        this.totalSize = filesArray.reduce((sum, f) => sum + f.size, 0);
        this.active = true;
        this.allFiles = filesArray.map(f => ({ name: f.name, size: f.size }));
        uploadManager.resetProgress();
        pendingConflicts = [];
        batchConflictResolution = null;
        try {
            localStorage.removeItem('uploadTasks');
            localStorage.removeItem('uploadSession');
            localStorage.removeItem('uploadAllFiles');
        } catch (e) {}
    },

    reset() {
        this.totalFiles = 0;
        this.totalSize = 0;
        this.active = false;
        this.allFiles = [];
    },

    getProgressInfo() {
        if (!this.active || this.totalSize === 0) {
            return null;
        }

        const totalUploaded = Array.from(uploadManager.tasks.values()).reduce(function(sum, t) {
            if (t.status === 'success') return sum + t.size;
            if (t.status === 'error') return sum + 0;
            return sum + Math.round((t.progress / 100) * t.size);
        }, 0);

        const overallProgress = Math.round((totalUploaded / this.totalSize) * 100);
        const uploadedMB = (totalUploaded / (1024 * 1024)).toFixed(1);
        const totalMB = (this.totalSize / (1024 * 1024)).toFixed(1);

        return {
            overallProgress,
            uploadedMB,
            totalMB,
            totalUploaded,
            totalSize: this.totalSize
        };
    }
};

const uploadManager = {
    tasks: new Map(),
    isPanelOpen: false,
    _persistTimer: null,

    _flush() {
        this._persistTimer = null;
        this.saveToStorage();
        this.updateFloatWidget();
    },

    _schedulePersist() {
        if (this._persistTimer) return;
        this._persistTimer = setTimeout(() => this._flush(), 2000);
    },

    _taskList() {
        return Array.from(this.tasks.values());
    },

    resetProgress() {
        this.tasks.clear();
        if (this._persistTimer) {
            clearTimeout(this._persistTimer);
            this._persistTimer = null;
        }
        this.saveToStorage();
        this.updateFloatWidget();
    },

    addTask(id, filename, size) {
        this.tasks.set(id, {
            id: id,
            filename: filename,
            size: size,
            uploaded: 0,
            progress: 0,
            status: 'uploading'
        });
        this.saveToStorage();
        this.updateFloatWidget();
        this.showFloatWidget();
    },

    removeTask(id) {
        this.tasks.delete(id);
        this.saveToStorage();
        this.updateFloatWidget();
    },

    updateTask(id, progress, status) {
        const task = this.tasks.get(id);
        if (task) {
            task.progress = progress;
            task.uploaded = Math.round((progress / 100) * task.size);
            if (status) task.status = status;
            this._schedulePersist();
        }
    },

    cancelTask(id) {
        if (activeUploads[id]) {
            if (typeof activeUploads[id].abort === 'function') {
                activeUploads[id].abort();
            } else if (activeUploads[id].abort) {
                activeUploads[id].abort();
            }
            delete activeUploads[id];
        }

        const queueItem = document.getElementById(id);
        if (queueItem) queueItem.remove();

        const statusEl = document.getElementById(id + '_status');
        if (statusEl) {
            statusEl.innerHTML = '<i class="fas fa-ban" style="color:var(--text-muted)"></i> 已取消';
        }

        uploadManager.updateTask(id, uploadManager.tasks.get(id)?.progress || 0, 'error');
        uploadFinished();

        api('cancel_upload', {upload_id: id}).then(() => {}).catch(() => {});
    },

    updateFloatWidget() {
        const widget = document.getElementById('uploadFloatWidget');
        const mini = document.getElementById('uploadFloatMini');
        if (this.tasks.size === 0) {
            mini.classList.add('idle');
            return;
        }

        const list = this._taskList();
        const sessionInfo = uploadSession.getProgressInfo();
        const uploadingTasks = list.filter(t => t.status === 'uploading');
        
        if (sessionInfo) {
            document.getElementById('uploadFloatText').textContent = `上传中 ${sessionInfo.overallProgress}% (${sessionInfo.uploadedMB}/${sessionInfo.totalMB}MB)`;
        } else {
            document.getElementById('uploadFloatText').textContent = `全部完成`;
        }

        if (uploadingTasks.length === 0 && list.length > 0) {
            setTimeout(() => {
                if (this._taskList().every(t => t.status !== 'uploading')) {
                    mini.classList.add('idle');
                    this.tasks.clear();
                    uploadSession.reset();
                    this.saveToStorage();
                }
            }, 3000);
        }

        this.updateFloatList();
    },

    updateFloatList() {
        const list = document.getElementById('uploadFloatList');
        if (!list) return;

        var count = 0;
        let html = '';
        this.tasks.forEach(task => {
            if (task.status === 'success') return; // 已完成的不显示在浮窗
            const statusText = task.status === 'uploading' ? '上传中...' : 
                              task.status === 'success' ? '完成' : 
                              task.status === 'error' ? '上传失败' : '';
            const statusClass = task.status === 'success' ? 'success' : 
                               task.status === 'error' ? 'error' : '';
            const showCancel = task.status === 'uploading';

            html += `
                <div class="upload-float-item">
                    <div class="upload-float-item-header">
                        <span class="upload-float-item-name" title="${escapeHtml(task.filename)}">${escapeHtml(task.filename)}</span>
                        <span class="upload-float-item-percent">${task.progress}%</span>
                        ${showCancel ? `<button class="upload-float-cancel-btn" onclick="uploadManager.cancelTask('${task.id}')" title="取消上传"><i class="fas fa-times"></i></button>` : ''}
                    </div>
                    <div class="upload-float-item-bar">
                        <div class="upload-float-item-fill" style="width:${task.progress}%"></div>
                    </div>
                    <div class="upload-float-item-status ${statusClass}">${statusText}</div>
                </div>
            `;
        });
        list.innerHTML = html;
    },

    showFloatWidget() {
        const mini = document.getElementById('uploadFloatMini');
        if (mini) mini.classList.remove('idle');
    },

    hideFloatWidget() {
        const mini = document.getElementById('uploadFloatMini');
        if (mini) mini.classList.add('idle');
    },

    saveToStorage() {
        try {
            localStorage.setItem('uploadTasks', JSON.stringify(this._taskList()));
            if (uploadSession.active) {
                localStorage.setItem('uploadSession', JSON.stringify({
                    totalFiles: uploadSession.totalFiles,
                    totalSize: uploadSession.totalSize,
                    active: uploadSession.active
                }));
                localStorage.setItem('uploadAllFiles', JSON.stringify(uploadSession.allFiles));
            } else {
                localStorage.removeItem('uploadSession');
                localStorage.removeItem('uploadAllFiles');
            }
        } catch (e) {}
    },

    loadFromStorage() {
        try {
            const saved = localStorage.getItem('uploadTasks');
            if (saved) {
                var arr = JSON.parse(saved);
                this.tasks = new Map(arr.map(function(t) { return [t.id, t]; }));
                const sessionSaved = localStorage.getItem('uploadSession');
                const allFilesSaved = localStorage.getItem('uploadAllFiles');
                const allFilesList = allFilesSaved ? JSON.parse(allFilesSaved) : [];
                if (sessionSaved) {
                    const sessionData = JSON.parse(sessionSaved);
                    uploadSession.totalFiles = sessionData.totalFiles || 0;
                    uploadSession.totalSize = sessionData.totalSize || 0;
                    uploadSession.active = sessionData.active || false;
                    uploadSession.allFiles = allFilesList;
                }
                var list = this._taskList();
                var hasUploading = list.some(function(t) { return t.status === 'uploading'; });
                if (hasUploading || uploadSession.active) {
                    var incomplete = uploadSession.allFiles.filter(function(f) {
                        var matchingTask = uploadManager.tasks.get(f.name);
                        return !matchingTask || matchingTask.status !== 'success';
                    }).map(function(f) {
                        var matchingTask = uploadManager.tasks.get(f.name);
                        return {
                            filename: f.name,
                            size: f.size,
                            progress: matchingTask ? matchingTask.progress : 0,
                            status: matchingTask ? matchingTask.status : 'pending'
                        };
                    });
                    if (incomplete.length > 0) {
                        this.tasks.clear();
                        uploadSession.reset();
                        localStorage.removeItem('uploadTasks');
                        localStorage.removeItem('uploadSession');
                        localStorage.removeItem('uploadAllFiles');
                        showInterruptDialog(incomplete);
                        return;
                    }
                }
                this.tasks.clear();
                uploadSession.reset();
                localStorage.removeItem('uploadTasks');
                localStorage.removeItem('uploadSession');
                localStorage.removeItem('uploadAllFiles');
            }
        } catch (e) {}
    }
};

let interruptedFiles = [];

function addUploadConflict(conflictData, itemId, type, extra) {
    if (batchConflictResolution) {
        resolveSingleConflict(itemId, batchConflictResolution, type, extra);
        return;
    }

    pendingConflicts.push({
        itemId,
        filename: conflictData.duplicate_filename || '',
        message: conflictData.message,
        type,
        file: extra.file || null,
        parentId: extra.parentId || null,
        uploadId: extra.uploadId || conflictData.upload_id || null,
    });

    renderBatchConflictDialog();
}

function renderBatchConflictDialog() {
    let overlay = document.getElementById('uploadConflictOverlay');

    if (!overlay) {
        overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.id = 'uploadConflictOverlay';
        document.body.appendChild(overlay);
        requestAnimationFrame(() => overlay.classList.add('active'));
    }

    const count = pendingConflicts.length;

    let listHtml = '';
    pendingConflicts.forEach((c, i) => {
        const ext = c.filename.split('.').pop().toLowerCase();
        const iconMap = {
            jpg: 'fa-image', jpeg: 'fa-image', png: 'fa-image', gif: 'fa-image', webp: 'fa-image', svg: 'fa-image', bmp: 'fa-image',
            mp4: 'fa-film', avi: 'fa-film', mkv: 'fa-film', mov: 'fa-film', flv: 'fa-film', webm: 'fa-film',
            mp3: 'fa-music', wav: 'fa-music', flac: 'fa-music', aac: 'fa-music', ogg: 'fa-music',
            pdf: 'fa-file-pdf', doc: 'fa-file-word', docx: 'fa-file-word', xls: 'fa-file-excel', xlsx: 'fa-file-excel',
            zip: 'fa-file-archive', rar: 'fa-file-archive', '7z': 'fa-file-archive',
            txt: 'fa-file-alt', md: 'fa-file-alt', log: 'fa-file-alt',
        };
        const icon = iconMap[ext] || 'fa-file';

        listHtml += `
            <div class="conflict-file-item" id="conflict_${i}" style="display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:8px;background:var(--bg-glass);margin-bottom:8px">
                <i class="fas ${icon}" style="color:var(--accent-warning);font-size:18px;flex-shrink:0"></i>
                <div style="flex:1;min-width:0">
                    <div style="font-size:13px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="${escapeHtml(c.filename)}">${escapeHtml(c.filename)}</div>
                    <div style="font-size:12px;color:var(--text-muted);margin-top:2px">${escapeHtml(c.message)}</div>
                </div>
                <div style="display:flex;gap:6px;flex-shrink:0">
                    <button class="btn btn-sm" style="padding:4px 10px;font-size:12px;background:var(--accent-primary);color:#fff;border:none;border-radius:6px;cursor:pointer" onclick="resolveConflictItem(${i},'overwrite')">覆盖</button>
                    <button class="btn btn-sm" style="padding:4px 10px;font-size:12px;background:var(--bg-glass);color:var(--text-secondary);border:1px solid var(--bg-glass-border);border-radius:6px;cursor:pointer" onclick="resolveConflictItem(${i},'keep_both')">副本</button>
                    <button class="btn btn-sm" style="padding:4px 10px;font-size:12px;background:transparent;color:var(--text-muted);border:1px solid var(--bg-glass-border);border-radius:6px;cursor:pointer" onclick="resolveConflictItem(${i},'cancel')">取消</button>
                </div>
            </div>
        `;
    });

    overlay.innerHTML = `
        <div class="modal-box glass-strong" onclick="event.stopPropagation()" style="max-width:560px">
            <div class="modal-header">
                <h3>文件冲突 <span style="font-size:13px;color:var(--text-muted);font-weight:400">${count} 个文件</span></h3>
                <button class="modal-close" onclick="closeBatchConflictDialog()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body" style="max-height:50vh;overflow-y:auto">
                ${listHtml}
            </div>
            <div style="padding:12px 20px 16px;border-top:1px solid var(--bg-glass-border)">
                <div style="display:flex;gap:8px;margin-bottom:10px">
                    <button class="btn btn-primary" style="flex:1" onclick="resolveAllConflicts('overwrite')">
                        <i class="fas fa-sync-alt"></i> 全部覆盖
                    </button>
                    <button class="btn btn-glass" style="flex:1" onclick="resolveAllConflicts('keep_both')">
                        <i class="fas fa-copy"></i> 全部保留副本
                    </button>
                    <button class="btn btn-glass" style="flex:1;color:var(--text-muted)" onclick="resolveAllConflicts('cancel')">
                        <i class="fas fa-ban"></i> 全部取消
                    </button>
                </div>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:var(--text-secondary)">
                    <input type="checkbox" id="conflictAutoApply" style="accent-color:var(--accent-primary)">
                    对后续冲突自动应用相同操作
                </label>
            </div>
        </div>
    `;

    overlay.onclick = (e) => {
        if (e.target === overlay) closeBatchConflictDialog();
    };
}

function resolveConflictItem(index, resolution) {
    const conflict = pendingConflicts[index];
    if (!conflict) return;

    const el = document.getElementById('conflict_' + index);
    if (el) {
        const labelMap = { overwrite: '已覆盖', keep_both: '保留副本', cancel: '已取消' };
        const colorMap = { overwrite: 'var(--accent-primary)', keep_both: 'var(--accent-success)', cancel: 'var(--text-muted)' };
        el.innerHTML = `
            <i class="fas fa-check" style="color:${colorMap[resolution]};font-size:16px;flex-shrink:0"></i>
            <div style="flex:1;min-width:0">
                <div style="font-size:13px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${escapeHtml(conflict.filename)}</div>
            </div>
            <span style="font-size:12px;color:${colorMap[resolution]}">${labelMap[resolution]}</span>
        `;
    }

    resolveSingleConflict(conflict.itemId, resolution, conflict.type, conflict);

    pendingConflicts[index] = null;

    const remaining = pendingConflicts.filter(c => c !== null);
    if (remaining.length === 0) {
        closeBatchConflictDialog();
    }
}

function resolveAllConflicts(resolution) {
    const autoApply = document.getElementById('conflictAutoApply');
    if (autoApply && autoApply.checked) {
        batchConflictResolution = resolution;
    }

    pendingConflicts.forEach((conflict, i) => {
        if (!conflict) return;
        resolveSingleConflict(conflict.itemId, resolution, conflict.type, conflict);
    });

    pendingConflicts = [];
    closeBatchConflictDialog();
}

function closeBatchConflictDialog() {
    const overlay = document.getElementById('uploadConflictOverlay');
    if (overlay) {
        overlay.classList.remove('active');
        setTimeout(() => overlay.remove(), 300);
    }

    pendingConflicts.forEach(conflict => {
        if (conflict) {
            resolveSingleConflict(conflict.itemId, 'cancel', conflict.type, conflict);
        }
    });
    pendingConflicts = [];
}

function resolveSingleConflict(itemId, resolution, type, extra) {
    if (resolution === 'cancel') {
        const statusEl = document.getElementById(itemId + '_status');
        if (statusEl) {
            statusEl.innerHTML = '<i class="fas fa-ban" style="color:var(--text-muted)"></i> 已取消';
        }
        uploadManager.updateTask(itemId, uploadManager.tasks.get(itemId)?.progress || 0, 'error');
        if (extra.uploadId) {
            api('cancel_upload', { upload_id: extra.uploadId }).catch(() => {});
        }
        return;
    }

    if (type === 'regular' && extra.file) {
        retryUploadWithResolution(extra.file, itemId, extra.parentId, resolution);
    } else if (type === 'chunked' && extra.uploadId) {
        resolveChunkedConflict(itemId, extra.uploadId, resolution);
    }
}

function retryUploadWithResolution(file, itemId, parentId, conflictResolution) {
    const formData = new FormData();
    formData.append('file', file);
    formData.append('parent_id', parentId);
    formData.append('conflict_resolution', conflictResolution);
    formData.append('_csrf_token', APP_CONFIG.csrfToken);

    const statusEl = document.getElementById(itemId + '_status');
    if (statusEl) {
        statusEl.innerHTML = '重新上传...';
    }

    const xhr = new XMLHttpRequest();
    activeUploads[itemId] = xhr;
    xhr.open('POST', 'index.php?action=upload');
    xhr.setRequestHeader('X-CSRF-TOKEN', APP_CONFIG.csrfToken);

    xhr.upload.onprogress = e => {
        if (e.lengthComputable) {
            const pct = Math.round((e.loaded / e.total) * 100);
            const bar = document.getElementById(itemId + '_bar');
            if (bar) bar.style.width = pct + '%';
            uploadManager.updateTask(itemId, pct);
        }
    };

    xhr.onload = () => {
        delete activeUploads[itemId];
        try {
            const data = JSON.parse(xhr.responseText);
            if (data.success) {
                const s = document.getElementById(itemId + '_status');
                const b = document.getElementById(itemId + '_bar');
                if (s) s.innerHTML = '<i class="fas fa-check" style="color:var(--accent-success)"></i> 完成';
                if (b) b.style.width = '100%';
                uploadManager.updateTask(itemId, 100, 'success');
                _removeQueueItem(itemId);
                _requestUploadRefresh();
            } else {
                const s = document.getElementById(itemId + '_status');
                if (s) s.innerHTML = '<i class="fas fa-times" style="color:var(--accent-danger)"></i> ' + escapeHtml(data.message);
                uploadManager.updateTask(itemId, uploadManager.tasks.get(itemId)?.progress || 0, 'error');
            }
            uploadFinished();
        } catch (e) {
            const s = document.getElementById(itemId + '_status');
            if (s) s.innerHTML = '<i class="fas fa-times" style="color:var(--accent-danger)"></i> 上传失败';
            uploadManager.updateTask(itemId, uploadManager.tasks.get(itemId)?.progress || 0, 'error');
            uploadFinished();
        }
    };

    xhr.onerror = () => {
        delete activeUploads[itemId];
        const s = document.getElementById(itemId + '_status');
        if (s) s.innerHTML = '<i class="fas fa-times" style="color:var(--accent-danger)"></i> 网络错误';
        uploadManager.updateTask(itemId, uploadManager.tasks.get(itemId)?.progress || 0, 'error');
        uploadFinished();
    };

    xhr.send(formData);
}

function resolveChunkedConflict(itemId, uploadId, conflictResolution) {
    const statusEl = document.getElementById(itemId + '_status');
    if (statusEl) {
        statusEl.innerHTML = '处理中...';
    }

    api('resolve_upload_conflict', {
        upload_id: uploadId,
        conflict_resolution: conflictResolution
    }).then(data => {
        if (data.success) {
            const s = document.getElementById(itemId + '_status');
            const b = document.getElementById(itemId + '_bar');
            if (s) s.innerHTML = '<i class="fas fa-check" style="color:var(--accent-success)"></i> 完成';
            if (b) b.style.width = '100%';
            uploadManager.updateTask(itemId, 100, 'success');
                _removeQueueItem(itemId);
            _requestUploadRefresh();
        } else {
            const s = document.getElementById(itemId + '_status');
            if (s) s.innerHTML = '<i class="fas fa-times" style="color:var(--accent-danger)"></i> ' + escapeHtml(data.message);
            uploadManager.updateTask(itemId, uploadManager.tasks.get(itemId)?.progress || 0, 'error');
        }
        uploadFinished();
    }).catch(() => {
        const s = document.getElementById(itemId + '_status');
        if (s) s.innerHTML = '<i class="fas fa-times" style="color:var(--accent-danger)"></i> 处理失败';
        uploadManager.updateTask(itemId, uploadManager.tasks.get(itemId)?.progress || 0, 'error');
        uploadFinished();
    });
}

function showInterruptDialog(tasks) {
    interruptedFiles = tasks;
    
    const count = tasks.length;
    const totalSize = tasks.reduce((sum, t) => sum + t.size, 0);
    const hint = document.getElementById('interruptHint');
    hint.textContent = `页面已刷新，${count} 个文件（${formatSize(totalSize)}）未成功上传。`;

    const list = document.getElementById('interruptFileList');
    const MAX_VISIBLE = 50;
    const displayTasks = tasks.slice(0, MAX_VISIBLE);
    const hasMore = tasks.length > MAX_VISIBLE;

    const iconMap = {
        jpg: 'fa-image', jpeg: 'fa-image', png: 'fa-image', gif: 'fa-image', webp: 'fa-image', svg: 'fa-image', bmp: 'fa-image',
        mp4: 'fa-film', avi: 'fa-film', mkv: 'fa-film', mov: 'fa-film', flv: 'fa-film', webm: 'fa-film',
        mp3: 'fa-music', wav: 'fa-music', flac: 'fa-music', aac: 'fa-music', ogg: 'fa-music',
        pdf: 'fa-file-pdf', doc: 'fa-file-word', docx: 'fa-file-word', xls: 'fa-file-excel', xlsx: 'fa-file-excel', ppt: 'fa-file-powerpoint', pptx: 'fa-file-powerpoint',
        zip: 'fa-file-archive', rar: 'fa-file-archive', '7z': 'fa-file-archive', tar: 'fa-file-archive', gz: 'fa-file-archive',
        txt: 'fa-file-alt', md: 'fa-file-alt', log: 'fa-file-alt', csv: 'fa-file-alt',
        js: 'fa-code', ts: 'fa-code', py: 'fa-code', php: 'fa-code', html: 'fa-code', css: 'fa-code', java: 'fa-code',
    };

    let html = '';
    displayTasks.forEach(task => {
        const ext = task.filename.split('.').pop().toLowerCase();
        const icon = iconMap[ext] || 'fa-file';
        const statusText = task.status === 'success' ? '完成' :
                           task.status === 'error' ? '失败' :
                           task.status === 'pending' ? '未开始' :
                           task.progress + '%';
        const statusClass = (task.status === 'error' || task.status === 'pending') ? 'failed' : '';
        html += `
            <div class="interrupt-file-item">
                <div class="interrupt-file-icon"><i class="fas ${icon}"></i></div>
                <div class="interrupt-file-info">
                    <div class="interrupt-file-name" title="${escapeHtml(task.filename)}">${escapeHtml(task.filename)}</div>
                    <div class="interrupt-file-meta">${formatSize(task.size)} · .${ext}</div>
                </div>
                <div class="interrupt-file-progress ${statusClass}">${statusText}</div>
            </div>
        `;
    });

    if (hasMore) {
        html += `<div class="interrupt-more-hint">…以及 ${tasks.length - MAX_VISIBLE} 个文件</div>`;
    }

    list.innerHTML = html;
    document.getElementById('uploadInterruptOverlay').style.display = 'flex';
}

function closeInterruptDialog() {
    document.getElementById('uploadInterruptOverlay').style.display = 'none';
    interruptedFiles = [];
}

function toggleFloatWidget() {
    uploadManager.isPanelOpen = !uploadManager.isPanelOpen;
    const panel = document.getElementById('uploadFloatPanel');
    if (panel) {
        panel.style.display = uploadManager.isPanelOpen ? 'block' : 'none';
    }
}

function showUploadDialog() {
    document.getElementById('uploadOverlay').style.display = 'flex';
    document.getElementById('uploadQueue').innerHTML = '';
}

function closeUploadDialog() {
    const hasUploadingTasks = Array.from(uploadManager.tasks.values()).some(function(t) { return t.status === 'uploading'; });
    if (hasUploadingTasks) {
        document.getElementById('uploadOverlay').style.display = 'none';
        uploadManager.showFloatWidget();
    } else {
        document.getElementById('uploadOverlay').style.display = 'none';
    }
}

function handleFileSelect(files) {
    if (!files || files.length === 0) return;
    
    uploadSession.start(files);
    
    const uploadParentId = currentParentId;
    
    for (let i = 0; i < files.length; i++) {
        uploadQueue.push({
            file: files[i],
            index: i,
            itemId: null,
            parentId: uploadParentId
        });
    }
    
    processUploadQueue();
}

function processUploadQueue() {
    while (currentUploadCount < MAX_CONCURRENT_UPLOADS && uploadQueue.length > 0) {
        const queueItem = uploadQueue.shift();
        currentUploadCount++;
        uploadFile(queueItem.file, queueItem.index, queueItem.parentId);
    }
}

function uploadFinished() {
    currentUploadCount = Math.max(0, currentUploadCount - 1);
    processUploadQueue();
    // 上传批次全部完成后一次性刷新文件列表
    if (_uploadRefreshNeeded && uploadQueue.length === 0 && currentUploadCount === 0) {
        _uploadRefreshNeeded = false;
        loadFiles(currentParentId);
        loadStorageInfo();
    }
}

function uploadFile(file, fileIndex = 0, parentId) {
    const queue = document.getElementById('uploadQueue');
    const itemId = 'upload_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    const maxSize = APP_CONFIG.chunkSize;

    queue.insertAdjacentHTML('beforeend', `
        <div class="queue-item" id="${itemId}">
            <div class="queue-info"><span class="queue-filename">${escapeHtml(file.name)}</span><span class="queue-size">${formatSize(file.size)}</span></div>
            <div class="queue-bar"><div class="queue-fill" id="${itemId}_bar" style="width:0%"></div></div>
            <span class="queue-status" id="${itemId}_status">上传中...</span>
            <button class="queue-cancel-btn" onclick="uploadManager.cancelTask('${itemId}')" title="取消上传"><i class="fas fa-times"></i></button>
        </div>
    `);

    uploadManager.addTask(itemId, file.name, file.size);

    if (file.size > maxSize) {
        uploadChunked(file, itemId, parentId);
    } else {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('parent_id', parentId);
        formData.append('_csrf_token', APP_CONFIG.csrfToken);

        const xhr = new XMLHttpRequest();
        activeUploads[itemId] = xhr;
        xhr.open('POST', 'index.php?action=upload');
        xhr.setRequestHeader('X-CSRF-TOKEN', APP_CONFIG.csrfToken);

        xhr.upload.onprogress = e => {
            if (e.lengthComputable) {
                const pct = Math.round((e.loaded / e.total) * 100);
                document.getElementById(itemId + '_bar').style.width = pct + '%';
                uploadManager.updateTask(itemId, pct);
            }
        };

        xhr.onload = () => {
            delete activeUploads[itemId];
            try {
                const data = JSON.parse(xhr.responseText);
                if (data.success) {
                    document.getElementById(itemId + '_status').innerHTML = '<i class="fas fa-check" style="color:var(--accent-success)"></i> 完成';
                    document.getElementById(itemId + '_bar').style.width = '100%';
                    uploadManager.updateTask(itemId, 100, 'success');
                _removeQueueItem(itemId);
                    _requestUploadRefresh();
                    uploadFinished();
                } else if (data.duplicate_conflict) {
                    document.getElementById(itemId + '_status').innerHTML = '<i class="fas fa-exclamation-triangle" style="color:var(--accent-warning)"></i> 文件冲突';
                    uploadManager.updateTask(itemId, 100, 'error');
                    addUploadConflict(data, itemId, 'regular', { file, parentId });
                    uploadFinished();
                } else {
                    document.getElementById(itemId + '_status').innerHTML = '<i class="fas fa-times" style="color:var(--accent-danger)"></i> ' + escapeHtml(data.message);
                    uploadManager.updateTask(itemId, uploadManager.tasks.get(itemId)?.progress || 0, 'error');
                    uploadFinished();
                }
            } catch (e) {
                document.getElementById(itemId + '_status').innerHTML = '<i class="fas fa-times" style="color:var(--accent-danger)"></i> 上传失败';
                uploadManager.updateTask(itemId, uploadManager.tasks.get(itemId)?.progress || 0, 'error');
                uploadFinished();
            }
        };

        xhr.onerror = () => {
            delete activeUploads[itemId];
            document.getElementById(itemId + '_status').innerHTML = '<i class="fas fa-times" style="color:var(--accent-danger)"></i> 网络错误';
            uploadManager.updateTask(itemId, uploadManager.tasks.get(itemId)?.progress || 0, 'error');
            uploadFinished();
        };

        xhr.onabort = () => {
            delete activeUploads[itemId];
            document.getElementById(itemId + '_status').innerHTML = '<i class="fas fa-ban" style="color:var(--text-muted)"></i> 已取消';
            uploadManager.updateTask(itemId, uploadManager.tasks.get(itemId)?.progress || 0, 'error');
            uploadFinished();
        };

        xhr.send(formData);
    }
}

function uploadChunked(file, itemId, parentId) {
    const chunkSize = APP_CONFIG.chunkSize;
    const totalChunks = Math.ceil(file.size / chunkSize);
    const uploadId = Date.now().toString(36) + Math.random().toString(36).substr(2, 9);
    let currentChunk = 0;
    const maxRetries = 3;
    let retryCount = 0;
    let isCancelled = false;

    function uploadNextChunk() {
        if (isCancelled) return;
        if (currentChunk >= totalChunks) return;

        const start = currentChunk * chunkSize;
        const end = Math.min(start + chunkSize, file.size);
        const chunk = file.slice(start, end);

        const formData = new FormData();
        formData.append('parent_id', parentId);
        formData.append('upload_id', uploadId);
        formData.append('chunk_index', currentChunk);
        formData.append('total_chunks', totalChunks);
        formData.append('filename', file.name);
        formData.append('total_size', file.size);
        formData.append('_csrf_token', APP_CONFIG.csrfToken);
        formData.append('chunk_data', chunk);

            const xhr = new XMLHttpRequest();
            activeUploads[itemId] = xhr;
            xhr.open('POST', 'index.php?action=upload_chunk');
            xhr.setRequestHeader('X-CSRF-TOKEN', APP_CONFIG.csrfToken);

            xhr.onload = () => {
                delete activeUploads[itemId];
                if (isCancelled) return;

                try {
                    const data = JSON.parse(xhr.responseText);
                    const pct = Math.round(((currentChunk + 1) / totalChunks) * 100);
                    document.getElementById(itemId + '_bar').style.width = pct + '%';
                    uploadManager.updateTask(itemId, pct);

                    if (data.success && data.merged) {
                        document.getElementById(itemId + '_status').innerHTML = '<i class="fas fa-check" style="color:var(--accent-success)"></i> 完成';
                        uploadManager.updateTask(itemId, 100, 'success');
                _removeQueueItem(itemId);
                        _requestUploadRefresh();
                        uploadFinished();
                    } else if (data.success) {
                        currentChunk++;
                        retryCount = 0;
                        uploadNextChunk();
                    } else if (data.duplicate_conflict) {
                        document.getElementById(itemId + '_status').innerHTML = '<i class="fas fa-exclamation-triangle" style="color:var(--accent-warning)"></i> 文件冲突';
                        uploadManager.updateTask(itemId, pct, 'error');
                        addUploadConflict(data, itemId, 'chunked', { uploadId: data.upload_id || uploadId });
                        uploadFinished();
                    } else {
                        document.getElementById(itemId + '_status').innerHTML = '<i class="fas fa-times" style="color:var(--accent-danger)"></i> ' + escapeHtml(data.message);
                        uploadManager.updateTask(itemId, pct, 'error');
                        uploadFinished();
                    }
                } catch (e) {
                    handleUploadError(itemId, pct, currentChunk, totalChunks);
                }
            };

            xhr.onerror = () => {
                delete activeUploads[itemId];
                if (isCancelled) return;
                const pct = Math.round((currentChunk / totalChunks) * 100);
                handleUploadError(itemId, pct, currentChunk, totalChunks);
            };

            xhr.onabort = () => {
                delete activeUploads[itemId];
            };

            xhr.send(formData);
    }

    function handleUploadError(itemId, pct, chunk, total) {
        if (isCancelled) return;
        if (retryCount < maxRetries) {
            retryCount++;
            const delay = Math.min(1000 * Math.pow(2, retryCount - 1), 5000);
            setTimeout(() => uploadNextChunk(), delay);
        } else {
            document.getElementById(itemId + '_status').innerHTML = '<i class="fas fa-times" style="color:var(--accent-danger)"></i> 网络错误（已重试' + maxRetries + '次）';
            uploadManager.updateTask(itemId, pct, 'error');
            uploadFinished();
        }
    }

    activeUploads[itemId] = {
        abort: () => {
            isCancelled = true;
            const activeXhr = activeUploads[itemId];
            if (activeXhr && activeXhr.abort) {
                activeXhr.abort();
            }
        }
    };

    uploadNextChunk();
}

// 拖放上传由 app.js 中的 Uploader 统一处理，避免重复触发
// (function initDropzone() {
//     const dropzone = document.getElementById('uploadDropzone');
//     if (dropzone) {
//         ['dragenter', 'dragover'].forEach(evt => {
//             dropzone.addEventListener(evt, e => { e.preventDefault(); dropzone.classList.add('dragover'); });
//         });
//         ['dragleave', 'drop'].forEach(evt => {
//             dropzone.addEventListener(evt, e => { e.preventDefault(); dropzone.classList.remove('dragover'); });
//         });
//         dropzone.addEventListener('drop', e => { handleFileSelect(e.dataTransfer.files); });
//     }
// })();

(function initFloatButton() {
    const mini = document.getElementById('uploadFloatMini');
    if (mini) {
        mini.addEventListener('click', () => {
            if (mini.classList.contains('idle')) {
                showUploadDialog();
            } else {
                toggleFloatWidget();
            }
        });
    }
})();
