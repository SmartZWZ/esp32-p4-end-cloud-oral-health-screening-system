(() => {
  const CLASS_STYLE = {
    Caries: { name: '龋齿', color: '#ff5f68' },
    Cavity: { name: '窝洞', color: '#50d890' },
    Crack: { name: '裂纹', color: '#6f8cff' },
    Tooth: { name: '牙齿', color: '#ffb84d' },
    Abrasion: { name: '磨耗', color: '#bd8cff' },
    Filling: { name: '充填体', color: '#54cfe8' },
    Crown: { name: '牙冠', color: '#ffd166' },
    Calculus: { name: '牙结石', color: '#ff8a4c' },
    MicrocariesDarkline: { name: '浅龋暗线候选', color: '#ff6f61' },
  };
  const PIPELINES = {
    caries: '龋齿候选检测',
    both: '综合检测',
    dental_seg: '口腔四类分割',
    calculus_seg: '牙结石分割',
    tooth_outline: '牙齿轮廓与浅龋暗线',
    all_models: '全部模型联合分析',
  };
  const STATUS = {
    saved: '可以开始分析',
    received: '等待本地工作端',
    processing: '本地模型分析中',
    completed: '分析已完成',
    failed: '分析失败',
  };
  const labState = {
    records: [],
    current: null,
    result: null,
    findings: [],
    classFilter: 'all',
    pipelineFilters: new Map(),
    mode: 'overlay',
    zoom: 1,
    fitWidth: 0,
    fitHeight: 0,
    pollTimer: null,
    highlighted: -1,
    darkline: {
      teeth: [],
      selectedId: 0,
      layer: 'overview',
      job: null,
      pollTimer: null,
      dirty: false,
    },
  };

  const element = (selector) => document.querySelector(selector);
  const styleFor = (label) => CLASS_STYLE[label] || { name: label || '目标', color: '#dce1e5' };
  const parseResult = (item) => {
    if (!item?.result_json) return null;
    try { return typeof item.result_json === 'string' ? JSON.parse(item.result_json) : item.result_json; }
    catch (_) { return null; }
  };
  const pipelineTitle = (pipeline) => PIPELINES[pipeline] || pipeline || '模型分析';
  const formatTime = (value) => value ? String(value).replace('T', ' ').slice(0, 19) : '—';
  const formatPercent = (value) => Number.isFinite(Number(value)) ? `${Math.round(Number(value) * 100)}%` : '—';
  const isPending = (item) => ['received', 'processing'].includes(item?.status);

  function visibleFindings() {
    const threshold = Number(element('#model-confidence-filter')?.value || 25) / 100;
    return labState.findings
      .map((finding, index) => ({ finding, index }))
      .filter(({ finding }) => Number(finding.confidence || 0) >= threshold)
      .filter(({ finding }) => {
        if (labState.current?.model_pipeline !== 'all_models') {
          return labState.classFilter === 'all' || finding.label === labState.classFilter;
        }
        const pipeline = String(finding.pipeline_key || 'unknown');
        const enabled = labState.pipelineFilters.get(pipeline);
        return enabled instanceof Set && enabled.has(String(finding.label || ''));
      });
  }

  function setStatusBadge(item) {
    const badge = element('#model-result-badge');
    badge.className = '';
    if (!item) {
      badge.textContent = '尚无结果';
      return;
    }
    badge.textContent = STATUS[item.status] || item.status;
    if (isPending(item)) badge.classList.add('is-running');
    if (item.status === 'completed') badge.classList.add('is-complete');
    if (item.status === 'failed') badge.classList.add('is-failed');
  }

  function renderRecordMeta() {
    const item = labState.current;
    element('#model-lab-status').textContent = item ? (STATUS[item.status] || item.status) : '等待选择';
    element('#model-lab-member').textContent = item?.member_name || '—';
    element('#model-lab-captured').textContent = formatTime(item?.created_at);
    element('#model-lab-id').textContent = item?.public_id || '—';
    setStatusBadge(item);
    renderJointProgress(item);
  }

  function renderJointProgress(item) {
    const panel = element('#model-joint-progress');
    const isJoint = item?.model_pipeline === 'all_models';
    panel.hidden = !isJoint || (!isPending(item) && !Number(item?.progress_total));
    if (panel.hidden) return;
    const total = Math.max(5, Number(item.progress_total || 5));
    const step = Math.min(total, Math.max(0, Number(item.progress_step || 0)));
    element('#model-progress-label').textContent = item.progress_label || (isPending(item) ? '等待本地模型工作端领取任务。' : '联合分析已结束。');
    element('#model-progress-value').style.width = `${Math.round(step / total * 100)}%`;
    element('#model-progress-stages').querySelectorAll('li').forEach((node, index) => {
      node.classList.toggle('is-done', index + 1 < step || (!isPending(item) && index + 1 <= step));
      node.classList.toggle('is-active', isPending(item) && index + 1 === step);
    });
  }

  function setViewerMode(mode) {
    labState.mode = mode;
    document.querySelectorAll('[data-lab-mode]').forEach((button) => button.classList.toggle('is-active', button.dataset.labMode === mode));
    const stage = element('#model-lab-stage');
    stage?.classList.toggle('is-mask-only', mode === 'mask');
    stage?.classList.toggle('is-original-only', mode === 'original');
    stage?.classList.toggle('is-compare', mode === 'compare');
    element('#model-lab-compare-line').hidden = mode !== 'compare';
    element('#model-compare-control').hidden = mode !== 'compare';
    drawOverlay();
  }

  function updateStageScale() {
    const stage = element('#model-lab-stage');
    if (!stage || !labState.fitWidth || !labState.fitHeight) return;
    stage.style.width = `${Math.round(labState.fitWidth * labState.zoom)}px`;
    stage.style.height = `${Math.round(labState.fitHeight * labState.zoom)}px`;
    element('#model-lab-zoom-value').textContent = `${Math.round(labState.zoom * 100)}%`;
  }

  function fitStage() {
    const viewport = element('#model-lab-viewport');
    const image = element('#model-lab-source');
    if (!viewport || !image.naturalWidth) return;
    const availableWidth = Math.max(160, viewport.clientWidth - 28);
    const availableHeight = Math.max(160, viewport.clientHeight - 28);
    const fit = Math.min(availableWidth / image.naturalWidth, availableHeight / image.naturalHeight);
    labState.fitWidth = Math.round(image.naturalWidth * fit);
    labState.fitHeight = Math.round(image.naturalHeight * fit);
    labState.zoom = 1;
    updateStageScale();
    drawOverlay();
  }

  function changeZoom(delta, absolute = false) {
    labState.zoom = absolute ? delta : Math.min(6, Math.max(.5, labState.zoom + delta));
    updateStageScale();
  }

  function drawLabel(context, text, x, y, color, naturalWidth) {
    const size = Math.max(12, Math.round(naturalWidth / 80));
    context.font = `600 ${size}px Inter, "PingFang SC", sans-serif`;
    const padding = Math.max(5, Math.round(size * .42));
    const height = size + padding * 1.45;
    const width = context.measureText(text).width + padding * 2;
    const top = Math.max(0, y - height);
    context.fillStyle = color;
    context.fillRect(x, top, width, height);
    context.fillStyle = '#07100d';
    context.fillText(text, x + padding, top + size + padding * .35);
  }

  function drawOverlay(force = false) {
    const image = element('#model-lab-source');
    const canvas = element('#model-lab-overlay');
    if (!image?.naturalWidth || !canvas) return;
    canvas.width = image.naturalWidth;
    canvas.height = image.naturalHeight;
    const context = canvas.getContext('2d');
    context.clearRect(0, 0, canvas.width, canvas.height);
    if (labState.mode === 'original' && !force) return;
    const showMasks = element('#model-show-mask').checked;
    const showBoxes = element('#model-show-box').checked;
    const showLabels = element('#model-show-label').checked;
    const opacity = Number(element('#model-mask-opacity').value) / 100;
    const findings = visibleFindings();

    findings.forEach(({ finding, index }) => {
      const style = styleFor(finding.label);
      const polygon = Array.isArray(finding.polygon) ? finding.polygon : [];
      const box = Array.isArray(finding.bbox_xyxy) ? finding.bbox_xyxy.map(Number) : [];
      const highlighted = index === labState.highlighted;
      if (showMasks && polygon.length >= 3) {
        context.save();
        context.beginPath();
        polygon.forEach((point, pointIndex) => {
          if (!Array.isArray(point) || point.length < 2) return;
          if (pointIndex === 0) context.moveTo(Number(point[0]), Number(point[1]));
          else context.lineTo(Number(point[0]), Number(point[1]));
        });
        context.closePath();
        context.globalAlpha = labState.mode === 'mask' ? Math.min(.85, opacity + .35) : opacity;
        context.fillStyle = style.color;
        context.fill();
        context.globalAlpha = 1;
        context.strokeStyle = style.color;
        context.lineWidth = highlighted ? 5 : 2.5;
        context.stroke();
        context.restore();
      }
      if (showBoxes && box.length === 4) {
        const [left, top, right, bottom] = box;
        context.strokeStyle = style.color;
        context.lineWidth = highlighted ? 5 : 2.5;
        context.strokeRect(left, top, Math.max(1, right - left), Math.max(1, bottom - top));
      }
      if (showLabels && box.length === 4) {
        drawLabel(
          context,
          `${style.name} ${formatPercent(finding.confidence)}`,
          Math.max(0, box[0]),
          Math.max(0, box[1]),
          style.color,
          canvas.width,
        );
      }
    });
  }

  function renderDynamicFilters(findings) {
    const isJoint = labState.current?.model_pipeline === 'all_models';
    element('.model-legend').hidden = isJoint;
    element('#model-joint-filters').hidden = !isJoint;
    if (isJoint) {
      renderJointFilters();
      renderClassGrid(findings);
      return;
    }
    const labels = [...new Set(findings.map((item) => String(item.label || '')).filter(Boolean))];
    if (!labels.length && element('#model-lab-pipeline').value === 'dental_seg') labels.push('Caries', 'Cavity', 'Crack', 'Tooth');
    if (!labels.length && element('#model-lab-pipeline').value === 'calculus_seg') labels.push('Calculus');
    if (!labels.length && element('#model-lab-pipeline').value === 'tooth_outline') labels.push('Tooth', 'MicrocariesDarkline');
    const legend = element('.model-legend');
    legend.replaceChildren();
    const all = document.createElement('button');
    all.type = 'button';
    all.dataset.classFilter = 'all';
    all.innerHTML = '<i></i>全部';
    legend.append(all);
    labels.forEach((label) => {
      const style = styleFor(label);
      const button = document.createElement('button');
      button.type = 'button';
      button.dataset.classFilter = label;
      const dot = document.createElement('i');
      dot.style.background = style.color;
      button.append(dot, style.name);
      legend.append(button);
    });
    legend.querySelectorAll('button').forEach((button) => {
      button.classList.toggle('is-active', button.dataset.classFilter === labState.classFilter);
      button.addEventListener('click', () => setClassFilter(button.dataset.classFilter));
    });

    renderClassGrid(findings);
  }

  function renderClassGrid(findings) {
    const labels = [...new Set(findings.map((item) => String(item.label || '')).filter(Boolean))];
    const grid = element('#model-class-grid');
    grid.replaceChildren();
    labels.forEach((label) => {
      const style = styleFor(label);
      const matches = findings.filter((item) => item.label === label);
      const average = matches.length ? matches.reduce((sum, item) => sum + Number(item.confidence || 0), 0) / matches.length : 0;
      const button = document.createElement('button');
      button.type = 'button';
      button.dataset.classCard = label;
      button.style.setProperty('--class-color', style.color);
      button.innerHTML = `<span>${style.name}</span><strong>${matches.length}</strong><small>${label.toUpperCase()} · ${matches.length ? formatPercent(average) : '—'}</small>`;
      button.classList.toggle('is-active', labState.current?.model_pipeline !== 'all_models' && labState.classFilter === label);
      if (labState.current?.model_pipeline !== 'all_models') {
        button.addEventListener('click', () => setClassFilter(labState.classFilter === label ? 'all' : label));
      }
      grid.append(button);
    });
    if (!labels.length) grid.innerHTML = '<p>模型完成后显示分类统计。</p>';
  }

  function initialiseJointFilters() {
    labState.pipelineFilters = new Map();
    const stages = Array.isArray(labState.result?.pipeline_results) ? labState.result.pipeline_results : [];
    stages.forEach((stage) => {
      if (stage?.status !== 'completed') return;
      const labels = [...new Set((stage.findings || []).map((item) => String(item.label || '')).filter(Boolean))];
      labState.pipelineFilters.set(String(stage.pipeline), new Set(labels.filter((label) => label !== 'Tooth')));
    });
  }

  function renderJointFilters() {
    const container = element('#model-joint-filter-groups');
    const stages = Array.isArray(labState.result?.pipeline_results) ? labState.result.pipeline_results : [];
    container.replaceChildren();
    stages.forEach((stage) => {
      const key = String(stage.pipeline || '');
      const labels = [...new Set((stage.findings || []).map((item) => String(item.label || '')).filter(Boolean))];
      const enabled = labState.pipelineFilters.get(key) || new Set();
      const group = document.createElement('section');
      group.className = `model-filter-group${stage.status === 'failed' ? ' is-failed' : ''}`;
      const parent = document.createElement('label');
      parent.className = 'model-filter-parent';
      const parentInput = document.createElement('input');
      parentInput.type = 'checkbox';
      parentInput.disabled = stage.status === 'failed' || !labels.length;
      parentInput.checked = labels.length > 0 && labels.every((label) => enabled.has(label));
      parentInput.indeterminate = !parentInput.checked && labels.some((label) => enabled.has(label));
      const title = document.createElement('span');
      title.textContent = stage.title || pipelineTitle(key);
      const state = document.createElement('small');
      state.textContent = stage.status === 'failed' ? 'FAILED' : `${enabled.size}/${labels.length}`;
      parent.append(parentInput, title, state);
      parentInput.addEventListener('change', () => {
        labState.pipelineFilters.set(key, new Set(parentInput.checked ? labels : []));
        refreshJointView();
      });
      group.append(parent);
      const classes = document.createElement('div');
      classes.className = 'model-filter-classes';
      labels.forEach((label) => {
        const control = document.createElement('label');
        const input = document.createElement('input');
        input.type = 'checkbox';
        input.checked = enabled.has(label);
        const text = document.createElement('span');
        text.textContent = styleFor(label).name;
        input.addEventListener('change', () => {
          const next = new Set(labState.pipelineFilters.get(key) || []);
          if (input.checked) next.add(label); else next.delete(label);
          labState.pipelineFilters.set(key, next);
          refreshJointView();
        });
        control.append(input, text);
        classes.append(control);
      });
      if (!labels.length) classes.textContent = stage.status === 'failed' ? (stage.error || '该流水线运行失败。') : '没有候选区域';
      group.append(classes);
      container.append(group);
    });
    if (!stages.length) container.textContent = '联合分析完成后显示四组结果通道。';
  }

  function refreshJointView() {
    labState.highlighted = -1;
    renderJointFilters();
    renderFindingTable();
    renderVisibleMetrics();
    renderClassGrid(visibleFindings().map(({ finding }) => finding));
    drawOverlay();
  }

  function setClassFilter(filter) {
    labState.classFilter = filter || 'all';
    labState.highlighted = -1;
    document.querySelectorAll('[data-class-filter]').forEach((button) => button.classList.toggle('is-active', button.dataset.classFilter === labState.classFilter));
    document.querySelectorAll('[data-class-card]').forEach((button) => button.classList.toggle('is-active', button.dataset.classCard === labState.classFilter));
    renderFindingTable();
    renderVisibleMetrics();
    drawOverlay();
  }

  function renderVisibleMetrics() {
    const visible = visibleFindings().map(({ finding }) => finding);
    const average = visible.length ? visible.reduce((sum, item) => sum + Number(item.confidence || 0), 0) / visible.length : NaN;
    element('#model-total-findings').textContent = String(visible.length);
    element('#model-average-confidence').textContent = Number.isFinite(average) ? formatPercent(average) : '—';
    const summary = element('#model-visible-summary');
    if (summary) {
      const pipelines = new Set(visible.map((item) => item.pipeline_key).filter(Boolean));
      summary.textContent = `当前显示 ${pipelines.size} 组模型 · ${visible.length} 个区域`;
    }
  }

  function renderFindingTable() {
    const body = element('#model-detail-body');
    body.replaceChildren();
    const visible = visibleFindings();
    element('#model-detail-count').textContent = `${visible.length} 项`;
    if (!visible.length) {
      body.innerHTML = '<tr><td colspan="5">当前筛选条件下没有可显示的候选区域。</td></tr>';
      return;
    }
    visible.forEach(({ finding, index }, position) => {
      const style = styleFor(finding.label);
      const box = Array.isArray(finding.bbox_xyxy) ? finding.bbox_xyxy.map((value) => Math.round(Number(value))) : [];
      const row = document.createElement('tr');
      row.dataset.findingIndex = String(index);
      const labelCell = document.createElement('td');
      labelCell.innerHTML = `<span class="model-detail-label" style="--finding-color:${style.color}"><i></i>${style.name}</span>`;
      row.innerHTML = `<td>${String(position + 1).padStart(2, '0')}</td>`;
      row.append(labelCell);
      const source = finding.pipeline_title ? `${finding.pipeline_title} / ${finding.model || '—'}` : (finding.model || '—');
      row.insertAdjacentHTML('beforeend', `<td>${formatPercent(finding.confidence)}</td><td>${box.length === 4 ? box.join(', ') : '—'}</td><td>${source}</td>`);
      row.addEventListener('click', () => {
        labState.highlighted = labState.highlighted === index ? -1 : index;
        drawOverlay();
      });
      body.append(row);
    });
  }

  function renderInspector() {
    const item = labState.current;
    const result = labState.result;
    const runtime = result?.runtime || {};
    const model = Array.isArray(result?.models) ? result.models[0] : null;
    element('#model-result-title').textContent = item?.status === 'completed'
      ? `${pipelineTitle(item.model_pipeline)}完成`
      : (STATUS[item?.status] || '等待模型分析');
    element('#model-result-summary').textContent = item?.report_text || '选择一张影像并开始分析，完成后这里会显示模型摘要、运行信息和分项统计。';
    renderPipelineReports();
    element('#model-runtime-name').textContent = model?.name || pipelineTitle(item?.model_pipeline);
    const imageWidth = runtime.image_width || item?.image_width;
    const imageHeight = runtime.image_height || item?.image_height;
    element('#model-runtime-size').textContent = imageWidth && imageHeight ? `${imageWidth} × ${imageHeight}` : (model?.imgsz ? `${model.imgsz}px` : '—');
    element('#model-runtime-device').textContent = runtime.device || '本地工作端';
    element('#model-runtime-time').textContent = Number(runtime.inference_ms) > 0 ? `${runtime.inference_ms} ms` : '—';
    renderVisibleMetrics();
  }

  function renderPipelineReports() {
    const panel = element('#model-pipeline-reports');
    const stages = Array.isArray(labState.result?.pipeline_results) ? labState.result.pipeline_results : [];
    panel.hidden = !stages.length;
    panel.replaceChildren();
    stages.forEach((stage) => {
      const card = document.createElement('section');
      card.className = `model-pipeline-report${stage.status === 'failed' ? ' is-failed' : ''}`;
      const header = document.createElement('header');
      const title = document.createElement('strong');
      title.textContent = stage.title || pipelineTitle(stage.pipeline);
      const state = document.createElement('span');
      state.textContent = stage.status === 'failed' ? `失败 · ${stage.attempts || 2} 次` : `完成 · ${stage.attempts || 1} 次`;
      header.append(title, state);
      const copy = document.createElement('p');
      copy.textContent = stage.status === 'failed' ? (stage.error || '运行失败。') : (stage.summary_text || '运行完成。');
      card.append(header, copy);
      panel.append(card);
    });
  }

  const DARKLINE_LAYERS = {
    overview: ['全图定位', '全图定位用于确认当前牙齿在原图中的位置，不参与最终诊断。'],
    tooth: ['单牙原图', '保留牙齿真实颜色并显示模型轮廓，作为所有证据层的原始依据。'],
    normalized: ['光照归一', '校正牙面不均匀照明，使局部暗线不被大范围阴影淹没。'],
    heatmap: ['暗线热力', '融合黑帽响应与线状结构响应；暖色仅代表算法响应较强。'],
    candidate: ['候选暗区', '显示经过阈值、形态和亮度约束后保留的候选区域。'],
    skeleton: ['骨架分叉', '将候选区域细化为中心骨架，并标记端点与分叉点。'],
  };
  const DARKLINE_DEFAULTS = {
    edge_shrink_pct: 6,
    darkness_threshold: .602,
    black_level_pct: 35,
    min_contrast_pct: 5,
    min_width_px: .5,
    min_length_pct: 18,
    smooth_px: 5,
    exclude_highlights: true,
  };

  function darklinePayload() {
    const result = labState.result || {};
    if (result.pipeline === 'tooth_outline_darkline_v3') return result;
    const stages = Array.isArray(result.pipeline_results) ? result.pipeline_results : [];
    return stages.find((stage) => stage?.pipeline === 'tooth_outline' && stage?.status === 'completed') || null;
  }

  function darklineTeeth(payload) {
    if (Array.isArray(payload?.teeth) && payload.teeth.length) return payload.teeth;
    const findings = Array.isArray(payload?.findings) ? payload.findings : [];
    const teeth = new Map();
    findings.filter((item) => item?.label === 'Tooth').forEach((item) => {
      const id = Number(item.navigation_id || 0);
      if (id > 0) teeth.set(id, { ...item, candidate_count: 0, darkline_candidates: [] });
    });
    findings.filter((item) => item?.label === 'MicrocariesDarkline').forEach((item) => {
      const id = Number(item.tooth_navigation_id || 0);
      if (!teeth.has(id)) return;
      const tooth = teeth.get(id);
      tooth.darkline_candidates.push(item);
      tooth.candidate_count = tooth.darkline_candidates.length;
    });
    return [...teeth.values()].sort((a, b) => Number(a.navigation_id) - Number(b.navigation_id));
  }

  function selectedDarklineTooth() {
    return labState.darkline.teeth.find((tooth) => Number(tooth.navigation_id) === Number(labState.darkline.selectedId)) || null;
  }

  function darklineParameters() {
    const form = element('#darkline-params-form');
    if (!form) return { ...DARKLINE_DEFAULTS };
    const data = new FormData(form);
    return {
      edge_shrink_pct: Number(data.get('edge_shrink_pct')),
      darkness_threshold: Number(data.get('darkness_threshold')),
      black_level_pct: Number(data.get('black_level_pct')),
      min_contrast_pct: Number(data.get('min_contrast_pct')),
      min_width_px: Number(data.get('min_width_px')),
      min_length_pct: Number(data.get('min_length_pct')),
      smooth_px: Number(data.get('smooth_px')),
      exclude_highlights: Boolean(data.get('exclude_highlights')),
    };
  }

  function setDarklineParameters(parameters = DARKLINE_DEFAULTS, markClean = true) {
    const form = element('#darkline-params-form');
    if (!form) return;
    Object.entries(DARKLINE_DEFAULTS).forEach(([name, fallback]) => {
      const input = form.elements.namedItem(name);
      if (!input) return;
      const value = parameters[name] ?? fallback;
      if (input.type === 'checkbox') input.checked = Boolean(value);
      else input.value = String(value);
    });
    updateDarklineParamOutputs();
    if (markClean) {
      labState.darkline.dirty = false;
      element('#darkline-param-note').textContent = '参数与当前证据层一致。每次提交只重算当前牙齿。';
    }
  }

  function updateDarklineParamOutputs() {
    const values = darklineParameters();
    const labels = {
      edge_shrink_pct: `${values.edge_shrink_pct.toFixed(1)}%`,
      darkness_threshold: `${(values.darkness_threshold * 100).toFixed(1)}%`,
      black_level_pct: `${values.black_level_pct.toFixed(0)}%`,
      min_contrast_pct: `${values.min_contrast_pct.toFixed(1)}%`,
      min_width_px: `${values.min_width_px.toFixed(1)} px`,
      min_length_pct: `${values.min_length_pct.toFixed(0)}%`,
      smooth_px: `${values.smooth_px.toFixed(0)} px`,
    };
    Object.entries(labels).forEach(([name, text]) => {
      const output = document.querySelector(`[data-param-output="${name}"]`);
      if (output) output.textContent = text;
    });
  }

  function setDarklineState(status, message) {
    const badge = element('.darkline-lab-state');
    badge?.classList.remove('is-running', 'is-complete', 'is-failed');
    if (status) badge?.classList.add(`is-${status}`);
    element('#darkline-lab-status').textContent = message;
  }

  function renderDarklineToothList() {
    const list = element('#darkline-tooth-list');
    list.replaceChildren();
    labState.darkline.teeth.forEach((tooth) => {
      const id = Number(tooth.navigation_id || 0);
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'darkline-tooth-card';
      button.classList.toggle('is-active', id === Number(labState.darkline.selectedId));
      button.innerHTML = `<strong>T${String(id).padStart(2, '0')}</strong><small>${Number(tooth.candidate_count || 0)} 候选</small>`;
      button.addEventListener('click', () => selectDarklineTooth(id));
      list.append(button);
    });
    element('#darkline-tooth-count').textContent = `${labState.darkline.teeth.length} 颗`;
  }

  function renderDarklineOverview() {
    const tooth = selectedDarklineTooth();
    const source = element('#model-lab-source');
    const canvas = element('#darkline-evidence-canvas');
    if (!tooth || !source?.naturalWidth || !canvas) return false;
    const width = source.naturalWidth;
    const height = source.naturalHeight;
    canvas.width = width;
    canvas.height = height;
    const context = canvas.getContext('2d');
    context.drawImage(source, 0, 0, width, height);
    const polygon = Array.isArray(tooth.polygon) ? tooth.polygon : [];
    if (polygon.length >= 3) {
      context.save();
      context.beginPath();
      polygon.forEach((point, index) => index ? context.lineTo(Number(point[0]), Number(point[1])) : context.moveTo(Number(point[0]), Number(point[1])));
      context.closePath();
      context.fillStyle = 'rgba(48,190,255,.18)';
      context.strokeStyle = '#30beff';
      context.lineWidth = Math.max(3, width / 500);
      context.fill();
      context.stroke();
      context.restore();
    }
    canvas.hidden = false;
    element('#darkline-evidence-image').hidden = true;
    element('#darkline-evidence-empty').hidden = true;
    return true;
  }

  function darklineCandidates() {
    const completed = labState.darkline.job?.status === 'completed' ? labState.darkline.job.result : null;
    if (Array.isArray(completed?.candidates)) return completed.candidates;
    const tooth = selectedDarklineTooth();
    return Array.isArray(tooth?.darkline_candidates) ? tooth.darkline_candidates : [];
  }

  function renderDarklineCandidateTable() {
    const candidates = darklineCandidates();
    const body = element('#darkline-candidate-body');
    body.replaceChildren();
    element('#darkline-candidate-count').textContent = `${candidates.length} 项`;
    if (!candidates.length) {
      body.innerHTML = '<tr><td colspan="7">当前参数下没有保留的暗线候选。</td></tr>';
      return;
    }
    candidates.forEach((candidate, index) => {
      const metrics = candidate.metrics || {};
      const row = document.createElement('tr');
      const score = Number(candidate.structure_score ?? candidate.confidence ?? 0);
      const length = Number(candidate.skeleton_length_px ?? metrics.skeleton_length_px ?? 0);
      const width = Number(candidate.median_width_px ?? metrics.median_width_px ?? 0);
      const contrast = Number(candidate.local_contrast_pct ?? metrics.local_contrast_pct ?? 0);
      row.innerHTML = `<td>${candidate.candidate_id || `D${String(index + 1).padStart(2, '0')}`}</td><td>${candidate.kind || '线型'}</td><td>${formatPercent(score)}</td><td>${length || '—'} px</td><td>${width ? width.toFixed(2) : '—'} px</td><td>${contrast ? `${contrast.toFixed(1)}%` : '—'}</td><td>${candidate.evidence_mode || metrics.evidence_mode || '—'}</td>`;
      body.append(row);
    });
  }

  function renderDarklineMetrics() {
    const tooth = selectedDarklineTooth();
    const result = labState.darkline.job?.status === 'completed' ? labState.darkline.job.result : null;
    const candidates = darklineCandidates();
    element('#darkline-metric-candidates').textContent = String(result?.candidate_count ?? candidates.length);
    element('#darkline-metric-length').textContent = Number.isFinite(Number(result?.total_skeleton_length_px)) ? `${result.total_skeleton_length_px} px` : '—';
    element('#darkline-metric-branches').textContent = Number.isFinite(Number(result?.branch_count)) ? String(result.branch_count) : '—';
    element('#darkline-metric-confidence').textContent = formatPercent(result?.tooth_confidence ?? tooth?.confidence);
  }

  function renderDarklineEvidence() {
    const layer = labState.darkline.layer;
    const [title, description] = DARKLINE_LAYERS[layer] || DARKLINE_LAYERS.overview;
    element('#darkline-current-layer').textContent = title;
    element('#darkline-layer-description').textContent = description;
    document.querySelectorAll('[data-darkline-layer]').forEach((button) => button.classList.toggle('is-active', button.dataset.darklineLayer === layer));
    const image = element('#darkline-evidence-image');
    const canvas = element('#darkline-evidence-canvas');
    const empty = element('#darkline-evidence-empty');
    const url = labState.darkline.job?.status === 'completed' ? labState.darkline.job.result?.evidence_urls?.[layer] : '';
    if (url) {
      image.src = url;
      image.hidden = false;
      canvas.hidden = true;
      empty.hidden = true;
      element('#darkline-download-evidence').disabled = false;
    } else if (layer === 'overview' && renderDarklineOverview()) {
      element('#darkline-download-evidence').disabled = true;
    } else {
      image.hidden = true;
      canvas.hidden = true;
      empty.hidden = false;
      empty.innerHTML = '<span>EVIDENCE NOT GENERATED</span><p>调整参数后点击“生成当前牙齿证据层”，本地工作端会回传这一层。</p>';
      element('#darkline-download-evidence').disabled = true;
    }
  }

  function selectDarklineTooth(id) {
    if (labState.darkline.pollTimer) clearTimeout(labState.darkline.pollTimer);
    labState.darkline.selectedId = Number(id);
    labState.darkline.job = null;
    labState.darkline.layer = 'overview';
    const tooth = selectedDarklineTooth();
    element('#darkline-current-tooth').textContent = tooth ? `T${String(id).padStart(2, '0')}` : 'T—';
    renderDarklineToothList();
    renderDarklineCandidateTable();
    renderDarklineMetrics();
    renderDarklineEvidence();
    setDarklineState('', '已选择牙齿，可调整参数生成证据层');
  }

  function renderDarklinePanel() {
    const panel = element('#darkline-lab');
    const payload = darklinePayload();
    const teeth = darklineTeeth(payload);
    panel.hidden = !payload || !teeth.length || labState.current?.status !== 'completed';
    if (panel.hidden) {
      if (labState.darkline.pollTimer) clearTimeout(labState.darkline.pollTimer);
      return;
    }
    labState.darkline.teeth = teeth;
    const models = Array.isArray(payload.models) ? payload.models : [];
    const sourceParams = models.find((model) => model?.name === 'microcaries_darkline_v3')?.parameters;
    setDarklineParameters(sourceParams || DARKLINE_DEFAULTS);
    const selectedExists = teeth.some((tooth) => Number(tooth.navigation_id) === Number(labState.darkline.selectedId));
    labState.darkline.selectedId = selectedExists ? labState.darkline.selectedId : Number(teeth[0].navigation_id || 1);
    labState.darkline.job = null;
    labState.darkline.layer = 'overview';
    element('#darkline-current-tooth').textContent = `T${String(labState.darkline.selectedId).padStart(2, '0')}`;
    renderDarklineToothList();
    renderDarklineCandidateTable();
    renderDarklineMetrics();
    renderDarklineEvidence();
    setDarklineState('', '选择牙齿后可调整参数');
  }

  async function pollDarklineJob(jobId) {
    try {
      const data = await request(`api/tooth_darkline_lab.php?action=status&job_id=${encodeURIComponent(jobId)}`);
      labState.darkline.job = data.job;
      if (data.job.status === 'completed') {
        setDarklineState('complete', `证据层已生成 · ${Number(data.job.result?.processing_ms || 0).toFixed(0)} ms`);
        setDarklineParameters(data.job.parameters || DARKLINE_DEFAULTS);
        renderDarklineCandidateTable();
        renderDarklineMetrics();
        renderDarklineEvidence();
        element('#darkline-run-params').disabled = false;
        return;
      }
      if (data.job.status === 'failed') {
        setDarklineState('failed', data.job.error_message || '证据层生成失败');
        element('#darkline-run-params').disabled = false;
        return;
      }
      setDarklineState('running', data.job.status === 'processing' ? '本地 RTX 正在计算证据层' : '等待本地模型工作端领取任务');
      labState.darkline.pollTimer = setTimeout(() => pollDarklineJob(jobId), 1800);
    } catch (error) {
      setDarklineState('failed', error.message);
      element('#darkline-run-params').disabled = false;
    }
  }

  async function submitDarklineParameters(event) {
    event.preventDefault();
    const tooth = selectedDarklineTooth();
    if (!tooth || !labState.current) return;
    const button = element('#darkline-run-params');
    button.disabled = true;
    setDarklineState('running', '正在创建单牙证据层任务');
    try {
      const data = await request('api/tooth_darkline_lab.php?action=start', {
        method: 'POST',
        body: JSON.stringify({
          detection_id: labState.current.public_id,
          tooth_navigation_id: Number(tooth.navigation_id),
          parameters: darklineParameters(),
        }),
      });
      labState.darkline.job = data.job;
      labState.darkline.dirty = false;
      element('#darkline-param-note').textContent = '参数已提交，等待本地 RTX 返回证据层。';
      pollDarklineJob(data.job.public_id);
    } catch (error) {
      setDarklineState('failed', error.message);
      button.disabled = false;
    }
  }

  function renderResult() {
    const item = labState.current;
    labState.result = parseResult(item);
    labState.findings = Array.isArray(labState.result?.findings) ? labState.result.findings : [];
    labState.classFilter = 'all';
    if (labState.current?.model_pipeline === 'all_models') initialiseJointFilters();
    else labState.pipelineFilters = new Map();
    labState.highlighted = -1;
    renderInspector();
    renderDynamicFilters(labState.findings);
    renderFindingTable();
    drawOverlay();
    renderDarklinePanel();
  }

  function renderImageRail() {
    const rail = element('#model-image-rail');
    rail.replaceChildren();
    labState.records.slice(0, 12).forEach((item) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'model-image-card';
      button.classList.toggle('is-active', item.public_id === labState.current?.public_id);
      const image = document.createElement('img');
      image.src = `api/image.php?id=${encodeURIComponent(item.public_id)}`;
      image.alt = `${item.member_name || '未归属成员'}的口腔影像`;
      image.style.filter = 'none';
      const caption = document.createElement('span');
      caption.innerHTML = `<strong>${item.member_name || '未归属成员'} · ${STATUS[item.status] || item.status}</strong><small>${item.source_type === 'web' ? '本地上传' : '设备采集'} · ${formatTime(item.created_at)}</small>`;
      button.append(image, caption);
      button.addEventListener('click', () => selectRecord(item.public_id));
      rail.append(button);
    });
    if (!labState.records.length) rail.innerHTML = '<p>尚无可用于分析的影像，可通过设备采集或直接上传本地照片。</p>';
  }

  function renderSelect() {
    const select = element('#model-lab-image');
    const selected = labState.current?.public_id;
    select.replaceChildren();
    labState.records.forEach((item) => {
      const option = document.createElement('option');
      option.value = item.public_id;
      option.textContent = `${item.member_name || '未归属成员'} · ${formatTime(item.created_at)} · ${STATUS[item.status] || item.status}`;
      select.append(option);
    });
    if (selected) select.value = selected;
  }

  function loadCurrentImage(item) {
    const image = element('#model-lab-source');
    const stage = element('#model-lab-stage');
    element('#model-lab-empty').hidden = true;
    stage.hidden = false;
    image.onload = () => {
      fitStage();
      if (!element('#darkline-lab')?.hidden) renderDarklineEvidence();
    };
    image.src = `api/image.php?id=${encodeURIComponent(item.public_id)}&v=${encodeURIComponent(item.updated_at || item.created_at || '')}`;
    image.alt = `${item.member_name || '当前成员'}的待检测口腔影像`;
    if (image.complete && image.naturalWidth) fitStage();
  }

  function selectRecord(publicId, options = {}) {
    const item = labState.records.find((record) => record.public_id === publicId) || labState.records[0] || null;
    labState.current = item;
    if (!item) {
      renderRecordMeta();
      setStatusBadge(null);
      return;
    }
    if (!options.fromPoll) {
      const url = new URL(location.href);
      url.searchParams.set('id', item.public_id);
      history.replaceState({}, '', url);
    }
    renderSelect();
    renderRecordMeta();
    renderImageRail();
    loadCurrentImage(item);
    const pipeline = item.status === 'completed' ? item.model_pipeline : (element('#model-lab-pipeline').value || 'dental_seg');
    element('#model-lab-pipeline').value = PIPELINES[pipeline] ? pipeline : 'dental_seg';
    renderResult();
    schedulePoll();
  }

  async function loadRecords(options = {}) {
    const data = await request('api/history.php?limit=100');
    const currentId = options.publicId || labState.current?.public_id || new URLSearchParams(location.search).get('id');
    labState.records = Array.isArray(data.items) ? data.items : [];
    const next = labState.records.find((item) => item.public_id === currentId) || labState.records[0] || null;
    const changed = JSON.stringify(next) !== JSON.stringify(labState.current);
    labState.current = next;
    renderSelect();
    renderRecordMeta();
    renderImageRail();
    if (next && (!options.silent || changed)) {
      loadCurrentImage(next);
      if (!options.silent || changed) {
        const preferredPipeline = next.status === 'completed' ? next.model_pipeline : 'dental_seg';
        element('#model-lab-pipeline').value = PIPELINES[preferredPipeline] ? preferredPipeline : 'dental_seg';
      }
      renderResult();
    }
    schedulePoll();
  }

  function schedulePoll() {
    if (labState.pollTimer) clearTimeout(labState.pollTimer);
    if (!isPending(labState.current)) return;
    labState.pollTimer = setTimeout(() => {
      loadRecords({ silent: true, publicId: labState.current?.public_id }).catch((error) => {
        element('#model-lab-message').textContent = error.message;
        schedulePoll();
      });
    }, 2400);
  }

  async function submitAnalysis(event) {
    event.preventDefault();
    if (!labState.current) return;
    const button = element('#model-lab-run');
    const message = element('#model-lab-message');
    button.disabled = true;
    message.textContent = '正在创建模型任务…';
    try {
      const payload = {
        public_id: element('#model-lab-image').value,
        pipeline: element('#model-lab-pipeline').value,
      };
      const created = await request('api/images.php?action=analyze', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      message.textContent = payload.pipeline === 'all_models'
        ? '已创建联合分析记录，将依次运行五个模型流水线。'
        : '任务已交给本地模型工作端，页面会在完成后自动更新。';
      await loadRecords({ publicId: created.public_id || payload.public_id });
    } catch (error) {
      message.textContent = error.message;
    } finally {
      button.disabled = false;
    }
  }

  function setupViewerControls() {
    document.querySelectorAll('[data-lab-mode]').forEach((button) => button.addEventListener('click', () => setViewerMode(button.dataset.labMode)));
    document.querySelectorAll('[data-lab-zoom]').forEach((button) => button.addEventListener('click', () => {
      if (button.dataset.labZoom === 'fit') fitStage();
      else changeZoom(button.dataset.labZoom === 'in' ? .2 : -.2);
    }));
    ['#model-show-mask', '#model-show-box', '#model-show-label'].forEach((selector) => element(selector).addEventListener('change', drawOverlay));
    element('#model-mask-opacity').addEventListener('input', (event) => {
      element('#model-mask-opacity-value').textContent = `${event.currentTarget.value}%`;
      drawOverlay();
    });
    element('#model-confidence-filter').addEventListener('input', (event) => {
      element('#model-confidence-value').textContent = `${event.currentTarget.value}%`;
      renderFindingTable();
      renderVisibleMetrics();
      drawOverlay();
    });
    element('#model-compare-range').addEventListener('input', (event) => {
      const position = `${event.currentTarget.value}%`;
      element('#model-lab-stage').style.setProperty('--compare-position', position);
    });
    element('#model-download-view').addEventListener('click', downloadCurrentView);
    const viewport = element('#model-lab-viewport');
    viewport.addEventListener('wheel', (event) => {
      if (element('#model-lab-stage').hidden) return;
      event.preventDefault();
      changeZoom(event.deltaY < 0 ? .12 : -.12);
    }, { passive: false });
    let drag = null;
    viewport.addEventListener('pointerdown', (event) => {
      if (labState.zoom <= 1) return;
      drag = { x: event.clientX, y: event.clientY, left: viewport.scrollLeft, top: viewport.scrollTop };
      viewport.setPointerCapture(event.pointerId);
      viewport.classList.add('is-dragging');
    });
    viewport.addEventListener('pointermove', (event) => {
      if (!drag) return;
      viewport.scrollLeft = drag.left - (event.clientX - drag.x);
      viewport.scrollTop = drag.top - (event.clientY - drag.y);
    });
    const stopDrag = () => { drag = null; viewport.classList.remove('is-dragging'); };
    viewport.addEventListener('pointerup', stopDrag);
    viewport.addEventListener('pointercancel', stopDrag);
    window.addEventListener('resize', () => fitStage());
  }

  function downloadCurrentView() {
    const image = element('#model-lab-source');
    const overlay = element('#model-lab-overlay');
    if (!image?.naturalWidth || !overlay) return;
    drawOverlay(true);
    const canvas = document.createElement('canvas');
    canvas.width = image.naturalWidth;
    canvas.height = image.naturalHeight;
    const context = canvas.getContext('2d');
    context.drawImage(image, 0, 0, canvas.width, canvas.height);
    context.drawImage(overlay, 0, 0);
    canvas.toBlob((blob) => {
      if (!blob) return;
      const link = document.createElement('a');
      link.href = URL.createObjectURL(blob);
      link.download = `chijing_${labState.current?.public_id || 'result'}_overlay.png`;
      link.click();
      setTimeout(() => URL.revokeObjectURL(link.href), 1000);
    }, 'image/png');
    drawOverlay();
  }

  function setupDarklineControls() {
    const form = element('#darkline-params-form');
    form.addEventListener('submit', submitDarklineParameters);
    form.addEventListener('input', () => {
      updateDarklineParamOutputs();
      labState.darkline.dirty = true;
      element('#darkline-param-note').textContent = '参数已修改；提交后只重算当前牙齿，旧证据层仍会保留到新结果返回。';
    });
    element('#darkline-reset-params').addEventListener('click', () => {
      setDarklineParameters(DARKLINE_DEFAULTS, false);
      labState.darkline.dirty = true;
      element('#darkline-param-note').textContent = '已恢复桌面版默认参数，点击生成后生效。';
    });
    document.querySelectorAll('[data-darkline-layer]').forEach((button) => button.addEventListener('click', () => {
      labState.darkline.layer = button.dataset.darklineLayer || 'overview';
      renderDarklineEvidence();
    }));
    element('#darkline-download-evidence').addEventListener('click', () => {
      const source = element('#darkline-evidence-image');
      const url = !source.hidden ? source.src : '';
      if (!url) return;
      const link = document.createElement('a');
      link.href = url;
      link.download = `chijing_${labState.current?.public_id || 'result'}_T${String(labState.darkline.selectedId).padStart(2, '0')}_${labState.darkline.layer}.png`;
      link.click();
    });
  }

  async function setupModelLab() {
    setupViewerControls();
    setupDarklineControls();
    element('#model-lab-form').addEventListener('submit', submitAnalysis);
    element('#model-lab-image').addEventListener('change', (event) => selectRecord(event.currentTarget.value));
    element('#model-lab-pipeline').value = 'dental_seg';
    window.addEventListener('pagehide', () => {
      if (labState.pollTimer) clearTimeout(labState.pollTimer);
      if (labState.darkline.pollTimer) clearTimeout(labState.darkline.pollTimer);
    }, { once: true });
    try {
      const members = await request('api/members.php?action=list');
      window.setupLocalImageUpload?.({
        members: members.items || [],
        defaultMode: 'detect',
        onUploaded: async (data) => {
          element('#model-lab-message').textContent = data.upload_mode === 'detect'
            ? '本地影像已上传并进入模型队列，页面会自动更新结果。'
            : '本地影像已上传并选中，可选择模型开始分析。';
          await loadRecords({ publicId: data.detection_id });
        },
      });
      await loadRecords();
      if (!labState.current) element('#model-lab-message').textContent = '当前账号还没有可分析影像，可通过设备采集或点击“上传本地影像”。';
    } catch (error) {
      element('#model-lab-message').textContent = error.message;
    }
  }

  window.setupModelLab = setupModelLab;
})();
