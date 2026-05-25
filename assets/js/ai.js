/**
 * AI 聊天助手 - 对话管理、流式响应、工具调用
 */

let aiChatHistory = [];
let currentChatId = null;
const MAX_CHAT_HISTORY = 10;
const generatedTitles = {};
const titleFailCount = {};
const MAX_TITLE_RETRIES = 3;
let _lastSentMsg = '';
let _markedLoaded = false;

function generateChatTitle(messages) {
    const firstUserMsg = messages.find(m => m.role === 'user')?.content || '新对话';
    return firstUserMsg.substring(0, 20);
}

function saveChatHistory() {
    if (!currentChatId) return;

    const history = getChatHistoryFromStorage();
    const chatIndex = history.findIndex(c => c.id === currentChatId);
    const existingChat = chatIndex >= 0 ? history[chatIndex] : null;

    const fallbackTitle = generateChatTitle(aiChatHistory);

    let finalTitle;
    if (generatedTitles[currentChatId] && generatedTitles[currentChatId] !== '') {
        finalTitle = generatedTitles[currentChatId];
    } else if (existingChat && existingChat.title !== generateChatTitle(existingChat.messages) && existingChat.title !== '新对话') {
        finalTitle = existingChat.title;
    } else {
        finalTitle = fallbackTitle;
    }

    const chatData = {
        id: currentChatId,
        title: finalTitle,
        messages: aiChatHistory,
        updatedAt: Date.now()
    };

    if (chatIndex >= 0) {
        history[chatIndex] = chatData;
    } else {
        history.unshift(chatData);
        if (history.length > MAX_CHAT_HISTORY) {
            history.pop();
        }
    }

    localStorage.setItem('pancloud_chat_history', JSON.stringify(history));
    renderChatHistoryList();

    if (!generatedTitles[currentChatId] || generatedTitles[currentChatId] === '') {
        generateSmartTitleInBackground(aiChatHistory);
    }
}

function initMarkdown() {
    if (typeof marked !== 'undefined') {
        _markedLoaded = true;
        var renderer = new marked.Renderer();
        renderer.html = function (html) { return escapeHtml(html); };
        marked.setOptions({ renderer: renderer });
        return;
    }
    loadScript('marked').then(function () {
        _markedLoaded = true;
        var renderer = new marked.Renderer();
        renderer.html = function (html) { return escapeHtml(html); };
        marked.setOptions({ renderer: renderer });
    }).catch(function () {
        console.warn('[AI] marked 加载失败，Markdown 将以纯文本显示');
    });
}

function renderMarkdown(text) {
    if (typeof marked !== 'undefined') {
        try {
            return marked.parse(text, { breaks: true, headerIds: false });
        } catch(e) {}
    }
    return escapeHtml(text).replace(/\n/g, '<br>');
}

async function generateSmartTitleInBackground(messages) {
    if (!currentChatId) return;
    if (generatedTitles[currentChatId] && generatedTitles[currentChatId] !== '') return;
    if (generatedTitles[currentChatId] === '') return;
    if ((titleFailCount[currentChatId] || 0) >= MAX_TITLE_RETRIES) return;

    generatedTitles[currentChatId] = '';

    const firstUserMsg = messages.find(m => m.role === 'user')?.content;
    const firstAiMsg = messages.find(m => m.role === 'assistant')?.content;

    if (!firstUserMsg || !firstAiMsg) {
        delete generatedTitles[currentChatId];
        return;
    }

    try {
        const response = await api('ai_generate_title', {
            firstUserMsg: firstUserMsg.substring(0, 200),
            firstAiMsg: firstAiMsg.substring(0, 200)
        });

        if (response.success && response.title) {
            const title = response.title.substring(0, 20);
            generatedTitles[currentChatId] = title;
            delete titleFailCount[currentChatId];

            const history = getChatHistoryFromStorage();
            const chatIndex = history.findIndex(c => c.id === currentChatId);
            if (chatIndex >= 0) {
                history[chatIndex].title = title;
                localStorage.setItem('pancloud_chat_history', JSON.stringify(history));
                renderChatHistoryList();
            }
        } else {
            titleFailCount[currentChatId] = (titleFailCount[currentChatId] || 0) + 1;
            delete generatedTitles[currentChatId];
        }
    } catch (e) {
        titleFailCount[currentChatId] = (titleFailCount[currentChatId] || 0) + 1;
        delete generatedTitles[currentChatId];
    }
}

