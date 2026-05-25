/**
 * 统一预览子系统
 * 所有文件类型共享同一个预览容器，统一头部、加载态、错误态
 */

var previewState = {
    fileId: 0,
    fileList: null,
    fileIndex: -1,
    audio: null
};

var PreviewShell = {
    init: function () {
        var self = this;
        var overlay = document.getElementById('previewOverlay');
        if (!overlay) return;

        document.getElementById('previewCloseBtn').onclick = function () { self.close(); };
        document.getElementById('previewPrevBtn').onclick = function () { self.prev(); };
        document.getElementById('previewNextBtn').onclick = function () { self.next(); };
        document.getElementById('previewDownloadBtn').onclick = function () { self.download(); };
        overlay.onclick = function (e) {
            if (e.target === overlay || e.target.classList.contains('preview-body')) self.close();
        };

        document.addEventListener('keydown', function (e) {
            if (!overlay.classList.contains('active')) return;
            if (e.key === 'Escape') self.close();
            if (e.key === 'ArrowLeft') self.prev();
            if (e.key === 'ArrowRight') self.next();
        });

        this.overlay = overlay;
    },

    open: function (fileId, fileList, index) {
        if (this.overlay.classList.contains('active')) {
            this.cleanupAudio();
        }
        previewState.fileId = fileId;
        previewState.fileList = fileList || null;
        previewState.fileIndex = index != null ? index : -1;
        this.updateNavButtons();
        this.showLoading();
        this.overlay.classList.add('active');
        document.body.style.overflow = 'hidden';
    },

    close: function () {
        this.overlay.classList.remove('active');
        document.body.style.overflow = '';
        this.cleanupAudio();
        previewState.fileList = null;
        previewState.fileIndex = -1;
    },

    prev: function () {
        if (previewState.fileList && previewState.fileIndex > 0) {
            var target = previewState.fileList[previewState.fileIndex - 1];
            if (target && !target.is_dir) openPreviewById(target.id);
        }
    },

    next: function () {
        if (previewState.fileList && previewState.fileIndex < previewState.fileList.length - 1) {
            var target = previewState.fileList[previewState.fileIndex + 1];
            if (target && !target.is_dir) openPreviewById(target.id);
        }
    },

    download: function () {
        if (previewState.fileId) {
            var file = previewState.fileList ? previewState.fileList[previewState.fileIndex] : null;
            if (file) {
                downloadFile(previewState.fileId);
            } else {
                window.location.href = 'index.php?action=download&file_id=' + previewState.fileId;
            }
        }
    },

    updateNavButtons: function () {
        var prevBtn = document.getElementById('previewPrevBtn');
        var nextBtn = document.getElementById('previewNextBtn');
        if (!previewState.fileList) {
            prevBtn.style.display = 'none';
            nextBtn.style.display = 'none';
            return;
        }
        prevBtn.style.display = '';
        nextBtn.style.display = '';
        prevBtn.disabled = previewState.fileIndex <= 0;
        nextBtn.disabled = previewState.fileIndex >= previewState.fileList.length - 1;
    },

    setHeader: function (file) {
        document.getElementById('previewFileIcon').className = 'preview-file-icon ' + (file.icon ? 'icon-' + file.icon : '');
        document.getElementById('previewFileName').textContent = file.filename || '';
        document.getElementById('previewFileSize').textContent = file.filesize_formatted || '';
    },

    showLoading: function () {
        document.getElementById('previewLoading').style.display = '';
        document.getElementById('previewContent').style.display = 'none';
        document.getElementById('previewError').style.display = 'none';
    },

    hideLoading: function () {
        document.getElementById('previewLoading').style.display = 'none';
        document.getElementById('previewContent').style.display = '';
        document.getElementById('previewError').style.display = 'none';
    },

    showError: function (msg) {
        document.getElementById('previewLoading').style.display = 'none';
        document.getElementById('previewContent').style.display = 'none';
        document.getElementById('previewError').style.display = 'flex';
        document.getElementById('previewErrorMessage').textContent = msg || '无法加载文件';
    },

    setContent: function (html, cssClass) {
        var content = document.getElementById('previewContent');
        content.innerHTML = html;
        content.className = 'preview-content' + (cssClass ? ' ' + cssClass : '');
        this.hideLoading();
    },

    cleanupAudio: function () {
        if (previewState.audio) {
            previewState.audio.pause();
            previewState.audio.src = '';
            previewState.audio = null;
        }
    }
};

PreviewShell.init();

// ===== 入口 =====

function previewFile(fileId) {
    api('record_access', { file_id: fileId });

    PreviewShell.open(fileId);

    // 如果有 currentFileList，传入支持上下导航
    var fileList = typeof currentFileList !== 'undefined' && Array.isArray(currentFileList) ? currentFileList : null;
    var index = -1;
    if (fileList) {
        for (var i = 0; i < fileList.length; i++) {
            if (fileList[i].id === fileId) { index = i; break; }
        }
    }
    previewState.fileId = fileId;
    previewState.fileList = fileList;
    previewState.fileIndex = index;
    PreviewShell.updateNavButtons();

    api('file_info', { file_id: fileId }, 'GET').then(function (infoData) {
        if (!infoData.success) {
            PreviewShell.showError('无法获取文件信息');
            return;
        }
        var file = infoData.file;
        PreviewShell.setHeader(file);
        if (index >= 0 && fileList) {
            fileList[index] = file;
        }
        loadPreviewByType(file, fileId);
    });
}

function openPreviewById(fileId) {
    previewFile(fileId);
}

