const $ = (selector) => document.querySelector(selector);
let csrf = '';
let recordRefreshTimer = null;
let imageRecords = [];
let recordStatuses = new Map();
const queuedAnalysisIds = new Set();
const announcedAnalysisIds = new Set();
const pendingAnalysisNoticeIds = new Set();

const clientLog = (level, event, detail = {}) => {
  const writer = console[level] || console.log;
  writer.call(console, `[齿镜] ${event}`, { time: new Date().toISOString(), ...detail });
};

window.addEventListener('error', (event) => {
  clientLog('error', '页面脚本错误', {
    message: event.message || '未知脚本错误',
    source: event.filename ? event.filename.split('/').pop() : '',
    line: event.lineno || 0,
    column: event.colno || 0,
  });
});

window.addEventListener('unhandledrejection', (event) => {
  clientLog('error', '未处理的异步错误', {
    message: event.reason instanceof Error ? event.reason.message : String(event.reason || '未知异步错误'),
  });
});

function applyTheme(theme) {
  document.documentElement.dataset.theme = theme;
  localStorage.setItem('chijing-theme', theme);
  const meta = document.querySelector('meta[name="theme-color"]');
  if (meta) meta.content = theme === 'dark' ? '#111111' : '#ffffff';
}

function setupTheme() {
  const stored = localStorage.getItem('chijing-theme');
  applyTheme(stored || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));
  $('.theme-toggle')?.addEventListener('click', () => applyTheme(document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark'));
}

async function request(url, options = {}) {
  const method = String(options.method || 'GET').toUpperCase();
  const started = performance.now();
  let response;
  try {
    response = await fetch(url, { ...options, headers: { 'X-CSRF-Token': csrf, ...(options.headers || {}) } });
  } catch (error) {
    clientLog('error', '接口网络失败', { method, url, message: error.message });
    throw error;
  }
  const raw = await response.text();
  const requestId = response.headers.get('X-Chijing-Request-Id') || response.headers.get('X-Request-Id') || '';
  const detail = {
    method,
    url,
    status: response.status,
    duration_ms: Math.round(performance.now() - started),
    content_type: response.headers.get('Content-Type') || '',
    request_id: requestId,
  };
  let result;
  try { result = JSON.parse(raw); } catch (error) {
    clientLog('error', '接口返回非 JSON', { ...detail, response_bytes: new Blob([raw]).size });
    throw new Error('服务器接口返回了非 JSON 错误页。请检查数据库迁移和 PHP 错误日志。');
  }
  if (!response.ok || !result.ok) {
    clientLog('error', '接口请求失败', {
      ...detail,
      error_code: result.error_code || '',
      message: result.error || result.instruction || '请求未完成。',
    });
    throw new Error(result.error || result.instruction || '请求未完成。');
  }
  clientLog('info', '接口请求完成', detail);
  return result;
}

// Feature pages may reuse the authenticated request path after requireUser has loaded CSRF.
window.chijingApiRequest = request;

function formData(form) { return Object.fromEntries(new FormData(form).entries()); }
function setMessage(element, text) { if (element) element.textContent = text || ''; }

function setupPasswordToggles() {
  document.querySelectorAll('.password-toggle').forEach((button) => button.addEventListener('click', () => {
    const input = button.parentElement.querySelector('input');
    const reveal = input.type === 'password';
    input.type = reveal ? 'text' : 'password';
    button.textContent = reveal ? '隐藏' : '显示';
  }));
}

function setupLogin() {
  const form = $('#login-form');
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    setMessage($('#auth-message'), '');
    try {
      await request('api/auth.php?action=login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(formData(form)) });
      location.assign('dashboard.html');
    } catch (error) { setMessage($('#auth-message'), error.message); }
  });
}

function setupRegister() {
  const form = $('#register-form');
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const data = formData(form);
    setMessage($('#auth-message'), '');
    if (data.password !== data.password_confirm) { setMessage($('#auth-message'), '两次输入的密码不一致。'); return; }
    delete data.password_confirm;
    try {
      await request('api/auth.php?action=register', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) });
      location.assign('dashboard.html');
    } catch (error) { setMessage($('#auth-message'), error.message); }
  });
}

async function requireUser() {
  try {
    const data = await request('api/auth.php?action=me');
    csrf = data.csrf;
    return data.user;
  } catch (error) {
    location.replace('login.html');
    throw error;
  }
}

function setupLogout() {
  $('.logout-button')?.addEventListener('click', async () => {
    try { await request('api/auth.php?action=logout', { method: 'POST' }); } finally { location.assign('index.html'); }
  });
}

function statusText(status) {
  return ({ saved: '仅保存', received: '等待分析', processing: '分析中', completed: '已完成', failed: '处理失败' })[status] || status;
}

function uploadModeText(mode) {
  return mode === 'archive' ? '上传保存' : '云端检测';
}

function uploadSourceText(source) {
  return source === 'web' ? '本地上传' : '设备采集';
}

