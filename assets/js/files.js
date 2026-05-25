/**
 * 文件管理核心 - 列表渲染、导航、操作
 */

let currentParentId = 0;
let currentView = 'list';
let currentSort = 'name';
let currentSortOrder = 'asc';
let selectedFiles = new Set();
let contextFileId = null;
let contextFileData = null;
let isFirstLoad = true;
let currentFileList = [];
let isTouchDevice = ('ontouchstart' in window) || (navigator.maxTouchPoints > 0);
function handleFileRowClick(fileId, isDir) {
    if (!isTouchDevice) return;
    // 如果是长按触发的点击，不执行任何操作（已经弹出菜单）
    if (isLongPressing) return;
    if (isDir) {
        navigateTo(fileId);
    } else {
        previewFile(fileId);
    }
}

var fileListAbort = null;
var longPressInitialized = false;

function loadFiles(parentId = 0) {
    // 取消前一个尚未返回的请求，避免快速导航时响应乱序
    if (fileListAbort) {
        fileListAbort.abort();
    }
    fileListAbort = new AbortController();

    currentParentId = parentId;
    selectedFiles.clear();
    updateBatchButtons();
    syncMasterCheckboxes();
    
    // 初始化长按检测（只执行一次）
    if (!longPressInitialized && isTouchDevice) {
        initLongPressForContextMenu();
        longPressInitialized = true;
    }

    // 网格模式：清空容器，加载后渐显
    if (currentView === 'grid') {
        var container = document.getElementById('fileList');
        if (container) {
            container.classList.add('file-grid-mode');
            container.innerHTML = '';
        }
    }

    // 面包屑和文件列表同时请求，互不等待
    loadBreadcrumb(parentId, fileListAbort.signal);

    api('list_files', {parent_id: parentId, sort_by: currentSort, sort_order: currentSortOrder, page_size: 0}, 'GET', fileListAbort.signal)
        .then(data => {
            if (data.success) {
                renderFileList(data.files);
                initDragSort();
            }
        }).catch(function(err) {
            if (err.name === 'AbortError') return;
        });
}

function renderTags(tags, fileId) {
    if (!tags || tags.length === 0) {
        return `<span class="tag-placeholder" onclick="showTagDialog(${fileId}, [])"><i class="fas fa-plus"></i> 添加标签</span>`;
    }
    const tagColors = [
        'tag-blue', 'tag-green', 'tag-orange', 'tag-purple',
        'tag-pink', 'tag-cyan', 'tag-red', 'tag-teal'
    ];
    let html = '';
    tags.forEach((tag, i) => {
        const colorClass = tagColors[i % tagColors.length];
        html += `<span class="tag ${colorClass}" title="点击编辑">${escapeHtml(tag)}</span>`;
    });
    html += `<button class="tag-add-btn" onclick="showTagDialog(${fileId}, ${escapeJsonForHtml(JSON.stringify(tags))})" title="编辑标签"><i class="fas fa-pen"></i></button>`;
    return html;
}

function showTagDialog(fileId, currentTags) {
    const tagListHtml = currentTags.map(tag => `<div class="tag-edit-item"><span>${escapeHtml(tag)}</span><button class="tag-remove-btn" onclick="removeTagFromEditor(this)"><i class="fas fa-times"></i></button></div>`).join('');
    showModal('编辑标签', `
        <div class="tag-editor">
            <div class="tag-current-list" id="tagCurrentList">
                ${tagListHtml}
                ${currentTags.length === 0 ? '<p class="tag-empty-hint">暂无标签</p>' : ''}
            </div>
            <div class="tag-input-row">
                <input type="text" id="tagInput" placeholder="输入标签名，回车添加" onkeydown="handleTagInput(event, ${fileId})">
                <button class="btn btn-primary btn-sm" onclick="addTagToEditor(${fileId})">添加</button>
            </div>
            <div style="display:flex;gap:8px;margin-top:8px">
                <button class="btn btn-primary" style="flex:1" onclick="saveFileTags(${fileId})"><i class="fas fa-save"></i> 保存</button>
                <button class="btn btn-glass" style="flex:1" onclick="closeModal()">取消</button>
            </div>
        </div>
    `);
    setTimeout(() => document.getElementById('tagInput')?.focus(), 100);
}

function handleTagInput(event) {
    if (event.key === 'Enter') {
        event.preventDefault();
        addTagToEditor();
    }
}

function addTagToEditor() {
    const input = document.getElementById('tagInput');
    const tag = input.value.trim();
    if (!tag) return;

    const list = document.getElementById('tagCurrentList');
    const emptyHint = list.querySelector('.tag-empty-hint');
    if (emptyHint) emptyHint.remove();

    const item = document.createElement('div');
    item.className = 'tag-edit-item';
    item.innerHTML = `<span>${escapeHtml(tag)}</span><button class="tag-remove-btn" onclick="removeTagFromEditor(this)"><i class="fas fa-times"></i></button>`;
    list.appendChild(item);
    input.value = '';
    input.focus();
}

function removeTagFromEditor(btn) {
    const item = btn.closest('.tag-edit-item');
    item.remove();
    const list = document.getElementById('tagCurrentList');
    if (list.children.length === 0) {
        list.innerHTML = '<p class="tag-empty-hint">暂无标签</p>';
    }
}

function saveFileTags(fileId) {
    const items = document.querySelectorAll('#tagCurrentList .tag-edit-item span');
    const tags = Array.from(items).map(el => el.textContent.trim()).filter(t => t.length > 0);
    api('update_tags', { file_id: fileId, tags: tags }).then(data => {
        if (data.success) {
            closeModal();
            loadFiles(currentParentId);
            showToast('标签已更新');
        } else {
            showToast(data.message, 'error');
        }
    });
}

function getFileIcon(icon) {
    const map = {
        image: '<i class="fas fa-image"></i>',
        video: '<i class="fas fa-film"></i>',
        audio: '<i class="fas fa-music"></i>',
        pdf: '<i class="fas fa-file-pdf"></i>',
        word: '<i class="fas fa-file-word"></i>',
        excel: '<i class="fas fa-file-excel"></i>',
        ppt: '<i class="fas fa-file-powerpoint"></i>',
        text: '<i class="fas fa-file-alt"></i>',
        archive: '<i class="fas fa-file-archive"></i>',
        code: '<i class="fas fa-code"></i>',
        file: '<i class="fas fa-file"></i>'
    };
    return map[icon] || '<i class="fas fa-file"></i>';
}

