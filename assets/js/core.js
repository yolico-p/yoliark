/**
 * 基础设施模块 - API通信、UI组件、通用工具
 */

const CDN_LIBS = {
    qrcode: [
        'https://cdn.bootcdn.net/ajax/libs/qrcodejs/1.0.0/qrcode.min.js',
        'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js'
    ],
    crypto: [
        'https://cdn.bootcdn.net/ajax/libs/crypto-js/4.1.1/crypto-js.min.js',
        'https://cdnjs.cloudflare.com/ajax/libs/crypto-js/4.1.1/crypto-js.min.js'
    ],
    highlight: [
        'https://cdn.bootcdn.net/ajax/libs/highlight.js/11.9.0/highlight.min.js',
        'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js'
    ],
    xlsx: [
        'https://cdn.bootcdn.net/ajax/libs/xlsx/0.18.5/xlsx.full.min.js',
        'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js'
    ],
    mammoth: [
        'https://cdn.bootcdn.net/ajax/libs/mammoth/1.6.0/mammoth.browser.min.js',
        'https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.6.0/mammoth.browser.min.js'
    ],
    marked: [
        'https://cdn.bootcdn.net/ajax/libs/marked/11.1.1/marked.min.js',
        'https://cdnjs.cloudflare.com/ajax/libs/marked/11.1.1/marked.min.js'
    ]
};

const loadedLibs = {};

function loadScript(libName) {
    if (loadedLibs[libName]) {
        return Promise.resolve();
    }
    var urls = CDN_LIBS[libName];
    if (!urls || urls.length === 0) {
        return Promise.reject(new Error('未知的库: ' + libName));
    }
    // 逐个尝试 URL，失败后自动切换备用 CDN
    function tryUrl(index) {
        return new Promise(function(resolve, reject) {
            if (index >= urls.length) {
                reject(new Error('加载 ' + libName + ' 失败（所有 CDN 均不可用）'));
                return;
            }
            var script = document.createElement('script');
            script.src = urls[index];
            script.onload = function() {
                loadedLibs[libName] = true;
                resolve();
            };
            script.onerror = function() {
                tryUrl(index + 1).then(resolve, reject);
            };
            document.head.appendChild(script);
        });
    }
    return tryUrl(0);
}

function loadScripts(libNames) {
    const promises = libNames.map(name => loadScript(name));
    return Promise.all(promises);
}

function loadHighlightCSS() {
    if (document.getElementById('hljs-css')) return;
    var link = document.createElement('link');
    link.id = 'hljs-css';
    link.rel = 'stylesheet';
    link.href = 'https://cdn.bootcdn.net/ajax/libs/highlight.js/11.9.0/styles/atom-one-dark.min.css';
    link.onerror = function() {
        this.href = 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/atom-one-dark.min.css';
        link.onerror = null;
    };
    document.head.appendChild(link);
}

function api(action, data = {}, method = 'POST', abortSignal) {
    const isGet = method === 'GET';
    let url = 'index.php?action=' + encodeURIComponent(action);
    let options = {
        method: method,
        headers: {
            'X-CSRF-TOKEN': APP_CONFIG.csrfToken
        }
    };
    if (abortSignal) {
        options.signal = abortSignal;
    }

    if (isGet) {
        const params = new URLSearchParams(data);
        url += '&' + params.toString();
    } else {
        if (data instanceof FormData) {
            options.body = data;
        } else {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify({...data, _csrf_token: APP_CONFIG.csrfToken});
        }
    }

    return fetch(url, options).then(r => {
        if (r.status === 401) {
            window.location.href = 'index.php?page=login';
            throw new Error('未登录');
        }
        const contentType = r.headers.get('content-type');
        if (contentType && contentType.includes('application/json')) {
            return r.json().then(d => {
                if (d.cloud_config && d.cloud_config.notice) {
                    setTimeout(function(){ showCloudNotice(d.cloud_config.notice, d.cloud_config.notice_hash); }, 1500);
                }
                return d;
            });
        }
        return r;
    });
}

function showToast(message, type = 'info') {
    const container = document.getElementById('toastContainer');
    const toast = document.createElement('div');
    toast.className = 'toast toast-' + type;
    const icons = { info: 'fa-info-circle', success: 'fa-check-circle', error: 'fa-times-circle', warning: 'fa-exclamation-triangle' };
    toast.innerHTML = '<i class="fas ' + (icons[type] || icons.info) + '"></i> ' + escapeHtml(message);
    container.appendChild(toast);
    setTimeout(() => toast.classList.add('show'), 10);
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 400);
    }, 3000);
}

function showModal(title, body) {
    document.getElementById('modalTitle').textContent = title;
    document.getElementById('modalBody').innerHTML = body;
    document.getElementById('modalOverlay').classList.add('active');
}

function closeModal() {
    document.getElementById('modalOverlay').classList.remove('active');
}

function showLicense() {
    api('license', {}, 'GET')
        .then(function(d) {
            if (d.success) {
                showModal('许可协议', '<pre style="font-size:12px;line-height:1.6;max-height:60vh;overflow-y:auto;white-space:pre-wrap;word-break:break-word;background:var(--bg-secondary);padding:16px;border-radius:8px;margin:0">' + escapeHtml(d.content) + '</pre>');
            } else {
                showToast('无法加载许可协议', 'error');
            }
        })
        .catch(function() {
            showToast('无法加载许可协议', 'error');
        });
}

function showConfirm(message, onConfirm, onCancel) {
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.id = 'confirmOverlay';
    overlay.innerHTML = `
        <div class="modal-box glass-strong" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3>确认操作</h3>
                <button class="modal-close" onclick="closeConfirm(false)"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <p style="margin-bottom:24px;color:var(--text-secondary);font-size:15px;line-height:1.7">${escapeHtml(message)}</p>
                <div style="display:flex;gap:12px">
                    <button class="btn btn-glass" style="flex:1" onclick="closeConfirm(false)">取消</button>
                    <button class="btn btn-danger" style="flex:1" onclick="closeConfirm(true)">确定</button>
                </div>
            </div>
        </div>
    `;
    document.body.appendChild(overlay);
    
    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) closeConfirm(false);
    });
    
    requestAnimationFrame(() => overlay.classList.add('active'));
    
    window._confirmCallback = { onConfirm, onCancel };
}

function closeConfirm(confirmed) {
    const overlay = document.getElementById('confirmOverlay');
    if (overlay) {
        overlay.classList.remove('active');
        setTimeout(() => overlay.remove(), 300);
    }
    
    if (window._confirmCallback) {
        if (confirmed && window._confirmCallback.onConfirm) {
            window._confirmCallback.onConfirm();
        } else if (!confirmed && window._confirmCallback.onCancel) {
            window._confirmCallback.onCancel();
        }
        window._confirmCallback = null;
    }
}

function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('active');
}

function copyText(text) {
    navigator.clipboard.writeText(text).then(() => showToast('已复制'));
}

function formatSize(bytes) {
    if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
    if (bytes >= 1024) return (bytes / 1024).toFixed(2) + ' KB';
    return bytes + ' B';
}
