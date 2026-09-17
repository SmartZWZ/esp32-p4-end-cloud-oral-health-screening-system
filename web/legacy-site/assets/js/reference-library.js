(() => {
  const el = (id) => document.getElementById(id);
  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);
  const statusLabel = (value) => ({ draft: '草稿', published: '已发布', archived: '已归档' })[value] || value;
  const operationLabel = (value) => ({ create_version: '新建版本', copy_version: '复制版本', delete_version: '删除草稿版本', begin_revision: '开始修订', upload_image: '上传图片', select_cloud_image: '选择云端影像', replace_image: '替换图片', edit_image: '编辑图片', reset_image: '重置图片', review_image: '确认图片', unreview_image: '取消确认', delete_draft_image: '删除草稿图片', publish_version: '发布版本', activate_version: '启用版本', deactivate_version: '停止使用', archive_version: '归档版本', restore_version: '恢复版本', toggle_validation: '切换质量判断', update_quality_settings: '更新判定策略', test_validation: '测试判断', label_test: '人工复核测试' })[value] || value;
  const state = { data: null, selectedId: '', pendingSlot: null, cloudImages: [], editorImage: null, sourceImage: null, rotation: 0, flipHorizontal: false, crop: null, cropStart: null, working: null, display: null, testHistory: [], testCandidateObjectUrl: '' };

  function message(text, success = false) {
    el('reference-message').textContent = text || '';
    el('reference-message').classList.toggle('is-success', success);
  }
  async function mutate(action, payload = {}, options = {}) {
    return request(`api/reference_library.php?action=${encodeURIComponent(action)}`, options.formData ? { method: 'POST', body: payload } : { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
  }
  function selectedVersion() { return state.data?.selected_version || null; }
  function imageMap() { return new Map((state.data?.images || []).map((item) => [item.slot_code, item])); }
  function imageById(id) { return (state.data?.images || []).find((item) => item.public_id === id); }
  function slotByCode(code) { return (state.data?.slots || []).find((slot) => slot.code === code); }

  async function bootstrap(versionId = state.selectedId) {
    const query = versionId ? `&version_id=${encodeURIComponent(versionId)}` : '';
    const data = await request(`api/reference_library.php?action=bootstrap${query}`);
    state.data = data;
    state.selectedId = data.selected_version?.public_id || '';
    render();
  }
  function render() { renderRuntime(); renderVersions(); renderSelectedVersion(); renderSlots(); }
  function renderRuntime() {
    const active = (state.data?.versions || []).find((item) => Number(item.is_active));
    el('reference-active-version').textContent = active ? `${active.version_code} · ${active.name}` : '未启用';
    const enabled = Boolean(state.data?.settings?.validation_enabled);
    el('reference-validation-state').textContent = enabled ? '运行中' : '已关闭';
    el('reference-validation-toggle').checked = enabled;
    el('reference-validation-toggle').disabled = !active;
    el('reference-orientation-state').textContent = '保持设备方向';
    const modelInput = el('reference-quality-model'); const thresholdInput = el('reference-quality-threshold');
    if (modelInput) modelInput.value = state.data?.settings?.quality_model || '';
    if (thresholdInput) thresholdInput.value = Number(state.data?.settings?.quality_confidence_threshold || 0.8).toFixed(2);
  }
  function renderVersions() {
    const root = el('reference-version-list'); const versions = state.data?.versions || [];
    if (!versions.length) { root.innerHTML = '<p class="reference-empty">还没有参考版本。新建后会生成21个固定槽位。</p>'; return; }
    root.innerHTML = versions.map((item) => `<button class="reference-version-item${item.public_id === state.selectedId ? ' is-selected' : ''}" type="button" data-version-id="${escapeHtml(item.public_id)}"><strong>${escapeHtml(item.name)}</strong><span>${escapeHtml(item.version_code)} · ${Number(item.image_count || 0)}/21</span><small><i class="reference-pill">${escapeHtml(statusLabel(item.status))}</i>${Number(item.is_active) ? '<i class="reference-pill is-active">正在使用</i>' : ''}</small></button>`).join('');
    root.querySelectorAll('[data-version-id]').forEach((button) => button.addEventListener('click', () => { state.selectedId = button.dataset.versionId; bootstrap(state.selectedId).catch((error) => message(error.message)); }));
  }
  function renderSelectedVersion() {
    const version = selectedVersion(); const actions = el('reference-version-actions');
    if (!version) {
      el('reference-version-code').textContent = 'NO VERSION'; el('reference-version-name').textContent = '先创建一个参考版本'; el('reference-version-description').textContent = '参考图片不会进入任何家庭成员的影像记录。'; el('reference-version-badges').replaceChildren(); actions.hidden = true;
      el('reference-progress-label').textContent = '0 / 21 张'; el('reference-review-label').textContent = '0 张已确认'; el('reference-progress-bar').style.width = '0%'; return;
    }
    const items = state.data.images || []; const reviewed = items.filter((item) => item.reviewed_at).length;
    el('reference-version-code').textContent = version.version_code; el('reference-version-name').textContent = version.name;
    el('reference-version-description').textContent = version.description || `${version.hardware_profile} · 创建于 ${version.created_at}`;
    const revising = version.status === 'draft' && Boolean(version.published_at); el('reference-version-badges').innerHTML = `<span class="reference-pill">${escapeHtml(statusLabel(version.status))}</span>${revising ? '<span class="reference-pill is-revision">修订中 · 待重新发布</span>' : ''}${Number(version.is_active) ? '<span class="reference-pill is-active">正在使用</span>' : ''}`;
    actions.hidden = false; el('reference-copy-version').hidden = false; el('reference-test-version').hidden = version.status === 'archived'; el('reference-publish-version').hidden = version.status !== 'draft'; el('reference-publish-version').textContent = revising ? '确认并重新发布' : '发布版本'; el('reference-activate-version').hidden = version.status !== 'published' || Number(version.is_active); el('reference-deactivate-version').hidden = !Number(version.is_active); el('reference-archive-version').hidden = version.status !== 'published' || Number(version.is_active); el('reference-restore-version').hidden = version.status !== 'archived'; el('reference-delete-version').hidden = version.status !== 'draft' || revising;
    el('reference-progress-label').textContent = `${items.length} / 21 张`; el('reference-review-label').textContent = `${reviewed} 张已确认`; el('reference-progress-bar').style.width = `${Math.round(items.length / 21 * 100)}%`;
  }
  function renderSlots() {
    const root = el('reference-slot-groups'); const version = selectedVersion();
    if (!version) { root.innerHTML = '<div class="reference-no-version">创建版本后，这里会出现21个固定槽位。</div>'; return; }
    const images = imageMap(); const regions = [];
    for (const slot of state.data.slots || []) { let region = regions.find((item) => item.id === slot.region_id); if (!region) { region = { id: slot.region_id, label: slot.region_label, slots: [] }; regions.push(region); } region.slots.push(slot); }
    const modifiable = version.status !== 'archived'; root.innerHTML = regions.map((region) => `<section class="reference-region"><div class="reference-region-head"><h3>${escapeHtml(region.label)}</h3><span>${escapeHtml(region.id)} · 患者本人左右</span></div><div class="reference-region-grid">${region.slots.map((slot) => slotCard(slot, images.get(slot.code), modifiable, version.status === 'draft')).join('')}</div></section>`).join('');
    root.querySelectorAll('[data-upload-slot]').forEach((button) => button.addEventListener('click', () => startUpload(button.dataset.uploadSlot)));
    root.querySelectorAll('[data-cloud-slot]').forEach((button) => button.addEventListener('click', () => openCloudPicker(button.dataset.cloudSlot)));
    root.querySelectorAll('[data-edit-image]').forEach((button) => button.addEventListener('click', () => openEditor(button.dataset.editImage)));
    root.querySelectorAll('[data-delete-image]').forEach((button) => button.addEventListener('click', () => deleteImage(button.dataset.deleteImage)));
    root.querySelectorAll('.reference-slot[data-slot-code]').forEach((card) => {
      if (!modifiable) return;
      ['dragenter', 'dragover'].forEach((type) => card.addEventListener(type, (event) => { event.preventDefault(); card.classList.add('is-dragging'); }));
      ['dragleave', 'drop'].forEach((type) => card.addEventListener(type, (event) => { event.preventDefault(); card.classList.remove('is-dragging'); }));
      card.addEventListener('drop', async (event) => { const file = event.dataTransfer?.files?.[0]; if (file && await ensureVersionEditable()) uploadFile(file, slotByCode(card.dataset.slotCode)); });
    });
  }
  function slotCard(slot, image, editable, allowDelete) {
    const source = image?.source_type === 'cloud_detection' ? '云端影像' : '管理员上传';
    return `<article class="reference-slot" data-slot-code="${escapeHtml(slot.code)}"><div class="reference-slot-image">${image ? `<img src="${escapeHtml(image.urls.current)}&t=${encodeURIComponent(image.updated_at || '')}" alt="${escapeHtml(slot.region_label)} ${escapeHtml(slot.distance_name)}"><span class="reference-slot-state${image.reviewed_at ? ' is-reviewed' : ''}">${image.reviewed_at ? '已确认' : '待确认'}</span>` : `<div class="reference-slot-empty"><div><strong>${String(slot.index).padStart(2, '0')}</strong><span>拖入硬件 JPEG<br>或从云端影像选择</span></div></div>`}</div><div class="reference-slot-body"><div class="reference-slot-title"><strong>${escapeHtml(slot.distance_name)}</strong><code>${escapeHtml(slot.distance_label)}</code></div><p class="reference-slot-meta">${image ? `${escapeHtml(source)} · ${image.image_width}×${image.image_height}` : escapeHtml(slot.code)}</p><div class="reference-slot-actions">${editable ? `<button class="text-button" type="button" data-upload-slot="${escapeHtml(slot.code)}">${image ? '替换' : '上传'}</button><button class="text-button" type="button" data-cloud-slot="${escapeHtml(slot.code)}">从影像选择</button>${image ? `<button class="text-button" type="button" data-edit-image="${escapeHtml(image.public_id)}">编辑与确认</button>${allowDelete ? `<button class="text-button" type="button" data-delete-image="${escapeHtml(image.public_id)}">删除</button>` : ''}` : ''}` : image ? `<button class="text-button" type="button" data-edit-image="${escapeHtml(image.public_id)}">查看</button>` : ''}</div></div></article>`;
  }

  async function ensureVersionEditable() { const version = selectedVersion(); if (!version || version.status === 'archived') return false; if (version.status === 'draft') return true; const warning = Number(version.is_active) ? '该版本正在使用。开始修订会立即停止线上质量判断；完成修改并逐张确认后，需要重新发布并再次设为当前版本。继续吗？' : '开始修订后，该版本会转为草稿；完成修改并确认后需要重新发布。继续吗？'; if (!confirm(warning)) return false; try { const result = await mutate('begin_revision', { version_id: version.public_id }); await bootstrap(version.public_id); await loadLogs(); message(result.was_active ? '已停止使用并进入修订。请修改、重新确认、发布并再次启用。' : '版本已进入修订状态。修改过的图片需要重新确认。', true); return true; } catch (error) { message(error.message); return false; } }
  async function startUpload(code) { if (!await ensureVersionEditable()) return; state.pendingSlot = slotByCode(code); if (!state.pendingSlot) return; el('reference-slot-file').value = ''; el('reference-slot-file').click(); }
  async function uploadFile(file, slot) {
    if (!slot || !selectedVersion()) return;
    if (!/image\/jpeg/i.test(file.type) && !/\.jpe?g$/i.test(file.name)) { message('参考图只接受硬件输出的 JPEG 文件。'); return; }
    const payload = new FormData(); payload.append('version_id', state.selectedId); payload.append('region_id', slot.region_id); payload.append('distance_label', slot.distance_label); payload.append('file', file);
    message(`正在上传 ${slot.region_label} · ${slot.distance_name}…`);
    try { await mutate('upload_image', payload, { formData: true }); await bootstrap(state.selectedId); message('参考图已放入槽位，请打开编辑器确认区域、距离和左右方向。', true); } catch (error) { message(error.message); }
  }
  async function loadCloudImages(query = '') { const data = await request(`api/reference_library.php?action=cloud_images&limit=160${query ? `&query=${encodeURIComponent(query)}` : ''}`); state.cloudImages = data.items || []; return state.cloudImages; }
  async function openCloudPicker(code) {
    if (!await ensureVersionEditable()) return;
    state.pendingSlot = slotByCode(code); if (!state.pendingSlot) return; el('reference-cloud-target').textContent = `目标槽位：${state.pendingSlot.region_label} · ${state.pendingSlot.distance_name}`; el('reference-cloud-dialog').showModal(); el('reference-cloud-grid').innerHTML = '<p>正在读取影像…</p>';
    try { renderCloudImages(await loadCloudImages()); } catch (error) { el('reference-cloud-grid').textContent = error.message; }
  }
  function renderCloudImages(items) {
    const root = el('reference-cloud-grid'); if (!items.length) { root.innerHTML = '<p>没有找到可用云端影像。</p>'; return; }
    root.innerHTML = items.map((item) => `<button class="reference-cloud-card" type="button" data-cloud-image="${escapeHtml(item.public_id)}"><img src="api/image.php?id=${encodeURIComponent(item.public_id)}" alt="口腔影像"><span>${escapeHtml(item.member_name)}<small>${escapeHtml(item.created_at)} · ${item.source_type === 'device' ? '设备采集' : '网页上传'}</small></span></button>`).join('');
    root.querySelectorAll('[data-cloud-image]').forEach((button) => button.addEventListener('click', () => assignCloudImage(button.dataset.cloudImage)));
  }
  async function assignCloudImage(detectionId) {
    if (!state.pendingSlot) return; const slot = state.pendingSlot; message(`正在复制 ${slot.region_label} · ${slot.distance_name}…`);
    try { await mutate('assign_cloud_image', { version_id: state.selectedId, region_id: slot.region_id, distance_label: slot.distance_label, detection_id: detectionId }); el('reference-cloud-dialog').close(); await bootstrap(state.selectedId); message('云端影像已放入槽位。系统根据影像方向记录避免重复翻转。', true); } catch (error) { message(error.message); }
  }
  async function deleteImage(id) { const image = imageById(id); if (!image || !confirm(`删除槽位 ${image.slot_code} 中的参考图？`)) return; try { await mutate('delete_image', { image_id: id }); await bootstrap(state.selectedId); message('草稿参考图已删除。', true); } catch (error) { message(error.message); } }

  async function openEditor(id) {
    const version = selectedVersion(); if (!version) return; if (version.status !== 'archived' && !await ensureVersionEditable()) return; const image = imageById(id); if (!image) return; state.editorImage = image; state.rotation = 0; state.flipHorizontal = false; state.crop = null; state.cropStart = null;
    el('reference-editor-title').textContent = `${image.slot_code} · ${image.reviewed_at ? '已确认' : '待确认'}`; el('reference-editor-note').value = image.note || ''; el('reference-editor-distance').value = image.captured_distance || ''; el('reference-editor-lighting').value = image.lighting_note || ''; el('reference-editor-reviewed').checked = Boolean(image.reviewed_at); el('reference-editor-message').textContent = '';
    const editable = selectedVersion()?.status === 'draft'; ['reference-rotation', 'reference-rotate-left', 'reference-auto-straighten', 'reference-rotate-right', 'reference-flip-horizontal', 'reference-clear-crop', 'reference-reset-image', 'reference-save-metadata', 'reference-save-image', 'reference-editor-note', 'reference-editor-distance', 'reference-editor-lighting', 'reference-editor-reviewed'].forEach((controlId) => { el(controlId).disabled = !editable; }); el('reference-flip-horizontal').classList.remove('is-active'); el('reference-flip-horizontal').textContent = '左右镜像';
    el('reference-editor-dialog').showModal(); const source = new Image(); source.onload = () => { state.sourceImage = source; updateRotation(0, false); }; source.onerror = () => { el('reference-editor-message').textContent = '参考图读取失败。'; }; source.src = `${image.urls.current}&t=${Date.now()}`;
  }
  function buildWorkingCanvas() {
    const source = state.sourceImage; if (!source) return null; const angle = state.rotation * Math.PI / 180; const sin = Math.abs(Math.sin(angle)); const cos = Math.abs(Math.cos(angle)); const canvas = document.createElement('canvas'); canvas.width = Math.max(1, Math.ceil(source.naturalWidth * cos + source.naturalHeight * sin)); canvas.height = Math.max(1, Math.ceil(source.naturalWidth * sin + source.naturalHeight * cos)); const ctx = canvas.getContext('2d'); ctx.imageSmoothingEnabled = true; ctx.imageSmoothingQuality = 'high'; ctx.translate(canvas.width / 2, canvas.height / 2); ctx.rotate(angle); if (state.flipHorizontal) ctx.scale(-1, 1); ctx.drawImage(source, -source.naturalWidth / 2, -source.naturalHeight / 2); state.working = canvas; return canvas;
  }
  function drawEditor() {
    const working = buildWorkingCanvas(); if (!working) return; const canvas = el('reference-editor-canvas'); const rect = canvas.getBoundingClientRect(); const ratio = Math.min(devicePixelRatio || 1, 2); canvas.width = Math.max(1, Math.round(rect.width * ratio)); canvas.height = Math.max(1, Math.round(rect.height * ratio)); const scale = Math.min(canvas.width / working.width, canvas.height / working.height); const width = working.width * scale; const height = working.height * scale; const x = (canvas.width - width) / 2; const y = (canvas.height - height) / 2; const ctx = canvas.getContext('2d'); ctx.clearRect(0, 0, canvas.width, canvas.height); ctx.fillStyle = '#090909'; ctx.fillRect(0, 0, canvas.width, canvas.height); ctx.drawImage(working, x, y, width, height); state.display = { x, y, width, height, scale };
    if (state.crop) { const c = state.crop; ctx.save(); ctx.fillStyle = 'rgba(0,0,0,.5)'; ctx.beginPath(); ctx.rect(x, y, width, height); ctx.rect(x + c.x * scale, y + c.y * scale, c.w * scale, c.h * scale); ctx.fill('evenodd'); ctx.strokeStyle = '#fff'; ctx.lineWidth = 2 * ratio; ctx.setLineDash([7 * ratio, 5 * ratio]); ctx.strokeRect(x + c.x * scale, y + c.y * scale, c.w * scale, c.h * scale); ctx.restore(); el('reference-crop-label').textContent = `${Math.round(c.w)} × ${Math.round(c.h)} px`; } else el('reference-crop-label').textContent = '尚未选择裁剪区域';
  }
  function markEditorChanged() { el('reference-editor-reviewed').checked = false; el('reference-editor-message').textContent = '图片已调整，请目视确认区域、距离和左右方向后再勾选确认。'; }
  function updateRotation(value, userChange = true) { state.rotation = Math.max(-180, Math.min(180, Number(value) || 0)); state.crop = null; el('reference-rotation').value = String(state.rotation); el('reference-rotation-value').textContent = `${state.rotation.toFixed(1).replace('.0', '')}°`; if (userChange) markEditorChanged(); requestAnimationFrame(drawEditor); }
  function toggleHorizontalMirror() { state.flipHorizontal = !state.flipHorizontal; state.crop = null; const button = el('reference-flip-horizontal'); button.classList.toggle('is-active', state.flipHorizontal); button.textContent = state.flipHorizontal ? '取消左右镜像' : '左右镜像'; markEditorChanged(); requestAnimationFrame(drawEditor); }
  function canvasPoint(event) { const canvas = el('reference-editor-canvas'); const rect = canvas.getBoundingClientRect(); const px = (event.clientX - rect.left) * canvas.width / rect.width; const py = (event.clientY - rect.top) * canvas.height / rect.height; const d = state.display; if (!d || px < d.x || py < d.y || px > d.x + d.width || py > d.y + d.height) return null; return { x: Math.max(0, Math.min(state.working.width, (px - d.x) / d.scale)), y: Math.max(0, Math.min(state.working.height, (py - d.y) / d.scale)) }; }
  function setupCropPointer() {
    const canvas = el('reference-editor-canvas');
    canvas.addEventListener('pointerdown', (event) => { if (!state.editorImage || selectedVersion()?.status !== 'draft') return; const point = canvasPoint(event); if (!point) return; markEditorChanged(); state.cropStart = point; state.crop = { x: point.x, y: point.y, w: 1, h: 1 }; canvas.setPointerCapture(event.pointerId); });
    canvas.addEventListener('pointermove', (event) => { if (!state.cropStart) return; const point = canvasPoint(event); if (!point) return; const x = Math.min(point.x, state.cropStart.x); const y = Math.min(point.y, state.cropStart.y); state.crop = { x, y, w: Math.abs(point.x - state.cropStart.x), h: Math.abs(point.y - state.cropStart.y) }; drawEditor(); });
    canvas.addEventListener('pointerup', () => { if (state.crop && (state.crop.w < 10 || state.crop.h < 10)) state.crop = null; state.cropStart = null; drawEditor(); });
  }
  function estimateStraighten() {
    if (!state.sourceImage) return; const sample = document.createElement('canvas'); const scale = Math.min(1, 320 / Math.max(state.sourceImage.naturalWidth, state.sourceImage.naturalHeight)); sample.width = Math.max(40, Math.round(state.sourceImage.naturalWidth * scale)); sample.height = Math.max(40, Math.round(state.sourceImage.naturalHeight * scale)); const ctx = sample.getContext('2d', { willReadFrequently: true }); ctx.drawImage(state.sourceImage, 0, 0, sample.width, sample.height); const data = ctx.getImageData(0, 0, sample.width, sample.height).data; let jxx = 0; let jyy = 0; let jxy = 0; const gray = (x, y) => { const i = (y * sample.width + x) * 4; return data[i] * .299 + data[i + 1] * .587 + data[i + 2] * .114; };
    for (let y = 1; y < sample.height - 1; y += 2) for (let x = 1; x < sample.width - 1; x += 2) { const gx = gray(x + 1, y) - gray(x - 1, y); const gy = gray(x, y + 1) - gray(x, y - 1); if (gx * gx + gy * gy < 500) continue; jxx += gx * gx; jyy += gy * gy; jxy += gx * gy; }
    let lineAngle = .5 * Math.atan2(2 * jxy, jxx - jyy) * 180 / Math.PI + 90; while (lineAngle > 90) lineAngle -= 180; while (lineAngle < -90) lineAngle += 180; const correction = Math.max(-12, Math.min(12, -lineAngle)); updateRotation(correction); el('reference-editor-message').textContent = `自动拉直试算 ${correction.toFixed(1)}°，请目视确认后保存。`;
  }
  async function saveEditedImage() {
    if (!state.working || !state.editorImage) return; const crop = state.crop || { x: 0, y: 0, w: state.working.width, h: state.working.height }; const width = Math.round(crop.w); const height = Math.round(crop.h); if (width < 320 || height < 320) { el('reference-editor-message').textContent = '裁剪区域至少需要 320 × 320 像素。'; return; }
    const output = document.createElement('canvas'); output.width = width; output.height = height; output.getContext('2d').drawImage(state.working, Math.round(crop.x), Math.round(crop.y), width, height, 0, 0, width, height); el('reference-save-image').disabled = true; el('reference-editor-message').textContent = '正在保存图像编辑…';
    output.toBlob(async (blob) => { try { if (!blob) throw new Error('浏览器生成 JPEG 失败。'); const mirrored = state.flipHorizontal; const reviewed = el('reference-editor-reviewed').checked; const form = new FormData(); form.append('image_id', state.editorImage.public_id); form.append('file', blob, 'edited.jpg'); form.append('horizontal_flipped', mirrored ? '1' : '0'); form.append('reviewed', reviewed ? '1' : '0'); form.append('note', el('reference-editor-note').value.trim()); form.append('captured_distance', el('reference-editor-distance').value.trim()); form.append('lighting_note', el('reference-editor-lighting').value.trim()); await mutate('edit_image', form, { formData: true }); el('reference-editor-dialog').close(); await bootstrap(state.selectedId); message(reviewed ? '图像编辑已保存并重新确认。' : (mirrored ? '图像已左右镜像并保存；请重新确认该参考图。' : '图像编辑已保存；该参考图仍待确认。'), true); } catch (error) { el('reference-editor-message').textContent = error.message; } finally { el('reference-save-image').disabled = false; } }, 'image/jpeg', .94);
  }
  async function saveMetadata() { if (!state.editorImage) return; el('reference-editor-message').textContent = '正在保存资料…'; try { await mutate('review_image', { image_id: state.editorImage.public_id, note: el('reference-editor-note').value.trim(), captured_distance: el('reference-editor-distance').value.trim(), lighting_note: el('reference-editor-lighting').value.trim(), reviewed: el('reference-editor-reviewed').checked }); el('reference-editor-dialog').close(); await bootstrap(state.selectedId); message('参考图资料与审核状态已保存。', true); } catch (error) { el('reference-editor-message').textContent = error.message; } }
  async function resetImage() { if (!state.editorImage || !confirm('恢复到首次上传的原始基准图？当前裁剪和旋转会丢失。')) return; try { await mutate('reset_image', { image_id: state.editorImage.public_id }); el('reference-editor-dialog').close(); await bootstrap(state.selectedId); message('已恢复原始基准图，需要重新确认。', true); } catch (error) { el('reference-editor-message').textContent = error.message; } }

  async function loadLogs() { try { const data = await request('api/reference_library.php?action=logs'); const body = el('reference-log-body'); body.innerHTML = data.items?.length ? data.items.map((item) => `<tr><td>${escapeHtml(item.created_at)}</td><td>${escapeHtml(operationLabel(item.operation))}</td><td>${escapeHtml(item.version_code || '—')}</td><td>${escapeHtml(item.slot_code || '—')}</td><td>${escapeHtml(item.email)}</td><td>${escapeHtml(item.ip_address || '—')}</td></tr>`).join('') : '<tr><td colspan="6">还没有后台操作记录。</td></tr>'; } catch (error) { el('reference-log-body').innerHTML = `<tr><td colspan="6">${escapeHtml(error.message)}</td></tr>`; } }
  async function createVersion(event) { event.preventDefault(); const form = event.currentTarget; const data = Object.fromEntries(new FormData(form).entries()); el('reference-version-form-message').textContent = '正在创建…'; try { const result = await mutate('create_version', data); el('reference-version-dialog').close(); form.reset(); state.selectedId = result.public_id; await bootstrap(result.public_id); await loadLogs(); message(`已创建 ${result.version_code}。`, true); } catch (error) { el('reference-version-form-message').textContent = error.message; } }
  async function duplicateVersion() { const version = selectedVersion(); if (!version) return; const name = prompt('新草稿名称', `${version.name} · 副本`); if (!name) return; try { const result = await mutate('duplicate_version', { version_id: version.public_id, name }); state.selectedId = result.public_id; await bootstrap(result.public_id); await loadLogs(); message(`已复制为 ${result.version_code}，所有图片需要重新确认后才能发布。`, true); } catch (error) { message(error.message); } }
  async function simpleVersionAction(action, confirmation, successText) { const version = selectedVersion(); if (!version || (confirmation && !confirm(confirmation))) return; try { await mutate(action, { version_id: version.public_id }); await bootstrap(version.public_id); await loadLogs(); message(successText, true); } catch (error) { message(error.message); } }
  async function deleteVersion() { const version=selectedVersion();if(!version||version.status!=='draft'||!confirm(`删除草稿版本“${version.name}”及其中全部参考图？此操作不能撤销。`))return;try{await mutate('delete_version',{version_id:version.public_id});state.selectedId='';await bootstrap('');await loadLogs();message('草稿版本已删除。',true);}catch(error){message(error.message);} }
  function groupRegions() { const values = {}; (state.data?.slots || []).forEach((slot) => { values[slot.region_id] = slot.region_label; }); return values; }

  const testLabels = {
    distance: { too_far: '太远', too_close: '太近', good: '合适', unknown: '无法判断' },
    sharpness: { good: '清晰', blurred: '模糊', unknown: '无法判断' },
    lighting: { good: '合适', too_dark: '偏暗', overexposed: '过曝', unknown: '无法判断' },
    framing: { complete: '完整', partial: '不完整', unknown: '无法判断' },
  };
  const humanVerdicts = {
    '': '尚未复核', accepted: '合格', too_far: '太远', too_close: '太近', wrong_region: '区域错误', blurred: '模糊', lighting: '光照问题', framing: '取景不完整', uncertain: '人工也无法确定',
  };
  const formatMs = (value) => Number.isFinite(Number(value)) ? `${Number(value).toLocaleString('zh-CN')} ms` : '—';
  const formatRate = (value, count) => Number(count) > 0 && Number.isFinite(Number(value)) ? `${Math.round(Number(value) * 100)}%` : '—';
  function clearTestCandidateObjectUrl() { if (state.testCandidateObjectUrl) URL.revokeObjectURL(state.testCandidateObjectUrl); state.testCandidateObjectUrl = ''; }
  function setTestCandidatePreview(url, label = '本次候选图') {
    const root = el('reference-test-candidate-preview');
    root.innerHTML = url ? `<img src="${escapeHtml(url)}" alt="${escapeHtml(label)}">` : '<span>选择测试图片</span>';
    const image = root.querySelector('img'); if (image) image.addEventListener('error', () => { root.innerHTML = '<span>测试图片已清理或不可读取</span>'; }, { once: true });
    root.closest('figure').querySelector('figcaption span').textContent = label;
  }
  function renderTestReferences() {
    const region = el('reference-test-region').value; const images = state.data?.images || []; let complete = true;
    ['too_far', 'too_close', 'good'].forEach((distance) => {
      const figure = document.querySelector(`[data-test-distance="${distance}"]`); const image = images.find((item) => item.region_id === region && item.distance_label === distance); const stage = figure.querySelector('div');
      if (image) stage.innerHTML = `<img src="${escapeHtml(image.urls.current)}&t=${encodeURIComponent(image.updated_at || '')}" alt="${escapeHtml(groupRegions()[region])} ${escapeHtml(testLabels.distance[distance])}">`;
      else { stage.innerHTML = '<span>缺少参考图</span>'; complete = false; }
    });
    const readiness = el('reference-test-readiness'); readiness.className = `reference-test-readiness ${complete ? 'is-ready' : 'is-error'}`; readiness.textContent = complete ? '三张参考图已齐全，可以运行四图比较。' : '该区域参考图不完整，请先补齐“太远、太近、合适”。'; el('reference-run-test').disabled = !complete;
    return complete;
  }
  function renderTestResult(result = null, meta = {}) {
    const status = el('reference-test-status'); const facts = el('reference-test-facts').querySelectorAll('strong'); const timing = meta.timing || result?._timing || {};
    if (!result) {
      status.dataset.state = meta.state || 'idle'; el('reference-test-verdict-label').textContent = meta.label || '等待测试'; el('reference-test-verdict').textContent = meta.title || '尚未运行'; el('reference-test-advice').textContent = meta.advice || '选择区域和候选图后开始测试。'; facts.forEach((item) => { item.textContent = '—'; }); el('reference-test-timing').querySelectorAll('strong').forEach((item) => { item.textContent = '—'; }); el('reference-test-summary').textContent = meta.summary || ''; return;
    }
    const accepted = Boolean(result.accepted); status.dataset.state = accepted ? 'accepted' : 'rejected'; el('reference-test-verdict-label').textContent = accepted ? 'CAPTURE ACCEPTED' : 'ADJUST AND RETRY'; el('reference-test-verdict').textContent = accepted ? '可以采纳' : '需要重拍'; el('reference-test-advice').textContent = result.instruction || '请按判断结果调整后重拍。';
    const regionName = result.detected_region === 'unknown' ? '无法判断' : (groupRegions()[result.detected_region] || result.detected_region); const threshold = Number(result.confidence_threshold ?? state.data?.settings?.quality_confidence_threshold ?? 0.8); const values = [testLabels.distance[result.distance] || result.distance, result.region_match ? `匹配 · ${regionName}` : `不匹配 · ${regionName}`, testLabels.sharpness[result.sharpness] || result.sharpness, testLabels.lighting[result.lighting] || result.lighting, testLabels.framing[result.framing] || result.framing, `${Math.round(Number(result.confidence || 0) * 100)}% / 门槛 ${Math.round(threshold * 100)}%`]; facts.forEach((item, index) => { item.textContent = values[index] || '—'; });
    const similarityDecision = result._similarity?.decision; const similarityText = similarityDecision === 'mirrored_opposite_region' ? '像素校验：检测到水平镜像' : similarityDecision === 'approved_reference_match' ? '像素校验：匹配合适参考图' : result._similarity?.available === false ? '像素校验未运行，请确认服务器 PHP GD 扩展' : '';
    const timeValues = [timing.prepare_ms, timing.model_ms ?? meta.latency_ms, timing.persist_ms, timing.total_ms ?? meta.latency_ms, timing.client_roundtrip_ms]; el('reference-test-timing').querySelectorAll('strong').forEach((item, index) => { item.textContent = formatMs(timeValues[index]); }); el('reference-test-summary').textContent = [result.summary, similarityText, result.model_claimed_accepted !== undefined && Boolean(result.model_claimed_accepted) !== accepted ? '服务端已按规则纠正模型结论' : '', meta.model ? `模型：${meta.model}` : '', meta.testId ? `测试编号：${meta.testId}` : ''].filter(Boolean).join(' · ');
  }

  async function saveQualitySettings() {
    const button = el('reference-save-quality-settings'); const output = el('reference-quality-settings-message'); const model = el('reference-quality-model').value.trim(); const threshold = Number(el('reference-quality-threshold').value);
    if (!Number.isFinite(threshold) || threshold < 0.5 || threshold > 0.99) { output.textContent = '最低通过置信度必须在 0.50 到 0.99 之间。'; return; }
    button.disabled = true; output.textContent = '正在保存判定设置…';
    try { await mutate('save_quality_settings', { quality_model: model, quality_confidence_threshold: threshold }); await bootstrap(state.selectedId); output.textContent = model ? `已使用 ${model}，通过门槛 ${Math.round(threshold * 100)}%。` : `已跟随 AI 牙医主模型，通过门槛 ${Math.round(threshold * 100)}%。`; await loadLogs(); }
    catch (error) { output.textContent = error.message; }
    finally { button.disabled = false; }
  }

  async function saveHumanLabel(testId, verdict, currentNote = '') {
    if (!verdict) return;
    const note = prompt('可选：记录人工判断依据（可留空）', currentNote || ''); if (note === null) return;
    try { await mutate('label_test', { test_id: testId, human_verdict: verdict, human_note: note.trim() }); await Promise.all([loadTestHistory(), loadLogs()]); }
    catch (error) { message(error.message); }
  }
  async function loadTestHistory() {
    const body = el('reference-test-history-body'); body.innerHTML = '<tr><td colspan="10">正在读取测试记录…</td></tr>';
    try {
      const data = await request(`api/reference_library.php?action=tests&version_id=${encodeURIComponent(state.selectedId)}&limit=30`); state.testHistory = data.items || [];
      const calibration = data.calibration || {}; el('reference-calibration-labeled').textContent = String(calibration.labeled_count || 0); el('reference-calibration-agreement').textContent = formatRate(calibration.accepted_agreement_rate, calibration.labeled_count); el('reference-calibration-distance').textContent = formatRate(calibration.distance_accuracy_rate, calibration.distance_labeled_count);
      body.innerHTML = state.testHistory.length ? state.testHistory.map((item) => {
        const result = item.result || {}; const timing = result._timing || {}; const options = Object.entries(humanVerdicts).filter(([value]) => value).map(([value, label]) => `<option value="${value}"${item.human_verdict === value ? ' selected' : ''}>${escapeHtml(label)}</option>`).join('');
        return `<tr data-test-id="${escapeHtml(item.public_id)}"><td>${escapeHtml(item.created_at)}</td><td>${escapeHtml(groupRegions()[item.expected_region] || item.expected_region)}</td><td>${item.success ? (result.accepted ? '可以采纳' : '需要重拍') : '测试失败'}</td><td>${escapeHtml(result.instruction || item.error_message || '—')}</td><td>${escapeHtml(formatMs(timing.model_ms ?? item.latency_ms))}</td><td>${escapeHtml(formatMs(timing.total_ms ?? item.latency_ms))}</td><td>${escapeHtml(formatMs(timing.client_roundtrip_ms))}</td><td>${escapeHtml(item.model_name || '—')}</td><td>${escapeHtml(humanVerdicts[item.human_verdict || ''] || item.human_verdict || '尚未复核')}${item.human_note ? `<small class="reference-review-note">${escapeHtml(item.human_note)}</small>` : ''}</td><td><div class="reference-review-control"><select data-human-verdict aria-label="人工复核结论"><option value="">选择结论</option>${options}</select><button class="text-button" type="button" data-save-label>保存</button></div></td></tr>`;
      }).join('') : '<tr><td colspan="10">这个版本还没有测试记录。</td></tr>';
      body.querySelectorAll('[data-test-id]').forEach((row) => row.addEventListener('click', () => { const item = state.testHistory.find((entry) => entry.public_id === row.dataset.testId); if (!item) return; el('reference-test-region').value = item.expected_region; renderTestReferences(); setTestCandidatePreview(`${item.candidate_url}&t=${Date.now()}`, '历史候选图'); if (item.success && item.result) renderTestResult(item.result, { model: item.model_name, latency_ms: item.latency_ms, testId: item.public_id }); else renderTestResult(null, { state: 'error', label: 'TEST FAILED', title: '测试失败', advice: item.error_message || '模型调用失败。', summary: `测试编号：${item.public_id}` }); }));
      body.querySelectorAll('select,button').forEach((control) => control.addEventListener('click', (event) => event.stopPropagation()));
      body.querySelectorAll('[data-save-label]').forEach((button) => button.addEventListener('click', () => { const row = button.closest('[data-test-id]'); const item = state.testHistory.find((entry) => entry.public_id === row?.dataset.testId); const verdict = row?.querySelector('[data-human-verdict]')?.value || ''; if (!item || !verdict) { message('请先选择人工复核结论。'); return; } saveHumanLabel(item.public_id, verdict, item.human_note || ''); }));
    } catch (error) { body.innerHTML = `<tr><td colspan="10">${escapeHtml(error.message)}</td></tr>`; }
  }
  async function openTest() {
    const version = selectedVersion(); if (!version || version.status === 'archived') return; clearTestCandidateObjectUrl(); el('reference-test-form').reset(); el('reference-quality-model').value = state.data?.settings?.quality_model || ''; el('reference-quality-threshold').value = Number(state.data?.settings?.quality_confidence_threshold || 0.8).toFixed(2); el('reference-quality-settings-message').textContent = '通过还必须同时满足区域、距离、清晰度、光照和取景要求。'; const region = el('reference-test-region'); region.replaceChildren(...Object.entries(groupRegions()).map(([id, label]) => new Option(label, id))); renderTestReferences(); setTestCandidatePreview(''); renderTestResult(); el('reference-test-dialog').showModal();
    try { const items = state.cloudImages.length ? state.cloudImages : await loadCloudImages(); const cloud = el('reference-test-cloud'); cloud.replaceChildren(new Option('不选择云端影像', '')); items.forEach((item) => cloud.add(new Option(`${item.member_name} · ${item.created_at} · ${item.public_id}`, item.public_id))); await loadTestHistory(); } catch (error) { renderTestResult(null, { state: 'error', label: 'SOURCE ERROR', title: '候选影像读取失败', advice: error.message }); }
  }
  async function runTest(event) {
    event.preventDefault(); const file = el('reference-test-file').files?.[0]; const cloud = el('reference-test-cloud').value; if (!renderTestReferences()) return; if (!file && !cloud) { renderTestResult(null, { state: 'error', label: 'INPUT REQUIRED', title: '缺少测试图片', advice: '请从云端影像选择，或上传一张 JPEG。' }); return; }
    const form = new FormData(); form.append('version_id', state.selectedId); form.append('expected_region', el('reference-test-region').value); if (file) form.append('candidate_file', file); else form.append('candidate_detection_id', cloud); el('reference-run-test').disabled = true; renderTestResult(null, { state: 'running', label: 'MODEL RUNNING', title: '正在比较参考图与候选图', advice: '左右侧区域会同时使用反侧参考图校验方向…' }); const clientStarted = performance.now();
    try { const response = await mutate('test_validation', form, { formData: true }); const clientRoundtripMs = Math.round(performance.now() - clientStarted); response.result._timing = { ...(response.result._timing || response.timing || {}), client_roundtrip_ms: clientRoundtripMs }; setTestCandidatePreview(`${response.candidate_url}&t=${Date.now()}`, '本次候选图'); renderTestResult(response.result, { model: response.model, latency_ms: response.latency_ms, timing: response.result._timing, testId: response.test_id }); try { await mutate('record_test_client_timing', { test_id: response.test_id, client_roundtrip_ms: clientRoundtripMs }); } catch (timingError) { console.warn('端到端计时未能写入历史记录：', timingError); } await Promise.all([loadLogs(), loadTestHistory()]); } catch (error) { renderTestResult(null, { state: 'error', label: 'TEST FAILED', title: '测试失败', advice: error.message }); } finally { el('reference-run-test').disabled = !renderTestReferences(); }
  }

  function bindEvents() {
    el('reference-new-version').addEventListener('click', () => { el('reference-version-form-message').textContent = ''; el('reference-version-dialog').showModal(); }); document.querySelectorAll('.reference-dialog-close').forEach((button) => button.addEventListener('click', () => el('reference-version-dialog').close())); el('reference-version-form').addEventListener('submit', createVersion);
    el('reference-slot-file').addEventListener('change', () => { const file = el('reference-slot-file').files?.[0]; if (file && state.pendingSlot) uploadFile(file, state.pendingSlot); });
    el('reference-cloud-search').addEventListener('click', async () => { el('reference-cloud-grid').innerHTML = '<p>正在搜索…</p>'; try { renderCloudImages(await loadCloudImages(el('reference-cloud-query').value.trim())); } catch (error) { el('reference-cloud-grid').textContent = error.message; } }); el('reference-cloud-query').addEventListener('keydown', (event) => { if (event.key === 'Enter') { event.preventDefault(); el('reference-cloud-search').click(); } }); el('reference-cloud-dialog').addEventListener('click', (event) => { if (event.target === el('reference-cloud-dialog')) el('reference-cloud-dialog').close(); }); el('reference-cloud-dialog').querySelector('.reference-cloud-close').addEventListener('click', () => el('reference-cloud-dialog').close());
    el('reference-copy-version').addEventListener('click', duplicateVersion); el('reference-publish-version').addEventListener('click', () => simpleVersionAction('publish_version', '确认页面总览中的21张参考图，其区域、距离和左右方向均正确，并发布该版本？发布不等于启用。', '版本已发布，但尚未设为当前使用版本。')); el('reference-activate-version').addEventListener('click', () => simpleVersionAction('activate_version', '将该版本设为当前参考版本？原当前版本会停止使用但保持已发布。', '当前参考版本已切换。')); el('reference-deactivate-version').addEventListener('click', () => simpleVersionAction('deactivate_version', '停止使用当前参考版本？质量判断开关也会关闭。', '已停止使用参考版本并关闭质量判断。')); el('reference-archive-version').addEventListener('click', () => simpleVersionAction('archive_version', '归档后不能直接启用，确认归档？', '版本已归档。')); el('reference-restore-version').addEventListener('click', () => simpleVersionAction('restore_version', '', '版本已恢复为已发布状态。'));el('reference-delete-version').addEventListener('click',deleteVersion);
    el('reference-validation-toggle').addEventListener('change', async (event) => { const enabled = event.currentTarget.checked; event.currentTarget.disabled = true; try { await mutate('toggle_validation', { enabled }); await bootstrap(state.selectedId); await loadLogs(); message(enabled ? '拍摄质量模型判断已启用。' : '拍摄质量模型判断已关闭。', true); } catch (error) { event.currentTarget.checked = !enabled; message(error.message); } finally { event.currentTarget.disabled = false; } }); el('reference-refresh-logs').addEventListener('click', loadLogs);
    el('reference-editor-close').addEventListener('click', () => el('reference-editor-dialog').close()); el('reference-editor-dialog').addEventListener('click', (event) => { if (event.target === el('reference-editor-dialog')) el('reference-editor-dialog').close(); }); el('reference-rotation').addEventListener('input', (event) => updateRotation(event.currentTarget.value)); el('reference-rotate-left').addEventListener('click', () => updateRotation(state.rotation - 90)); el('reference-rotate-right').addEventListener('click', () => updateRotation(state.rotation + 90)); el('reference-auto-straighten').addEventListener('click', estimateStraighten); el('reference-flip-horizontal').addEventListener('click', toggleHorizontalMirror); el('reference-clear-crop').addEventListener('click', () => { if (state.crop) markEditorChanged(); state.crop = null; drawEditor(); }); el('reference-save-image').addEventListener('click', saveEditedImage); el('reference-save-metadata').addEventListener('click', saveMetadata); el('reference-reset-image').addEventListener('click', resetImage); window.addEventListener('resize', () => { if (el('reference-editor-dialog').open) drawEditor(); }); setupCropPointer();
    el('reference-test-version').addEventListener('click', openTest); document.querySelectorAll('.reference-test-close').forEach((button) => button.addEventListener('click', () => { clearTestCandidateObjectUrl(); el('reference-test-dialog').close(); })); el('reference-save-quality-settings').addEventListener('click', saveQualitySettings); el('reference-test-form').addEventListener('submit', runTest); el('reference-test-region').addEventListener('change', () => { renderTestReferences(); renderTestResult(); }); el('reference-test-file').addEventListener('change', () => { const file = el('reference-test-file').files?.[0]; if (!file) return; el('reference-test-cloud').value = ''; clearTestCandidateObjectUrl(); state.testCandidateObjectUrl = URL.createObjectURL(file); setTestCandidatePreview(state.testCandidateObjectUrl, file.name); renderTestResult(); el('reference-test-file').closest('label').querySelector('span').textContent = file.name; }); el('reference-test-cloud').addEventListener('change', () => { const id = el('reference-test-cloud').value; if (!id) return; el('reference-test-file').value = ''; el('reference-test-file').closest('label').querySelector('span').textContent = '选择一张照片'; clearTestCandidateObjectUrl(); const item = state.cloudImages.find((entry) => entry.public_id === id); setTestCandidatePreview(`api/image.php?id=${encodeURIComponent(id)}&t=${Date.now()}`, item ? `${item.member_name} · 云端影像` : '云端影像'); renderTestResult(); }); el('reference-refresh-tests').addEventListener('click', loadTestHistory);
  }
  window.setupReferenceLibrary = async (user) => { if (!user?.is_admin) { location.replace('dashboard.html'); return; } bindEvents(); try { await bootstrap(); await loadLogs(); } catch (error) { message(error.message); if (/权限/.test(error.message)) setTimeout(() => location.replace('dashboard.html'), 900); } };
})();