function showNewFolderDialog() {
    showModal('新建文件夹', '<div class="form-group"><label>文件夹名称</label><input type="text" id="newFolderName" placeholder="请输入文件夹名称" autofocus></div><button class="btn btn-primary" onclick="createFolder()">创建</button>');
    setTimeout(() => document.getElementById('newFolderName')?.focus(), 100);
}

function createFolder() {
    const name = document.getElementById('newFolderName').value.trim();
    if (!name) { showToast('请输入文件夹名称', 'error'); return; }
    api('create_folder', {parent_id: currentParentId, folder_name: name}).then(data => {
        if (data.success) { closeModal(); loadFiles(currentParentId); showToast('文件夹创建成功'); }
        else { showToast(data.message, 'error'); }
    });
}

function downloadFile(fileId) {
    const file = currentFileList.find(f => f.id === fileId);
    if (file && !file.is_dir) {
        resumableDownload(fileId, file.filename, file.filesize);
    } else {
        window.location.href = 'index.php?action=download&file_id=' + fileId;
    }
}

const DOWNLOAD_STORE_KEY = 'pancloud_dl_';

function resumableDownload(fileId, filename, totalSize) {
    const storeKey = DOWNLOAD_STORE_KEY + fileId;
    let saved = null;
    try {
        const raw = localStorage.getItem(storeKey);
        if (raw) { saved = JSON.parse(raw); }
    } catch (e) {}

    let startByte = 0;
    if (saved && saved.totalSize === totalSize && saved.received < totalSize) {
        startByte = saved.received;
    }

    const url = 'index.php?action=download&file_id=' + fileId;
    const headers = {};
    if (startByte > 0) {
        headers['Range'] = 'bytes=' + startByte + '-';
    }

    let received = startByte;
    let chunks = [];
    let contentHash = '';
    let aborted = false;

    var lastPersist = 0;
    function persist() {
        var now = Date.now();
        if (now - lastPersist < 500) return; // 节流：最多每 500ms 写一次
        lastPersist = now;
        try {
            localStorage.setItem(storeKey, JSON.stringify({
                fileId: fileId, filename: filename, totalSize: totalSize,
                received: received, contentHash: contentHash, updated: now
            }));
        } catch (e) {}
    }

    function clearStore() {
        try { localStorage.removeItem(storeKey); } catch (e) {}
    }

    function makeBlobAndSave() {
        const blob = new Blob(chunks);
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(function() { URL.revokeObjectURL(a.href); }, 60000);
    }

    function verifyAndSave() {
        clearStore();
        if (contentHash && chunks.length > 0) {
            const reader = new FileReader();
            reader.onload = function() {
                const arrayBuffer = reader.result;
                crypto.subtle.digest('SHA-256', arrayBuffer).then(function(hashBuffer) {
                    const hashArray = Array.from(new Uint8Array(hashBuffer));
                    const hashHex = hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
                    if (hashHex !== contentHash) {
                        showToast('文件完整性校验失败，请重新下载', 'error');
                        return;
                    }
                    makeBlobAndSave();
                }).catch(function() {
                    makeBlobAndSave();
                });
            };
            reader.onerror = function() { makeBlobAndSave(); };
            reader.readAsArrayBuffer(new Blob(chunks));
        } else {
            makeBlobAndSave();
        }
    }

    function cleanup() {
        aborted = true;
    }

    fetch(url, { headers: headers, credentials: 'same-origin' }).then(function(response) {
        if (startByte > 0 && response.status === 206) {
            received = startByte;
            chunks = [];
        } else if (startByte > 0 && response.status !== 206) {
            received = 0;
            chunks = [];
        } else {
            received = 0;
            chunks = [];
        }

        const ch = response.headers.get('X-Content-SHA256');
        if (ch) { contentHash = ch; }

        const reader = response.body.getReader();

        function readChunk() {
            return reader.read().then(function(result) {
                if (result.done) {
                    if (received >= totalSize || totalSize === 0) {
                        verifyAndSave();
                    } else {
                        persist();
                        showToast('下载中断，已保存进度（' + formatSize(received) + '/' + formatSize(totalSize) + '），下次可续传', 'warning');
                    }
                    return;
                }
                if (aborted) { reader.cancel(); return; }
                chunks.push(result.value);
                received += result.value.byteLength;
                persist();
                return readChunk();
            }).catch(function() {
                persist();
                showToast('下载中断，已保存进度（' + formatSize(received) + '/' + formatSize(totalSize) + '），下次可续传', 'warning');
            });
        }

        return readChunk();
    }).catch(function(err) {
        persist();
        showToast('下载失败：' + (err.message || '网络错误'), 'error');
    });

    return { abort: cleanup };
}

function deleteFile(fileId) {
    showConfirm('确定要删除此文件吗？文件将移至回收站。', () => {
        api('delete', {file_id: fileId}).then(data => {
            if (data.success) { loadFiles(currentParentId); showToast('已移至回收站'); }
            else { showToast(data.message, 'error'); }
        });
    });
}

function batchDelete() {
    if (selectedFiles.size === 0) return;
    showConfirm(`确定要删除选中的 ${selectedFiles.size} 个文件吗？`, () => {
        api('batch_delete', {file_ids: Array.from(selectedFiles)}).then(data => {
            if (data.success) { selectedFiles.clear(); loadFiles(currentParentId); showToast('批量删除完成'); }
        });
    });
}