function setupLocalImageUpload({ members = [], defaultMode = 'archive', onUploaded = null } = {}) {
  const dialog = $('#local-upload-dialog');
  const form = $('#local-upload-form');
  const openButton = $('#open-local-upload');
  if (!dialog || !form || !openButton || dialog.dataset.uploadReady === 'true') return;
  dialog.dataset.uploadReady = 'true';
  const fileInput = $('#local-upload-file');
  const drop = $('#local-upload-drop');
  const preview = $('#local-upload-preview');
  const empty = $('#local-upload-empty');
  const fileMeta = $('#local-upload-file-meta');
  const memberSelect = $('#local-upload-member');
  const pipelineField = $('#local-upload-pipeline');
  const message = $('#local-upload-message');
  const submit = $('#local-upload-submit');
  let previewUrl = '';

  memberSelect.replaceChildren(new Option('请选择成员', ''));
  members.forEach((member) => {
    const option = new Option(member.name, member.public_id);
    if (member.is_default) option.dataset.defaultMember = 'true';
    memberSelect.append(option);
  });

  const clearPreview = () => {
    if (previewUrl) URL.revokeObjectURL(previewUrl);
    previewUrl = '';
    preview.removeAttribute('src');
    preview.hidden = true;
    fileMeta.hidden = true;
    empty.hidden = false;
  };
  const reset = () => {
    form.reset();
    const preferredMode = form.querySelector(`[name="upload_mode"][value="${defaultMode}"]`) || form.querySelector('[name="upload_mode"]');
    if (preferredMode) preferredMode.checked = true;
    const defaultMember = memberSelect.querySelector('[data-default-member="true"]');
    if (defaultMember) memberSelect.value = defaultMember.value;
    pipelineField.hidden = preferredMode?.value !== 'detect';
    message.textContent = '';
    message.classList.remove('is-success');
    clearPreview();
  };
  const displayFile = (file) => {
    message.classList.remove('is-success');
    if (!file) { clearPreview(); return false; }
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
      message.textContent = '请选择 JPEG、PNG 或 WebP 图片。';
      fileInput.value = '';
      clearPreview();
      return false;
    }
    if (file.size > 8 * 1024 * 1024) {
      message.textContent = '图片超过 8 MB，请压缩后再上传。';
      fileInput.value = '';
      clearPreview();
      return false;
    }
    clearPreview();
    previewUrl = URL.createObjectURL(file);
    preview.src = previewUrl;
    preview.hidden = false;
    empty.hidden = true;
    fileMeta.hidden = false;
    const values = fileMeta.querySelectorAll('span');
    values[0].textContent = file.name;
    values[1].textContent = `${Math.max(1, Math.ceil(file.size / 1024))} KB`;
    message.textContent = '';
    const probe = new Image();
    probe.onload = () => { values[1].textContent = `${probe.naturalWidth} × ${probe.naturalHeight} · ${Math.max(1, Math.ceil(file.size / 1024))} KB`; };
    probe.src = previewUrl;
    return true;
  };

  openButton.addEventListener('click', () => {
    reset();
    if (!members.length) {
      message.textContent = '当前账号还没有可用成员，请先在成员页面添加家庭成员。';
      submit.disabled = true;
    } else {
      submit.disabled = false;
    }
    dialog.showModal();
  });
  document.querySelectorAll('.local-upload-close').forEach((button) => button.addEventListener('click', () => dialog.close()));
  $('.local-upload-reset')?.addEventListener('click', () => { fileInput.value = ''; clearPreview(); message.textContent = ''; });
  dialog.addEventListener('click', (event) => { if (event.target === dialog) dialog.close(); });
  dialog.addEventListener('close', () => { clearPreview(); drop.classList.remove('is-dragging'); });
  fileInput.addEventListener('change', () => displayFile(fileInput.files?.[0]));
  drop.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); fileInput.click(); }
  });
  ['dragenter', 'dragover'].forEach((type) => drop.addEventListener(type, (event) => {
    event.preventDefault();
    drop.classList.add('is-dragging');
  }));
  ['dragleave', 'drop'].forEach((type) => drop.addEventListener(type, (event) => {
    event.preventDefault();
    drop.classList.remove('is-dragging');
  }));
  drop.addEventListener('drop', (event) => {
    const file = event.dataTransfer?.files?.[0];
    if (!file) return;
    const transfer = new DataTransfer();
    transfer.items.add(file);
    fileInput.files = transfer.files;
    displayFile(file);
  });
  form.querySelectorAll('[name="upload_mode"]').forEach((input) => input.addEventListener('change', () => {
    pipelineField.hidden = input.value !== 'detect' || !input.checked;
  }));
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!displayFile(fileInput.files?.[0])) return;
    submit.disabled = true;
    message.textContent = '正在安全上传并创建影像记录…';
    try {
      const payload = new FormData(form);
      const data = await request('api/images.php?action=upload', { method: 'POST', body: payload });
      const pageMessage = $('#local-upload-page-message');
      if (pageMessage) {
        pageMessage.textContent = data.upload_mode === 'detect' ? '本地影像已上传，正在等待模型分析。' : '本地影像已上传并归档。';
        pageMessage.classList.add('is-success');
      }
      dialog.close();
      if (typeof onUploaded === 'function') await onUploaded(data);
    } catch (error) {
      message.textContent = error.message;
    } finally {
      submit.disabled = false;
    }
  });
}
window.setupLocalImageUpload = setupLocalImageUpload;

function makeImage(id, className) {
  const image = document.createElement('img');
  image.className = className;
  image.style.filter = 'none';
  image.src = `api/image.php?id=${encodeURIComponent(id)}`;
  image.alt = '口腔影像';
  return image;
}

function modelResult(item) {
  if (!item?.result_json) return null;
  try { return typeof item.result_json === 'string' ? JSON.parse(item.result_json) : item.result_json; }
  catch (_) { return null; }
}