function getChatHistoryFromStorage() {
    try {
        return JSON.parse(localStorage.getItem('pancloud_chat_history') || '[]');
    } catch (e) {
        return [];
    }
}

function renderChatHistoryList() {
    const history = getChatHistoryFromStorage();
    const container = document.getElementById('chatHistoryList');
    
    if (history.length === 0) {
        container.innerHTML = '<div class="chat-history-empty" style="text-align:center;padding:20px;color:var(--text-muted);font-size:13px">暂无历史对话</div>';
        return;
    }
    
    let html = '';
    history.forEach(chat => {
        const date = new Date(chat.updatedAt);
        const timeStr = date.toLocaleString('zh-CN', { month: 'numeric', day: 'numeric', hour: '2-digit', minute: '2-digit' });
        const isActive = chat.id === currentChatId ? 'active' : '';
        html += `
            <div class="chat-history-item ${isActive}" onclick="loadChat('${chat.id}')">
                <i class="fas fa-comment-alt" style="font-size:14px;color:var(--text-secondary)"></i>
                <div class="chat-history-item-title">${escapeHtml(chat.title)}</div>
                <div class="chat-history-item-time">${timeStr}</div>
                <div class="chat-history-item-delete" onclick="event.stopPropagation();deleteChat('${chat.id}')">
                    <i class="fas fa-times" style="font-size:12px"></i>
                </div>
            </div>
        `;
    });
    
    container.innerHTML = html;
}

function loadChat(chatId) {
    const history = getChatHistoryFromStorage();
    const chat = history.find(c => c.id === chatId);
    if (!chat) return;
    
    currentChatId = chatId;
    aiChatHistory = [...chat.messages];
    
    const msgsContainer = document.getElementById('aiChatMessages');
    msgsContainer.innerHTML = '';
    
    chat.messages.forEach(msg => {
        addAIChatMessage(msg.role, msg.content, false);
    });
    
    renderChatHistoryList();
}

function deleteChat(chatId) {
    const history = getChatHistoryFromStorage();
    const newHistory = history.filter(c => c.id !== chatId);
    localStorage.setItem('pancloud_chat_history', JSON.stringify(newHistory));
    
    if (currentChatId === chatId) {
        clearAIChat();
    } else {
        renderChatHistoryList();
    }
}

// 页面加载时立即检查 AI 配置并预加载 markdown 库
(function() {
    initMarkdown();
    fetch('index.php?action=ai_agent_config')
        .then(r => r.json())
        .then(data => {
            const configured = data.success && data.config && data.config.api_key && data.config.api_key !== '';
            if (!configured) {
                const msgs = document.getElementById('aiChatMessages');
                if (msgs) {
                    msgs.innerHTML = '<div class="ai-msg ai-msg-assistant"><div class="ai-msg-avatar"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--accent-primary)" stroke-width="2"><path d="M12 2l2.4 7.2L21 12l-6.6 2.8L12 22l-2.4-7.2L3 12l6.6-2.8z"/></svg></div><div class="ai-msg-content" style="color:var(--text-muted)">AI 云助手尚未配置，请先在 <a href="javascript:void(0)" onclick="switchPage(\'settings\', document.querySelector(\'[data-page=settings]\'))" style="color:var(--accent-primary);text-decoration:underline">系统设置 → AI 配置</a> 中填写 API Key 后使用。</div></div>';
                }
            }
        })
        .catch(() => {});
})();

