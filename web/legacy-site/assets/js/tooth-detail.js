(() => {
  'use strict';

  const DEMO_DATASETS = new Set([
    'seven-view-numbered-wx-v1',
    'seven-view-numbered-v1',
  ]);
  const DEFAULT_DEMO_DATASET = 'seven-view-numbered-wx-v1';
  const ARCHES = {
    upper: [18,17,16,15,14,13,12,11,21,22,23,24,25,26,27,28],
    lower: [48,47,46,45,44,43,42,41,31,32,33,34,35,36,37,38],
  };
  const ALL_TEETH = [...ARCHES.upper, ...ARCHES.lower];
  const TOOTH_NAMES = { 1:'中切牙', 2:'侧切牙', 3:'尖牙', 4:'第一前磨牙', 5:'第二前磨牙', 6:'第一磨牙', 7:'第二磨牙', 8:'第三磨牙' };
  const STAGES = [
    ['original','原始单牙'], ['contour','轮廓定位'], ['normalized','亮度归一化'],
    ['heatmap','暗线响应热图'], ['candidate','结构候选'], ['skeleton','骨架与分叉'],
  ];
  const PARAMS = [
    ['edge_shrink_pct','边缘内缩','%',0,15,.5,6],
    ['darkness_threshold','暗线阈值','',.2,.8,.01,.602],
    ['black_level_pct','黑色水平','%',20,65,1,35],
    ['min_contrast_pct','最小局部反差','%',2,20,.5,5],
    ['min_width_px','最小线宽',' px',.5,6,.5,.5],
    ['min_length_pct','最小长度','%',2,25,1,18],
    ['smooth_px','平滑尺度',' px',1,9,2,5],
  ];

  const $ = (id) => document.getElementById(id);
  const params = new URLSearchParams(location.search);
  const tooth = Number(params.get('tooth'));
  const dataset = params.get('dataset') || '';
  const activeDemoDataset = DEMO_DATASETS.has(dataset) ? dataset : '';
  const isDemoDataset = activeDemoDataset !== '';
  const manifestUrl = activeDemoDataset ? `assets/demo/${encodeURIComponent(activeDemoDataset)}/manifest.json` : '';
  const version = params.get('version') || '';
  const capture = params.get('capture') || '';
  const member = params.get('member') || '';
  const state = {
    manifest:null, toothData:null, activeView:null, activeStage:'original', displayMode:'overlay',
    imageCache:new Map(), dynamicStages:null, parameters:{}, parametersDirty:false,
    remoteJob:null, remoteEvidence:null, darklinePollToken:0,
    zoom:1, panX:0, panY:0, pointer:null, compare:46, renderToken:0,
  };
  PARAMS.forEach(([key,,,,,,value]) => { state.parameters[key] = value; });
  state.parameters.exclude_highlights = true;

  function toothName(number) { return `${number} ${TOOTH_NAMES[number % 10] || '恒牙'}`; }
  function toothPosition(number) {
    const quadrant = Math.floor(number / 10);
    return `${quadrant <= 2 ? '上颌' : '下颌'} · ${quadrant === 1 || quadrant === 4 ? '右侧' : '左侧'}`;
  }
  function appendArchiveContext(query) {
    if (version) query.set('version', version);
    else query.set('dataset', activeDemoDataset || DEFAULT_DEMO_DATASET);
    if (capture) query.set('capture', capture);
    if (member) query.set('member', member);
    return query;
  }
  function withContext(number) { return `tooth-detail.html?${appendArchiveContext(new URLSearchParams({tooth:String(number)}))}`; }
  function loadImage(url) {
    if (!state.imageCache.has(url)) state.imageCache.set(url, new Promise((resolve,reject) => {
      const image = new Image(); image.decoding='async'; image.onload=()=>resolve(image); image.onerror=()=>reject(new Error(`图片读取失败：${url}`)); image.src=url;
    }));
    return state.imageCache.get(url);
  }

  function renderIdentity() {
    const index = ALL_TEETH.indexOf(tooth);
    const prev = ALL_TEETH[(index - 1 + ALL_TEETH.length) % ALL_TEETH.length];
    const next = ALL_TEETH[(index + 1) % ALL_TEETH.length];
    document.title = `${toothName(tooth)}单牙档案 · 齿镜`;
    $('tooth-code-mark').textContent = tooth;
    $('tooth-page-title').textContent = toothName(tooth);
    $('tooth-page-meta').textContent = `${toothPosition(tooth)} · FDI 恒牙编号 · 多视角数字影像档案`;
    $('tooth-prev-link').href=withContext(prev); $('tooth-prev-link').querySelector('strong').textContent=toothName(prev);
    $('tooth-next-link').href=withContext(next); $('tooth-next-link').querySelector('strong').textContent=toothName(next);
    const back=appendArchiveContext(new URLSearchParams({tooth:String(tooth)}));
    const backUrl=`dental-model-demo.html?${back}`;
    $('tooth-back-link').href=backUrl;
    const emptyBack=$('tooth-empty-back-link');if(emptyBack)emptyBack.href=backUrl;
  }

  function renderOdontogram() {
    const root=$('tooth-mini-odontogram'); root.replaceChildren();
    Object.values(ARCHES).forEach((arch) => {
      const row=document.createElement('div'); row.className='mini-arch';
      arch.forEach((number)=>{ const item=document.createElement('span'); item.className=`mini-tooth${number===tooth?' current':''}`; item.title=toothName(number); row.append(item); });
      root.append(row);
    });
  }

  function renderViewCards() {
    const root=$('tooth-view-list'); root.replaceChildren();
    state.toothData.views.forEach((view,index)=>{
      const button=document.createElement('button'); button.type='button'; button.className=`tooth-angle-card${index===0?' active':''}`;
      button.innerHTML=`<img src="${view.stages?.original||view.original_url}" alt="${view.view_label}"><span><strong>${view.view_label}</strong><small>${view.view_id.replaceAll('_',' ').toUpperCase()} · ${view.metrics.candidate_count} 处候选</small></span>`;
      button.addEventListener('click',()=>selectView(view,button)); root.append(button);
    });
  }

  function renderStageButtons() {
    const root=$('processing-stage-list'); root.replaceChildren();
    STAGES.forEach(([id,label],index)=>{
      const button=document.createElement('button'); button.type='button'; button.className=`stage-button${id===state.activeStage?' active':''}`; button.dataset.stage=id;
      button.innerHTML=`<span>0${index+1}</span><strong>${label}</strong>`;
      button.addEventListener('click',()=>{ state.activeStage=id; root.querySelectorAll('button').forEach((item)=>item.classList.toggle('active',item===button)); draw(); });
      root.append(button);
    });
  }

  function exactDarklineAvailable() {
    const realSource=Boolean(state.activeView?.outline_detection_id && Number(state.activeView?.tooth_navigation_id) > 0);
    const demoSource=isDemoDataset&&Boolean(state.activeView?.view_id&&state.activeView?.polygon_xy?.length>=3);
    return realSource||demoSource;
  }

  function setParameterInputsDisabled(disabled) {
    document.querySelectorAll('#parameter-controls input, #param-exclude-highlights, #reset-parameters').forEach((control)=>{ control.disabled=disabled; });
  }

  function setDarklineStatus(mode, message) {
    const indicator=$('darkline-run-indicator');
    indicator.className=`darkline-run-indicator${mode?` is-${mode}`:''}`;
    $('darkline-run-status').textContent=message;
    const button=$('run-shallow-caries');
    if(mode==='queued'||mode==='processing')button.textContent=mode==='queued'?'等待本地 RTX':'正在生成证据层';
    else if(mode==='completed')button.textContent='按当前参数重新检测';
    else button.textContent='运行浅龋检测';
  }

  function updateDarklineAvailability() {
    const button=$('run-shallow-caries');
    if(!button)return;
    const available=exactDarklineAvailable();
    button.disabled=!available;
    if(!available){
      setDarklineStatus('unavailable',version?'当前视角缺少原始轮廓实例映射，不能提交精确检测。':'当前测试牙齿视角缺少有效轮廓。');
      button.title='请选择一个包含有效牙齿轮廓的视角。';
    }else{
      button.title='按当前参数提交本地 RTX 计算';
      if(!state.remoteJob)setDarklineStatus('ready',isDemoDataset?'当前为内置测试档案，可按当前轮廓运行本地 RTX 浅龋检测。':'当前视角已关联牙齿轮廓实例，可以运行精确浅龋证据检测。');
    }
  }

  function resetRemoteEvidence() {
    state.darklinePollToken+=1;
    state.remoteJob=null;state.remoteEvidence=null;
    setParameterInputsDisabled(false);
    $('tooth-evidence-section').hidden=true;
    $('tooth-evidence-section').classList.remove('is-stale');
    $('processing-source-label').textContent='浏览器即时预览';
  }

  function markRemoteEvidenceStale() {
    if(!state.remoteEvidence)return;
    $('tooth-evidence-section').classList.add('is-stale');
    $('evidence-source-badge').textContent='参数已改变';
    setDarklineStatus('stale','参数已经调整。当前画布为浏览器预览，请重新运行以更新本地 RTX 证据。');
    $('processing-source-label').textContent='浏览器预览 · 待重新检测';
  }

  function renderEvidenceResult(result) {
    const section=$('tooth-evidence-section'),rows=$('tooth-evidence-rows'),empty=$('tooth-evidence-empty');
    section.hidden=false;section.classList.remove('is-stale');rows.replaceChildren();
    $('evidence-source-badge').textContent='LOCAL RTX · 精确证据';
    $('evidence-duration').textContent=Number.isFinite(Number(result.processing_ms))?`${Number(result.processing_ms).toFixed(0)} ms`:'已完成';
    const candidates=Array.isArray(result.candidates)?result.candidates:[];
    candidates.forEach((candidate,index)=>{
      const row=document.createElement('tr');
      const values=[
        candidate.candidate_id||`候选 ${index+1}`,
        Number(candidate.structure_score||0).toFixed(3),
        `${Number(candidate.skeleton_length_px||0).toFixed(0)} px`,
        `${Number(candidate.median_width_px||0).toFixed(2)} px`,
        `${Number(candidate.local_contrast_pct||0).toFixed(1)}%`,
        candidate.evidence_mode||candidate.kind||'结构暗线',
      ];
      values.forEach((value)=>{const cell=document.createElement('td');cell.textContent=String(value);row.append(cell);});
      rows.append(row);
    });
    empty.hidden=candidates.length>0;
    empty.textContent=candidates.length?'':'按当前参数未保留浅龋暗线研究候选；这不等同于排除龋病。';
    const totalWidth=candidates.reduce((sum,item)=>sum+Number(item.median_width_px||0),0);
    updateMetrics({
      candidate_count:Number(result.candidate_count||candidates.length),
      total_skeleton_length_px:Number(result.total_skeleton_length_px||0),
      mean_candidate_width_px:candidates.length?Math.round(totalWidth/candidates.length*100)/100:0,
    });
  }

  function renderParameterControls() {
    const root=$('parameter-controls'); root.replaceChildren();
    PARAMS.forEach(([key,label,suffix,min,max,step,value])=>{
      const wrap=document.createElement('div'); wrap.className='parameter-control';
      wrap.innerHTML=`<label for="param-${key}">${label}</label><output id="output-${key}">${value}${suffix}</output><input id="param-${key}" type="range" min="${min}" max="${max}" step="${step}" value="${value}">`;
      const input=wrap.querySelector('input'); const output=wrap.querySelector('output');
      input.addEventListener('input',()=>{ state.parameters[key]=Number(input.value); output.textContent=`${input.value}${suffix}`; scheduleDynamicProcessing(); });
      root.append(wrap);
    });
    $('param-exclude-highlights').addEventListener('change',(event)=>{ state.parameters.exclude_highlights=event.target.checked; scheduleDynamicProcessing(); });
    $('reset-parameters').addEventListener('click',()=>{
      PARAMS.forEach(([key,,, ,,,value])=>{ state.parameters[key]=value; $(`param-${key}`).value=value; $(`output-${key}`).textContent=`${value}${PARAMS.find((item)=>item[0]===key)[2]}`; });
      state.parameters.exclude_highlights=true; $('param-exclude-highlights').checked=true; state.dynamicStages=null; scheduleDynamicProcessing();
    });
  }

  function selectView(view,button) {
    resetRemoteEvidence();state.activeView=view; state.dynamicStages=null; state.parametersDirty=false; state.zoom=1; state.panX=0; state.panY=0;
    $('tooth-view-list').querySelectorAll('button').forEach((item)=>item.classList.toggle('active',item===button));
    $('lab-view-title').textContent=`${toothName(tooth)} · ${view.view_label}`; $('history-summary').textContent=`首次测试记录包含 ${state.toothData.views.length} 个真实来源视角。`;
    $('parameter-state').textContent='默认参数'; updateMetrics();updateDarklineAvailability(); draw();
  }

  function updateMetrics(metrics=null) {
    const view=state.activeView; if(!view) return; const source=metrics||view.metrics;
    $('metric-candidate-count').textContent=source.candidate_count||0;
    $('metric-skeleton-length').textContent=`${source.total_skeleton_length_px||0} px`;
    $('metric-line-width').textContent=`${source.mean_candidate_width_px||0} px`;
    const area=Number(view.pixel_area||Math.max(0,(view.bbox_xyxy?.[2]-view.bbox_xyxy?.[0])*(view.bbox_xyxy?.[3]-view.bbox_xyxy?.[1]))||0);$('metric-tooth-area').textContent=`${area.toLocaleString()} px²`;
  }

  function createCanvas(width,height) { const canvas=document.createElement('canvas'); canvas.width=width; canvas.height=height; return canvas; }
  function localPolygon(view) { const [x0,y0]=view.bbox_xyxy; return view.polygon_xy.map(([x,y])=>[x-x0,y-y0]); }
  function pathPolygon(ctx,points) { ctx.beginPath(); points.forEach(([x,y],i)=>i?ctx.lineTo(x,y):ctx.moveTo(x,y)); ctx.closePath(); }
  function maskCanvas(canvas,points) { const ctx=canvas.getContext('2d'); ctx.save(); ctx.globalCompositeOperation='destination-in'; ctx.fillStyle='#fff'; pathPolygon(ctx,points); ctx.fill(); ctx.restore(); }

  async function buildDynamicStages() {
    const view=state.activeView; const original=await loadImage(view.original_url); const [x0,y0,x1,y1]=view.bbox_xyxy; const width=x1-x0,height=y1-y0; const polygon=localPolygon(view);
    const source=createCanvas(width,height); const sctx=source.getContext('2d',{willReadFrequently:true}); sctx.drawImage(original,x0,y0,width,height,0,0,width,height); maskCanvas(source,polygon);
    const raw=sctx.getImageData(0,0,width,height); const normalized=createCanvas(width,height), heatmap=createCanvas(width,height), candidate=createCanvas(width,height), skeleton=createCanvas(width,height);
    const nctx=normalized.getContext('2d'), hctx=heatmap.getContext('2d'), cctx=candidate.getContext('2d'), skctx=skeleton.getContext('2d');
    const norm=nctx.createImageData(width,height); const heat=hctx.createImageData(width,height); const binary=new Uint8Array(width*height); const response=new Float32Array(width*height); const mask=raw.data;
    let mean=0,count=0; for(let i=0;i<width*height;i++){if(mask[i*4+3]){mean+=(mask[i*4]*.2126+mask[i*4+1]*.7152+mask[i*4+2]*.0722);count++;}} mean/=Math.max(count,1);
    const contrast=Math.max(1,state.parameters.min_contrast_pct/5); const threshold=state.parameters.darkness_threshold; const blackLimit=state.parameters.black_level_pct*2.55;
    for(let y=0;y<height;y++)for(let x=0;x<width;x++){const i=y*width+x,o=i*4,a=mask[o+3]; if(!a)continue; const l=mask[o]*.2126+mask[o+1]*.7152+mask[o+2]*.0722; const corrected=Math.max(0,Math.min(255,128+(l-mean)*contrast)); norm.data[o]=norm.data[o+1]=norm.data[o+2]=corrected;norm.data[o+3]=255; let local=0,n=0; for(let dy=-2;dy<=2;dy+=2)for(let dx=-2;dx<=2;dx+=2){const xx=Math.max(0,Math.min(width-1,x+dx)),yy=Math.max(0,Math.min(height-1,y+dy));local+=mask[(yy*width+xx)*4]*.2126+mask[(yy*width+xx)*4+1]*.7152+mask[(yy*width+xx)*4+2]*.0722;n++;} local/=n; const r=Math.max(0,(local-l)/Math.max(12,local))*1.6; response[i]=Math.min(1,r); const hue=Math.max(0,Math.min(1,r)); heat.data[o]=Math.round(255*Math.min(1,hue*1.8)); heat.data[o+1]=Math.round(255*Math.max(0,1-Math.abs(hue-.5)*2)); heat.data[o+2]=Math.round(255*(1-hue)); heat.data[o+3]=190; binary[i]=(r>=threshold&&(l<=blackLimit||local-l>=state.parameters.min_contrast_pct*2.55))?1:0; }
    nctx.putImageData(norm,0,0); maskCanvas(normalized,polygon); hctx.drawImage(source,0,0); hctx.globalAlpha=.56; hctx.putImageData(heat,0,0); hctx.globalAlpha=1; maskCanvas(heatmap,polygon);
    cctx.drawImage(source,0,0); const overlay=cctx.createImageData(width,height); let binaryCount=0; for(let i=0;i<binary.length;i++)if(binary[i]){binaryCount++;overlay.data[i*4]=45;overlay.data[i*4+1]=207;overlay.data[i*4+2]=235;overlay.data[i*4+3]=180;} cctx.putImageData(overlay,0,0); maskCanvas(candidate,polygon);
    skctx.drawImage(source,0,0); const sk=skctx.createImageData(width,height); let skeletonCount=0; for(let y=1;y<height-1;y++)for(let x=1;x<width-1;x++){const i=y*width+x;if(!binary[i])continue;const horizontal=binary[i-1]+binary[i+1],vertical=binary[i-width]+binary[i+width];if(horizontal<=1||vertical<=1){skeletonCount++;sk.data[i*4]=68;sk.data[i*4+1]=222;sk.data[i*4+2]=255;sk.data[i*4+3]=255;}} skctx.putImageData(sk,0,0); maskCanvas(skeleton,polygon);
    const contour=createCanvas(width,height); const tctx=contour.getContext('2d'); tctx.drawImage(source,0,0); tctx.strokeStyle='#55d7f2';tctx.lineWidth=2;pathPolygon(tctx,polygon);tctx.stroke();maskCanvas(contour,polygon);
    return { original:source, contour, normalized, heatmap, candidate, skeleton, metrics:{candidate_count:binaryCount?1:0,total_skeleton_length_px:skeletonCount,mean_candidate_width_px:skeletonCount?Math.round(binaryCount/skeletonCount*100)/100:0} };
  }

  let processTimer=0;
  function scheduleDynamicProcessing() { clearTimeout(processTimer); state.parametersDirty=true; state.dynamicStages=null;markRemoteEvidenceStale();$('parameter-state').textContent='正在重新计算…'; processTimer=setTimeout(async()=>{ try{state.dynamicStages=await buildDynamicStages();$('parameter-state').textContent='浏览器即时预览';updateMetrics(state.dynamicStages.metrics);draw();}catch(error){$('parameter-state').textContent='计算失败';console.error(error);}},160); }

  async function stageSource(stageId) {
    if(state.remoteEvidence&&!state.parametersDirty){
      const urls=state.remoteEvidence.evidence_urls||{};
      const layer=stageId==='original'||stageId==='contour'?'tooth':stageId;
      if(urls[layer])return loadImage(urls[layer]);
    }
    if(state.parametersDirty||!state.activeView.stages){ if(!state.dynamicStages) state.dynamicStages=await buildDynamicStages(); return state.dynamicStages[stageId]; }
    return loadImage(state.activeView.stages[stageId]);
  }

  async function buildFullCanvas(overlay=false) {
    const view=state.activeView; const image=await loadImage(view.original_url); const canvas=createCanvas(image.naturalWidth,image.naturalHeight); const ctx=canvas.getContext('2d');ctx.drawImage(image,0,0);
    if(overlay){
      const all=state.manifest.views.find((item)=>(item.id||item.view_id)===view.view_id)?.teeth||[];
      const current=all.find((item)=>Number(item.fdi||item.tooth_id)===tooth);
      ctx.fillStyle='rgba(5,12,15,.58)';ctx.fillRect(0,0,canvas.width,canvas.height);
      const polygon=(item)=>item.polygon_xy||item.contour||[];
      if(current){ctx.save();pathPolygon(ctx,polygon(current));ctx.clip();ctx.drawImage(image,0,0);ctx.restore();}
      all.forEach((item)=>{const selected=Number(item.fdi||item.tooth_id)===tooth;const points=polygon(item);if(points.length<3)return;ctx.strokeStyle=selected?'#55dcf5':'rgba(225,235,238,.32)';ctx.fillStyle=selected?'#55dcf5':'rgba(225,235,238,.58)';ctx.lineWidth=selected?5:2;pathPolygon(ctx,points);ctx.stroke();const [x,y]=points.reduce((best,p)=>p[1]<best[1]?p:best,points[0]);ctx.font=`600 ${selected?24:17}px system-ui`;ctx.fillText(String(item.fdi||item.tooth_id),x,y-7);});
    }
    return canvas;
  }

  async function draw() {
    if(!state.activeView)return; const token=++state.renderToken; const canvas=$('tooth-analysis-canvas'),stage=$('tooth-canvas-stage'); const rect=stage.getBoundingClientRect(); const dpr=Math.min(devicePixelRatio||1,2); canvas.width=Math.max(1,Math.round(rect.width*dpr));canvas.height=Math.max(1,Math.round(rect.height*dpr)); const ctx=canvas.getContext('2d');ctx.setTransform(dpr,0,0,dpr,0,0);ctx.clearRect(0,0,rect.width,rect.height);
    let source, original=null; if(state.displayMode==='tooth'){source=await stageSource(state.activeStage);original=await stageSource('original');}else source=await buildFullCanvas(state.displayMode==='overlay'); if(token!==state.renderToken)return;
    const scale=Math.min(rect.width/source.width,rect.height/source.height)*.88*state.zoom; const dw=source.width*scale,dh=source.height*scale,dx=(rect.width-dw)/2+state.panX,dy=(rect.height-dh)/2+state.panY;
    ctx.imageSmoothingEnabled=true;ctx.imageSmoothingQuality='high';ctx.drawImage(source,dx,dy,dw,dh);
    if(state.displayMode==='tooth'&&state.activeStage!=='original'&&original){ctx.save();ctx.beginPath();ctx.rect(dx,dy,dw*state.compare/100,dh);ctx.clip();ctx.clearRect(dx,dy,dw,dh);ctx.drawImage(original,dx,dy,dw,dh);ctx.restore();}
    $('canvas-zoom-value').textContent=`${Math.round(state.zoom*100)}%`; $('canvas-stage-label').textContent=state.displayMode==='tooth'?(STAGES.find((item)=>item[0]===state.activeStage)?.[1]||'单牙'):state.displayMode==='full'?'完整来源图片':'完整图片 + 当前牙轮廓'; $('compare-control').hidden=state.displayMode!=='tooth'||state.activeStage==='original';
  }

  function setupCanvasInteractions() {
    const stage=$('tooth-canvas-stage');
    stage.addEventListener('wheel',(event)=>{event.preventDefault();state.zoom=Math.max(.35,Math.min(8,state.zoom*Math.exp(-event.deltaY*.0012)));draw();},{passive:false});
    stage.addEventListener('pointerdown',(event)=>{state.pointer={x:event.clientX,y:event.clientY,panX:state.panX,panY:state.panY};stage.setPointerCapture(event.pointerId);stage.classList.add('is-dragging');});
    stage.addEventListener('pointermove',(event)=>{if(!state.pointer)return;state.panX=state.pointer.panX+event.clientX-state.pointer.x;state.panY=state.pointer.panY+event.clientY-state.pointer.y;draw();});
    const stop=()=>{state.pointer=null;stage.classList.remove('is-dragging');};stage.addEventListener('pointerup',stop);stage.addEventListener('pointercancel',stop);stage.addEventListener('dblclick',resetCanvas);
    document.querySelectorAll('[data-canvas-action]').forEach((button)=>button.addEventListener('click',()=>{if(button.dataset.canvasAction==='zoom-in')state.zoom=Math.min(8,state.zoom*1.2);else if(button.dataset.canvasAction==='zoom-out')state.zoom=Math.max(.35,state.zoom/1.2);else resetCanvas();draw();}));
    $('compare-range').addEventListener('input',(event)=>{state.compare=Number(event.target.value);draw();});
    document.querySelectorAll('[data-display-mode]').forEach((button)=>button.addEventListener('click',()=>{state.displayMode=button.dataset.displayMode;document.querySelectorAll('[data-display-mode]').forEach((item)=>item.classList.toggle('active',item===button));resetCanvas();draw();}));
    new ResizeObserver(()=>draw()).observe(stage);
  }
  function resetCanvas(){state.zoom=1;state.panX=0;state.panY=0;}

  async function canvasForDownload() { const source=state.displayMode==='tooth'?await stageSource(state.activeStage):await buildFullCanvas(state.displayMode==='overlay'); const canvas=createCanvas(source.width,source.height);canvas.getContext('2d').drawImage(source,0,0);return canvas; }
  function canvasBlob(canvas){return new Promise((resolve)=>canvas.toBlob(resolve,'image/png'));}
  async function setupDownloads() {
    $('download-current-image').addEventListener('click',async()=>{const canvas=await canvasForDownload();const url=URL.createObjectURL(await canvasBlob(canvas));const a=document.createElement('a');a.href=url;a.download=`FDI-${tooth}-${state.activeView.view_id}-${state.activeStage}.png`;a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);});
    $('download-coordinate-json').addEventListener('click',()=>{const payload={archive_id:state.manifest.archive_id,fdi:tooth,view_id:state.activeView.view_id,image_size:state.activeView.image_size,bbox_xyxy:state.activeView.bbox_xyxy,polygon_xy:state.activeView.polygon_xy,parameters:state.parameters,notice:state.manifest.notice};const blob=new Blob([JSON.stringify(payload,null,2)],{type:'application/json'});const url=URL.createObjectURL(blob);const a=document.createElement('a');a.href=url;a.download=`FDI-${tooth}-${state.activeView.view_id}-coordinates.json`;a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);});
    $('download-stage-zip').addEventListener('click',async()=>{const button=$('download-stage-zip');button.disabled=true;button.textContent='正在打包…';try{const zip=new JSZip();for(const [id] of STAGES){let blob;if(state.parametersDirty||!state.activeView.stages){const source=await stageSource(id);blob=await canvasBlob(source);}else blob=await (await fetch(state.activeView.stages[id])).blob();zip.file(`${STAGES.findIndex((item)=>item[0]===id)+1}-${id}.png`,blob);}zip.file('coordinates.json',JSON.stringify({fdi:tooth,view:state.activeView,parameters:state.parameters},null,2));const output=await zip.generateAsync({type:'blob'});const url=URL.createObjectURL(output);const a=document.createElement('a');a.href=url;a.download=`FDI-${tooth}-${state.activeView.view_id}-all-stages.zip`;a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);}finally{button.disabled=false;button.textContent='全部阶段 ZIP';}});
  }

  async function pollDarklineJob(jobId, token) {
    for(let attempt=0;attempt<600;attempt+=1){
      if(token!==state.darklinePollToken)return;
      await new Promise((resolve)=>setTimeout(resolve,attempt<3?900:1500));
      const response=await window.chijingApiRequest(`api/tooth_darkline_lab.php?action=status&job_id=${encodeURIComponent(jobId)}`,{cache:'no-store'});
      if(token!==state.darklinePollToken)return;
      const job=response.job||{};state.remoteJob=job;
      if(job.status==='queued'){
        setDarklineStatus('queued','任务已进入本地模型队列；请保持齿镜本地模型控制台在线。');
        continue;
      }
      if(job.status==='processing'){
        setDarklineStatus('processing','本地 RTX 正在按当前参数生成单牙证据层。');
        continue;
      }
      if(job.status==='failed')throw new Error(job.error_message||'本地浅龋证据检测失败。');
      if(job.status==='completed'){
        state.remoteEvidence=job.result||{};state.parametersDirty=false;state.dynamicStages=null;
        $('processing-source-label').textContent='本地 RTX 精确证据';
        $('parameter-state').textContent='当前参数已完成检测';
        setDarklineStatus('completed',`检测完成：保留 ${Number(state.remoteEvidence.candidate_count||0)} 处研究候选。`);
        renderEvidenceResult(state.remoteEvidence);
        state.displayMode='tooth';state.activeStage='candidate';resetCanvas();
        document.querySelectorAll('[data-display-mode]').forEach((item)=>item.classList.toggle('active',item.dataset.displayMode==='tooth'));
        renderStageButtons();await draw();
        setParameterInputsDisabled(false);$('run-shallow-caries').disabled=false;
        return;
      }
    }
    throw new Error('任务仍在队列中。请检查本地模型控制台，稍后可重新运行。');
  }

  function setupDarklineDetection() {
    const button=$('run-shallow-caries');
    button.addEventListener('click',async()=>{
      if(!exactDarklineAvailable())return updateDarklineAvailability();
      state.darklinePollToken+=1;const token=state.darklinePollToken;
      button.disabled=true;setParameterInputsDisabled(true);setDarklineStatus('queued','正在创建单牙浅龋证据任务…');
      try{
        const payload=isDemoDataset?{
          demo_dataset:activeDemoDataset,
          demo_view_id:state.activeView.view_id,
          demo_tooth_fdi:tooth,
          parameters:{...state.parameters},
        }:{
          detection_id:state.activeView.outline_detection_id,
          tooth_navigation_id:Number(state.activeView.tooth_navigation_id),
          parameters:{...state.parameters},
        };
        const response=await window.chijingApiRequest('api/tooth_darkline_lab.php?action=start',{
          method:'POST',headers:{'Content-Type':'application/json'},
          body:JSON.stringify(payload),
        });
        state.remoteJob=response.job||null;
        setDarklineStatus('queued','任务已进入本地模型队列；等待本地 RTX 接收。');
        await pollDarklineJob(String(response.job?.public_id||''),token);
      }catch(error){
        if(token!==state.darklinePollToken)return;
        setDarklineStatus('failed',error.message||'浅龋证据检测失败。');
        $('parameter-state').textContent='精确检测失败';
        setParameterInputsDisabled(false);button.disabled=false;
      }
    });
  }

  function setupCorrection(){const button=$('start-tooth-correction');if(!button||!version)return;button.hidden=false;button.addEventListener('click',async()=>{if(!confirm(`将基于当前版本创建一个新修订版本，并重点复核 FDI ${tooth}。原版本会保留，是否继续？`))return;button.disabled=true;try{const response=await window.chijingApiRequest('api/dental_arch_jobs.php?action=create_revision',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({version_id:version,tooth_id:String(tooth)})});location.assign(`dental-arch-review.html?version=${encodeURIComponent(response.version_id)}`);}catch(error){$('tooth-page-message').textContent=error.message;button.disabled=false;}});}

  function normalizeClinicalManifest(manifest){
    manifest.views=(manifest.views||[]).map((view)=>({...view,id:view.id||view.view_id,teeth:(view.teeth||[]).map((item)=>({...item,fdi:Number(item.fdi||item.tooth_id),polygon_xy:item.polygon_xy||item.contour||[]}))}));
    Object.values(manifest.teeth||{}).forEach((entry)=>{entry.views=(entry.views||[]).map((view)=>({...view,pixel_area:Number(view.pixel_area||Math.max(0,(view.bbox_xyxy?.[2]-view.bbox_xyxy?.[0])*(view.bbox_xyxy?.[3]-view.bbox_xyxy?.[1]))||0),metrics:view.metrics||{candidate_count:0,total_skeleton_length_px:0,mean_candidate_width_px:0}}));});
    return manifest;
  }

  async function init() {
    if(!ALL_TEETH.includes(tooth)){ $('tooth-page-message').textContent='牙位编号无效，请返回牙列重新选择。';return; }
    renderIdentity(); renderOdontogram(); renderStageButtons(); renderParameterControls(); setupCanvasInteractions(); setupDownloads(); setupDarklineDetection(); setupCorrection();
    if(!version&&!isDemoDataset){$('tooth-page-message').textContent='请先在牙列模型页载入编号测试数据或已确认的七视图牙列版本。';$('tooth-empty-state').hidden=false;$('tooth-empty-state').querySelector('strong').textContent='尚未选择七视图牙列档案';return;}
    try{
      const url=version?`api/dental_arch_jobs.php?action=manifest&version=${encodeURIComponent(version)}`:manifestUrl;
      const response=await fetch(url,{cache:'no-store',credentials:'same-origin'});
      if(!response.ok)throw new Error(`档案读取失败（HTTP ${response.status}）`);
      state.manifest=normalizeClinicalManifest(await response.json());
      const archiveTitle=state.manifest.title||state.manifest.archive_title||(isDemoDataset?'七视图编号测试档案':'七视图牙列档案');
      $('archive-badge').textContent=archiveTitle;
      $('history-title').textContent=archiveTitle;
      const createdAt=String(state.manifest.created_at||state.manifest.generated_at||'').slice(0,10);
      $('history-date').textContent=createdAt?createdAt.replaceAll('-','.'):'—';
      state.toothData=state.manifest.teeth[String(tooth)]||null;
      if(!state.toothData){$('tooth-page-message').textContent=`FDI ${tooth} 在本次七视图中未记录；这不表示缺牙。`;$('tooth-empty-state').hidden=false;$('tooth-empty-state').querySelector('strong').textContent='本次未记录该牙位';return;}
      state.activeView=state.toothData.views[0];renderViewCards();updateMetrics();updateDarklineAvailability();
      const viewSourceLabel=isDemoDataset?'标定来源视角':'真实来源视角';
      $('tooth-page-message').textContent=`已关联 ${state.toothData.views.length} 个${viewSourceLabel}。默认显示“完整图 + 轮廓”；数字图像处理仅针对当前单牙区域。`;
      $('tooth-workbench').hidden=false;$('tooth-history-section').hidden=false;
      $('history-summary').textContent=`当前牙列版本包含 ${state.toothData.views.length} 个该牙${viewSourceLabel}。`;draw();
    }catch(error){$('tooth-page-message').textContent=error.message;$('tooth-page-message').classList.add('is-error');}
  }
  document.addEventListener('DOMContentLoaded',init,{once:true});
})();
