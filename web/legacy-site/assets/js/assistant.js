/* global request */
(() => {
  const state = {
    conversation: null,
    conversations: [],
    gateway: null,
    socket: null,
    micStream: null,
    audioContext: null,
    source: null,
    processor: null,
    mutedGain: null,
    outputAt: 0,
    listening: false,
    messages: [],
    liveUser: null,
    liveAssistant: null,
    savedUser: '',
    savedAssistant: '',
    context: null,
    memorySettings: null,
    isAdmin: false,
    pendingAction: null,
  };
  const byId = (id) => document.getElementById(id);
  const node = (tag, className, text) => { const element = document.createElement(tag); if (className) element.className = className; if (text !== undefined) element.textContent = text; return element; };
  const timeText = (value) => { try { return new Intl.DateTimeFormat('zh-CN',{hour:'2-digit',minute:'2-digit'}).format(new Date(value.replace(' ','T'))); } catch (_) { return ''; } };

  function setStatus(text, mode='idle') {
    byId('assistant-status').textContent = text;
    document.querySelector('.assistant-console')?.classList.toggle('is-listening', mode === 'listening');
    byId('assistant-mic-label').textContent = state.listening ? '停止' : '开始';
    byId('assistant-mic').setAttribute('aria-label', state.listening ? '停止采集语音' : '开始语音对话');
  }
  function showError(message) {
    setStatus(message);
    byId('assistant-voice-title').textContent = '暂时无法开始';
    byId('assistant-voice-description').textContent = message;
  }
  function renderMemoryStatus(context=state.context) {
    const root = byId('assistant-memory-status');
    if (!Boolean(Number(state.conversation?.memory_enabled))) { root.textContent = '记忆已关闭'; root.dataset.status = 'disabled'; return; }
    const turns = context?.turns !== undefined
      ? Number(context.turns || 0)
      : Math.ceil(Number(state.conversation.context_message_count || 0) / 2);
    const status = context?.status || state.conversation.context_status || 'ready';
    const summary = context?.summary_status || (state.conversation.summary_text ? 'ready' : 'empty');
    root.textContent = `${status === 'failed' ? '本轮未参考历史' : `已参考最近 ${Math.ceil(turns)} 轮`} · ${summary === 'empty' ? '摘要尚未生成' : summary === 'updated' ? '摘要已更新' : summary === 'unavailable' ? '摘要暂不可用' : '摘要已就绪'}`;
    root.dataset.status = status;
  }
  function renderMessages() {
    const root = byId('assistant-messages'); root.replaceChildren();
    const messages = [...state.messages];
    if (state.liveUser) messages.push({ role:'user', content:state.liveUser, live:true, created_at:'' });
    if (state.liveAssistant) messages.push({ role:'assistant', content:state.liveAssistant, live:true, created_at:'' });
    if (!messages.length) { root.append(node('p','assistant-empty','点击“开始”后直接说话。')); return; }
    messages.forEach((message) => {
      const card = node('article', `assistant-message ${message.role}${message.live ? ' is-live' : ''}`);
      card.append(node('span','assistant-message-meta', message.role === 'user' ? `你 ${timeText(message.created_at)}` : `齿镜助手 ${timeText(message.created_at)}`));
      card.append(node('div','assistant-message-body',message.content));
      root.append(card);
    });
    root.scrollTop = root.scrollHeight;
  }
  function renderConversations() {
    const root = byId('assistant-conversations'); root.replaceChildren();
    state.conversations.forEach((conversation) => {
      const isActive = conversation.public_id === state.conversation?.public_id;
      const row = node('div', `assistant-conversation-row${isActive ? ' active' : ''}`);
      const button = node('button', `assistant-conversation${isActive ? ' active' : ''}`);
      button.type = 'button'; button.append(node('strong','',conversation.title),node('span','',conversation.updated_at));
      button.addEventListener('click', () => loadAssistant(conversation.public_id));

      const menu = node('details','assistant-conversation-menu');
      const summary = node('summary','assistant-conversation-menu-trigger','⋯');
      summary.setAttribute('aria-label', `管理对话：${conversation.title}`);
      summary.title = '管理对话';
      const actions = node('div','assistant-conversation-menu-panel');
      actions.setAttribute('role','menu');
      [
        ['rename','重命名'],
        ['clear','清空消息'],
        ['delete','删除对话'],
      ].forEach(([mode,label]) => {
        const action = node('button', mode === 'delete' ? 'danger' : '', label);
        action.type = 'button';
        action.setAttribute('role','menuitem');
        action.addEventListener('click', () => openConversationAction(mode, conversation));
        actions.append(action);
      });
      menu.append(summary,actions);
      menu.addEventListener('toggle', () => {
        if (!menu.open) return;
        document.querySelectorAll('.assistant-conversation-menu[open]').forEach((other) => {
          if (other !== menu) other.removeAttribute('open');
        });
      });
      row.append(button,menu);
      root.append(row);
    });
  }
  function closeConversationMenus() {
    document.querySelectorAll('.assistant-conversation-menu[open]').forEach((menu) => menu.removeAttribute('open'));
  }
  function openConversationAction(mode, conversation=state.conversation) {
    if (!conversation) return;
    closeConversationMenus();
    const dialog = byId('assistant-action-dialog');
    const renameField = byId('assistant-rename-field');
    const renameInput = byId('assistant-rename-input');
    const impact = byId('assistant-action-impact');
    const confirmButton = byId('assistant-action-confirm');
    const copy = {
      rename: {
        kicker:'CONVERSATION / RENAME',
        title:'重命名对话',
        description:'修改后的名称只影响当前账号中的对话列表。',
        confirm:'保存名称',
      },
      clear: {
        kicker:'CONVERSATION / CLEAR',
        title:'清空全部消息？',
        description:'对话名称和助手设置会保留，但全部文字消息与历史摘要将被永久清除。',
        confirm:'清空消息',
        impact:'不会删除成员、影像、检测记录，也不会影响其他对话。',
      },
      delete: {
        kicker:'CONVERSATION / DELETE',
        title:'删除这个对话？',
        description:'该对话及其全部消息和历史摘要将被永久删除，无法恢复。',
        confirm:'删除对话',
        impact:'不会删除成员、影像、检测记录，也不会影响设备端的独立对话。',
      },
    }[mode];
    if (!copy) return;
    state.pendingAction = { mode, conversation };
    byId('assistant-action-kicker').textContent = copy.kicker;
    byId('assistant-action-title').textContent = copy.title;
    byId('assistant-action-description').textContent = copy.description;
    byId('assistant-action-message').textContent = '';
    renameField.hidden = mode !== 'rename';
    renameInput.required = mode === 'rename';
    renameInput.value = mode === 'rename' ? conversation.title : '';
    impact.hidden = !copy.impact;
    impact.textContent = copy.impact || '';
    confirmButton.textContent = copy.confirm;
    confirmButton.classList.toggle('assistant-danger-confirm', mode === 'delete');
    dialog.showModal();
    if (mode === 'rename') {
      requestAnimationFrame(() => { renameInput.focus(); renameInput.select(); });
    } else {
      requestAnimationFrame(() => confirmButton.focus());
    }
  }
  async function submitConversationAction() {
    const pending = state.pendingAction;
    if (!pending) return;
    const { mode, conversation } = pending;
    const isActive = conversation.public_id === state.conversation?.public_id;
    if ((mode === 'clear' || mode === 'delete') && isActive) closeSocket();
    let data;
    if (mode === 'rename') {
      data = await request('api/assistant.php?action=rename',{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({conversation_id:conversation.public_id,title:byId('assistant-rename-input').value}),
      });
    } else if (mode === 'clear') {
      data = await request('api/assistant.php?action=clear_messages',{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({conversation_id:conversation.public_id}),
      });
    } else {
      data = await request('api/assistant.php?action=delete',{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({conversation_id:conversation.public_id}),
      });
    }
    byId('assistant-action-dialog').close();
    state.pendingAction = null;
    if (mode === 'delete' && isActive) {
      await loadAssistant(data.conversation.public_id);
    } else {
      await loadAssistant(state.conversation?.public_id || data.conversation?.public_id || '');
    }
    setStatus(mode === 'rename' ? '对话已重命名' : mode === 'clear' ? '消息已清空' : '对话已删除');
  }
  async function loadAssistant(conversationId='') {
    closeSocket();
    setStatus('正在读取对话…');
    const url = `api/assistant.php?action=bootstrap${conversationId ? `&conversation_id=${encodeURIComponent(conversationId)}` : ''}`;
    const data = await request(url);
    state.conversation = data.conversation; state.conversations = data.conversations || []; state.messages = data.messages || []; state.gateway = data.gateway;
    state.context = null; state.memorySettings = data.memory_settings || null; state.isAdmin = Boolean(data.is_admin);
    state.liveUser = null; state.liveAssistant = null; state.savedUser = ''; state.savedAssistant = '';
    byId('assistant-conversation-title').textContent = state.conversation.title;
    renderMessages(); renderConversations(); renderMemoryStatus(); setStatus('准备就绪');
    byId('assistant-voice-title').textContent = '按下开始说话';
    byId('assistant-voice-description').textContent = '模型会自动识别说话结束，并以语音和文字回复。';
  }
  async function saveMessage(role, content) {
    if (!content || (role === 'user' && content === state.savedUser) || (role === 'assistant' && content === state.savedAssistant)) return;
    await request('api/assistant.php?action=save_message', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ conversation_id:state.conversation.public_id, role, content }) });
    if (role === 'user') state.savedUser = content; else state.savedAssistant = content;
    state.messages.push({ role, content, created_at:new Date().toISOString() });
    if (role === 'user') state.liveUser = null; else state.liveAssistant = null;
    renderMessages();
  }
  function ensureAudioContext() {
    if (!state.audioContext) state.audioContext = new (window.AudioContext || window.webkitAudioContext)();
    return state.audioContext;
  }
  function downsampleToPcm16(input, inputRate) {
    const targetRate = 16000;
    const ratio = inputRate / targetRate;
    const length = Math.max(1, Math.round(input.length / ratio));
    const output = new Int16Array(length);
    for (let index = 0; index < length; index += 1) {
      const sourceIndex = Math.min(input.length - 1, Math.floor(index * ratio));
      const sample = Math.max(-1, Math.min(1, input[sourceIndex]));
      output[index] = sample < 0 ? sample * 0x8000 : sample * 0x7fff;
    }
    return output.buffer;
  }
  function playPcm24(base64) {
    try {
      const context = ensureAudioContext();
      const raw = atob(base64); const sampleCount = Math.floor(raw.length / 2);
      const buffer = context.createBuffer(1, sampleCount, 24000); const channel = buffer.getChannelData(0);
      for (let index = 0; index < sampleCount; index += 1) {
        let value = raw.charCodeAt(index * 2) | (raw.charCodeAt(index * 2 + 1) << 8);
        if (value >= 0x8000) value -= 0x10000;
        channel[index] = value / 0x8000;
      }
      const source = context.createBufferSource(); source.buffer = buffer; source.connect(context.destination);
      state.outputAt = Math.max(context.currentTime + 0.04, state.outputAt); source.start(state.outputAt); state.outputAt += buffer.duration;
    } catch (_) { /* A dropped output chunk must not end the conversation. */ }
  }
  function stopCapture() {
    if (state.processor) { state.processor.disconnect(); state.processor.onaudioprocess = null; state.processor = null; }
    if (state.source) { state.source.disconnect(); state.source = null; }
    if (state.mutedGain) { state.mutedGain.disconnect(); state.mutedGain = null; }
    if (state.micStream) { state.micStream.getTracks().forEach((track) => track.stop()); state.micStream = null; }
    state.listening = false;
  }
  function closeSocket() {
    stopCapture();
    if (state.socket && state.socket.readyState === WebSocket.OPEN) state.socket.send(JSON.stringify({type:'control.close'}));
    if (state.socket) { state.socket.onclose = null; state.socket.close(); state.socket = null; }
  }
  function wsUrl() {
    const target = new URL(state.gateway.path, window.location.origin);
    target.protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
    target.searchParams.set('ticket', state.gateway.ticket);
    return target.toString();
  }
  function appendTranscript(role, event, isFinal=false) {
    const fragment = String(event.delta || event.text || event.transcript || '');
    if (!fragment) return;
    if (role === 'user') state.liveUser = isFinal ? fragment : `${state.liveUser || ''}${fragment}`;
    else state.liveAssistant = isFinal ? fragment : `${state.liveAssistant || ''}${fragment}`;
    renderMessages();
  }
  async function handleGatewayEvent(event) {
    const type = event.type || '';
    if (type === 'gateway.context') {
      state.context = event;
      if (typeof event.summary === 'string') {
        state.conversation.summary_text = event.summary;
        if (event.summary) state.conversation.summary_updated_at = new Date().toISOString();
      }
      renderMemoryStatus(event);
      if (event.warning) byId('assistant-voice-description').textContent = event.warning;
      return;
    }
    if (type === 'gateway.ready') { setStatus('正在聆听', 'listening'); return; }
    if (type === 'input_audio_buffer.speech_started') { state.outputAt = ensureAudioContext().currentTime; setStatus('正在聆听', 'listening'); return; }
    if (type === 'input_audio_buffer.speech_stopped') { setStatus('正在理解…'); return; }
    if (type === 'conversation.item.input_audio_transcription.delta') { appendTranscript('user',event); return; }
    if (type === 'conversation.item.input_audio_transcription.completed') {
      appendTranscript('user',event,true); await saveMessage('user',state.liveUser || String(event.transcript || '')); return;
    }
    if (type === 'response.audio.delta' && event.delta) { playPcm24(event.delta); return; }
    if (type === 'response.audio_transcript.delta') { appendTranscript('assistant',event); return; }
    if (type === 'response.audio_transcript.done') {
      appendTranscript('assistant',event,true); await saveMessage('assistant',state.liveAssistant || String(event.transcript || '')); return;
    }
    if (type === 'response.done') { stopCapture(); setStatus('准备就绪'); byId('assistant-voice-title').textContent = '按下开始说话'; return; }
    if (type === 'error') { stopCapture(); showError(event.error?.message || '语音服务暂不可用。'); }
  }
  async function startVoice() {
    if (!window.isSecureContext) { byId('assistant-http-notice').hidden = false; showError('需要 HTTPS 才能使用网页麦克风。'); return; }
    if (!navigator.mediaDevices?.getUserMedia) { showError('当前浏览器不支持网页麦克风。'); return; }
    if (state.listening) { stopCapture(); setStatus('已停止采集，等待回复…'); byId('assistant-voice-title').textContent = '已停止采集'; return; }
    try {
      if (!state.gateway || state.gateway.expires_at - Math.floor(Date.now() / 1000) < 20) await loadAssistant(state.conversation?.public_id || '');
      state.savedUser = ''; state.savedAssistant = ''; state.liveUser = null; state.liveAssistant = null;
      byId('assistant-mic').disabled = true; setStatus('正在连接语音服务…');
      const context = ensureAudioContext(); await context.resume();
      state.micStream = await navigator.mediaDevices.getUserMedia({audio:{channelCount:1,echoCancellation:true,noiseSuppression:true,autoGainControl:true}});
      const socket = new WebSocket(wsUrl()); socket.binaryType = 'arraybuffer'; state.socket = socket;
      socket.onopen = () => {
        state.source = context.createMediaStreamSource(state.micStream);
        state.processor = context.createScriptProcessor(4096, 1, 1);
        state.mutedGain = context.createGain(); state.mutedGain.gain.value = 0;
        state.processor.onaudioprocess = (audio) => {
          if (socket.readyState === WebSocket.OPEN && state.listening) socket.send(downsampleToPcm16(audio.inputBuffer.getChannelData(0), context.sampleRate));
        };
        state.source.connect(state.processor); state.processor.connect(state.mutedGain); state.mutedGain.connect(context.destination);
        state.listening = true; byId('assistant-mic').disabled = false;
        byId('assistant-voice-title').textContent = '正在聆听'; byId('assistant-voice-description').textContent = '说完后稍等片刻，助手会自动回应。'; setStatus('正在聆听', 'listening');
      };
      socket.onmessage = (message) => { try { handleGatewayEvent(JSON.parse(message.data)); } catch (_) {} };
      socket.onerror = () => { stopCapture(); showError('无法连接 AI 语音服务。'); };
      socket.onclose = () => { if (state.listening) { stopCapture(); setStatus('连接已结束'); } byId('assistant-mic').disabled = false; };
    } catch (error) { stopCapture(); showError(error.message || '无法访问麦克风。'); byId('assistant-mic').disabled = false; }
  }
  function setupSettings() {
    const dialog = byId('assistant-settings-dialog'); const form = byId('assistant-settings-form');
    const message = () => byId('assistant-settings-message');
    const refreshSettings = () => {
      byId('assistant-title-input').value = state.conversation.title;
      byId('assistant-instructions-input').value = state.conversation.instructions;
      byId('assistant-memory-enabled').checked = Boolean(Number(state.conversation.memory_enabled));
      byId('assistant-summary-text').textContent = state.conversation.summary_text || '暂无历史摘要。对话超过最近记忆窗口后会自动生成。';
      byId('assistant-summary-meta').textContent = state.conversation.summary_updated_at ? `更新于 ${state.conversation.summary_updated_at}` : '尚未生成摘要';
      byId('assistant-admin-memory').hidden = !state.isAdmin;
      if (state.isAdmin && state.memorySettings) {
        byId('assistant-admin-turns').value = state.memorySettings.context_turns;
        byId('assistant-admin-idle').value = state.memorySettings.device_idle_minutes;
        byId('assistant-admin-retention').value = state.memorySettings.retention_days;
        byId('assistant-admin-model').value = state.memorySettings.summary_model;
      }
      message().textContent = '';
    };
    byId('assistant-settings').addEventListener('click', () => { refreshSettings(); dialog.showModal(); });
    byId('assistant-settings-cancel').addEventListener('click', () => dialog.close());
    form.addEventListener('submit', async (event) => {
      event.preventDefault(); const submit = form.querySelector('[type="submit"]'); submit.disabled = true;
      try {
        const data = await request('api/assistant.php?action=update', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({conversation_id:state.conversation.public_id,title:byId('assistant-title-input').value,instructions:byId('assistant-instructions-input').value,memory_enabled:byId('assistant-memory-enabled').checked})});
        state.conversation = data.conversation; state.gateway = null; closeSocket(); byId('assistant-conversation-title').textContent = data.conversation.title; const item = state.conversations.find((entry) => entry.public_id === data.conversation.public_id); if (item) item.title = data.conversation.title; renderConversations(); dialog.close();
      } catch (error) { byId('assistant-settings-message').textContent = error.message; } finally { submit.disabled = false; }
    });
    byId('assistant-clear-summary').addEventListener('click', async () => {
      if (!confirm('只清除自动摘要？最近消息仍会保留。')) return;
      try {
        const data = await request('api/assistant.php?action=clear_summary',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({conversation_id:state.conversation.public_id})});
        state.conversation=data.conversation; state.gateway=null; state.context=null; refreshSettings(); renderMemoryStatus();
      } catch(error) { message().textContent=error.message; }
    });
    byId('assistant-clear-messages').addEventListener('click', async () => {
      dialog.close();
      openConversationAction('clear',state.conversation);
    });
    byId('assistant-delete-conversation').addEventListener('click', async () => {
      dialog.close();
      openConversationAction('delete',state.conversation);
    });
    byId('assistant-admin-save').addEventListener('click', async () => {
      try {
        const data=await request('api/assistant.php?action=admin_memory_settings',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({
          context_turns:Number(byId('assistant-admin-turns').value),
          summary_max_chars:2000,context_max_chars:12000,
          device_idle_minutes:Number(byId('assistant-admin-idle').value),
          retention_days:Number(byId('assistant-admin-retention').value),
          summary_model:byId('assistant-admin-model').value,
        })});
        state.memorySettings=data.memory_settings; message().textContent='全局记忆设置已保存。';
      } catch(error) { message().textContent=error.message; }
    });
  }
  function setupConversationActions() {
    const dialog = byId('assistant-action-dialog');
    const form = byId('assistant-action-form');
    byId('assistant-action-cancel').addEventListener('click', () => {
      state.pendingAction = null;
      dialog.close();
    });
    dialog.addEventListener('cancel', () => { state.pendingAction = null; });
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const confirmButton = byId('assistant-action-confirm');
      confirmButton.disabled = true;
      byId('assistant-action-message').textContent = '';
      try {
        await submitConversationAction();
      } catch (error) {
        byId('assistant-action-message').textContent = error.message;
      } finally {
        confirmButton.disabled = false;
      }
    });
    document.addEventListener('click', (event) => {
      if (!event.target.closest('.assistant-conversation-menu')) closeConversationMenus();
    });
  }
  window.setupAssistant = async () => {
    byId('assistant-mic').addEventListener('click', startVoice);
    byId('assistant-new').addEventListener('click', async () => { try { const data = await request('api/assistant.php?action=create',{method:'POST'}); await loadAssistant(data.conversation.public_id); } catch (error) { showError(error.message); } });
    setupSettings();
    setupConversationActions();
    if (!window.isSecureContext) byId('assistant-http-notice').hidden = false;
    try { await loadAssistant(); } catch (error) { showError(error.message); }
    window.addEventListener('pagehide', closeSocket, {once:true});
  };
})();
