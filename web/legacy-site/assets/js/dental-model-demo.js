(() => {
  'use strict';

  const VIEWS = [
    { id: 'front_bite', label: '正面咬合', yaw: 0, pitch: 0 },
    { id: 'left_bite', label: '左侧咬合', yaw: -0.72, pitch: 0.02 },
    { id: 'right_bite', label: '右侧咬合', yaw: 0.72, pitch: 0.02 },
    { id: 'upper_left_open', label: '左上牙列', yaw: -0.42, pitch: -0.54 },
    { id: 'upper_right_open', label: '右上牙列', yaw: 0.42, pitch: -0.54 },
    { id: 'lower_left_open', label: '左下牙列', yaw: -0.42, pitch: 0.54 },
    { id: 'lower_right_open', label: '右下牙列', yaw: 0.42, pitch: 0.54 },
  ];
  const NUMBERED_TEST_DATASETS = {
    'seven-view-numbered-wx-v1': {
      label: 'WX 标定 V1',
      meta: '2026.08.23 标定 · 73 组单牙视角',
    },
    'seven-view-numbered-v1': {
      label: '编号测试 01',
      meta: '2026.08.14 标定 · 74 组单牙视角',
    },
  };
  const DEFAULT_NUMBERED_TEST_DATASET = 'seven-view-numbered-wx-v1';

  const TOOTH_NAMES = {
    1: '中切牙', 2: '侧切牙', 3: '尖牙', 4: '第一前磨牙',
    5: '第二前磨牙', 6: '第一磨牙', 7: '第二磨牙', 8: '第三磨牙',
  };

  const FDI_ARCHES = {
    upper: [18, 17, 16, 15, 14, 13, 12, 11, 21, 22, 23, 24, 25, 26, 27, 28],
    lower: [48, 47, 46, 45, 44, 43, 42, 41, 31, 32, 33, 34, 35, 36, 37, 38],
  };
  const state = {
    inputs: new Map(),
    activeView: VIEWS[0].id,
    modelReady: false,
    building: false,
    renderer: null,
    anatomicalViewer: null,
    anatomicalReady: false,
    activeLayer: 'anatomical',
    activeArch: 'both',
    testArchiveId: '',
    testManifest: null,
    testArchiveReady: false,
    captureArchiveId: '',
    captureImported: false,
    activeJobId: '',
    activeVersionId: '',
    clinicalManifest: null,
  };

  const q = (selector, root = document) => root.querySelector(selector);
  const qa = (selector, root = document) => [...root.querySelectorAll(selector)];
  const clamp = (value, min, max) => Math.max(min, Math.min(max, value));

  function viewById(id) {
    return VIEWS.find((view) => view.id === id) || VIEWS[0];
  }

  function setActiveView(id, moveModel = true) {
    state.activeView = id;
    qa('.view-slot').forEach((slot) => slot.classList.toggle('active', slot.dataset.view === id));
    qa('[data-preset]').forEach((button) => button.classList.toggle('active', button.dataset.preset === id));
    if (moveModel && state.modelReady && state.activeLayer === 'canvas') state.renderer?.setPreset(viewById(id));
  }

  function toothDetailUrl(tooth) {
    const query = new URLSearchParams({ tooth: String(tooth) });
    const member = q('#model-demo-member')?.value || new URLSearchParams(location.search).get('member') || '';
    const capture = new URLSearchParams(location.search).get('capture') || '';
    if (member) query.set('member', member);
    if (capture) query.set('capture', capture);
    if (state.activeVersionId) query.set('version', state.activeVersionId);
    if (state.testArchiveReady && state.testArchiveId) query.set('dataset', state.testArchiveId);
    return `tooth-detail.html?${query}`;
  }

  function openToothRecord(tooth) {
    if (!FDI_ARCHES.upper.includes(tooth) && !FDI_ARCHES.lower.includes(tooth)) return;
    location.href = toothDetailUrl(tooth);
  }

  function setSelectedTooth(tooth, source = '完整恒牙模型') {
    const quadrant = Math.floor(tooth / 10);
    const jaw = quadrant <= 2 ? '上颌' : '下颌';
    const side = quadrant === 1 || quadrant === 4 ? '右侧' : '左侧';
    q('#selected-tooth-number').textContent = tooth;
    q('#selected-tooth-name').textContent = `${tooth} ${TOOTH_NAMES[tooth % 10] || '恒牙'}`;
    q('#selected-tooth-meta').textContent = `${source} · ${jaw}${side} · 点击后进入该牙的不同视角、时间线和 AI 观察页面。`;
    qa('.fdi-index-tooth').forEach((node) => node.classList.toggle('is-selected', Number(node.dataset.tooth) === tooth));
  }

  function updateInputProgress() {
    const ready = state.inputs.size;
    q('#view-ready-count').textContent = `${ready} / 7`;
    q('#view-progress-bar').style.width = `${ready / 7 * 100}%`;
    q('#build-demo-model').disabled = ready !== 7 || state.building;
    const hasReal = [...state.inputs.values()].some((input) => !input.demo && !input.numberedTest);
    const hasNumbered = [...state.inputs.values()].some((input) => input.numberedTest);
    q('#input-status').textContent = ready === 7
      ? (hasNumbered ? '七张编号测试原图已就绪。坐标与 FDI 牙位已完成离线校验，可生成单牙测试档案。' : (hasReal ? '七个真实视角已就绪。生成时将归档照片并交由本地 RTX 提取逐牙轮廓。' : '七个演示视角已就绪。可生成通用交互模型。'))
      : `还需要 ${7 - ready} 个固定视角。`;
  }

  function markInput(viewId, data) {
    const old = state.inputs.get(viewId);
    if (old?.objectUrl) URL.revokeObjectURL(old.objectUrl);
    state.inputs.set(viewId, data);
    const slot = q(`.view-slot[data-view="${viewId}"]`);
    q('.view-slot-outline', slot)?.remove();
    slot.classList.add('loaded');
    slot.style.setProperty('--slot-image', `url("${data.preview}")`);
    const stateNode = q('.slot-state', slot);
    stateNode.textContent = data.demo ? '示意' : (data.archive ? '档案袋' : '已载入');
    const quality = data.demo ? '演示输入' : `亮度 ${data.brightness} · 清晰 ${data.sharpness}`;
    slot.title = `${data.name}｜${quality}`;
    updateInputProgress();
  }

  function demoPreview(view, index) {
    const canvas = document.createElement('canvas');
    canvas.width = 520;
    canvas.height = 300;
    const ctx = canvas.getContext('2d');
    const gradient = ctx.createLinearGradient(0, 0, canvas.width, canvas.height);
    gradient.addColorStop(0, index % 2 ? '#b7c5cc' : '#d8d2ca');
    gradient.addColorStop(1, index % 2 ? '#546772' : '#867b72');
    ctx.fillStyle = gradient;
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.strokeStyle = 'rgba(255,255,255,.58)';
    ctx.lineWidth = 2;
    ctx.beginPath();
    ctx.ellipse(260, 156, 165, 82, index < 3 ? 0 : (index % 2 ? -.22 : .22), 0, Math.PI * 2);
    ctx.stroke();
    for (let tooth = 0; tooth < 12; tooth += 1) {
      const x = 158 + tooth * 18.5;
      const y = 146 + Math.abs(tooth - 5.5) * 2.1;
      ctx.beginPath();
      ctx.roundRect(x, y, 14, 26, 6);
      ctx.fillStyle = `rgba(248,246,239,${0.36 + tooth * .012})`;
      ctx.fill();
    }
    ctx.fillStyle = 'rgba(255,255,255,.86)';
    ctx.font = '600 14px system-ui';
    ctx.fillText(`0${index + 1}  ${view.label}`, 24, 35);
    ctx.font = '10px ui-monospace, monospace';
    ctx.fillStyle = 'rgba(255,255,255,.6)';
    ctx.fillText('DEMO INPUT / NOT A CLINICAL PHOTO', 24, 55);
    return canvas.toDataURL('image/jpeg', .84);
  }

  async function analyzeFile(file) {
    const objectUrl = URL.createObjectURL(file);
    const image = new Image();
    image.decoding = 'async';
    image.src = objectUrl;
    await image.decode();
    const canvas = document.createElement('canvas');
    const width = 96;
    const height = Math.max(48, Math.round(width * image.naturalHeight / image.naturalWidth));
    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    ctx.drawImage(image, 0, 0, width, height);
    const pixels = ctx.getImageData(0, 0, width, height).data;
    const luminance = new Float32Array(width * height);
    let brightnessSum = 0;
    for (let i = 0; i < luminance.length; i += 1) {
      const offset = i * 4;
      const value = pixels[offset] * .2126 + pixels[offset + 1] * .7152 + pixels[offset + 2] * .0722;
      luminance[i] = value;
      brightnessSum += value;
    }
    let edgeSum = 0;
    for (let y = 1; y < height - 1; y += 1) {
      for (let x = 1; x < width - 1; x += 1) {
        const i = y * width + x;
        edgeSum += Math.abs(luminance[i - 1] - luminance[i + 1]);
        edgeSum += Math.abs(luminance[i - width] - luminance[i + width]);
      }
    }
    return {
      name: file.name,
      preview: objectUrl,
      objectUrl,
      width: image.naturalWidth,
      height: image.naturalHeight,
      brightness: Math.round(brightnessSum / luminance.length / 2.55),
      sharpness: clamp(Math.round(edgeSum / ((width - 2) * (height - 2)) * 3.2), 1, 99),
      file,
      demo: false,
    };
  }

  function setPipelineStep(step) {
    qa('#pipeline-log li').forEach((item, index) => {
      item.classList.toggle('active', index === step);
      item.classList.toggle('done', index < step);
    });
  }

  const wait = (milliseconds) => new Promise((resolve) => window.setTimeout(resolve, milliseconds));

  function parseResultJson(value) {
    if (!value) return null;
    if (typeof value === 'object') return value;
    try { return JSON.parse(value); } catch (error) { return null; }
  }

  function setBuildProgress(percent, text) {
    q('#build-percent').textContent = `${Math.round(percent)}%`;
    q('#build-progress-bar').style.width = `${percent}%`;
    q('#build-step').textContent = text;
    setPipelineStep(Math.min(3, Math.floor(percent / 25)));
  }

  function setModelLayer(layer, displayMode = 'parametric') {
    const stage = q('#model-stage');
    state.activeLayer = layer === 'anatomical' ? 'anatomical' : 'canvas';
    stage.dataset.modelLayer = state.activeLayer;
    state.renderer?.setVisible(state.activeLayer === 'canvas');
    q('#toggle-wireframe').disabled = state.activeLayer === 'anatomical';
    if (state.activeLayer === 'anatomical') {
      q('#model-state-label').textContent = state.anatomicalReady ? '完整恒牙列已就绪' : '正在载入完整恒牙列';
      q('#model-render-mode-label').textContent = '32-TOOTH ANATOMICAL DENTITION · LOCAL GLTF';
      q('#download-model-view').disabled = !state.anatomicalReady;
      return;
    }
    q('#model-state-label').textContent = displayMode === 'measured' ? '实测局部牙面' : '参数牙列';
    q('#model-render-mode-label').textContent = displayMode === 'measured'
      ? 'MEASURED CONTOUR SURFACES · 2.5D'
      : 'PARAMETRIC DENTAL ARCH · WEB CANVAS';
    q('#download-model-view').disabled = false;
    state.renderer?.render();
  }

  function finishBuild(profile = null, label = '演示模型已生成', measuredResults = null) {
    state.building = false;
    state.modelReady = true;
    q('#model-build-overlay').hidden = true;
    q('#model-stage').classList.add('model-ready');
    q('#model-state-label').textContent = label;
    q('#download-model-view').disabled = false;
    q('#build-demo-model').disabled = false;
    q('#build-demo-model').textContent = '重新生成';
    setPipelineStep(4);
    if (profile) state.renderer?.setContourProfile(profile);
    const measuredReady = Array.isArray(measuredResults) && measuredResults.length > 0;
    if (measuredReady) state.renderer?.setMeasuredViews(measuredResults);
    qa('[data-render-mode]').forEach((button) => {
      button.disabled = button.dataset.renderMode === 'measured' && !measuredReady;
      button.classList.toggle('active', button.dataset.renderMode === (measuredReady ? 'measured' : 'parametric'));
    });
    state.renderer?.setDisplayMode(measuredReady ? 'measured' : 'parametric');
    if (measuredReady) setActiveView(VIEWS[0].id);
    setModelLayer('canvas', measuredReady ? 'measured' : 'parametric');
    state.renderer?.render();
  }

  function applyOutlineToSlot(viewId, raw) {
    const slot = q(`.view-slot[data-view="${viewId}"]`);
    q('.view-slot-outline', slot)?.remove();
    const width = Number(raw?.runtime?.image_width || 1);
    const height = Number(raw?.runtime?.image_height || 1);
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.classList.add('view-slot-outline');
    svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
    svg.setAttribute('preserveAspectRatio', 'xMidYMid slice');
    (raw?.teeth || []).forEach((tooth) => {
      if (!Array.isArray(tooth.polygon) || tooth.polygon.length < 3) return;
      const polygon = document.createElementNS(svg.namespaceURI, 'polygon');
      polygon.setAttribute('points', tooth.polygon.map((point) => point.join(',')).join(' '));
      svg.append(polygon);
      (tooth.darkline_candidates || []).forEach((candidate) => {
        if (!Array.isArray(candidate.skeleton_xy) || candidate.skeleton_xy.length < 2) return;
        const line = document.createElementNS(svg.namespaceURI, 'polyline');
        line.setAttribute('points', candidate.skeleton_xy.map((point) => point.join(',')).join(' '));
        svg.append(line);
      });
    });
    slot.append(svg);
    q('.slot-state', slot).textContent = `${raw?.counts?.Tooth || 0} 颗`;
  }

  function median(values) {
    const sorted = values.filter(Number.isFinite).sort((a, b) => a - b);
    if (!sorted.length) return 1;
    const middle = Math.floor(sorted.length / 2);
    return sorted.length % 2 ? sorted[middle] : (sorted[middle - 1] + sorted[middle]) / 2;
  }

  function contourProfile(results) {
    const summaries = results.map(({ viewId, raw }) => {
      const width = Number(raw?.runtime?.image_width || 1);
      const teeth = Array.isArray(raw?.teeth) ? raw.teeth : [];
      const ratios = teeth.map((tooth) => {
        const box = tooth.bbox_xyxy || [];
        return Math.max(1, Number(box[2]) - Number(box[0])) / Math.max(1, Number(box[3]) - Number(box[1]));
      });
      const xs = teeth.flatMap((tooth) => [Number(tooth.bbox_xyxy?.[0]), Number(tooth.bbox_xyxy?.[2])]).filter(Number.isFinite);
      return {
        viewId,
        count: teeth.length,
        ratio: median(ratios),
        span: xs.length ? (Math.max(...xs) - Math.min(...xs)) / width : .7,
      };
    });
    const baseRatio = median(summaries.map((summary) => summary.ratio));
    const regionScale = {};
    summaries.forEach((summary) => {
      regionScale[summary.viewId] = clamp(summary.ratio / Math.max(.1, baseRatio), .8, 1.2);
    });
    const totalDarklines = results.reduce((sum, item) => sum + Number(item.raw?.counts?.MicrocariesDarkline || 0), 0);
    return {
      regionScale,
      archWidth: clamp(median(summaries.map((summary) => summary.span)) / .72, .86, 1.14),
      crownHeight: clamp(.72 / Math.max(.3, baseRatio), .86, 1.15),
      depthScale: clamp((regionScale.left_bite + regionScale.right_bite) / 2, .86, 1.14),
      detectedInstances: summaries.reduce((sum, summary) => sum + summary.count, 0),
      totalDarklines,
    };
  }

  async function uploadRealViews() {
    const memberId = q('#model-demo-member').value;
    if (!memberId) throw new Error('请先选择这组照片所属的家庭成员。');
    const apiRequest = window.chijingApiRequest;
    if (typeof apiRequest !== 'function') throw new Error('页面认证接口尚未准备好，请刷新后重试。');

    const entries = VIEWS.map((view) => [view, state.inputs.get(view.id)]);
    for (let index = 0; index < entries.length; index += 1) {
      const [view, input] = entries[index];
      if (input.result) continue;
      const slot = q(`.view-slot[data-view="${view.id}"]`);
      q('.slot-state', slot).textContent = '上传中';
      setBuildProgress(5 + index * 4, `正在归档 ${index + 1}/7：${view.label}`);
      const form = new FormData();
      form.append('file', input.file, input.name);
      form.append('member_id', memberId);
      form.append('upload_mode', 'detect');
      form.append('model_pipeline', 'tooth_outline');
      const response = await apiRequest('api/images.php?action=upload', { method: 'POST', body: form });
      input.detectionId = response.detection_id;
      q('.slot-state', slot).textContent = '队列中';
    }

    const deadline = Date.now() + 10 * 60 * 1000;
    while (Date.now() < deadline) {
      const history = await apiRequest('api/history.php?limit=100');
      const byId = new Map((history.items || []).map((item) => [String(item.public_id), item]));
      let completed = 0;
      for (const [view, input] of entries) {
        if (input.result) { completed += 1; continue; }
        const record = byId.get(String(input.detectionId));
        if (!record) continue;
        if (record.status === 'failed') throw new Error(`${view.label}轮廓提取失败：${record.report_text || '本地工作端返回失败'}`);
        if (record.status !== 'completed') continue;
        const raw = parseResultJson(record.result_json);
        if (!raw || raw.pipeline !== 'tooth_outline_darkline_v3') throw new Error(`${view.label}返回的轮廓结果格式不正确。`);
        input.result = raw;
        completed += 1;
        applyOutlineToSlot(view.id, raw);
        console.info('[齿镜牙列] 实测轮廓结果已载入', {
          view: view.id,
          tooth_instances: Number(raw?.counts?.Tooth || 0),
          darkline_candidates: Number(raw?.counts?.MicrocariesDarkline || 0),
        });
      }
      setBuildProgress(33 + completed / 7 * 58, `本地 RTX 已完成 ${completed}/7 个视角的逐牙轮廓`);
      if (completed === 7) return entries.map(([view, input]) => ({ viewId: view.id, raw: input.result }));
      await wait(2600);
    }
    throw new Error('等待轮廓模型超过 10 分钟，请检查本地模型工作端是否正在运行。');
  }

  function askReviewMode() {
    const dialog = q('#dental-arch-mode-dialog');
    return new Promise((resolve) => {
      let settled = false;
      const finish = (value) => {
        if (settled) return;
        settled = true;
        qa('[data-review-choice]', dialog).forEach((button) => button.removeEventListener('click', choose));
        if (dialog.open) dialog.close();
        resolve(value);
      };
      const choose = (event) => finish(event.currentTarget.dataset.reviewChoice);
      qa('[data-review-choice]', dialog).forEach((button) => button.addEventListener('click', choose));
      dialog.addEventListener('close', () => finish(''), { once: true });
      dialog.showModal();
    });
  }

  async function loadCaptureArchive(captureId) {
    const response = await window.chijingApiRequest(`api/capture_archive.php?id=${encodeURIComponent(captureId)}`);
    if (!response.archive?.is_complete || (response.images || []).length !== 7) throw new Error('这个档案袋尚未集齐七个固定视角，请先进入档案袋补齐照片。');
    state.captureArchiveId = captureId;
    state.captureImported = true;
    const card = q('#capture-import-card');
    card.hidden = false;
    q('#capture-import-title').textContent = `${response.member.name} · ${String(response.archive.completed_at || response.archive.created_at).replace('T', ' ').slice(0, 16)}`;
    q('#capture-import-meta').textContent = '七张照片已按固定视角导入。单独替换任一槽位后，将作为一组自定义输入处理，不会改写原档案袋。';
    q('#model-demo-member').value = response.member.public_id;
    q('#model-demo-member').disabled = true;
    const byRegion = new Map(response.images.map((image) => [image.capture_region_id, image]));
    VIEWS.forEach((view) => {
      const image = byRegion.get(view.id);
      if (!image) return;
      markInput(view.id, {
        name: `${view.id}-${image.public_id}.jpg`,
        preview: `api/image.php?id=${encodeURIComponent(image.public_id)}`,
        brightness: '档案', sharpness: '已归档', demo: false, archive: true,
        detectionId: image.public_id,
      });
    });
    q('#input-status').textContent = '档案袋七图已就绪。点击任一槽位可从本地替换；点击“提取轮廓并生成”开始异步处理。';
    if (response.dental_arch?.public_id) {
      state.activeJobId = response.dental_arch.public_id;
      const existing = { ...response.dental_arch, progress_total: 10, version: response.dental_arch.version_public_id ? { public_id: response.dental_arch.version_public_id } : null };
      renderDentalArchTask(existing);
      if (['waiting_outline','vision_pending','vision_processing'].includes(existing.status)) pollDentalArchJob(existing.public_id).catch((error) => { q('#input-status').textContent = error.message; });
    }
  }

  async function prepareDentalArchSources() {
    const memberId = q('#model-demo-member').value;
    if (!memberId) throw new Error('请先选择这组照片所属的家庭成员。');
    const views = {};
    for (let index = 0; index < VIEWS.length; index += 1) {
      const view = VIEWS[index];
      const input = state.inputs.get(view.id);
      if (!input) throw new Error(`${view.label}尚未选择。`);
      if (!input.detectionId) {
        setBuildProgress(4 + index * 3, `正在归档 ${view.label}`);
        const form = new FormData();
        form.append('file', input.file, input.name);
        form.append('member_id', memberId);
        form.append('upload_mode', 'archive');
        const result = await window.chijingApiRequest('api/images.php?action=upload', { method: 'POST', body: form });
        input.detectionId = result.detection_id;
      }
      views[view.id] = input.detectionId;
    }
    return { memberId, views };
  }

  function renderDentalArchTask(job) {
    const card = q('#dental-arch-task');
    if (!card || !job) return;
    card.hidden = false;
    const labels = { waiting_outline: '本地轮廓', vision_pending: '等待视觉复核', vision_processing: '视觉牙号复核', review_required: '等待人工确认', completed: '牙列已绑定', failed: '任务失败', stale: '来源已变化' };
    q('#dental-arch-task-state').textContent = labels[job.status] || job.status;
    q('#dental-arch-task-progress').textContent = `${job.progress_step || 0} / ${job.progress_total || 10}`;
    q('#dental-arch-task-bar').style.width = `${Math.min(100, Number(job.progress_step || 0) / Math.max(1, Number(job.progress_total || 10)) * 100)}%`;
    q('#dental-arch-task-label').textContent = job.error_message || job.progress_label || '任务正在后台处理。关闭页面不会中断。';
    const action = q('#dental-arch-task-action');
    action.hidden = !(job.version || job.status === 'failed');
    if (job.status === 'failed') {
      const failedImage = (job.images || []).find((item) => item.status === 'failed');
      action.textContent = failedImage ? `仅重试${viewById(failedImage.capture_region_id).label}` : '仅重试视觉复核阶段';
      action.onclick = async () => {
        action.disabled = true;
        try {
          const response = await window.chijingApiRequest('api/dental_arch_jobs.php?action=retry', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ job_id: job.public_id, stage: failedImage ? 'outline' : 'vision', region_id: failedImage?.capture_region_id || '' }) });
          renderDentalArchTask(response.job); await pollDentalArchJob(job.public_id);
        } catch (error) { q('#input-status').textContent = error.message; }
        finally { action.disabled = false; }
      };
    } else if (job.version) {
      action.textContent = job.status === 'review_required' ? '进入人工确认' : '查看牙列版本';
      action.onclick = () => location.assign(job.status === 'review_required'
        ? `dental-arch-review.html?version=${encodeURIComponent(job.version.public_id)}`
        : `dental-model-demo.html?version=${encodeURIComponent(job.version.public_id)}`);
    }
  }

  async function loadClinicalManifest(versionId) {
    const manifest = await window.chijingApiRequest(`api/dental_arch_jobs.php?action=manifest&version=${encodeURIComponent(versionId)}`);
    state.clinicalManifest = manifest;
    state.activeVersionId = versionId;
    if (q('#model-demo-member')) q('#model-demo-member').value = manifest.member.public_id || '';
    state.modelReady = true;
    const statuses = {};
    FDI_ARCHES.upper.concat(FDI_ARCHES.lower).forEach((number) => { statuses[number] = 'unrecorded'; });
    Object.values(manifest.teeth || {}).forEach((tooth) => {
      const views = tooth.views || [];
      statuses[tooth.fdi] = views.some((item) => item.needs_review) ? 'review'
        : (views.some((item) => Number(item.metrics?.candidate_count || 0) > 0) ? 'abnormal' : 'trusted');
    });
    state.anatomicalViewer?.setStatuses(statuses);
    renderFdiIndex();
    q('#model-state-label').textContent = `${manifest.member.name} · ${manifest.summary.tooth_count} 个已记录牙位`;
    q('#input-status').textContent = `牙列版本已载入：7 个视角、${manifest.summary.tooth_view_count} 组单牙记录。白色牙位表示本次未记录，并不表示缺牙。`;
    setModelLayer('anatomical');
  }

  async function pollDentalArchJob(jobId) {
    const deadline = Date.now() + 45 * 60 * 1000;
    while (Date.now() < deadline) {
      const response = await window.chijingApiRequest(`api/dental_arch_jobs.php?action=status&id=${encodeURIComponent(jobId)}`);
      const job = response.job;
      renderDentalArchTask(job);
      setBuildProgress(Math.min(99, Number(job.progress_step || 0) / Math.max(1, Number(job.progress_total || 10)) * 100), job.progress_label || '后台处理中');
      if (job.status === 'failed') throw new Error(job.error_message || '牙列生成任务失败。');
      if (job.status === 'review_required' && job.version) {
        state.building = false; q('#model-build-overlay').hidden = true;
        location.assign(`dental-arch-review.html?version=${encodeURIComponent(job.version.public_id)}`); return;
      }
      if (job.status === 'completed' && job.version) {
        setBuildProgress(100, '牙列档案已生成');
        await loadClinicalManifest(job.version.public_id);
        state.building = false; q('#model-build-overlay').hidden = true; q('#build-demo-model').disabled = false; q('#build-demo-model').textContent = '生成新版本';
        return;
      }
      await wait(3000);
    }
    throw new Error('任务仍在后台运行。可以关闭页面，稍后从档案袋继续查看。');
  }

  async function startDentalArchJob(reviewDecision) {
    const prepared = await prepareDentalArchSources();
    const allFromArchive = state.captureImported && VIEWS.every((view) => state.inputs.get(view.id)?.archive);
    const payload = allFromArchive
      ? { source_type: 'capture_archive', capture_archive_id: state.captureArchiveId, review_decision: reviewDecision }
      : { source_type: 'manual', member_id: prepared.memberId, views: prepared.views, review_decision: reviewDecision };
    const response = await window.chijingApiRequest('api/dental_arch_jobs.php?action=start', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
    state.activeJobId = response.job_id;
    localStorage.setItem('chijing-dental-arch-job', response.job_id);
    await pollDentalArchJob(response.job_id);
  }

  function simulateBuild() {
    const steps = [[0, '正在校验七个固定视角'], [26, '正在建立视角对应关系'], [51, '正在拟合通用牙列先验'], [76, '正在生成可交互表面'], [93, '正在建立单牙索引']];
    return new Promise((resolve) => {
      let percent = 0;
      const timer = window.setInterval(() => {
        percent = Math.min(100, percent + 2);
        const current = [...steps].reverse().find(([threshold]) => percent >= threshold);
        setBuildProgress(percent, current[1]);
        if (percent < 100) return;
        window.clearInterval(timer);
        window.setTimeout(resolve, 260);
      }, 48);
    });
  }

  async function buildModel() {
    if (state.inputs.size !== 7 || state.building) return;
    state.building = true;
    q('#build-demo-model').disabled = true;
    const overlay = q('#model-build-overlay');
    overlay.hidden = false;
    setBuildProgress(0, '正在校验七个固定视角');
    try {
      const inputs = [...state.inputs.values()];
      const hasNumbered = inputs.some((input) => input.numberedTest);
      const hasDemo = inputs.some((input) => input.demo);
      const hasReal = inputs.some((input) => !input.demo && !input.numberedTest);
      if (hasNumbered && inputs.some((input) => !input.numberedTest)) throw new Error('编号测试数据不能与其他输入混用，请重新载入完整的一组照片。');
      if (hasDemo && hasReal) throw new Error('演示输入不能与真实照片混用，请重新载入完整的一组照片。');
      if (hasNumbered) {
        setBuildProgress(24, '正在核对七视图与 FDI 编号'); await wait(240);
        setBuildProgress(58, `正在关联 ${state.testManifest?.summary?.tooth_view_count || 0} 组单牙多视角记录`); await wait(320);
        setBuildProgress(86, '正在载入暗线处理阶段与结构指标'); await wait(260);
        setBuildProgress(100, '单牙测试档案已建立');
        state.testArchiveReady = true;
        state.modelReady = true;
        state.building = false;
        q('#model-build-overlay').hidden = true;
        q('#build-demo-model').disabled = false;
        q('#build-demo-model').textContent = '重新建立档案';
        q('#input-status').textContent = `测试档案已建立：${state.testManifest?.summary?.tooth_count || 28} 个已编号牙位、${state.testManifest?.summary?.tooth_view_count || 74} 组单牙视角。现在点击三维牙齿即可进入详细处理页。`;
        q('#model-state-label').textContent = '编号测试档案已绑定到完整恒牙列';
        setPipelineStep(4);
        setModelLayer('anatomical');
        renderFdiIndex();
        return;
      }
      if (hasDemo) {
        await simulateBuild();
        finishBuild(null, '通用演示模型已生成');
        return;
      }
      const reviewDecision = await askReviewMode();
      if (!reviewDecision) {
        state.building = false; overlay.hidden = true; q('#build-demo-model').disabled = false;
        q('#input-status').textContent = '已取消生成，七张输入照片仍保留在页面中。';
        return;
      }
      q('#input-status').textContent = '任务会在后台依次完成：本地轮廓 → 七次逐图视觉复核 → 一次七图统一复核。关闭页面不会中断。';
      await startDentalArchJob(reviewDecision);
    } catch (error) {
      state.building = false;
      overlay.hidden = true;
      q('#build-demo-model').disabled = false;
      q('#input-status').textContent = error.message;
      q('#model-state-label').textContent = '生成未完成';
    }
  }

  function createDentalRenderer(canvas) {
    const ctx = canvas.getContext('2d');
    const stage = q('#model-stage');
    const teeth = [];
    const measuredViews = new Map();
    const pointers = new Map();
    let width = 1;
    let height = 1;
    let dpr = 1;
    let yaw = 0;
    let pitch = .02;
    let zoom = 1;
    let activeArch = 'both';
    let selected = null;
    let wireframe = false;
    let visible = false;
    let dragStart = null;
    let pinchDistance = 0;
    let lastBoxes = [];
    let displayMode = 'parametric';
    let activeMeasuredView = VIEWS[0].id;
    let contour = {
      regionScale: {},
      archWidth: 1,
      crownHeight: 1,
      depthScale: 1,
    };

    const upperNumbers = FDI_ARCHES.upper;
    const lowerNumbers = FDI_ARCHES.lower;

    function toothDimensions(index, jaw) {
      const distance = Math.abs(index - 7.5);
      let base;
      if (distance <= 1.5) base = { rx: .28, ry: .49, rz: .34 };
      else if (distance <= 2.5) base = { rx: .31, ry: .55, rz: .38 };
      else if (distance <= 4.5) base = { rx: .38, ry: .48, rz: .45 };
      else base = { rx: .49, ry: .45, rz: .55 };
      const side = index < 8 ? 'right' : 'left';
      const openViewScale = Number(contour.regionScale?.[`${jaw}_${side}_open`] || 1);
      const biteViewScale = Number(contour.regionScale?.[`${side}_bite`] || 1);
      const regionalScale = clamp(openViewScale * .72 + biteViewScale * .28, .78, 1.22);
      return {
        rx: base.rx * regionalScale,
        ry: base.ry * contour.crownHeight,
        rz: base.rz * regionalScale,
      };
    }

    function createToothMesh(center, dimensions, rotation, jaw, number, index) {
      const vertices = [];
      const faces = [];
      const latitudes = 8;
      const longitudes = 14;
      for (let lat = 0; lat <= latitudes; lat += 1) {
        const phi = -Math.PI / 2 + Math.PI * lat / latitudes;
        const ring = Math.pow(Math.max(0, Math.cos(phi)), .72);
        for (let lon = 0; lon < longitudes; lon += 1) {
          const theta = Math.PI * 2 * lon / longitudes;
          const cusp = 1 + Math.cos(theta * (dimensions.rx > .4 ? 4 : 2)) * .045 * ring;
          const lx = dimensions.rx * ring * Math.cos(theta) * cusp;
          const ly = dimensions.ry * Math.sin(phi);
          const lz = dimensions.rz * ring * Math.sin(theta) * cusp;
          const cos = Math.cos(rotation);
          const sin = Math.sin(rotation);
          vertices.push({
            x: center.x + lx * cos - lz * sin,
            y: center.y + (jaw === 'upper' ? -ly : ly),
            z: center.z + lx * sin + lz * cos,
          });
        }
      }
      for (let lat = 0; lat < latitudes; lat += 1) {
        for (let lon = 0; lon < longitudes; lon += 1) {
          const next = (lon + 1) % longitudes;
          const a = lat * longitudes + lon;
          const b = lat * longitudes + next;
          const c = (lat + 1) * longitudes + next;
          const d = (lat + 1) * longitudes + lon;
          faces.push([a, b, c, d]);
        }
      }
      const side = index < 8 ? '右侧' : '左侧';
      return {
        center, vertices, faces, jaw, number,
        label: `${number} ${TOOTH_NAMES[number % 10] || '牙齿'}`,
        side,
      };
    }

    function createTeeth() {
      teeth.length = 0;
      ['upper', 'lower'].forEach((jaw) => {
        const numbers = jaw === 'upper' ? upperNumbers : lowerNumbers;
        numbers.forEach((number, index) => {
          const angle = -1.24 + index / 15 * 2.48;
          const dimensions = toothDimensions(index, jaw);
          const center = {
            x: Math.sin(angle) * 4.65 * contour.archWidth,
            y: jaw === 'upper' ? .72 : -.72,
            z: Math.cos(angle) * 3.15 * contour.depthScale - 1.95,
          };
          teeth.push(createToothMesh(center, dimensions, angle * .76, jaw, number, index));
        });
      });
    }

    function createMeasuredView(viewId, raw) {
      const sourceTeeth = (Array.isArray(raw?.teeth) ? raw.teeth : [])
        .filter((tooth) => Array.isArray(tooth.polygon) && tooth.polygon.length >= 3);
      if (!sourceTeeth.length) return [];
      const allPoints = sourceTeeth.flatMap((tooth) => tooth.polygon);
      const xs = allPoints.map((point) => Number(point[0])).filter(Number.isFinite);
      const ys = allPoints.map((point) => Number(point[1])).filter(Number.isFinite);
      if (!xs.length || !ys.length) return [];
      const minX = Math.min(...xs); const maxX = Math.max(...xs);
      const minY = Math.min(...ys); const maxY = Math.max(...ys);
      const centerX = (minX + maxX) / 2; const centerY = (minY + maxY) / 2;
      const scale = Math.min(8.1 / Math.max(1, maxX - minX), 5.4 / Math.max(1, maxY - minY));
      const biteSplit = median(sourceTeeth.map((tooth) => Number(tooth.centroid_xy?.[1])).filter(Number.isFinite));
      const fixedJaw = viewId.startsWith('upper_') ? 'upper' : viewId.startsWith('lower_') ? 'lower' : '';
      return sourceTeeth.map((source, index) => {
        const polygon = source.polygon
          .map((point) => [Number(point[0]), Number(point[1])])
          .filter((point) => point.every(Number.isFinite));
        const boundary = polygon.map((point) => ({
          x: (point[0] - centerX) * scale,
          y: -(point[1] - centerY) * scale,
        }));
        const localCenter = boundary.reduce((sum, point) => ({
          x: sum.x + point.x / boundary.length,
          y: sum.y + point.y / boundary.length,
        }), { x: 0, y: 0 });
        const box = source.bbox_xyxy || [];
        const boxWidth = Math.max(1, Number(box[2]) - Number(box[0])) * scale;
        const depth = clamp(boxWidth * .28, .14, .48);
        const vertices = [];
        boundary.forEach((point) => vertices.push({ x: point.x, y: point.y, z: depth * .08 }));
        boundary.forEach((point) => vertices.push({ x: point.x, y: point.y, z: -depth * .62 }));
        const frontCenter = vertices.length;
        vertices.push({ x: localCenter.x, y: localCenter.y, z: depth * .78 });
        const backCenter = vertices.length;
        vertices.push({ x: localCenter.x, y: localCenter.y, z: -depth * .62 });
        const faces = [];
        for (let pointIndex = 0; pointIndex < boundary.length; pointIndex += 1) {
          const next = (pointIndex + 1) % boundary.length;
          faces.push([frontCenter, pointIndex, next]);
          faces.push([backCenter, boundary.length + next, boundary.length + pointIndex]);
          faces.push([pointIndex, boundary.length + pointIndex, boundary.length + next, next]);
        }
        const centroidY = Number(source.centroid_xy?.[1]);
        const jaw = fixedJaw || (Number.isFinite(centroidY) && centroidY <= biteSplit ? 'upper' : 'lower');
        const instanceId = String(source.instance_id || `T${String(index + 1).padStart(2, '0')}`);
        return {
          center: { x: localCenter.x, y: localCenter.y, z: 0 },
          vertices, faces, jaw,
          number: instanceId,
          label: `实测牙面 ${instanceId}`,
          side: localCenter.x < 0 ? '画面左侧' : '画面右侧',
          measured: true,
          sourceView: viewId,
          confidence: Number(source.confidence || 0),
          candidateCount: Number(source.candidate_count || source.darkline_candidates?.length || 0),
        };
      });
    }

    function rotatePoint(point) {
      const cy = Math.cos(yaw);
      const sy = Math.sin(yaw);
      const cp = Math.cos(pitch);
      const sp = Math.sin(pitch);
      const x1 = point.x * cy - point.z * sy;
      const z1 = point.x * sy + point.z * cy;
      return { x: x1, y: point.y * cp - z1 * sp, z: point.y * sp + z1 * cp };
    }

    function project(point) {
      const rotated = rotatePoint(point);
      const cameraDistance = 12;
      const factor = Math.min(width, height) * .1 * zoom * cameraDistance / Math.max(4, cameraDistance - rotated.z);
      return {
        x: width / 2 + rotated.x * factor,
        y: height / 2 - rotated.y * factor,
        z: rotated.z,
        factor,
      };
    }

    function archVisible(jaw) {
      return activeArch === 'both' || activeArch === jaw;
    }

    function drawGum(jaw) {
      if (!archVisible(jaw)) return;
      const points = [];
      for (let i = 0; i < 48; i += 1) {
        const angle = -1.25 + i / 47 * 2.5;
        points.push(project({
          x: Math.sin(angle) * 4.65 * contour.archWidth,
          y: jaw === 'upper' ? 1.07 : -1.07,
          z: Math.cos(angle) * 3.15 * contour.depthScale - 1.95,
        }));
      }
      ctx.save();
      ctx.beginPath();
      points.forEach((point, index) => index ? ctx.lineTo(point.x, point.y) : ctx.moveTo(point.x, point.y));
      ctx.lineWidth = Math.max(12, 30 * zoom);
      ctx.lineCap = 'round';
      ctx.lineJoin = 'round';
      ctx.strokeStyle = getComputedStyle(document.documentElement).getPropertyValue('--dm-gum').trim() || '#9f7772';
      ctx.globalAlpha = .58;
      ctx.stroke();
      ctx.globalAlpha = .2;
      ctx.lineWidth *= .52;
      ctx.strokeStyle = '#ffffff';
      ctx.stroke();
      ctx.restore();
    }

    function faceNormal(points, center) {
      const a = points[0];
      const b = points[1];
      const c = points[2];
      const ux = b.x - a.x;
      const uy = b.y - a.y;
      const uz = b.z - a.z;
      const vx = c.x - a.x;
      const vy = c.y - a.y;
      const vz = c.z - a.z;
      let normal = { x: uy * vz - uz * vy, y: uz * vx - ux * vz, z: ux * vy - uy * vx };
      const faceCenter = points.reduce((sum, point) => ({ x: sum.x + point.x / points.length, y: sum.y + point.y / points.length, z: sum.z + point.z / points.length }), { x: 0, y: 0, z: 0 });
      const outward = (faceCenter.x - center.x) * normal.x + (faceCenter.y - center.y) * normal.y + (faceCenter.z - center.z) * normal.z;
      if (outward < 0) normal = { x: -normal.x, y: -normal.y, z: -normal.z };
      const length = Math.hypot(normal.x, normal.y, normal.z) || 1;
      return { x: normal.x / length, y: normal.y / length, z: normal.z / length };
    }

    function enamelColor(light, isSelected) {
      if (isSelected) {
        const value = Math.round(138 + light * 70);
        return `rgb(${value},${Math.round(value * .94)},${Math.round(value * .77)})`;
      }
      const dark = document.documentElement.dataset.theme === 'dark';
      const base = dark ? 182 : 204;
      const range = dark ? 54 : 48;
      const red = Math.round(base + range * light);
      return `rgb(${red},${Math.round(red * .992)},${Math.round(red * .955)})`;
    }

    function drawTooth(tooth) {
      if (!archVisible(tooth.jaw)) return null;
      const rotatedCenter = rotatePoint(tooth.center);
      const projectedVertices = tooth.vertices.map(project);
      const rotatedVertices = tooth.vertices.map(rotatePoint);
      const faces = tooth.faces.map((face) => {
        const rotated = face.map((index) => rotatedVertices[index]);
        return {
          face,
          depth: rotated.reduce((sum, point) => sum + point.z, 0) / rotated.length,
          normal: faceNormal(rotated, rotatedCenter),
        };
      }).filter((entry) => entry.normal.z > -.04).sort((a, b) => a.depth - b.depth);
      const isSelected = selected === tooth;
      faces.forEach(({ face, normal }) => {
        const light = clamp((normal.x * -.22 + normal.y * .42 + normal.z * .84 + 1) / 2, .06, 1);
        ctx.beginPath();
        face.forEach((index, pointIndex) => {
          const point = projectedVertices[index];
          if (pointIndex) ctx.lineTo(point.x, point.y); else ctx.moveTo(point.x, point.y);
        });
        ctx.closePath();
        if (!wireframe) {
          ctx.fillStyle = enamelColor(light, isSelected);
          ctx.fill();
        }
        ctx.strokeStyle = isSelected
          ? 'rgba(88,74,47,.48)'
          : wireframe ? 'rgba(112,139,155,.52)' : 'rgba(80,78,71,.10)';
        ctx.lineWidth = isSelected ? 1.1 : .45;
        ctx.stroke();
      });
      const xs = projectedVertices.map((point) => point.x);
      const ys = projectedVertices.map((point) => point.y);
      return {
        tooth,
        depth: rotatedCenter.z,
        minX: Math.min(...xs), maxX: Math.max(...xs),
        minY: Math.min(...ys), maxY: Math.max(...ys),
      };
    }

    function drawOrientationMarker() {
      const x = width - 40;
      const y = 42;
      ctx.save();
      ctx.font = '600 9px ui-monospace, monospace';
      ctx.textAlign = 'center';
      ctx.fillStyle = getComputedStyle(document.documentElement).getPropertyValue('--muted').trim() || '#777';
      ctx.fillText('L', x - 20, y);
      ctx.fillText('R', x + 20, y);
      ctx.beginPath();
      ctx.moveTo(x - 12, y - 3);
      ctx.lineTo(x + 12, y - 3);
      ctx.strokeStyle = 'rgba(120,120,120,.42)';
      ctx.stroke();
      ctx.restore();
    }

    function render() {
      ctx.clearRect(0, 0, width, height);
      if (!visible) return;
      const measured = displayMode === 'measured';
      if (!measured) { drawGum('upper'); drawGum('lower'); }
      const source = measured ? (measuredViews.get(activeMeasuredView) || []) : teeth;
      const ordered = source
        .filter((tooth) => archVisible(tooth.jaw))
        .sort((a, b) => rotatePoint(a.center).z - rotatePoint(b.center).z);
      lastBoxes = ordered.map(drawTooth).filter(Boolean);
      drawOrientationMarker();
    }

    function resize() {
      const rect = stage.getBoundingClientRect();
      width = Math.max(1, Math.round(rect.width));
      height = Math.max(1, Math.round(rect.height));
      dpr = Math.min(2, window.devicePixelRatio || 1);
      canvas.width = Math.round(width * dpr);
      canvas.height = Math.round(height * dpr);
      canvas.style.width = `${width}px`;
      canvas.style.height = `${height}px`;
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      render();
    }

    function selectAt(clientX, clientY) {
      const rect = canvas.getBoundingClientRect();
      const x = clientX - rect.left;
      const y = clientY - rect.top;
      const matches = lastBoxes.filter((box) => x >= box.minX && x <= box.maxX && y >= box.minY && y <= box.maxY);
      selected = matches.sort((a, b) => b.depth - a.depth)[0]?.tooth || null;
      if (selected) {
        q('#selected-tooth-number').textContent = selected.number;
        q('#selected-tooth-name').textContent = selected.label;
        const jaw = selected.jaw === 'upper' ? '上颌' : '下颌';
        q('#selected-tooth-meta').textContent = selected.measured
          ? `${viewById(selected.sourceView).label} · ${jaw} · 轮廓置信度 ${Math.round(selected.confidence * 100)}% · 浅龋暗线候选 ${selected.candidateCount} 处。当前为轮廓挤出的局部牙面。`
          : `${jaw} · ${selected.side}｜当前为参数牙列占位，不代表患者真实牙面。`;
        if (!selected.measured && Number.isInteger(Number(selected.number))) {
          setSelectedTooth(Number(selected.number), '参数牙列');
          window.setTimeout(() => openToothRecord(Number(selected.number)), 120);
        }
      } else {
        q('#selected-tooth-number').textContent = '—';
        q('#selected-tooth-name').textContent = '尚未选择单牙';
        q('#selected-tooth-meta').textContent = '点击牙面，可查看模型实例编号和来源视角。';
      }
      render();
    }

    function pointerDistance() {
      const values = [...pointers.values()];
      return values.length < 2 ? 0 : Math.hypot(values[0].x - values[1].x, values[0].y - values[1].y);
    }

    stage.addEventListener('pointerdown', (event) => {
      if (!visible) return;
      stage.setPointerCapture(event.pointerId);
      pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
      dragStart = { x: event.clientX, y: event.clientY, yaw, pitch, moved: false };
      pinchDistance = pointerDistance();
      stage.classList.add('dragging');
    });
    stage.addEventListener('pointermove', (event) => {
      if (!pointers.has(event.pointerId)) return;
      pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
      if (pointers.size >= 2) {
        const distance = pointerDistance();
        if (pinchDistance) zoom = clamp(zoom * distance / pinchDistance, .62, 1.9);
        pinchDistance = distance;
      } else if (dragStart) {
        const dx = event.clientX - dragStart.x;
        const dy = event.clientY - dragStart.y;
        if (Math.abs(dx) + Math.abs(dy) > 4) dragStart.moved = true;
        yaw = dragStart.yaw + dx * .009;
        pitch = clamp(dragStart.pitch + dy * .008, -.95, .95);
      }
      render();
    });
    function endPointer(event) {
      const shouldSelect = dragStart && !dragStart.moved && pointers.size === 1;
      pointers.delete(event.pointerId);
      if (shouldSelect) selectAt(event.clientX, event.clientY);
      if (!pointers.size) {
        stage.classList.remove('dragging');
        dragStart = null;
        pinchDistance = 0;
      }
    }
    stage.addEventListener('pointerup', endPointer);
    stage.addEventListener('pointercancel', endPointer);
    stage.addEventListener('wheel', (event) => {
      if (!visible) return;
      event.preventDefault();
      zoom = clamp(zoom * (event.deltaY > 0 ? .92 : 1.08), .62, 1.9);
      render();
    }, { passive: false });
    stage.addEventListener('keydown', (event) => {
      if (!visible) return;
      const keyActions = {
        ArrowLeft: () => { yaw -= .1; }, ArrowRight: () => { yaw += .1; },
        ArrowUp: () => { pitch -= .1; }, ArrowDown: () => { pitch += .1; },
        '+': () => { zoom = clamp(zoom * 1.08, .62, 1.9); },
        '-': () => { zoom = clamp(zoom * .92, .62, 1.9); },
      };
      if (!keyActions[event.key]) return;
      event.preventDefault();
      keyActions[event.key]();
      render();
    });

    const resizeObserver = new ResizeObserver(resize);
    resizeObserver.observe(stage);
    const themeObserver = new MutationObserver(render);
    themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    window.addEventListener('pagehide', () => { resizeObserver.disconnect(); themeObserver.disconnect(); }, { once: true });

    createTeeth();
    resize();

    return {
      render,
      setVisible(value) { visible = value; render(); },
      setPreset(view) {
        activeMeasuredView = view.id;
        yaw = displayMode === 'measured' ? 0 : view.yaw;
        pitch = displayMode === 'measured' ? 0 : view.pitch;
        zoom = 1; selected = null; render();
      },
      setArch(value) { activeArch = value; selected = null; render(); },
      setMeasuredViews(results) {
        measuredViews.clear();
        results.forEach(({ viewId, raw }) => measuredViews.set(viewId, createMeasuredView(viewId, raw)));
        activeMeasuredView = state.activeView;
        selected = null;
        render();
      },
      setDisplayMode(value) {
        if (value === 'measured' && !measuredViews.size) return false;
        displayMode = value === 'measured' ? 'measured' : 'parametric';
        selected = null; yaw = 0; pitch = .02; zoom = 1; render();
        return true;
      },
      setContourProfile(value) {
        contour = {
          ...contour,
          ...value,
          regionScale: { ...contour.regionScale, ...(value?.regionScale || {}) },
        };
        selected = null;
        createTeeth();
        render();
      },
      reset() { yaw = 0; pitch = .02; zoom = 1; selected = null; render(); },
      toggleWireframe() { wireframe = !wireframe; render(); return wireframe; },
      download() {
        render();
        const link = document.createElement('a');
        link.download = `chijing-dental-model-demo-${Date.now()}.png`;
        link.href = canvas.toDataURL('image/png');
        link.click();
      },
    };
  }

  function renderFdiIndex() {
    const root = q('#fdi-tooth-index');
    if (!root) return;
    root.replaceChildren();
    Object.entries(FDI_ARCHES).forEach(([arch, numbers]) => {
      const row = document.createElement('div');
      row.className = `fdi-index-row ${arch}`;
      numbers.forEach((tooth) => {
        const link = document.createElement('a');
        link.className = 'fdi-index-tooth';
        link.href = toothDetailUrl(tooth);
        link.dataset.tooth = tooth;
        link.textContent = tooth;
        const record = state.clinicalManifest?.teeth?.[String(tooth)];
        if (record) {
          const recordViews = record.views || [];
          link.classList.add(recordViews.some((item) => item.needs_review) ? 'status-review'
            : (recordViews.some((item) => Number(item.metrics?.candidate_count || 0) > 0) ? 'status-abnormal' : 'status-trusted'));
        }
        link.title = `${tooth} ${TOOTH_NAMES[tooth % 10] || '恒牙'} · 打开单牙档案`;
        link.setAttribute('aria-label', link.title);
        link.addEventListener('pointerenter', () => setSelectedTooth(tooth, 'FDI 牙位索引'));
        row.append(link);
      });
      root.append(row);
    });
  }

  function applyAnatomicalArchVisibility() {
    state.anatomicalViewer?.setArch(state.activeArch);
  }

  async function initializeAnatomicalViewer() {
    const viewport = q('#local-dentition-viewport');
    const loading = q('#dentition-loading');
    if (!viewport) return;
    try {
      const module = await import('./local-dentition-viewer.js?v=20260814-numbered-hover-v2');
      const hoverLabel = q('#tooth-hover-label');
      state.anatomicalViewer = module.createLocalDentitionViewer(viewport, {
        onProgress(percent) {
          const progressText = loading?.querySelector('small');
          if (progressText && percent > 0) progressText.textContent = `正在从齿镜服务器读取模型资源 · ${percent}%`;
        },
        onReady(info) {
          state.anatomicalReady = true;
          state.modelReady = true;
          if (loading) loading.hidden = true;
          q('#model-state-label').textContent = `完整恒牙列已就绪 · ${info.mappedTeeth}/32 牙位`;
          q('#download-model-view').disabled = false;
          applyAnatomicalArchVisibility();
        },
        onHover(tooth) {
          if (tooth) setSelectedTooth(tooth);
          if (hoverLabel) hoverLabel.hidden = !tooth;
        },
        onPointer(pointer) {
          if (!hoverLabel || !pointer.tooth) return;
          hoverLabel.style.left = `${pointer.x}px`;
          hoverLabel.style.top = `${pointer.y}px`;
          hoverLabel.querySelector('strong').textContent = `FDI ${pointer.tooth} · ${TOOTH_NAMES[pointer.tooth % 10] || '恒牙'}`;
        },
        onSelect(tooth) {
          setSelectedTooth(tooth);
          window.setTimeout(() => openToothRecord(tooth), 120);
        },
        onError(error) {
          console.error('[齿镜] 本地恒牙模型载入失败', error);
          if (loading) loading.innerHTML = '<strong>服务器模型文件读取失败</strong><small>请确认 assets/models/permanent-dentition 已完整上传。当前已切换到参数牙列。</small>';
          qa('[data-render-mode]').forEach((button) => button.classList.toggle('active', button.dataset.renderMode === 'parametric'));
          setModelLayer('canvas', 'parametric');
        },
      });
    } catch (error) {
      console.error('[齿镜] 本地三维组件载入失败', error);
      if (loading) loading.innerHTML = '<strong>本地三维组件未完整上传</strong><small>请检查 assets/vendor/three 目录。当前已切换到参数牙列。</small>';
      qa('[data-render-mode]').forEach((button) => button.classList.toggle('active', button.dataset.renderMode === 'parametric'));
      setModelLayer('canvas', 'parametric');
    }
  }

  function downloadAnatomicalView() {
    if (!state.anatomicalReady) return;
    state.anatomicalViewer?.download();
  }

  function setupDentalModelDemo() {
    const pageParams = new URLSearchParams(location.search);
    const requestedCapture = pageParams.get('capture') || '';
    const requestedVersion = pageParams.get('version') || '';
    state.renderer = createDentalRenderer(q('#dental-model-canvas'));
    state.modelReady = true;
    renderFdiIndex();
    initializeAnatomicalViewer();
    setModelLayer('anatomical');
    setActiveView(VIEWS[0].id, false);
    updateInputProgress();

    const fileInput = q('#view-file-input');
    const memberSelect = q('#model-demo-member');
    if (memberSelect && typeof window.chijingApiRequest === 'function') {
      window.chijingApiRequest('api/members.php?action=list').then((response) => {
        const members = Array.isArray(response.items) ? response.items : [];
        memberSelect.innerHTML = '<option value="">请选择家庭成员</option>';
        members.forEach((member) => {
          const option = document.createElement('option');
          option.value = member.public_id;
          option.textContent = `${member.name}${member.relationship ? ` · ${member.relationship}` : ''}`;
          if (Number(member.is_default) === 1) option.selected = true;
          memberSelect.append(option);
        });
        renderFdiIndex();
        if (requestedCapture) loadCaptureArchive(requestedCapture).catch((error) => { q('#input-status').textContent = error.message; });
        if (requestedVersion) loadClinicalManifest(requestedVersion).catch((error) => { q('#input-status').textContent = `牙列版本读取失败：${error.message}`; });
      }).catch((error) => {
        q('#input-status').textContent = `成员列表读取失败：${error.message}`;
      });
    }
    qa('.view-slot').forEach((slot) => slot.addEventListener('click', () => {
      setActiveView(slot.dataset.view, false);
      fileInput.value = '';
      fileInput.click();
    }));
    fileInput.addEventListener('change', async () => {
      const file = fileInput.files?.[0];
      if (!file) return;
      q('#input-status').textContent = '正在读取照片的尺寸、亮度和清晰度…';
      try {
        const input = await analyzeFile(file);
        markInput(state.activeView, input);
        if (state.captureImported) {
          q('#capture-import-meta').textContent = '已替换一个档案袋视角；这次会作为自定义七图生成，不会修改原档案袋。';
        }
      } catch (error) {
        q('#input-status').textContent = `无法读取该图片：${error.message}`;
      }
    });

    q('#load-demo-views').addEventListener('click', () => {
      memberSelect.disabled = false; state.captureImported = false; state.captureArchiveId = ''; q('#capture-import-card').hidden = true;
      state.testArchiveId = '';
      state.testManifest = null;
      state.testArchiveReady = false;
      VIEWS.forEach((view, index) => markInput(view.id, {
        name: `${String(index + 1).padStart(2, '0')}-${view.id}-demo.jpg`,
        preview: demoPreview(view, index),
        brightness: 72 + index,
        sharpness: 80 - index,
        demo: true,
      }));
      q('#input-status').textContent = '演示输入已载入。它们是流程示意图，不是患者照片。';
    });
    const numberedDatasetSelect = q('#numbered-test-dataset');
    const updateNumberedDatasetMeta = () => {
      const selected = NUMBERED_TEST_DATASETS[numberedDatasetSelect?.value] || NUMBERED_TEST_DATASETS[DEFAULT_NUMBERED_TEST_DATASET];
      if (q('#numbered-test-meta')) q('#numbered-test-meta').textContent = selected.meta;
    };
    numberedDatasetSelect?.addEventListener('change', updateNumberedDatasetMeta);
    q('#load-numbered-test')?.addEventListener('click', async () => {
      memberSelect.disabled = false; state.captureImported = false; state.captureArchiveId = ''; q('#capture-import-card').hidden = true;
      const datasetId = NUMBERED_TEST_DATASETS[numberedDatasetSelect?.value]
        ? numberedDatasetSelect.value
        : DEFAULT_NUMBERED_TEST_DATASET;
      const datasetInfo = NUMBERED_TEST_DATASETS[datasetId];
      state.testArchiveReady = false;
      q('#input-status').textContent = `正在读取${datasetInfo.label}七视图编号档案…`;
      try {
        const response = await fetch(`assets/demo/${encodeURIComponent(datasetId)}/manifest.json`, { cache: 'no-store' });
        if (!response.ok) throw new Error(`测试档案读取失败（HTTP ${response.status}）`);
        const manifest = await response.json();
        state.testArchiveId = manifest.archive_id;
        state.testManifest = manifest;
        state.testArchiveReady = new URLSearchParams(location.search).get('dataset') === manifest.archive_id;
        manifest.views.forEach((view) => markInput(view.id, {
          name: `${String(view.index).padStart(2, '0')}-${view.id}-numbered.jpg`,
          preview: view.original_url,
          brightness: '已标定',
          sharpness: '已标定',
          numberedTest: true,
          demo: false,
        }));
        q('#input-status').textContent = `${manifest.title}已载入：7 张原图、${manifest.summary.tooth_count} 个有效 FDI 牙位、${manifest.summary.tooth_view_count} 组多视角记录。点击“提取轮廓并生成”建立测试档案。`;
      } catch (error) {
        q('#input-status').textContent = error.message;
      }
    });
    q('#build-demo-model').addEventListener('click', buildModel);

    qa('[data-render-mode]').forEach((button) => button.addEventListener('click', () => {
      if (button.dataset.renderMode === 'anatomical') {
        qa('[data-render-mode]').forEach((item) => item.classList.toggle('active', item === button));
        setModelLayer('anatomical');
        return;
      }
      if (!state.renderer.setDisplayMode(button.dataset.renderMode)) return;
      qa('[data-render-mode]').forEach((item) => item.classList.toggle('active', item === button));
      setModelLayer('canvas', button.dataset.renderMode);
    }));

    qa('[data-preset]').forEach((button) => button.addEventListener('click', () => setActiveView(button.dataset.preset)));
    qa('[data-arch]').forEach((button) => button.addEventListener('click', () => {
      qa('[data-arch]').forEach((item) => item.classList.toggle('active', item === button));
      state.activeArch = button.dataset.arch;
      state.renderer.setArch(button.dataset.arch);
      applyAnatomicalArchVisibility();
    }));
    q('#reset-model-view').addEventListener('click', () => {
      if (state.activeLayer === 'anatomical') state.anatomicalViewer?.reset();
      else state.renderer.reset();
    });
    q('#toggle-wireframe').addEventListener('click', (event) => {
      const enabled = state.renderer.toggleWireframe();
      event.currentTarget.textContent = enabled ? '实体' : '轮廓';
    });
    q('#download-model-view').addEventListener('click', () => {
      if (state.activeLayer === 'anatomical') downloadAnatomicalView();
      else state.renderer.download();
    });
    memberSelect?.addEventListener('change', renderFdiIndex);

    const requestedTooth = Number(new URLSearchParams(location.search).get('tooth'));
    if (FDI_ARCHES.upper.includes(requestedTooth) || FDI_ARCHES.lower.includes(requestedTooth)) setSelectedTooth(requestedTooth);
    const requestedDataset = new URLSearchParams(location.search).get('dataset') || '';
    if (NUMBERED_TEST_DATASETS[requestedDataset]) {
      if (numberedDatasetSelect) numberedDatasetSelect.value = requestedDataset;
      updateNumberedDatasetMeta();
      q('#load-numbered-test')?.click();
    } else {
      if (numberedDatasetSelect) numberedDatasetSelect.value = DEFAULT_NUMBERED_TEST_DATASET;
      updateNumberedDatasetMeta();
    }

    window.addEventListener('pagehide', () => {
      state.anatomicalViewer?.dispose();
      state.inputs.forEach((input) => { if (input.objectUrl) URL.revokeObjectURL(input.objectUrl); });
    }, { once: true });
  }

  window.setupDentalModelDemo = setupDentalModelDemo;
})();