function modelFindingStyle(finding) {
  if (finding?.model === 'microcaries_darkline_v3') {
    return { color: '#ff6f61', label: '浅龋暗线候选' };
  }
  if (finding?.model === 'tooth_instance_yolo11s_seg') {
    return { color: '#ffb84d', label: '牙齿轮廓' };
  }
  if (finding?.model === 'calculus_seg_ensemble_v5') {
    return { color: '#ff8a4c', label: '牙结石' };
  }
  if (finding?.model === 'dental_seg_yolo11n') {
    const styles = {
      Caries: { color: '#ff5f68', label: '龋齿' },
      Cavity: { color: '#50d890', label: '窝洞' },
      Crack: { color: '#6f8cff', label: '裂纹' },
      Tooth: { color: '#ffb84d', label: '牙齿' },
    };
    return styles[finding.label] || { color: '#e8edf2', label: finding.label || '目标' };
  }
  if (finding?.model === 'alphadent_4class') return { color: '#ffd166', label: finding.label || '综合候选' };
  return { color: '#43e5b0', label: finding?.label || '龋齿候选' };
}

function drawModelOverlay(item) {
  const image = $('#image-preview-image');
  const canvas = $('#image-preview-overlay');
  const result = modelResult(item);
  const findings = Array.isArray(result?.findings) ? result.findings : [];
  const resultText = $('#image-preview-result');
  const draw = () => {
    const width = image.offsetWidth;
    const height = image.offsetHeight;
    const ratio = Math.max(1, window.devicePixelRatio || 1);
    canvas.width = Math.max(1, Math.round(width * ratio));
    canvas.height = Math.max(1, Math.round(height * ratio));
    canvas.style.width = `${width}px`;
    canvas.style.height = `${height}px`;
    const context = canvas.getContext('2d');
    context.setTransform(ratio, 0, 0, ratio, 0, 0);
    context.clearRect(0, 0, width, height);
    if (!findings.length) return;
    const sourceWidth = Number(item.image_width) || image.naturalWidth || width;
    const sourceHeight = Number(item.image_height) || image.naturalHeight || height;
    const xScale = width / sourceWidth;
    const yScale = height / sourceHeight;
    findings.forEach((finding, index) => {
      const style = modelFindingStyle(finding);
      const polygon = Array.isArray(finding.polygon) ? finding.polygon : [];
      if (polygon.length >= 3) {
        context.save();
        context.beginPath();
        polygon.forEach((point, pointIndex) => {
          if (!Array.isArray(point) || point.length < 2) return;
          const px = Number(point[0]) * xScale;
          const py = Number(point[1]) * yScale;
          if (pointIndex === 0) context.moveTo(px, py);
          else context.lineTo(px, py);
        });
        context.closePath();
        context.globalAlpha = 0.24;
        context.fillStyle = style.color;
        context.fill();
        context.globalAlpha = 0.95;
        context.lineWidth = 2;
        context.strokeStyle = style.color;
        context.stroke();
        context.restore();
      }
      const box = Array.isArray(finding.bbox_xyxy) ? finding.bbox_xyxy : [];
      if (box.length !== 4) return;
      const [left, top, right, bottom] = box.map(Number);
      const x = Math.max(0, left * xScale);
      const y = Math.max(0, top * yScale);
      const boxWidth = Math.max(1, (right - left) * xScale);
      const boxHeight = Math.max(1, (bottom - top) * yScale);
      const color = style.color;
      const label = `${style.label} ${Math.round((Number(finding.confidence) || 0) * 100)}%`;
      context.lineWidth = 2;
      context.strokeStyle = color;
      context.strokeRect(x, y, boxWidth, boxHeight);
      context.font = '600 12px Inter, PingFang SC, sans-serif';
      const labelWidth = context.measureText(label).width + 12;
      const labelY = Math.max(0, y - 22);
      context.fillStyle = color;
      context.fillRect(x, labelY, labelWidth, 20);
      context.fillStyle = '#0d1513';
      context.fillText(label, x + 6, labelY + 14);
    });
  };
  if (item.status === 'completed') {
    const hasSegmentation = findings.some((finding) => Array.isArray(finding.polygon) && finding.polygon.length >= 3);
    resultText.textContent = findings.length
      ? `已叠加 ${findings.length} 个模型候选区域。${hasSegmentation ? '半透明彩色区域为分割轮廓。' : '绿色为龋齿候选，黄色为综合模型结果。'}`
      : '模型已完成，但未发现可展示的候选区域。';
  } else {
    resultText.textContent = '尚无可视化结果。完成模型分析后会自动叠加候选框或分割轮廓。';
  }
  if (image.complete && image.naturalWidth) requestAnimationFrame(draw);
  else image.addEventListener('load', () => requestAnimationFrame(draw), { once: true });
}

