(() => {
  let page = 1;
  let pages = 1;
  const el = (id) => document.getElementById(id);
  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);
  const operationLabel = (value) => ({ analysis: '影像分析', follow_up: '报告追问', admin_test: '连接测试', dental_arch_image: '牙列逐图复核', dental_arch_joint: '牙列七图统检' })[value] || value;
  function message(text, success = false) { el('dentist-admin-message').textContent = text || ''; el('dentist-admin-message').classList.toggle('is-success', success); }
  function filterQuery() {
    const params = new URLSearchParams({ page: String(page) });
    if (el('admin-filter-success').value) params.set('success', el('admin-filter-success').value);
    if (el('admin-filter-operation').value) params.set('operation', el('admin-filter-operation').value);
    if (el('admin-filter-model').value.trim()) params.set('model', el('admin-filter-model').value.trim());
    return params.toString();
  }
  function renderRuntime(runtime) {
    const values = [runtime.api_key, runtime.base_url, runtime.public_origin];
    el('dentist-admin-runtime').querySelectorAll('div strong').forEach((node, index) => { node.textContent = values[index] ? '已配置' : '未配置'; });
  }
  function renderStats(stats = {}) {
    const total = Number(stats.total_calls || 0);
    const success = Number(stats.successful_calls || 0);
    el('admin-total-calls').textContent = total.toLocaleString();
    el('admin-today-calls').textContent = Number(stats.today_calls || 0).toLocaleString();
    el('admin-success-rate').textContent = total ? `${Math.round(success / total * 100)}%` : '—';
    el('admin-average-latency').textContent = stats.avg_latency ? `${(Number(stats.avg_latency) / 1000).toFixed(1)}s` : '—';
    el('admin-total-tokens').textContent = Number(stats.total_tokens || 0).toLocaleString();
  }
  function renderSettings(settings) {
    el('admin-enabled').checked = Boolean(Number(settings.enabled));
    el('admin-primary-model').value = settings.primary_model || '';
    el('admin-fallback-model').value = settings.fallback_model || '';
    el('admin-dental-arch-model').value = settings.dental_arch_model || 'qwen3.7-plus';
    el('admin-dental-arch-fallback-model').value = settings.dental_arch_fallback_model || 'qwen3.6-plus';
    el('admin-timeout').value = settings.request_timeout_seconds || 90;
    el('admin-temperature').value = settings.temperature || 0.2;
    el('admin-max-images').value = settings.max_images || 6;
    el('admin-max-history').value = settings.max_history_items || 8;
    el('admin-daily-limit').value = settings.daily_user_limit ?? 20;
    el('admin-history-default').checked = Boolean(Number(settings.include_history_default));
    el('admin-local-results-default').checked = Boolean(Number(settings.include_local_results_default));
    el('admin-high-resolution').checked = Boolean(Number(settings.high_resolution_images));
    el('admin-system-prompt').value = settings.system_prompt || '';
    el('admin-prompt-count').textContent = `${el('admin-system-prompt').value.length} 字`;
  }
  function renderModels(models = []) {
    const root = el('dentist-admin-model-list');
    root.innerHTML = models.length ? models.map((model) => {
      const rate = Number(model.calls) ? Math.round(Number(model.successes) / Number(model.calls) * 100) : 0;
      return `<div class="dentist-admin-model-row"><strong>${escapeHtml(model.model_name)}</strong><span>${escapeHtml(model.calls)} 次</span><small>成功率 ${rate}% · ${Number(model.tokens || 0).toLocaleString()} Token</small></div>`;
    }).join('') : '<p>还没有模型调用记录。</p>';
  }
  function renderLogs(logs = [], pagination = {}) {
    const body = el('dentist-admin-log-body');
    body.innerHTML = logs.length ? logs.map((log) => `<tr><td>${escapeHtml(log.created_at)}</td><td>${escapeHtml(operationLabel(log.operation))}</td><td><code>${escapeHtml(log.model_name)}</code></td><td>${escapeHtml(log.email)}</td><td><span class="admin-log-status${Number(log.success) ? '' : ' is-failed'}">${Number(log.success) ? '成功' : `失败 ${escapeHtml(log.http_status || '')}`}</span></td><td>${(Number(log.input_tokens) + Number(log.output_tokens)).toLocaleString()}</td><td>${Number(log.latency_ms) ? `${(Number(log.latency_ms) / 1000).toFixed(1)}s` : '—'}</td><td>${escapeHtml(log.error_message || log.provider_request_id || '—')}</td></tr>`).join('') : '<tr><td colspan="8">没有符合条件的调用日志。</td></tr>';
    page = Number(pagination.page || 1);
    pages = Number(pagination.pages || 1);
    el('admin-page-label').textContent = `${page} / ${pages}`;
    el('admin-page-prev').disabled = page <= 1;
    el('admin-page-next').disabled = page >= pages;
  }
  async function bootstrap() {
    const data = await request(`api/ai_dentist_admin.php?action=bootstrap&${filterQuery()}`);
    renderRuntime(data.runtime || {});
    renderStats(data.stats || {});
    renderSettings(data.settings || {});
    renderModels(data.models || []);
    renderLogs(data.logs || [], data.pagination || {});
  }
  async function loadLogs() {
    const data = await request(`api/ai_dentist_admin.php?action=logs&${filterQuery()}`);
    renderLogs(data.logs || [], data.pagination || {});
  }
  async function save(event) {
    event.preventDefault();
    message('正在保存全部配置…');
    try {
      const data = await request('api/ai_dentist_admin.php?action=update', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          enabled: el('admin-enabled').checked,
          primary_model: el('admin-primary-model').value.trim(),
          fallback_model: el('admin-fallback-model').value.trim(),
          dental_arch_model: el('admin-dental-arch-model').value.trim(),
          dental_arch_fallback_model: el('admin-dental-arch-fallback-model').value.trim(),
          request_timeout_seconds: Number(el('admin-timeout').value),
          temperature: Number(el('admin-temperature').value),
          max_images: Number(el('admin-max-images').value),
          max_history_items: Number(el('admin-max-history').value),
          daily_user_limit: Number(el('admin-daily-limit').value),
          include_history_default: el('admin-history-default').checked,
          include_local_results_default: el('admin-local-results-default').checked,
          high_resolution_images: el('admin-high-resolution').checked,
          system_prompt: el('admin-system-prompt').value.trim(),
        }),
      });
      renderSettings(data.settings);
      message('AI牙医配置已保存。', true);
    } catch (error) { message(error.message); }
  }
  async function testConnection() {
    const button = el('dentist-admin-test');
    button.disabled = true;
    message('正在调用主模型检查连接，这次测试会产生少量 Token 用量…');
    try {
      const data = await request('api/ai_dentist_admin.php?action=test', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
      message(`${data.message} 模型：${data.model}，耗时 ${(Number(data.latency_ms) / 1000).toFixed(1)} 秒。`, true);
      await loadLogs();
    } catch (error) { message(error.message); }
    finally { button.disabled = false; }
  }
  window.setupAiDentistAdmin = async () => {
    el('dentist-admin-settings').addEventListener('submit', save);
    el('dentist-admin-test').addEventListener('click', testConnection);
    el('admin-system-prompt').addEventListener('input', () => { el('admin-prompt-count').textContent = `${el('admin-system-prompt').value.length} 字`; });
    el('dentist-admin-filter').addEventListener('submit', (event) => { event.preventDefault(); page = 1; loadLogs().catch((error) => message(error.message)); });
    el('admin-page-prev').addEventListener('click', () => { if (page > 1) { page -= 1; loadLogs(); } });
    el('admin-page-next').addEventListener('click', () => { if (page < pages) { page += 1; loadLogs(); } });
    try { await bootstrap(); } catch (error) { message(error.message); if (/权限/.test(error.message)) setTimeout(() => location.replace('ai-dentist.html'), 1200); }
  };
})();