function renderFileList(files) {
    currentFileList = files;
    const container = document.getElementById('fileList');
    // 网格模式去掉表格容器边框背景
    if (currentView === 'grid') {
        container.classList.add('file-grid-mode');
    } else {
        container.classList.remove('file-grid-mode');
    }
    var selectAllCheck = document.getElementById('selectAllCheck');
    if (selectAllCheck) {
        selectAllCheck.style.display = files.length > 0 ? '' : 'none';
    }
    if (files.length === 0) {
        container.innerHTML = '<div class="empty-state"><div class="empty-icon"><i class="fas fa-folder-open"></i></div><h3>暂无文件</h3><p>点击上传按钮添加文件</p></div>';
        return;
    }

    if (currentView === 'list') {
        let html = '<div class="file-table-header"><div class="col-check"><input type="checkbox" onchange="toggleSelectAll(this)"></div><div class="col-name">名称</div><div class="col-size">大小</div><div class="col-time">修改时间</div><div class="col-tags">标签</div><div class="col-actions">操作</div></div>';
        files.forEach(file => {
            const hasThumbnail = file.thumbnail_url && !file.is_dir;
            const tagsHtml = renderTags(file.tags || [], file.id);
            html += `<div class="file-row ${selectedFiles.has(file.id) ? 'selected' : ''}" data-id="${file.id}" draggable="true" onclick="handleFileRowClick(${file.id}, ${file.is_dir ? 'true' : 'false'})" ondblclick="${isTouchDevice ? '' : (file.is_dir ? `navigateTo(${file.id})` : `previewFile(${file.id})`)}" oncontextmenu="showContextMenu(event, ${file.id}, ${escapeJsonForHtml(JSON.stringify(file))})">
                <div class="col-check" onclick="event.stopPropagation()"><input type="checkbox" ${selectedFiles.has(file.id) ? 'checked' : ''} onchange="toggleSelect(${file.id}, this)"></div>
                <div class="col-name"><div class="file-name-wrap"><span class="file-icon icon-${file.icon}">${hasThumbnail ? `<img src="${file.thumbnail_url}" alt="" class="file-thumbnail" onerror="this.style.display='none'">` : ''}${file.is_dir ? '<i class="fas fa-folder"></i>' : getFileIcon(file.icon)}</span><span class="file-name-text">${escapeHtml(file.filename)}</span>${file.is_favorite ? '<span class="file-fav"><i class="fas fa-star"></i></span>' : ''}${file.is_locked ? '<span class="file-fav" style="color:#ef4444"><i class="fas fa-lock"></i></span>' : ''}${file.is_encrypted ? '<span class="file-fav" style="color:#8b5cf6"><i class="fas fa-shield-alt"></i></span>' : ''}</div></div>
                <div class="col-size">${file.is_dir ? '-' : file.filesize_formatted}</div>
                <div class="col-time">${file.updated_at_formatted}</div>
                <div class="col-tags" onclick="event.stopPropagation()">${tagsHtml}</div>
                <div class="col-actions" onclick="event.stopPropagation()">
                    <button class="btn-icon" style="width:30px;height:30px;font-size:13px" onclick="event.stopPropagation();downloadFile(${file.id})" title="下载"><i class="fas fa-download"></i></button>
                    <button class="btn-icon" style="width:30px;height:30px;font-size:13px" onclick="event.stopPropagation();showShareDialog(${file.id})" title="分享"><i class="fas fa-link"></i></button>
                    <button class="btn-icon" style="width:30px;height:30px;font-size:13px" onclick="event.stopPropagation();deleteFile(${file.id})" title="删除"><i class="fas fa-trash-alt"></i></button>
                </div>
            </div>`;
        });
        container.innerHTML = html;
    } else {
        var html = '<div class="file-grid">';
        files.forEach(file => {
            const hasThumbnail = file.thumbnail_url && !file.is_dir;
            html += `<div class="grid-item ${selectedFiles.has(file.id) ? 'selected' : ''}" data-id="${file.id}" onclick="handleFileRowClick(${file.id}, ${file.is_dir ? 'true' : 'false'})" ondblclick="${isTouchDevice ? '' : (file.is_dir ? `navigateTo(${file.id})` : `previewFile(${file.id})`)}" oncontextmenu="showContextMenu(event, ${file.id}, ${escapeJsonForHtml(JSON.stringify(file))})">
                <div class="grid-check" onclick="event.stopPropagation()"><input type="checkbox" ${selectedFiles.has(file.id) ? 'checked' : ''} onchange="toggleSelect(${file.id}, this)"></div>
                <div class="grid-icon icon-${file.icon}">${hasThumbnail ? `<img src="${file.thumbnail_url}" alt="" class="grid-thumbnail" onerror="this.style.display='none'">` : ''}${file.is_dir ? '<i class="fas fa-folder"></i>' : getFileIcon(file.icon)}</div>
                <div class="grid-info">
                    <div class="grid-name" title="${escapeHtml(file.filename)}">${escapeHtml(file.filename)}</div>
                    <div class="grid-size">${file.is_dir ? '' : file.filesize_formatted}</div>
                </div>
                <div class="grid-actions" onclick="event.stopPropagation()">
                    <button class="grid-action-btn" onclick="event.stopPropagation();downloadFile(${file.id})" title="下载"><i class="fas fa-download"></i></button>
                    <button class="grid-action-btn" onclick="event.stopPropagation();showShareDialog(${file.id})" title="分享"><i class="fas fa-link"></i></button>
                    <button class="grid-action-btn" onclick="event.stopPropagation();deleteFile(${file.id})" title="删除"><i class="fas fa-trash-alt"></i></button>
                </div>
            </div>`;
        });
        html += '</div>';
        container.innerHTML = html;
        initGridRubberBand();
    }
}

function navigateTo(parentId) {
    loadFiles(parentId);
}

function loadBreadcrumb(parentId, abortSignal) {
    if (parentId === 0) {
        document.getElementById('breadcrumb').innerHTML = '<span class="breadcrumb-item" onclick="navigateTo(0)">全部文件</span>';
        return;
    }
    api('breadcrumb', {parent_id: parentId}, 'GET', abortSignal).then(data => {
        if (data && data.success) {
            let html = '<span class="breadcrumb-item" onclick="navigateTo(0)">全部文件</span>';
            data.breadcrumb.forEach(item => {
                html += '<span class="breadcrumb-sep"><i class="fas fa-chevron-right"></i></span>';
                html += `<span class="breadcrumb-item" onclick="navigateTo(${item.id})">${escapeHtml(item.filename)}</span>`;
            });
            document.getElementById('breadcrumb').innerHTML = html;
        }
    }).catch(function(err) {
        if (err && err.name === 'AbortError') return;
    });
}

