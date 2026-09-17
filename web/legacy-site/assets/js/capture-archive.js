(() => {
  'use strict';
  const state = { data: null, pendingRegion: '', pendingMode: 'fill', busy: false };
  const el = (id) => document.getElementById(id);
  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[char]);
  const formatDate = (value) => value ? String(value).replace('T', ' ').slice(0, 16) : '—';
  const regionMeta = {
    front_bite:{index:1,code:'01',name:'正面咬合',prompt:'上下牙自然咬合，拍摄正面牙列'},
    left_bite:{index:2,code:'02',name:'左侧咬合',prompt:'上下牙咬合，完整呈现左侧后牙关系'},
    right_bite:{index:3,code:'03',name:'右侧咬合',prompt:'上下牙咬合，完整呈现右侧后牙关系'},
    upper_left_open:{index:4,code:'04',name:'左上牙列',prompt:'张口拍摄左上侧牙列及咬合面'},
    upper_right_open:{index:5,code:'05',name:'右上牙列',prompt:'张口拍摄右上侧牙列及咬合面'},
    lower_left_open:{index:6,code:'06',name:'左下牙列',prompt:'张口拍摄左下侧牙列及咬合面'},
    lower_right_open:{index:7,code:'07',name:'右下牙列',prompt:'张口拍摄右下侧牙列及咬合面'},
  };
  const archiveRows = [
    ['left_bite', 'front_bite', 'right_bite'],
    ['upper_left_open', 'upper_right_open'],
    ['lower_left_open', 'lower_right_open'],
  ];
  const riskText=(value)=>({low:'日常关注',medium:'建议复核',high:'建议及时就诊',unknown:'尚未分级'})[value]||'尚未分级';
  const typeText=(value)=>value==='family_report'?'口腔综合报告':'AI牙医报告';
  async function archiveRequest(id){if(typeof window.chijingApiRequest!=='function')throw new Error('页面认证接口尚未准备好，请刷新后重试。');return window.chijingApiRequest(`api/capture_archive.php?id=${encodeURIComponent(id)}`);}
  const imageUrl=(image)=>`api/image.php?id=${encodeURIComponent(image.public_id)}`;

  function renderDentalArchState(){
    const card=el('capture-model-state');if(!card)return;const model=state.data.dental_arch;
    if(!model){card.innerHTML='<span>牙列档案</span><strong>尚未生成</strong><p>完成七个标准视角后，可提取轮廓、复核 FDI 牙号并绑定到交互牙列。</p>';return;}
    const labels={waiting_outline:'本地轮廓处理中',vision_pending:'等待视觉复核',vision_processing:'视觉复核中',review_required:'等待人工确认',completed:'已绑定牙列',failed:'生成失败',stale:'来源已变化'};
    card.innerHTML=`<span>牙列档案</span><strong>${escapeHtml(labels[model.status]||model.status)}</strong><p>${escapeHtml(model.progress_label||'可以在牙列模型页面继续处理。')}</p>`;
    if(model.version_public_id){const link=document.createElement('a');link.className='text-button';link.href=model.status==='review_required'?`dental-arch-review.html?version=${encodeURIComponent(model.version_public_id)}`:`dental-model-demo.html?version=${encodeURIComponent(model.version_public_id)}&capture=${encodeURIComponent(state.data.archive.public_id)}`;link.textContent=model.status==='review_required'?'进入人工确认 →':'打开牙列档案 →';card.append(link);}
    const versions=state.data.dental_arch_versions||[];if(versions.length){const history=document.createElement('div');history.className='capture-model-versions';versions.forEach((item)=>{const link=document.createElement('a');link.href=item.status==='draft'?`dental-arch-review.html?version=${encodeURIComponent(item.public_id)}`:`dental-model-demo.html?version=${encodeURIComponent(item.public_id)}&capture=${encodeURIComponent(state.data.archive.public_id)}`;link.textContent=`V${item.version_number}${Number(item.is_current)?' · 当前':''}${item.status==='draft'?' · 待确认':''}${item.status==='stale'?' · 需更新':''}`;history.append(link);});card.append(history);}
  }

  function renderHeader(){
    const {archive,member}=state.data;const date=formatDate(archive.completed_at||archive.created_at);document.title=`${member.name} · 全口采集 ${date} · 齿镜`;
    el('capture-archive-title').textContent=`全口采集 · ${date}`;el('capture-archive-meta').textContent=`${member.name} · ${archive.device_name} · ${archive.reference_version?`参考版本 ${archive.reference_version}`:'未记录参考版本'}`;
    el('capture-archive-back').href=`member-profile.html?member=${encodeURIComponent(member.public_id)}#archives`;el('capture-archive-back').textContent=`← 返回${member.name}的采集档案`;
    const report=el('capture-archive-report');report.href=`family-reports.html?member=${encodeURIComponent(member.public_id)}&capture=${encodeURIComponent(archive.public_id)}`;report.classList.toggle('is-disabled',!archive.can_generate_report);report.setAttribute('aria-disabled',String(!archive.can_generate_report));
    const model=el('capture-archive-model');model.href=`dental-model-demo.html?capture=${encodeURIComponent(archive.public_id)}&member=${encodeURIComponent(member.public_id)}`;model.classList.toggle('is-disabled',!archive.is_complete);model.setAttribute('aria-disabled',String(!archive.is_complete));
    el('capture-archive-status').textContent=archive.is_complete?'完整采集':'资料不完整';el('capture-archive-status').dataset.status=archive.status;el('capture-archive-count').textContent=`${archive.completed_count}/7`;el('capture-archive-progress').style.width=`${Math.min(100,archive.completed_count/7*100)}%`;
    el('capture-archive-guidance').textContent=archive.is_complete?'七个标准拍摄位置均已归档，可生成口腔综合报告或牙列模型。':'点击下方缺失位置可从电脑或手机补图；补图会明确标记为“本地上传”。';renderDentalArchState();
  }
  function openImage(image){const back=`capture-archive.html?id=${encodeURIComponent(state.data.archive.public_id)}`;location.href=`image-editor.html?id=${encodeURIComponent(image.public_id)}&return=${encodeURIComponent(back)}`;}
  function requestImageFile(regionId,mode='fill'){
    if(state.busy)return;
    state.pendingRegion=regionId;state.pendingMode=mode;
    const input=el('capture-fill-file');input.value='';input.click();
  }
  async function refreshArchive(message=''){
    state.data=await archiveRequest(state.data.archive.public_id);renderHeader();renderImages();renderReports();
    if(message)el('capture-archive-message').textContent=message;
  }
  async function deleteView(regionId,image){
    if(state.busy)return;const meta=regionMeta[regionId];
    if(!window.confirm(`确定删除“${meta.name}”照片吗？\n\n档案会变为不完整；这张照片的模型分析会一并清理，已有文字报告仍会保留。`))return;
    state.busy=true;el('capture-archive-message').textContent=`正在删除${meta.name}…`;
    try{
      await window.chijingApiRequest(`api/capture_archive.php?action=delete_image&id=${encodeURIComponent(state.data.archive.public_id)}`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({region_id:regionId,public_id:image.public_id})});
      await refreshArchive(`${meta.name}已删除。该位置现在可以从电脑或手机补图。`);
    }catch(error){el('capture-archive-message').textContent=error.message;}finally{state.busy=false;}
  }
  async function deleteArchive(){
    if(state.busy||!state.data)return;
    const {archive,member}=state.data;
    if(!window.confirm(`确定永久删除 ${member.name} 的这份全口采集档案吗？\n\n将删除档案内全部照片、对应模型分析和牙列模型；已经生成的文字报告会保留。此操作无法撤销。`))return;
    state.busy=true;const button=el('capture-archive-delete');button.disabled=true;el('capture-archive-message').textContent='正在删除整份全口采集档案…';
    try{
      await window.chijingApiRequest(`api/capture_archive.php?action=delete_archive&id=${encodeURIComponent(archive.public_id)}`,{method:'POST',headers:{'Content-Type':'application/json'},body:'{}'});
      location.replace(`member-profile.html?member=${encodeURIComponent(member.public_id)}#archives`);
    }catch(error){el('capture-archive-message').textContent=error.message;button.disabled=false;state.busy=false;}
  }
  function renderImages(){
    const images=new Map((state.data.images||[]).map((image)=>[image.capture_region_id,image]));const root=el('capture-archive-grid');root.replaceChildren();
    archiveRows.forEach((regions)=>{const row=document.createElement('div');row.className=`capture-view-row capture-view-row-${regions.length}`;regions.forEach((regionId)=>{const meta=regionMeta[regionId];const image=images.get(regionId);if(!image){const missing=document.createElement('button');missing.type='button';missing.className=`capture-view-card view-${meta.index} is-missing`;missing.innerHTML=`<div class="capture-view-missing"><span>${meta.code}</span><strong>缺少照片</strong><i>从本地补图</i></div><div class="capture-view-copy"><span>${meta.code} / 07</span><h3>${escapeHtml(meta.name)}</h3><p>${escapeHtml(meta.prompt)}</p><small>点击选择电脑或手机中的照片</small></div>`;missing.addEventListener('click',()=>requestImageFile(regionId,'fill'));row.append(missing);return;}
        const card=document.createElement('article');card.className=`capture-view-card view-${meta.index}`;const source=image.source_type==='local_fill'||image.source_type==='web'?'<em>本地上传</em>':'<em>设备采集</em>';
        card.innerHTML=`<button class="capture-view-open" type="button" aria-label="查看与编辑${escapeHtml(meta.name)}"><div class="capture-view-image"><img loading="lazy" src="${imageUrl(image)}" alt="${escapeHtml(meta.name)}"><i>查看与编辑</i>${source}</div><div class="capture-view-copy"><span>${meta.code} / 07</span><h3>${escapeHtml(meta.name)}</h3><p>${escapeHtml(meta.prompt)}</p><small>${escapeHtml(formatDate(image.created_at))} · ${Number(image.analysis_count||0)} 次分析</small></div></button><footer class="capture-view-actions"><button type="button" data-action="replace">替换</button><button type="button" class="is-danger" data-action="delete">删除</button></footer>`;
        card.querySelector('.capture-view-open').addEventListener('click',()=>openImage(image));card.querySelector('[data-action="replace"]').addEventListener('click',()=>requestImageFile(regionId,'replace'));card.querySelector('[data-action="delete"]').addEventListener('click',()=>deleteView(regionId,image));row.append(card);});root.append(row);});
  }
  function renderReports(){const reports=state.data.reports||[];el('capture-report-total').textContent=`${reports.length} 份`;const root=el('capture-report-list');root.replaceChildren();if(!reports.length){root.innerHTML=`<div class="member-profile-empty member-empty-invitation"><strong>还没有使用这组照片生成报告</strong><p>${state.data.archive.is_complete?'可以从页面顶部开始生成一份口腔综合报告。':'档案不完整，补齐七张照片后才能生成完整报告。'}</p></div>`;return;}reports.forEach((report)=>{const link=document.createElement('a');link.className='capture-report-card';link.href=report.url;link.innerHTML=`<div><span>${escapeHtml(typeText(report.type))}</span><time>${escapeHtml(formatDate(report.date))}</time></div><h3>${escapeHtml(report.title)}</h3><p>${escapeHtml(report.summary||'打开报告查看完整内容。')}</p><footer><span>${escapeHtml(riskText(report.risk))}</span><i>查看报告 →</i></footer>`;root.append(link);});}
  async function uploadArchiveView(file){
    if(!file||!state.pendingRegion||state.busy)return;const region=state.pendingRegion;const mode=state.pendingMode;const meta=regionMeta[region];
    if(mode==='replace'&&!window.confirm(`用“${file.name}”替换${meta.name}吗？\n\n原图及其模型分析会被清理，已有文字报告仍会保留。`)){state.pendingRegion='';return;}
    state.busy=true;el('capture-archive-message').textContent=mode==='replace'?`正在替换${meta.name}…`:`正在把${meta.name}补入档案袋…`;
    const form=new FormData();form.append('file',file,file.name);form.append('capture_archive_id',state.data.archive.public_id);form.append('capture_region_id',region);
    try{
      await window.chijingApiRequest(`api/capture_archive.php?action=${mode}&id=${encodeURIComponent(state.data.archive.public_id)}`,{method:'POST',body:form});
      await refreshArchive(mode==='replace'?`${meta.name}已替换；相关牙列模型需要重新生成。`:`${meta.name}已补入，并标记为本地上传。`);
    }catch(error){el('capture-archive-message').textContent=error.message;}finally{state.pendingRegion='';state.pendingMode='fill';state.busy=false;}
  }
  async function setup(){const id=new URLSearchParams(location.search).get('id')||'';if(!id)throw new Error('没有指定要查看的全口采集档案。');state.data=await archiveRequest(id);renderHeader();renderImages();renderReports();}
  el('capture-archive-report').addEventListener('click',(event)=>{if(el('capture-archive-report').getAttribute('aria-disabled')==='true')event.preventDefault();});
  el('capture-archive-model').addEventListener('click',(event)=>{if(el('capture-archive-model').getAttribute('aria-disabled')==='true')event.preventDefault();});
  el('capture-archive-delete').addEventListener('click',deleteArchive);
  el('capture-fill-file').addEventListener('change',(event)=>uploadArchiveView(event.target.files?.[0]).catch((error)=>{el('capture-archive-message').textContent=error.message;state.busy=false;}));
  el('capture-preview-close').addEventListener('click',()=>el('capture-image-preview').close());el('capture-image-preview').addEventListener('click',(event)=>{if(event.target===el('capture-image-preview'))el('capture-image-preview').close();});
  window.setupCaptureArchive=()=>setup().catch((error)=>{el('capture-archive-message').textContent=error.message;el('capture-archive-title').textContent='全口采集档案无法读取';el('capture-archive-grid').innerHTML=`<p class="member-profile-empty">${escapeHtml(error.message)}</p>`;el('capture-report-list').innerHTML=`<p class="member-profile-empty">${escapeHtml(error.message)}</p>`;});
})();