function loadPreviewByType(file, fileId) {
    var ext = (file.file_type || '').toLowerCase();
    var size = file.filesize || 0;
    var previewUrl = 'index.php?action=preview&file_id=' + fileId;

    var imageExts  = ['jpg','jpeg','png','gif','bmp','webp','svg','ico','tiff','tif'];
    var videoExts  = ['mp4','webm','avi','mkv','mov','wmv','flv','m4v','3gp','mpg','mpeg','ts','f4v','ogv','rm','rmvb','vob','mts','m2ts'];
    var audioExts  = ['mp3','wav','ogg','flac','aac','wma','aiff','aif','m4a','opus','ape','alac','ra','ram','ac3','amr','mid','midi'];
    var textExts   = ['txt','json','xml','html','css','js','log','ini','cfg','yml','yaml','py','rb','java','c','cpp','h','go','rs','sql','ts','jsx','tsx','vue','sh','bash','bat','ps1','r','m','swift','kt','scala','php'];
    var mdExts     = ['md'];
    var csvExts    = ['csv'];
    var pdfExts    = ['pdf'];
    var excelExts  = ['xlsx','xls'];
    var wordExts   = ['docx'];

    var limits = { image: 10*1024*1024, media: 150*1024*1024, text: 2*1024*1024, office: 150*1024*1024, pdf: 150*1024*1024 };

    if (imageExts.indexOf(ext) >= 0) {
        if (size > limits.image) { PreviewShell.showError('图片过大，无法预览'); return; }
        renderImageViewer(file, previewUrl, fileId);
        return;
    }

    if (videoExts.indexOf(ext) >= 0) {
        if (size > limits.media) { PreviewShell.showError('视频过大，无法预览'); return; }
        renderVideoPlayer(file, previewUrl, fileId);
        return;
    }

    if (audioExts.indexOf(ext) >= 0) {
        if (size > limits.media) { PreviewShell.showError('音频过大，无法预览'); return; }
        renderMusicPlayer(file, previewUrl, fileId);
        return;
    }

    if (pdfExts.indexOf(ext) >= 0) {
        if (size > limits.pdf) { PreviewShell.showError('PDF 过大，无法预览'); return; }
        renderPdfViewer(file, previewUrl, fileId);
        return;
    }

    // 需要异步加载内容的类型
    if (textExts.indexOf(ext) >= 0 || mdExts.indexOf(ext) >= 0 || csvExts.indexOf(ext) >= 0) {
        if (size > limits.text) { PreviewShell.showError('文件过大，无法预览'); return; }
        PreviewShell.showLoading();
        api('preview', { file_id: fileId }, 'GET').then(function (data) {
            if (!data.success) { PreviewShell.showError(data.message || '无法预览'); return; }
            if (mdExts.indexOf(ext) >= 0) {
                renderMarkdownPreviewInShell(data.content, data.filename);
            } else if (csvExts.indexOf(ext) >= 0) {
                renderCsvPreviewInShell(data.content, data.filename);
            } else {
                renderTextPreviewInShell(data.content, data.filename);
            }
        });
        return;
    }

    if (excelExts.indexOf(ext) >= 0) {
        if (size > limits.office) { PreviewShell.showError('文件过大，无法预览'); return; }
        PreviewShell.showLoading();
        loadScript('xlsx').then(function () {
            renderExcelPreviewInShell(previewUrl.replace('preview', 'download'), file.filename);
        }).catch(function () {
            PreviewShell.showError('Excel 预览组件加载失败');
        });
        return;
    }

    if (wordExts.indexOf(ext) >= 0) {
        if (size > limits.office) { PreviewShell.showError('文件过大，无法预览'); return; }
        PreviewShell.showLoading();
        loadScript('mammoth').then(function () {
            renderWordPreviewInShell(previewUrl.replace('preview', 'download'), file.filename);
        }).catch(function () {
            PreviewShell.showError('Word 预览组件加载失败');
        });
        return;
    }

    PreviewShell.showError('暂不支持该文件类型的预览');
}

// ===== 文本预览 =====

function renderTextPreviewInShell(content, filename) {
    var lines = content.split('\n');
    var lineCount = lines.length;
    var lineNumWidth = String(lineCount).length;
    var lineNumbersHtml = '';
    var codeHtml = '';
    for (var i = 0; i < lines.length; i++) {
        lineNumbersHtml += '<span class="line-num" data-line="' + (i + 1) + '">' + (i + 1) + '</span>';
        codeHtml += '<span class="line-content">' + escapeHtml(lines[i]) + '</span>\n';
    }
    var ext = (filename.split('.').pop() || '').toLowerCase();
    var langClass = 'language-' + ext;
    PreviewShell.setContent(
        '<div class="preview-code-header">' +
            '<span class="preview-code-filename"><i class="fas fa-file-code"></i> ' + escapeHtml(filename) + '</span>' +
            '<span class="preview-code-meta">' + lineCount + ' 行</span>' +
        '</div>' +
        '<div class="preview-scroll code-preview">' +
            '<div class="code-line-numbers" style="--line-num-width:' + (lineNumWidth * 9 + 24) + 'px">' + lineNumbersHtml + '</div>' +
            '<pre><code class="' + langClass + '">' + codeHtml + '</code></pre>' +
        '</div>'
    );
    loadScript('highlight').then(function () {
        loadHighlightCSS();
        var codeBlock = document.querySelector('#previewContent pre code');
        if (codeBlock && typeof hljs !== 'undefined') {
            hljs.highlightElement(codeBlock);
        }
    }).catch(function () {});
}

// ===== Markdown 预览 =====

function renderMarkdownPreviewInShell(content, filename) {
    if (typeof marked === 'undefined') {
        renderTextPreviewInShell(content, filename);
        return;
    }
    try {
        marked.setOptions({
            highlight: function (code, lang) {
                if (typeof hljs !== 'undefined' && lang && hljs.getLanguage(lang)) {
                    return hljs.highlight(code, { language: lang }).value;
                }
                return escapeHtml(code);
            },
            breaks: true, gfm: true
        });
        var html = marked.parse(content);
        PreviewShell.setContent('<div class="preview-scroll"><div class="markdown-content">' + html + '</div></div>');
    } catch (e) {
        renderTextPreviewInShell(content, filename);
    }
}