let folderTreeData = [];
let fileOpTargetId = 0;
let fileOpTargetName = '根目录';

// ===== 长按检测 =====
let longPressTimer = null;
let longPressDelay = 500; // 500ms 触发长按
let isLongPressing = false;

function initLongPressForContextMenu() {
    document.addEventListener('touchstart', function(e) {
        const fileRow = e.target.closest('.file-row, .grid-item');
        if (!fileRow) return;
        
        const fileId = fileRow.dataset.id;
        const fileData = currentFileList.find(f => f.id == fileId);
        if (!fileData) return;
        
        isLongPressing = false;
        longPressTimer = setTimeout(function() {
            isLongPressing = true;
            e.preventDefault();
            showContextMenu({
                preventDefault: function() {},
                clientX: e.touches[0].clientX,
                clientY: e.touches[0].clientY
            }, parseInt(fileId), fileData);
        }, longPressDelay);
    }, { passive: false });
    
    document.addEventListener('touchend', function(e) {
        if (longPressTimer) {
            clearTimeout(longPressTimer);
            longPressTimer = null;
        }
        isLongPressing = false;
    });
    
    document.addEventListener('touchmove', function(e) {
        if (longPressTimer && isLongPressing === false) {
            clearTimeout(longPressTimer);
            longPressTimer = null;
        }
    });
}

function showMoveDialog() { showFileOpDialog('move'); }
function showCopyDialog() { showFileOpDialog('copy'); }

function showFileOpDialog(mode) {
    const targetIds = contextFileId ? [contextFileId] : Array.from(selectedFiles);
    if (targetIds.length === 0) return;

    const isMove = mode === 'move';
    const title = isMove ? '移动到' : '复制到';
    const icon = isMove ? 'fa-arrows-alt' : 'fa-copy';
    const opClass = isMove ? 'move' : 'copy';
    const actionLabel = isMove ? '移动' : '复制';

    fileOpTargetId = 0;
    fileOpTargetName = '根目录';

    showModal(title, `
        <div class="file-op-summary">
            <div class="op-icon ${opClass}"><i class="fas ${icon}"></i></div>
            <span>${isMove ? '将移动' : '将复制'}</span>
            <span class="op-count">${targetIds.length}</span>
            <span>个文件</span>
        </div>
        <div class="folder-tree-search">
            <i class="fas fa-search"></i>
            <input type="text" id="folderTreeSearchInput" placeholder="搜索文件夹..." oninput="filterFolderTree(this.value)">
        </div>
        <div class="folder-tree-path" id="folderTreePath">
            <span>根目录</span>
        </div>
        <div class="folder-tree-box" id="folderTreeBox">
            <div class="loading" style="padding:20px;text-align:center"><i class="fas fa-spinner fa-spin"></i> 加载中...</div>
        </div>
        <input type="hidden" id="fileOpTargetId" value="0">
        <div class="file-op-footer">
            <button class="btn btn-cancel" onclick="closeModal()"><i class="fas fa-times"></i> 取消</button>
            <button class="btn btn-primary" id="fileOpExecuteBtn" onclick="executeFileOp('${mode}')">
                <i class="fas ${icon}"></i> ${actionLabel}
            </button>
        </div>
    `);
    loadFolderTree();
}

function loadFolderTree(excludeIds) {
    excludeIds = excludeIds || [];
    const targetIds = contextFileId ? [contextFileId] : Array.from(selectedFiles);
    const allExclude = new Set(excludeIds.concat(targetIds));

    api('list_all_folders', {}, 'GET').then(data => {
        if (data.success) {
            folderTreeData = data.folders;
            document.getElementById('folderTreeBox').innerHTML = buildRootNodeHTML() + buildFolderTreeHTML(folderTreeData, allExclude);
        } else {
            document.getElementById('folderTreeBox').innerHTML = '<div class="folder-tree-empty">加载失败</div>';
        }
    }).catch(function() {
        document.getElementById('folderTreeBox').innerHTML = '<div class="folder-tree-empty">加载失败</div>';
    });
}

function buildRootNodeHTML() {
    return '<div class="folder-tree-node"><div class="node-row selected" onclick="selectFolderTarget(0, this)"><i class="fas fa-chevron-right node-toggle leaf"></i><i class="fas fa-folder node-icon"></i><span class="node-name">根目录</span></div></div>';
}

function buildFolderTreeHTML(folders, excludeIds, depth) {
    depth = depth || 0;
    var html = '';
    folders.forEach(function(f) {
        if (excludeIds.has(f.id)) return;
        var hasChildren = f.children && f.children.length > 0;
        html += '<div class="folder-tree-node" data-depth="' + depth + '" data-folder-id="' + f.id + '" data-folder-name="' + escapeHtml(f.filename) + '">';
        html += '<div class="node-row" style="padding-left:' + (10 + (depth + 1) * 20) + 'px" onclick="selectFolderTarget(' + f.id + ', this)">';
        html += '<i class="fas fa-chevron-right node-toggle ' + (hasChildren ? '' : 'leaf') + '" onclick="toggleFolderExpand(this.parentElement.parentElement, event)"></i>';
        html += '<i class="fas fa-folder node-icon"></i>';
        html += '<span class="node-name">' + escapeHtml(f.filename) + '</span>';
        html += '</div>';
        if (hasChildren) {
            html += '<div class="folder-tree-children">' + buildFolderTreeHTML(f.children, excludeIds, depth + 1) + '</div>';
        }
        html += '</div>';
    });
    return html;
}

function toggleFolderExpand(nodeEl, event) {
    if (event) {
        event.stopPropagation();
    }
    nodeEl.classList.toggle('expanded');
}

function selectFolderTarget(id, el) {
    document.getElementById('fileOpTargetId').value = id;
    fileOpTargetId = id;

    document.querySelectorAll('#folderTreeBox .node-row.selected').forEach(function(e) { e.classList.remove('selected'); });
    el.classList.add('selected');

    if (id === 0) {
        fileOpTargetName = '根目录';
        document.getElementById('folderTreePath').innerHTML = '<span>根目录</span>';
    } else {
        var pathParts = getFolderPathById(folderTreeData, id);
        fileOpTargetName = pathParts[pathParts.length - 1] || '';
        var pathHtml = pathParts.map(function(name, i) {
            return (i > 0 ? '<i class="fas fa-chevron-right path-sep"></i>' : '') + (i === pathParts.length - 1 ? '<span>' + escapeHtml(name) + '</span>' : escapeHtml(name));
        }).join('');
        document.getElementById('folderTreePath').innerHTML = '<span>根目录</span> <i class="fas fa-chevron-right path-sep"></i> ' + pathHtml;
    }
}

