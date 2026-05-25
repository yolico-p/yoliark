<script>
// 防止浏览器自动填充
(function preventAutofill() {
    setTimeout(function() {
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.value = '';
            searchInput.setAttribute('autocomplete', 'off');
        }
        const fakeInput = document.getElementById('fakeInput');
        if (fakeInput) fakeInput.value = '';
        const fakePassword = document.getElementById('fakePassword');
        if (fakePassword) fakePassword.value = '';
    }, 100);
    
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            const searchInput = document.getElementById('searchInput');
            if (searchInput) searchInput.value = '';
        }
    });
})();

function showBatchRenameDialog() {
    if (selectedFiles.size === 0) { showToast('请先选择文件', 'error'); return; }
    
    const isDark = document.documentElement.classList.contains('dark-mode') || document.body.classList.contains('dark-mode') || document.querySelector('.dark-mode');
    const textColor = isDark ? '#e2e8f0' : '#1e293b';
    const textColorMuted = isDark ? '#94a3b8' : '#64748b';
    const borderColor = isDark ? '#475569' : '#e2e8f0';
    const inputBg = isDark ? '#1e293b' : '#ffffff';
    const accentColor = isDark ? '#60a5fa' : '#2563eb';
    const successColor = isDark ? '#22c55e' : '#16a34a';
    
    const selectedFilesList = Array.from(selectedFiles);
    const fileIdToFile = {};
    if (typeof currentFileList !== 'undefined') {
        currentFileList.forEach(f => { fileIdToFile[f.id] = f; });
    }
    
    let previewText = '<div style="margin-bottom:8px;font-weight:500;color:' + accentColor + '"><i class="fas fa-eye"></i> 预览（前 5 个）：</div>';
    let idx = 0;
    for (const fileId of selectedFilesList) {
        if (!fileIdToFile[fileId]) continue;
        const f = fileIdToFile[fileId];
        if (idx >= 5) break;
        idx++;
        previewText += `<div style="margin:4px 0;font-size:13px"><span style="color:${textColorMuted};text-decoration:line-through">${escapeHtml(f.filename || '未知文件')}</span> <span style="color:${accentColor}">→</span> <span style="color:${successColor}">待重命名</span></div>`;
    }
    if (selectedFiles.size > 5) {
        previewText += `<div style="margin-top:8px;color:${textColorMuted};font-size:12px"><i class="fas fa-ellipsis-h"></i> 还有 ${selectedFiles.size - 5} 个文件</div>`;
    }
    
    showModal('批量重命名', `
        <div style="margin-bottom:16px;padding:12px;background:${inputBg};border:1px solid ${borderColor};border-radius:8px">
            <div style="display:flex;align-items:center;gap:8px;color:${textColor};font-size:14px">
                <i class="fas fa-check-circle" style="color:${accentColor}"></i>
                <span>已选择 <strong style="color:${accentColor};font-size:16px">${selectedFiles.size}</strong> 个文件</span>
            </div>
        </div>
        
        <div class="form-group" style="margin-bottom:16px">
            <label class="batch-dialog-label" style="color:${textColor};font-size:13px;margin-bottom:8px;display:block;font-weight:500">
                <i class="fas fa-palette"></i> 重命名方式
            </label>
            <select id="renameMode" class="form-control batch-dialog-select" style="width:100%;padding:10px 12px;border:1px solid ${borderColor};border-radius:8px;background:${inputBg};color:${textColor};font-size:14px" onchange="updateRenamePreview()">
                <option value="prefix"><i class="fas fa-arrow-left"></i> 添加前缀</option>
                <option value="suffix"><i class="fas fa-arrow-right"></i> 添加后缀</option>
                <option value="prefix_suffix"><i class="fas fa-arrows-alt-h"></i> 前缀 + 后缀</option>
                <option value="number"><i class="fas fa-list-ol"></i> 序号命名</option>
                <option value="replace"><i class="fas fa-exchange-alt"></i> 查找替换</option>
            </select>
        </div>
        
        <div id="renameFields" class="form-group" style="margin-bottom:16px"></div>
        
        <div id="renamePreview" class="batch-dialog-preview" style="margin-bottom:16px;padding:12px;background:${inputBg};border:1px solid ${borderColor};border-radius:8px;max-height:200px;overflow-y:auto">
            ${previewText}
        </div>
        
        <div style="display:flex;gap:10px;justify-content:flex-end">
            <button class="btn" onclick="closeModal()"><i class="fas fa-times"></i> 取消</button>
            <button class="btn btn-primary" onclick="executeBatchRename()"><i class="fas fa-font"></i> 执行重命名</button>
        </div>
    `);
    updateRenamePreview();
}