// ===== CSV 预览 =====

function renderCsvPreviewInShell(content, filename) {
    var rows = content.split('\n').filter(function (r) { return r.trim(); });
    if (rows.length === 0) { PreviewShell.showError('CSV 文件为空'); return; }
    var delimiter = content.indexOf('\t') >= 0 ? '\t' : ',';
    var html = '<table><thead><tr>';
    var headers = rows[0].split(delimiter);
    for (var i = 0; i < headers.length; i++) {
        html += '<th>' + escapeHtml(headers[i].trim()) + '</th>';
    }
    html += '</tr></thead><tbody>';
    var maxRows = Math.min(rows.length, 500);
    for (var r = 1; r < maxRows; r++) {
        var cells = rows[r].split(delimiter);
        html += '<tr>';
        for (var c = 0; c < cells.length; c++) {
            html += '<td>' + escapeHtml(cells[c].trim()) + '</td>';
        }
        html += '</tr>';
    }
    html += '</tbody></table>';
    if (rows.length > 500) html += '<p class="csv-note" style="padding:8px;text-align:center;color:var(--text-muted);font-size:12px">文件过大，仅显示前 500 行</p>';
    PreviewShell.setContent('<div class="preview-scroll">' + html + '</div>');
}

// ===== Excel 预览 =====

function renderExcelPreviewInShell(downloadUrl, filename) {
    fetch(downloadUrl).then(function (r) { return r.arrayBuffer(); }).then(function (data) {
        var workbook = XLSX.read(data, { type: 'array' });
        window._currentWorkbook = workbook;
        var sheetCount = workbook.SheetNames.length;
        var html = '<div class="excel-header">' +
            '<span class="excel-filename"><i class="fas fa-file-excel"></i> ' + escapeHtml(filename) + '</span>' +
            '<span class="excel-meta">' + sheetCount + ' 个工作表</span>' +
        '</div>';
        html += '<div class="excel-tabs">';
        for (var i = 0; i < workbook.SheetNames.length; i++) {
            var name = workbook.SheetNames[i];
            html += '<button class="excel-tab-btn' + (i === 0 ? ' active' : '') + '" data-sheet="' + escapeHtml(name) + '" onclick="switchExcelTab(this)">' + escapeHtml(name) + '</button>';
        }
        html += '</div><div id="excelSheetContent" class="excel-sheet-content">' + renderExcelSheet(workbook, workbook.SheetNames[0]) + '</div>';
        PreviewShell.setContent('<div class="preview-scroll excel-preview">' + html + '</div>');
    }).catch(function (err) {
        PreviewShell.showError('Excel 加载失败：' + (err.message || ''));
    });
}

function switchExcelTab(btn) {
    document.querySelectorAll('.excel-tab-btn').forEach(function (b) {
        b.classList.remove('active');
    });
    btn.classList.add('active');
    var name = btn.getAttribute('data-sheet');
    var wb = window._currentWorkbook;
    if (wb && wb.Sheets[name]) {
        document.getElementById('excelSheetContent').innerHTML = renderExcelSheet(wb, name);
    }
}

function renderExcelSheet(workbook, sheetName) {
    var ws = workbook.Sheets[sheetName];
    var json = XLSX.utils.sheet_to_json(ws, { header: 1 });
    if (!json || json.length === 0) return '<p style="padding:12px;color:var(--text-muted)">工作表为空</p>';
    var maxCols = 0;
    for (var i = 0; i < Math.min(json.length, 50); i++) {
        if (json[i] && json[i].length > maxCols) maxCols = json[i].length;
    }
    var rowCount = json.length;
    var colLabels = [];
    for (var c = 0; c < maxCols; c++) {
        var label = '';
        var n = c;
        do {
            label = String.fromCharCode(65 + (n % 26)) + label;
            n = Math.floor(n / 26) - 1;
        } while (n >= 0);
        colLabels.push(label);
    }
    var html = '<div class="excel-table-wrap"><table class="excel-table">';
    html += '<thead><tr><th class="excel-corner"></th>';
    for (var j = 0; j < maxCols; j++) {
        html += '<th class="excel-col-header">' + colLabels[j] + '</th>';
    }
    html += '</tr></thead><tbody>';
    for (var ri = 0; ri < Math.min(rowCount, 1000); ri++) {
        html += '<tr><td class="excel-row-header">' + (ri + 1) + '</td>';
        for (var cj = 0; cj < maxCols; cj++) {
            var cv = (json[ri] && json[ri][cj] !== undefined) ? String(json[ri][cj]) : '';
            var cellClass = ri === 0 ? 'excel-cell-header' : 'excel-cell';
            html += '<td class="' + cellClass + '">' + escapeHtml(cv) + '</td>';
        }
        html += '</tr>';
    }
    html += '</tbody></table></div>';
    if (rowCount > 1000) html += '<div class="excel-footer">共 ' + rowCount + ' 行，仅显示前 1000 行</div>';
    else html += '<div class="excel-footer">共 ' + rowCount + ' 行 × ' + maxCols + ' 列</div>';
    return html;
}

// ===== Word 预览 =====

function renderWordPreviewInShell(downloadUrl, filename) {
    fetch(downloadUrl).then(function (r) { return r.arrayBuffer(); }).then(function (buf) {
        return mammoth.convertToHtml({ arrayBuffer: buf });
    }).then(function (result) {
        var html = '<div class="word-header">' +
            '<span class="word-filename"><i class="fas fa-file-word"></i> ' + escapeHtml(filename) + '</span>' +
        '</div>' +
        '<div class="preview-scroll word-preview">' +
            '<div class="word-page">' +
                '<div class="word-content">' + result.value + '</div>' +
            '</div>' +
        '</div>';
        PreviewShell.setContent(html);
    }).catch(function (err) {
        PreviewShell.showError('Word 加载失败：' + (err.message || ''));
    });
}