function getFolderPathById(folders, id) {
    function find(folders, targetId, path) {
        for (var i = 0; i < folders.length; i++) {
            var f = folders[i];
            if (f.id === targetId) {
                path.push(f.filename);
                return true;
            }
            if (f.children && f.children.length > 0) {
                path.push(f.filename);
                if (find(f.children, targetId, path)) return true;
                path.pop();
            }
        }
        return false;
    }
    var path = [];
    find(folders, id, path);
    return path;
}

function filterFolderTree(query) {
    var nodes = document.querySelectorAll('#folderTreeBox .folder-tree-node');
    var normalizedQuery = query.toLowerCase().trim();

    nodes.forEach(function(node) {
        var name = (node.dataset.folderName || '').toLowerCase();
        if (normalizedQuery === '') {
            node.style.display = '';
        } else if (name.indexOf(normalizedQuery) !== -1) {
            node.style.display = '';
            var parent = node.parentElement;
            while (parent) {
                if (parent.classList && parent.classList.contains('folder-tree-node')) {
                    parent.classList.add('expanded');
                    parent.style.display = '';
                }
                if (parent.classList && parent.classList.contains('folder-tree-children')) {
                    parent.style.display = 'block';
                }
                parent = parent.parentElement;
            }
        } else {
            node.style.display = 'none';
        }
    });
}

function executeFileOp(mode) {
    var targetId = document.getElementById('fileOpTargetId').value;
    var fileIds = contextFileId ? [contextFileId] : Array.from(selectedFiles);

    if (targetId === undefined || targetId === null || targetId === '') {
        showToast('请选择目标文件夹', 'warning');
        return;
    }

    var targetIdNum = parseInt(targetId);
    var action = mode === 'move' ? 'batch_move' : 'batch_copy';

    var btn = document.getElementById('fileOpExecuteBtn');
    var originalHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 处理中...';
    btn.disabled = true;

    api(action, { file_ids: JSON.stringify(fileIds), target_parent_id: targetIdNum }).then(function(data) {
        btn.innerHTML = originalHtml;
        btn.disabled = false;
        if (data.success) {
            closeModal();
            selectedFiles.clear();
            contextFileId = null;
            updateBatchButtons();
            loadFiles(currentParentId);
            showToast(data.message);
        } else {
            showToast(data.message, 'error');
        }
    }).catch(function() {
        btn.innerHTML = originalHtml;
        btn.disabled = false;
        showToast('操作失败', 'error');
    });
}

function syncMasterCheckboxes() {
    var total = document.querySelectorAll('.file-row input[type="checkbox"], .grid-item input[type="checkbox"]').length;
    var checked = selectedFiles.size;
    var allChecked = total > 0 && checked === total;
    var noneChecked = checked === 0;
    document.querySelectorAll('.select-all-check input, .file-table-header .col-check input[type="checkbox"]').forEach(function (cb) {
        cb.checked = allChecked;
        cb.indeterminate = !allChecked && !noneChecked;
    });
}

function toggleSelect(fileId, checkbox) {
    if (checkbox.checked) selectedFiles.add(fileId);
    else selectedFiles.delete(fileId);
    updateBatchButtons();
    syncMasterCheckboxes();
    var row = checkbox.closest('.file-row, .grid-item');
    if (row) row.classList.toggle('selected', checkbox.checked);
}

function toggleSelectAll(checkbox) {
    var boxes = document.querySelectorAll('.file-row input[type="checkbox"], .grid-item input[type="checkbox"]');
    if (checkbox.checked) {
        boxes.forEach(function (b) { b.checked = true; selectedFiles.add(parseInt(b.closest('.file-row, .grid-item').dataset.id)); });
    } else {
        boxes.forEach(function (b) { b.checked = false; });
        selectedFiles.clear();
    }
    updateBatchButtons();
    syncMasterCheckboxes();
    document.querySelectorAll('.file-row, .grid-item').forEach(function (row) {
        row.classList.toggle('selected', checkbox.checked);
    });
}

function updateBatchButtons() {
    const show = selectedFiles.size > 0;
    document.getElementById('batchDeleteBtn').style.display = show ? '' : 'none';
    document.getElementById('batchRenameBtn').style.display = show ? '' : 'none';
    document.getElementById('batchMoveBtn').style.display = show ? '' : 'none';
    document.getElementById('batchCopyBtn').style.display = show ? '' : 'none';
    const sep = document.querySelector('.toolbar-sep');
    if (sep) sep.style.display = show ? '' : 'none';
}

function showContextMenu(event, fileId, fileData) {
    event.preventDefault();
    event.stopPropagation();
    contextFileId = fileId;
    contextFileData = fileData;
    const menu = document.getElementById('contextMenu');
    menu.style.display = 'block';

    let x = event.clientX;
    let y = event.clientY;
    const menuWidth = menu.offsetWidth || 180;
    const menuHeight = menu.offsetHeight || 280;

    if (x + menuWidth > window.innerWidth) {
        x = window.innerWidth - menuWidth - 8;
    }
    if (y + menuHeight > window.innerHeight) {
        y = window.innerHeight - menuHeight - 8;
    }
    if (x < 8) x = 8;
    if (y < 8) y = 8;

    menu.style.left = x + 'px';
    menu.style.top = y + 'px';
}