function updateRenamePreview() {
    const mode = document.getElementById('renameMode').value;
    const fields = document.getElementById('renameFields');
    const preview = document.getElementById('renamePreview');
    if (!preview) return;
    
    const isDark = document.documentElement.classList.contains('dark-mode') || document.body.classList.contains('dark-mode') || document.querySelector('.dark-mode');
    const textColor = isDark ? '#e2e8f0' : '#1e293b';
    const textColorMuted = isDark ? '#94a3b8' : '#64748b';
    const borderColor = isDark ? '#475569' : '#e2e8f0';
    const inputBg = isDark ? '#1e293b' : '#ffffff';
    const accentColor = isDark ? '#60a5fa' : '#2563eb';
    const successColor = isDark ? '#22c55e' : '#16a34a';
    
    if (fields.children.length === 0) {
        let fieldsHtml = '';
        if (mode === 'prefix') {
            fieldsHtml = `<input type="text" id="renamePrefix" class="batch-dialog-input" placeholder="输入前缀，例如：IMG_" style="width:100%;padding:10px 12px;border:1px solid ${borderColor};border-radius:8px;background:${inputBg};color:${textColor};font-size:14px" oninput="updateRenamePreview()">`;
        } else if (mode === 'suffix') {
            fieldsHtml = `<input type="text" id="renameSuffix" class="batch-dialog-input" placeholder="输入后缀，例如：_backup" style="width:100%;padding:10px 12px;border:1px solid ${borderColor};border-radius:8px;background:${inputBg};color:${textColor};font-size:14px" oninput="updateRenamePreview()">`;
        } else if (mode === 'prefix_suffix') {
            fieldsHtml = `<div style="display:flex;gap:10px"><input type="text" id="renamePrefix" class="batch-dialog-input" placeholder="前缀" style="flex:1;padding:10px 12px;border:1px solid ${borderColor};border-radius:8px;background:${inputBg};color:${textColor};font-size:14px" oninput="updateRenamePreview()"><input type="text" id="renameSuffix" class="batch-dialog-input" placeholder="后缀" style="flex:1;padding:10px 12px;border:1px solid ${borderColor};border-radius:8px;background:${inputBg};color:${textColor};font-size:14px" oninput="updateRenamePreview()"></div>`;
        } else if (mode === 'number') {
            fieldsHtml = `<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap"><div style="display:flex;align-items:center;gap:6px"><label class="batch-dialog-label" style="color:${textColor};font-size:13px">起始:</label><input type="number" id="renameStartNum" class="batch-dialog-input" value="1" min="1" style="width:70px;padding:8px 10px;border:1px solid ${borderColor};border-radius:8px;background:${inputBg};color:${textColor};font-size:14px" oninput="updateRenamePreview()"></div><div style="display:flex;align-items:center;gap:6px"><label class="batch-dialog-label" style="color:${textColor};font-size:13px">位数:</label><input type="number" id="renamePadLength" class="batch-dialog-input" value="3" min="0" max="10" style="width:60px;padding:8px 10px;border:1px solid ${borderColor};border-radius:8px;background:${inputBg};color:${textColor};font-size:14px" oninput="updateRenamePreview()"></div><div style="display:flex;align-items:center;gap:6px"><label class="batch-dialog-label" style="color:${textColor};font-size:13px;white-space:nowrap"><input type="checkbox" id="renameKeepExt" checked style="margin-right:4px" onchange="updateRenamePreview()">保留扩展名</label></div></div>`;
        } else if (mode === 'replace') {
            fieldsHtml = `<div style="display:flex;gap:10px"><input type="text" id="renameFind" class="batch-dialog-input" placeholder="查找内容" style="flex:1;padding:10px 12px;border:1px solid ${borderColor};border-radius:8px;background:${inputBg};color:${textColor};font-size:14px" oninput="updateRenamePreview()"><input type="text" id="renameReplace" class="batch-dialog-input" placeholder="替换为" style="flex:1;padding:10px 12px;border:1px solid ${borderColor};border-radius:8px;background:${inputBg};color:${textColor};font-size:14px" oninput="updateRenamePreview()"></div>`;
        }
        fields.innerHTML = fieldsHtml;
    }
    
    let previewHtml = '<div style="margin-bottom:8px;font-weight:500;color:' + accentColor + '"><i class="fas fa-eye"></i> 预览（前 5 个）：</div>';
    let idx = 0;
    let changedCount = 0;
    const selectedFilesList = Array.from(selectedFiles);
    const fileIdToFile = {};
    if (typeof currentFileList !== 'undefined') {
        currentFileList.forEach(f => { fileIdToFile[f.id] = f; });
    }
    
    for (const fileId of selectedFilesList) {
        if (!fileIdToFile[fileId]) continue;
        const f = fileIdToFile[fileId];
        if (idx >= 5) break;
        idx++;
        
        const pathParts = f.filename.split('.');
        const ext = pathParts.length > 1 ? '.' + pathParts.pop() : '';
        const nameOnly = pathParts.join('.');
        let newName = f.filename;
        
        if (mode === 'prefix') { 
            const p = document.getElementById('renamePrefix')?.value || ''; 
            if (p) newName = p + nameOnly + ext; 
        }
        else if (mode === 'suffix') { 
            const s = document.getElementById('renameSuffix')?.value || ''; 
            if (s) newName = nameOnly + s + ext; 
        }
        else if (mode === 'prefix_suffix') { 
            const p = document.getElementById('renamePrefix')?.value || ''; 
            const s = document.getElementById('renameSuffix')?.value || ''; 
            if (p || s) newName = p + nameOnly + s + ext; 
        }
        else if (mode === 'number') { 
            const start = parseInt(document.getElementById('renameStartNum')?.value || 1); 
            const pad = parseInt(document.getElementById('renamePadLength')?.value || 3); 
            const keepExt = document.getElementById('renameKeepExt')?.checked !== false;
            const num = start + idx - 1; 
            const numStr = pad > 0 ? String(num).padStart(pad, '0') : String(num);
            newName = keepExt ? (numStr + ext) : numStr; 
        }
        else if (mode === 'replace') { 
            const find = document.getElementById('renameFind')?.value || ''; 
            const repl = document.getElementById('renameReplace')?.value || ''; 
            if (find) newName = f.filename.split(find).join(repl); 
        }
        
        if (newName !== f.filename) {
            changedCount++;
            previewHtml += `<div style="margin:4px 0;font-size:13px"><span style="color:${textColorMuted};text-decoration:line-through">${escapeHtml(f.filename)}</span> <span style="color:${accentColor}">→</span> <span style="color:${successColor}">${escapeHtml(newName)}</span></div>`;
        }
    }
    
    if (changedCount === 0) {
        previewHtml += '<div style="color:' + textColorMuted + '>当前设置下文件名将无变化</div>';
    }
    if (selectedFiles.size > 5) {
        previewHtml += `<div style="margin-top:8px;color:${textColorMuted};font-size:12px"><i class="fas fa-ellipsis-h"></i> 还有 ${selectedFiles.size - 5} 个文件</div>`;
    }
    
    preview.innerHTML = previewHtml;
}