// ===== PDF 查看器 =====

function renderPdfViewer(file, previewUrl, fileId) {
    PreviewShell.setContent(
        '<div class="preview-pdf-wrap" id="pdfWrap">' +
            '<iframe src="' + previewUrl + '" id="pdfIframe" allowfullscreen></iframe>' +
            '<div class="preview-pdf-toolbar" id="pdfToolbar">' +
                '<span class="pdf-filename"><i class="fas fa-file-pdf"></i> ' + escapeHtml(file.filename) + '</span>' +
                '<div class="pdf-divider"></div>' +
                '<button class="pdf-btn" id="pdfZoomOut" title="缩小"><i class="fas fa-minus"></i></button>' +
                '<span class="pdf-zoom-text" id="pdfZoomText">100%</span>' +
                '<button class="pdf-btn" id="pdfZoomIn" title="放大"><i class="fas fa-plus"></i></button>' +
                '<div class="pdf-divider"></div>' +
                '<button class="pdf-btn" id="pdfFullscreen" title="全屏"><i class="fas fa-expand"></i></button>' +
            '</div>' +
        '</div>',
        'pdf'
    );

    setTimeout(function () {
        var iframe = document.getElementById('pdfIframe');
        var toolbar = document.getElementById('pdfToolbar');
        if (!iframe) return;

        var zoomLevels = [50, 75, 100, 125, 150, 200, 300];
        var zoomIndex = 2;

        function updateZoom() {
            var zoom = zoomLevels[zoomIndex];
            document.getElementById('pdfZoomText').textContent = zoom + '%';
            // iframe 内 PDF 缩放通过 CSS transform 模拟
            iframe.style.transform = 'scale(' + (zoom / 100) + ')';
            iframe.style.transformOrigin = 'top center';
        }

        document.getElementById('pdfZoomOut').onclick = function () {
            if (zoomIndex > 0) { zoomIndex--; updateZoom(); }
        };
        document.getElementById('pdfZoomIn').onclick = function () {
            if (zoomIndex < zoomLevels.length - 1) { zoomIndex++; updateZoom(); }
        };
        document.getElementById('pdfFullscreen').onclick = function () {
            var wrap = document.getElementById('pdfWrap');
            if (!document.fullscreenElement) {
                wrap.requestFullscreen().catch(function(){});
            } else {
                document.exitFullscreen();
            }
        };
    }, 50);
}

// ===== 音乐播放器 =====

function renderMusicPlayer(file, previewUrl, fileId) {
    // 检查缩略图
    var thumbUrl = '';
    if (file.thumbnail_url) {
        thumbUrl = 'index.php?action=thumbnail&file_id=' + fileId;
    }

    var coverHtml = thumbUrl
        ? '<img src="' + thumbUrl + '" alt="" onerror="this.parentElement.innerHTML=\'<i class=\\\\\'fas fa-music fallback-icon\\\\\'></i>\'">'
        : '<i class="fas fa-music fallback-icon"></i>';

    PreviewShell.setContent(
        '<div class="preview-music">' +
            '<div class="preview-music-cover" id="musicCover">' + coverHtml + '</div>' +
            '<div class="preview-music-name">' + escapeHtml(file.filename) + '</div>' +
            '<div class="preview-music-meta">' + (file.filesize_formatted || '') + '</div>' +
            '<div class="preview-music-progress">' +
                '<div class="preview-music-bar" id="musicBar">' +
                    '<div class="preview-music-fill" id="musicFill" style="width:0%"></div>' +
                '</div>' +
                '<div class="preview-music-time">' +
                    '<span id="musicCurrent">0:00</span>' +
                    '<span id="musicDuration">0:00</span>' +
                '</div>' +
            '</div>' +
            '<div class="preview-music-controls">' +
                '<button class="preview-music-btn" id="musicRewind" title="后退 10s"><i class="fas fa-backward"></i></button>' +
                '<button class="preview-music-btn play-btn" id="musicPlayBtn" title="播放/暂停"><i class="fas fa-play"></i></button>' +
                '<button class="preview-music-btn" id="musicForward" title="前进 10s"><i class="fas fa-forward"></i></button>' +
            '</div>' +
        '</div>' +
        '<audio id="previewAudio" preload="metadata" style="display:none"><source src="' + previewUrl + '" type="' + (file.mime_type || 'audio/mpeg') + '"></audio>',
        'music'
    );

    // 在 DOM 渲染后绑定事件
    setTimeout(function () {
        var audio = document.getElementById('previewAudio');
        if (!audio) return;
        previewState.audio = audio;
        var cover = document.getElementById('musicCover');
        var playBtn = document.getElementById('musicPlayBtn');
        var fill = document.getElementById('musicFill');
        var currentEl = document.getElementById('musicCurrent');
        var durationEl = document.getElementById('musicDuration');
        var bar = document.getElementById('musicBar');

        audio.load();
        audio.addEventListener('loadedmetadata', function () {
            durationEl.textContent = formatTime(audio.duration);
        });
        audio.addEventListener('timeupdate', function () {
            if (audio.duration) {
                fill.style.width = (audio.currentTime / audio.duration * 100) + '%';
                currentEl.textContent = formatTime(audio.currentTime);
            }
        });
        audio.addEventListener('play', function () {
            playBtn.innerHTML = '<i class="fas fa-pause"></i>';
            if (cover) cover.classList.add('playing');
        });
        audio.addEventListener('pause', function () {
            playBtn.innerHTML = '<i class="fas fa-play"></i>';
            if (cover) cover.classList.remove('playing');
        });

        playBtn.onclick = function () {
            if (audio.paused) { audio.play().catch(function () {}); }
            else { audio.pause(); }
        };
        document.getElementById('musicRewind').onclick = function () {
            audio.currentTime = Math.max(0, audio.currentTime - 10);
        };
        document.getElementById('musicForward').onclick = function () {
            audio.currentTime = Math.min(audio.duration || 0, audio.currentTime + 10);
        };
        bar.onclick = function (e) {
            var rect = bar.getBoundingClientRect();
            var pct = (e.clientX - rect.left) / rect.width;
            if (audio.duration) audio.currentTime = pct * audio.duration;
        };
    }, 50);
}