function setupImagePreview() {
  const dialog = $('#image-preview-dialog');
  if (!dialog) return;
  const viewport = $('#image-preview-viewport');
  const image = viewport.querySelector('.image-preview-image');
  const stage = $('#image-preview-stage');
  const zoomValue = $('#image-preview-zoom-value');
  const zoomRange = $('#image-preview-zoom-range');
  let scale = 1;
  let panX = 0;
  let panY = 0;
  let baseWidth = 0;
  let baseHeight = 0;
  let drag = null;
  const pointers = new Map();
  let pinch = null;

  const clamp = (value, min, max) => Math.min(max, Math.max(min, value));
  const viewportPoint = (clientX, clientY) => {
    const rect = viewport.getBoundingClientRect();
    return { x: clientX - rect.left, y: clientY - rect.top };
  };
  const bounds = () => ({
    x: Math.max(0, ((baseWidth * scale) - viewport.clientWidth) / 2),
    y: Math.max(0, ((baseHeight * scale) - viewport.clientHeight) / 2),
  });
  const constrainPan = () => {
    const limit = bounds();
    panX = clamp(panX, -limit.x, limit.x);
    panY = clamp(panY, -limit.y, limit.y);
  };
  const paint = () => {
    stage.style.setProperty('--preview-scale', String(scale));
    stage.style.setProperty('--preview-pan-x', `${panX}px`);
    stage.style.setProperty('--preview-pan-y', `${panY}px`);
    zoomValue.textContent = `${Math.round(scale * 100)}%`;
    if (zoomRange) zoomRange.value = String(Math.round(scale * 100));
    viewport.classList.toggle('is-zoomed', scale > 1.01);
  };
  const fit = () => {
    if (!image.naturalWidth || !image.naturalHeight || !viewport.clientWidth || !viewport.clientHeight) return;
    const imageRatio = image.naturalWidth / image.naturalHeight;
    const viewportRatio = viewport.clientWidth / viewport.clientHeight;
    if (imageRatio > viewportRatio) {
      baseWidth = viewport.clientWidth;
      baseHeight = baseWidth / imageRatio;
    } else {
      baseHeight = viewport.clientHeight;
      baseWidth = baseHeight * imageRatio;
    }
    stage.style.width = `${Math.round(baseWidth)}px`;
    stage.style.height = `${Math.round(baseHeight)}px`;
    scale = 1;
    panX = 0;
    panY = 0;
    paint();
  };
  const zoomTo = (nextScale, point = null) => {
    if (!baseWidth || !baseHeight) return;
    const oldScale = scale;
    const targetScale = clamp(nextScale, 1, 8);
    const anchor = point || { x: viewport.clientWidth / 2, y: viewport.clientHeight / 2 };
    const localX = (baseWidth / 2) + ((anchor.x - (viewport.clientWidth / 2) - panX) / oldScale);
    const localY = (baseHeight / 2) + ((anchor.y - (viewport.clientHeight / 2) - panY) / oldScale);
    scale = targetScale;
    panX = anchor.x - (viewport.clientWidth / 2) - ((localX - (baseWidth / 2)) * scale);
    panY = anchor.y - (viewport.clientHeight / 2) - ((localY - (baseHeight / 2)) * scale);
    constrainPan();
    paint();
  };
  const reset = () => fit();
  const close = () => dialog.close();
  $('#close-image-preview')?.addEventListener('click', close);
  $('#image-preview-close-bottom')?.addEventListener('click', close);
  dialog.addEventListener('click', (event) => { if (event.target === dialog) close(); });
  dialog.addEventListener('close', () => {
    pointers.clear();
    drag = null;
    pinch = null;
    viewport.classList.remove('is-dragging');
    reset();
  });
  document.querySelectorAll('[data-preview-zoom]').forEach((button) => button.addEventListener('click', () => {
    const action = button.dataset.previewZoom;
    if (action === 'in') zoomTo(scale * 1.25);
    else if (action === 'out') zoomTo(scale / 1.25);
    else reset();
  }));
  zoomRange?.addEventListener('input', () => zoomTo(Number(zoomRange.value) / 100));
  viewport.addEventListener('wheel', (event) => {
    event.preventDefault();
    const point = viewportPoint(event.clientX, event.clientY);
    zoomTo(scale * Math.exp(-event.deltaY * 0.0015), point);
  }, { passive: false });
  viewport.addEventListener('pointerdown', (event) => {
    if (event.pointerType === 'mouse' && event.button !== 0) return;
    viewport.setPointerCapture(event.pointerId);
    pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
    if (pointers.size === 2) {
      const [first, second] = [...pointers.values()];
      pinch = {
        distance: Math.hypot(second.x - first.x, second.y - first.y),
        scale,
      };
      drag = null;
    } else if (scale > 1.01) {
      drag = { pointerId: event.pointerId, x: event.clientX, y: event.clientY, panX, panY };
      viewport.classList.add('is-dragging');
    }
  });
  viewport.addEventListener('pointermove', (event) => {
    if (!pointers.has(event.pointerId)) return;
    pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
    if (pointers.size === 2 && pinch) {
      const [first, second] = [...pointers.values()];
      const distance = Math.hypot(second.x - first.x, second.y - first.y);
      const point = viewportPoint((first.x + second.x) / 2, (first.y + second.y) / 2);
      zoomTo(pinch.scale * (distance / Math.max(1, pinch.distance)), point);
      return;
    }
    if (!drag || drag.pointerId !== event.pointerId) return;
    panX = drag.panX + event.clientX - drag.x;
    panY = drag.panY + event.clientY - drag.y;
    constrainPan();
    paint();
  });
  const stopDrag = (event) => {
    if (viewport.hasPointerCapture(event.pointerId)) viewport.releasePointerCapture(event.pointerId);
    pointers.delete(event.pointerId);
    if (drag?.pointerId === event.pointerId) drag = null;
    if (pointers.size < 2) pinch = null;
    viewport.classList.remove('is-dragging');
  };
  viewport.addEventListener('pointerup', stopDrag);
  viewport.addEventListener('pointercancel', stopDrag);
  viewport.addEventListener('dblclick', (event) => {
    const point = viewportPoint(event.clientX, event.clientY);
    if (scale > 1.01) reset();
    else zoomTo(2, point);
  });
  viewport.addEventListener('keydown', (event) => {
    if (event.key === '+' || event.key === '=') { event.preventDefault(); zoomTo(scale * 1.25); }
    if (event.key === '-') { event.preventDefault(); zoomTo(scale / 1.25); }
    if (event.key === '0') { event.preventDefault(); reset(); }
  });
  image.addEventListener('load', () => {
    requestAnimationFrame(fit);
  });
  window.addEventListener('resize', () => { if (dialog.open) requestAnimationFrame(fit); });
}

