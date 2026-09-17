(() => {
  let state = {
    members: [],
    images: [],
    imageCache: new Map(),
    sessions: [],
    selected: [],
    galleryDraft: [],
    galleryPage: 1,
    galleryHasMore: false,
    galleryLoading: false,
    maxImages: 6,
    current: null,
    pendingDelete: null,
    activeImage: 0,
    initialSelectionApplied: false,
    initialSessionApplied: false,
  };
  const el = (id) => document.getElementById(id);
  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);
  const riskLabel = (risk) => ({ low: '低风险', medium: '中等风险', high: '较高风险', unknown: '无法判断' })[risk] || '无法判断';
  const priorityLabel = (priority) => ({ routine: '常规关注', soon: '尽快复核', urgent: '及时就诊' })[priority] || '建议';
  const sourceLabel = (source) => ({ web: '网页上传', device_archive: '设备保存', device_detect: '设备检测' })[source] || '影像记录';
  const statusLabel = (status) => ({ saved: '尚未分析', received: '等待分析', processing: '正在分析', completed: '已有结果', failed: '分析失败' })[status] || '状态未知';
  const debounce = (fn, wait = 260) => {
    let timer;
    return (...args) => {
      clearTimeout(timer);
      timer = setTimeout(() => fn(...args), wait);
    };
  };

  function setFormMessage(text, success = false) {
    const node = el('dentist-form-message');
    node.textContent = text || '';
    node.classList.toggle('is-success', success);
  }

  function selectedMember() {
    return state.members.find((member) => member.public_id === el('dentist-member').value);
  }

  function cacheImages(images) {
    (images || []).forEach((image) => state.imageCache.set(image.public_id, image));
  }

  function imageUrl(id) {
    return `api/image.php?id=${encodeURIComponent(id)}`;
  }

  function renderSelectedImages() {
    const root = el('dentist-selected-images');
    root.replaceChildren();
    const memberId = el('dentist-member').value;
    el('dentist-selection-count').textContent = `已选 ${state.selected.length} 张`;
    el('dentist-open-gallery').disabled = !memberId;
    if (!memberId) {
      root.innerHTML = '<p>选择成员后，从影像库中挑选本次需要观察的照片。</p>';
      return;
    }
    if (!state.selected.length) {
      root.innerHTML = '<p>尚未选择照片。可选择 1～6 张不同角度的清晰口腔照片。</p>';
      return;
    }
    state.selected.forEach((id, index) => {
      const image = state.imageCache.get(id) || { public_id: id, created_at: '' };
      const item = document.createElement('article');
      item.className = 'dentist-selected-item';
      item.innerHTML = `<button class="dentist-selected-preview" type="button" aria-label="查看与编辑第 ${index + 1} 张照片"><img src="${imageUrl(id)}" alt="已选口腔照片 ${index + 1}"><span>${index + 1}</span></button><div><strong>${escapeHtml(sourceLabel(image.source_type))}</strong><small>${escapeHtml(image.created_at || '已选择')}</small></div><button class="dentist-selected-remove" type="button" aria-label="移除第 ${index + 1} 张照片">×</button>`;
      item.querySelector('.dentist-selected-preview').addEventListener('click', () => openPreview(image));
      item.querySelector('.dentist-selected-remove').addEventListener('click', () => {
        state.selected = state.selected.filter((selectedId) => selectedId !== id);
        renderSelectedImages();
      });
      root.append(item);
    });
  }

  function galleryQuery(page) {
    const params = new URLSearchParams({
      action: 'images',
      member_id: el('dentist-member').value,
      page: String(page),
    });
    const source = el('dentist-gallery-source').value;
    const status = el('dentist-gallery-status').value;
    const days = el('dentist-gallery-date').value;
    if (source) params.set('source', source);
    if (status) params.set('status', status);
    if (days) params.set('days', days);
    return `api/ai_dentist.php?${params}`;
  }

  function updateGalleryCount() {
    el('dentist-gallery-count').textContent = `已选 ${state.galleryDraft.length}/${state.maxImages}`;
  }

  function renderGalleryCard(image) {
    const selectedIndex = state.galleryDraft.indexOf(image.public_id);
    const article = document.createElement('article');
    article.className = `dentist-gallery-card${selectedIndex >= 0 ? ' is-selected' : ''}`;
    article.dataset.imageId = image.public_id;
    article.innerHTML = `
      <button class="dentist-gallery-select" type="button" aria-label="${selectedIndex >= 0 ? '取消选择' : '选择'}这张照片">
        <span class="dentist-gallery-number">${selectedIndex >= 0 ? selectedIndex + 1 : ''}</span>
        <img src="${imageUrl(image.public_id)}" alt="上传于 ${escapeHtml(image.created_at)} 的口腔照片" loading="lazy">
      </button>
      <button class="dentist-gallery-zoom" type="button" aria-label="查看与编辑">⌕</button>
      <div class="dentist-gallery-card-meta">
        <div><strong>${escapeHtml(sourceLabel(image.source_type))}</strong><span>${escapeHtml(statusLabel(image.status))}</span></div>
        <small>${escapeHtml(image.created_at)} · ${Number(image.report_count || 0)} 份报告使用</small>
      </div>`;
    article.querySelector('.dentist-gallery-select').addEventListener('click', () => {
      const index = state.galleryDraft.indexOf(image.public_id);
      if (index >= 0) state.galleryDraft.splice(index, 1);
      else if (state.galleryDraft.length < state.maxImages) state.galleryDraft.push(image.public_id);
      else {
        el('dentist-gallery-state').textContent = `每次最多选择 ${state.maxImages} 张照片。`;
        return;
      }
      refreshGallerySelection();
    });
    article.querySelector('.dentist-gallery-zoom').addEventListener('click', () => openPreview(image));
    return article;
  }

  function refreshGallerySelection() {
    document.querySelectorAll('.dentist-gallery-card').forEach((card) => {
      const index = state.galleryDraft.indexOf(card.dataset.imageId);
      card.classList.toggle('is-selected', index >= 0);
      card.querySelector('.dentist-gallery-number').textContent = index >= 0 ? String(index + 1) : '';
      card.querySelector('.dentist-gallery-select').setAttribute('aria-label', index >= 0 ? '取消选择这张照片' : '选择这张照片');
    });
    updateGalleryCount();
  }

  async function loadGallery(reset = false) {
    if (state.galleryLoading || !el('dentist-member').value) return;
    if (!reset && !state.galleryHasMore) return;
    if (reset) {
      state.galleryPage = 1;
      state.galleryHasMore = true;
      el('dentist-gallery-grid').replaceChildren();
    }
    state.galleryLoading = true;
    el('dentist-gallery-state').textContent = state.galleryPage === 1 ? '正在读取照片…' : '正在载入更多照片…';
    try {
      const data = await request(galleryQuery(state.galleryPage));
      cacheImages(data.images);
      data.images.forEach((image) => el('dentist-gallery-grid').append(renderGalleryCard(image)));
      state.galleryHasMore = Boolean(data.has_more);
      state.galleryPage += 1;
      if (!data.total) el('dentist-gallery-state').textContent = '没有符合当前筛选条件的照片。';
      else if (state.galleryHasMore) el('dentist-gallery-state').textContent = `已显示 ${el('dentist-gallery-grid').children.length}/${data.total}，继续向下滚动`;
      else el('dentist-gallery-state').textContent = `已显示全部 ${data.total} 张照片`;
      refreshGallerySelection();
    } catch (error) {
      el('dentist-gallery-state').textContent = error.message;
      state.galleryHasMore = false;
    } finally {
      state.galleryLoading = false;
    }
  }

  function openGallery() {
    const member = selectedMember();
    if (!member) {
      setFormMessage('请先选择家庭成员。');
      return;
    }
    state.galleryDraft = [...state.selected];
    el('dentist-gallery-member').textContent = `${member.name} · 仅显示该成员的影像`;
    el('dentist-photo-modal').hidden = false;
    document.body.classList.add('dentist-modal-open');
    updateGalleryCount();
    loadGallery(true);
    setTimeout(() => el('dentist-gallery-source').focus(), 0);
  }

  function closeGallery() {
    el('dentist-photo-modal').hidden = true;
    if (el('dentist-photo-preview').hidden) document.body.classList.remove('dentist-modal-open');
  }

  function confirmGallery() {
    state.selected = [...state.galleryDraft];
    closeGallery();
    renderSelectedImages();
    setFormMessage(state.selected.length ? `已选择 ${state.selected.length} 张照片。` : '');
  }

  function openPreview(image) {
    if (!image?.public_id) return;
    const back = `ai-dentist.html?member=${encodeURIComponent(el('dentist-member').value || '')}`;
    window.open(`image-editor.html?id=${encodeURIComponent(image.public_id)}&return=${encodeURIComponent(back)}`, '_blank', 'noopener');
  }

  function closePreview() {
    el('dentist-photo-preview').hidden = true;
    el('dentist-preview-image').removeAttribute('src');
    if (el('dentist-photo-modal').hidden) document.body.classList.remove('dentist-modal-open');
  }

  function renderSessions() {
    const root = el('dentist-session-list');
    root.replaceChildren();
    if (!state.sessions.length) { root.innerHTML = '<p>还没有符合条件的 AI 牙医报告。</p>'; return; }
    state.sessions.forEach((session) => {
      const item = document.createElement('article');
      item.className = `dentist-session${state.current?.public_id === session.public_id ? ' is-active' : ''}`;
      item.innerHTML = `
        <button class="dentist-session-open" type="button">
          <span><strong>${escapeHtml(session.title)}</strong><i>${escapeHtml(riskLabel(session.risk_level))}</i></span>
          <small>${escapeHtml(session.member_name || '')} · ${escapeHtml(session.created_at)} · ${Number(session.image_count || 0)} 张照片</small>
          <p>${escapeHtml(session.summary || (session.status === 'failed' ? '分析未完成' : '处理中'))}</p>
        </button>
        <details class="dentist-session-menu">
          <summary aria-label="报告操作">•••</summary>
          <div><button type="button" data-delete-session>删除报告</button></div>
        </details>`;
      item.querySelector('.dentist-session-open').addEventListener('click', () => loadSession(session.public_id));
      item.querySelector('[data-delete-session]').addEventListener('click', () => openDeleteConfirm(session));
      root.append(item);
    });
  }

  function sessionQuery() {
    const params = new URLSearchParams({ action: 'sessions' });
    const member = el('dentist-session-member').value;
    const risk = el('dentist-session-risk').value;
    const days = el('dentist-session-date').value;
    const query = el('dentist-session-search').value.trim();
    if (member) params.set('member_id', member);
    if (risk) params.set('risk', risk);
    if (days) params.set('days', days);
    if (query) params.set('q', query);
    return `api/ai_dentist.php?${params}`;
  }

  async function loadSessions() {
    el('dentist-session-list').innerHTML = '<p>正在读取报告…</p>';
    try {
      const data = await request(sessionQuery());
      state.sessions = data.sessions || [];
      renderSessions();
    } catch (error) {
      el('dentist-session-list').innerHTML = `<p>${escapeHtml(error.message)}</p>`;
    }
  }

  function openDeleteConfirm(session = state.current) {
    if (!session?.public_id) return;
    state.pendingDelete = session;
    el('dentist-delete-title').textContent = `删除“${session.title || '这份报告'}”？`;
    el('dentist-delete-modal').hidden = false;
    document.body.classList.add('dentist-modal-open');
    setTimeout(() => el('dentist-delete-confirm').focus(), 0);
  }

  function closeDeleteConfirm() {
    state.pendingDelete = null;
    el('dentist-delete-modal').hidden = true;
    if (el('dentist-photo-modal').hidden && el('dentist-photo-preview').hidden) document.body.classList.remove('dentist-modal-open');
  }

  async function deletePendingSession() {
    if (!state.pendingDelete) return;
    const target = state.pendingDelete;
    const button = el('dentist-delete-confirm');
    button.disabled = true;
    button.textContent = '正在删除…';
    try {
      const data = await request('api/ai_dentist.php?action=delete_session', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ session_id: target.public_id }),
      });
      const deletingCurrent = state.current?.public_id === target.public_id;
      closeDeleteConfirm();
      if (deletingCurrent) resetCase();
      await loadSessions();
      if (!el('dentist-photo-modal').hidden) await loadGallery(true);
      setFormMessage(data.message || '报告已删除，原始照片仍然保留。', true);
    } catch (error) {
      closeDeleteConfirm();
      setFormMessage(error.message);
    } finally {
      button.disabled = false;
      button.textContent = '删除报告';
    }
  }

  function showMode(mode, shouldScroll = false) {
    const report = el('dentist-report');
    const shell = document.querySelector('.dentist-shell');
    const visible = mode === 'working' || mode === 'report';
    report.hidden = !visible;
    shell.classList.toggle('is-report-active', visible);
    shell.classList.toggle('is-mobile-report', visible);
    shell.classList.remove('is-mobile-editing');
    el('dentist-view-report').hidden = !visible;
    el('dentist-working').hidden = mode !== 'working';
    el('dentist-report-content').hidden = mode !== 'report';
    if (visible && shouldScroll) {
      requestAnimationFrame(() => report.scrollIntoView({ behavior: 'smooth', block: 'start' }));
    }
  }

  function findingMarkers(report, imageIndex) {
    const root = el('dentist-markers');
    root.replaceChildren();
    (report.visible_findings || []).filter((finding) => Number(finding.image_index) === imageIndex + 1).forEach((finding) => {
      const point = finding.relative_position;
      if (!point || !Number.isFinite(Number(point.x)) || !Number.isFinite(Number(point.y))) return;
      const marker = document.createElement('button');
      marker.type = 'button';
      marker.className = 'dentist-marker';
      marker.style.left = `${Math.min(Math.max(Number(point.x), 0), 100)}%`;
      marker.style.top = `${Math.min(Math.max(Number(point.y), 0), 100)}%`;
      marker.textContent = finding.id || '?';
      marker.title = finding.finding || '可见表现';
      root.append(marker);
    });
  }

  function selectReportImage(index) {
    if (!state.current?.images?.[index]) return;
    state.activeImage = index;
    const image = state.current.images[index];
    el('dentist-report-image').src = `api/image.php?id=${encodeURIComponent(image.public_id)}`;
    document.querySelectorAll('.dentist-report-thumb').forEach((button, i) => button.classList.toggle('is-active', i === index));
    findingMarkers(state.current.report || {}, index);
  }

  function renderChat(messages) {
    const root = el('dentist-chat');
    root.replaceChildren();
    (messages || []).forEach((message) => {
      const item = document.createElement('div');
      item.className = `dentist-chat-message${message.role === 'user' ? ' is-user' : ''}`;
      item.innerHTML = `${escapeHtml(message.content).replace(/\n/g, '<br>')}<small>${message.role === 'user' ? '你' : 'AI牙医'} · ${escapeHtml(message.created_at || '')}</small>`;
      root.append(item);
    });
    root.scrollTop = root.scrollHeight;
  }

  function renderReport(session, shouldScroll = true) {
    state.current = session;
    const report = session.report || {};
    el('dentist-report-title').textContent = session.title || 'AI牙医报告';
    el('dentist-risk').textContent = riskLabel(report.overall_risk || session.risk_level);
    el('dentist-summary').textContent = report.summary || session.summary || '分析已完成。';
    el('dentist-disclaimer').textContent = report.disclaimer || '本结果仅用于口腔照片辅助筛查，不替代口腔医生面诊、探诊或影像学检查。';
    el('dentist-download').disabled = false;
    el('dentist-print').disabled = false;
    el('dentist-delete-report').disabled = false;

    const thumbs = el('dentist-report-thumbs');
    thumbs.replaceChildren();
    (session.images || []).forEach((image, index) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = `dentist-report-thumb${index === 0 ? ' is-active' : ''}`;
      button.innerHTML = `<img src="api/image.php?id=${encodeURIComponent(image.public_id)}" alt="口腔照片 ${index + 1}"><span>${index + 1}</span>`;
      button.addEventListener('click', () => selectReportImage(index));
      thumbs.append(button);
    });
    el('dentist-main-image').classList.toggle('is-missing', !(session.images || []).length);
    if (!(session.images || []).length) {
      el('dentist-report-image').removeAttribute('src');
      el('dentist-markers').replaceChildren();
    }

    const quality = report.image_quality || {};
    const problems = Array.isArray(quality.problems) && quality.problems.length ? quality.problems : ['未发现明显影响分析的质量问题'];
    el('dentist-quality').innerHTML = `<div class="dentist-quality-score"><strong>${escapeHtml(quality.score ?? '—')}</strong><span>QUALITY / 100</span></div><div class="dentist-quality-copy"><ul>${problems.map((problem) => `<li>${escapeHtml(problem)}</li>`).join('')}</ul>${quality.retake_advice ? `<p><strong>重拍建议：</strong>${escapeHtml(quality.retake_advice)}</p>` : '<p>当前照片可用于本次辅助观察。</p>'}</div>`;

    const findings = el('dentist-findings');
    const list = Array.isArray(report.visible_findings) ? report.visible_findings : [];
    findings.innerHTML = list.length ? list.map((finding) => `<article class="dentist-finding"><span class="dentist-finding-id">${escapeHtml(finding.id || '—')}</span><div><h4>${escapeHtml(finding.region || '位置未明确')} · ${escapeHtml(finding.finding || '可见表现')}</h4><p>${escapeHtml(finding.evidence || '')}</p><ul>${(finding.possibilities || []).map((item) => `<li>${escapeHtml(item)}</li>`).join('')}</ul></div><span class="dentist-risk-tag">${escapeHtml(riskLabel(finding.risk))}</span></article>`).join('') : '<p class="dentist-message is-success">本次照片中没有形成明确的可见异常条目；这不等于排除所有口腔问题。</p>';

    const recommendations = Array.isArray(report.recommendations) ? report.recommendations : [];
    el('dentist-recommendations').innerHTML = recommendations.length ? recommendations.map((item) => `<article class="dentist-recommendation"><strong>${escapeHtml(priorityLabel(item.priority))} · ${escapeHtml(item.action || '')}</strong><p>${escapeHtml(item.reason || '')}</p></article>`).join('') : '<p class="dentist-message">暂无单独建议。</p>';
    const limitations = Array.isArray(report.not_assessable) ? report.not_assessable : [];
    el('dentist-limitations').innerHTML = limitations.length ? limitations.map((item) => `<li>${escapeHtml(item)}</li>`).join('') : '<li>照片无法替代口腔医生面诊和必要的影像学检查。</li>';
    renderChat(session.messages);
    showMode('report', shouldScroll);
    selectReportImage(0);
    renderSessions();
  }

  async function loadSession(id, shouldScroll = true) {
    try {
      const data = await request(`api/ai_dentist.php?action=session&id=${encodeURIComponent(id)}`);
      renderReport(data.session, shouldScroll);
    } catch (error) { setFormMessage(error.message); }
  }

  async function bootstrap() {
    const data = await request('api/ai_dentist.php?action=bootstrap');
    state.members = data.members || [];
    state.images = data.images || [];
    cacheImages(state.images);
    state.sessions = data.sessions || [];
    state.maxImages = data.preferences?.max_images || 6;
    el('dentist-quota').textContent = data.preferences?.daily_limit > 0 ? `今日额度 ${data.preferences.daily_used}/${data.preferences.daily_limit}` : `今日已调用 ${data.preferences?.daily_used || 0} 次`;
    el('dentist-use-history').checked = Boolean(data.preferences?.include_history_default);
    el('dentist-use-local-results').checked = Boolean(data.preferences?.include_local_results_default);
    document.querySelectorAll('[data-admin-only]').forEach((node) => { node.hidden = !data.is_admin; });
    const memberSelect = el('dentist-member');
    const sessionMemberSelect = el('dentist-session-member');
    const previousMemberId = memberSelect.value;
    memberSelect.replaceChildren(new Option('请选择成员', ''));
    const previousSessionMember = sessionMemberSelect.value;
    sessionMemberSelect.replaceChildren(new Option('全部成员', ''));
    state.members.forEach((member) => {
      const option = new Option(member.name, member.public_id);
      if (member.is_default) option.selected = true;
      memberSelect.append(option);
      sessionMemberSelect.append(new Option(member.name, member.public_id));
    });
    if (state.members.some((member) => member.public_id === previousSessionMember)) sessionMemberSelect.value = previousSessionMember;
    if (!state.initialSelectionApplied) {
      state.initialSelectionApplied = true;
      const requestedParams = new URLSearchParams(location.search);
      const requestedId = requestedParams.get('id');
      const requested = state.images.find((image) => image.public_id === requestedId);
      if (requested) {
        memberSelect.value = requested.member_public_id;
        state.selected = [requested.public_id];
      } else {
        const requestedMember = requestedParams.get('member');
        if (state.members.some((member) => member.public_id === requestedMember)) memberSelect.value = requestedMember;
      }
    } else if (state.members.some((member) => member.public_id === previousMemberId)) {
      memberSelect.value = previousMemberId;
    }
    renderSelectedImages();
    await loadSessions();
    if (!state.initialSessionApplied) {
      state.initialSessionApplied = true;
      const requestedSession = new URLSearchParams(location.search).get('session');
      if (requestedSession) await loadSession(requestedSession, false);
    }
  }

  async function uploadImage(file) {
    const member = selectedMember();
    if (!member) { setFormMessage('请先选择图片所属成员。'); return; }
    if (!file || !['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) { setFormMessage('请选择 JPEG、PNG 或 WebP 图片。'); return; }
    if (file.size > 8 * 1024 * 1024) { setFormMessage('图片超过 8 MB，请压缩后再上传。'); return; }
    const form = new FormData();
    form.append('file', file);
    form.append('member_id', member.public_id);
    form.append('upload_mode', 'archive');
    form.append('model_pipeline', 'dental_seg');
    setFormMessage('正在上传图片…');
    try {
      const data = await request('api/images.php?action=upload', { method: 'POST', body: form });
      await bootstrap();
      el('dentist-member').value = member.public_id;
      state.selected = [data.detection_id];
      const uploaded = state.images.find((image) => image.public_id === data.detection_id);
      if (uploaded) state.imageCache.set(uploaded.public_id, uploaded);
      renderSelectedImages();
      setFormMessage('图片已上传并选中。', true);
    } catch (error) { setFormMessage(error.message); }
  }

  async function analyze(event) {
    event.preventDefault();
    const memberId = el('dentist-member').value;
    if (!memberId) { setFormMessage('请先选择家庭成员。'); return; }
    if (!state.selected.length) { setFormMessage('请至少选择一张口腔照片。'); return; }
    const button = el('dentist-analyze');
    button.disabled = true;
    button.textContent = '正在分析…';
    setFormMessage('');
    el('dentist-report-title').textContent = '正在生成辅助筛查报告';
    el('dentist-delete-report').disabled = true;
    showMode('working', true);
    try {
      const data = await request('api/ai_dentist.php?action=analyze', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          member_id: memberId,
          image_ids: state.selected,
          symptoms: el('dentist-symptoms').value.trim(),
          use_history: el('dentist-use-history').checked,
          use_local_results: el('dentist-use-local-results').checked,
        }),
      });
      state.sessions.unshift({ public_id: data.session.public_id, title: data.session.title, status: data.session.status, risk_level: data.session.risk_level, summary: data.session.summary, created_at: data.session.created_at, member_name: data.session.member.name });
      renderReport(data.session);
      await bootstrap();
      renderReport(data.session, false);
    } catch (error) {
      showMode('empty');
      el('dentist-report-title').textContent = '本次分析未完成';
      setFormMessage(error.message);
    } finally {
      button.disabled = false;
      button.textContent = '开始AI辅助分析';
    }
  }

  async function followUp(event) {
    event.preventDefault();
    if (!state.current) return;
    const question = el('dentist-question').value.trim();
    if (!question) return;
    const button = event.currentTarget.querySelector('button');
    button.disabled = true;
    el('dentist-chat-message').textContent = '正在结合报告回答…';
    try {
      await request('api/ai_dentist.php?action=follow_up', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ session_id: state.current.public_id, question }),
      });
      el('dentist-question').value = '';
      await loadSession(state.current.public_id, false);
      el('dentist-chat-message').textContent = '';
    } catch (error) { el('dentist-chat-message').textContent = error.message; }
    finally { button.disabled = false; }
  }

  function downloadReport() {
    if (!state.current) return;
    const report = state.current.report || {};
    const html = `<!doctype html><meta charset="utf-8"><title>${escapeHtml(state.current.title)}</title><style>body{max-width:760px;margin:40px auto;font:15px/1.7 system-ui;color:#111}h1{font-size:32px}h2{margin-top:28px;border-bottom:1px solid #ddd;padding-bottom:8px}.note{color:#666}</style><h1>${escapeHtml(state.current.title)}</h1><p>成员：${escapeHtml(state.current.member.name)}　时间：${escapeHtml(state.current.created_at)}　综合风险：${escapeHtml(riskLabel(report.overall_risk))}</p><h2>摘要</h2><p>${escapeHtml(report.summary || '')}</p><h2>可见表现</h2>${(report.visible_findings || []).map((item) => `<p><strong>${escapeHtml(item.id)} · ${escapeHtml(item.region)}</strong><br>${escapeHtml(item.finding)}<br><span class="note">${escapeHtml(item.evidence)}</span></p>`).join('') || '<p>没有形成明确条目。</p>'}<h2>建议</h2>${(report.recommendations || []).map((item) => `<p><strong>${escapeHtml(item.action)}</strong><br><span class="note">${escapeHtml(item.reason)}</span></p>`).join('')}<h2>无法判断</h2><ul>${(report.not_assessable || []).map((item) => `<li>${escapeHtml(item)}</li>`).join('')}</ul><p class="note">${escapeHtml(report.disclaimer || '')}</p>`;
    const url = URL.createObjectURL(new Blob([html], { type: 'text/html;charset=utf-8' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = `${state.current.title}-${state.current.created_at.slice(0, 10)}.html`;
    link.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }

  function resetCase() {
    state.current = null;
    state.selected = [];
    el('dentist-symptoms').value = '';
    el('dentist-report-title').textContent = 'AI牙医报告';
    el('dentist-download').disabled = true;
    el('dentist-print').disabled = true;
    el('dentist-delete-report').disabled = true;
    setFormMessage('');
    renderSelectedImages();
    renderSessions();
    showMode('empty');
  }

  window.setupAiDentist = async () => {
    el('dentist-member').addEventListener('change', () => {
      state.selected = [];
      state.galleryDraft = [];
      closeGallery();
      renderSelectedImages();
    });
    el('dentist-upload-input').addEventListener('change', (event) => { const [file] = event.target.files; uploadImage(file); event.target.value = ''; });
    el('dentist-form').addEventListener('submit', analyze);
    el('dentist-chat-form').addEventListener('submit', followUp);
    el('dentist-new').addEventListener('click', resetCase);
    el('dentist-refresh').addEventListener('click', bootstrap);
    el('dentist-open-gallery').addEventListener('click', openGallery);
    el('dentist-gallery-confirm').addEventListener('click', confirmGallery);
    document.querySelectorAll('[data-close-gallery]').forEach((button) => button.addEventListener('click', closeGallery));
    document.querySelectorAll('[data-close-preview]').forEach((button) => button.addEventListener('click', closePreview));
    document.querySelectorAll('[data-cancel-delete]').forEach((button) => button.addEventListener('click', closeDeleteConfirm));
    el('dentist-delete-confirm').addEventListener('click', deletePendingSession);
    el('dentist-delete-report').addEventListener('click', () => openDeleteConfirm(state.current));
    ['dentist-gallery-source', 'dentist-gallery-status', 'dentist-gallery-date'].forEach((id) => {
      el(id).addEventListener('change', () => loadGallery(true));
    });
    el('dentist-gallery-clear').addEventListener('click', () => {
      el('dentist-gallery-source').value = '';
      el('dentist-gallery-status').value = '';
      el('dentist-gallery-date').value = '';
      loadGallery(true);
    });
    el('dentist-gallery-scroll').addEventListener('scroll', () => {
      const root = el('dentist-gallery-scroll');
      if (root.scrollTop + root.clientHeight >= root.scrollHeight - 280) loadGallery(false);
    }, { passive: true });
    ['dentist-session-member', 'dentist-session-risk', 'dentist-session-date'].forEach((id) => {
      el(id).addEventListener('change', loadSessions);
    });
    el('dentist-session-search').addEventListener('input', debounce(loadSessions));
    el('dentist-edit-case').addEventListener('click', () => {
      document.querySelector('.dentist-shell').classList.add('is-mobile-editing');
      document.querySelector('.dentist-intake').scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
    el('dentist-view-report').addEventListener('click', () => {
      document.querySelector('.dentist-shell').classList.remove('is-mobile-editing');
      el('dentist-report').scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
    el('dentist-download').addEventListener('click', downloadReport);
    el('dentist-print').addEventListener('click', () => window.print());
    document.addEventListener('keydown', (event) => {
      if (event.key !== 'Escape') return;
      if (!el('dentist-photo-preview').hidden) closePreview();
      else if (!el('dentist-delete-modal').hidden) closeDeleteConfirm();
      else if (!el('dentist-photo-modal').hidden) closeGallery();
    });
    try { await bootstrap(); } catch (error) { setFormMessage(error.message); el('dentist-session-list').textContent = error.message; }
  };
})();
