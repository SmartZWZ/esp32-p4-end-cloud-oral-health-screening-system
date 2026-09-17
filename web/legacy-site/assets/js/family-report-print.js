(() => {
  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  })[char]);
  const imageUrl = (id) => `api/image.php?id=${encodeURIComponent(id || '')}`;
  const riskText = (risk) => ({ low: '较低', medium: '需关注', high: '建议及时复核', unknown: '无法判断' })[risk] || '无法判断';
  const priorityText = (value) => ({ routine: '常规关注', soon: '尽快复核', urgent: '及时就诊' })[value] || '建议';
  const regionNames = {
    front_bite: '正面咬合', left_bite: '左侧咬合', right_bite: '右侧咬合',
    upper_left_open: '左上牙列', upper_right_open: '右上牙列',
    lower_left_open: '左下牙列', lower_right_open: '右下牙列',
  };
  const viewName = (image) => image?.capture_region_name || regionNames[image?.capture_region_id] || `照片 ${image?.image_index || ''}`;
  const sourceLabel = (mode) => mode === 'seven_view_archive' ? '七图全口档案' : '最近图片快速观察';

  function findingsOf(image) {
    const result = image.model_result || {};
    if (Array.isArray(result.findings)) return result.findings;
    return (result.pipeline_results || []).flatMap((stage) => stage.findings || []);
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
    const findings = findingsOf(image);
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

  function color(label) {
    const value = String(label || '').toLowerCase();
    if (value.includes('calculus')) return '#ff9d2e';
    if (value.includes('caries') || value.includes('cavity')) return '#ff4d57';
    if (value.includes('crack')) return '#388fd8';
    return '#9450c7';
  }

  function draw(img, canvas, findings) {
    if (!img.naturalWidth) return;
    canvas.width = img.naturalWidth;
    canvas.height = img.naturalHeight;
    const ctx = canvas.getContext('2d');
    findings.filter((item) => String(item.label || '').toLowerCase() !== 'tooth').forEach((item) => {
      const box = Array.isArray(item.bbox_xyxy) ? item.bbox_xyxy.map(Number) : [];
      const polygon = Array.isArray(item.polygon) ? item.polygon : [];
      const stroke = color(item.label);
      if (polygon.length > 2) {
        ctx.beginPath();
        polygon.forEach((point, i) => i ? ctx.lineTo(Number(point[0]), Number(point[1])) : ctx.moveTo(Number(point[0]), Number(point[1])));
        ctx.closePath(); ctx.globalAlpha = .25; ctx.fillStyle = stroke; ctx.fill(); ctx.globalAlpha = 1; ctx.strokeStyle = stroke; ctx.lineWidth = 3; ctx.stroke();
      }
      if (box.length === 4) {
        ctx.strokeStyle = stroke; ctx.lineWidth = 3;
        ctx.strokeRect(box[0], box[1], box[2] - box[0], box[3] - box[1]);
      }
    });
  }

  function singleReport(image) {
    const ai = image.ai_report;
    if (!ai) return `<section class="print-image-report"><img src="${imageUrl(image.source_public_id)}" alt=""><div class="print-error"><h3>${escapeHtml(viewName(image))} · 观察未完成</h3><p>${escapeHtml(image.ai_error || '没有可用报告。')}</p></div></section>`;
    const findings = Array.isArray(ai.visible_findings) ? ai.visible_findings : [];
    return `<section class="print-image-report"><img src="${imageUrl(image.source_public_id)}" alt="${escapeHtml(viewName(image))}"><div><h3>${escapeHtml(viewName(image))} · ${escapeHtml(riskText(ai.overall_risk))}</h3><p>${escapeHtml(ai.summary || '')}</p><div class="print-finding"><strong>影像质量 ${Number(ai.image_quality?.score || 0)}/100</strong><p>${escapeHtml((ai.image_quality?.problems || []).join('、') || '未记录明显成像问题。')}</p></div>${findings.map((item) => `<div class="print-finding"><strong>${escapeHtml(item.region || '可见区域')} · ${escapeHtml(item.finding || '')}</strong><p>${escapeHtml(item.evidence || '')}</p></div>`).join('')}</div></section>`;
  }

  function render(report) {
    const clinical = report.clinical_summary || {};
    const model = report.model_summary || {};
    const recommendations = clinical.recommendations || [];
    const limits = clinical.not_assessable || [];
    const modelReports = model.image_reports || [];
    const sevenView = report.source?.mode === 'seven_view_archive';
    const collection = clinical.collection_quality || {};
    const limitedViews = Array.isArray(collection.limited_views) ? collection.limited_views : [];
    const regionSummaries = Array.isArray(clinical.region_summaries) ? clinical.region_summaries : [];
    const changedNotice = report.source?.changed ? '<div class="print-source-warning"><strong>档案已发生变化：</strong>本 PDF 保留报告生成时的结论。若要依据替换后的七张图片重新判断，请重新生成报告。</div>' : '';
    const sevenViewSection = sevenView ? `<section class="print-section print-seven-view"><header><p class="print-kicker">SEVEN-VIEW CONSOLIDATION</p><h2>七视图联合复核</h2></header><p class="print-collection-quality">${escapeHtml(collection.summary || '已完成七个标准视角的联合复核。')} · 可用视角 ${Number(collection.usable_views ?? Math.max(0,7-limitedViews.length))}/7${limitedViews.length ? ` · 受限：${escapeHtml(limitedViews.join('、'))}` : ''}</p><div class="print-region-grid">${regionSummaries.map((item) => `<article><strong>${escapeHtml(item.region || '口腔区域')}</strong><p>${escapeHtml((item.observations || []).join('；') || '未记录明确可见表现。')}</p>${(item.limitations || []).length ? `<small>观察限制：${escapeHtml(item.limitations.join('；'))}</small>` : ''}</article>`).join('') || '<p>本次联合复核没有返回分区摘要，逐视角报告仍可正常查看。</p>'}</div></section>` : '';
    document.title = `${report.title} · 齿镜`;
    document.getElementById('print-back').href = `family-reports.html?id=${encodeURIComponent(report.public_id)}`;
    document.getElementById('print-report').innerHTML = `
      <header class="print-head">
        <div><p class="print-kicker">CHIJING · PRELIMINARY ORAL REPORT</p><h1>${escapeHtml(report.title)}</h1><p class="print-meta">${escapeHtml(report.member.name)} · ${escapeHtml(sourceLabel(report.source?.mode))} · ${report.images.length} 张照片 · ${escapeHtml(String(report.completed_at || report.created_at).slice(0,16))}</p></div>
        <div class="print-brand"><img src="cj.svg" alt="">齿镜</div>
      </header>
      ${changedNotice}
      <section class="print-summary"><div class="print-risk"><span>AI 独立观察风险</span><strong>${escapeHtml(riskText(clinical.overall_risk))}</strong></div><div><p class="print-kicker">INDEPENDENT AI SUMMARY</p><h2>成员级初步观察</h2><p>${escapeHtml(clinical.summary || '没有可显示的成员级汇总。')}</p></div></section>
      ${sevenViewSection}
      <section class="print-section"><header><p class="print-kicker">VIEW-BY-VIEW REVIEW</p><h2>逐视角独立报告</h2></header>${report.images.map(singleReport).join('')}</section>
      <section class="print-section print-two-column"><div><p class="print-kicker">NEXT STEPS</p><h3>下一步建议</h3>${recommendations.map((item) => `<div class="print-recommendation"><strong>${escapeHtml(priorityText(item.priority))} · ${escapeHtml(item.action || '')}</strong><p>${escapeHtml(item.reason || '')}</p></div>`).join('') || '<p>没有记录额外建议。</p>'}</div><div><p class="print-kicker">LIMITATIONS</p><h3>仅凭照片无法判断</h3><ul>${limits.map((item) => `<li>${escapeHtml(item)}</li>`).join('') || '<li>照片不能代替面诊、探诊和影像学检查。</li>'}</ul></div></section>
      <section class="print-section print-model-section"><header><p class="print-kicker">EXPERIMENTAL MODEL APPENDIX</p><h2>本地模型技术附录</h2></header><div class="print-model-warning"><strong>实验性输出：</strong>${escapeHtml(model.notice || '本节不参与 AI 牙医判断，不作为诊断依据。')}</div><p class="print-model-summary">${escapeHtml(sevenView ? '三个咬合视角仅展示牙磨损与牙结石；四个张口牙列视角仅展示牙磨损与微龋暗线。其他已运行模型不在本附录中显示。' : (model.summary || '没有可整理的模型输出。'))}</p><div class="print-model-grid">${report.images.map((image) => { const text=modelReports.find((item)=>Number(item.image_index)===Number(image.image_index)); return `<article class="print-model-card"><div class="print-model-view"><img src="${imageUrl(image.source_public_id)}" alt=""><canvas></canvas></div><div class="print-model-copy"><strong>${escapeHtml(viewName(image))}</strong><p>${escapeHtml(appendixNarrative(image,text?.narrative || ''))}</p></div></article>`; }).join('')}</div></section>
      <p class="print-disclaimer">${escapeHtml(clinical.disclaimer || '本报告仅用于口腔照片辅助筛查，不替代口腔医生面诊、探诊或影像学检查。')} 本地模型结果为实验性技术输出，不参与 AI 牙医独立判断。</p>`;
    document.querySelectorAll('.print-model-card').forEach((card, index) => {
      const img = card.querySelector('img');
      const canvas = card.querySelector('canvas');
      const findings = appendixFindings(report.images[index]);
      img.addEventListener('load', () => draw(img, canvas, findings), { once: true });
      if (img.complete) draw(img, canvas, findings);
    });
  }

  async function setupFamilyReportPrint() {
    document.getElementById('print-button').addEventListener('click', () => window.print());
    const params = new URLSearchParams(location.search);
    const id = params.get('id');
    if (!id) {
      document.getElementById('print-loading').textContent = '缺少报告编号。';
      return;
    }
    try {
      const data = await request(`api/family_reports.php?action=get&id=${encodeURIComponent(id)}`);
      render(data.report);
      if (params.get('print') === '1') {
        const images = [...document.images].filter((image) => !image.complete);
        await Promise.race([
          Promise.all(images.map((image) => new Promise((resolve) => {
            image.addEventListener('load', resolve, { once: true });
            image.addEventListener('error', resolve, { once: true });
          }))),
          new Promise((resolve) => setTimeout(resolve, 3500)),
        ]);
        setTimeout(() => window.print(), 250);
      }
    } catch (error) {
      document.getElementById('print-loading').textContent = error.message;
    }
  }

  window.setupFamilyReportPrint = setupFamilyReportPrint;
})();
