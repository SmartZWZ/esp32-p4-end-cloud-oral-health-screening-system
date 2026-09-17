/* global request */
(() => {
  const state = { deviceId:'', devices:[], conversations:[], selected:'', current:null };
  const byId=(id)=>document.getElementById(id);
  const element=(tag,className,text)=>{const item=document.createElement(tag);if(className)item.className=className;if(text!==undefined)item.textContent=text;return item;};
  const dateText=(value)=>{try{return new Intl.DateTimeFormat('zh-CN',{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}).format(new Date(value.replace(' ','T')));}catch(_){return value||'';}};
  const post=(action,payload)=>request(`api/device_assistant.php?action=${action}`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});

  function renderDevices(){
    const root=byId('device-assistant-devices');root.replaceChildren();
    const all=element('button',`device-assistant-device${state.deviceId===''?' active':''}`,'全部设备');all.type='button';
    all.addEventListener('click',()=>{state.deviceId='';state.selected='';state.current=null;loadList();});root.append(all);
    if(!state.devices.length){root.append(element('p','device-assistant-hint','暂无已绑定设备。'));return;}
    state.devices.forEach((device)=>{const button=element('button',`device-assistant-device${device.public_id===state.deviceId?' active':''}`);button.type='button';button.append(element('strong','',device.display_name),element('span','',device.device_uid));button.addEventListener('click',()=>{state.deviceId=device.public_id;state.selected='';state.current=null;loadList();});root.append(button);});
  }
  function renderConversations(){
    const root=byId('device-assistant-conversations');root.replaceChildren();byId('device-assistant-count').textContent=`${state.conversations.length} 条`;
    if(!state.conversations.length){root.append(element('p','device-assistant-hint','该设备还没有语音对话记录。'));return;}
    state.conversations.forEach((item)=>{const button=element('button',`device-assistant-conversation${item.public_id===state.selected?' active':''}`);button.type='button';button.append(element('span','device-assistant-conversation-device',`${item.device_name}${Number(item.is_active)?' · 当前':''}`),element('strong','',item.title),element('time','',dateText(item.updated_at)));button.addEventListener('click',()=>loadMessages(item.public_id));root.append(button);});
  }
  function renderMessages(messages){
    const root=byId('device-assistant-messages');root.replaceChildren();
    if(!messages.length){root.append(element('p','device-assistant-empty','这次设备会话尚未产生可保存的文字记录。'));return;}
    messages.forEach((message)=>{const item=element('article',`device-assistant-message ${message.role}`);item.append(element('span','device-assistant-message-meta',message.role==='user'?`设备听到 · ${dateText(message.created_at)}`:`齿镜助手 · ${dateText(message.created_at)}`),element('p','',message.content));root.append(item);});root.scrollTop=root.scrollHeight;
  }
  function renderConversationMeta(){
    const item=state.current;
    const buttons=['device-assistant-rename','device-assistant-toggle-memory','device-assistant-clear-summary','device-assistant-clear','device-assistant-delete'];
    buttons.forEach((id)=>{byId(id).disabled=!item;});
    if(!item){
      byId('device-assistant-title').textContent='选择一条设备对话';
      byId('device-assistant-meta').textContent='设备端语音对话会在这里按设备独立归档';
      byId('device-assistant-memory').textContent='记忆状态将在选择对话后显示';
      return;
    }
    byId('device-assistant-title').textContent=item.title;
    byId('device-assistant-meta').textContent=`${item.device_name} · ${item.device_uid} · ${dateText(item.created_at)}`;
    const enabled=Boolean(Number(item.memory_enabled));
    const turns=Math.ceil(Number(item.context_message_count||0)/2);
    byId('device-assistant-memory').textContent=enabled?`已参考最近 ${turns} 轮 · ${item.summary_updated_at?'摘要已生成':'摘要尚未生成'}`:'记忆已关闭';
    byId('device-assistant-memory').dataset.status=item.context_status||'ready';
    byId('device-assistant-toggle-memory').textContent=enabled?'关闭记忆':'开启记忆';
  }
  async function loadList(){
    byId('device-assistant-conversations').textContent='正在读取记录…';
    const url=`api/device_assistant.php?action=list${state.deviceId?`&device_id=${encodeURIComponent(state.deviceId)}`:''}`;
    try{
      const data=await request(url);state.devices=data.devices||[];state.conversations=data.conversations||[];
      renderDevices();renderConversations();renderConversationMeta();
      if(state.selected&&state.conversations.some((item)=>item.public_id===state.selected))await loadMessages(state.selected);
    }catch(error){byId('device-assistant-conversations').textContent=error.message;}
  }
  async function loadMessages(conversationId){
    state.selected=conversationId;renderConversations();byId('device-assistant-messages').textContent='正在读取对话…';
    try{const data=await request(`api/device_assistant.php?action=messages&conversation_id=${encodeURIComponent(conversationId)}`);state.current=data.conversation;renderConversationMeta();renderMessages(data.messages||[]);}catch(error){byId('device-assistant-messages').textContent=error.message;}
  }
  function selectedDeviceId(){
    if(state.deviceId)return state.deviceId;
    if(state.devices.length===1)return state.devices[0].public_id;
    return '';
  }
  function setupActions(){
    byId('device-assistant-new').addEventListener('click',async()=>{
      const deviceId=selectedDeviceId();
      if(!deviceId){alert('请先在左侧选择一台设备，再新建设备对话。');return;}
      try{const data=await post('create',{device_id:deviceId});state.selected=data.conversation.public_id;await loadList();await loadMessages(state.selected);}catch(error){alert(error.message);}
    });
    byId('device-assistant-rename').addEventListener('click',async()=>{
      const title=prompt('输入新的设备对话标题：',state.current?.title||'');if(title===null)return;
      try{await post('update',{conversation_id:state.selected,title,memory_enabled:Boolean(Number(state.current.memory_enabled))});await loadList();await loadMessages(state.selected);}catch(error){alert(error.message);}
    });
    byId('device-assistant-toggle-memory').addEventListener('click',async()=>{
      try{await post('update',{conversation_id:state.selected,title:state.current.title,memory_enabled:!Boolean(Number(state.current.memory_enabled))});await loadList();await loadMessages(state.selected);}catch(error){alert(error.message);}
    });
    byId('device-assistant-clear-summary').addEventListener('click',async()=>{
      if(!confirm('只清除该设备对话的自动摘要？消息仍会保留。'))return;
      try{await post('clear_summary',{conversation_id:state.selected});await loadMessages(state.selected);}catch(error){alert(error.message);}
    });
    byId('device-assistant-clear').addEventListener('click',async()=>{
      if(!confirm('确定清空该设备对话的全部文字消息和摘要吗？此操作不可撤销。'))return;
      try{await post('clear_messages',{conversation_id:state.selected});await loadList();await loadMessages(state.selected);}catch(error){alert(error.message);}
    });
    byId('device-assistant-delete').addEventListener('click',async()=>{
      if(!confirm('永久删除该设备对话及其消息？成员、照片和检测记录不会受到影响。'))return;
      try{await post('delete',{conversation_id:state.selected});state.selected='';state.current=null;renderConversationMeta();byId('device-assistant-messages').replaceChildren(element('p','device-assistant-empty','已删除设备对话。'));await loadList();}catch(error){alert(error.message);}
    });
  }
  window.setupDeviceAssistant=()=>{setupActions();loadList();};
})();