function executeBatchRename() {
    const mode = document.getElementById('renameMode').value;
    const params = { file_ids: JSON.stringify([...selectedFiles]), mode: mode };
    
    if (mode === 'prefix') {
        const prefix = document.getElementById('renamePrefix')?.value || '';
        if (!prefix) { showToast('请输入前缀', 'error'); return; }
        params.prefix = prefix;
    }
    else if (mode === 'suffix') {
        const suffix = document.getElementById('renameSuffix')?.value || '';
        if (!suffix) { showToast('请输入后缀', 'error'); return; }
        params.suffix = suffix;
    }
    else if (mode === 'prefix_suffix') {
        params.prefix = document.getElementById('renamePrefix')?.value || '';
        params.suffix = document.getElementById('renameSuffix')?.value || '';
        if (!params.prefix && !params.suffix) { showToast('前缀和后缀至少填写一项', 'error'); return; }
    }
    else if (mode === 'number') {
        params.start_num = parseInt(document.getElementById('renameStartNum')?.value || 1);
        params.pad_length = parseInt(document.getElementById('renamePadLength')?.value || 3);
        params.keep_ext = document.getElementById('renameKeepExt')?.checked !== false;
    }
    else if (mode === 'replace') {
        const find = document.getElementById('renameFind')?.value || '';
        if (!find) { showToast('请输入查找内容', 'error'); return; }
        params.find = find;
        params.replace = document.getElementById('renameReplace')?.value || '';
    }
    
    const btn = event.target;
    const originalText = btn.textContent;
    btn.textContent = '处理中...';
    btn.disabled = true;
    
    api('batch_rename', params).then(data => {
        btn.textContent = originalText;
        btn.disabled = false;
        if (data.success) { 
            closeModal(); 
            selectedFiles.clear(); 
            updateBatchButtons();
            loadFiles(currentParentId); 
            showToast(data.message); 
        }
        else { 
            showToast(data.message, 'error'); 
        }
    }).catch(err => {
        btn.textContent = originalText;
        btn.disabled = false;
        showToast('操作失败', 'error');
    });
}

