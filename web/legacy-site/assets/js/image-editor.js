(() => {
  'use strict';
  const state = {
    meta: null,
    source: null,
    sourceCanvas: document.createElement('canvas'),
    finalCanvas: document.createElement('canvas'),
    detection: null,
    stage: 'original',
    mirror: false,
    rotation: 0,
    removeReflector: false,
    dirty: false,
    busy: false,
    zoom: 1,
    panX: 0,
    panY: 0,
    drag: null,
    settings: { brightness: 145, saturation: 155, blueBias: -12, closeSize: 19, padding: 28 },
  };
  const el = (id) => document.getElementById(id);
  const canvas = el('image-editor-canvas');
  const context = canvas.getContext('2d', { willReadFrequently: true });
  const viewport = el('image-editor-viewport');
  const clamp = (value, min, max) => Math.min(max, Math.max(min, value));

  function setMessage(text, type = '') {
    const message = el('image-editor-message');
    message.textContent = text || '';
    message.className = `image-editor-message${type ? ` is-${type}` : ''}`;
  }

  function setLoading(show, label = '正在处理图片') {
    const loading = el('image-editor-loading');
    loading.hidden = !show;
    loading.querySelector('strong').textContent = label;
    state.busy = show;
    updateActions();
  }

  function safeReturnUrl() {
    const requested = new URLSearchParams(location.search).get('return') || '';
    if (/^[a-z0-9-]+\.html(?:[?#][^\s]*)?$/i.test(requested)) return requested;
    return 'detections.html';
  }

  function loadImage(url) {
    return new Promise((resolve, reject) => {
      const image = new Image();
      image.onload = () => resolve(image);
      image.onerror = () => reject(new Error('图片文件读取失败。'));
      image.src = `${url}${url.includes('?') ? '&' : '?'}editor=${Date.now()}`;
    });
  }

  function odd(value) {
    const rounded = Math.max(3, Math.round(value));
    return rounded % 2 ? rounded : rounded + 1;
  }

  function integralMask(mask, width, height) {
    const stride = width + 1;
    const integral = new Uint32Array((height + 1) * stride);
    for (let y = 0; y < height; y += 1) {
      let row = 0;
      const sourceOffset = y * width;
      const targetOffset = (y + 1) * stride;
      const previousOffset = y * stride;
      for (let x = 0; x < width; x += 1) {
        row += mask[sourceOffset + x];
        integral[targetOffset + x + 1] = integral[previousOffset + x + 1] + row;
      }
    }
    return integral;
  }

  function boxMorphology(mask, width, height, radius, mode) {
    if (radius < 1) return mask.slice();
    const integral = integralMask(mask, width, height);
    const stride = width + 1;
    const output = new Uint8Array(mask.length);
    for (let y = 0; y < height; y += 1) {
      const y1 = Math.max(0, y - radius);
      const y2 = Math.min(height - 1, y + radius);
      for (let x = 0; x < width; x += 1) {
        const x1 = Math.max(0, x - radius);
        const x2 = Math.min(width - 1, x + radius);
        const sum = integral[(y2 + 1) * stride + x2 + 1] - integral[y1 * stride + x2 + 1]
          - integral[(y2 + 1) * stride + x1] + integral[y1 * stride + x1];
        const area = (x2 - x1 + 1) * (y2 - y1 + 1);
        output[y * width + x] = mode === 'dilate' ? Number(sum > 0) : Number(sum === area);
      }
    }
    return output;
  }

  const dilate = (mask, width, height, radius) => boxMorphology(mask, width, height, radius, 'dilate');
  const erode = (mask, width, height, radius) => boxMorphology(mask, width, height, radius, 'erode');
  const closeMask = (mask, width, height, radius) => erode(dilate(mask, width, height, radius), width, height, radius);
  const openMask = (mask, width, height, radius) => dilate(erode(mask, width, height, radius), width, height, radius);

  function filterComponents(binary, width, height, minimumArea, requireBorder) {
    const visited = new Uint8Array(binary.length);
    const selected = new Uint8Array(binary.length);
    const queue = new Int32Array(binary.length);
    const border = Math.max(2, Math.round(Math.min(width, height) * 0.012));
    const neighbours = [-1, 0, 1];
    for (let start = 0; start < binary.length; start += 1) {
      if (!binary[start] || visited[start]) continue;
      let head = 0;
      let tail = 0;
      let touches = false;
      queue[tail++] = start;
      visited[start] = 1;
      while (head < tail) {
        const index = queue[head++];
        const x = index % width;
        const y = Math.floor(index / width);
        if (x <= border || y <= border || x >= width - 1 - border || y >= height - 1 - border) touches = true;
        neighbours.forEach((dy) => neighbours.forEach((dx) => {
          if ((!dx && !dy)) return;
          const nx = x + dx;
          const ny = y + dy;
          if (nx < 0 || ny < 0 || nx >= width || ny >= height) return;
          const next = ny * width + nx;
          if (!binary[next] || visited[next]) return;
          visited[next] = 1;
          queue[tail++] = next;
        }));
      }
      if (tail >= minimumArea && (!requireBorder || touches)) {
        for (let offset = 0; offset < tail; offset += 1) selected[queue[offset]] = 1;
      }
    }
    return selected;
  }

  function fillHoles(mask, width, height) {
    const outside = new Uint8Array(mask.length);
    const queue = new Int32Array(mask.length);
    let head = 0;
    let tail = 0;
    const add = (index) => {
      if (index < 0 || index >= mask.length || mask[index] || outside[index]) return;
      outside[index] = 1;
      queue[tail++] = index;
    };
    for (let x = 0; x < width; x += 1) { add(x); add((height - 1) * width + x); }
    for (let y = 1; y < height - 1; y += 1) { add(y * width); add(y * width + width - 1); }
    while (head < tail) {
      const index = queue[head++];
      const x = index % width;
      const y = Math.floor(index / width);
      if (x > 0) add(index - 1);
      if (x + 1 < width) add(index + 1);
      if (y > 0) add(index - width);
      if (y + 1 < height) add(index + width);
    }
    const output = mask.slice();
    for (let i = 0; i < output.length; i += 1) if (!mask[i] && !outside[i]) output[i] = 1;
    return output;
  }

  function median(values) {
    values.sort((a, b) => a - b);
    return values[Math.floor(values.length / 2)] || 0;
  }

  function detectReflector() {
    const sourceWidth = state.sourceCanvas.width;
    const sourceHeight = state.sourceCanvas.height;
    const maxSide = 720;
    const scale = Math.min(1, maxSide / Math.max(sourceWidth, sourceHeight));
    const width = Math.max(1, Math.round(sourceWidth * scale));
    const height = Math.max(1, Math.round(sourceHeight * scale));
    const analysisCanvas = document.createElement('canvas');
    analysisCanvas.width = width;
    analysisCanvas.height = height;
    const analysisContext = analysisCanvas.getContext('2d', { willReadFrequently: true });
    analysisContext.drawImage(state.sourceCanvas, 0, 0, width, height);
    const imageData = analysisContext.getImageData(0, 0, width, height);
    const pixels = imageData.data;
    const values = new Uint8Array(width * height);
    const saturations = new Uint8Array(width * height);
    const blueDelta = new Int16Array(width * height);
    const edgeValues = [];
    const edgeWidth = Math.max(4, Math.round(Math.min(width, height) * 0.04));
    for (let i = 0; i < values.length; i += 1) {
      const offset = i * 4;
      const red = pixels[offset];
      const green = pixels[offset + 1];
      const blue = pixels[offset + 2];
      const high = Math.max(red, green, blue);
      const low = Math.min(red, green, blue);
      values[i] = high;
      saturations[i] = high ? Math.round(((high - low) * 255) / high) : 0;
      blueDelta[i] = blue - red;
      const x = i % width;
      const y = Math.floor(i / width);
      if (x < edgeWidth || x >= width - edgeWidth || y < edgeWidth || y >= height - edgeWidth) edgeValues.push(high);
    }
    const profile = median(edgeValues) >= 220 ? 'large' : 'thin';
    const candidate = new Uint8Array(width * height);
    const thinBrightness = clamp(state.settings.brightness + 100, 238, 250);
    const thinSaturation = Math.min(120, state.settings.saturation);
    for (let i = 0; i < candidate.length; i += 1) {
      if (profile === 'thin') {
        candidate[i] = Number(values[i] >= thinBrightness && saturations[i] <= thinSaturation);
      } else {
        const cyanWhite = values[i] >= state.settings.brightness
          && saturations[i] <= state.settings.saturation
          && blueDelta[i] >= state.settings.blueBias;
        const neutralHot = values[i] >= Math.min(245, state.settings.brightness + 70) && saturations[i] <= 55;
        candidate[i] = Number(cyanWhite || neutralHot);
      }
    }
    const closeRadius = Math.max(1, Math.floor((odd(profile === 'thin' ? Math.min(9, state.settings.closeSize) : state.settings.closeSize) * scale) / 2));
    const openRadius = Math.max(1, Math.floor(((profile === 'thin' ? 3 : 5) * scale) / 2));
    let prepared = openMask(closeMask(candidate, width, height, closeRadius), width, height, openRadius);
    const minimumArea = Math.max(12, Math.round(width * height * (profile === 'thin' ? 0.002 : 0.001)));
    let mask = filterComponents(prepared, width, height, minimumArea, true);
    if (profile === 'large') mask = fillHoles(mask, width, height);
    if (profile === 'thin') {
      const edgeStrip = Math.max(2, Math.round(Math.min(width, height) * 0.01));
      const sideSpan = Math.round(width * 0.36);
      for (let y = 0; y < height; y += 1) {
        for (let x = 0; x < width; x += 1) {
          const edgeFragment = x < edgeStrip || x >= width - edgeStrip
            || ((y < edgeStrip || y >= height - edgeStrip) && (x < sideSpan || x >= width - sideSpan));
          if (edgeFragment && prepared[y * width + x]) mask[y * width + x] = 1;
        }
      }
    }
    const requestedPadding = Math.max(0, state.settings.padding);
    const padding = profile === 'thin'
      ? Math.min(22, Math.max(4, Math.round(requestedPadding * 0.75)))
      : requestedPadding;
    const paddingRadius = Math.max(0, Math.round(padding * scale));
    if (paddingRadius) mask = dilate(mask, width, height, paddingRadius);
    if (profile === 'large' && paddingRadius) {
      const nearby = dilate(mask, width, height, Math.min(40, Math.max(8, paddingRadius * 3)));
      let fringe = new Uint8Array(mask.length);
      for (let i = 0; i < fringe.length; i += 1) {
        const x = i % width;
        const y = Math.floor(i / width);
        const outer = x < width * 0.22 || x >= width * 0.78 || y < height * 0.22 || y >= height * 0.78;
        fringe[i] = Number(nearby[i] && outer && values[i] >= 72 && blueDelta[i] >= 10);
      }
      fringe = closeMask(fringe, width, height, Math.max(1, Math.round(5 * scale)));
      fringe = filterComponents(fringe, width, height, Math.max(18, Math.round(width * height * 0.0001)), false);
      for (let i = 0; i < mask.length; i += 1) if (fringe[i]) mask[i] = 1;
      mask = dilate(mask, width, height, Math.max(1, Math.round(3 * scale)));
    }
    let count = 0;
    mask.forEach((value) => { count += value; });
    return { width, height, sourceWidth, sourceHeight, imageData, candidate, mask, profile, coverage: count / mask.length };
  }

  function buildFinalCanvas() {
    const base = document.createElement('canvas');
    base.width = state.sourceCanvas.width;
    base.height = state.sourceCanvas.height;
    const baseContext = base.getContext('2d', { willReadFrequently: Boolean(state.removeReflector) });
    baseContext.drawImage(state.sourceCanvas, 0, 0);
    if (state.removeReflector && state.detection) {
      const data = baseContext.getImageData(0, 0, base.width, base.height);
      const mask = state.detection.mask;
      const maskWidth = state.detection.width;
      const maskHeight = state.detection.height;
      for (let y = 0; y < base.height; y += 1) {
        const maskY = Math.min(maskHeight - 1, Math.floor((y * maskHeight) / base.height));
        for (let x = 0; x < base.width; x += 1) {
          const maskX = Math.min(maskWidth - 1, Math.floor((x * maskWidth) / base.width));
          if (!mask[maskY * maskWidth + maskX]) continue;
          const offset = (y * base.width + x) * 4;
          data.data[offset] = 0;
          data.data[offset + 1] = 0;
          data.data[offset + 2] = 0;
          data.data[offset + 3] = 255;
        }
      }
      baseContext.putImageData(data, 0, 0);
    }
    const radians = state.rotation * Math.PI / 180;
    const cosine = Math.abs(Math.cos(radians));
    const sine = Math.abs(Math.sin(radians));
    const outputWidth = Math.max(1, Math.ceil(base.width * cosine + base.height * sine));
    const outputHeight = Math.max(1, Math.ceil(base.width * sine + base.height * cosine));
    state.finalCanvas.width = outputWidth;
    state.finalCanvas.height = outputHeight;
    const output = state.finalCanvas.getContext('2d');
    output.save();
    output.fillStyle = '#000';
    output.fillRect(0, 0, outputWidth, outputHeight);
    output.translate(outputWidth / 2, outputHeight / 2);
    output.rotate(radians);
    output.scale(state.mirror ? -1 : 1, 1);
    output.drawImage(base, -base.width / 2, -base.height / 2);
    output.restore();
  }

  function stageCanvas() {
    if (state.stage === 'final') return state.finalCanvas;
    if (state.stage === 'original' || !state.detection) return state.sourceCanvas;
    const stage = document.createElement('canvas');
    stage.width = state.detection.width;
    stage.height = state.detection.height;
    const stageContext = stage.getContext('2d');
    if (state.stage === 'candidate') {
      const output = stageContext.createImageData(stage.width, stage.height);
      state.detection.candidate.forEach((value, index) => {
        const shade = value ? 255 : 0;
        const offset = index * 4;
        output.data[offset] = shade;
        output.data[offset + 1] = shade;
        output.data[offset + 2] = shade;
        output.data[offset + 3] = 255;
      });
      stageContext.putImageData(output, 0, 0);
      return stage;
    }
    stageContext.putImageData(state.detection.imageData, 0, 0);
    const overlay = stageContext.getImageData(0, 0, stage.width, stage.height);
    state.detection.mask.forEach((value, index) => {
      if (!value) return;
      const offset = index * 4;
      overlay.data[offset] = Math.round(overlay.data[offset] * 0.25);
      overlay.data[offset + 1] = Math.round(overlay.data[offset + 1] * 0.35 + 190);
      overlay.data[offset + 2] = Math.round(overlay.data[offset + 2] * 0.25 + 205);
    });
    stageContext.putImageData(overlay, 0, 0);
    return stage;
  }

  function render() {
    if (!state.source) return;
    buildFinalCanvas();
    const source = stageCanvas();
    canvas.width = source.width;
    canvas.height = source.height;
    context.clearRect(0, 0, canvas.width, canvas.height);
    context.drawImage(source, 0, 0);
    const labels = { original: '原始画面', candidate: '亮度、饱和度与青白偏色候选', mask: '青色区域将被去除', final: '当前保存效果' };
    el('image-editor-stage-label').textContent = labels[state.stage];
    resetZoom();
    updateActions();
  }

  function updateActions() {
    el('image-editor-reset').disabled = !state.source || !state.dirty || state.busy;
    el('image-editor-download').disabled = !state.source || state.busy;
    el('image-editor-save').disabled = !state.source || !state.dirty || state.busy;
    el('image-editor-dirty').textContent = state.busy ? '正在处理' : state.dirty ? '有未保存修改' : '尚未修改';
    el('image-editor-mirror').classList.toggle('is-active', state.mirror);
    el('image-editor-mirror-state').textContent = state.mirror ? '已镜像' : '未应用';
    el('image-editor-reflector').classList.toggle('is-active', state.removeReflector);
    el('image-editor-reflector-state').textContent = state.removeReflector ? '已应用' : '未应用';
  }

  function markDirty() {
    state.dirty = state.mirror || state.rotation !== 0 || state.removeReflector;
    updateActions();
  }

  function selectStage(stage) {
    if (!['original', 'candidate', 'mask', 'final'].includes(stage)) return;
    if ((stage === 'candidate' || stage === 'mask') && !state.detection) {
      setMessage('请先运行“自动识别并去除”，再查看识别过程。');
      return;
    }
    state.stage = stage;
    document.querySelectorAll('[data-editor-stage]').forEach((button) => {
      const active = button.dataset.editorStage === stage;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-selected', String(active));
    });
    render();
  }

  async function processReflector(force = false) {
    if (state.busy || !state.source) return;
    if (state.removeReflector && !force) {
      state.removeReflector = false;
      markDirty();
      selectStage('final');
      setMessage('已暂时关闭反光材料去除；保存前可再次开启。');
      return;
    }
    setLoading(true, '正在识别画面边缘反光材料');
    setMessage('处理在当前浏览器内完成，不会调用外部模型。');
    await new Promise((resolve) => setTimeout(resolve, 30));
    try {
      state.detection = detectReflector();
      state.removeReflector = true;
      state.stage = 'final';
      el('image-editor-profile').textContent = state.detection.profile === 'large' ? '大面积材料' : '细小光圈';
      el('image-editor-coverage').textContent = `${(state.detection.coverage * 100).toFixed(2)}%`;
      el('image-editor-process-size').textContent = `${state.detection.width}×${state.detection.height}`;
      markDirty();
      document.querySelectorAll('[data-editor-stage]').forEach((button) => {
        const active = button.dataset.editorStage === 'final';
        button.classList.toggle('is-active', active);
        button.setAttribute('aria-selected', String(active));
      });
      render();
      setMessage('反光材料识别完成。可切换处理阶段检查遮罩是否覆盖正确。', 'success');
    } catch (error) {
      setMessage(`反光材料识别失败：${error.message}`, 'error');
    } finally {
      setLoading(false);
    }
  }

  function resetEdits() {
    state.mirror = false;
    state.rotation = 0;
    state.removeReflector = false;
    state.detection = null;
    state.stage = 'original';
    state.dirty = false;
    el('image-editor-rotation').value = '0';
    el('image-editor-rotation-value').textContent = '0°';
    el('image-editor-profile').textContent = '—';
    el('image-editor-coverage').textContent = '—';
    el('image-editor-process-size').textContent = '—';
    document.querySelectorAll('[data-editor-stage]').forEach((button) => {
      const active = button.dataset.editorStage === 'original';
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-selected', String(active));
    });
    render();
    setMessage('本次未保存的修改已重置。');
  }

  function canvasBlob() {
    return new Promise((resolve, reject) => {
      state.finalCanvas.toBlob(async (png) => {
        if (!png) { reject(new Error('浏览器无法编码编辑结果。')); return; }
        if (png.size <= 15 * 1024 * 1024) { resolve({ blob: png, extension: 'png' }); return; }
        state.finalCanvas.toBlob((webp) => {
          if (!webp) reject(new Error('编辑结果超过服务器大小限制，请降低原图分辨率。'));
          else resolve({ blob: webp, extension: 'webp' });
        }, 'image/webp', 0.98);
      }, 'image/png');
    });
  }

  function operations() {
    return {
      horizontal_mirror: state.mirror,
      rotation_degrees: state.rotation,
      reflector_removal: state.removeReflector ? {
        applied: true,
        profile: state.detection?.profile || null,
        coverage: state.detection?.coverage || 0,
        settings: { ...state.settings },
        implementation: 'browser-classical-v1',
      } : { applied: false },
    };
  }

  async function save() {
    if (!state.dirty || state.busy) return;
    if (Number(state.meta?.analysis_count) > 0) {
      const confirmed = window.confirm(`这张图片关联 ${state.meta.analysis_count} 条既有模型分析。改变方向或画面内容后，原结果可能失效。是否保存并在需要时重新分析？`);
      if (!confirmed) return;
    }
    setLoading(true, '正在生成并保存图片修订');
    setMessage('正在保存新的图片修订文件…');
    try {
      buildFinalCanvas();
      const encoded = await canvasBlob();
      const form = new FormData();
      form.append('public_id', state.meta.public_id);
      form.append('operations', JSON.stringify(operations()));
      form.append('file', new File([encoded.blob], `chijing-edit.${encoded.extension}`, { type: encoded.blob.type }));
      const result = await window.chijingApiRequest('api/image_edit.php', { method: 'POST', body: form });
      setMessage(result.message, 'success');
      await loadDocument(result.public_id);
    } catch (error) {
      setMessage(error.message, 'error');
    } finally {
      setLoading(false);
    }
  }

  async function download() {
    if (!state.source || state.busy) return;
    buildFinalCanvas();
    const encoded = await canvasBlob();
    const link = document.createElement('a');
    link.href = URL.createObjectURL(encoded.blob);
    link.download = `chijing-${state.meta.public_id}-edited.${encoded.extension}`;
    link.click();
    setTimeout(() => URL.revokeObjectURL(link.href), 1000);
  }

  function updateMeta() {
    document.title = `${state.meta.member_name} · 查看与编辑 · 齿镜`;
    el('image-editor-meta').textContent = `${state.meta.member_name} · ${state.meta.width} × ${state.meta.height} · ${Math.ceil(state.meta.bytes / 1024)} KB · ${state.meta.analysis_count} 条关联分析`;
    if (state.meta.resolved_from_analysis) setMessage('当前记录是模型分析结果，编辑器已自动定位到它对应的原始影像。');
  }

  async function loadDocument(publicId) {
    setLoading(true, '正在读取原始影像');
    const data = await window.chijingApiRequest(`api/image_edit.php?id=${encodeURIComponent(publicId)}`);
    state.meta = data.image;
    state.source = await loadImage(state.meta.url);
    state.sourceCanvas.width = state.source.naturalWidth;
    state.sourceCanvas.height = state.source.naturalHeight;
    state.sourceCanvas.getContext('2d').drawImage(state.source, 0, 0);
    state.mirror = false;
    state.rotation = 0;
    state.removeReflector = false;
    state.detection = null;
    state.stage = 'original';
    state.dirty = false;
    el('image-editor-rotation').value = '0';
    el('image-editor-rotation-value').textContent = '0°';
    el('image-editor-profile').textContent = '—';
    el('image-editor-coverage').textContent = '—';
    el('image-editor-process-size').textContent = '—';
    document.querySelectorAll('[data-editor-stage]').forEach((button) => {
      const active = button.dataset.editorStage === 'original';
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-selected', String(active));
    });
    updateMeta();
    render();
    setLoading(false);
  }

  function resetZoom() {
    state.zoom = 1;
    state.panX = 0;
    state.panY = 0;
    paintZoom();
  }

  function paintZoom() {
    canvas.style.setProperty('--editor-zoom', String(state.zoom));
    canvas.style.setProperty('--editor-pan-x', `${state.panX}px`);
    canvas.style.setProperty('--editor-pan-y', `${state.panY}px`);
    el('image-editor-zoom-value').textContent = `${Math.round(state.zoom * 100)}%`;
  }

  function zoomTo(value, point = null) {
    const old = state.zoom;
    const next = clamp(value, 1, 8);
    const rect = viewport.getBoundingClientRect();
    const anchor = point || { x: rect.width / 2, y: rect.height / 2 };
    state.panX = anchor.x - rect.width / 2 - (anchor.x - rect.width / 2 - state.panX) * (next / old);
    state.panY = anchor.y - rect.height / 2 - (anchor.y - rect.height / 2 - state.panY) * (next / old);
    state.zoom = next;
    if (next === 1) { state.panX = 0; state.panY = 0; }
    paintZoom();
  }

  function bindControls() {
    el('image-editor-back').href = safeReturnUrl();
    document.querySelectorAll('[data-editor-stage]').forEach((button) => button.addEventListener('click', () => selectStage(button.dataset.editorStage)));
    el('image-editor-mirror').addEventListener('click', () => {
      state.mirror = !state.mirror;
      markDirty();
      selectStage('final');
    });
    document.querySelectorAll('[data-editor-rotate]').forEach((button) => button.addEventListener('click', () => {
      const value = Number(button.dataset.editorRotate);
      state.rotation = value === 0 ? 0 : clamp(state.rotation + value, -180, 180);
      el('image-editor-rotation').value = String(state.rotation);
      el('image-editor-rotation-value').textContent = `${state.rotation}°`;
      markDirty();
      selectStage('final');
    }));
    el('image-editor-rotation').addEventListener('input', (event) => {
      state.rotation = Number(event.target.value);
      el('image-editor-rotation-value').textContent = `${state.rotation}°`;
      markDirty();
      selectStage('final');
    });
    el('image-editor-reflector').addEventListener('click', () => processReflector());
    el('image-editor-reprocess').addEventListener('click', () => processReflector(true));
    document.querySelectorAll('.image-editor-advanced input').forEach((input) => input.addEventListener('input', () => {
      state.settings[input.name] = Number(input.value);
      document.querySelector(`[data-value-for="${input.name}"]`).textContent = input.value;
    }));
    el('image-editor-reset').addEventListener('click', resetEdits);
    el('image-editor-save').addEventListener('click', save);
    el('image-editor-download').addEventListener('click', () => download().catch((error) => setMessage(error.message, 'error')));
    document.querySelectorAll('[data-editor-zoom]').forEach((button) => button.addEventListener('click', () => {
      const action = button.dataset.editorZoom;
      if (action === 'in') zoomTo(state.zoom * 1.25);
      else if (action === 'out') zoomTo(state.zoom / 1.25);
      else resetZoom();
    }));
    viewport.addEventListener('wheel', (event) => {
      event.preventDefault();
      const rect = viewport.getBoundingClientRect();
      zoomTo(state.zoom * Math.exp(-event.deltaY * 0.0015), { x: event.clientX - rect.left, y: event.clientY - rect.top });
    }, { passive: false });
    viewport.addEventListener('pointerdown', (event) => {
      if (event.pointerType === 'mouse' && event.button !== 0) return;
      viewport.setPointerCapture(event.pointerId);
      state.drag = { id: event.pointerId, x: event.clientX, y: event.clientY, panX: state.panX, panY: state.panY };
      viewport.classList.add('is-dragging');
    });
    viewport.addEventListener('pointermove', (event) => {
      if (!state.drag || state.drag.id !== event.pointerId || state.zoom <= 1) return;
      state.panX = state.drag.panX + event.clientX - state.drag.x;
      state.panY = state.drag.panY + event.clientY - state.drag.y;
      paintZoom();
    });
    const stop = (event) => {
      if (viewport.hasPointerCapture(event.pointerId)) viewport.releasePointerCapture(event.pointerId);
      state.drag = null;
      viewport.classList.remove('is-dragging');
    };
    viewport.addEventListener('pointerup', stop);
    viewport.addEventListener('pointercancel', stop);
    viewport.addEventListener('dblclick', resetZoom);
    window.addEventListener('beforeunload', (event) => {
      if (!state.dirty) return;
      event.preventDefault();
      event.returnValue = '';
    });
  }

  bindControls();
  window.setupImageEditor = async () => {
    const publicId = new URLSearchParams(location.search).get('id') || '';
    if (!publicId) {
      setLoading(false);
      setMessage('没有指定要查看与编辑的图片。请从影像、成员或档案袋页面重新进入。', 'error');
      return;
    }
    try { await loadDocument(publicId); }
    catch (error) { setLoading(false); setMessage(error.message, 'error'); }
  };
})();