// ===== 视频播放器 =====

function renderVideoPlayer(file, previewUrl, fileId) {
    PreviewShell.setContent(
        '<div class="preview-video-wrap">' +
            '<video id="previewVideo" preload="metadata" controlsList="nodownload">' +
                '<source src="' + previewUrl + '" type="' + (file.mime_type || 'video/mp4') + '">' +
            '</video>' +
            '<div class="preview-video-controls" id="videoControls">' +
                '<div class="pv-progress-wrap">' +
                    '<div class="pv-progress-bar" id="videoProgressBar">' +
                        '<div class="pv-progress-fill" id="videoProgressFill" style="width:0%"></div>' +
                        '<div class="pv-progress-handle" id="videoProgressHandle" style="left:0%"></div>' +
                    '</div>' +
                    '<div class="pv-time-tooltip" id="videoTimeTooltip">0:00</div>' +
                '</div>' +
                '<div class="pv-controls-row">' +
                    '<div class="pv-controls-left">' +
                        '<button class="pv-btn" id="videoPlayBtn" title="播放/暂停 (空格)"><i class="fas fa-play"></i></button>' +
                        '<button class="pv-btn" id="videoRewindBtn" title="后退 10s"><i class="fas fa-undo-10"></i></button>' +
                        '<button class="pv-btn" id="videoForwardBtn" title="前进 10s"><i class="fas fa-redo-10"></i></button>' +
                        '<div class="pv-volume-wrap">' +
                            '<button class="pv-btn" id="videoMuteBtn" title="静音"><i class="fas fa-volume-up"></i></button>' +
                            '<div class="pv-volume-slider" id="videoVolumeSlider">' +
                                '<div class="pv-volume-fill" id="videoVolumeFill" style="width:100%"></div>' +
                            '</div>' +
                        '</div>' +
                        '<span class="pv-time" id="videoTime">0:00 / 0:00</span>' +
                    '</div>' +
                    '<div class="pv-controls-right">' +
                        '<button class="pv-btn" id="videoSpeedBtn" title="播放速度">1x</button>' +
                        '<button class="pv-btn" id="videoFullscreenBtn" title="全屏"><i class="fas fa-expand"></i></button>' +
                    '</div>' +
                '</div>' +
            '</div>' +
            '<div class="preview-video-overlay" id="videoOverlay">' +
                '<button class="pv-big-play" id="videoBigPlayBtn"><i class="fas fa-play"></i></button>' +
            '</div>' +
        '</div>',
        'video'
    );

    setTimeout(function () {
        var video = document.getElementById('previewVideo');
        if (!video) return;
        previewState.video = video;

        var playBtn = document.getElementById('videoPlayBtn');
        var bigPlayBtn = document.getElementById('videoBigPlayBtn');
        var overlay = document.getElementById('videoOverlay');
        var progressBar = document.getElementById('videoProgressBar');
        var progressFill = document.getElementById('videoProgressFill');
        var progressHandle = document.getElementById('videoProgressHandle');
        var timeTooltip = document.getElementById('videoTimeTooltip');
        var timeEl = document.getElementById('videoTime');
        var muteBtn = document.getElementById('videoMuteBtn');
        var volumeSlider = document.getElementById('videoVolumeSlider');
        var volumeFill = document.getElementById('videoVolumeFill');
        var speedBtn = document.getElementById('videoSpeedBtn');
        var fullscreenBtn = document.getElementById('videoFullscreenBtn');
        var controls = document.getElementById('videoControls');
        var wrap = video.closest('.preview-video-wrap');

        var speeds = [0.5, 0.75, 1, 1.25, 1.5, 2];
        var speedIndex = 2;
        var isDragging = false;
        var hideControlsTimer = null;

        function togglePlay() {
            if (video.paused) { video.play().catch(function(){}); }
            else { video.pause(); }
        }

        function updatePlayState() {
            var icon = video.paused ? 'fa-play' : 'fa-pause';
            playBtn.innerHTML = '<i class="fas ' + icon + '"></i>';
            bigPlayBtn.innerHTML = '<i class="fas ' + icon + '"></i>';
            overlay.style.opacity = video.paused ? '1' : '0';
            overlay.style.pointerEvents = video.paused ? 'auto' : 'none';
        }

        function formatTime(t) {
            if (!isFinite(t)) return '0:00';
            var m = Math.floor(t / 60);
            var s = Math.floor(t % 60);
            return m + ':' + (s < 10 ? '0' : '') + s;
        }

        function updateProgress() {
            if (video.duration) {
                var pct = (video.currentTime / video.duration) * 100;
                progressFill.style.width = pct + '%';
                progressHandle.style.left = pct + '%';
                timeEl.textContent = formatTime(video.currentTime) + ' / ' + formatTime(video.duration);
            }
        }

        function seekToRatio(ratio) {
            ratio = Math.max(0, Math.min(1, ratio));
            if (video.duration) video.currentTime = ratio * video.duration;
        }

        function updateVolumeUI() {
            var pct = video.muted ? 0 : video.volume * 100;
            volumeFill.style.width = pct + '%';
            var icon = video.muted || video.volume === 0 ? 'fa-volume-mute' : video.volume < 0.5 ? 'fa-volume-down' : 'fa-volume-up';
            muteBtn.innerHTML = '<i class="fas ' + icon + '"></i>';
        }

        // 事件绑定
        playBtn.onclick = togglePlay;
        bigPlayBtn.onclick = togglePlay;
        video.onclick = togglePlay;

        video.addEventListener('play', updatePlayState);
        video.addEventListener('pause', updatePlayState);
        video.addEventListener('timeupdate', updateProgress);
        video.addEventListener('loadedmetadata', function () {
            timeEl.textContent = '0:00 / ' + formatTime(video.duration);
        });
        video.addEventListener('ended', function () {
            updatePlayState();
            overlay.style.opacity = '1';
            overlay.style.pointerEvents = 'auto';
        });

        document.getElementById('videoRewindBtn').onclick = function () {
            video.currentTime = Math.max(0, video.currentTime - 10);
        };
        document.getElementById('videoForwardBtn').onclick = function () {
            video.currentTime = Math.min(video.duration || 0, video.currentTime + 10);
        };

        // 进度条交互
        progressBar.addEventListener('click', function (e) {
            var rect = progressBar.getBoundingClientRect();
            seekToRatio((e.clientX - rect.left) / rect.width);
        });
        progressBar.addEventListener('mousemove', function (e) {
            var rect = progressBar.getBoundingClientRect();
            var ratio = (e.clientX - rect.left) / rect.width;
            ratio = Math.max(0, Math.min(1, ratio));
            timeTooltip.style.left = (ratio * 100) + '%';
            timeTooltip.textContent = formatTime(ratio * (video.duration || 0));
            timeTooltip.style.opacity = '1';
        });
        progressBar.addEventListener('mouseleave', function () {
            timeTooltip.style.opacity = '0';
        });

        // 拖拽进度（鼠标）
        progressHandle.addEventListener('mousedown', function (e) {
            isDragging = true;
            e.preventDefault();
        });
        document.addEventListener('mousemove', function (e) {
            if (!isDragging) return;
            var rect = progressBar.getBoundingClientRect();
            var ratio = (e.clientX - rect.left) / rect.width;
            ratio = Math.max(0, Math.min(1, ratio));
            progressFill.style.width = (ratio * 100) + '%';
            progressHandle.style.left = (ratio * 100) + '%';
            timeTooltip.style.left = (ratio * 100) + '%';
            timeTooltip.textContent = formatTime(ratio * (video.duration || 0));
            timeTooltip.style.opacity = '1';
        });
        document.addEventListener('mouseup', function (e) {
            if (!isDragging) return;
            isDragging = false;
            var rect = progressBar.getBoundingClientRect();
            var ratio = (e.clientX - rect.left) / rect.width;
            seekToRatio(ratio);
            timeTooltip.style.opacity = '0';
        });
        
        // 拖拽进度（触摸）
        progressHandle.addEventListener('touchstart', function (e) {
            isDragging = true;
            e.preventDefault();
        }, { passive: false });
        
        progressBar.addEventListener('touchstart', function (e) {
            if (e.touches.length === 1) {
                isDragging = true;
                e.preventDefault();
            }
        }, { passive: false });
        
        document.addEventListener('touchmove', function (e) {
            if (!isDragging || e.touches.length !== 1) return;
            var rect = progressBar.getBoundingClientRect();
            var ratio = (e.touches[0].clientX - rect.left) / rect.width;
            ratio = Math.max(0, Math.min(1, ratio));
            progressFill.style.width = (ratio * 100) + '%';
            progressHandle.style.left = (ratio * 100) + '%';
            timeTooltip.style.left = (ratio * 100) + '%';
            timeTooltip.textContent = formatTime(ratio * (video.duration || 0));
            timeTooltip.style.opacity = '1';
            e.preventDefault();
        }, { passive: false });
        
        document.addEventListener('touchend', function (e) {
            if (!isDragging) return;
            isDragging = false;
            var rect = progressBar.getBoundingClientRect();
            var ratio = (e.changedTouches[0].clientX - rect.left) / rect.width;
            ratio = Math.max(0, Math.min(1, ratio));
            seekToRatio(ratio);
            timeTooltip.style.opacity = '0';
        });

        // 音量
        muteBtn.onclick = function () {
            video.muted = !video.muted;
            updateVolumeUI();
        };
        volumeSlider.addEventListener('click', function (e) {
            var rect = volumeSlider.getBoundingClientRect();
            video.volume = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
            video.muted = false;
            updateVolumeUI();
        });
        
        // 音量滑块触摸支持
        volumeSlider.addEventListener('touchstart', function (e) {
            var rect = volumeSlider.getBoundingClientRect();
            video.volume = Math.max(0, Math.min(1, (e.touches[0].clientX - rect.left) / rect.width));
            video.muted = false;
            updateVolumeUI();
            e.preventDefault();
        }, { passive: false });

        // 倍速
        speedBtn.onclick = function () {
            speedIndex = (speedIndex + 1) % speeds.length;
            video.playbackRate = speeds[speedIndex];
            speedBtn.textContent = speeds[speedIndex] + 'x';
        };

        // 全屏
        fullscreenBtn.onclick = function () {
            if (!document.fullscreenElement) {
                wrap.requestFullscreen().catch(function(){});
            } else {
                document.exitFullscreen();
            }
        };

        // 控制栏自动隐藏
        function showControls() {
            controls.classList.add('active');
            clearTimeout(hideControlsTimer);
            if (!video.paused) {
                hideControlsTimer = setTimeout(function () {
                    controls.classList.remove('active');
                }, 3000);
            }
        }
        wrap.addEventListener('mousemove', showControls);
        wrap.addEventListener('mouseleave', function () {
            if (!video.paused) controls.classList.remove('active');
        });

        // 键盘快捷键
        document.addEventListener('keydown', function videoKeyHandler(e) {
            if (!wrap.closest('.preview-overlay.active')) {
                document.removeEventListener('keydown', videoKeyHandler);
                return;
            }
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
            switch (e.key) {
                case ' ': e.preventDefault(); togglePlay(); break;
                case 'ArrowLeft': video.currentTime = Math.max(0, video.currentTime - 5); break;
                case 'ArrowRight': video.currentTime = Math.min(video.duration || 0, video.currentTime + 5); break;
                case 'ArrowUp': video.volume = Math.min(1, video.volume + 0.1); updateVolumeUI(); break;
                case 'ArrowDown': video.volume = Math.max(0, video.volume - 0.1); updateVolumeUI(); break;
                case 'f': case 'F': fullscreenBtn.click(); break;
                case 'm': case 'M': muteBtn.click(); break;
            }
        });

        video.load();
        updateVolumeUI();
    }, 50);
}