function openImagePreview(item, options = {}) {
  const dialog = $('#image-preview-dialog');
  if (!dialog) return;
  const announcement = $('#image-preview-announcement');
  if (dialog.open) dialog.close();
  $('#image-preview-title').textContent = `${item.member_name || '未归属成员'} · ${uploadModeText(item.upload_mode)}`;
  $('#image-preview-meta').textContent = `${item.created_at} · ${item.image_width || '—'} × ${item.image_height || '—'} · ${Math.ceil((Number(item.image_bytes) || 0) / 1024)} KB`;
  const previewImage = document.querySelector('#image-preview-viewport .image-preview-image');
  previewImage.removeAttribute('width');
  previewImage.removeAttribute('height');
  previewImage.src = `api/image.php?id=${encodeURIComponent(item.public_id)}`;
  const previewStage = $('#image-preview-stage');
  previewStage.style.removeProperty('--preview-scale');
  previewStage.style.removeProperty('--preview-pan-x');
  previewStage.style.removeProperty('--preview-pan-y');
  previewStage.style.removeProperty('width');
  previewStage.style.removeProperty('height');
  $('#image-preview-zoom-value').textContent = '100%';
  $('#image-preview-zoom-range').value = '100';
  $('#image-preview-download').href = `api/image.php?id=${encodeURIComponent(item.public_id)}&download=1`;
  const editLink = $('#image-preview-edit');
  if (editLink) editLink.href = `image-editor.html?id=${encodeURIComponent(item.public_id)}&return=${encodeURIComponent('detections.html')}`;
  if (announcement) {
    announcement.hidden = !options.analysisCompleted;
    announcement.textContent = options.analysisCompleted ? '云端分析已完成 · 已打开标注结果' : '';
  }
  dialog.showModal();
  if (previewImage.complete && previewImage.naturalWidth) {
    previewImage.dispatchEvent(new Event('load'));
  }
  drawModelOverlay(item);
}

function showCompletedAnalysis(item) {
  if (!item) return;
  pendingAnalysisNoticeIds.delete(item.public_id);
  if (announcedAnalysisIds.has(item.public_id)) return;
  announcedAnalysisIds.add(item.public_id);
  openImagePreview(item, { analysisCompleted: true });
}

async function loadDashboard(user) {
  $('#welcome-name').textContent = `${user.nickname}的齿镜`;
  const [devices, history] = await Promise.all([request('api/devices.php?action=list'), request('api/history.php?limit=50')]);
  $('#device-count').textContent = devices.items.length;
  $('#history-count').textContent = history.items.length;
  $('#complete-count').textContent = history.items.filter((item) => item.status === 'completed').length;
  const root = $('#latest-record'); root.replaceChildren();
  const item = history.items[0];
  if (!item) { root.textContent = '还没有影像记录。可绑定设备完成采集，也可以在影像管理中上传本地照片。'; return; }
  const card = document.createElement('article'); card.className = 'latest-card';
  const image = makeImage(item.public_id, '');
  const content = document.createElement('div');
  const title = document.createElement('h3'); title.textContent = `${item.member_name || '未归属成员'} · 采集 ${item.public_id.slice(-6)}`;
  const time = document.createElement('p'); time.textContent = item.created_at;
  const report = document.createElement('p'); report.textContent = item.report_text || '图片已接收，等待模型服务处理。';
  const status = document.createElement('span'); status.className = 'status'; status.textContent = statusText(item.status);
  content.append(title, time, report, status); card.append(image, content); root.append(card);
}

function deviceRow(item) {
  const row = document.createElement('article'); row.className = 'device-row';
  const name = document.createElement('div'); const h3 = document.createElement('h3'); h3.textContent = item.display_name; const uid = document.createElement('p'); uid.textContent = item.device_uid; name.append(h3, uid);
  const state = document.createElement('span'); state.textContent = item.last_seen_at ? `最近在线：${item.last_seen_at}` : '尚未上传图片';
  const action = document.createElement('button'); action.className = 'text-button'; action.textContent = '解绑';
  action.addEventListener('click', async () => { if (!confirm('解绑后该设备将不能继续上传。确认解绑？')) return; try { await request('api/devices.php?action=unbind', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ public_id: item.public_id }) }); loadDevices(); } catch (error) { alert(error.message); } });
  row.append(name, state, action); return row;
}

async function loadDevices() {
  const data = await request('api/devices.php?action=list');
  $('#device-total').textContent = `${data.items.length} 台`;
  const root = $('#device-list'); root.replaceChildren();
  if (!data.items.length) { root.textContent = '尚未绑定设备。点击右上角“绑定设备”开始。'; return; }
  data.items.forEach((item) => root.append(deviceRow(item)));
}