function createNewChat() {
    currentChatId = 'chat_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    aiChatHistory = [];
    const msgs = document.getElementById('aiChatMessages');
    msgs.innerHTML = '<div class="ai-msg ai-msg-assistant"><div class="ai-msg-avatar"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--accent-primary)" stroke-width="2"><path d="M12 2l2.4 7.2L21 12l-6.6 2.8L12 22l-2.4-7.2L3 12l6.6-2.8z"/></svg></div><div class="ai-msg-content">你好！我是云助手，可以帮你管理文件、创建分享、查看存储信息等。有什么可以帮你的吗？</div></div>';
    renderChatHistoryList();
    _lastSentMsg = '';
}

function clearAIChat() {
    const input = document.getElementById('aiChatInput');
    if (input) input.value = '';
    createNewChat();
}

function sendAIQuick(text) {
    const input = document.getElementById('aiChatInput');
    input.value = text;
    sendAIMessage();
}

function aiSetSending(enabled) {
    const input = document.getElementById('aiChatInput');
    const sendBtn = document.getElementById('aiSendBtn');
    if (input) input.disabled = enabled;
    if (sendBtn) sendBtn.disabled = enabled;
}

function aiShowError(contentDiv, textArea, msg) {
    if (typeof clearTypingTimer === 'function') clearTypingTimer();
    textArea.style.display = 'block';
    textArea.innerHTML = '<span style="color:var(--accent-danger)">' + escapeHtml(msg) + '</span><button class="btn-retry" onclick="aiRetry()"><i class="fas fa-redo"></i> 重试</button>';
}

function aiRetry() {
    const input = document.getElementById('aiChatInput');
    if (_lastSentMsg && input) {
        input.value = _lastSentMsg;
        sendAIMessage();
    }
}

