/**
 * 工具函数集合
 * 纯 JavaScript 工具函数，不依赖业务逻辑
 */

/**
 * 格式化时间（秒转为分:秒格式）
 * @param {number} seconds - 秒数
 * @returns {string} 格式化后的时间字符串
 */
function formatTime(seconds) {
    if (!seconds || isNaN(seconds)) return '0:00';
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return mins + ':' + (secs < 10 ? '0' : '') + secs;
}

/**
 * HTML 转义
 * @param {string} str - 需要转义的字符串
 * @returns {string} 转义后的字符串
 */
function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

/**
 * JSON 字符串转义（用于嵌入 HTML）
 * @param {string} jsonStr - JSON 字符串
 * @returns {string} 转义后的字符串
 */
function escapeJsonForHtml(jsonStr) {
    return jsonStr.replace(/'/g, '&#39;').replace(/"/g, '&quot;');
}

/**
 * 代码高亮处理
 * @param {string} content - 代码内容
 * @param {string} filename - 文件名
 * @returns {string} 高亮后的 HTML
 */
function highlightTextContent(content, filename) {
    if (typeof hljs === 'undefined') return content;
    
    const codeExtensions = ['py', 'rb', 'java', 'c', 'cpp', 'h', 'go', 'rs', 'sql', 'ts', 'jsx', 'tsx', 'vue', 'js', 'php', 'sh', 'bash', 'css', 'html', 'xml', 'json', 'yml', 'yaml', 'ini', 'cfg', 'md', 'r', 'm', 'swift', 'kt', 'scala', 'log'];
    const ext = filename.split('.').pop().toLowerCase();
    
    if (codeExtensions.includes(ext)) {
        return `<code class="language-${ext === 'js' ? 'javascript' : ext === 'py' ? 'python' : ext === 'rb' ? 'ruby' : ext === 'rs' ? 'rust' : ext === 'ts' ? 'typescript' : ext}">${content}</code>`;
    }
    return content;
}

/**
 * CSV 文件预览
 * @param {string} content - CSV 内容
 * @param {string} filename - 文件名
 */
function renderCSVPreview(content, filename) {
    const rows = content.split('\n').filter(row => row.trim());
    if (rows.length === 0) {
        showToast('CSV 文件为空', 'error');
        return;
    }
    
    const delimiter = content.includes('\t') ? '\t' : ',';
    let table = '<div class="csv-preview-container"><table class="csv-preview-table"><thead><tr>';
    
    const headers = rows[0].split(delimiter);
    headers.forEach(header => {
        table += `<th>${escapeHtml(header.trim())}</th>`;
    });
    table += '</tr></thead><tbody>';
    
    for (let i = 1; i < Math.min(rows.length, 500); i++) {
        const cells = rows[i].split(delimiter);
        table += '<tr>';
        cells.forEach(cell => {
            table += `<td>${escapeHtml(cell.trim())}</td>`;
        });
        table += '</tr>';
    }
    table += '</tbody></table>';
    if (rows.length > 500) {
        table += `<p class="csv-note">文件过大，仅显示前 500 行</p>`;
    }
    table += '</div>';
    
    showModal('CSV 预览 - ' + escapeHtml(filename), table);
}

/**
 * Excel 文件预览
 * @param {string} downloadUrl - 下载 URL
 * @param {string} filename - 文件名
 */
function renderExcelPreview(downloadUrl, filename) {
    const container = document.createElement('div');
    container.className = 'loading-excel-preview';
    container.innerHTML = '<div class="loading-indicator"><i class="fas fa-spinner fa-spin"></i><p>正在加载 Excel 文件...</p></div>';
    showModal('Excel 预览 - ' + escapeHtml(filename), container.outerHTML);
    
    fetch(downloadUrl)
        .then(response => response.arrayBuffer())
        .then(data => {
            const workbook = XLSX.read(data, {type: 'array'});
            window._currentWorkbook = workbook;
            let html = '<div class="excel-preview-container"><div class="excel-tabs">';
            
            workbook.SheetNames.forEach((name, index) => {
                html += `<div class="excel-tab ${index === 0 ? 'active' : ''}" data-sheet-name="${escapeHtml(name)}" onclick="switchExcelTab(this)">${escapeHtml(name)}</div>`;
            });
            html += '</div><div class="excel-content">';
            html += renderExcelSheet(workbook, workbook.SheetNames[0]);
            html += '</div></div>';
            
            document.getElementById('modalBody').innerHTML = html;
        })
        .catch(error => {
            document.getElementById('modalBody').innerHTML = '<p class="error-message">Excel 文件加载失败：' + escapeHtml(error.message) + '</p>';
        });
}

/**
 * 渲染 Excel 工作表
 * @param {Object} workbook - Excel 工作簿对象
 * @param {string} sheetName - 工作表名称
 * @returns {string} HTML 字符串
 */
function renderExcelSheet(workbook, sheetName) {
    const worksheet = workbook.Sheets[sheetName];
    const json = XLSX.utils.sheet_to_json(worksheet, {header: 1});
    
    if (json.length === 0) return '<p>工作表为空</p>';
    
    let maxCols = 0;
    for (let i = 0; i < Math.min(json.length, 50); i++) {
        if (json[i] && json[i].length > maxCols) {
            maxCols = json[i].length;
        }
    }
    
    let html = '<table class="excel-table"><thead><tr>';
    for (let j = 0; j < maxCols; j++) {
        html += `<th>${escapeHtml(String((json[0][j] !== undefined) ? json[0][j] : ''))}</th>`;
    }
    html += '</tr></thead><tbody>';
    
    for (let i = 1; i < Math.min(json.length, 1000); i++) {
        html += '<tr>';
        for (let j = 0; j < maxCols; j++) {
            html += `<td>${escapeHtml(String((json[i][j] !== undefined) ? json[i][j] : ''))}</td>`;
        }
        html += '</tr>';
    }
    html += '</tbody></table>';
    
    if (json.length > 1000) {
        html += '<p class="excel-note">文件过大，仅显示前 1000 行</p>';
    }
    
    return html;
}

/**
 * 切换 Excel 工作表标签
 * @param {HTMLElement} tabElement - 标签元素
 */
function switchExcelTab(tabElement) {
    document.querySelectorAll('.excel-tab').forEach(tab => tab.classList.remove('active'));
    tabElement.classList.add('active');
    
    const sheetName = tabElement.dataset.sheetName;
    const workbook = window._currentWorkbook;
    if (workbook && workbook.Sheets[sheetName]) {
        document.querySelector('.excel-content').innerHTML = renderExcelSheet(workbook, sheetName);
    }
}

/**
 * Word 文档预览
 * @param {string} downloadUrl - 下载 URL
 * @param {string} filename - 文件名
 */
function renderWordPreview(downloadUrl, filename) {
    const container = document.createElement('div');
    container.className = 'loading-word-preview';
    container.innerHTML = '<div class="loading-indicator"><i class="fas fa-spinner fa-spin"></i><p>正在加载 Word 文档...</p></div>';
    showModal('Word 预览 - ' + escapeHtml(filename), container.outerHTML);
    
    fetch(downloadUrl)
        .then(response => response.arrayBuffer())
        .then(arrayBuffer => {
            return mammoth.convertToHtml({arrayBuffer: arrayBuffer});
        })
        .then(result => {
            document.getElementById('modalBody').innerHTML = `<div class="word-preview-container"><div class="word-content">${result.value}</div></div>`;
        })
        .catch(error => {
            document.getElementById('modalBody').innerHTML = '<p class="error-message">Word 文档加载失败：' + escapeHtml(error.message) + '</p>';
        });
}

/**
 * Markdown 预览
 * @param {string} content - Markdown 内容
 * @param {string} filename - 文件名
 * @returns {string} 渲染后的 HTML
 */
function renderMarkdownPreview(content, filename) {
    if (typeof marked === 'undefined') {
        return `<pre class="preview-text">${escapeHtml(content)}</pre>`;
    }
    
    marked.setOptions({
        highlight: function(code, lang) {
            if (typeof hljs !== 'undefined' && lang && hljs.getLanguage(lang)) {
                return hljs.highlight(code, {language: lang}).value;
            }
            return escapeHtml(code);
        },
        breaks: true,
        gfm: true,
        sanitize: true
    });
    
    const html = marked.parse(content);
    return `<div class="markdown-preview">${html}</div>`;
}

/**
 * PDF 全屏预览
 * @param {number} fileId - 文件 ID
 * @param {string} filename - 文件名
 * @param {string} previewUrl - 预览 URL
 */
function showPdfFullscreen(fileId, filename, previewUrl) {
    const container = document.createElement('div');
    container.className = 'pdf-fullscreen-container';
    container.id = 'pdfFullscreenContainer';
    container.innerHTML = `
        <div class="pdf-fullscreen-toolbar">
            <span class="pdf-fullscreen-title">${escapeHtml(filename)}</span>
            <div class="pdf-fullscreen-actions">
                <button class="pdf-fullscreen-btn" id="pdfDownloadBtn" title="下载">
                    <i class="fas fa-download"></i>
                </button>
                <button class="pdf-close-btn" id="pdfCloseBtn" title="关闭">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        <iframe class="pdf-fullscreen-iframe" src="${previewUrl}" frameborder="0"></iframe>
    `;

    document.body.appendChild(container);
    document.body.style.overflow = 'hidden';

    const closeBtn = document.getElementById('pdfCloseBtn');
    const downloadBtn = document.getElementById('pdfDownloadBtn');

    closeBtn.addEventListener('click', () => {
        container.remove();
        document.body.style.overflow = '';
    });

    downloadBtn.addEventListener('click', () => {
        window.location.href = 'index.php?action=download&file_id=' + fileId;
    });

    document.addEventListener('keydown', function pdfEscHandler(e) {
        if (e.key === 'Escape') {
            container.remove();
            document.body.style.overflow = '';
            document.removeEventListener('keydown', pdfEscHandler);
        }
    });
}