// ===== 图片查看器 =====

function renderImageViewer(file, previewUrl, fileId) {
    var thumbUrl = file.thumbnail_url || '';
    var initialSrc = (thumbUrl && file.filesize > 512 * 1024) ? thumbUrl : previewUrl;

    PreviewShell.setContent(
        '<div class="preview-image-wrap" id="imageWrap">' +
            '<div class="preview-image-container" id="imageContainer">' +
                '<img src="' + initialSrc + '" alt="" id="previewImage" draggable="false">' +
            '</div>' +
            '<div class="preview-image-toolbar" id="imageToolbar">' +
                '<button class="pi-btn" id="imgZoomOut" title="缩小 (-)"><i class="fas fa-minus"></i></button>' +
                '<span class="pi-zoom-text" id="imgZoomText">100%</span>' +
                '<button class="pi-btn" id="imgZoomIn" title="放大 (+)"><i class="fas fa-plus"></i></button>' +
                '<div class="pi-divider"></div>' +
                '<button class="pi-btn" id="imgRotateLeft" title="向左旋转 (L)"><i class="fas fa-undo"></i></button>' +
                '<button class="pi-btn" id="imgRotateRight" title="向右旋转 (R)"><i class="fas fa-redo"></i></button>' +
                '<div class="pi-divider"></div>' +
                '<button class="pi-btn" id="imgReset" title="适应屏幕 (0)"><i class="fas fa-compress-arrows-alt"></i></button>' +
                '<button class="pi-btn" id="imgActualSize" title="实际大小 (1)"><i class="fas fa-expand-arrows-alt"></i></button>' +
                '<div class="pi-divider"></div>' +
                '<button class="pi-btn" id="imgFullscreen" title="全屏 (F)"><i class="fas fa-expand"></i></button>' +
            '</div>' +
            '<div class="preview-image-loading" id="imageLoading" style="display:none">' +
                '<i class="fas fa-spinner fa-spin"></i>' +
            '</div>' +
        '</div>',
        'image'
    );

    setTimeout(function () {
        var wrap = document.getElementById('imageWrap');
        var container = document.getElementById('imageContainer');
        var img = document.getElementById('previewImage');
        var toolbar = document.getElementById('imageToolbar');
        if (!img) return;

        var state = {
            scale: 1,
            rotate: 0,
            translateX: 0,
            translateY: 0,
            isDragging: false,
            lastX: 0,
            lastY: 0,
            naturalWidth: 0,
            naturalHeight: 0,
            loaded: false
        };

        // 如果用了缩略图占位，先加载原图
        if (initialSrc !== previewUrl) {
            var loadingEl = document.getElementById('imageLoading');
            if (loadingEl) loadingEl.style.display = '';
            var fullImg = new Image();
            fullImg.onload = function () {
                img.src = previewUrl;
                state.naturalWidth = fullImg.naturalWidth;
                state.naturalHeight = fullImg.naturalHeight;
                state.loaded = true;
                setTimeout(fitToScreen, 80);
                if (loadingEl) loadingEl.style.display = 'none';
            };
            fullImg.src = previewUrl;
        } else {
            // 处理图片已缓存的情况
            if (img.complete && img.naturalWidth > 0) {
                state.naturalWidth = img.naturalWidth;
                state.naturalHeight = img.naturalHeight;
                state.loaded = true;
                setTimeout(fitToScreen, 80);
            } else {
                img.onload = function () {
                    state.naturalWidth = img.naturalWidth;
                    state.naturalHeight = img.naturalHeight;
                    state.loaded = true;
                    setTimeout(fitToScreen, 80);
                };
                img.onerror = function () {
                    PreviewShell.showError('图片加载失败');
                };
            }
        }

        function applyTransform() {
            img.style.transform = 'translate(' + state.translateX + 'px, ' + state.translateY + 'px) scale(' + state.scale + ') rotate(' + state.rotate + 'deg)';
            document.getElementById('imgZoomText').textContent = Math.round(state.scale * 100) + '%';
        }

        function fitToScreen() {
            if (!state.loaded) return;
            var wrapW = wrap.clientWidth;
            var wrapH = wrap.clientHeight;
            var ratio = Math.min(wrapW / state.naturalWidth, wrapH / state.naturalHeight, 1);
            state.scale = ratio;
            state.rotate = 0;
            state.translateX = 0;
            state.translateY = 0;
            applyTransform();
        }

        function actualSize() {
            state.scale = 1;
            state.translateX = 0;
            state.translateY = 0;
            applyTransform();
        }

        function zoom(delta) {
            var newScale = Math.max(0.1, Math.min(10, state.scale + delta));
            state.scale = newScale;
            applyTransform();
        }

        function rotate(deg) {
            state.rotate = (state.rotate + deg) % 360;
            applyTransform();
        }

        // 工具栏事件
        document.getElementById('imgZoomOut').onclick = function () { zoom(-0.25); };
        document.getElementById('imgZoomIn').onclick = function () { zoom(0.25); };
        document.getElementById('imgRotateLeft').onclick = function () { rotate(-90); };
        document.getElementById('imgRotateRight').onclick = function () { rotate(90); };
        document.getElementById('imgReset').onclick = fitToScreen;
        document.getElementById('imgActualSize').onclick = actualSize;
        document.getElementById('imgFullscreen').onclick = function () {
            if (!document.fullscreenElement) {
                wrap.requestFullscreen().catch(function(){});
            } else {
                document.exitFullscreen();
            }
        };

        // 滚轮缩放
        container.addEventListener('wheel', function (e) {
            e.preventDefault();
            var delta = e.deltaY > 0 ? -0.15 : 0.15;
            zoom(delta);
        }, { passive: false });

        // 拖拽平移（鼠标）
        container.addEventListener('mousedown', function (e) {
            if (e.button !== 0) return;
            state.isDragging = true;
            state.lastX = e.clientX;
            state.lastY = e.clientY;
            container.style.cursor = 'grabbing';
            e.preventDefault();
        });
        document.addEventListener('mousemove', function (e) {
            if (!state.isDragging) return;
            var dx = e.clientX - state.lastX;
            var dy = e.clientY - state.lastY;
            state.translateX += dx;
            state.translateY += dy;
            state.lastX = e.clientX;
            state.lastY = e.clientY;
            applyTransform();
        });
        document.addEventListener('mouseup', function () {
            if (state.isDragging) {
                state.isDragging = false;
                container.style.cursor = 'grab';
            }
        });
        
        // ===== 触摸支持 =====
        var touchState = {
            isDragging: false,
            isPinching: false,
            lastTouchX: 0,
            lastTouchY: 0,
            lastPinchDistance: 0,
            initialScale: 0
        };
        
        function getPinchDistance(touch1, touch2) {
            var dx = touch2.clientX - touch1.clientX;
            var dy = touch2.clientY - touch1.clientY;
            return Math.sqrt(dx * dx + dy * dy);
        }
        
        function getPinchCenter(touch1, touch2) {
            return {
                x: (touch1.clientX + touch2.clientX) / 2,
                y: (touch1.clientY + touch2.clientY) / 2
            };
        }
        
        container.addEventListener('touchstart', function(e) {
            e.preventDefault();
            
            if (e.touches.length === 1) {
                // 单指拖拽
                var touch = e.touches[0];
                touchState.isDragging = true;
                touchState.lastTouchX = touch.clientX;
                touchState.lastTouchY = touch.clientY;
            } else if (e.touches.length === 2) {
                // 双指缩放
                touchState.isPinching = true;
                touchState.lastPinchDistance = getPinchDistance(e.touches[0], e.touches[1]);
                touchState.initialScale = state.scale;
            }
        }, { passive: false });
        
        container.addEventListener('touchmove', function(e) {
            e.preventDefault();
            
            if (touchState.isDragging && e.touches.length === 1) {
                var touch = e.touches[0];
                var dx = touch.clientX - touchState.lastTouchX;
                var dy = touch.clientY - touchState.lastTouchY;
                state.translateX += dx;
                state.translateY += dy;
                touchState.lastTouchX = touch.clientX;
                touchState.lastTouchY = touch.clientY;
                applyTransform();
            } else if (touchState.isPinching && e.touches.length === 2) {
                var currentDistance = getPinchDistance(e.touches[0], e.touches[1]);
                var ratio = currentDistance / touchState.lastPinchDistance;
                
                // 计算缩放中心（相对于图片）
                var center = getPinchCenter(e.touches[0], e.touches[1]);
                var rect = container.getBoundingClientRect();
                var centerX = (center.x - rect.left) / rect.width;
                var centerY = (center.y - rect.top) / rect.height;
                
                // 应用缩放
                var newScale = touchState.initialScale * ratio;
                newScale = Math.max(0.1, Math.min(10, newScale));
                
                // 简单的缩放（以中心点为基准）
                state.scale = newScale;
                applyTransform();
                
                touchState.lastPinchDistance = currentDistance;
            }
        }, { passive: false });
        
        container.addEventListener('touchend', function(e) {
            if (e.touches.length === 0) {
                touchState.isDragging = false;
                touchState.isPinching = false;
            } else if (e.touches.length === 1) {
                // 从双指变为单指，切换为拖拽模式
                touchState.isPinching = false;
                touchState.isDragging = true;
                var touch = e.touches[0];
                touchState.lastTouchX = touch.clientX;
                touchState.lastTouchY = touch.clientY;
            }
        }, { passive: false });
        
        container.addEventListener('touchcancel', function(e) {
            touchState.isDragging = false;
            touchState.isPinching = false;
        }, { passive: false });

        // 双击适应屏幕/实际大小切换
        container.addEventListener('dblclick', function () {
            if (state.scale >= 0.95 && state.scale <= 1.05) {
                fitToScreen();
            } else {
                actualSize();
            }
        });

        // 键盘快捷键
        document.addEventListener('keydown', function imgKeyHandler(e) {
            if (!wrap.closest('.preview-overlay.active')) {
                document.removeEventListener('keydown', imgKeyHandler);
                return;
            }
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
            switch (e.key) {
                case '+': case '=': zoom(0.25); break;
                case '-': case '_': zoom(-0.25); break;
                case '0': fitToScreen(); break;
                case '1': actualSize(); break;
                case 'r': case 'R': rotate(90); break;
                case 'l': case 'L': rotate(-90); break;
                case 'f': case 'F': document.getElementById('imgFullscreen').click(); break;
                case 'ArrowLeft': PreviewShell.prev(); break;
                case 'ArrowRight': PreviewShell.next(); break;
            }
        });

        container.style.cursor = 'grab';
    }, 50);
}
