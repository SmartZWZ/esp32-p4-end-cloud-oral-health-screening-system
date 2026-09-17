(() => {
  const state = { memberId: '', data: null, reportFilter: 'family_report', activeTab: 'overview' };
  const el = (id) => document.getElementById(id);
  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  })[char]);
  const formatDate = (value, short = false) => {
    if (!value) return '—';
    const text = String(value).replace('T', ' ');
    return short ? text.slice(0, 10) : text.slice(0, 16);
  };
  const genderText = (value) => ({ male: '男性', female: '女性', unknown: '未填写性别' })[value] || '未填写性别';
  const riskText = (value) => ({ low: '日常关注', medium: '建议复核', high: '建议及时就诊', unknown: '尚未分级' })[value] || '尚未分级';
  const priorityText = (value) => ({ routine: '日常关注', soon: '尽快复核', urgent: '及时就诊' })[value] || '建议';
  const reportTypeText = (value) => ({ ai_dentist: 'AI牙医', family_report: '口腔综合报告', model: '模型记录' })[value] || '报告';
  const pipelineText = (value) => ({
    caries: '龋齿候选检测', both: '综合检测', dental_seg: '口腔四类分割',
    calculus_seg: '牙结石分割', tooth_outline: '牙齿轮廓与浅龋暗线', all_models: '全部模型联合分析',
  })[value] || value || '模型检测';
  const sourceText = (value) => value === 'device' ? '设备采集' : '本地上传';
  const statusText = (value) => ({
    saved: '已保存', received: '已接收', processing: '处理中', completed: '已完成', failed: '失败',
  })[value] || value || '已保存';
  const regionMeta = {
    front_bite: { index: 1, short: '正面', name: '正面咬合' },
    left_bite: { index: 2, short: '左咬合', name: '左侧咬合' },
    right_bite: { index: 3, short: '右咬合', name: '右侧咬合' },
    upper_left_open: { index: 4, short: '左上', name: '左上牙列' },
    upper_right_open: { index: 5, short: '右上', name: '右上牙列' },
    lower_left_open: { index: 6, short: '左下', name: '左下牙列' },
    lower_right_open: { index: 7, short: '右下', name: '右下牙列' },
  };

  async function profileRequest(url) {
    const response = await fetch(url, { headers: { Accept: 'application/json' } });
    const raw = await response.text();
    let data;
    try { data = JSON.parse(raw); } catch (error) { throw new Error('成员档案接口返回了非 JSON 内容。'); }
    if (!response.ok || !data.ok) throw new Error(data.error || '成员档案读取失败。');
    return data;
  }

  function imageUrl(image) {
    return `api/image.php?id=${encodeURIComponent(image.public_id)}`;
  }

  function archiveTitle(archive) {
    return `全口采集 · ${formatDate(archive.completed_at || archive.created_at)}`;
  }

  function reportTitle(report) {
    if (report.type === 'model') return pipelineText(report.pipeline || report.title);
    return report.title || reportTypeText(report.type);
  }

  function archiveSlots(archive, compact = false) {
    const images = new Map((archive.images || []).map((image) => [image.capture_region_id, image]));
    return Object.entries(regionMeta).map(([regionId, meta]) => {
      const image = images.get(regionId);
      const className = `member-archive-slot slot-${meta.index}${image ? '' : ' is-missing'}`;
      if (!image) return `<span class="${className}"><i>${escapeHtml(meta.short)}</i><small>缺失</small></span>`;
      return `<span class="${className}"><img loading="lazy" src="${imageUrl(image)}" alt="${escapeHtml(meta.name)}"><i>${escapeHtml(meta.short)}</i></span>`;
    }).join('') + (compact ? '' : '<span class="member-archive-fold" aria-hidden="true"></span>');
  }

  function renderIdentity() {
    const { member, stats, capture_archives: archives, reports, images } = state.data;
    document.title = `${member.name}的健康档案 · 齿镜`;
    el('member-profile-monogram').textContent = [...member.name][0]?.toUpperCase() || '—';
    el('member-profile-name').textContent = member.name;
    const labels = [member.relationship || '未填写关系', genderText(member.gender)];
    if (member.birth_date) labels.push(`${member.birth_date} 出生`);
    if (Number(member.is_default)) labels.push('默认成员');
    el('member-profile-meta').textContent = labels.join(' · ');
    el('member-profile-report').href = `family-reports.html?member=${encodeURIComponent(member.public_id)}`;
    el('member-profile-ai').href = `ai-dentist.html?member=${encodeURIComponent(member.public_id)}`;
    el('member-stat-archives').textContent = stats.archive_count;
    el('member-stat-images').textContent = stats.image_count;
    el('member-stat-models').textContent = stats.model_report_count;
    el('member-stat-ai').textContent = stats.ai_report_count + stats.family_report_count;
    el('member-tab-archive-count').textContent = `${archives.length} 个档案`;
    el('member-tab-report-count').textContent = `${reports.length} 份记录`;
    el('member-tab-image-count').textContent = `${images.length} 张`;
  }

  function renderAdvice() {
    const report = state.data.latest_advice;
    const list = el('member-advice-list');
    list.replaceChildren();
    if (!report) {
      el('member-advice-title').textContent = '尚无已完成的健康报告';
      el('member-advice-date').textContent = 'NO REPORT';
      el('member-advice-risk').textContent = '等待第一份报告';
      el('member-advice-risk').dataset.risk = 'unknown';
      el('member-advice-summary').textContent = '完成口腔综合报告后，最重要的观察结论和建议会出现在这里。';
      list.innerHTML = '<div class="member-advice-item"><span>下一步</span><div><strong>生成第一份口腔综合报告</strong><p>报告仅用于口腔照片辅助筛查，不替代医生面诊。</p></div></div>';
      el('member-advice-link').hidden = true;
      return;
    }
    el('member-advice-title').textContent = report.title || '最近一次报告建议';
    el('member-advice-date').textContent = formatDate(report.date);
    el('member-advice-risk').textContent = riskText(report.risk);
    el('member-advice-risk').dataset.risk = report.risk || 'unknown';
    el('member-advice-summary').textContent = report.summary || '报告已完成，请查看下方建议。';
    const recommendations = Array.isArray(report.recommendations) ? report.recommendations : [];
    if (recommendations.length) {
      list.innerHTML = recommendations.slice(0, 3).map((item) =>
        `<div class="member-advice-item"><span>${escapeHtml(priorityText(item.priority))}</span><div><strong>${escapeHtml(item.action || '继续观察')}</strong><p>${escapeHtml(item.reason || '')}</p></div></div>`).join('');
    } else {
      list.innerHTML = '<div class="member-advice-item"><span>报告摘要</span><div><strong>本次报告没有单独列出行动建议</strong><p>打开完整报告查看观察内容、局限性及后续说明。</p></div></div>';
    }
    const link = el('member-advice-link');
    link.href = report.url;
    link.hidden = false;
  }

  function renderOverview() {
    const images = state.data.images || [];
    const archives = state.data.capture_archives || [];
    const reports = state.data.reports || [];
    const captureRoot = el('member-recent-capture-content');
    const latestImage = images[0] || null;
    const latestArchive = latestImage?.capture_session_id
      ? archives.find((archive) => (archive.images || []).some((item) => item.public_id === latestImage.public_id))
      : null;
    if (latestArchive) {
      captureRoot.innerHTML = `<a class="member-recent-archive" href="${escapeHtml(latestArchive.url)}"><div class="member-mini-contact">${archiveSlots(latestArchive, true)}</div><div><span>${latestArchive.is_complete ? '完整全口采集' : `资料不完整 ${latestArchive.completed_count}/7`}</span><strong>${escapeHtml(archiveTitle(latestArchive))}</strong><small>${escapeHtml(latestArchive.device_name)} · 查看档案</small></div></a>`;
    } else if (latestImage) {
      captureRoot.innerHTML = `<button class="member-recent-image" type="button"><img src="${imageUrl(latestImage)}" alt="最近一次采集"><span><small>${escapeHtml(sourceText(latestImage.source_type))}</small><strong>${escapeHtml(formatDate(latestImage.created_at))}</strong><i>查看与编辑</i></span></button>`;
      captureRoot.querySelector('button').addEventListener('click', () => openImage(latestImage));
    } else {
      captureRoot.innerHTML = '<p class="member-profile-empty">还没有影像记录。可通过设备采集或本地上传第一张照片。</p>';
    }

    const reportRoot = el('member-recent-report-content');
    const latestReport = reports[0] || null;
    if (!latestReport) {
      reportRoot.innerHTML = `<div class="member-recent-empty"><p>还没有检测报告。</p><a href="${escapeHtml(el('member-profile-report').href)}" class="text-button">生成第一份报告</a></div>`;
    } else {
      reportRoot.innerHTML = `<a class="member-recent-report-link" href="${escapeHtml(latestReport.url)}"><span>${escapeHtml(reportTypeText(latestReport.type))} · ${escapeHtml(formatDate(latestReport.date))}</span><strong>${escapeHtml(reportTitle(latestReport))}</strong><p>${escapeHtml(latestReport.summary || '打开报告查看完整内容。')}</p><i>查看报告 →</i></a>`;
    }
  }

  function renderArchives() {
    const archives = state.data.capture_archives || [];
    el('member-archive-total').textContent = `${archives.length} 个档案袋`;
    const root = el('member-archive-list');
    root.replaceChildren();
    if (!archives.length) {
      root.innerHTML = '<div class="member-profile-empty member-empty-invitation"><strong>还没有全口采集档案</strong><p>在齿镜设备上完成一次七视图采集后，七张照片会自动整理成一个档案袋。</p></div>';
      return;
    }
    archives.forEach((archive) => {
      const link = document.createElement('a');
      link.className = `member-archive-card${archive.is_complete ? '' : ' is-incomplete'}`;
      link.href = archive.url;
      const archLabels={waiting_outline:'轮廓处理中',vision_pending:'等待视觉复核',vision_processing:'视觉复核中',review_required:'牙列待确认',completed:'牙列已绑定',failed:'牙列生成失败',stale:'牙列需更新'};
      const archText=archive.dental_arch?` · ${archLabels[archive.dental_arch.status]||archive.dental_arch.status}`:'';
      link.innerHTML = `<div class="member-archive-cover"><div class="member-archive-contact">${archiveSlots(archive)}</div></div><div class="member-archive-info"><div class="member-archive-status"><span>${archive.is_complete ? '完整采集' : '资料不完整'}${escapeHtml(archText)}</span><strong>${archive.completed_count}/7</strong></div><h3>${escapeHtml(archiveTitle(archive))}</h3><p>${escapeHtml(archive.device_name)}${archive.reference_version ? ` · 参考版本 ${escapeHtml(archive.reference_version)}` : ''}</p><div><small>${archive.dental_arch?.progress_label?escapeHtml(archive.dental_arch.progress_label):(archive.is_complete ? '可生成口腔报告与牙列模型' : '缺失照片，暂不可生成完整报告')}</small><i>打开档案 →</i></div></div>`;
      root.append(link);
    });
  }

  function openImage(image) {
    const back = `member-profile.html?member=${encodeURIComponent(state.memberId)}#images`;
    location.href = `image-editor.html?id=${encodeURIComponent(image.public_id)}&return=${encodeURIComponent(back)}`;
  }

  function renderImages() {
    const images = state.data.images || [];
    el('member-image-total').textContent = `${images.length} 张`;
    const root = el('member-image-grid');
    root.replaceChildren();
    if (!images.length) {
      root.innerHTML = '<p class="member-profile-empty">还没有影像记录。可通过设备采集，或从电脑、手机上传一张口腔照片。</p>';
      return;
    }
    images.forEach((image) => {
      const region = regionMeta[image.capture_region_id];
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'member-image-card';
      button.innerHTML = `<span class="member-image-frame"><img loading="lazy" src="${imageUrl(image)}" alt="${escapeHtml(state.data.member.name)}的口腔照片">${region ? `<i>${escapeHtml(region.short)}</i>` : ''}</span><div><strong>${escapeHtml(formatDate(image.created_at))}</strong><span>${escapeHtml(statusText(image.status))}</span><small>${escapeHtml(sourceText(image.source_type))} · ${Number(image.analysis_count || 0)} 次衍生分析</small></div>`;
      button.addEventListener('click', () => openImage(image));
      root.append(button);
    });
  }

  function renderReports() {
    const reports = (state.data.reports || []).filter((item) => state.reportFilter === 'all' || item.type === state.reportFilter);
    const root = el('member-report-list');
    root.replaceChildren();
    if (!reports.length) {
      const label = state.reportFilter === 'family_report' ? '口腔综合报告' : reportTypeText(state.reportFilter);
      root.innerHTML = `<div class="member-profile-empty member-empty-invitation"><strong>还没有${escapeHtml(label)}</strong><p>切换分类可查看其他记录，或为该成员生成一份新报告。</p></div>`;
      return;
    }
    reports.forEach((report) => {
      const article = document.createElement('article');
      article.className = 'member-report-item';
      article.innerHTML = `<time>${escapeHtml(formatDate(report.date))}</time><div class="member-report-copy"><header><span>${escapeHtml(reportTypeText(report.type))}</span><h3>${escapeHtml(reportTitle(report))}</h3></header><p>${escapeHtml(report.summary || '本次记录没有可显示的文字摘要。')}</p></div><span class="member-report-risk" data-risk="${escapeHtml(report.risk)}">${escapeHtml(riskText(report.risk))}</span><a class="text-button" href="${escapeHtml(report.url)}">查看详情</a>`;
      root.append(article);
    });
  }

  function activateTab(name, focus = false) {
    const valid = ['overview', 'archives', 'reports', 'images'];
    state.activeTab = valid.includes(name) ? name : 'overview';
    document.querySelectorAll('[data-profile-tab]').forEach((button) => {
      const active = button.dataset.profileTab === state.activeTab;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-selected', String(active));
      button.tabIndex = active ? 0 : -1;
      if (active && focus) button.focus();
    });
    document.querySelectorAll('[data-profile-panel]').forEach((panel) => {
      const active = panel.dataset.profilePanel === state.activeTab;
      panel.classList.toggle('is-active', active);
      panel.hidden = !active;
    });
    if (history.replaceState) history.replaceState(null, '', `${location.pathname}${location.search}#${state.activeTab}`);
  }

  function setupTabs() {
    const tabs = [...document.querySelectorAll('[data-profile-tab]')];
    tabs.forEach((button, index) => {
      button.addEventListener('click', () => activateTab(button.dataset.profileTab));
      button.addEventListener('keydown', (event) => {
        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
        event.preventDefault();
        let target = index;
        if (event.key === 'ArrowLeft') target = (index - 1 + tabs.length) % tabs.length;
        if (event.key === 'ArrowRight') target = (index + 1) % tabs.length;
        if (event.key === 'Home') target = 0;
        if (event.key === 'End') target = tabs.length - 1;
        activateTab(tabs[target].dataset.profileTab, true);
      });
    });
  }

  function setupFilters() {
    document.querySelectorAll('[data-report-filter]').forEach((button) => {
      button.addEventListener('click', () => {
        state.reportFilter = button.dataset.reportFilter;
        document.querySelectorAll('[data-report-filter]').forEach((item) => item.classList.toggle('is-active', item === button));
        renderReports();
      });
    });
  }

  async function setup() {
    state.memberId = new URLSearchParams(location.search).get('member') || '';
    if (!state.memberId) throw new Error('没有指定要查看的家庭成员。');
    state.data = await profileRequest(`api/member_profile.php?member=${encodeURIComponent(state.memberId)}`);
    renderIdentity();
    renderAdvice();
    renderOverview();
    renderArchives();
    renderReports();
    renderImages();
    const requestedTab = location.hash.replace('#', '');
    activateTab(requestedTab || 'overview');
  }

  el('member-preview-close').addEventListener('click', () => el('member-image-preview').close());
  el('member-image-preview').addEventListener('click', (event) => {
    if (event.target === el('member-image-preview')) el('member-image-preview').close();
  });
  setupTabs();
  setupFilters();
  setup().catch((error) => {
    el('member-profile-message').textContent = error.message;
    el('member-profile-name').textContent = '成员档案无法读取';
    ['member-archive-list', 'member-image-grid', 'member-report-list'].forEach((id) => {
      el(id).innerHTML = `<p class="member-profile-empty">${escapeHtml(error.message)}</p>`;
    });
  });
})();