function initDragSort() {
    const container = document.getElementById('fileListContainer');
    if (!container) return;
    if (container._dragSortInit) return;
    container._dragSortInit = true;
    let draggedRow = null;
    container.addEventListener('dragstart', function(e) {
        if (currentSort !== 'custom') { e.preventDefault(); return; }
        const row = e.target.closest('.file-row');
        if (!row) return;
        draggedRow = row;
        row.style.opacity = '0.4';
        e.dataTransfer.effectAllowed = 'move';
    });
    container.addEventListener('dragend', function(e) {
        if (draggedRow) draggedRow.style.opacity = '';
        draggedRow = null;
        document.querySelectorAll('.file-row').forEach(r => r.style.borderTop = '');
    });
    container.addEventListener('dragover', function(e) {
        if (currentSort !== 'custom') return;
        e.preventDefault();
        const row = e.target.closest('.file-row');
        if (!row || row === draggedRow) return;
        e.dataTransfer.dropEffect = 'move';
        document.querySelectorAll('.file-row').forEach(r => r.style.borderTop = '');
        row.style.borderTop = '2px solid #667eea';
    });
    container.addEventListener('drop', function(e) {
        if (currentSort !== 'custom') return;
        e.preventDefault();
        const targetRow = e.target.closest('.file-row');
        if (!targetRow || !draggedRow || targetRow === draggedRow) return;
        document.querySelectorAll('.file-row').forEach(r => r.style.borderTop = '');
        container.insertBefore(draggedRow, targetRow);
        const rows = [...container.querySelectorAll('.file-row')];
        const orders = [];
        rows.forEach((row, index) => {
            orders.push({ id: parseInt(row.dataset.id), sort_order: index });
        });
        api('update_sort_order', { orders: JSON.stringify(orders) }).then(data => {
            if (data.success) loadFiles(currentParentId);
        });
    });
}

function showCloudNotice(message, hash) {
    var readHash = localStorage.getItem('cloud_notice_read');
    if (readHash === hash) return;
    var isDark = document.documentElement.classList.contains('dark-mode') || document.body.classList.contains('dark-mode') || document.querySelector('.dark-mode');
    var overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:99999;display:flex;align-items:center;justify-content:center;';
    var box = document.createElement('div');
    box.style.cssText = 'background:' + (isDark ? '#1e1e1e' : '#ffffff') + ';border-radius:16px;padding:28px 24px 20px;max-width:420px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,0.15);';
    box.innerHTML = '<div style="font-size:17px;font-weight:600;color:' + (isDark ? '#ffffff' : '#1a1a1a') + ';margin-bottom:14px;text-align:center;">系统通知</div><div style="font-size:14px;color:' + (isDark ? '#cccccc' : '#4a4a4a') + ';line-height:1.7;text-align:center;word-break:break-word;max-height:300px;overflow-y:auto;">' + message.replace(/\n/g, '<br>') + '</div><button id="cloudNoticeBtn" style="margin-top:18px;width:100%;padding:10px 0;background:linear-gradient(135deg, #667eea 0%, #764ba2 100%);color:#ffffff;border:none;border-radius:10px;font-size:15px;cursor:pointer;">我知道了</button>';
    overlay.appendChild(box);
    document.body.appendChild(overlay);
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay || e.target.id === 'cloudNoticeBtn') {
            localStorage.setItem('cloud_notice_read', hash);
            overlay.remove();
        }
    });
}
uploadManager.loadFromStorage();
initViewFromStorage();
loadFiles(0);
loadStorageInfo();
</script>