function setupDevices() {
  const bindDialog = $('#bind-dialog'); const bindStatusDialog = $('#bind-status-dialog');
  let bindingTimer = null;
  const stopBindingWatch = () => { if (bindingTimer) { clearInterval(bindingTimer); bindingTimer = null; } };
  const updateBindingStatus = (title, message, detail = '') => {
    $('#bind-status-title').textContent = title;
    $('#bind-status-message').textContent = message;
    setMessage($('#bind-status-detail'), detail);
  };
  const watchBinding = (pairingId) => {
    stopBindingWatch();
    const poll = async () => {
      try {
        const data = await request('api/device_link.php?action=claim_status', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ pairing_id: pairingId }) });
        if (data.status === 'bound') {
          stopBindingWatch();
          updateBindingStatus('绑定完成', '设备已保存上传令牌，现在可以开始采集口腔影像。');
          loadDevices();
        } else if (data.status === 'expired') {
          stopBindingWatch();
          updateBindingStatus('等待已取消', '设备尚未完成联动。本次确认已失效，但设备码保持不变，可重新输入。');
        } else {
          updateBindingStatus('等待设备确认', '请保持 ESP32-P4 联网。设备会自动更新设备码并保存上传令牌。');
        }
      } catch (error) { updateBindingStatus('暂时无法确认状态', '请保持此页面打开，系统会继续尝试连接。', error.message); }
    };
    poll(); bindingTimer = setInterval(poll, 3000);
  };
  $('#open-bind').addEventListener('click', () => bindDialog.showModal());
  document.querySelectorAll('.close-dialog').forEach((button) => button.addEventListener('click', () => button.closest('dialog').close()));
  $('#close-bind-status').addEventListener('click', () => bindStatusDialog.close());
  bindStatusDialog.addEventListener('close', stopBindingWatch);
  bindDialog.querySelector('[name="device_code"]').addEventListener('input', (event) => { event.currentTarget.value = event.currentTarget.value.toUpperCase(); });
  $('#bind-form').addEventListener('submit', async (event) => {
    event.preventDefault(); const form = event.currentTarget; setMessage($('#bind-message'), '');
    try {
      const data = await request('api/device_link.php?action=claim', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(formData(form)) });
      bindDialog.close(); form.reset(); updateBindingStatus('等待设备确认', '网页已确认设备码。请保持 ESP32-P4 联网，设备会自动更新设备码并保存上传令牌。'); bindStatusDialog.showModal(); watchBinding(data.pairing_id);
    } catch (error) { setMessage($('#bind-message'), error.message); }
  });
  loadDevices();
}

function openModelTool(selectedItem = null) {
  const dialog = $('#model-tool-dialog');
  const select = $('#model-tool-image');
  if (!dialog || !select) return;
  select.replaceChildren();
  imageRecords.forEach((item) => {
    const option = document.createElement('option');
    option.value = item.public_id;
    option.textContent = `${item.member_name || '未归属成员'} · ${item.created_at} · ${uploadModeText(item.upload_mode)}`;
    select.append(option);
  });
  if (selectedItem) select.value = selectedItem.public_id;
  $('#model-tool-pipeline').value = selectedItem?.model_pipeline || 'caries';
  setMessage($('#model-tool-message'), '');
  if (!select.options.length) { setMessage($('#model-tool-message'), '当前筛选范围内没有可处理的图片。'); return; }
  dialog.showModal();
}

function setupModelTool() {
  const dialog = $('#model-tool-dialog');
  const form = $('#model-tool-form');
  if (!dialog || !form) return;
  $('#open-model-tool')?.addEventListener('click', () => openModelTool());
  document.querySelectorAll('.close-model-tool').forEach((button) => button.addEventListener('click', () => dialog.close()));
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const submit = $('#model-tool-submit');
    submit.disabled = true;
    setMessage($('#model-tool-message'), '正在加入分析队列…');
    try {
      const data = formData(form);
      await request('api/images.php?action=analyze', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) });
      queuedAnalysisIds.add(data.public_id);
      dialog.close();
      await loadRecords();
    } catch (error) { setMessage($('#model-tool-message'), error.message); }
    finally { submit.disabled = false; }
  });
}

function recordRow(item) {
  const row = document.createElement('article'); row.className = 'record-row';
  const image = makeImage(item.public_id, 'record-thumb');
  const content = document.createElement('div'); content.className = 'record-text';
  const title = document.createElement('h3'); title.textContent = `${item.member_name || '未归属成员'} · ${uploadSourceText(item.source_type)} · ${uploadModeText(item.upload_mode)} ${item.public_id.slice(-6)}`;
  const status = document.createElement('span'); status.className = 'status'; status.textContent = statusText(item.status); title.append(' · ', status);
  const report = document.createElement('p'); report.textContent = item.report_text || (item.upload_mode === 'archive' ? '图片已保存，可在网页端选择云端分析。' : '图片已接收，等待模型服务处理。'); content.append(title, report);
  const actions = document.createElement('div'); actions.className = 'record-actions';
    const preview = document.createElement('a'); preview.className = 'text-button'; preview.textContent = '查看与编辑'; preview.href = `image-editor.html?id=${encodeURIComponent(item.public_id)}&return=${encodeURIComponent('detections.html')}`;
  const download = document.createElement('a'); download.className = 'text-button'; download.textContent = '下载'; download.href = `api/image.php?id=${encodeURIComponent(item.public_id)}&download=1`;
  const aiDentist = document.createElement('a'); aiDentist.className = 'text-button'; aiDentist.textContent = 'AI牙医'; aiDentist.href = `ai-dentist.html?id=${encodeURIComponent(item.public_id)}`;
  const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'text-button record-delete'; remove.textContent = '删除';
  remove.addEventListener('click', async () => {
    if (!confirm('删除后将同时移除云端原图与该图片的模型检测结果，且无法恢复。已生成的 AI牙医文字报告会保留，但报告中将无法继续查看这张原图。确认删除？')) return;
    try { await request('api/images.php?action=delete', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ public_id: item.public_id }) }); await loadRecords(); }
    catch (error) { alert(error.message); }
  });
  actions.append(preview, download, aiDentist);
  if (!['received', 'processing'].includes(item.status)) {
    const analyze = document.createElement('button'); analyze.type = 'button'; analyze.className = 'text-button record-analyze'; analyze.textContent = item.status === 'completed' ? '再次分析' : '云端分析';
    analyze.addEventListener('click', () => location.assign(`model-lab.html?id=${encodeURIComponent(item.public_id)}`));
    actions.append(analyze);
  }
  actions.append(remove); content.append(actions);
  const date = document.createElement('p'); date.className = 'record-date'; date.textContent = item.created_at;
  row.append(image, content, date); return row;
}

