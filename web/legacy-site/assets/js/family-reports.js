(() => {
  const state = {
    members: [],
    reports: [],
    current: null,
    advancing: false,
    timer: null,
    pendingDelete: null,
    setupStarted: false,
    captureId: '',
    archives: [],
    sourceMode: 'seven_view_archive',
  };
  const el = (id) => document.getElementById(id);
  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  })[char]);
  const post = (action, payload) => request(`api/family_reports.php?action=${action}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
  const riskText = (risk) => ({ low: '较低', medium: '需关注', high: '建议及时复核', unknown: '无法判断' })[risk] || '无法判断';
  const priorityText = (priority) => ({ routine: '常规关注', soon: '尽快复核', urgent: '及时就诊' })[priority] || '建议';
  const statusText = (status) => ({
    processing: '正在生成', waiting_models: '等待本地模型', compiling: '正在汇总',
    completed: '已完成', partial: '部分完成', failed: '生成失败',
  })[status] || status;
  const aiStepText = (status) => ({
    queued: '等待观察', processing: '正在观察', completed: '观察完成', failed: '观察失败',
  })[status] || '等待';
  const modelStepText = (image) => {
    if (image.model_skipped) return '已跳过';
    if (image.model_status === 'completed') return '分析完成';
    if (image.model_status === 'failed') return '分析失败';
    if (image.model_status === 'processing') return image.model_progress_label || '正在运行';
    return '等待本地电脑';
  };
  const imageUrl = (id) => `api/image.php?id=${encodeURIComponent(id || '')}`;
  const finished = (report) => ['completed', 'partial'].includes(report?.status);
  const reportDate = (value) => String(value || '').replace('T', ' ').slice(0, 16);
  const regionOrder = ['left_bite', 'front_bite', 'right_bite', 'upper_left_open', 'upper_right_open', 'lower_left_open', 'lower_right_open'];
  const regionNames = {
    front_bite: '正面咬合', left_bite: '左侧咬合', right_bite: '右侧咬合',
    upper_left_open: '左上牙列', upper_right_open: '右上牙列',
    lower_left_open: '左下牙列', lower_right_open: '右下牙列',
  };
  const sourceLabel = (mode) => mode === 'seven_view_archive' ? '七图全口档案' : '最近图片快速观察';
  const viewName = (image) => image?.capture_region_name || regionNames[image?.capture_region_id] || `照片 ${image?.image_index || ''}`;

  function setMessage(text) {
    el('family-report-message').textContent = text || '';
  }

  function fillMembers() {
    const select = el('family-report-member');
    const previousMember = select.value;
    select.innerHTML = '<option value="">请选择成员</option>';
    state.members.forEach((member) => {
      const option = document.createElement('option');
      option.value = member.public_id;
      option.textContent = `${member.name}${Number(member.is_default) ? '（默认）' : ''}`;
      select.append(option);
    });
    const queryMember = new URLSearchParams(location.search).get('member');
    const captureMember = state.archives.find((item) => item.public_id === state.captureId)?.member_public_id || '';
    const requestedMember = previousMember || queryMember || captureMember;
    const preferred = requestedMember && state.members.some((item) => item.public_id === requestedMember)
      ? requestedMember
      : (state.members.find((item) => Number(item.is_default))?.public_id || '');
    if (!select.value && preferred) select.value = preferred;
    renderSourceSelection();
  }

  function archiveImages(archive) {
    const byRegion = new Map((archive?.images || []).map((image) => [image.region_id, image]));
    return regionOrder.map((regionId) => ({ regionId, image: byRegion.get(regionId) || null }));
  }

  function archiveCard(archive) {
    const selected = state.captureId === archive.public_id;
    const complete = Boolean(archive.is_complete);
    const article = document.createElement('article');
    article.className = `family-archive-card${selected ? ' is-selected' : ''}${complete ? '' : ' is-incomplete'}`;
    article.tabIndex = complete ? 0 : -1;
    article.setAttribute('role', 'radio');
    article.setAttribute('aria-checked', selected ? 'true' : 'false');
    article.setAttribute('aria-disabled', complete ? 'false' : 'true');
    const views = archiveImages(archive).map(({ regionId, image }) => image
      ? `<figure data-region="${regionId}"><img src="${imageUrl(image.public_id)}" alt="${regionNames[regionId]}"><figcaption>${regionNames[regionId]}</figcaption></figure>`
      : `<figure class="is-missing" data-region="${regionId}"><span>缺失</span><figcaption>${regionNames[regionId]}</figcaption></figure>`).join('');
    article.innerHTML = `<div class="family-archive-card-head"><div><strong>${escapeHtml(reportDate(archive.completed_at || archive.updated_at || archive.created_at))}</strong><small>${escapeHtml(archive.device_name || '采集档案')}</small></div><span>${complete ? '7/7 完整' : `${Number(archive.completed_count_actual || 0)}/7`}</span></div><div class="family-archive-contact-sheet">${views}</div><footer><span>${Number(archive.report_count || 0)} 份既有报告</span><b>${selected ? '已选择' : (complete ? '选择此档案' : '补齐后可用')}</b></footer>`;
    if (complete) {
      const choose = () => {
        state.captureId = archive.public_id;
        renderSourceSelection();
        setMessage('已选择七张图片完整的全口采集档案。替换图和本地补图同样有效。');
      };
      article.addEventListener('click', choose);
      article.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); choose(); }
      });
    }
    return article;
  }

  function renderSourceSelection() {
    const memberId = el('family-report-member')?.value || '';
    document.querySelectorAll('input[name="family-source-mode"]').forEach((radio) => {
      radio.checked = radio.value === state.sourceMode;
    });
    el('family-archive-picker').hidden = state.sourceMode !== 'seven_view_archive';
    el('family-quick-source').hidden = state.sourceMode !== 'recent_images';
    const ruleTitle = el('family-report-rule-title');
    const ruleCopy = el('family-report-rule-copy');
    if (ruleTitle) ruleTitle.textContent = state.sourceMode === 'seven_view_archive' ? '完整即可生成' : '最多读取 6 张';
    if (ruleCopy) ruleCopy.textContent = state.sourceMode === 'seven_view_archive'
      ? '仅要求七个标准位置当前都有可读取图片；设备采集、本地补图和替换图均可用。清晰度不足会写入报告限制，但不会阻止生成。'
      : '按时间读取该成员最近上传的 1～6 张图片，保留作为兼容旧数据的快速观察入口。';
    if (state.sourceMode !== 'seven_view_archive') return;
    const root = el('family-archive-list');
    const archives = state.archives.filter((archive) => archive.member_public_id === memberId);
    if (!archives.some((archive) => archive.public_id === state.captureId && archive.is_complete)) {
      state.captureId = archives.find((archive) => archive.is_complete)?.public_id || '';
    }
    el('family-archive-count').textContent = `${archives.filter((item) => item.is_complete).length} 份完整 / ${archives.length} 份全部`;
    root.replaceChildren();
    if (!memberId) {
      root.innerHTML = '<p class="family-report-empty">先选择一位家庭成员。</p>';
      return;
    }
    if (!archives.length) {
      root.innerHTML = '<p class="family-report-empty">该成员还没有七图全口档案。可切换到“最近图片”生成快速观察报告。</p>';
      return;
    }
    archives.forEach((archive) => root.append(archiveCard(archive)));
  }

  function renderHistory() {
    const root = el('family-report-list');
    root.replaceChildren();
    if (!state.reports.length) {
      root.innerHTML = '<p class="family-report-empty">还没有报告。选择成员后可直接生成第一份。</p>';
      return;
    }
    state.reports.forEach((report) => {
      const entry = document.createElement('article');
      entry.className = 'family-report-history-entry';
      const button = document.createElement('button');
      button.type = 'button';
      button.className = `family-report-history-item${state.current?.public_id === report.public_id ? ' is-active' : ''}`;
      button.innerHTML = `<strong>${escapeHtml(report.title)}</strong><span><i>${escapeHtml(report.member_name)} · ${Number(report.image_count)} 张</i><i>${escapeHtml(statusText(report.status))}</i></span><span><i>${escapeHtml(sourceLabel(report.source_mode))}</i><i>${escapeHtml(reportDate(report.created_at))}</i></span>`;
      button.addEventListener('click', () => loadReport(report.public_id));
      const actions = document.createElement('div');
      actions.className = 'family-report-history-actions';
      if (['completed', 'partial'].includes(report.status)) {
        const download = document.createElement('a');
        download.href = `family-report-print.html?id=${encodeURIComponent(report.public_id)}&print=1`;
        download.target = '_blank';
        download.rel = 'noopener';
        download.textContent = '导出 PDF';
        download.setAttribute('aria-label', `导出${report.title}为 PDF`);
        actions.append(download);
      } else {
        const unavailable = document.createElement('span');
        unavailable.textContent = '报告未完成';
        unavailable.title = '报告完成后才能导出 PDF';
        actions.append(unavailable);
      }
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.textContent = '删除';
      remove.setAttribute('aria-label', `删除${report.title}`);
      remove.addEventListener('click', () => prepareDelete(report));
      actions.append(remove);
      entry.append(button, actions);
      root.append(entry);
    });
  }

  function showView(name) {
    el('family-report-welcome').hidden = name !== 'welcome';
    el('family-report-progress').hidden = name !== 'progress';
    el('family-report-document').hidden = name !== 'document';
  }

  function progressItem(index, status, text, label = '') {
    const terminal = ['completed', 'failed'].includes(status);
    const symbol = status === 'completed' ? '✓' : status === 'failed' ? '!' : index;
    return `<div class="family-progress-item is-${escapeHtml(status)}"><i>${symbol}</i><strong>${escapeHtml(label || `照片 ${index}`)}</strong><small>${escapeHtml(text)}${terminal ? '' : ''}</small></div>`;
  }

  function renderProgress(report) {
    showView('progress');
    const progress = report.progress || {};
    const percent = progress.total ? Math.min(100, Math.round(Number(progress.step || 0) / Number(progress.total) * 100)) : 0;
    el('family-progress-title').textContent = report.title || '正在生成报告';
    el('family-progress-label').textContent = report.error_message || report.progress_label || '正在处理…';
    el('family-progress-percent').textContent = `${percent}%`;
    el('family-progress-bar').style.width = `${percent}%`;
    el('family-ai-progress-count').textContent = `${progress.ai_done || 0}/${progress.image_count || 0}`;
    el('family-model-progress-count').textContent = `${progress.model_done || 0}/${progress.image_count || 0}`;
    el('family-ai-progress-items').innerHTML = report.images.map((image) =>
      progressItem(image.image_index, image.ai_status, image.ai_error || aiStepText(image.ai_status), viewName(image))).join('');
    el('family-model-progress-items').innerHTML = report.images.map((image) => {
      let status = 'queued';
      if (image.model_skipped || image.model_status === 'completed') status = 'completed';
      else if (image.model_status === 'failed') status = 'failed';
      else if (image.model_status === 'processing') status = 'processing';
      return progressItem(image.image_index, status, modelStepText(image), viewName(image));
    }).join('');
    const clinicalDone = Boolean(report.clinical_summary);
    const modelDone = Boolean(report.model_summary);
    el('family-summary-step').textContent = clinicalDone ? '✓ 成员级 AI 汇总已完成' : '等待生成成员级 AI 汇总';
    el('family-summary-step').classList.toggle('is-completed', clinicalDone);
    el('family-model-summary-step').textContent = modelDone ? '✓ 实验模型附录已整理' : '等待整理实验模型附录';
    el('family-model-summary-step').classList.toggle('is-completed', modelDone);
    el('family-skip-models').hidden = report.status !== 'waiting_models';
    el('family-retry-report').hidden = report.status !== 'failed';
  }

  function findingHtml(finding) {
    const possibilities = Array.isArray(finding.possibilities) ? finding.possibilities.filter(Boolean).join('、') : '';
    return `<div class="family-image-finding"><strong>${escapeHtml(finding.region || '可见区域')} · ${escapeHtml(finding.finding || '可见表现')}</strong><p>${escapeHtml(finding.evidence || '')}${possibilities ? `；可能性：${escapeHtml(possibilities)}` : ''}</p></div>`;
  }

  function renderImageReports(report) {
    const root = el('family-image-reports');
    root.replaceChildren();
    report.images.forEach((image) => {
      const card = document.createElement('article');
      card.className = 'family-image-report';
      const ai = image.ai_report;
      const quality = ai?.image_quality || {};
      if (!ai) {
        card.innerHTML = `<div class="family-image-photo"><img src="${imageUrl(image.source_public_id)}" alt="${escapeHtml(viewName(image))}"><span>${image.image_index}</span></div><div class="family-image-copy family-image-failed"><h4>${escapeHtml(viewName(image))} · 独立观察未完成</h4><p>${escapeHtml(image.ai_error || '这张照片没有生成可用报告。')}</p></div>`;
      } else {
        const findings = Array.isArray(ai.visible_findings) ? ai.visible_findings : [];
        const qualityText = quality.usable
          ? `影像可用度 ${Number(quality.score || 0)}/100`
          : `影像质量有限 · ${escapeHtml(quality.retake_advice || '建议补拍更清晰角度')}`;
        card.innerHTML = `<div class="family-image-photo"><img src="${imageUrl(image.source_public_id)}" alt="${escapeHtml(viewName(image))}"><span>${image.image_index}</span></div><div class="family-image-copy"><h4>${escapeHtml(viewName(image))} · ${escapeHtml(riskText(ai.overall_risk))}</h4><p>${escapeHtml(ai.summary || '观察已完成。')}</p><div class="family-image-finding"><strong>${qualityText}</strong><p>${escapeHtml((quality.problems || []).join('、') || '未记录明显的成像问题。')}</p></div>${findings.length ? findings.map(findingHtml).join('') : '<div class="family-image-finding"><strong>未记录明确可定位表现</strong><p>这不等于排除口腔问题，仍需结合面诊。</p></div>'}</div>`;
      }
      root.append(card);
    });
  }

  function modelNarrative(report, index) {
    return (report.model_summary?.image_reports || []).find((item) => Number(item.image_index) === Number(index));
  }

  function allFindings(image) {
    const result = image.model_result || {};
    if (Array.isArray(result.findings)) return result.findings;
    return (result.pipeline_results || []).flatMap((stage) => (stage.findings || []).map((finding) => ({
      ...finding,
      pipeline_key: finding.pipeline_key || stage.pipeline,
      pipeline_title: finding.pipeline_title || stage.title,
    })));
  }

  function appendixPolicy(image) {
    const region = String(image?.capture_region_id || '');
    if (['front_bite', 'left_bite', 'right_bite'].includes(region)) {
      return { scope: 'abrasion_calculus', labels: ['abrasion', 'calculus'], pipelines: ['both', 'calculus_seg'] };
    }
    if (['upper_left_open', 'upper_right_open', 'lower_left_open', 'lower_right_open'].includes(region)) {
      return { scope: 'abrasion_microcaries', labels: ['abrasion', 'microcariesdarkline'], pipelines: ['both', 'tooth_outline'] };
    }
    return null;
  }

  function appendixFindings(image) {
    const policy = appendixPolicy(image);
    const findings = allFindings(image);
    return policy ? findings.filter((finding) => policy.labels.includes(String(finding.label || '').toLowerCase())) : findings;
  }

  function appendixNarrative(image, fallback = '') {
    const policy = appendixPolicy(image);
    if (!policy) return fallback || image.model_report_text || '尚无模型说明。';
    if (image.model_skipped) return '本视角已跳过本地模型分析。';
    const findings = appendixFindings(image);
    const count = (label) => findings.filter((item) => String(item.label || '').toLowerCase() === label).length;
    const stages = Array.isArray(image.model_result?.pipeline_results) ? image.model_result.pipeline_results : [];
    const failed = stages.filter((stage) => policy.pipelines.includes(String(stage.pipeline || '')) && stage.status === 'failed');
    const parts = policy.scope === 'abrasion_calculus'
      ? [`牙磨损候选 ${count('abrasion')} 个`, `牙结石候选 ${count('calculus')} 个`]
      : [`牙磨损候选 ${count('abrasion')} 个`, `微龋暗线候选 ${count('microcariesdarkline')} 个`];
    if (failed.length) parts.push(`未完成：${failed.map((stage) => stage.title || stage.pipeline).join('、')}`);
    return `${parts.join('；')}。仅展示该标准视角规定的模型结果。`;
  }

  function findingColor(label) {
    const key = String(label || '').toLowerCase();
    if (key.includes('calculus')) return '#ff9d2e';
    if (key.includes('caries') || key.includes('cavity')) return '#ff4d57';
    if (key.includes('crack')) return '#67b7ff';
    if (key.includes('tooth')) return '#b2b2b2';
    return '#bb6bff';
  }
  const modelLabelText = (label) => ({
    abrasion: '牙磨损候选', calculus: '牙结石候选', microcariesdarkline: '微龋暗线候选',
    caries: '龋齿候选', cavity: '窝洞候选', crack: '裂纹候选', tooth: '牙齿轮廓',
  })[String(label || '').toLowerCase()] || String(label || '候选区域');

  function drawModelOverlay(img, canvas, findings) {
    if (!img.naturalWidth) return;
    canvas.width = img.naturalWidth;
    canvas.height = img.naturalHeight;
    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    findings.filter((finding) => String(finding.label || '').toLowerCase() !== 'tooth').forEach((finding) => {
      const color = findingColor(finding.label);
      const polygon = Array.isArray(finding.polygon) ? finding.polygon : [];
      const box = Array.isArray(finding.bbox_xyxy) ? finding.bbox_xyxy.map(Number) : [];
      if (polygon.length >= 3) {
        ctx.save();
        ctx.beginPath();
        polygon.forEach((point, index) => {
          if (!Array.isArray(point) || point.length < 2) return;
          if (!index) ctx.moveTo(Number(point[0]), Number(point[1]));
          else ctx.lineTo(Number(point[0]), Number(point[1]));
        });
        ctx.closePath();
        ctx.globalAlpha = .24;
        ctx.fillStyle = color;
        ctx.fill();
        ctx.globalAlpha = 1;
        ctx.strokeStyle = color;
        ctx.lineWidth = Math.max(2, canvas.width / 500);
        ctx.stroke();
        ctx.restore();
      }
      if (box.length === 4) {
        const [x1, y1, x2, y2] = box;
        ctx.strokeStyle = color;
        ctx.lineWidth = Math.max(2, canvas.width / 500);
        ctx.strokeRect(x1, y1, x2 - x1, y2 - y1);
        const label = `${modelLabelText(finding.label)} ${Math.round(Number(finding.confidence || 0) * 100)}%`;
        const size = Math.max(12, Math.round(canvas.width / 75));
        ctx.font = `600 ${size}px sans-serif`;
        const width = ctx.measureText(label).width + 12;
        const top = Math.max(0, y1 - size - 10);
        ctx.fillStyle = color;
        ctx.fillRect(x1, top, width, size + 10);
        ctx.fillStyle = '#fff';
        ctx.fillText(label, x1 + 6, top + size + 1);
      }
    });
  }

  function renderModelReports(report) {
    const model = report.model_summary || {};
    el('family-model-notice').textContent = model.notice || '本节仅记录实验性本地模型输出，不参与 AI 牙医独立判断。';
    const sevenView = report.source?.mode === 'seven_view_archive';
    el('family-model-summary').textContent = sevenView
      ? '显示规则：三个咬合视角仅展示牙磨损与牙结石；四个张口牙列视角仅展示牙磨损与微龋暗线。其他模型即使已经运行，也不在本附录中显示。'
      : (model.summary || '没有可整理的本地模型输出。');
    const root = el('family-model-images');
    root.replaceChildren();
    const toothRows = [];
    report.images.forEach((image) => {
      const findings = appendixFindings(image);
      const shown = findings.filter((finding) => String(finding.label || '').toLowerCase() !== 'tooth');
      const tooth = findings.filter((finding) => String(finding.label || '').toLowerCase() === 'tooth');
      if (tooth.length) toothRows.push(`${viewName(image)}：${tooth.length} 个 Tooth 轮廓`);
      const narrative = modelNarrative(report, image.image_index);
      const counts = new Map();
      shown.forEach((finding) => counts.set(finding.label || '候选区域', (counts.get(finding.label || '候选区域') || 0) + 1));
      const card = document.createElement('article');
      card.className = 'family-model-card';
      card.innerHTML = `<div class="family-model-canvas"><img alt="${escapeHtml(viewName(image))}的模型叠加结果" src="${imageUrl(image.source_public_id)}"><canvas aria-hidden="true"></canvas></div><div class="family-model-card-copy"><h4>${escapeHtml(viewName(image))} · ${escapeHtml(narrative?.status || image.model_status || (image.model_skipped ? 'skipped' : 'pending'))}</h4><p>${escapeHtml(appendixNarrative(image, narrative?.narrative || ''))}</p><div class="family-model-tags">${counts.size ? [...counts].map(([name, count]) => `<span>${escapeHtml(modelLabelText(name))} × ${count}</span>`).join('') : '<span>本视角规定项目未检出候选区域</span>'}</div></div>`;
      const img = card.querySelector('img');
      const canvas = card.querySelector('canvas');
      img.addEventListener('load', () => drawModelOverlay(img, canvas, shown), { once: true });
      if (img.complete) drawModelOverlay(img, canvas, shown);
      root.append(card);
    });
    const toothDetails = el('family-tooth-content').closest('details');
    if (toothDetails) toothDetails.hidden = sevenView;
    el('family-tooth-content').innerHTML = toothRows.length
      ? `<p>${escapeHtml(toothRows.join('；'))}。牙体轮廓默认隐藏，以免覆盖其他候选区域。</p>`
      : '<p>本次结果中没有 Tooth 轮廓输出。</p>';
  }

  function renderRecommendations(report) {
    const clinical = report.clinical_summary || {};
    const recommendations = Array.isArray(clinical.recommendations) ? clinical.recommendations : [];
    el('family-recommendations').innerHTML = recommendations.length
      ? recommendations.map((item) => `<div class="family-recommendation"><strong>${escapeHtml(priorityText(item.priority))} · ${escapeHtml(item.action || '建议复核')}</strong><p>${escapeHtml(item.reason || '')}</p></div>`).join('')
      : '<p class="family-report-empty">没有记录额外建议。</p>';
    const limits = Array.isArray(clinical.not_assessable) ? clinical.not_assessable : [];
    el('family-not-assessable').innerHTML = limits.length
      ? limits.map((item) => `<li>${escapeHtml(item)}</li>`).join('')
      : '<li>仅凭口腔照片不能替代面诊、探诊或影像学检查。</li>';
  }

  function renderSevenViewOverview(report) {
    const section = el('family-seven-view-overview');
    const clinical = report.clinical_summary || {};
    const isSevenView = report.source?.mode === 'seven_view_archive';
    section.hidden = !isSevenView;
    if (!isSevenView) return;
    const quality = clinical.collection_quality || {};
    const limited = Array.isArray(quality.limited_views) ? quality.limited_views.filter(Boolean) : [];
    const usable = Number.isFinite(Number(quality.usable_views)) ? Number(quality.usable_views) : Math.max(0, 7 - limited.length);
    el('family-collection-quality').textContent = `${quality.summary || '已完成七个标准视角的联合复核。'} · 可用视角 ${usable}/7${limited.length ? ` · 受限：${limited.join('、')}` : ''}`;
    const regions = Array.isArray(clinical.region_summaries) ? clinical.region_summaries : [];
    el('family-region-summaries').innerHTML = regions.length
      ? regions.map((item) => {
        const observations = Array.isArray(item.observations) ? item.observations.filter(Boolean) : [];
        const limitations = Array.isArray(item.limitations) ? item.limitations.filter(Boolean) : [];
        return `<article><strong>${escapeHtml(item.region || '口腔区域')}</strong><p>${escapeHtml(observations.join('；') || '未记录明确可见表现。')}</p>${limitations.length ? `<small>观察限制：${escapeHtml(limitations.join('；'))}</small>` : ''}</article>`;
      }).join('')
      : '<p class="family-report-empty">本次联合复核没有返回分区摘要，逐视角报告仍可正常查看。</p>';
  }

  function renderDocument(report) {
    showView('document');
    const clinical = report.clinical_summary || {};
    el('family-document-title').textContent = report.title;
    el('family-document-meta').textContent = `${report.member.name} · ${sourceLabel(report.source?.mode)} · ${report.images.length} 张照片 · ${reportDate(report.completed_at || report.created_at)}`;
    const changed = Boolean(report.source?.changed);
    el('family-source-change').hidden = !changed;
    el('family-source-change').innerHTML = changed
      ? '<strong>档案内容已在报告生成后发生变化</strong><span>本页仍展示生成报告时保存的文字结论和图片关联。若要依据替换后的七张图片重新判断，请新建一份口腔综合报告。</span>'
      : '';
    el('family-overall-risk').textContent = riskText(clinical.overall_risk);
    el('family-clinical-text').textContent = clinical.summary || '本次成员级汇总没有可显示内容。';
    el('family-document-disclaimer').textContent = clinical.disclaimer || '本报告仅用于口腔照片辅助筛查，不替代口腔医生面诊、探诊或影像学检查。';
    el('family-report-print').href = `family-report-print.html?id=${encodeURIComponent(report.public_id)}`;
    renderSevenViewOverview(report);
    renderImageReports(report);
    renderRecommendations(report);
    renderModelReports(report);
  }

  function renderCurrent() {
    const report = state.current;
    renderHistory();
    if (!report) {
      showView('welcome');
      return;
    }
    if (finished(report)) renderDocument(report);
    else renderProgress(report);
  }

  async function refreshBootstrap() {
    const select = el('family-report-member');
    if (!state.members.length) {
      select.innerHTML = '<option value="">正在加载家庭成员…</option>';
      select.disabled = true;
    }
    const [memberResult, reportResult] = await Promise.allSettled([
      request('api/members.php?action=list'),
      request('api/family_reports.php?action=bootstrap'),
    ]);
    const memberData = memberResult.status === 'fulfilled' ? memberResult.value : null;
    const reportData = reportResult.status === 'fulfilled' ? reportResult.value : null;
    const members = Array.isArray(memberData?.items)
      ? memberData.items
      : (Array.isArray(reportData?.members) ? reportData.members : []);
    if (!members.length && memberResult.status === 'rejected' && reportResult.status === 'rejected') {
      throw new Error(`成员列表加载失败：${memberResult.reason?.message || reportResult.reason?.message || '请检查登录状态和成员接口。'}`);
    }
    state.members = members;
    state.reports = Array.isArray(reportData?.reports) ? reportData.reports : [];
    state.archives = Array.isArray(reportData?.archives) ? reportData.archives : [];
    select.disabled = false;
    fillMembers();
    renderHistory();
    if (reportResult.status === 'rejected') {
      setMessage(`成员已经载入，但报告历史暂时无法读取：${reportResult.reason?.message || '请检查口腔综合报告数据库迁移。'}`);
    } else if (!state.members.length) {
      setMessage('当前账号没有可用的家庭成员，请先在成员管理页面创建成员。');
    } else if (el('family-report-message').textContent.startsWith('成员')) {
      setMessage('');
    }
  }

  async function loadReport(id, resume = true) {
    clearTimeout(state.timer);
    const data = await request(`api/family_reports.php?action=get&id=${encodeURIComponent(id)}`);
    state.current = data.report;
    renderCurrent();
    if (resume && !finished(state.current) && state.current.status !== 'failed') scheduleAdvance(180);
  }

  function scheduleAdvance(delay = 600) {
    clearTimeout(state.timer);
    state.timer = setTimeout(advanceReport, delay);
  }

  async function advanceReport() {
    if (state.advancing || !state.current || finished(state.current) || state.current.status === 'failed') return;
    state.advancing = true;
    try {
      const data = await post('advance', { report_id: state.current.public_id });
      const wasFinished = finished(state.current);
      state.current = data.report;
      renderCurrent();
      if (finished(state.current)) {
        await refreshBootstrap();
        state.current = data.report;
        renderCurrent();
        const key = `chijing-family-report-complete:${state.current.public_id}`;
        if (!wasFinished && !sessionStorage.getItem(key)) {
          sessionStorage.setItem(key, '1');
          el('family-report-complete').showModal();
        }
      } else if (state.current.status !== 'failed') {
        scheduleAdvance(state.current.status === 'waiting_models' ? 3500 : 450);
      }
    } catch (error) {
      el('family-progress-label').textContent = error.message;
      scheduleAdvance(8000);
    } finally {
      state.advancing = false;
    }
  }

  async function startReport(event) {
    event.preventDefault();
    const memberId = el('family-report-member').value;
    if (!memberId) {
      setMessage('请先选择一位家庭成员。');
      return;
    }
    if (state.sourceMode === 'seven_view_archive' && !state.captureId) {
      setMessage('请选择一份七张图片完整的全口采集档案；若暂无完整档案，可切换到“最近图片”。');
      return;
    }
    setMessage('');
    const button = el('family-report-start');
    button.disabled = true;
    button.textContent = '正在创建任务…';
    try {
      const data = await post('start', {
        member_id: memberId,
        symptoms: el('family-report-symptoms').value.trim(),
        source_mode: state.sourceMode,
        capture_session_id: state.sourceMode === 'seven_view_archive' ? state.captureId : '',
      });
      state.current = data.report;
      renderCurrent();
      await refreshBootstrap();
      state.current = data.report;
      renderCurrent();
      scheduleAdvance(150);
    } catch (error) {
      setMessage(error.message);
    } finally {
      button.disabled = false;
      button.textContent = '生成综合报告';
    }
  }

  async function skipModels() {
    if (!state.current || !confirm('确认跳过本次报告尚未完成的本地模型任务？AI 独立观察仍会保留。')) return;
    el('family-skip-models').disabled = true;
    try {
      const data = await post('skip_models', { report_id: state.current.public_id });
      state.current = data.report;
      renderCurrent();
      scheduleAdvance(100);
    } catch (error) {
      alert(error.message);
    } finally {
      el('family-skip-models').disabled = false;
    }
  }

  async function retryReport() {
    if (!state.current) return;
    const button = el('family-retry-report');
    button.disabled = true;
    try {
      const data = await post('retry', { report_id: state.current.public_id });
      state.current = data.report;
      renderCurrent();
      scheduleAdvance(120);
    } catch (error) {
      alert(error.message);
    } finally {
      button.disabled = false;
    }
  }

  async function renameReport() {
    if (!state.current) return;
    const title = prompt('输入新的报告名称：', state.current.title);
    if (title === null || !title.trim()) return;
    try {
      const data = await post('rename', { report_id: state.current.public_id, title: title.trim() });
      state.current.title = data.title;
      await refreshBootstrap();
      renderCurrent();
    } catch (error) {
      alert(error.message);
    }
  }

  function prepareDelete(report = state.current) {
    if (!report?.public_id) return;
    state.pendingDelete = {
      public_id: report.public_id,
      title: report.title || '这份报告',
    };
    el('family-delete-title').textContent = `删除“${state.pendingDelete.title}”？`;
    el('family-report-confirm-delete').showModal();
  }

  async function confirmDelete() {
    if (!state.pendingDelete) return;
    const button = el('family-delete-confirm');
    button.disabled = true;
    try {
      const target = state.pendingDelete;
      const deletingCurrent = state.current?.public_id === target.public_id;
      await post('delete', { report_id: target.public_id });
      state.pendingDelete = null;
      if (deletingCurrent) state.current = null;
      el('family-report-confirm-delete').close();
      await refreshBootstrap();
      renderCurrent();
    } catch (error) {
      alert(error.message);
    } finally {
      button.disabled = false;
    }
  }

  async function setupFamilyReports() {
    if (state.setupStarted) return;
    state.setupStarted = true;
    state.captureId = new URLSearchParams(location.search).get('capture') || '';
    state.sourceMode = 'seven_view_archive';
    el('family-report-form').addEventListener('submit', startReport);
    el('family-report-member').addEventListener('change', () => {
      state.captureId = '';
      setMessage('');
      renderSourceSelection();
    });
    document.querySelectorAll('input[name="family-source-mode"]').forEach((radio) => {
      radio.addEventListener('change', () => {
        state.sourceMode = radio.value;
        setMessage('');
        renderSourceSelection();
      });
    });
    el('family-report-refresh').addEventListener('click', async () => {
      try {
        await refreshBootstrap();
      } catch (error) {
        setMessage(error.message);
      }
    });
    el('family-skip-models').addEventListener('click', skipModels);
    el('family-retry-report').addEventListener('click', retryReport);
    el('family-report-rename').addEventListener('click', renameReport);
    el('family-report-delete').addEventListener('click', () => prepareDelete());
    el('family-delete-cancel').addEventListener('click', () => {
      state.pendingDelete = null;
      el('family-report-confirm-delete').close();
    });
    el('family-delete-confirm').addEventListener('click', confirmDelete);
    el('family-new-report').addEventListener('click', () => {
      clearTimeout(state.timer);
      state.current = null;
      el('family-report-symptoms').value = '';
      setMessage('');
      renderCurrent();
      scrollTo({ top: 0, behavior: 'smooth' });
    });
    window.addEventListener('pagehide', () => clearTimeout(state.timer), { once: true });
    try {
      await refreshBootstrap();
    } catch (error) {
      const select = el('family-report-member');
      select.disabled = true;
      select.innerHTML = '<option value="">家庭成员加载失败</option>';
      setMessage(error.message);
    }
    const reportId = new URLSearchParams(location.search).get('id');
    if (reportId) await loadReport(reportId);
  }

  window.setupFamilyReports = setupFamilyReports;
  const autoStart = () => setupFamilyReports().catch((error) => setMessage(error.message));
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', autoStart, { once: true });
  } else {
    autoStart();
  }
})();