function sendAIMessage() {
    if (!currentChatId) {
        createNewChat();
    }
    
    const input = document.getElementById('aiChatInput');
    const msg = input.value.trim();
    if (!msg) return;

    _lastSentMsg = msg;
    input.value = '';
    addAIChatMessage('user', msg);
    aiChatHistory.push({ role: 'user', content: msg });

    aiSetSending(true);

    const msgs = document.getElementById('aiChatMessages');
    const div = document.createElement('div');
    div.className = 'ai-msg ai-msg-assistant';
    const svgIcon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--accent-primary)" stroke-width="2"><path d="M12 2l2.4 7.2L21 12l-6.6 2.8L12 22l-2.4-7.2L3 12l6.6-2.8z"/></svg>';
    var bubbleHtml = '<div class="ai-msg-avatar">'+svgIcon+'</div><div class="ai-msg-content"><div class="ai-tool-history" style="display:none"></div><div class="ai-text-area" style="display:none"></div><span class="ai-typing-indicator"><span>正在思考</span><span class="ai-typing-dots"><span></span><span></span><span></span></span><span class="ai-typing-elapsed" id="typingElapsed"></span></span></div>';
    div.innerHTML = bubbleHtml;
    msgs.appendChild(div);
    msgs.scrollTop = msgs.scrollHeight;
    const contentDiv = div.querySelector('.ai-msg-content');
    const textArea = div.querySelector('.ai-text-area');
    const toolHistoryDiv = div.querySelector('.ai-tool-history');

    let fullContent = '';
    let toolResults = [];
    let typingStarted = Date.now();
    let typingTimer = setInterval(function() {
        var sec = Math.floor((Date.now() - typingStarted) / 1000);
        var el = document.getElementById('typingElapsed');
        if (el) el.textContent = sec + 's';
    }, 1000);
    function clearTypingTimer() {
        if (typingTimer) { clearInterval(typingTimer); typingTimer = null; }
    }
    let toolIndicator = null;
    let toolIndicatorList = [];
    let aiStatusDiv = null;
    let toolCallCount = 0;
    const toolNameMap = {
        'list_files': '列出文件',
        'scan_files': '扫描文件',
        'search_files': '搜索文件',
        'create_folder': '创建文件夹',
        'rename_file': '重命名文件',
        'delete_file': '删除文件',
        'delete_files_batch': '批量删除文件',
        'move_file': '移动文件',
        'move_files_batch': '批量移动文件',
        'toggle_favorite': '切换收藏',
        'create_share': '创建分享',
        'list_shares': '列出分享',
        'delete_share': '删除分享',
        'storage_info': '获取存储信息',
        'list_trash': '查看回收站',
        'restore_from_trash': '恢复文件',
        'generate_qrcode': '生成二维码',
        'extract_share_link': '解析分享链接',
        'cleanup_empty_folders': '清理空文件夹',
        'detect_duplicates': '查找重复文件',
        'get_largest_images': '查找最大图片',
        'get_file_stats_by_type': '文件类型统计',
        'search_and_delete': '搜索并删除',
        'search_and_move': '搜索并移动',
        'organize_files_by_type': '按类型整理文件',
        'get_recent_files': '最近文件',
        'get_favorite_files': '收藏文件',
        'batch_create_folders': '批量创建文件夹',
        'get_storage_usage_details': '详细存储使用',
        'find_and_share_largest_image': '查找并分享最大图片',
        'search_and_share': '搜索并分享'
    };

    fetch('index.php?action=ai_agent_chat_stream', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': APP_CONFIG.csrfToken
        },
        body: JSON.stringify({ messages: aiChatHistory, _csrf_token: APP_CONFIG.csrfToken }),
        signal: AbortSignal.timeout(180000)
    }).then(response => {
        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }
        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';

        function processStream() {
            reader.read().then(({done, value}) => {
                if (done) {
                    aiSetSending(false);
                    clearTypingTimer();
                    if (fullContent) {
                        aiChatHistory.push({ role: 'assistant', content: fullContent });
                    }
                    saveChatHistory();
                    _lastSentMsg = '';
                    return;
                }

                buffer += decoder.decode(value, {stream: true});
                let boundary;
                while ((boundary = buffer.indexOf('\n\n')) !== -1) {
                    const block = buffer.substring(0, boundary);
                    buffer = buffer.substring(boundary + 2);
                    const lines = block.split('\n');
                    for (const line of lines) {
                        if (line.startsWith('data: ')) {
                            try {
                                const event = JSON.parse(line.substring(6));
                                switch (event.type) {
                                    case 'text':
                                        fullContent += event.content;
                                        textArea.style.display = 'block';
                                        clearTypingTimer();
                                        textArea.innerHTML = renderMarkdown(fullContent.trimStart());
                                        var _te = contentDiv.querySelector('.ai-typing-indicator');
                                        if (_te) _te.style.display = 'none';
                                        msgs.scrollTop = msgs.scrollHeight;
                                        break;
                                    case 'tool_start':
                                        clearTypingTimer();
                                        toolHistoryDiv.style.display = 'block';
                                        toolCallCount++;
                                        
                                        if (!aiStatusDiv) {
                                            aiStatusDiv = document.createElement('div');
                                            aiStatusDiv.className = 'ai-status-bar';
                                            aiStatusDiv.style.cssText = 'display:flex;align-items:center;gap:8px;padding:8px 12px;margin:8px 0;background:rgba(37,99,235,0.08);border-radius:8px;font-size:12px;color:var(--accent-primary)';
                                            contentDiv.insertBefore(aiStatusDiv, contentDiv.firstChild);
                                        }
                                        
                                        const displayName = toolNameMap[event.name] || event.name;
                                        aiStatusDiv.innerHTML = '<svg class="ai-status-spinner" style="width:14px;height:14px;animation:ai-spin 1s linear infinite" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg><span>正在' + displayName + '，请稍候...</span><span style="margin-left:auto;color:var(--text-muted)">(' + toolCallCount + ')</span>';
                                        aiStatusDiv.style.display = 'flex';
                                        
                                        toolIndicator = document.createElement('div');
                                        toolIndicator.className = 'ai-tool-indicator';
                                        toolIndicator.dataset.name = event.name;
                                        const toolIndex = toolIndicatorList.length;
                                        toolIndicator.dataset.index = toolIndex;
                                        toolIndicatorList.push(toolIndicator);
                                        let argsHtml = '';
                                        if (event.args && Object.keys(event.args).length > 0) {
                                            const argTexts = [];
                                            for (const [k, v] of Object.entries(event.args)) {
                                                let val = v;
                                                if (typeof val === 'object') val = JSON.stringify(val);
                                                if (String(val).length > 30) val = String(val).substring(0, 30) + '...';
                                                argTexts.push(`${k}: ${val}`);
                                            }
                                            argsHtml = `<div class="ai-tool-args">${argTexts.join(' | ')}</div>`;
                                        }
                                        toolIndicator.innerHTML = `<div class="ai-tool-row"><span class="ai-tool-spinner"></span><span class="ai-tool-name">${displayName}</span></div>${argsHtml}`;
                                        toolHistoryDiv.appendChild(toolIndicator);
                                        msgs.scrollTop = msgs.scrollHeight;
                                        break;
                                    case 'tool_progress':
                                        // 更新工具执行进度
                                        if (event.name && aiStatusDiv) {
                                            const displayName = toolNameMap[event.name] || event.name;
                                            const progress = event.progress || 0;
                                            const statusMsg = event.message || '处理中...';
                                            aiStatusDiv.innerHTML = '<svg class="ai-status-spinner" style="width:14px;height:14px;animation:ai-spin 1s linear infinite" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg><span>正在' + displayName + ' - ' + statusMsg + '</span><span style="margin-left:auto;color:var(--text-muted)">' + progress + '%</span>';
                                            aiStatusDiv.style.display = 'flex';
                                        }
                                        break;
                                    case 'tool_result':
                                        toolHistoryDiv.style.display = 'block';
                                        const receivedName = event.name ? event.name.trim() : '';
                                        let targetIndicator = null;
                                        for (let i = toolIndicatorList.length - 1; i >= 0; i--) {
                                            if (toolIndicatorList[i] && toolIndicatorList[i].dataset.name === receivedName) {
                                                targetIndicator = toolIndicatorList[i];
                                                break;
                                            }
                                        }
                                        if (!targetIndicator) {
                                            console.error('targetIndicator not found for:', receivedName);
                                            break;
                                        }
                                        const resultName = toolNameMap[receivedName] || receivedName;
                                        const hasError = event.result && event.result.error;
                                        const success = !hasError;
                                        const statusIcon = success
                                            ? '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>'
                                            : '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
                                        const statusClass = success ? 'ai-tool-success' : 'ai-tool-error';
                                        let resultSummary = '执行成功';
                                        if (hasError) {
                                            resultSummary = String(event.result.error);
                                        } else if (event.result && Array.isArray(event.result)) {
                                            resultSummary = `返回 ${event.result.length} 条结果`;
                                        }
                                        targetIndicator.innerHTML = `<div class="ai-tool-row ${statusClass}">${statusIcon}<span class="ai-tool-name">${resultName}</span><span class="ai-tool-summary">${resultSummary}</span></div>`;

                                        // 自动渲染分享卡片（含二维码）
                                        if (success && event.result && (event.result.share_url || event.result.qrcode_svg)) {
                                            const shareCard = document.createElement('div');
                                            shareCard.className = 'ai-share-card';
                                            shareCard.style.cssText = 'margin-top:12px;padding:16px;background:var(--bg-surface);border:1px solid var(--bg-glass-border);border-radius:12px;display:flex;align-items:center;gap:16px;flex-wrap:wrap';
                                            let cardHtml = '<div style="flex:1;min-width:200px">';
                                            cardHtml += '<div style="font-size:13px;color:var(--text-muted);margin-bottom:4px">分享链接</div>';
                                            cardHtml += '<a href="' + escapeHtml(event.result.share_url) + '" target="_blank" style="font-size:14px;color:var(--accent-primary);word-break:break-all">' + escapeHtml(event.result.share_url) + '</a>';
                                            if (event.result.has_password) {
                                                cardHtml += '<div style="font-size:12px;color:var(--accent-warning);margin-top:4px"><i class="fas fa-lock"></i> 需要密码</div>';
                                            }
                                            if (event.result.expire_at) {
                                                const expireDate = new Date(event.result.expire_at * 1000);
                                                cardHtml += '<div style="font-size:12px;color:var(--text-muted);margin-top:4px"><i class="fas fa-clock"></i> 有效期至: ' + expireDate.toLocaleString() + '</div>';
                                            }
                                            cardHtml += '</div>';
                                            if (event.result.qrcode_svg) {
                                                cardHtml += '<div style="flex-shrink:0"><img src="data:image/svg+xml;base64,' + event.result.qrcode_svg + '" style="width:140px;height:140px;border-radius:8px;display:block" alt="二维码"></div>';
                                            }
                                            shareCard.innerHTML = cardHtml;
                                            contentDiv.appendChild(shareCard);
                                            msgs.scrollTop = msgs.scrollHeight;
                                        }

                                        if (aiStatusDiv) {
                                            const remainingTools = toolCallCount - toolResults.length - 1;
                                            if (remainingTools <= 0) {
                                                aiStatusDiv.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--accent-success)" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg><span style="color:var(--accent-success)">处理完成，正在整理结果...</span>';
                                                setTimeout(() => {
                                                    if (aiStatusDiv) aiStatusDiv.style.display = 'none';
                                                }, 1500);
                                            } else {
                                                aiStatusDiv.innerHTML = '<svg class="ai-status-spinner" style="width:14px;height:14px;animation:ai-spin 1s linear infinite" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg><span>已完成' + (toolResults.length + 1) + '个任务，还有' + remainingTools + '个进行中...</span>';
                                            }
                                        }

                                        toolResults.push(event);
                                        break;
                                    case 'done':
                                        // 分享卡片已在 tool_result 中渲染，此处无需重复处理二维码
                                        break;
                                    case 'error':
                                        clearTypingTimer();
                                        var _te = contentDiv.querySelector('.ai-typing-indicator');
                                        if (_te) _te.style.display = 'none';
                                        aiShowError(contentDiv, textArea, event.message || '请求失败');
                                        break;
                                }
                            } catch(e) {}
                        }
                    }
                }
                processStream();
            }).catch((err) => {
                aiSetSending(false);
                clearTypingTimer();
                var _te = contentDiv.querySelector('.ai-typing-indicator');
                if (_te) _te.style.display = 'none';
                const isTimeout = err.name === 'TimeoutError' || err.message?.includes('timeout');
                aiShowError(contentDiv, textArea, isTimeout ? '请求超时，AI 响应时间过长，请简化问题或稍后重试' : '网络错误，请重试');
            });
        }
        processStream();
    }).catch((err) => {
        aiSetSending(false);
        clearTypingTimer();
        var _te = contentDiv.querySelector('.ai-typing-indicator');
        if (_te) _te.style.display = 'none';
        const isTimeout = err.name === 'TimeoutError' || err.message?.includes('timeout');
        aiShowError(contentDiv, textArea, isTimeout ? '请求超时，AI 响应时间过长，请简化问题或稍后重试' : '网络错误，请重试');
    });
}

function addAIChatMessage(role, content, animate) {
    const msgs = document.getElementById('aiChatMessages');
    const div = document.createElement('div');
    div.className = 'ai-msg ai-msg-' + role + (animate === false ? '' : ' ai-msg-new');
    const avatarIcon = role === 'user'
        ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>'
        : '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--accent-primary)" stroke-width="2"><path d="M12 2l2.4 7.2L21 12l-6.6 2.8L12 22l-2.4-7.2L3 12l6.6-2.8z"/></svg>';
    const escaped = content.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    const displayContent = _markedLoaded && role === 'assistant'
        ? renderMarkdown(content)
        : escaped.replace(/\n/g, '<br>');
    div.innerHTML = '<div class="ai-msg-avatar">' + avatarIcon + '</div><div class="ai-msg-content">' + displayContent + '</div>';
    msgs.appendChild(div);
    msgs.scrollTop = msgs.scrollHeight;
}