function contextAction(action) {
    hideContextMenu();
    const fileId = contextFileId;
    const file = contextFileData;

    switch(action) {
        case 'download': downloadFile(fileId); break;
        case 'preview': previewFile(fileId); break;
        case 'share': showShareDialog(fileId); break;
        case 'favorite':
            api('toggle_favorite', {file_id: fileId}).then(data => {
                if (data.success) { loadFiles(currentParentId); showToast(data.message); }
            }); break;
        case 'lock':
            api('toggle_lock', {file_id: fileId}).then(data => {
                if (data.success) { loadFiles(currentParentId); showToast(data.is_locked ? '已锁定' : '已解锁'); }
                else { showToast(data.message, 'error'); }
            }); break;
        case 'encrypt':
            if (file.is_dir) { showToast('文件夹不支持加密', 'error'); break; }
            api('toggle_encryption', {file_id: fileId}).then(data => {
                if (data.success) { loadFiles(currentParentId); showToast(data.message); }
                else { showToast(data.message, 'error'); }
            }); break;
        case 'tags':
            showTagDialog(fileId, file.tags || []);
            break;
        case 'rename':
            showModal('重命名', `<div class="form-group"><input type="text" id="renameInput" value="${escapeHtml(file.filename)}"></div><button class="btn btn-primary" onclick="renameFile(${fileId})">确定</button>`);
            setTimeout(() => { const inp = document.getElementById('renameInput'); inp?.focus(); inp?.select(); }, 100);
            break;
        case 'move': showFileOpDialog('move'); break;
        case 'copy': showFileOpDialog('copy'); break;
        case 'info':
            api('file_info', {file_id: fileId}, 'GET').then(data => {
                if (data.success) {
                    const f = data.file;
                    showModal('文件详情', `
                        <div class="detail-row"><span>文件名</span><span>${escapeHtml(f.filename)}</span></div>
                        <div class="detail-row"><span>大小</span><span>${f.filesize_formatted}</span></div>
                        <div class="detail-row"><span>类型</span><span>${f.file_type || '文件夹'}</span></div>
                        <div class="detail-row"><span>创建时间</span><span>${f.created_at_formatted}</span></div>
                        <div class="detail-row"><span>修改时间</span><span>${f.updated_at_formatted}</span></div>
                        <div class="detail-row"><span>路径</span><span>${escapeHtml(f.filepath)}</span></div>
                    `);
                }
            }); break;
        case 'delete': deleteFile(fileId); break;
    }
}

function renameFile(fileId) {
    const newName = document.getElementById('renameInput').value.trim();
    if (!newName) { showToast('文件名不能为空', 'error'); return; }
    api('rename', {file_id: fileId, new_name: newName}).then(data => {
        if (data.success) { closeModal(); loadFiles(currentParentId); showToast('重命名成功'); }
        else { showToast(data.message, 'error'); }
    });
}

function hideContextMenu() {
    document.getElementById('contextMenu').style.display = 'none';
}

document.addEventListener('click', hideContextMenu);

function changeSort(sort) {
    currentSort = sort;
    if (sort === 'custom') currentSortOrder = 'asc';
    loadFiles(currentParentId);
}

function switchView(view) {
    if (view === currentView) return;
    currentView = view;
    try { localStorage.setItem('pancloud_view', view); } catch (e) {}
    document.querySelectorAll('#viewToggle .view-toggle-btn').forEach(function (b) {
        b.classList.toggle('active', b.dataset.view === view);
    });
    // 只重新渲染，不发请求
    renderFileList(currentFileList);
}

function initViewFromStorage() {
    try {
        var saved = localStorage.getItem('pancloud_view');
        if (saved === 'grid' || saved === 'list') {
            currentView = saved;
        }
    } catch (e) {}
    // 仅同步按钮状态，不触发重新渲染
    document.querySelectorAll('#viewToggle .view-toggle-btn').forEach(function (b) {
        b.classList.toggle('active', b.dataset.view === currentView);
    });
}

function handleSearch(event) {
    if (event.key === 'Enter') performSearch();
}

function performSearch() {
    const keyword = document.getElementById('searchInput').value.trim();
    if (!keyword) { loadFiles(currentParentId); return; }
    api('search', {keyword: keyword}, 'GET').then(data => {
        if (data.success) renderFileList(data.files);
    });
}

function switchPage(page, el) {
    document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
    document.getElementById('page' + page.charAt(0).toUpperCase() + page.slice(1)).classList.add('active');
    if (el) el.classList.add('active');

    const breadcrumb = document.getElementById('breadcrumb');
    const pageTitleMap = {
        'files': '全部文件',
        'favorites': '我的收藏',
        'recent': '最近访问',
        'shares': '我的分享',
        'trash': '回收站',
        'logs': '操作日志',
        'ai': 'AI 助手',
        'settings': '系统设置'
    };

    if (page === 'files') {
        breadcrumb.style.cursor = 'pointer';
    } else {
        breadcrumb.innerHTML = `<span class="breadcrumb-page-title">${pageTitleMap[page] || '我的文件'}</span>`;
        breadcrumb.style.cursor = 'default';
    }

    switch(page) {
        case 'files':
            if (!isFirstLoad) {
                loadFiles(currentParentId);
            }
            isFirstLoad = false;
            break;
        case 'favorites':
            loadFavorites();
            break;
        case 'recent':
            loadRecent();
            break;
        case 'shares':
            loadShares();
            break;
        case 'trash':
            loadTrash();
            break;
        case 'logs':
            loadOperationLogs();
            break;
        case 'ai':
            if (!currentChatId) {
                createNewChat();
            } else {
                renderChatHistoryList();
            }
            break;
        case 'settings':
            loadSettings();
            break;
    }
}

// ===== 网格框选 =====
var _rubberBand = { active: false, drag: false };