async function loadRecords() {
  if (recordRefreshTimer) { clearTimeout(recordRefreshTimer); recordRefreshTimer = null; }
  const memberId = $('#record-member-filter')?.value || '';
  const uploadMode = $('#record-mode-filter')?.value || '';
  const query = `${memberId ? `&member_id=${encodeURIComponent(memberId)}` : ''}${uploadMode ? `&upload_mode=${encodeURIComponent(uploadMode)}` : ''}`;
  const data = await request(`api/history.php?limit=100${query}`);
  imageRecords = data.items;
  const completedItems = data.items.filter((item) => {
    if (item.status !== 'completed' || announcedAnalysisIds.has(item.public_id) || pendingAnalysisNoticeIds.has(item.public_id)) return false;
    const previousStatus = recordStatuses.get(item.public_id);
    return queuedAnalysisIds.has(item.public_id) || ['received', 'processing'].includes(previousStatus);
  });
  recordStatuses = new Map(data.items.map((item) => [item.public_id, item.status]));
  if (data.items.some((item) => ['received', 'processing'].includes(item.status)) && !document.hidden) {
    recordRefreshTimer = setTimeout(() => loadRecords().catch(() => {}), 5000);
  }
  $('#record-total').textContent = `${data.items.length} 条`;
  const root = $('#record-list'); root.replaceChildren();
  if (!data.items.length) { root.textContent = '还没有影像记录。可通过设备采集，或从电脑、手机上传一张口腔照片。'; return; }
  data.items.forEach((item) => root.append(recordRow(item)));
  completedItems.forEach((item, index) => {
    queuedAnalysisIds.delete(item.public_id);
    pendingAnalysisNoticeIds.add(item.public_id);
    window.setTimeout(() => showCompletedAnalysis(item), index * 260);
  });
}

function genderText(value) {
  return ({ male: '男', female: '女', unknown: '未填写' })[value] || '未填写';
}

function memberRow(item, onEdit) {
  const row = document.createElement('article'); row.className = 'member-row member-row-link';
  const profileLink = document.createElement('a');
  profileLink.className = 'member-card-profile-link';
  profileLink.href = `member-profile.html?member=${encodeURIComponent(item.public_id)}`;
  profileLink.setAttribute('aria-label', `查看${item.name}的成员档案`);
  const identity = document.createElement('div');
  const title = document.createElement('h3'); title.textContent = item.name;
  const description = document.createElement('p');
  const labels = [item.relationship || '未填写关系', genderText(item.gender)];
  if (item.is_default) labels.push('默认成员');
  description.textContent = labels.join(' · ');
  identity.append(title, description);
  const hint = document.createElement('span'); hint.className = 'member-open-hint'; hint.textContent = '查看完整档案 →';
  identity.append(hint);
  const meta = document.createElement('p'); meta.className = 'member-meta';
  meta.textContent = `${item.birth_date || '未填写出生日期'} · ${item.detection_count} 条记录`;
  const actions = document.createElement('div'); actions.className = 'member-actions';
  const report = document.createElement('a'); report.className = 'text-button'; report.textContent = '生成报告';
  report.href = `family-reports.html?member=${encodeURIComponent(item.public_id)}`;
  const edit = document.createElement('button'); edit.type = 'button'; edit.className = 'text-button'; edit.textContent = '编辑'; edit.addEventListener('click', () => onEdit(item));
  const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'text-button'; remove.textContent = '删除';
  remove.addEventListener('click', async () => {
    if (!confirm(`删除“${item.name}”后，设备将不再显示该成员。已有记录会保留。确认删除？`)) return;
    try {
      await request('api/members.php?action=delete', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ public_id: item.public_id }) });
      loadMembers();
    } catch (error) { alert(error.message); }
  });
  actions.append(report, edit, remove); row.append(profileLink, identity, meta, actions);
  return row;
}

function fillMemberForm(item = null) {
  const form = $('#member-form');
  form.reset();
  form.elements.public_id.value = item?.public_id || '';
  form.elements.name.value = item?.name || '';
  form.elements.relationship.value = item?.relationship || '';
  form.elements.gender.value = item?.gender || 'unknown';
  form.elements.birth_date.value = item?.birth_date || '';
  $('#member-form-index').textContent = item ? '成员 / 编辑' : '成员 / 新增';
  $('#member-dialog-title').textContent = item ? '编辑家庭成员' : '添加家庭成员';
  $('#member-submit').textContent = item ? '保存修改' : '添加成员';
  setMessage($('#member-message'), '');
}

async function loadMembers() {
  const data = await request('api/members.php?action=list');
  $('#member-total').textContent = `${data.items.length} 位`;
  const root = $('#member-list'); root.replaceChildren();
  if (!data.items.length) { root.textContent = '还没有成员，请先添加一位家庭成员。'; return; }
  data.items.forEach((item) => root.append(memberRow(item, (member) => { fillMemberForm(member); $('#member-dialog').showModal(); })));
}

function setupMembers() {
  const dialog = $('#member-dialog');
  $('#open-member-dialog').addEventListener('click', () => { fillMemberForm(); dialog.showModal(); });
  document.querySelectorAll('.close-member-dialog').forEach((button) => button.addEventListener('click', () => dialog.close()));
  $('#member-form').addEventListener('submit', async (event) => {
    event.preventDefault(); const form = event.currentTarget; const data = formData(form);
    const action = data.public_id ? 'update' : 'create'; setMessage($('#member-message'), '');
    try {
      await request(`api/members.php?action=${action}`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) });
      dialog.close(); loadMembers();
    } catch (error) { setMessage($('#member-message'), error.message); }
  });
  loadMembers().catch((error) => { $('#member-list').textContent = error.message; });
  // 设备端提交成员修改后，网页成员页会定时读取云端最新列表。
  const syncTimer = setInterval(() => { if (!dialog.open) loadMembers().catch(() => {}); }, 15000);
  window.addEventListener('pagehide', () => clearInterval(syncTimer), { once: true });
}