function initGridRubberBand() {
    if (window._gridRbInit) return;
    window._gridRbInit = true;

    function getGrid() { return document.querySelector('.file-grid'); }

    var sx = 0, sy = 0;
    var _rubberBandCtrl = false;
    var scrollInterval = null;
    var scrollContainer = null;
    var band = null;

    function ensureBand() {
        var g = getGrid();
        if (!g) return null;
        band = g.querySelector('.rubber-band');
        if (!band) {
            band = document.createElement('div');
            band.className = 'rubber-band';
            g.appendChild(band);
        }
        return g;
    }

    // 找到可滚动的父容器
    function findScrollContainer(el) {
        while (el && el !== document.body) {
            var style = window.getComputedStyle(el);
            if (style.overflowY === 'auto' || style.overflowY === 'scroll') return el;
            el = el.parentElement;
        }
        return document.querySelector('.content-area') || null;
    }

    function startAutoScroll() {
        if (!scrollContainer) scrollContainer = findScrollContainer(getGrid());
        if (scrollInterval) return;
        scrollInterval = setInterval(function() {
            if (!_rubberBand.active) { stopAutoScroll(); return; }
            var my = window._lastMouseY;
            if (my === undefined) return;
            var rect = scrollContainer.getBoundingClientRect();
            var edgeSize = 40;
            var topDist = my - rect.top;
            var botDist = rect.bottom - my;
            var speed = 0;
            if (topDist < edgeSize) {
                speed = -Math.max(30, (edgeSize - topDist) * 1.5);
            } else if (botDist < edgeSize) {
                speed = Math.max(30, (edgeSize - botDist) * 1.5);
            } else {
                stopAutoScroll();
                return;
            }
            scrollContainer.scrollTop += speed;
            // 鼠标不动时也更新框选
            var gr = getGrid().getBoundingClientRect();
            var mx = window._lastMouseX;
            if (mx !== undefined && my !== undefined) {
                var ncx = mx - gr.left, ncy = my - gr.top;
                var nl = Math.min(sx, ncx), nt = Math.min(sy, ncy);
                var nw = Math.abs(ncx - sx), nh = Math.abs(ncy - sy);
                updateBox({ l: nl, t: nt, r: nl + nw, b: nt + nh }, gr);
            }
        }, 20);
    }

    function stopAutoScroll() {
        if (scrollInterval) {
            clearInterval(scrollInterval);
            scrollInterval = null;
        }
    }

    document.addEventListener("mousedown", function (e) {
        if (e.button !== 0) return;
        if (!e.target.closest('.file-grid')) return;
        if (e.target.closest('.grid-check') || e.target.closest('.grid-action-btn')) return;
        var g = ensureBand();
        if (!g) return;
        var r = g.getBoundingClientRect();
        sx = e.clientX - r.left;
        sy = e.clientY - r.top;
        _rubberBand.active = true;
        _rubberBand.drag = false;
        _rubberBandCtrl = e.ctrlKey || e.metaKey;
        band.style.display = 'none';
        scrollContainer = null;

        // 非 Ctrl 框选：点下时清空已有选中
        if (!_rubberBandCtrl) {
            selectedFiles.clear();
            document.querySelectorAll('.grid-item').forEach(function(item) {
                item.classList.remove('selected');
                var c = item.querySelector('input[type="checkbox"]');
                if (c) c.checked = false;
            });
            // 如果点在文件上，把它加入选中（拖拽时以此为基础）
            var startItem = e.target.closest('.grid-item');
            if (startItem) {
                var sid = parseInt(startItem.dataset.id);
                selectedFiles.add(sid);
                startItem.classList.add('selected');
                var sc = startItem.querySelector('input[type="checkbox"]');
                if (sc) sc.checked = true;
            }
            updateBatchButtons();
            syncMasterCheckboxes();
        }

        e.preventDefault();
    });

    function updateBox(rect, gridRect) {
        var sr = { left: rect.l + gridRect.left, top: rect.t + gridRect.top, right: rect.r + gridRect.left, bottom: rect.b + gridRect.top };
        document.querySelectorAll('.grid-item').forEach(function (item) {
            var ir = item.getBoundingClientRect();
            var hit = !(ir.right < sr.left || ir.left > sr.right || ir.bottom < sr.top || ir.top > sr.bottom);
            var cb = item.querySelector('input[type="checkbox"]');
            if (cb) {
                cb.checked = hit;
                item.classList.toggle('selected', hit);
                if (hit) selectedFiles.add(parseInt(item.dataset.id));
                else selectedFiles.delete(parseInt(item.dataset.id));
            }
        });
        if (!_rubberBandCtrl) {
            document.querySelectorAll('.grid-item').forEach(function (item) {
                var ir = item.getBoundingClientRect();
                var inside = !(ir.right < sr.left || ir.left > sr.right || ir.bottom < sr.top || ir.top > sr.bottom);
                if (!inside) {
                    var cb = item.querySelector('input[type="checkbox"]');
                    if (cb && cb.checked) {
                        cb.checked = false;
                        item.classList.remove('selected');
                        selectedFiles.delete(parseInt(item.dataset.id));
                    }
                }
            });
        }
        updateBatchButtons();
        syncMasterCheckboxes();
    }

    function _onMouseMove(e) {
        if (!_rubberBand.active) return;
        if (!band) { endRubber(); return; }
        
        window._lastMouseX = e.clientX;
        window._lastMouseY = e.clientY;
        
        var g = getGrid();
        if (!g) return;
        var r = g.getBoundingClientRect();
        var cx = e.clientX - r.left;
        var cy = e.clientY - r.top;
        var dx = cx - sx, dy = cy - sy;

        // 自动滚动画布
        if (scrollContainer) {
            var scr = scrollContainer.getBoundingClientRect();
            var edgeSize = 40;
            if (e.clientY < scr.top + edgeSize || e.clientY > scr.bottom - edgeSize) {
                startAutoScroll();
            } else {
                stopAutoScroll();
            }
        } else {
            scrollContainer = findScrollContainer(getGrid());
        }

        if (band.style.display === 'none' && Math.abs(dx) <= 5 && Math.abs(dy) <= 5) return;

        if (band.style.display === 'none') {
            band.style.display = 'block';
            band.style.left = sx + 'px';
            band.style.top = sy + 'px';
            band.style.width = '0px';
            band.style.height = '0px';
            _rubberBand.drag = true;
        }

        var l = Math.min(sx, cx), t = Math.min(sy, cy);
        var w = Math.abs(dx), h = Math.abs(dy);
        band.style.left = l + 'px';
        band.style.top = t + 'px';
        band.style.width = w + 'px';
        band.style.height = h + 'px';

        updateBox({ l: l, t: t, r: l + w, b: t + h }, r);
        e.preventDefault();
    }

    function _onMouseUp(e) {
        if (_rubberBand.active) endRubber();
    }

    // 拖拽期间全局监听，鼠标移出 grid 也能继续工作
    document.addEventListener('mousemove', _onMouseMove);
    document.addEventListener('mouseup', _onMouseUp);
    
    // ===== 触摸支持 =====
    var touchStartX = 0, touchStartY = 0;
    
    document.addEventListener('touchstart', function(e) {
        if (e.touches.length !== 1) return;
        var touch = e.touches[0];
        if (!e.target.closest('.file-grid')) return;
        if (e.target.closest('.grid-check') || e.target.closest('.grid-action-btn')) return;
        
        var g = ensureBand();
        if (!g) return;
        var r = g.getBoundingClientRect();
        touchStartX = touch.clientX - r.left;
        touchStartY = touch.clientY - r.top;
        sx = touchStartX;
        sy = touchStartY;
        _rubberBand.active = true;
        _rubberBand.drag = false;
        _rubberBandCtrl = false; // 触摸框选默认不带修饰键
        band.style.display = 'none';
        scrollContainer = null;
        
        // 触摸框选：清空已有选中
        selectedFiles.clear();
        document.querySelectorAll('.grid-item').forEach(function(item) {
            item.classList.remove('selected');
            var c = item.querySelector('input[type="checkbox"]');
            if (c) c.checked = false;
        });
        // 如果点在文件上，把它加入选中
        var startItem = e.target.closest('.grid-item');
        if (startItem) {
            var sid = parseInt(startItem.dataset.id);
            selectedFiles.add(sid);
            startItem.classList.add('selected');
            var sc = startItem.querySelector('input[type="checkbox"]');
            if (sc) sc.checked = true;
        }
        updateBatchButtons();
        syncMasterCheckboxes();
        
        e.preventDefault();
    }, { passive: false });
    
    document.addEventListener('touchmove', function(e) {
        if (!_rubberBand.active || e.touches.length !== 1) return;
        var touch = e.touches[0];
        
        if (!band) { endRubber(); return; }
        
        window._lastMouseX = touch.clientX;
        window._lastMouseY = touch.clientY;
        
        var g = getGrid();
        if (!g) return;
        var r = g.getBoundingClientRect();
        var cx = touch.clientX - r.left;
        var cy = touch.clientY - r.top;
        var dx = cx - sx, dy = cy - sy;
        
        // 自动滚动画布
        if (scrollContainer) {
            var scr = scrollContainer.getBoundingClientRect();
            var edgeSize = 40;
            if (touch.clientY < scr.top + edgeSize || touch.clientY > scr.bottom - edgeSize) {
                startAutoScroll();
            } else {
                stopAutoScroll();
            }
        } else {
            scrollContainer = findScrollContainer(getGrid());
        }
        
        if (band.style.display === 'none' && Math.abs(dx) <= 5 && Math.abs(dy) <= 5) return;
        
        if (band.style.display === 'none') {
            band.style.display = 'block';
            band.style.left = sx + 'px';
            band.style.top = sy + 'px';
            band.style.width = '0px';
            band.style.height = '0px';
            _rubberBand.drag = true;
        }
        
        var l = Math.min(sx, cx), t = Math.min(sy, cy);
        var w = Math.abs(dx), h = Math.abs(dy);
        band.style.left = l + 'px';
        band.style.top = t + 'px';
        band.style.width = w + 'px';
        band.style.height = h + 'px';
        
        updateBox({ l: l, t: t, r: l + w, b: t + h }, r);
        e.preventDefault();
    }, { passive: false });
    
    document.addEventListener('touchend', function(e) {
        if (_rubberBand.active) {
            endRubber();
        }
    }, { passive: false });

    function endRubber() {
        _rubberBand.active = false;
        band.style.display = 'none';
        stopAutoScroll();
    }

    // 鼠标拖出浏览器窗口后释放的兜底
    window.addEventListener('blur', function _winBlur() {
        if (_rubberBand.active) endRubber();
    });

    // 框选拖动过则阻止后续 click 事件
    document.addEventListener('click', function (e) {
        if (_rubberBand.drag) {
            e.stopPropagation();
            _rubberBand.drag = false;
            return;
        }

        var g = getGrid();
        if (!g) return;
        if (!e.target.closest('.file-grid')) return;

        // 点空白区域 → 取消全选（Ctrl 按下时不取消，留待框选补充）
        if ((e.target === g || e.target.classList.contains('file-grid')) && !e.ctrlKey && !e.metaKey) {
            selectedFiles.clear();
            document.querySelectorAll('.grid-item').forEach(function(item) {
                item.classList.remove('selected');
                var cb = item.querySelector('input[type="checkbox"]');
                if (cb) cb.checked = false;
            });
            updateBatchButtons();
            syncMasterCheckboxes();
            return;
        }

        // 修饰键 + 点击：选中操作，不触发预览/导航
        var item = e.target.closest('.grid-item');
        if (item && (e.shiftKey || e.ctrlKey || e.metaKey) && !e.target.closest('.grid-check') && !e.target.closest('.grid-action-btn')) {
            var id = parseInt(item.dataset.id);
            if (e.shiftKey) {
                // Shift + 点击：范围选择
                e.stopPropagation();
                var items = document.querySelectorAll('.grid-item');
                var lastId = window._gridLastClicked || id;
                var selecting = false, done = false;
                selectedFiles.clear();
                items.forEach(function(g) {
                    if (done) return;
                    var gid = parseInt(g.dataset.id);
                    if (gid === lastId || gid === id) {
                        selecting = !selecting;
                        if (!selecting) done = true;
                    }
                    g.classList.toggle('selected', selecting || gid === id || gid === lastId);
                    var c = g.querySelector('input[type="checkbox"]');
                    if (c) c.checked = selecting || gid === id || gid === lastId;
                    if (selecting || gid === id || gid === lastId) selectedFiles.add(gid);
                });
            } else if (e.ctrlKey || e.metaKey) {
                // Ctrl + 点击：切换当前项，不取消其他
                e.stopPropagation();
                var cb = item.querySelector('input[type="checkbox"]');
                if (cb) {
                    cb.checked = !cb.checked;
                    item.classList.toggle('selected', cb.checked);
                    if (cb.checked) selectedFiles.add(id);
                    else selectedFiles.delete(id);
                }
            }
            window._gridLastClicked = id;
            updateBatchButtons();
            syncMasterCheckboxes();
        }
    }, true);
}