async function setupRecords() {
  const select = $('#record-member-filter');
  const modeSelect = $('#record-mode-filter');
  setupImagePreview();
  setupModelTool();
  window.addEventListener('pagehide', () => { if (recordRefreshTimer) clearTimeout(recordRefreshTimer); }, { once: true });
  try {
    const members = await request('api/members.php?action=list');
    members.items.forEach((member) => {
      const option = document.createElement('option'); option.value = member.public_id; option.textContent = member.name;
      select.append(option);
    });
    setupLocalImageUpload({
      members: members.items,
      defaultMode: 'archive',
      onUploaded: async (data) => {
        if (data.upload_mode === 'detect') queuedAnalysisIds.add(data.detection_id);
        select.value = '';
        if (modeSelect) modeSelect.value = '';
        await loadRecords();
      },
    });
    select.addEventListener('change', () => loadRecords().catch((error) => { $('#record-list').textContent = error.message; }));
    modeSelect?.addEventListener('change', () => loadRecords().catch((error) => { $('#record-list').textContent = error.message; }));
    $('#refresh-records').addEventListener('click', () => loadRecords().catch((error) => { $('#record-list').textContent = error.message; }));
    await loadRecords();
  } catch (error) { $('#record-list').textContent = error.message; }
}

async function startProtected(page) {
  const user = await requireUser(); setupLogout();
  document.querySelectorAll('[data-admin-only]').forEach((node) => { node.hidden = !user.is_admin; });
  if (page === 'dashboard') loadDashboard(user).catch((error) => { $('#latest-record').textContent = error.message; });
  if (page === 'devices') setupDevices();
  if (page === 'members') setupMembers();
  if (page === 'detections') setupRecords();
  if (page === 'model-lab' && typeof window.setupModelLab === 'function') window.setupModelLab(user);
  if (page === 'assistant' && typeof window.setupAssistant === 'function') window.setupAssistant(user);
  if (page === 'device-assistant' && typeof window.setupDeviceAssistant === 'function') window.setupDeviceAssistant(user);
  if (page === 'ai-dentist' && typeof window.setupAiDentist === 'function') window.setupAiDentist(user);
  if (page === 'ai-dentist-admin' && typeof window.setupAiDentistAdmin === 'function') window.setupAiDentistAdmin(user);
  if (page === 'reference-library' && typeof window.setupReferenceLibrary === 'function') window.setupReferenceLibrary(user);
  if (page === 'family-reports' && typeof window.setupFamilyReports === 'function') window.setupFamilyReports(user);
  if (page === 'family-report-print' && typeof window.setupFamilyReportPrint === 'function') window.setupFamilyReportPrint(user);
  if (page === 'dental-model-demo' && typeof window.setupDentalModelDemo === 'function') window.setupDentalModelDemo(user);
  if (page === 'capture-archive' && typeof window.setupCaptureArchive === 'function') window.setupCaptureArchive(user);
  if (page === 'dental-arch-review' && typeof window.setupDentalArchReview === 'function') window.setupDentalArchReview(user);
  if (page === 'image-editor' && typeof window.setupImageEditor === 'function') window.setupImageEditor(user);
}

function normalizePrimaryNavigation() {
  const nav = document.querySelector('.site-header nav');
  if (!nav || document.documentElement.dataset.protected !== 'true') return;

  const page = document.documentElement.dataset.page || '';
  const activeByPage = {
    dashboard: 'dashboard.html',
    devices: 'devices.html',
    'device-assistant': 'devices.html',
    members: 'members.html',
    'member-profile': 'members.html',
    'capture-archive': 'members.html',
    detections: 'detections.html',
    'model-lab': 'detections.html',
    'image-editor': 'detections.html',
    'dental-model-demo': 'dental-model-demo.html',
    'dental-arch-review': 'dental-model-demo.html',
    'tooth-detail': 'dental-model-demo.html',
    'ai-dentist': 'ai-dentist.html',
    'ai-dentist-admin': 'ai-dentist.html',
    'family-reports': 'ai-dentist.html',
    'reference-library': 'ai-dentist.html',
    assistant: 'assistant.html'
  };
  const existingActive = nav.querySelector('a.active')?.getAttribute('href')?.split('?')[0] || '';
  const activeHref = activeByPage[page] || existingActive;
  const items = [
    ['dashboard.html', '概览'],
    ['devices.html', '设备'],
    ['members.html', '成员'],
    ['detections.html', '影像'],
    ['dental-model-demo.html', '牙列模型'],
    ['ai-dentist.html', 'AI牙医'],
    ['assistant.html', '助手']
  ];

  nav.replaceChildren(...items.map(([href, label]) => {
    const link = document.createElement('a');
    link.href = href;
    link.textContent = label;
    if (href === activeHref) {
      link.className = 'active';
      link.setAttribute('aria-current', 'page');
    }
    return link;
  }));
}

function boot() {
  setupTheme(); setupPasswordToggles(); normalizePrimaryNavigation();
  const page = document.documentElement.dataset.page;
  if (page === 'login') setupLogin();
  if (page === 'register') setupRegister();
  if (document.documentElement.dataset.protected === 'true') startProtected(page);
}

boot();
