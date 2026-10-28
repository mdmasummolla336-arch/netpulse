'use strict';
// DudeWeb SPA
const SVGNS='http://www.w3.org/2000/svg';
const $=id=>document.getElementById(id);
const api={
  get:(a,p={})=>fetch('api.php?action='+a+'&'+new URLSearchParams(p)).then(r=>r.json()),
  post:(a,b)=>fetch('api.php?action='+a,{method:'POST',headers:{'Content-Type':'application/json','X-NetPulse':'1'},body:JSON.stringify(b)}).then(r=>r.json()),
};
const esc=s=>String(s==null?'':s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const statusSk=s=>TR(({up:'funkčné',pending:'nereaguje',down:'nefunkčné',unknown:'neznáme'}[s]||'neznáme'));
function fmtBps(v){return NetPulseGraph.formatRate(v);}
// farba linky podľa vyťaženia interface – plynulé spektrum (viditeľné aj pri nízkych %)
// 0%→modrá, ~15%→tyrkys, ~30%→zelená, ~50%→žltá, ~70%→oranžová, ~90%+→červená
function hsl2rgb(h,s,l){h=((h%360)+360)%360/360;const q=l<0.5?l*(1+s):l+s-l*s,p=2*l-q;
  const hk=t=>{t=(t+1)%1;if(t<1/6)return p+(q-p)*6*t;if(t<0.5)return q;if(t<2/3)return p+(q-p)*(2/3-t)*6;return p;};
  return `rgb(${Math.round(hk(h+1/3)*255)},${Math.round(hk(h)*255)},${Math.round(hk(h-1/3)*255)})`;}
function linkColor(util){
  if(util==null)return null;
  const u=Math.max(0,Math.min(1,util));
  const stops=[[0,210],[0.15,180],[0.3,140],[0.5,80],[0.7,40],[0.9,0]];
  let hue=stops[stops.length-1][1];
  for(let i=0;i<stops.length-1;i++){ if(u<=stops[i+1][0]){const a=stops[i],b=stops[i+1];const t=(u-a[0])/((b[0]-a[0])||1);hue=a[1]+(b[1]-a[1])*t;break;} }
  return hsl2rgb(hue,0.8,0.55);
}
const UI={"sun": "<svg class=\"ic\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.8\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><circle cx=\"12\" cy=\"12\" r=\"4\"/><path d=\"M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M6.3 17.7l-1.4 1.4M19.1 4.9l-1.4 1.4\"/></svg>", "moon": "<svg class=\"ic\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.8\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8z\"/></svg>", "mon": "<svg class=\"ic\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.8\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><rect x=\"2\" y=\"3\" width=\"20\" height=\"14\" rx=\"2\"/><path d=\"M8 21h8M12 17v4\"/></svg>", "palette": "<svg class=\"ic\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.8\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M12 2a10 10 0 1 0 0 20 1.5 1.5 0 0 0 1.06-2.56 1.5 1.5 0 0 1 1.06-2.56H16a5 5 0 0 0 5-5c0-5-4-9-9-9z\"/><circle cx=\"7.5\" cy=\"10.5\" r=\"1\"/><circle cx=\"12\" cy=\"7.5\" r=\"1\"/><circle cx=\"16.5\" cy=\"10.5\" r=\"1\"/></svg>", "key": "<svg class=\"ic\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.8\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><circle cx=\"7.5\" cy=\"15.5\" r=\"4\"/><path d=\"M10.5 12.5 20 3M17 6l2 2M13.5 9.5l2 2\"/></svg>", "users": "<svg class=\"ic\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.8\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2\"/><circle cx=\"9\" cy=\"7\" r=\"4\"/><path d=\"M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75\"/></svg>", "send": "<svg class=\"ic\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.8\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M22 2 11 13\"/><path d=\"M22 2 15 22l-4-9-9-4z\"/></svg>", "db": "<svg class=\"ic\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.8\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><ellipse cx=\"12\" cy=\"5\" rx=\"9\" ry=\"3\"/><path d=\"M3 5v14c0 1.7 4 3 9 3s9-1.3 9-3V5\"/><path d=\"M3 12c0 1.7 4 3 9 3s9-1.3 9-3\"/></svg>"};

function canEdit(){return S.role==='admin'||S.role==='administrator';}
let S={mapId:null,data:{nodes:[],links:[],map:null},view:{x:0,y:0,k:1},mode:null,linkFrom:null,sel:null,section:'maps'};
const sshState={devices:[],deviceId:null,socket:null,status:'disconnected'};
let sshFontSize=13;
let interfaceSoundEnabled=loadInterfaceSoundPreference();
let interfaceAudioContext=null;
let pendingInterfaceWhistle=false;
let interfaceStatusSnapshot=null;
let interfaceStatusCheckRunning=false;

function loadInterfaceSoundPreference(){
  try{return localStorage.getItem('np-port-down-sound')!=='off';}
  catch(error){console.warn('Could not read port-down sound preference:',error);return true;}
}

function saveInterfaceSoundPreference(enabled){
  try{localStorage.setItem('np-port-down-sound',enabled?'on':'off');}
  catch(error){console.warn('Could not save port-down sound preference:',error);}
}

function removeInterfaceSoundUnlockListeners(){
  document.removeEventListener('pointerdown',unlockInterfaceSound,true);
  document.removeEventListener('keydown',unlockInterfaceSound,true);
}

function unlockInterfaceSound(){
  if(!interfaceSoundEnabled||interfaceAudioContext?.state==='running')return;
  const AudioContextClass=window.AudioContext||window.webkitAudioContext;
  if(!AudioContextClass){
    console.error('This browser does not support interface alert sounds.');
    removeInterfaceSoundUnlockListeners();
    return;
  }
  try{
    interfaceAudioContext=interfaceAudioContext||new AudioContextClass();
    interfaceAudioContext.resume().then(()=>{
      if(interfaceAudioContext.state!=='running')return;
      removeInterfaceSoundUnlockListeners();
      if(pendingInterfaceWhistle)playInterfaceDownSound();
    }).catch(error=>console.error('Could not activate interface alert sound:',error));
  }catch(error){
    console.error('Could not activate interface alert sound:',error);
  }
}

document.addEventListener('pointerdown',unlockInterfaceSound,true);
document.addEventListener('keydown',unlockInterfaceSound,true);

// ---------- summary + alerts ----------
function paintSummary(s){
  $('summary').innerHTML=
    `<div class="chip"><span class="dot"></span><span class="clabel">${TR('Zariadenia')} </span><b>${s.total||0}</b></div>`+
    `<div class="chip up"><span class="dot up"></span><span class="clabel">${TR('funkčné')} </span><b>${s.up||0}</b></div>`+
    `<div class="chip pending"><span class="dot pending"></span><span class="clabel">${TR('nereaguje')} </span><b>${s.pending||0}</b></div>`+
    `<div class="chip down"><span class="dot down"></span><span class="clabel">${TR('nefunkčné')} </span><b>${s.down||0}</b></div>`+
    `<div class="chip unknown"><span class="dot unknown"></span><span class="clabel">${TR('neznáme')} </span><b>${s.unknown||0}</b></div>`;
}
async function loadSummary(){ paintSummary(await api.get('summary')); }
async function loadAlerts(){
  const ul=$('alert-list');
  if(!ul)return;
  const f=await api.get('faults');
  if(!f.length){ul.innerHTML='<li>'+TR('Žiadne aktívne poruchy')+' 🎉</li>';return;}
  ul.innerHTML=f.map(d=>`<li><b>${esc(d.name)}</b><br>${esc(d.ip||'')} · ${esc(fmtDur(d.duration))}</li>`).join('');
}
/** trvanie výpadku v sekundách -> "2 d 3 h", "1 h 20 min", "45 s" */
function fmtDur(s){
  if(s===null||s===undefined||s==='')return '—';
  s=Math.max(0,parseInt(s,10)||0);
  const d=Math.floor(s/86400),h=Math.floor(s%86400/3600),m=Math.floor(s%3600/60);
  if(d)return d+' d '+h+' h';
  if(h)return h+' h '+m+' min';
  if(m)return m+' min';
  return s+' s';
}

// ---------- sekcie ----------
document.querySelectorAll('#sections button').forEach(b=>b.onclick=()=>switchSection(b.dataset.sec));
function switchSection(sec){
  S.section=sec;
  document.querySelectorAll('#sections button').forEach(b=>b.classList.toggle('active',b.dataset.sec===sec));
  document.querySelectorAll('.view').forEach(v=>v.classList.remove('active'));
  $('view-'+sec).classList.add('active');
  const showMapSidebar=sec==='maps';
  $('sidebar').style.display=showMapSidebar?'flex':'none';
  if(sec==='devices')loadDevices();
  if(sec==='optical')loadOpticalDevices();
  if(sec==='services')loadServices();
  if(sec==='faults')loadFaults();
  if(sec==='events')loadEvents();
  if(sec==='ports')loadPorts();
  if(sec==='ssh')loadSshDevices();
  else if(sshState.socket)disconnectSsh();
  if(sec==='graphs')loadGraphs();
  if(sec==='mx80')loadMx80();
  if(sec==='settings')loadSettings();
}

// ---------- Dedicated Core Router MX80 traffic ----------
const mx80State={categories:[],categoryId:null,device:null,range:'hour',interfaces:[],users:[]};
function mx80Admin(){return S.role==='admin'||S.role==='administrator';}
function mx80SetMessage(message,isError=false){
  const element=$('mx80-message');
  if(element){element.textContent=message||'';element.classList.toggle('st-down',isError);}
}
async function loadMx80(){
  const admin=mx80Admin();
  document.querySelectorAll('.mx80-admin-only').forEach(element=>{
    element.style.display=admin?'':'none';
  });
  try{
    const data=await api.get('mx80_categories');
    mx80State.categories=(Array.isArray(data.categories)?data.categories:[]).map(category=>({
      ...category,id:Number(category.id),
      parent_id:category.parent_id===null?null:Number(category.parent_id)
    }));
    mx80State.device=data.device||null;
    $('mx80-device-label').textContent=mx80State.device
      ?`${mx80State.device.name} · ${mx80State.device.ip}`
      :'No core router has been configured.';
    renderMx80CategoryTree();
    if(mx80State.categoryId&&!mx80State.categories.some(category=>category.id===mx80State.categoryId)){
      mx80State.categoryId=null;
    }
    if(admin){
      await Promise.all([loadMx80DeviceOptions(),loadMx80Interfaces(),loadMx80Users()]);
    }
    if(mx80State.categoryId===null&&mx80State.categories.length){
      mx80State.categoryId=mx80State.categories[0].id;
      renderMx80CategoryTree();
    }
    await loadMx80Graphs();
  }catch(error){
    mx80SetMessage('Could not load MX80 module: '+error.message,true);
  }
}
async function loadMx80DeviceOptions(){
  const select=$('mx80-device-select');
  const devices=await api.get('mx80_devices');
  select.innerHTML='<option value="">Select the Juniper MX80</option>'+
    devices.map(device=>`<option value="${device.id}">${esc(device.name)} · ${esc(device.ip)} · SNMPv1</option>`).join('');
  select.value=mx80State.device?.id||'';
}
async function loadMx80Interfaces(){
  const list=$('mx80-interface-list');
  const interfaces=await api.get('mx80_interfaces');
  mx80State.interfaces=Array.isArray(interfaces)?interfaces:[];
  if(!mx80State.interfaces.length){
    list.innerHTML='<div class="mx80-category-empty">No described interfaces found. Discover interfaces from the configured router first.</div>';
    return;
  }
  const categories=mx80State.categories;
  list.innerHTML='<table class="mx80-interface-table"><thead><tr><th>Monitor</th><th>ifIndex</th><th>Description</th><th>ifName</th><th>Category</th><th>Latest RX</th><th>Latest TX</th></tr></thead><tbody>'+
    mx80State.interfaces.map(item=>`<tr data-ifindex="${item.ifindex}">
      <td><input type="checkbox" class="mx80-selected" ${Number(item.selected)?'checked':''} aria-label="Monitor interface ${item.ifindex}"></td>
      <td>${item.ifindex}</td><td>${esc(item.description)}</td><td>${esc(item.ifname||'—')}</td>
      <td><select class="mx80-interface-category"><option value="">Unassigned</option>${categories.map(category=>`<option value="${category.id}" ${String(item.category_id)===String(category.id)?'selected':''}>${esc(category.name)}</option>`).join('')}</select></td>
      <td>${item.rx_bps===null?'—':fmtBps(item.rx_bps)}</td><td>${item.tx_bps===null?'—':fmtBps(item.tx_bps)}</td>
    </tr>`).join('')+'</tbody></table>';
}
function renderMx80CategoryTree(){
  const container=$('mx80-category-tree');
  const children=new Map();
  mx80State.categories.forEach(category=>{
    const parent=category.parent_id===null?0:Number(category.parent_id);
    if(!children.has(parent))children.set(parent,[]);
    children.get(parent).push(category);
  });
  const build=(parentId,depth=0)=> (children.get(parentId)||[]).map(category=>{
    const id=Number(category.id),nested=children.get(id)||[],hasChildren=nested.length>0;
    const actionButtons=mx80Admin()
      ?`<span class="mx80-category-actions">
        <button type="button" data-mx80-child="${id}" title="Add child category">＋</button>
        <button type="button" data-mx80-rename="${id}" title="Rename category">✎</button>
        <button type="button" data-mx80-delete="${id}" title="Delete category">×</button>
      </span>`:'';
    const body=`<summary>
      <button class="mx80-tree-toggle" type="button" ${hasChildren?'':'disabled'} aria-label="${hasChildren?'Expand or collapse category':''}">${hasChildren?'▸':''}</button>
      <button class="mx80-tree-select ${mx80State.categoryId===id?'active':''}" type="button" data-mx80-category="${id}">${esc(category.name)}</button>${actionButtons}
    </summary>`+(hasChildren?`<div class="mx80-category-children">${build(id,depth+1)}</div>`:'');
    return hasChildren?`<details class="mx80-tree-branch" ${depth<1?'open':''}>${body}</details>`
      :`<div class="mx80-tree-branch">${body}</div>`;
  }).join('');
  container.innerHTML=build(0)||'<div class="mx80-category-empty">No categories are available to this user.</div>';
  container.querySelectorAll('[data-mx80-category]').forEach(button=>button.onclick=()=>{
    mx80State.categoryId=Number(button.dataset.mx80Category);
    renderMx80CategoryTree();
    loadMx80Graphs();
  });
  container.querySelectorAll('.mx80-tree-toggle').forEach(button=>button.onclick=event=>{
    event.preventDefault();
    event.stopPropagation();
    const details=button.closest('details');
    if(details){details.open=!details.open;button.textContent=details.open?'▾':'▸';}
  });
  container.querySelectorAll('[data-mx80-child]').forEach(button=>button.onclick=async()=>{
    const name=prompt('New child category name:');
    if(name===null)return;
    try{await mx80Post('mx80_category_create',{name,parent_id:Number(button.dataset.mx80Child)});await loadMx80();}
    catch(error){mx80SetMessage(error.message,true);}
  });
  container.querySelectorAll('[data-mx80-rename]').forEach(button=>button.onclick=async()=>{
    const category=mx80State.categories.find(item=>item.id===Number(button.dataset.mx80Rename));
    const name=prompt('Rename category:',category?.name||'');
    if(name===null)return;
    try{await mx80Post('mx80_category_rename',{category_id:Number(button.dataset.mx80Rename),name});await loadMx80();}
    catch(error){mx80SetMessage(error.message,true);}
  });
  container.querySelectorAll('[data-mx80-delete]').forEach(button=>button.onclick=async()=>{
    const category=mx80State.categories.find(item=>item.id===Number(button.dataset.mx80Delete));
    if(!confirm(`Delete "${category?.name||'category'}"? Child categories will be moved up and traffic history will be retained.`))return;
    try{await mx80Post('mx80_category_delete',{category_id:Number(button.dataset.mx80Delete)});await loadMx80();}
    catch(error){mx80SetMessage(error.message,true);}
  });
}
async function mx80Post(action,payload){
  const result=await api.post(action,payload);
  if(!result||!result.ok)throw new Error(result?.error||`${action} failed`);
  return result;
}
async function loadMx80Users(){
  if(!mx80Admin())return;
  mx80State.users=(await api.get('users_list')).filter(user=>!['admin','administrator'].includes(user.role));
  const select=$('mx80-permission-user');
  const current=select.value;
  select.innerHTML='<option value="">Choose a user</option>'+
    mx80State.users.map(user=>`<option value="${user.id}">${esc(user.username)} (${esc(user.role)})</option>`).join('');
  if(mx80State.users.some(user=>String(user.id)===current))select.value=current;
  select.onchange=loadMx80UserPermissions;
  await loadMx80UserPermissions();
}
async function loadMx80UserPermissions(){
  const userId=$('mx80-permission-user').value;
  const tree=$('mx80-permission-tree');
  if(!userId){tree.innerHTML='';return;}
  const saved=await api.get('mx80_permissions_get',{user_id:userId});
  const selected=new Set((saved.category_ids||[]).map(Number));
  const children=new Map();
  mx80State.categories.forEach(category=>{
    const parent=category.parent_id===null?0:Number(category.parent_id);
    if(!children.has(parent))children.set(parent,[]);
    children.get(parent).push(category);
  });
  const render=(parentId,depth=0)=>(children.get(parentId)||[]).map(category=>
    `<label class="mx80-permission-option" style="padding-left:${depth*16}px">
      <input type="checkbox" value="${category.id}" ${selected.has(Number(category.id))?'checked':''}>
      <span>${esc(category.name)}${children.has(Number(category.id))?' (grants this category and descendants)':''}</span>
    </label>`+render(Number(category.id),depth+1)
  ).join('');
  tree.innerHTML=render(0)||'<div class="mx80-category-empty">Create categories before assigning access.</div>';
}
async function loadMx80Graphs(){
  const grid=$('mx80-graph-grid'),empty=$('mx80-empty');
  grid.classList.add('mx80-category-graph');
  const category=mx80State.categories.find(item=>item.id===mx80State.categoryId);
  grid.innerHTML='';
  if(!category){
    $('mx80-selected-category').textContent='Select a category';
    empty.textContent=mx80State.categories.length?'Select a category to view assigned interface traffic.':'No categories are available to this user.';
    return;
  }
  $('mx80-selected-category').textContent=category.name;
  empty.textContent='Loading selected-interface traffic…';
  try{
    const graphs=await api.get('mx80_graphs',{category_id:category.id});
    if(!Array.isArray(graphs))throw new Error(graphs?.error||'Invalid MX80 graph response');
    if(!graphs.length){
      empty.textContent='No selected interfaces are assigned to this category.';
      return;
    }
    empty.textContent='';
    const cards=graphs.map(item=>{
      const card=document.createElement('article');
      card.className='graph-card mx80-category-graph';
      card.innerHTML=`<div class="graph-card-head"><div class="graph-card-title"><strong>${esc(item.ifname||item.description)}</strong><small>${esc(item.description)} · ifIndex ${item.ifindex}</small></div></div>
        <div class="graph-card-summary"></div><div class="graph-card-empty"></div>
        <div class="graph-chart-area"><canvas class="graph-card-canvas" aria-label="RX and TX traffic for ${esc(item.description)}"></canvas><div class="graph-chart-tooltip" role="status"></div></div>`;
      grid.appendChild(card);
      return {item,card};
    });
    await Promise.all(cards.map(async({item,card})=>{
      const canvas=card.querySelector('canvas'),message=card.querySelector('.graph-card-empty');
      try{
        const data=await api.get('mx80_graph_data',{
          category_id:category.id,ifindex:item.ifindex,range:mx80State.range
        });
        if(data.error)throw new Error(data.error);
        card.querySelector('.graph-card-summary').innerHTML=NetPulseGraph.summaryEntries(data.summary)
          .map(([label,value])=>`<div><span>${label}</span><strong>${fmtBps(value)}</strong></div>`).join('');
        if(!NetPulseGraph.hasHistory(data.points||[])){
          canvas.style.display='none';
          message.textContent='Not enough history yet; the graph will fill after consecutive selected-interface SNMP samples.';
        }else{
          message.textContent='';
          drawTrafficChart(canvas,data.points,mx80State.range);
        }
      }catch(error){
        canvas.style.display='none';
        message.textContent='Traffic graph load failed: '+error.message;
      }
    }));
  }catch(error){
    empty.textContent='Could not load this category: '+error.message;
  }
}
function initMx80Controls(){
  $('mx80-device-save').onclick=async()=>{
    try{
      await mx80Post('mx80_device_set',{device_id:Number($('mx80-device-select').value)});
      mx80SetMessage('Core router saved.');
      await loadMx80();
    }catch(error){mx80SetMessage(error.message,true);}
  };
  $('mx80-discover').onclick=async()=>{
    const button=$('mx80-discover');button.disabled=true;
    try{
      const result=await mx80Post('mx80_discover',{});
      mx80SetMessage(`Discovered ${result.interfaces} described interfaces; excluded ${result.without_description} without descriptions.`);
      await loadMx80Interfaces();
    }catch(error){mx80SetMessage(error.message,true);}
    finally{button.disabled=false;}
  };
  $('mx80-category-add').onclick=async()=>{
    const name=prompt('New category name:');
    if(name===null)return;
    try{await mx80Post('mx80_category_create',{name,parent_id:null});await loadMx80();}
    catch(error){mx80SetMessage(error.message,true);}
  };
  $('mx80-interfaces-save').onclick=async()=>{
    const interfaces=[...$('mx80-interface-list').querySelectorAll('tbody tr')].map(row=>({
      ifindex:Number(row.dataset.ifindex),
      selected:row.querySelector('.mx80-selected').checked,
      category_id:row.querySelector('.mx80-interface-category').value||null
    }));
    try{
      const result=await mx80Post('mx80_interfaces_save',{interfaces});
      mx80SetMessage(`Saved ${result.count} interface assignments. Only checked interfaces are polled.`);
      await loadMx80Graphs();
    }catch(error){mx80SetMessage(error.message,true);}
  };
  $('mx80-permission-save').onclick=async()=>{
    const userId=Number($('mx80-permission-user').value);
    if(!userId){mx80SetMessage('Choose a user before saving category access.',true);return;}
    const categoryIds=[...$('mx80-permission-tree').querySelectorAll('input:checked')].map(input=>Number(input.value));
    try{
      const result=await mx80Post('mx80_permissions_save',{user_id:userId,category_ids:categoryIds});
      mx80SetMessage(`Saved access to ${result.count} categories; parent grants include descendants.`);
    }catch(error){mx80SetMessage(error.message,true);}
  };
  $('mx80-refresh').onclick=()=>loadMx80Graphs();
  document.querySelectorAll('#mx80-ranges button').forEach(button=>button.onclick=()=>{
    if(!['hour','day','week'].includes(button.dataset.r))return;
    mx80State.range=button.dataset.r;
    document.querySelectorAll('#mx80-ranges button').forEach(item=>item.classList.toggle('active',item===button));
    loadMx80Graphs();
  });
}
initMx80Controls();

// ---------- Node category ----------
function promptNodeCategory(){
  const answer=prompt('Node category:\n1) Normal\n2) Reseller\n3) Bandwidth Reseller','1');
  if(answer===null)return null;
  return ({'1':'normal','2':'reseller','3':'bandwidth_reseller'})[answer.trim()]||null;
}

// ---------- MAPY ----------
async function loadMaps(){
  const maps=await api.get('maps');
  const ul=$('map-list');ul.innerHTML='';
  maps.forEach((m)=>{
    const li=document.createElement('li');li.dataset.id=m.id;
    li.innerHTML=`<span class="grip" title="${TR('Ťahaj pre presun')}">⠿</span>`+
      `<span class="mname">${esc(m.name||TR('(bez názvu)'))}</span>`+
      (m.down>0?`<span class="badge">${m.down}</span>`:`<span class="cnt">${m.nodes}</span>`);
    if(m.id===S.mapId)li.classList.add('active');
    installMapDrag(li,m.id);
    ul.appendChild(li);
  });
  if(S.mapId===null&&maps.length)selectMap(maps[0].id);
}

// ---- drag & drop radenie máp (chyť a potiahni) ----
function installMapDrag(li,id){
  let startY=0,dragging=false,moved=false;
  const ul=document.getElementById('map-list');
  const onDown=e=>{
    if(e.button!==undefined&&e.button!==0)return;
    startY=e.clientY;dragging=true;moved=false;
    document.addEventListener('mousemove',onMove);
    document.addEventListener('mouseup',onUp);
  };
  const onMove=e=>{
    if(!dragging)return;
    if(!canEdit())return;   // user nepresúva mapy
    if(!moved&&Math.abs(e.clientY-startY)<5)return;   // rozlíš klik od ťahu
    moved=true;li.classList.add('dragging');e.preventDefault();
    const items=[...ul.querySelectorAll('li:not(.dragging)')];
    const after=items.find(it=>{const r=it.getBoundingClientRect();return e.clientY<r.top+r.height/2;});
    if(after)ul.insertBefore(li,after);else ul.appendChild(li);
  };
  const onUp=async()=>{
    dragging=false;li.classList.remove('dragging');
    document.removeEventListener('mousemove',onMove);
    document.removeEventListener('mouseup',onUp);
    if(moved){
      const order=[...ul.querySelectorAll('li')].map(it=>+it.dataset.id);
      await api.post('reorder_maps',{order});
    }else{
      selectMap(id);   // bez ťahu = obyčajný klik = výber mapy
    }
  };
  li.addEventListener('mousedown',onDown);
}

async function selectMap(id){
  S.mapId=id;S.sel=null;S.linkFrom=null;
  document.getElementById('sidebar')&&document.getElementById('sidebar').classList.remove('open');
  document.querySelectorAll('#map-list li').forEach(li=>li.classList.toggle('active',+li.dataset.id===id));
  S.data=await api.get('map',{id});
  fitView();render();
}

// zalomí text na riadky s max počtom znakov (po slovách)
function wrapText(str,maxChars){
  const words=String(str||'').split(/\s+/).filter(Boolean);
  const lines=[];let cur='';
  words.forEach(w=>{
    const test=(cur?cur+' ':'')+w;
    if(test.length<=maxChars){cur=test;}
    else{ if(cur)lines.push(cur); cur=(w.length>maxChars)?w.slice(0,maxChars-1)+'…':w; }
  });
  if(cur)lines.push(cur);
  return lines.length?lines:[''];
}
function render(){
  const svg=$('canvas');svg.innerHTML='';
  // gradienty + defs
  const defs=document.createElementNS(SVGNS,'defs');
  defs.innerHTML=`
    <linearGradient id="gUp" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#4be07d"/><stop offset="1" stop-color="#1f9d4d"/></linearGradient>
    <linearGradient id="gDown" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ff6b84"/><stop offset="1" stop-color="#c62e4a"/></linearGradient>
    <linearGradient id="gPending" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ffdb7a"/><stop offset="1" stop-color="#d99a1e"/></linearGradient>
    <linearGradient id="gUnk" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#8a94aa"/><stop offset="1" stop-color="#565f75"/></linearGradient>`;
  svg.appendChild(defs);
  const g=document.createElementNS(SVGNS,'g');
  g.setAttribute('transform',`translate(${S.view.x},${S.view.y}) scale(${S.view.k})`);
  svg.appendChild(g);
  const byId={};S.data.nodes.forEach(n=>byId[n.id]=n);

  // linky
  S.data.links.forEach(l=>{
    const a=byId[l.from_node],b=byId[l.to_node];if(!a||!b)return;
    const line=document.createElementNS(SVGNS,'line');
    line.setAttribute('x1',a.x);line.setAttribute('y1',a.y);
    line.setAttribute('x2',b.x);line.setAttribute('y2',b.y);
    line.setAttribute('stroke-width',Math.max(1.5,(l.thickness||l.width||2)));
    // farba podľa vyťaženia interface (tok / rýchlosť portu)
    const _util=(S.role!=='employee'&&l.speed_bps>0&&(l.rx_bps!=null||l.tx_bps!=null))?Math.max(l.rx_bps||0,l.tx_bps||0)/l.speed_bps:null;
    const _col=linkColor(_util); if(_col){line.style.stroke=_col;line.style.strokeOpacity='0.95';}
    // štýl čiary podľa typu spoja (0 plná, 1 čiarkovaná, 2 bodkovaná, 3 čiara-bodka)
    const dash={1:'10 6',2:'1 7',3:'14 5 2 5'}[l.style];
    if(dash){line.setAttribute('stroke-dasharray',dash);line.setAttribute('stroke-linecap','round');}
    line.setAttribute('class','edge');line.dataset.link=l.id;
    line.onclick=()=>onLinkClick(l);
    if(l.ltype){const ti=document.createElementNS(SVGNS,'title');ti.textContent=l.ltype;line.appendChild(ti);}
    g.appendChild(line);
    // neviditeľná širšia čiara pre ľahšie kliknutie
    const hit=document.createElementNS(SVGNS,'line');
    hit.setAttribute('x1',a.x);hit.setAttribute('y1',a.y);hit.setAttribute('x2',b.x);hit.setAttribute('y2',b.y);
    hit.setAttribute('stroke','transparent');hit.setAttribute('stroke-width','16');
    hit.setAttribute('class','edge-hit');hit.dataset.link=l.id;
    hit.onclick=()=>onLinkClick(l);
    g.appendChild(hit);
    // reálny tok Rx/Tx (SNMP) – modrý štítok na strede čiary
    if(S.role!=='employee'&&(l.rx_bps!=null||l.tx_bps!=null)){
      const mx=(a.x+b.x)/2, my=(a.y+b.y)/2;
      const t1='Rx: '+fmtBps(l.rx_bps), t2='Tx: '+fmtBps(l.tx_bps)+((_util!=null)?' ('+Math.round(_util*100)+'%)':'');
      const w=Math.max(t1.length,t2.length)*5.6+12;
      const lg=document.createElementNS(SVGNS,'g');
      lg.setAttribute('transform',`translate(${mx},${my})`);
      const r=document.createElementNS(SVGNS,'rect');
      r.setAttribute('x',-w/2);r.setAttribute('y',-14);r.setAttribute('width',w);r.setAttribute('height',28);
      r.setAttribute('rx',5);r.setAttribute('class','traf-bg');
      lg.appendChild(r);
      lg.appendChild(txt(t1,0,-2,'traf-t',null,'middle'));
      lg.appendChild(txt(t2,0,10,'traf-t',null,'middle'));
      g.appendChild(lg);
    }
  });

  // uzly
  S.data.nodes.forEach(n=>{
    const grp=document.createElementNS(SVGNS,'g');
    const cat=['normal','reseller','bandwidth_reseller'].includes(n.category)?n.category:'normal';
    grp.setAttribute('class','node '+(n.status||'unknown')+' cat-'+cat+(S.sel===n.id?' sel':''));
    grp.setAttribute('transform',`translate(${n.x},${n.y})`);
    grp.dataset.node=n.id;
    if(n.kind==='submap'){
      // názov zalomíme na viac riadkov a koliečko zväčšíme, aby sa zmestil (ako Dude)
      const name=n.sub_name||n.label||'';
      const lines=wrapText(name,14);
      const countLine=`${n.sub_total||0} / 0 / ${n.sub_down||0}`;
      const all=lines.concat([countLine]);
      const lineH=13;
      const longest=Math.max.apply(null,all.map(l=>l.length));
      const r=Math.max(30, longest*3.4+12, all.length*lineH/2+10);
      const c=document.createElementNS(SVGNS,'circle');
      c.setAttribute('r',r);c.setAttribute('class','box');
      grp.appendChild(c);
      const y0=-(all.length-1)*lineH/2+4;
      all.forEach((ln,i)=>{
        const t=txt(ln,0,y0+i*lineH,'sub','#0b1220','middle');
        if(i===all.length-1)t.setAttribute('font-weight','700');
        grp.appendChild(t);
      });
    }else{
      // karta zariadenia s veľkou ikonou navrchu a názvom pod ňou (ako Dude, moderne)
      const name=n.dev_name||n.label||('#'+n.id);
      let lines=wrapText(name,22);
      if(lines.length>2) lines=[lines[0], lines[1].slice(0,20)+'…'];
      const lh=17, hasIcon=!!n.icon, iconSize=32;
      const longest=Math.max.apply(null,lines.map(l=>l.length));
      const typeName=String(n.type_name||n.device_type||n.dev_type||'').trim();
      const badgeH=typeName?19:0;
      const w=Math.max(136, longest*8+28, typeName.length*6.5+34);
      const h=(hasIcon?iconSize+12:8)+lines.length*lh+badgeH+13;
      const rect=document.createElementNS(SVGNS,'rect');
      rect.setAttribute('x',-w/2);rect.setAttribute('y',-h/2);
      rect.setAttribute('width',w);rect.setAttribute('height',h);
      rect.setAttribute('rx',10);rect.setAttribute('class','box');
      grp.appendChild(rect);
      if(hasIcon){
        const bs=iconSize+6;
        const bg=document.createElementNS(SVGNS,'rect');
        bg.setAttribute('x',-bs/2);bg.setAttribute('y',-h/2+5);
        bg.setAttribute('width',bs);bg.setAttribute('height',bs);bg.setAttribute('rx',8);
        bg.setAttribute('fill','#ffffff');bg.setAttribute('fill-opacity','0.85');
        grp.appendChild(bg);
        const img=document.createElementNS(SVGNS,'image');
        const iconPath='assets/icons/'+(String(n.icon).includes('.')?n.icon:n.icon+'.svg');
        img.setAttributeNS('http://www.w3.org/1999/xlink','href',iconPath);
        img.setAttribute('href',iconPath);
        img.setAttribute('x',-iconSize/2);img.setAttribute('y',-h/2+8);
        img.setAttribute('width',iconSize);img.setAttribute('height',iconSize);
        grp.appendChild(img);
      }
      let ly=-h/2+(hasIcon?iconSize+12:8)+14;
      lines.forEach(ln=>{
        const label=txt(ln,0,ly,'lbl','#07111f','middle');
        label.setAttribute('font-size','14');
        label.setAttribute('font-weight','800');
        label.setAttribute('paint-order','stroke');
        label.setAttribute('stroke','rgba(255,255,255,.20)');
        label.setAttribute('stroke-width','.35');
        grp.appendChild(label);
        ly+=lh;
      });
      if(typeName){
        const bw=Math.min(w-16,Math.max(58,typeName.length*6.5+20));
        const badge=document.createElementNS(SVGNS,'rect');
        badge.setAttribute('x',-bw/2);
        badge.setAttribute('y',h/2-23);
        badge.setAttribute('width',bw);
        badge.setAttribute('height',18);
        badge.setAttribute('rx',9);
        badge.setAttribute('fill','#101827');
        badge.setAttribute('stroke','rgba(255,255,255,.28)');
        badge.setAttribute('stroke-width','.7');
        grp.appendChild(badge);
        const badgeText=txt(typeName,0,h/2-10,'node-type-label','#ffffff','middle');
        badgeText.setAttribute('font-size','10');
        badgeText.setAttribute('font-weight','700');
        grp.appendChild(badgeText);
      }
    }
    grp.onmousedown=e=>onNodeDown(e,n);
    grp.onmouseenter=e=>showTip(e,n);
    grp.onmouseleave=()=>$('tooltip').style.display='none';
    grp.onclick=e=>onNodeClick(e,n);
    g.appendChild(grp);
  });
}
function txt(s,x,y,cls,fill,anchor){
  const t=document.createElementNS(SVGNS,'text');
  t.setAttribute('x',x);t.setAttribute('y',y);t.setAttribute('class',cls);
  if(fill)t.setAttribute('fill',fill);if(anchor)t.setAttribute('text-anchor',anchor);
  t.textContent=s;return t;
}
function showTip(e,n){
  const tip=$('tooltip');
  if(n.kind==='submap'){
    tip.innerHTML=`<b>${esc(n.sub_name||'')}</b><br>${TR('Mapa')} · ${n.sub_total||0} ${TR('zariadení')}`+
      (n.sub_down?` · <span class="st-down">${n.sub_down} ${TR('dole')}</span>`:'');
  }else{
    const r=n.rtt!=null?` · ${n.rtt} ms`:'';
    tip.innerHTML=`<b>${esc(n.dev_name||n.label||'')}</b><br>`+
      (n.ip?`IP: ${esc(n.ip)}<br>`:'')+
      `${TR('Stav')}: <span class="st-${n.status||'unknown'}">${statusSk(n.status)}${r}</span>`;
  }
  tip.style.display='block';tip.style.left=(e.offsetX+16)+'px';tip.style.top=(e.offsetY+16)+'px';
}

// ---------- ťahanie ----------
let drag=null;
function onNodeDown(e,n){
  if(S.mode)return;
  if(!canEdit())return;   // user = len čítanie (klik otvorí inšpektor)
  e.stopPropagation();
  drag={n,moved:false,sx:e.clientX,sy:e.clientY};
  document.addEventListener('mousemove',onDragMove);document.addEventListener('mouseup',onDragUp);
}
function onDragMove(e){
  if(!drag)return;drag.moved=true;
  drag.n.x+=(e.clientX-drag.sx)/S.view.k;drag.n.y+=(e.clientY-drag.sy)/S.view.k;
  drag.sx=e.clientX;drag.sy=e.clientY;render();
}
function onDragUp(){
  if(drag&&drag.moved)api.post('move',{node:drag.n.id,x:Math.round(drag.n.x),y:Math.round(drag.n.y)});
  drag=null;document.removeEventListener('mousemove',onDragMove);document.removeEventListener('mouseup',onDragUp);
}

// ---------- klik na uzol ----------
async function onNodeClick(e,n){
  e.stopPropagation();
  if(S.role==='employee'&&n.kind!=='submap')return;
  if(S.mode==='delete'){
    if(n.kind==='device'&&confirm(TR('Zmazať uzol')+' „'+(n.dev_name||n.label||n.id)+'"?\n\n'+TR('@del_node_hint'))){
      await api.post('del_node',{node:n.id});await selectMap(S.mapId);}
    return;
  }
  if(S.mode==='link'){
    if(n.kind!=='device')return;
    if(S.linkFrom===null){S.linkFrom=n.id;S.sel=n.id;render();$('mode').textContent=TR('Spoj: vyber druhý uzol');}
    else if(S.linkFrom!==n.id){await api.post('add_link',{map:S.mapId,from:S.linkFrom,to:n.id});
      S.linkFrom=null;S.sel=null;await selectMap(S.mapId);setMode('link');$('mode').textContent=TR('Spoj: vyber prvý uzol');}
    return;
  }
  if(n.kind==='submap'){selectMap(n.submap_id);switchSection('maps');
    document.querySelector('#sections button[data-sec=maps]').classList.add('active');return;}
  S.sel=n.id;render();openInspector(n.device_id);
}
async function onLinkClick(l){
  if(S.mode==='delete'){ if(confirm(TR('Zmazať spoj?'))){await api.post('del_link',{link:l.id});await selectMap(S.mapId);} return; }
  if(canEdit()) openLinkDialog(l);
}
let curLink=null;
const dashName=s=>TR(({0:'plná',1:'čiarkovaná',2:'bodkovaná',3:'čiara-bodka'}[s]||'plná'));
let linkDevs=null;
async function openLinkDialog(l){
  curLink=l;
  const types=await api.get('link_types');
  $('link-type').innerHTML=types.map(t=>`<option value="${t.id}" ${t.name===l.ltype?'selected':''}>${esc(t.name)} — ${dashName(t.style)}, ${TR('hr.')}${t.thickness}</option>`).join('')||'<option>'+TR('(žiadne typy)')+'</option>';
  if(!linkDevs) linkDevs=await api.get('devices',{q:''});
  $('link-dev').innerHTML='<option value="">'+TR('— žiadne —')+'</option>'+linkDevs.map(d=>`<option value="${d.id}" ${l.snmp_device==d.id?'selected':''}>${esc(d.name)}${d.ip?' ('+esc(d.ip)+')':''}</option>`).join('');
  $('link-if').innerHTML = l.snmp_ifindex ? `<option value="${l.snmp_ifindex}" selected>ifIndex ${l.snmp_ifindex} (${TR('aktuálne')})</option>` : '<option value="">—</option>';
  $('link-msg').textContent='';
  $('dlg-link').classList.remove('hidden');
}

// ---------- inspector ----------
async function openInspector(devId){
  if(!devId)return;
  const ins=$('inspector');ins.classList.remove('hidden');
  const d=await api.get('device',{id:devId});
  const dev=d.device||{};
  const mapNode=(S.data.nodes||[]).find(n =>
    String(n.id)===String(S.sel) && String(n.device_id)===String(devId)
  );
  const showNodeCategory=mapNode && String(dev.type_name||'').trim().toLowerCase()!=='switch';
  $('insp-title').textContent=dev.name||TR('Detail');
  const svc=(d.services||[]).map(s=>
    `<div class="svc"><span>${esc(s.name)}</span><span class="pill ${s.status||'unknown'}">${statusSk(s.status)}</span></div>`).join('')||'<div class="empty">'+TR('Žiadne služby')+'</div>';
  $('insp-body').innerHTML=
    (showNodeCategory ? `<div class="kv"><span>Node category</span><select id="insp-node-category">`+
      `<option value="normal" ${(!mapNode.category||mapNode.category==='normal')?'selected':''}>Normal</option>`+
      `<option value="reseller" ${mapNode.category==='reseller'?'selected':''}>Reseller</option>`+
      `<option value="bandwidth_reseller" ${mapNode.category==='bandwidth_reseller'?'selected':''}>Bandwidth Reseller</option>`+
      `</select></div>` : '')+
    `<div class="kv"><span>${TR('Stav')}</span><span class="pill ${dev.status||'unknown'}">${statusSk(dev.status)}</span></div>`+
    `<div class="kv"><span>IP</span><span>${esc(dev.ip||'–')}</span></div>`+
    `<div class="kv"><span>${TR('Typ')}</span><span>${esc(dev.type_name||'–')}</span></div>`+
    `<div class="kv"><span>DNS</span><span>${esc(dev.dns||'–')}</span></div>`+
    `<div class="kv"><span>${TR('Odozva')}</span><span>${dev.rtt!=null?dev.rtt+' ms':'–'}</span></div>`+
    `<div class="kv"><span>${TR('Posl. kontrola')}</span><span>${esc(dev.last_check||'–')}</span></div>`+
    `<h4 style="margin:14px 0 6px;color:var(--muted);font-size:12px">${TR('SLUŽBY')}</h4>${svc}`;

  const categorySelect=$('insp-node-category');
  if(categorySelect && mapNode){
    categorySelect.addEventListener('change',async()=>{
      const category=categorySelect.value;
      try{
        const result=await api.post('set_node_category',{node:mapNode.id,category});
        if(result && result.ok){
          mapNode.category=category;
          render();
        }else{
          alert('Could not save node category.');
          categorySelect.value=mapNode.category||'normal';
        }
      }catch(err){
        alert('Could not save node category. Check the connection.');
        categorySelect.value=mapNode.category||'normal';
      }
    });
  }

  $('insp-body').querySelectorAll('[data-device-access]').forEach(btn=>{
    btn.addEventListener('click',async()=>{
      const host=String(dev.ip||'').trim();
      const user=String(dev.username||'').trim();
      if(!host || !/^[a-zA-Z0-9.:-]+$/.test(host)){
        alert('Device IP/hostname যাচাই করুন।');return;
      }
      const action=btn.dataset.deviceAccess;
      if(action==='webfig'){
        window.open('http://'+host,'_blank','noopener,noreferrer');
      }else if(action==='web' || action==='https'){
        window.open((action==='https'?'https://':'http://')+host,'_blank','noopener,noreferrer');
      }else if(action==='ssh'){
        if(!user){
          alert('এই device-এর username সেট করা নেই। Device settings-এ username দিন।');return;
        }
        if(!/^[a-zA-Z0-9._-]+$/.test(user)){
          alert('Username-এ unsupported character আছে। Device settings যাচাই করুন।');return;
        }
        const port=Number(dev.ssh_port)||22;
        if(!Number.isInteger(port)||port<1||port>65535){alert('SSH port invalid।');return;}
        const command='ssh -p '+port+' '+user+'@'+host;
        try{
          await navigator.clipboard.writeText(command);
          alert('SSH command কপি হয়েছে। আপনার terminal-এ paste করুন: '+command);
        }catch(e){
          window.prompt('আপনার terminal-এ এই command paste করুন:',command);
        }
      }else if(action==='winbox'){
        if(!confirm('Winbox protocol handler configured থাকলে এটি খুলবে। '+host+'-এ Winbox চালাবেন?'))return;
        window.location.href='winbox://'+host;
      }
    });
  });
}
window.openDeviceDialog=openDeviceDialog;
$('insp-close').onclick=()=>$('inspector').classList.add('hidden');

// ---------- klik do plátna ----------
$('canvas').addEventListener('click',async e=>{
  if(!S.mapId)return;const p=toWorld(e);
  if(S.mode==='addnode'){
    const q=prompt(TR('Nájsť zariadenie (názov/IP):'),'');if(q===null)return;
    const list=await api.get('devices',{q});if(!list.length){alert(TR('Nič sa nenašlo.'));return;}
    const pick=prompt(TR('Vyber číslo:')+'\n'+list.slice(0,20).map((d,i)=>`${i+1}) ${d.name} ${d.ip?'('+d.ip+')':''}`).join('\n'),'1');
    const idx=parseInt(pick)-1;if(isNaN(idx)||!list[idx])return;
    const category=promptNodeCategory();if(!category)return;
    await api.post('add_node',{map:S.mapId,device_id:list[idx].id,x:Math.round(p.x),y:Math.round(p.y),category});await selectMap(S.mapId);
  }else if(S.mode==='newdevice'){
    const name=prompt(TR('Názov nového zariadenia:'),'');if(!name)return;
    const ip=prompt(TR('IP adresa (nepovinné):'),'')||'';
    const category=promptNodeCategory();if(!category)return;
    await api.post('new_device',{map:S.mapId,name,ip,x:Math.round(p.x),y:Math.round(p.y),category});await selectMap(S.mapId);
  }
});

// ---------- pan & zoom ----------
let pan=null;const svgEl=$('canvas');
svgEl.addEventListener('mousedown',e=>{
  if(e.target.tagName==='line')return;
  if(e.target===svgEl||e.target.tagName==='g'){pan={x:e.clientX,y:e.clientY,vx:S.view.x,vy:S.view.y};svgEl.style.cursor='grabbing';}
});
document.addEventListener('mousemove',e=>{if(!pan)return;S.view.x=pan.vx+(e.clientX-pan.x);S.view.y=pan.vy+(e.clientY-pan.y);render();});
document.addEventListener('mouseup',()=>{pan=null;svgEl.style.cursor='grab';});
svgEl.addEventListener('wheel',e=>{
  e.preventDefault();const s=e.deltaY<0?1.1:1/1.1;const p={x:e.offsetX,y:e.offsetY};
  S.view.x=p.x-(p.x-S.view.x)*s;S.view.y=p.y-(p.y-S.view.y)*s;S.view.k*=s;render();
},{passive:false});
function toWorld(e){return{x:(e.offsetX-S.view.x)/S.view.k,y:(e.offsetY-S.view.y)/S.view.k};}
function fitView(){
  const ns=S.data.nodes;if(!ns.length){S.view={x:60,y:60,k:1};return;}
  const xs=ns.map(n=>n.x),ys=ns.map(n=>n.y);
  const minx=Math.min(...xs),maxx=Math.max(...xs),miny=Math.min(...ys),maxy=Math.max(...ys);
  const w=svgEl.clientWidth||900,h=svgEl.clientHeight||600;
  let k=Math.min(w/(maxx-minx+260),h/(maxy-miny+200),1.3); if(w<600)k=Math.max(k,0.55);
  S.view={k,x:w/2-(minx+maxx)/2*k,y:h/2-(miny+maxy)/2*k};
}

// ---------- režimy ----------
function setMode(m){
  S.mode=(S.mode===m)?null:m;S.linkFrom=null;S.sel=null;
  ['btn-link','btn-delete','btn-add-node','btn-new-device'].forEach(id=>$(id).classList.remove('active'));
  const map={link:'btn-link',delete:'btn-delete',addnode:'btn-add-node',newdevice:'btn-new-device'};
  if(S.mode&&map[S.mode])$(map[S.mode]).classList.add('active');
  $('mode').textContent=TR({link:'Spoj: vyber prvý uzol',delete:'Mazací režim – klikni uzol/spoj',
    addnode:'Klikni do mapy pre umiestnenie',newdevice:'Klikni do mapy pre nové zariadenie'}[S.mode]||'');
  render();
}
$('btn-link').onclick=()=>setMode('link');
$('btn-delete').onclick=()=>setMode('delete');
$('btn-add-node').onclick=()=>setMode('addnode');
$('btn-new-device').onclick=()=>setMode('newdevice');
$('btn-refresh').onclick=()=>{selectMap(S.mapId);loadSummary();if(S.role!=='employee')loadAlerts();};
$('btn-add-map').onclick=async()=>{const name=prompt(TR('Názov novej mapy:'),'');if(!name)return;const r=await api.post('add_map',{name});await loadMaps();if(r.map)selectMap(r.map);};

// ---------- tabuľkové sekcie ----------
function pill(s){return `<span class="pill ${s||'unknown'}">${statusSk(s)}</span>`;}
// ---- generické triediteľné tabuľky (klik na hlavičku triedi) ----
const sortState={};
function ipNum(ip){if(!ip)return -1;const p=String(ip).split('.').map(n=>parseInt(n)||0);return p[0]*16777216+p[1]*65536+p[2]*256+p[3];}
function cellCmp(a,b,k,cols){
  const col=cols.find(c=>c.key===k), t=col&&col.type;
  if(t==='ip')return ipNum(a[k])-ipNum(b[k]);
  if(t==='status'){const o={up:0,pending:1,down:2,unknown:3};return (o[a[k]]??9)-(o[b[k]]??9);}
  const va=(a[k]==null?'':String(a[k])).toLowerCase(), vb=(b[k]==null?'':String(b[k])).toLowerCase();
  return va<vb?-1:va>vb?1:0;
}
function buildTable(tblId,rows,cols,emptyMsg,onRow){
  const st=sortState[tblId]||(sortState[tblId]={col:cols[0].key,dir:1});
  const sorted=[...rows].sort((a,b)=>cellCmp(a,b,st.col,cols)*st.dir);
  const head=cols.map(c=>`<th class="sortable" data-col="${c.key}">${c.label}${st.col===c.key?`<span class="arr">${st.dir>0?'▲':'▼'}</span>`:''}</th>`).join('');
  const body=sorted.length?sorted.map((r,i)=>'<tr'+(onRow?` data-ri="${i}" class="row-click"`:'')+'>'+cols.map(c=>`<td>${c.render?c.render(r):esc(r[c.key]??'')}</td>`).join('')+'</tr>').join('')
    :`<tr><td colspan="${cols.length}" class="empty">${emptyMsg||TR('Žiadne dáta')}</td></tr>`;
  const el=$(tblId); el.innerHTML=`<thead><tr>${head}</tr></thead><tbody>${body}</tbody>`;
  el.querySelectorAll('th.sortable').forEach(th=>th.onclick=()=>{const c=th.dataset.col;if(st.col===c)st.dir*=-1;else{st.col=c;st.dir=1;}buildTable(tblId,rows,cols,emptyMsg,onRow);});
  if(onRow) el.querySelectorAll('tr.row-click').forEach(tr=>tr.onclick=()=>onRow(sorted[+tr.dataset.ri]));
}
async function loadDevices(){
  const d=await api.get('devices',{q:''});
  buildTable('tbl-devices',d,[
    {key:'name',label:TR('Názov')},{key:'ip',label:'IP',type:'ip'},
    {key:'type_name',label:TR('Typ')},{key:'status',label:TR('Stav'),type:'status',render:x=>pill(x.status)}],
    undefined, r=>openDeviceDialog(r.id));
}
function sshStatusLabel(status){
  return ({up:'Device online',pending:'Pending',down:'Device offline',unknown:'Unknown'}[status]||'Unknown');
}
let sshTerminal = null;
let sshFitAddon = null;

function initSshTerminal(){
  if(sshTerminal)return;
  if(!window.Terminal || !window.FitAddon || !window.FitAddon.FitAddon){
    $('ssh-status').textContent='Terminal library unavailable';
    $('ssh-status').dataset.state='error';
    console.error('xterm.js or FitAddon failed to load.');
    return;
  }
  sshTerminal=new window.Terminal({
    cursorBlink:true, convertEol:true, scrollback:10000, fontSize:13,
    fontFamily:'Consolas, "Courier New", monospace',
    theme:{background:'#10151c',foreground:'#d7e3ef',cursor:'#d7e3ef',selectionBackground:'#334155'}
  });
  sshFitAddon=new window.FitAddon.FitAddon();
  sshTerminal.loadAddon(sshFitAddon);
  sshTerminal.open($('ssh-terminal'));
  sshTerminal.writeln('Select a device, then connect to open its shell.');
  sshTerminal.onData(data=>{
    const socket=sshState.socket;
    if(socket&&socket.readyState===WebSocket.OPEN&&sshState.status==='connected')
      socket.send(JSON.stringify({type:'input',data}));
  });
  sshTerminal.onResize(({cols,rows})=>{
    const socket=sshState.socket;
    if(socket&&socket.readyState===WebSocket.OPEN)
      socket.send(JSON.stringify({type:'resize',cols,rows}));
  });
  fitSshTerminal();
}
function fitSshTerminal(){
  if(!sshTerminal||!sshFitAddon)return;
  requestAnimationFrame(()=>{
    try{sshFitAddon.fit();}catch(e){}
  });
}
function setSshStatus(status,message){
  sshState.status=status;
  const el=$('ssh-status');
  el.textContent=message||({connecting:'Connecting…',connected:'Connected',disconnected:'Disconnected',error:'Connection error'}[status]||status);
  el.dataset.state=status;
  $('ssh-connect').disabled=!sshState.deviceId||status==='connecting'||status==='connected';
  $('ssh-disconnect').disabled=status!=='connecting'&&status!=='connected';
  $('ssh-reconnect').disabled=!sshState.deviceId;
}
function renderSshDevices(){
  const list=$('ssh-device-list');
  if(!sshState.devices.length){
    list.textContent='No SSH-enabled devices. Configure a device IP and username first.';
    return;
  }
  list.innerHTML=sshState.devices.map(d=>`
    <button type="button" class="ssh-device${Number(d.id)===sshState.deviceId?' selected':''}" data-ssh-device="${Number(d.id)}">
      <strong>${esc(d.name||'Unnamed device')}</strong>
      <span>${esc(d.ip)} · SSH ${Number(d.ssh_port)||22}</span>
      <span class="ssh-device-status ${esc(d.status||'unknown')}">${esc(sshStatusLabel(d.status))}</span>
    </button>`).join('');
  list.querySelectorAll('[data-ssh-device]').forEach(button=>button.onclick=()=>{
    sshState.deviceId=Number(button.dataset.sshDevice);
    const device=sshState.devices.find(d=>Number(d.id)===sshState.deviceId);
    $('ssh-target').textContent=`${device.name} · ${device.ip}:${Number(device.ssh_port)||22}`;
    renderSshDevices();
    connectSsh();
  });
}
async function loadSshDevices(){
  const list=$('ssh-device-list');
  list.textContent='Loading SSH devices…';
  try{
    const devices=await api.get('ssh_devices');
    if(!Array.isArray(devices))throw new Error(devices.error||'Could not load SSH devices');
    sshState.devices=devices;
    if(sshState.deviceId&&!devices.some(d=>Number(d.id)===sshState.deviceId)){
      disconnectSsh();
      sshState.deviceId=null;
      $('ssh-target').textContent='Select an SSH-enabled device';
    }
    renderSshDevices();
  }catch(e){
    list.textContent='SSH device list unavailable: '+e.message;
  }
}
function appendSshOutput(text){
  initSshTerminal();
  if(sshTerminal)sshTerminal.write(String(text));
}
function closeSshSocket(){
  const socket=sshState.socket;
  sshState.socket=null;
  if(socket&&socket.readyState<WebSocket.CLOSING)socket.close(1000,'Disconnected');
}
function disconnectSsh(){
  closeSshSocket();
  setSshStatus('disconnected');
}
function connectSsh(){
  const device=sshState.devices.find(d=>Number(d.id)===sshState.deviceId);
  if(!device)return;
  const deviceId=sshState.deviceId;
  closeSshSocket();
  initSshTerminal();
  if(sshTerminal){
    sshTerminal.reset();
    sshTerminal.writeln(`Connecting to ${device.name} (${device.ip}:${Number(device.ssh_port)||22})…`);
  }
  setSshStatus('connecting');
  const endpoint=new URL('ssh-ws/',window.location.href);
  endpoint.protocol=window.location.protocol==='https:'?'wss:':'ws:';
  const socket=new WebSocket(endpoint);
  sshState.socket=socket;
  socket.onopen=()=>{
    if(sshState.socket!==socket)return;
    socket.send(JSON.stringify({type:'connect',device_id:deviceId}));
    fitSshTerminal();
    const dims=sshFitAddon&&sshFitAddon.proposeDimensions();
    socket.send(JSON.stringify({type:'resize',cols:dims&&dims.cols||80,rows:dims&&dims.rows||24}));
  };
  socket.onmessage=event=>{
    if(sshState.socket!==socket)return;
    let message;
    try{message=JSON.parse(event.data);}catch(e){
      appendSshOutput('\r\nInvalid response from SSH bridge.\r\n');return;
    }
    if(message.type==='connected'){
      setSshStatus('connected');
      appendSshOutput(`\r\nConnected to ${message.device}.\r\n`);
      fitSshTerminal();
      if(sshTerminal)sshTerminal.focus();
    }else if(message.type==='output'){
      appendSshOutput(message.data||'');
    }else if(message.type==='error'){
      appendSshOutput(`\r\n${message.message||'SSH connection failed.'}\r\n`);
      setSshStatus('error');
    }
  };
  socket.onerror=()=>{
    if(sshState.socket!==socket)return;
    setSshStatus('error','WebSocket bridge unavailable');
    appendSshOutput('\r\nCould not reach the SSH WebSocket bridge. Check the bridge service and Nginx proxy.\r\n');
  };
  socket.onclose=()=>{
    if(sshState.socket===socket){
      sshState.socket=null;
      if(sshState.status!=='error'){
        setSshStatus('disconnected');
        appendSshOutput('\r\nDisconnected.\r\n');
      }
    }
  };
}
$('ssh-connect').onclick=connectSsh;
$('ssh-disconnect').onclick=disconnectSsh;
$('ssh-reconnect').onclick=connectSsh;
$('ssh-refresh').onclick=loadSshDevices;

window.addEventListener('resize',fitSshTerminal);

$('ssh-copy').onclick=async()=>{
  if(!sshTerminal)return;
  const selected=sshTerminal.getSelection();
  if(!selected){
    $('ssh-status').textContent='Select terminal text first';
    return;
  }
  try{
    await navigator.clipboard.writeText(selected);
    $('ssh-status').textContent='Copied';
    setTimeout(()=>{if(sshState.status==='connected')$('ssh-status').textContent='Connected';},1500);
  }catch(e){
    $('ssh-status').textContent='Copy blocked by browser';
  }
};

$('ssh-paste').onclick=async()=>{
  const socket=sshState.socket;
  if(!sshTerminal||!socket||socket.readyState!==WebSocket.OPEN||sshState.status!=='connected')return;
  try{
    const text=await navigator.clipboard.readText();
    if(text)sshTerminal.paste(text);
    sshTerminal.focus();
  }catch(e){
    $('ssh-status').textContent='Paste blocked by browser permission';
  }
};

$('ssh-font-down').onclick=()=>{
  if(!sshTerminal)return;
  sshFontSize=Math.max(8,sshFontSize-1);
  sshTerminal.options.fontSize=sshFontSize;
  fitSshTerminal();
};
$('ssh-font-up').onclick=()=>{
  if(!sshTerminal)return;
  sshFontSize=Math.min(28,sshFontSize+1);
  sshTerminal.options.fontSize=sshFontSize;
  fitSshTerminal();
};

$('ssh-fullscreen').onclick=async()=>{
  const panel=$('view-ssh');
  try{
    if(!document.fullscreenElement){
      await panel.requestFullscreen();
      $('ssh-fullscreen').textContent='Exit Fullscreen';
    }else{
      await document.exitFullscreen();
      $('ssh-fullscreen').textContent='Fullscreen';
    }
    fitSshTerminal();
  }catch(e){
    $('ssh-status').textContent='Fullscreen unavailable';
  }
};
document.addEventListener('fullscreenchange',()=>{
  $('ssh-fullscreen').textContent=document.fullscreenElement?'Exit Fullscreen':'Fullscreen';
  fitSshTerminal();
});

const sshView=$('view-ssh');
if(sshView)new MutationObserver(()=>{
  if(sshView.classList.contains('active'))fitSshTerminal();
}).observe(sshView,{attributes:true,attributeFilter:['class']});
initSshTerminal();

async function loadServices(){
  const d=await api.get('services');
  d.forEach(x=>x.check=String(x.ptype||'').toUpperCase()+(x.port?' '+x.port:''));
  buildTable('tbl-services',d,[
    {key:'name',label:TR('Služba')},{key:'check',label:TR('Kontroluje')},{key:'dev_name',label:TR('Zariadenie')},
    {key:'ip',label:'IP',type:'ip'},{key:'status',label:TR('Stav'),type:'status',render:x=>pill(x.status)}],
    undefined, r=>openDeviceDialog(r.device_id));
}
async function loadFaults(){
  const d=await api.get('fault_history');
  if(!Array.isArray(d)){
    console.error('Fault history API error:',d);
    buildTable('tbl-faults',[],[],TR('Nepodarilo načítať históriu porúch'));
    return;
  }
  buildTable('tbl-faults',d,[
    {key:'fault_status',label:TR('Stav'),render:x=>x.fault_status==='recovered'?'<span class="pill up">'+TR('obnovené')+'</span>':'<span class="pill down">'+TR('trvá')+'</span>'},
    {key:'name',label:TR('Zariadenie')},{key:'ip',label:'IP',type:'ip'},
    {key:'started',label:TR('Od')},
    {key:'ended',label:TR('Do'),render:x=>x.ended||'–'},
    {key:'duration',label:TR('Trvanie'),render:x=>x.duration!==null&&x.duration!==undefined?fmtDur(x.duration):'–'},
    {key:'service',label:TR('Služba')}],
    TR('কোনো fault history নেই'), r=>openDeviceDialog(r.device_id));
}

async function loadPorts(){
  const getPorts = async (action='list') => {
    const r=await fetch('ports_api.php?action='+encodeURIComponent(action));
    if(!r.ok) throw new Error('Port API error');
    return r.json();
  };
  const ports=await getPorts('list');
  buildTable('tbl-ports',ports,[
    {key:'device_name',label:'Device'},
    {key:'ip',label:'IP',type:'ip'},
    {key:'ifname',label:'Interface'},
    {key:'description',label:'Description',render:x=>x.description||'—'},
    {key:'alias_display',label:'Alias',render:x=>x.alias_display||'—'},
    {key:'oper_status',label:'Status',type:'status',render:x=>pill(x.oper_status)},
    {key:'ifindex',label:'ifIndex'},
    {key:'last_check',label:'Last check'}
  ],'No discovered interfaces');
  const btn=$('btn-port-discover');
  if(btn) btn.onclick=async()=>{
    btn.disabled=true;
    try{
      const r=await fetch('ports_api.php?action=discover',{
        method:'POST',headers:{'X-NetPulse':'1','Content-Type':'application/json'},body:'{}'
      });
      const result=await r.json();
      if(!r.ok||result.error) throw new Error(result.error||'Discovery failed');
      $('port-summary').textContent=
        `Discovered ${result.interfaces} interfaces; ${result.events} status changes`;
      await loadPorts();
    }catch(e){alert('Auto Discover failed: '+e.message);}
    finally{btn.disabled=false;}
  };
}

function updateInterfaceSoundButton(){
  const button=$('btn-interface-sound');
  if(!button)return;
  button.textContent=`${interfaceSoundEnabled?'🔊':'🔕'} Port-down whistle: ${interfaceSoundEnabled?'On':'Off'}`;
  button.setAttribute('aria-pressed',String(interfaceSoundEnabled));
  button.title='Play a whistle when a monitored port changes to down. Your choice is saved in this browser; after opening NetPulse, click or press a key once so the browser can allow audio.';
}
$('btn-interface-sound').onclick=async()=>{
  if(interfaceSoundEnabled){
    interfaceSoundEnabled=false;
    saveInterfaceSoundPreference(false);
    updateInterfaceSoundButton();
    return;
  }
  const AudioContextClass=window.AudioContext||window.webkitAudioContext;
  if(!AudioContextClass){
    alert('This browser does not support interface alert sounds.');
    return;
  }
  try{
    interfaceAudioContext=interfaceAudioContext||new AudioContextClass();
    await interfaceAudioContext.resume();
    interfaceSoundEnabled=true;
    saveInterfaceSoundPreference(true);
    updateInterfaceSoundButton();
    removeInterfaceSoundUnlockListeners();
    playInterfaceDownSound();
  }catch(error){
    console.error('Could not enable interface alert sound:',error);
    alert('Could not enable interface alert sound in this browser.');
  }
};
updateInterfaceSoundButton();

function playInterfaceDownSound(){
  if(!interfaceSoundEnabled)return;
  if(!interfaceAudioContext){
    pendingInterfaceWhistle=true;
    return;
  }
  const context=interfaceAudioContext;
  if(context.state!=='running'){
    pendingInterfaceWhistle=true;
    return;
  }
  pendingInterfaceWhistle=false;
  const start=context.currentTime;
  for(const [offset,from,to,duration] of [[0,1000,1700,0.38],[0.5,1500,850,0.42]]){
    const oscillator=context.createOscillator();
    const gain=context.createGain();
    oscillator.type='sine';
    oscillator.frequency.setValueAtTime(from,start+offset);
    oscillator.frequency.exponentialRampToValueAtTime(to,start+offset+duration);
    gain.gain.setValueAtTime(0.0001,start+offset);
    gain.gain.exponentialRampToValueAtTime(0.12,start+offset+0.025);
    gain.gain.setValueAtTime(0.12,start+offset+duration-0.04);
    gain.gain.exponentialRampToValueAtTime(0.0001,start+offset+duration);
    oscillator.connect(gain);
    gain.connect(context.destination);
    oscillator.start(start+offset);
    oscillator.stop(start+offset+duration);
  }
}

async function checkInterfaceStatus(){
  if(S.role==='user'||S.role==='employee'||interfaceStatusCheckRunning)return;
  interfaceStatusCheckRunning=true;
  try{
    const response=await fetch('ports_api.php?action=list');
    if(!response.ok)throw new Error(`Interface status request failed (${response.status})`);
    const ports=await response.json();
    if(!Array.isArray(ports))throw new Error(ports.error||'Invalid interface status response');
    const current=new Map(ports.map(port=>[
      `${port.device_id}:${port.ifindex}`,String(port.oper_status||'unknown').toLowerCase()
    ]));
    if(interfaceStatusSnapshot){
      const newlyDown=[...current].some(([key,status])=>
        status==='down'&&interfaceStatusSnapshot.get(key)!=='down'
      );
      if(newlyDown)playInterfaceDownSound();
    }
    interfaceStatusSnapshot=current;
  }catch(error){
    console.error('Could not check interface status for sound alerts:',error);
  }finally{
    interfaceStatusCheckRunning=false;
  }
}

async function loadEvents(){
  const d=await api.get('events');
  buildTable('tbl-events',d,[
    {key:'ts',label:TR('Čas')},
    {key:'device_name',label:TR('Zariadenie')},
    {key:'ip',label:'IP',type:'ip'},
    {key:'ifname',label:'Interface',render:x=>x.ifname||'—'},
    {key:'status',label:TR('Stav'),type:'status',render:x=>pill(x.status)},
    {key:'message',label:'Event',render:x=>x.message||'—'}
  ],TR('Zatiaľ žiadne udalosti (spustí sa monitoring)'));
}

// ---- GRAFY ----
let graphState={range:'hour',sources:[],selected:[],fullscreenCard:null};
function appGraphSourceSelectionId(source){
  return NetPulseGraph.sourceSelectionId(source);
}
function graphPickerOptions(query){
  return NetPulseGraph.pickerOptions(graphState.sources,query);
}
async function saveMx80GraphSelection(selected){
  const sourceIds=graphState.sources
    .filter(source=>source.mx80_interface&&selected.includes(appGraphSourceSelectionId(source)))
    .map(source=>appGraphSourceSelectionId(source));
  if(!graphState.sources.some(source=>source.mx80_interface))return;
  const result=await api.post('graph_interface_selection_set',{source_ids:sourceIds});
  if(!result||!result.ok)throw new Error(result?.error||'MX80 graph selection save failed');
}
async function changeGraphSelection(selected,persistMx80=false){
  const controls=document.querySelectorAll('#graph-source-list input[type="checkbox"],#graph-select-all,#graph-select-none');
  controls.forEach(control=>{control.disabled=true;});
  try{
    if(persistMx80)await saveMx80GraphSelection(selected);
    graphState.selected=selected;
    renderGraphSourcePicker();
    await drawGraphs();
  }catch(e){
    renderGraphSourcePicker();
    alert('Graph selection save failed: '+e.message);
  }finally{
    document.querySelectorAll('#graph-source-list input[type="checkbox"],#graph-select-all,#graph-select-none')
      .forEach(control=>{control.disabled=false;});
  }
}
function graphSourceMatches(source,query){
  return NetPulseGraph.sourceMatches(source,query);
}
function graphSourceMeta(source){
  return NetPulseGraph.sourceMeta(source);
}
function renderGraphSourcePicker(){
  const list=$('graph-source-list'),search=$('graph-search');
  const query=search.value||'';
  const shown=graphPickerOptions(query);
  list.innerHTML=shown.map(option=>{
    const source=option.labelSource;
    const name=source.mx80_interface
      ?`${source.device_name} / ${source.interface_name||'ifIndex '+source.mx80_selection_id.split(':').pop()}`
      :source.name;
    return `<label class="graph-source-option" title="${esc(graphSourceMeta(source)||source.name)}">
    <input type="checkbox" data-graph-id="${esc(option.key)}" ${graphState.selected.includes(option.key)?'checked':''}>
    <span class="graph-source-label"><strong>${esc(name)}</strong><small>${esc(graphSourceMeta(source)||source.sub||'')}</small></span>
  </label>`;
  }).join('');
  if(!shown.length)list.innerHTML='<span class="graph-source-empty">No matching graph sources.</span>';
  list.querySelectorAll('input[data-graph-id]').forEach(checkbox=>checkbox.onchange=()=>{
    const id=String(checkbox.dataset.graphId);
    const source=graphState.sources.find(item=>appGraphSourceSelectionId(item)===id);
    const selected=NetPulseGraph.toggleSelection(graphState.selected,id,checkbox.checked);
    changeGraphSelection(selected,!!source?.mx80_interface);
  });
  updateGraphCount();
}
function updateGraphCount(){
  $('graph-selection-count').textContent=`${graphState.selected.length} selected`;
}
async function loadGraphs(){
  const list=$('graph-source-list');
  const search=$('graph-search');
  try{
    graphState.sources=await api.get('graph_sources');
    if(!Array.isArray(graphState.sources))graphState.sources=[];
  }catch(e){
    list.textContent='Could not load graph sources: '+e.message;
    return;
  }
  const mx80Sources=graphState.sources.filter(source=>source.mx80_interface);
  const mx80Ids=new Set(mx80Sources.map(appGraphSourceSelectionId));
  const mx80Selected=[...new Set(mx80Sources
    .filter(source=>source.mx80_interface&&source.selected)
    .map(appGraphSourceSelectionId))];
  const localSelection=graphState.selected.filter(id=>
    !mx80Ids.has(id)
  );
  graphState.selected=[
    ...NetPulseGraph.selectionForSources(localSelection,graphState.sources.filter(source=>!source.mx80_interface)),
    ...mx80Selected
  ];
  search.oninput=renderGraphSourcePicker;
  $('graph-select-all').onclick=async()=>{
    const selected=graphPickerOptions(search.value||'').map(option=>option.key);
    await changeGraphSelection(selected,true);
  };
  $('graph-select-none').onclick=async()=>{
    await changeGraphSelection(NetPulseGraph.clearSelection(),true);
  };
  document.querySelectorAll('#graph-ranges button').forEach(b=>b.onclick=()=>{
    if(!NetPulseGraph.ranges.includes(b.dataset.r))return;
    graphState.range=b.dataset.r;
    document.querySelectorAll('#graph-ranges button').forEach(x=>x.classList.toggle('active',x.dataset.r===graphState.range));
    drawGraphs();
  });
  renderGraphSourcePicker();
  await drawGraphs();
}
async function drawGraphs(){
  const grid=$('graph-grid'), empty=$('graph-empty');
  const selected=new Set(graphState.selected);
  const chosenByMx80=new Map();
  const chosen=graphState.sources.filter(source=>{
    if(!source.mx80_interface)return selected.has(String(source.id));
    const key=appGraphSourceSelectionId(source);
    if(!selected.has(key))return false;
    const previous=chosenByMx80.get(key);
    if(!previous){
      chosenByMx80.set(key,source);
      return false;
    }
    if(String(previous.id).startsWith('p:')&&!String(source.id).startsWith('p:')){
      chosenByMx80.set(key,source);
    }
    return false;
  });
  chosen.push(...chosenByMx80.values());
  chosen.sort((a,b)=>
    Number(!!b.favorite)-Number(!!a.favorite)||
    String(a.name||'').localeCompare(String(b.name||''),undefined,{numeric:true,sensitivity:'base'})
  );
  grid.innerHTML='';
  if(!chosen.length){
    empty.textContent=graphState.sources.length?'Select one or more interfaces above to display graphs.':'No permitted graph sources found. Assign an interface and graph permission first.';
    return;
  }
  empty.textContent='';
  if(!document.documentElement.dataset.graphFullscreenBound){
    document.documentElement.dataset.graphFullscreenBound='1';
    document.addEventListener('fullscreenchange',()=>{
      document.querySelectorAll('.graph-fullscreen').forEach(button=>{
        const active=button.closest('.graph-card')===document.fullscreenElement;
        button.title=active?'Exit fullscreen':'Fullscreen';
        button.setAttribute('aria-label',active?'Exit fullscreen graph':'View graph fullscreen');
      });
      const activeCard=document.fullscreenElement||graphState.fullscreenCard;
      requestAnimationFrame(()=>requestAnimationFrame(()=>{
        const canvas=activeCard?.querySelector('.graph-card-canvas');
        if(canvas?.graphPoints)drawTrafficChart(canvas,canvas.graphPoints,canvas.graphRange);
        if(!document.fullscreenElement)graphState.fullscreenCard=null;
      }));
    });
  }
  const cards=chosen.map(src=>{
    const card=document.createElement('article');
    card.className='graph-card';
    card.innerHTML=`<div class="graph-card-head"><div class="graph-card-title"><strong>${esc(src.name)}</strong><small>${esc(graphSourceMeta(src)||src.sub||'')}</small></div><div class="graph-card-actions"><button type="button" class="graph-favorite" title="${src.favorite?'Remove favorite':'Add to favorites'}" aria-label="${src.favorite?'Remove favorite':'Add to favorites'}">${src.favorite?'★':'☆'}</button><button type="button" class="graph-fullscreen" title="Fullscreen" aria-label="View graph fullscreen">⛶</button><button type="button" class="graph-remove" title="Remove graph" aria-label="Remove graph">×</button></div></div>
      <div class="graph-card-summary"></div><div class="graph-card-empty"></div><div class="graph-chart-area"><canvas class="graph-card-canvas" aria-label="Rx and Tx traffic chart"></canvas><div class="graph-chart-tooltip" role="status"></div></div>`;
    card.querySelector('.graph-remove').onclick=()=>{
      const selectionId=appGraphSourceSelectionId(src);
      changeGraphSelection(graphState.selected.filter(id=>id!==selectionId),!!src.mx80_interface);
    };
    card.querySelector('.graph-fullscreen').onclick=async()=>{
      try{
        if(document.fullscreenElement===card)await document.exitFullscreen();
        else{
          graphState.fullscreenCard=card;
          await card.requestFullscreen();
        }
      }catch(e){
        graphState.fullscreenCard=null;
        alert('Fullscreen unavailable: '+e.message);
      }
    };
    const favButton=card.querySelector('.graph-favorite');
    favButton.hidden=!NetPulseGraph.canFavorite(S.role);
    favButton.classList.toggle('is-favorite',!!src.favorite);
    favButton.onclick=async()=>{
      favButton.disabled=true;
      try{
        const result=await api.post('graph_favorites_set',{
          source_id:String(src.id),favorite:!src.favorite
        });
        if(!result||!result.ok)throw new Error(result?.error||'Favorite save failed');
        src.favorite=!!result.favorite;
        favButton.textContent=src.favorite?'★':'☆';
        favButton.classList.toggle('is-favorite',src.favorite);
        favButton.title=src.favorite?'Remove favorite':'Add to favorites';
        favButton.setAttribute('aria-label',favButton.title);
        cards.sort((a,b)=>
          Number(!!b.src.favorite)-Number(!!a.src.favorite)||
          String(a.src.name||'').localeCompare(String(b.src.name||''),undefined,{numeric:true,sensitivity:'base'})
        ).forEach(({card:sortedCard})=>grid.appendChild(sortedCard));
      }catch(e){alert('Favorite save failed: '+e.message);}
      finally{favButton.disabled=false;}
    };
    grid.appendChild(card);
    return {src,card};
  });
  await Promise.all(cards.map(async ({src,card})=>{
    const cv=card.querySelector('canvas'), msg=card.querySelector('.graph-card-empty');
    try{
      const d=await api.get('graph_data',{id:String(src.id),range:graphState.range});
      if(d&&d.error)throw new Error(d.error);
      const pts=d.points||[],s=d.summary;
      card.querySelector('.graph-card-summary').innerHTML=NetPulseGraph.summaryEntries(s)
        .map(([label,value])=>{
          const displayLabel=label.replace(/^Avg\b/,'Average').replace(/^Max\b/,'Peak');
          return `<div><span>${displayLabel}</span><strong>${fmtBps(value)}</strong></div>`;
        }).join('');
      if(!NetPulseGraph.hasHistory(pts)){
        cv.style.display='none';
        msg.textContent='Not enough history yet; the graph will fill as polling continues.';
      }else{
        msg.textContent='';
        cv.style.display='block';
        drawTrafficChart(cv,pts,graphState.range);
      }
    }catch(e){
      cv.style.display='none';
      msg.textContent='Graph load failed: '+e.message;
    }
  }));
}
function drawTrafficChart(cv,pts,range){
  cv.graphPoints=pts;
  cv.graphRange=range;
  const rect=cv.getBoundingClientRect(); const dpr=window.devicePixelRatio||1;
  cv.width=rect.width*dpr; cv.height=rect.height*dpr;
  const ctx=cv.getContext('2d'); ctx.setTransform(dpr,0,0,dpr,0,0);
  const W=rect.width,H=rect.height,padL=66,padB=28,padT=22,padR=14;
  ctx.clearRect(0,0,W,H);
  const cs=getComputedStyle(document.documentElement);
  const cText=(cs.getPropertyValue('--muted')||'#889').trim(), cGrid=(cs.getPropertyValue('--border')||'#334').trim();
  const t0=pts[0].t, t1=pts[pts.length-1].t;
  let max=0; pts.forEach(p=>{max=Math.max(max,p.rx||0,p.tx||0);}); if(max<1000)max=1000;
  const X=t=>padL+(t-t0)/((t1-t0)||1)*(W-padL-padR);
  const Y=v=>H-padB-(v/max)*(H-padB-padT);
  ctx.strokeStyle=cGrid;ctx.fillStyle=cText;ctx.font='10px sans-serif';ctx.lineWidth=1;
  for(let k=0;k<=4;k++){const y=H-padB-k/4*(H-padB-padT);ctx.beginPath();ctx.moveTo(padL,y);ctx.lineTo(W-padR,y);ctx.stroke();ctx.fillText(fmtBps(max*k/4),6,y+3);}
  const fmtT=t=>{const d=new Date(t*1000);const p=n=>String(n).padStart(2,'0');
    if(range==='hour'||range==='day')return p(d.getHours())+':'+p(d.getMinutes());
    if(range==='year')return p(d.getMonth()+1)+'/'+String(d.getFullYear()).slice(-2);
    return p(d.getDate())+'.'+p(d.getMonth()+1)+'.';
  };
  for(let k=0;k<=4;k++){
    const t=t0+k/4*(t1-t0),x=X(t);
    ctx.textAlign=k===0?'left':k===4?'right':'center';
    ctx.fillText(fmtT(t),x,H-9);
  }
  ctx.textAlign='left';
  const series=(key,color,fill)=>{
    ctx.beginPath();ctx.moveTo(X(pts[0].t),Y(pts[0][key]||0));
    for(let i=1;i<pts.length;i++)ctx.lineTo(X(pts[i].t),Y(pts[i][key]||0));
    const lastX=X(pts[pts.length-1].t);
    ctx.strokeStyle=color;ctx.lineWidth=1.7;ctx.stroke();
    ctx.lineTo(lastX,H-padB);ctx.lineTo(X(pts[0].t),H-padB);ctx.closePath();ctx.fillStyle=fill;ctx.fill();
  };
  series('tx','#3b6fd6','rgba(59,111,214,.22)');
  series('rx','#35b779','rgba(53,183,121,.22)');
  ctx.fillStyle='#35b779';ctx.fillRect(padL,6,11,11);ctx.fillStyle=cText;ctx.fillText('RX · Inbound',padL+16,15);
  ctx.fillStyle='#3b6fd6';ctx.fillRect(padL+92,6,11,11);ctx.fillStyle=cText;ctx.fillText('TX · Outbound',padL+108,15);
  const tooltip=cv.parentElement.querySelector('.graph-chart-tooltip');
  cv.onmousemove=event=>{
    const bounds=cv.getBoundingClientRect(),localX=event.clientX-bounds.left;
    const ratio=Math.max(0,Math.min(1,(localX-padL)/(W-padL-padR)));
    const point=pts.reduce((nearest,current)=>
      Math.abs((current.t-t0)/((t1-t0)||1)-ratio)<Math.abs((nearest.t-t0)/((t1-t0)||1)-ratio)?current:nearest
    ,pts[0]);
    const date=new Date(point.t*1000);
    tooltip.textContent=`${date.toLocaleString()} · RX Inbound ${fmtBps(point.rx)} · TX Outbound ${fmtBps(point.tx)}`;
    tooltip.style.left=`${Math.max(6,Math.min(bounds.width-250,localX+12))}px`;
    tooltip.style.top=`${Math.max(6,event.clientY-bounds.top-38)}px`;
    tooltip.classList.add('visible');
  };
  cv.onmouseleave=()=>tooltip.classList.remove('visible');
}
const roleSk=new Proxy({user:'Používateľ',employee:'Employee',admin:'Admin',administrator:'Administrátor'},{get:(o,k)=>TR(o[k]!=null?o[k]:String(k))});
const SNMPIC='<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12a7 7 0 0 1 14 0M8.5 12a3.5 3.5 0 0 1 7 0"/><circle cx="12" cy="12" r="1.2" fill="currentColor"/><path d="M2 9a11 11 0 0 1 20 0"/></svg>';
async function loadSettings(){
  const me=await api.get('whoami'); S.role=me.role; S.user=me.user; S._me=me;
  showDefaultPwWarning(me);
  S._srank={user:1,admin:2,administrator:3}[me.role]||1;
  let tabs=`<button data-sp="appearance">${UI.palette}<span>${TR('Vzhľad')}</span></button>`+
           `<button data-sp="password">${UI.key}<span>${TR('Heslo')}</span></button>`;
  if(S._srank>=2) tabs+=`<button data-sp="users">${UI.users}<span>${TR('Používatelia')}</span></button>`;
  if(S._srank>=3) tabs+=`<button data-sp="telegram">${UI.send}<span>Telegram</span></button>`;
  if(S._srank>=3) tabs+=`<button data-sp="snmp">${SNMPIC}<span>SNMP</span></button>`;
  if(S._srank>=3) tabs+=`<button data-sp="backup">${UI.db}<span>${TR('Zálohy a import')}</span></button>`;
  $('settings-body').innerHTML=`<div class="settings-subnav">${tabs}</div><div id="settings-panel"></div>`;
  document.querySelectorAll('.settings-subnav button').forEach(b=>b.onclick=()=>openSettingsPanel(b.dataset.sp));
  openSettingsPanel('appearance');
}
function openSettingsPanel(name){
  document.querySelectorAll('.settings-subnav button').forEach(b=>b.classList.toggle('active',b.dataset.sp===name));
  ({appearance:panelAppearance,password:panelPassword,users:panelUsers,telegram:panelTelegram,snmp:panelSnmp,backup:panelBackup}[name]||panelAppearance)($('settings-panel'));
}
function panelAppearance(p){
  p.innerHTML=`<div class="settings-card"><h3>${UI.palette} ${TR('Vzhľad')}</h3>
    <div class="theme-seg"><button class="active" type="button" disabled>${UI.moon}<span>${TR('Tmavá')}</span></button></div></div>\n    <div class="settings-card"><h3>\uD83C\uDF10 ${TR('Jazyk')}</h3><select class="lang-select" id="lang-set" style="max-width:240px"></select></div>`;
  if(window.I18N)I18N.buildSelect(document.getElementById('lang-set'));
  if(S._srank>=3) renderTzCard(p);
}
async function renderTzCard(p){
  const card=document.createElement('div');
  card.className='settings-card';
  card.innerHTML='<h3>\u23F0 '+TR('Časové pásmo')+'</h3>'
    +'<p style="color:var(--muted);font-size:13px;margin:0 0 12px">'+TR('@help_timezone')+'</p>'
    +'<select id="tz-sel" style="max-width:320px"></select>'
    +'<div class="modal-actions" style="margin-top:12px"><button class="btn" id="tz-save">'+TR('Uložiť')+'</button></div>'
    +'<div id="tz-msg" style="font-size:13px;margin-top:8px"></div>';
  p.appendChild(card);
  const msgEarly=card.querySelector('#tz-msg');
  let st={},list=[];
  try{ st=await api.get('get_settings'); list=await api.get('timezones'); }
  catch(e){ msgEarly.innerHTML='<span class="st-down">'+TR('Chyba')+'</span>'; return; }
  const sel=card.querySelector('#tz-sel');
  const auto=document.createElement('option');
  auto.value=''; auto.textContent=TR('Automaticky zo servera')+' ('+(st.timezone_sys||'UTC')+')';
  sel.appendChild(auto);
  (list||[]).forEach(z=>{ const o=document.createElement('option'); o.value=z; o.textContent=z; sel.appendChild(o); });
  sel.value=st.timezone||'';
  const msg=card.querySelector('#tz-msg');
  msg.innerHTML='<span style="color:var(--muted)">'+TR('Čas na serveri:')+' '+esc(st.now||'')+'</span>';
  card.querySelector('#tz-save').onclick=async()=>{
    const r=await api.post('save_settings',{timezone:sel.value});
    if(r&&r.error){ msg.innerHTML='<span class="st-down">'+esc(r.error)+'</span>'; return; }
    const s2=await api.get('get_settings');
    msg.innerHTML='<span class="st-up">'+TR('Uložené.')+'</span> <span style="color:var(--muted)">'+esc(s2.now||'')+'</span>';
  };
}
function panelPassword(p){
  const me=S._me||{user:'',role:'user'};
  p.innerHTML=`<div class="settings-card"><h3>${UI.key} ${TR('Zmena môjho hesla')}</h3>
    <div style="color:var(--muted);font-size:12px;margin-bottom:12px">${TR('Prihlásený:')} <b>${esc(me.user)}</b> · ${TR('rola:')} <b>${esc(roleSk[me.role]||me.role)}</b></div>
    <label>${TR('Staré heslo')}<input type="password" id="pw-old"></label>
    <label>${TR('Nové heslo')}<input type="password" id="pw-new"></label>
    <button class="btn" id="pw-save">${TR('Uložiť')}</button>
    <div id="pw-msg" style="margin-top:10px;font-size:13px"></div></div>`;
  $('pw-save').onclick=async()=>{
    const r=await api.post('change_password',{old:$('pw-old').value,new:$('pw-new').value});
    $('pw-msg').innerHTML=r.ok?'<span class="st-up">'+TR('Heslo zmenené.')+'</span>':'<span class="st-down">'+esc(r.error||TR('Chyba'))+'</span>';
  };
}
function panelUsers(p){
  const rank=S._srank;
  p.innerHTML=`<div class="user-admin-grid">
    <div class="settings-card">
      <h3>${UI.users} ${TR('PRIDAŤ POUŽÍVATEĽA')}</h3>
      <div class="user-add-grid">
        <input id="nu-name" placeholder="${TR('Meno')}">
        <input id="nu-pass" type="password" placeholder="${TR('Heslo')}">
        <select id="nu-role">${rank>=3?('<option value="user">'+TR('Používateľ')+'</option><option value="employee">Employee</option><option value="admin">Admin</option><option value="administrator">'+TR('Administrátor')+'</option>'):('<option value="user">'+TR('Používateľ')+'</option><option value="employee">Employee</option>')}</select>
        <button class="btn" id="nu-add">${TR('Pridať')}</button>
      </div>
      <div id="nu-msg" style="margin-top:8px;font-size:13px"></div>
    </div>
    <div class="settings-card">
      <h3>${TR('Graph permissions')}</h3>
      <label>${TR('Select user')}<select id="ug-user"></select></label>
      <label>${TR('Device')}<select id="ug-device"></select></label>
      <div id="ug-list" style="display:grid;gap:8px;margin:12px 0"></div>
      <div id="ug-pages" class="graph-pagination"></div>
      <button class="btn" id="ug-save">${TR('Save permissions')}</button>
      <div id="ug-msg" style="margin-top:8px;font-size:13px"></div>
    </div>
  </div>
  <div class="settings-card" style="margin-top:14px">
    <h3>${TR('Používatelia')}</h3>
    <div id="users-tbl"></div>
  </div>`;

  loadUsers(rank);
  const historyCard=document.createElement('div');
  historyCard.className='settings-card';
  historyCard.style.marginTop='14px';
  historyCard.innerHTML=`<div style="display:flex;align-items:center;justify-content:space-between;gap:12px">
    <h3 style="margin:0">${TR('Login activity')}</h3>
    <button class="mini" id="login-history-refresh">${TR('Refresh')}</button>
  </div><div id="login-history-tbl" style="margin-top:10px"></div>`;
  p.appendChild(historyCard);
  const refreshHistory=()=>loadLoginHistory(historyCard.querySelector('#login-history-tbl'));
  historyCard.querySelector('#login-history-refresh').onclick=refreshHistory;
  refreshHistory();
  $('nu-add').onclick=async()=>{
    const r=await api.post('user_add',{
      username:$('nu-name').value,
      password:$('nu-pass').value,
      role:$('nu-role').value
    });
    $('nu-msg').innerHTML=r.ok
      ?'<span class="st-up">'+TR('Pridaný.')+'</span>'
      :'<span class="st-down">'+esc(r.error||TR('Chyba'))+'</span>';
    if(r.ok){
      $('nu-name').value='';$('nu-pass').value='';
      loadUsers(rank);
    }
  };
}
async function loadLoginHistory(container){
  container.innerHTML='<div class="empty">'+TR('Loading…')+'</div>';
  try{
    const rows=await api.get('login_history');
    container.innerHTML=rows.map(row=>`<div class="svc-row">
      <span class="grow">${esc(row.username)}</span>
      <span style="color:var(--muted)">${esc(new Date(row.logged_at*1000).toLocaleString())}</span>
    </div>`).join('')||'<div class="empty">'+TR('No login history')+'</div>';
  }catch(e){
    container.innerHTML='<div class="st-down">'+esc(e.message||TR('Error'))+'</div>';
  }
}
async function panelTelegram(p){
  const s=await api.get('get_settings');
  p.innerHTML=`<div class="settings-card"><h3>${UI.send} ${TR('Telegram notifikácie')}</h3>
    <p style="color:var(--muted);font-size:13px;margin:0 0 14px">${TR('@help_telegram')}</p>
    <label class="chk"><input type="checkbox" id="tg-en" ${s.tg_enabled==='1'?'checked':''}> ${TR('Zapnúť Telegram notifikácie')}</label>
    <label>${TR('Bot token')}<input id="tg-token" value="${esc(s.tg_token)}" placeholder="123456789:ABCdef..."></label>
    <label>${TR('Chat ID')}<input id="tg-chat" value="${esc(s.tg_chat)}" placeholder="-1001234567890"></label>
    <div class="modal-actions"><button class="btn ghost" id="tg-test">${TR('Poslať test')}</button><button class="btn" id="tg-save">${TR('Uložiť')}</button></div>
    <div id="tg-msg" style="margin-top:10px;font-size:13px"></div></div>
    <div class="settings-card"><h3>SMS Gateway</h3>
    <p style="color:var(--muted);font-size:13px">Device Down/Up alerts. Separate from Telegram. Enter multiple recipient numbers separated by commas or new lines.</p>
    <label class="chk"><input type="checkbox" id="sms-en" ${s.sms_enabled==='1'?'checked':''}> Enable SMS alerts</label>
    <label>HTTP(S) gateway endpoint<input id="sms-url" type="url" value="${esc(s.sms_gateway_url||'')}" placeholder="http://sms-provider.example/sendtext" autocomplete="url"></label>
    <label>API key<input id="sms-key" type="password" autocomplete="new-password" placeholder="${s.sms_key_saved==='1'?'Saved; leave blank to keep existing key':'Enter API key'}"></label>
    <label>Secret key<input id="sms-secret" type="password" autocomplete="new-password" placeholder="${s.sms_secret_saved==='1'?'Saved; leave blank to keep existing secret':'Enter secret key'}"></label>
    <label>Caller ID<input id="sms-caller" value="${esc(s.sms_callerid||'')}" placeholder="8809612770450"></label>
    <label>Recipient numbers<textarea id="sms-to" rows="3" placeholder="8801XXXXXXXXX, 8801YYYYYYYYY">${esc(s.sms_recipients||'')}</textarea></label>
    <p style="color:var(--warn);font-size:12px">Use the exact HTTP(S) endpoint documented by your provider. HTTP is unencrypted and exposes the API key, secret, phone number, and message to network observers; prefer HTTPS when supported. Redirects are rejected. The test sends to the first valid number.</p>
    <div class="modal-actions"><button class="btn ghost" id="sms-test">Test SMS</button><button class="btn" id="sms-save">Save SMS settings</button></div>
    <div id="sms-msg" style="margin-top:10px;font-size:13px"></div></div>`;

  $('tg-save').onclick=async()=>{
    const r=await api.post('save_settings',{tg_enabled:$('tg-en').checked?1:0,tg_token:$('tg-token').value,tg_chat:$('tg-chat').value});
    $('tg-msg').innerHTML=r.ok?'<span class="st-up">'+TR('Uložené.')+'</span>':'<span class="st-down">'+esc(r.error||TR('Chyba'))+'</span>';};
  $('tg-test').onclick=async()=>{
    const tok=$('tg-token').value.trim(), chat=$('tg-chat').value.trim();
    if(!tok.includes(':')){ $('tg-msg').innerHTML='<span class="st-down">'+TR('@tg_incomplete')+'</span>'; return; }
    $('tg-msg').textContent=TR('Posielam test…');
    const r=await api.post('test_telegram',{tg_token:tok,tg_chat:chat});
    if(r.ok){ $('tg-msg').innerHTML='<span class="st-up">'+TR('@tg_ok')+'</span>'; return; }
    let hint=esc(r.error||TR('Chyba'));
    if(/404|Not Found/i.test(r.error||'')) hint=TR('@tg_err_token');
    else if(/chat not found|400/i.test(r.error||'')) hint=TR('@tg_err_chat');
    $('tg-msg').innerHTML='<span class="st-down">'+hint+'</span>';};

  const smsPayload=()=>({
    sms_enabled:$('sms-en').checked?1:0,
    sms_gateway_url:$('sms-url').value.trim(),
    sms_apikey:$('sms-key').value.trim(),
    sms_secretkey:$('sms-secret').value.trim(),
    sms_callerid:$('sms-caller').value.trim(),
    sms_recipients:$('sms-to').value.trim()
  });
  $('sms-save').onclick=async()=>{
    $('sms-msg').textContent='Saving…';
    const r=await api.post('save_settings',smsPayload());
    $('sms-msg').innerHTML=r.ok?'<span class="st-up">SMS settings saved.</span>':'<span class="st-down">'+esc(r.error||'Save failed')+'</span>';
    if(r.ok){$('sms-key').value='';$('sms-secret').value='';}
  };
  $('sms-test').onclick=async()=>{
    $('sms-msg').textContent='Sending test SMS…';
    const r=await api.post('test_sms',smsPayload());
    if(Array.isArray(r.results)){
      $('sms-msg').innerHTML=r.results.map(x=>
        '<div style="margin-top:8px;padding:8px;border:1px solid var(--border);border-radius:6px">'
        +'<strong>'+esc(x.recipient)+'</strong> — '
        +(x.ok?'<span class="st-up">Gateway accepted</span>':'<span class="st-down">'+esc(x.error||'Request failed')+'</span>')
        +'<div style="margin-top:4px;white-space:pre-wrap;overflow-wrap:anywhere">'+esc(x.gateway_response||'(Empty gateway response)')+'</div></div>'
      ).join('');
    }else{
      $('sms-msg').innerHTML='<span class="st-down">'+esc(r.error||'Test failed')+'</span>';
    }
  };
}
async function panelSnmp(p){
  const st=await api.get('get_settings');
  const profs=await api.get('snmp_profiles_list');
  const rows=profs.map(pr=>`<div class="svc-row" data-id="${pr.id}">
    <span class="grow">${esc(pr.name)} <span style="color:var(--muted)">${pr.version==1?'v2c':(pr.version==0?'v1':'—')} · ${esc(pr.community||'')} · :${pr.port}</span></span>
    <button class="mini sp-edit">${TR('upraviť')}</button><button class="mini sp-del">${TR('zmazať')}</button></div>`).join('')||'<div class="empty">'+TR('Žiadne profily')+'</div>';
  p.innerHTML=`<div class="settings-card"><h3>${SNMPIC} ${TR('Meranie toku')}</h3>
    <div class="form-grid">
      <label>${TR('Interval merania SNMP (s)')}<input id="iv-snmp" type="number" min="3" value="${esc(st.snmp_interval||'30')}"></label>
      <label>${TR('Obnova mapy v prehliadači (s)')}<input id="iv-map" type="number" min="2" value="${esc(st.map_refresh||'10')}"></label>
      <label>${TR('Držať históriu (dní)')}<input id="iv-hd" type="number" min="1" value="${esc(st.history_days||'14')}"></label>
      <label>${TR('Vzorkovať odozvu každých (s)')}<input id="iv-he" type="number" min="0" value="${esc(st.history_every||'60')}"></label>
      <label>${TR('Držať históriu toku (dní)')}<input id="iv-td" type="number" min="1" value="${esc(st.traffic_days||'90')}"></label>
    </div>
    <p style="color:var(--muted);font-size:12px;margin:0 0 8px">${TR('@help_history')}</p>
    <div class="modal-actions"><button class="btn" id="iv-save">${TR('Uložiť intervaly')}</button></div>
    <div id="iv-msg" style="font-size:13px;margin-top:8px"></div></div>
    <div class="settings-card"><h3>${SNMPIC} ${TR('SNMP profily')}</h3>
    <p style=\"color:var(--muted);font-size:13px;margin:0 0 12px\">${TR('@help_snmp_profiles')}</p>
    <div id="sp-list">${rows}</div>
    <h4 style="margin:16px 0 8px;color:var(--muted);font-size:12px">${TR('PRIDAŤ / UPRAVIŤ PROFIL')}</h4>
    <input type="hidden" id="sp-id">
    <div class="user-add-grid">
      <input id="sp-name" placeholder="${TR('Názov (napr. v2-public)')}">
      <input id="sp-comm" placeholder="${TR('Community')}" value="public">
      <select id="sp-ver"><option value="0">SNMP v1</option><option value="1" selected>SNMP v2c</option><option value="2">SNMP v3</option></select>
      <input id="sp-port" placeholder="${TR('Port')}" value="161">
    </div>
    <div id="sp-v3" style="display:none">
      <h4 style="margin:14px 0 8px;color:var(--muted);font-size:12px">${TR('SNMP v3 – MENO A HESLO')}</h4>
      <div class="user-add-grid">
        <input id="sp-sec" placeholder="${TR('Meno (security name)')}">
        <select id="sp-authp"><option value="MD5">Auth MD5</option><option value="SHA">Auth SHA</option></select>
        <input id="sp-auth" type="password" placeholder="${TR('Auth heslo')}">
        <select id="sp-privp"><option value="DES">Priv DES</option><option value="AES">Priv AES</option></select>
        <input id="sp-priv" type="password" placeholder="${TR('Priv heslo (šifrovanie)')}">
      </div>
    </div>
    <div class="modal-actions"><button class="btn ghost" id="sp-clear">${TR('Nový')}</button><button class="btn" id="sp-save">${TR('Uložiť profil')}</button></div>
    <div id="sp-msg" style="margin-top:8px;font-size:13px"></div></div>`;
  const v3vis=()=>{$('sp-v3').style.display=$('sp-ver').value==='2'?'block':'none';};
  const fill=(pr)=>{$('sp-id').value=pr?pr.id:'';$('sp-name').value=pr?pr.name:'';$('sp-comm').value=pr?(pr.community||''):'public';$('sp-ver').value=pr?pr.version:'1';$('sp-port').value=pr?pr.port:'161';
    $('sp-sec').value=pr?(pr.sec_name||''):'';$('sp-auth').value=pr?(pr.auth_pass||''):'';$('sp-priv').value=pr?(pr.priv_pass||''):'';$('sp-authp').value=pr?(pr.auth_proto||'MD5'):'MD5';$('sp-privp').value=pr?(pr.priv_proto||'DES'):'DES';v3vis();};
  $('iv-save').onclick=async()=>{await api.post('save_settings',{snmp_interval:$('iv-snmp').value,map_refresh:$('iv-map').value,history_days:$('iv-hd').value,history_every:$('iv-he').value,traffic_days:$('iv-td').value});$('iv-msg').innerHTML='<span class="st-up">'+TR('@iv_saved')+'</span>';startLive();};
  $('sp-ver').onchange=v3vis; v3vis();
  $('sp-save').onclick=async()=>{
    if(!$('sp-name').value.trim()){$('sp-msg').innerHTML='<span class="st-down">'+TR('Zadaj názov.')+'</span>';return;}
    const r=await api.post('snmp_profile_save',{id:$('sp-id').value,name:$('sp-name').value,community:$('sp-comm').value,version:$('sp-ver').value,port:$('sp-port').value,
      sec_name:$('sp-sec').value,auth_pass:$('sp-auth').value,priv_pass:$('sp-priv').value,auth_proto:$('sp-authp').value,priv_proto:$('sp-privp').value});
    if(r.ok){panelSnmp(p);} else {$('sp-msg').innerHTML='<span class="st-down">'+esc(r.error||TR('Chyba'))+'</span>';}
  };
  $('sp-clear').onclick=()=>fill(null);
  p.querySelectorAll('.sp-edit').forEach(b=>b.onclick=()=>{const id=b.closest('.svc-row').dataset.id;fill(profs.find(x=>x.id==id));});
  p.querySelectorAll('.sp-del').forEach(b=>b.onclick=async()=>{const id=b.closest('.svc-row').dataset.id;if(confirm(TR('Zmazať profil?'))){await api.post('snmp_profile_delete',{id});panelSnmp(p);}});
}
function panelBackup(p){
  p.innerHTML=`<div class="settings-card"><h3>${UI.db} ${TR('Záloha, obnova a import')}</h3>
    <p style=\"color:var(--muted);font-size:13px;margin:0 0 12px\">${TR('@help_backup')}</p>
    <a class="btn" href="api.php?action=backup">⬇ ${TR('Stiahnuť zálohu (.db)')}</a>
    <div style="margin-top:16px"><label>${TR('Obnoviť zo zálohy (.db)')}<input type="file" id="rf" accept=".db"></label>
      <button class="btn ghost" id="rf-btn">${TR('Obnoviť databázu')}</button></div>
    <div style="margin-top:14px"><label>${TR('Import z Dude (dude.db)')}<input type="file" id="imf" accept=".db"></label>
      <button class="btn ghost" id="imf-btn">${TR('Importovať z Dude')}</button></div>
    <div id="br-msg" style="margin-top:12px;font-size:13px"></div></div>`;
  $('rf-btn').onclick=()=>doUpload('restore',$('rf'),TR('@restored'));
  $('imf-btn').onclick=()=>doUpload('import_dude',$('imf'),TR('@imported'));
}
async function loadUsers(rank){
  const us=await api.get('users_list');
  const rows=us.map(u=>{
    let actions='';
    if(rank>=3){
      actions=`<select class="mini u-role" data-id="${u.id}">
          ${['user','employee','admin','administrator'].map(r=>`<option value="${r}" ${r===u.role?'selected':''}>${roleSk[r]}</option>`).join('')}</select>
        <button class="mini u-pw" data-id="${u.id}">${TR('heslo')}</button>`;
    }
    actions+=`<button class="mini u-del" data-id="${u.id}">${TR('zmazať')}</button>`;
    return `<div class="svc-row"><span class="grow">${esc(u.username)} <span style="color:var(--muted)">${esc(roleSk[u.role]||u.role)}</span></span>${actions}</div>`;
  }).join('')||'<div class="empty">'+TR('Žiadni používatelia')+'</div>';
  $('users-tbl').innerHTML=rows;
  if(rank>=2) renderUserGraphPermissions(us);
  document.querySelectorAll('.u-del').forEach(b=>b.onclick=async()=>{
    if(confirm(TR('Zmazať používateľa?'))){const r=await api.post('user_delete',{id:b.dataset.id});if(!r.ok)alert(r.error);loadUsers(rank);}});
  document.querySelectorAll('.u-role').forEach(sel=>sel.onchange=async()=>{
    const r=await api.post('user_set_role',{id:sel.dataset.id,role:sel.value});if(!r.ok){alert(r.error);loadUsers(rank);}});
  document.querySelectorAll('.u-pw').forEach(b=>b.onclick=async()=>{
    const np=prompt(TR('Nové heslo pre používateľa:'));if(!np)return;
    const r=await api.post('user_reset_password',{id:b.dataset.id,new:np});alert(r.ok?TR('Heslo zmenené.'):r.error);});
}
async function renderUserGraphPermissions(users){
  const userSel=$('ug-user'), deviceSel=$('ug-device');
  const list=$('ug-list'), msg=$('ug-msg'), save=$('ug-save');
  if(!userSel||!deviceSel||!list||!save)return;

  const oldUser=userSel.value;
  userSel.innerHTML=users.map(u=>
    `<option value="${u.id}">${esc(u.username)} (${esc(u.role)})</option>`
  ).join('');
  if(oldUser&&users.some(u=>String(u.id)===oldUser))userSel.value=oldUser;

  let sources=[];
  let selectedIds=new Set();
  let currentPage=0;
  const pageSize=5;

  function renderDeviceGraphs(){
    const deviceId=deviceSel.value;
    const visible=sources.filter(g=>String(g.device_id)===String(deviceId));
    const pageCount=Math.max(1,Math.ceil(visible.length/pageSize));
    currentPage=Math.min(currentPage,pageCount-1);
    const pageItems=visible.slice(currentPage*pageSize,(currentPage+1)*pageSize);

    list.innerHTML=pageItems.map(g=>`<label class="chk">
      <input type="checkbox" class="ug-check" data-source="${esc(g.id)}"
        ${selectedIds.has(String(g.id))?'checked':''}>
      <span>${esc(g.name)} <small style="color:var(--muted)">${esc(g.sub||'')}</small></span>
    </label>`).join('')||`<div class="empty">${TR('No graph sources available for this device')}</div>`;

    list.querySelectorAll('.ug-check').forEach(ch=>{
      ch.onchange=()=>{
        if(ch.checked)selectedIds.add(String(ch.dataset.source));
        else selectedIds.delete(String(ch.dataset.source));
      };
    });

    const pager=$('ug-pages');
    if(pager){
      const from=visible.length ? currentPage*pageSize+1 : 0;
      const to=Math.min((currentPage+1)*pageSize,visible.length);
      pager.innerHTML=`<button type="button" class="mini" id="ug-prev" ${currentPage===0?'disabled':''}>Previous</button>
        <span>${from}-${to} / ${visible.length}</span>
        <button type="button" class="mini" id="ug-next" ${currentPage>=pageCount-1?'disabled':''}>Next</button>`;
      $('ug-prev').onclick=()=>{if(currentPage>0){currentPage--;renderDeviceGraphs();}};
      $('ug-next').onclick=()=>{if(currentPage<pageCount-1){currentPage++;renderDeviceGraphs();}};
    }
  }

  async function loadUserPermissions(){
    msg.textContent='';
    list.innerHTML='';
    const uid=userSel.value;
    if(!uid){deviceSel.innerHTML='';return;}

    try{
      const results=await Promise.all([
        api.get('graph_sources'),
        api.get('graph_permissions_get',{user_id:uid})
      ]);
      sources=Array.isArray(results[0])?results[0]:[];
      selectedIds=new Set((results[1].source_ids||[]).map(String));

      const devices=new Map();
      sources.forEach(g=>{
        if(g.device_id!==undefined && g.device_id!==null){
          devices.set(String(g.device_id),g.device_name||('Device '+g.device_id));
        }
      });
      deviceSel.innerHTML=[...devices.entries()]
        .sort((a,b)=>a[1].localeCompare(b[1]))
        .map(([id,name])=>`<option value="${esc(id)}">${esc(name)}</option>`)
        .join('');

      if(!devices.size){
        list.innerHTML=`<div class="empty">${TR('No graph sources available')}</div>`;
        msg.textContent=TR('No graph sources found. Check SNMP/interface discovery.');
        return;
      }
      renderDeviceGraphs();
    }catch(e){
      msg.textContent=TR('Could not load graph permissions');
      list.innerHTML='';
    }
  }

  userSel.onchange=loadUserPermissions;
  deviceSel.onchange=()=>{currentPage=0;renderDeviceGraphs();};
  save.onclick=async()=>{
    if(!userSel.value)return;
    save.disabled=true;
    msg.textContent=TR('Saving...');
    try{
      const r=await api.post('graph_permissions_save',{
        user_id:userSel.value,
        source_ids:[...selectedIds]
      });
      msg.textContent=r.ok
        ?`${TR('Saved')}: ${r.count}`
        :(r.error||TR('Error'));
      msg.className=r.ok?'st-up':'st-down';
    }catch(e){
      msg.textContent=TR('Error saving permissions');
      msg.className='st-down';
    }finally{
      save.disabled=false;
    }
  };
  await loadUserPermissions();
}
async function doUpload(action,input,okMsg){
  const f=input.files[0]; if(!f){$('br-msg').innerHTML='<span class="st-down">'+TR('Vyber súbor.')+'</span>';return;}
  if(action==='restore'&&!confirm(TR('Naozaj nahradiť aktuálnu databázu zálohou?')))return;
  if(action==='import_dude'&&!confirm(TR('Import prepíše mapy/zariadenia dátami z dude.db. Pokračovať?')))return;
  $('br-msg').textContent=TR('Nahrávam…');
  const fd=new FormData();fd.append('file',f);
  const r=await fetch('api.php?action='+action,{method:'POST',headers:{'X-NetPulse':'1'},body:fd}).then(x=>x.json()).catch(()=>({error:TR('Chyba prenosu')}));
  if(action==='restore'&&r&&r.ok){ location.href='login.php'; return; }   // po obnove sa treba prihlásiť znova
  $('br-msg').innerHTML=r.ok?'<span class="st-up">'+okMsg+'</span>':'<span class="st-down">'+esc(r.error||TR('Chyba'))+'</span>';
}

// ---------- štart ----------
api.get('whoami').then(w=>{
  S.role=w.role||'user';
  S.user=w.user;
  document.body.dataset.role=S.role;
  showDefaultPwWarning(w);
  if(S.role==='user'){
    switchSection('graphs');
  }else if(S.role==='employee'){
    switchSection('maps');
    loadSummary();
    loadMaps();
  }else{
    loadSummary();
    loadMaps();
    loadAlerts();
    checkInterfaceStatus();
  }
}).catch(()=>{});

// ---- TÉMA (svetlá/tmavá/auto) ----
function getTheme(){return 'dark';}
function setTheme(){
  document.documentElement.dataset.theme='dark';
  try{localStorage.setItem('np-theme','dark');}catch(error){console.warn('Could not save dark theme preference:',error);}
}
setTheme();

// ---- ŽIVÉ prefarbovanie (bez reloadu) ----
async function liveTick(){
  if(S.role==='user') return;
  const employee=S.role==='employee';
  if(!employee)checkInterfaceStatus();
  try{
    loadSummary();
    if(!employee)loadAlerts();
    if(S.section==='maps'&&S.mapId&&S.data&&S.data.nodes){
      // obnov celú mapu (stavy + tok) so zachovaním pohľadu a výberu
      const view={...S.view}, sel=S.sel;
      const d=await api.get('map',{id:S.mapId});
      S.data=d; S.view=view; S.sel=sel;
      render();
      // odznaky výpadkov v ľavom zozname
      const maps=await api.get('maps');
      maps.forEach(m=>{const li=document.querySelector(`#map-list li[data-id="${m.id}"]`);if(!li)return;
        const el=li.querySelector('.badge,.cnt');if(!el)return;
        if(m.down>0){el.className='badge';el.textContent=m.down;}else{el.className='cnt';el.textContent=m.nodes;}});
    }
  }catch(e){}
}
let liveTimer=null;
async function startLive(){
  let iv=10; try{const c=await api.get('client_config'); iv=Math.max(2,+c.map_refresh||10);}catch(e){}
  if(liveTimer)clearInterval(liveTimer);
  liveTimer=setInterval(liveTick, iv*1000);
}
startLive();

// ================= OKNO ZARIADENIA =================
let curDev=null, curDevData=null;
async function openDeviceDialog(devId){
  if(!devId)return;curDev=devId;
  $('dlg-device').classList.remove('hidden');
  showTab('obecne');
  await loadDeviceDialog();
}
async function loadDeviceDialog(){
  const d=await api.get('device',{id:curDev});curDevData=d;
  const dev=d.device||{};
  $('dlg-title').textContent=dev.name||TR('Zariadenie');
  // Obecné (editovateľné)
  const types=(await api.get('device_types'));
  const opts=types.map(t=>`<option value="${t.id}" ${t.id==dev.type_id?'selected':''}>${esc(t.name)}</option>`).join('');
  const oltAllowlist=(dev.olt_interface_allowlist||[]).join('\n');
  $('tab-obecne').innerHTML=`
    <div class="form-grid">
      <label>${TR('Názov')}<input id="e-name" value="${esc(dev.name||'')}"></label>
      <label>${TR('Adresa (IP)')}<input id="e-ip" value="${esc(dev.ip||'')}"></label>
      <label>${TR('DNS názov')}<input id="e-dns" value="${esc(dev.dns||'')}"></label>
      <label>${TR('Typ')}<select id="e-type">${opts}</select></label>
      <label id="e-olt-allowlist-wrap" style="display:none">OLT interfaces
        <textarea id="e-olt-interfaces" rows="4" placeholder="GE0/1&#10;GE0/2">${esc(oltAllowlist)}</textarea>
        <small>${TR('One interface per line; applies only to OLT monitoring.')}</small>
      </label>
      <label>Vendor<select id="e-vendor">
        <option value="unknown" ${(!dev.vendor||dev.vendor==="unknown")?"selected":""}>Unknown</option>
        <option value="mikrotik" ${dev.vendor==="mikrotik"?"selected":""}>MikroTik</option>
        <option value="cisco" ${dev.vendor==="cisco"?"selected":""}>Cisco</option>
        <option value="bdcom" ${dev.vendor==="bdcom"?"selected":""}>BDCOM</option>
        <option value="juniper" ${dev.vendor==="juniper"?"selected":""}>Juniper</option>
        <option value="vsol" ${dev.vendor==="vsol"?"selected":""}>VSOL</option>
        <option value="other" ${dev.vendor==="other"?"selected":""}>Other</option>
      </select></label>
      <label>SSH Port<input id="e-ssh-port" type="number" min="1" max="65535" step="1" value="${Number(dev.ssh_port)||22}" required></label>
      <label>${TR('Používateľ')}<input id="e-user" value="${esc(dev.username||'')}"></label>
      <label>${TR('Heslo')}<input id="e-pass" type="password" value="${esc(dev.password||'')}"></label>
      <label>${TR('Stav')}<input value="${statusSk(dev.status)}" disabled></label>
    </div>
    <label class="chk" style="margin:4px 2px 12px"><input type="checkbox" id="e-mon" ${String(dev.monitored)==='0'?'':'checked'}> ${TR('Monitorovať zariadenie')} <span style="color:var(--muted)">${TR('(vypnuté = sivé)')}</span></label>
    <div class="modal-actions"><button class="btn" id="e-save">${TR('Uložiť')}</button>${canEdit()?`<button class="btn danger" id="e-del" style="margin-left:auto">${TR('Zmazať zariadenie')}</button>`:''}</div>
    <div id="e-msg" style="margin-top:8px;font-size:13px"></div>`;
  const updateOltAllowlistVisibility=()=>{
    const selectedType=types.find(t=>String(t.id)===String($('e-type').value));
    $('e-olt-allowlist-wrap').style.display=String(selectedType?.name||'').toLowerCase()==='olt'?'':'none';
  };
  $('e-type').onchange=updateOltAllowlistVisibility;
  updateOltAllowlistVisibility();
  $('e-save').onclick=saveDevice;
  if($('e-del')) $('e-del').onclick=async()=>{
    if(!confirm(TR('Zmazať zariadenie')+' „'+(dev.name||'')+'"?\n\n'+TR('@del_device_hint'))) return;
    const r=await api.post('delete_device',{id:curDev}).catch(()=>({error:TR('Chyba prenosu')}));
    if(r&&r.ok){ $('dlg-device').classList.add('hidden'); $('inspector').classList.add('hidden');
      loadSummary(); loadAlerts(); if(S.section&&S.section!=='maps') switchSection(S.section); if(S.mapId) selectMap(S.mapId); }
    else { const em=$('e-msg'); if(em) em.innerHTML='<span class="st-down">'+esc((r&&r.error)||TR('Chyba'))+'</span>'; }
  };
  bindMonitorToggle();
  if(!canEdit()){
    document.querySelectorAll('#tab-obecne input,#tab-obecne select,#tab-obecne textarea').forEach(x=>x.disabled=true);
    const es=$('e-save'); if(es)es.style.display='none';
  }
  // Služby
  renderServices(d.services);

  // Nástroje
  $('tab-nastroje').innerHTML=`
    <button class="btn" id="tool-ping">▶ ${TR('Ping')} ${esc(dev.ip||'')}</button>
    <button class="btn ghost" id="tool-refresh">↻ ${TR('Otestovať teraz')}</button>
    <pre class="tool-out" id="tool-out">${TR('Klikni Ping…')}</pre>`;
  $('tool-ping').onclick=async()=>{
    $('tool-out').textContent=TR('Pingujem…');
    const r=await api.get('ping_now',{ip:dev.ip||''});
    $('tool-out').textContent=(r.output||r.error||'')+'\n\n'+(r.ok?'✓ '+TR('Dostupné'):'✗ '+TR('Nedostupné'));
  };
  $('tool-refresh').onclick=async()=>{
    const box=$('tool-out'); if(box) box.textContent=TR('Testujem ping a služby…');
    const r=await api.get('probe_now',{id:curDev}).catch(()=>({error:TR('Chyba prenosu')}));
    if(!box) return;
    if(!r||r.error){ box.textContent=(r&&r.error)||TR('Chyba'); return; }
    const lines=r.results.map(x=>(x.ok?'✓ ':'✗ ')+x.check+' – '+(x.ok?TR('odpovedá')+(x.ms!=null?' ('+x.ms+' ms)':''):TR('neodpovedá'))+(x.service&&!x.enabled?' ['+TR('vypnutá')+']':''));
    const pingOk=r.results.some(x=>!x.service&&x.ok), svcBad=r.results.some(x=>x.service&&!x.ok&&x.enabled);
    if(pingOk&&svcBad) lines.push('', TR('@svc_down_hint'));
    if(!r.results.length) lines.push(TR('Žiadne služby'));
    box.textContent=lines.join('\n');
  };

  // ---- karta SNMP ----
  const profs=await api.get('snmp_profiles_list');
  $('tab-snmp').innerHTML=`
    <p style=\"color:var(--muted);font-size:13px;margin:0 0 12px\">${TR('@help_snmp_dev')}</p>
    <div class="form-grid">
      <label>${TR('SNMP profil')}<select id="e-snmp" ${canEdit()?'':'disabled'}>
        <option value="">${TR('— žiadny (bez SNMP) —')}</option>
        ${profs.map(p=>`<option value="${p.id}" ${dev.snmp_profile==p.id?'selected':''}>${esc(p.name)} (${p.version==2?'v3':(p.version==1?'v2c':(p.version==0?'v1':'—'))})</option>`).join('')}
      </select></label>
      <label>Optical template<select id="e-optical-template" ${canEdit()?'':'disabled'}>
        <option value="auto" ${(!dev.optical_template || dev.optical_template==='auto')?'selected':''}>Auto-detect</option>
        <option value="cisco_nexus_nxos" ${dev.optical_template==='cisco_nexus_nxos'?'selected':''}>Cisco Nexus NX-OS</option>
        <option value="mikrotik_routeros" ${dev.optical_template==='mikrotik_routeros'?'selected':''}>MikroTik RouterOS</option>
        <option value="bdcom" ${dev.optical_template==='bdcom'?'selected':''}>BDCOM</option>
        <option value="vsol" ${dev.optical_template==='vsol'?'selected':''}>VSOL (V1600D4-DP)</option>
        <option value="none" ${dev.optical_template==='none'?'selected':''}>Disabled</option>
      </select></label>
    </div>
    ${canEdit()?'<div class="modal-actions"><button class="btn" id="e-snmp-save">'+TR('Uložiť')+'</button></div>':''}
    <div style="margin-top:6px;font-size:12px;color:var(--muted)">${TR('Profily pridáš/upravíš v Nastavenia → SNMP.')}</div>
    <div id="e-snmp-msg" style="margin-top:8px;font-size:13px"></div>`;
  if(canEdit()){ const b=$('e-snmp-save'); if(b) b.onclick=async()=>{ await saveDevice(); const m=$('e-snmp-msg'); if(m) m.innerHTML='<span class="st-up">'+TR('Uložené.')+'</span>'; }; }
}
/** Pruh s upozornením, keď má používateľ stále predvolené heslo admin/admin. */
function showDefaultPwWarning(me){
  const old=document.getElementById('np-pw-warn'); if(old) old.remove();
  if(!me||!me.default_pw) return;
  const b=document.createElement('div'); b.id='np-pw-warn';
  b.style.cssText='position:fixed;left:50%;transform:translateX(-50%);top:10px;z-index:9999;max-width:92vw;'+
    'background:#b45309;color:#fff;padding:9px 14px;border-radius:10px;font-size:13px;box-shadow:0 6px 20px rgba(0,0,0,.35);cursor:pointer';
  b.textContent='⚠ '+TR('@default_pw_warn');
  b.onclick=()=>b.remove();
  document.body.appendChild(b);
}
/** Prepínač „Monitorovať zariadenie" sa uloží okamžite. */
function bindMonitorToggle(){
  const cb=$('e-mon'); if(!cb||!canEdit()) return;
  cb.onchange=async()=>{
    const want=cb.checked;
    const r=await api.post('toggle_monitor',{id:curDev,monitored:want?1:0}).catch(()=>({error:TR('Chyba prenosu')}));
    const em=$('e-msg');
    if(r&&r.ok){ if(em) em.innerHTML='<span class="st-up">'+TR(want?'Monitoring zapnutý.':'Monitoring vypnutý.')+'</span>';
                 loadSummary(); loadAlerts(); if(S.mapId) selectMap(S.mapId); }
    else { cb.checked=!want; if(em) em.innerHTML='<span class="st-down">'+esc((r&&r.error)||TR('Chyba'))+'</span>'; }
  };
}
async function saveDevice(){
  const r=await api.post('update_device',{id:curDev,name:$('e-name').value,ip:$('e-ip').value,
    dns:$('e-dns').value,type_id:$('e-type').value,vendor:$('e-vendor').value,
    ssh_port:$('e-ssh-port').value,username:$('e-user').value,
    monitored:$('e-mon').checked?1:0, snmp_profile:$('e-snmp')?$('e-snmp').value:'',
    optical_template:$('e-optical-template')?$('e-optical-template').value:'auto',
    olt_interface_allowlist:$('e-olt-interfaces')?$('e-olt-interfaces').value:'',
    password:$('e-pass')?$('e-pass').value:''});
  const em=$('e-msg'); if(em) em.innerHTML=r.ok?'<span class="st-up">'+TR('Uložené.')+'</span>':'<span class="st-down">'+esc(r.error||TR('Chyba'))+'</span>';
  if(r.ok){ loadMaps(); if(S.mapId) await selectMap(S.mapId);
    if(!$('inspector').classList.contains('hidden')) openInspector(curDev); }
}
function renderServices(svcs){
  const rows=(svcs||[]).map(s=>`<div class="svc-row" data-sid="${s.id}">
    <span class="pill ${s.status||'unknown'}">${statusSk(s.status)}</span>
    <span class="grow">${esc(s.name)} <span style="color:var(--muted)">${s.ptype||''}${s.port?':'+s.port:''}</span></span>
    <button class="mini t-toggle" data-on="${(+s.enabled)?0:1}">${(+s.enabled)?TR('vypnúť'):TR('zapnúť')}</button>
    <button class="mini t-del">${TR('zmazať')}</button></div>`).join('')||'<div class="empty">'+TR('Žiadne služby')+'</div>';
  $('tab-sluzby').innerHTML=rows+`
    <div class="add-svc-bar"><select id="svc-probe"></select><button class="btn" id="svc-add">＋ ${TR('Pridať sondu')}</button></div>`;
  api.get('probes').then(ps=>{$('svc-probe').innerHTML=ps.map(p=>`<option value="${p.id}">${esc(p.name)} (${p.type}${p.port?':'+p.port:''})</option>`).join('');});
  $('svc-add').onclick=async()=>{await api.post('add_service',{device_id:curDev,probe_id:$('svc-probe').value});loadDeviceDialog();};
  $('tab-sluzby').querySelectorAll('.t-toggle').forEach(b=>b.onclick=async e=>{
    const row=e.target.closest('.svc-row');const on=e.target.dataset.on==='1';
    await api.post('toggle_service',{service:row.dataset.sid,enabled:on?1:0});loadDeviceDialog();});
  $('tab-sluzby').querySelectorAll('.t-del').forEach(b=>b.onclick=async e=>{
    const row=e.target.closest('.svc-row');
    if(confirm(TR('Zmazať službu?'))){await api.post('del_service',{service:row.dataset.sid});loadDeviceDialog();}});
  if(!canEdit()) $('tab-sluzby').querySelectorAll('.t-toggle,.t-del,.add-svc-bar').forEach(x=>x.style.display='none');
}
function fmtDur(s){s=+s;const h=Math.floor(s/3600),m=Math.floor(s%3600/60),ss=s%60;
  return (h?h+'h ':'')+(m?m+'m ':'')+ss+'s';}
// Optical/SFP: shared history API for the device tab and main sidebar.
async function loadOpticalDevices(){
  const select=$('optical-main-device');
  const empty=$('optical-main-empty');
  if(!select||!empty)return;
  const old=select.value;
  select.disabled=true;
  try{
    const allDevices=await api.get('devices',{q:''});
    if(!Array.isArray(allDevices))throw new Error('Device list unavailable');
    const devices=allDevices.filter(device=>Number(device.id)!==1000004);
    select.innerHTML='<option value="">Select device...</option>'+
      devices.map(d=>'<option value="'+esc(d.id)+'">'+
        esc(d.name||('Device '+d.id))+' ('+esc(d.ip||'')+')</option>').join('');
    if(devices.some(d=>String(d.id)===old))select.value=old;
    else if(devices.length)select.value=String(devices[0].id);
    if(!select.dataset.bound){
      select.dataset.bound='1';
      select.onchange=()=>loadOpticalHistory(Number(select.value),'optical-main');
      $('optical-main-port').onchange=()=>renderOpticalTable(
        window.__opticalPoints_optical_main||[],
        $('optical-main-port').value,'optical-main');
      $('optical-main-range').onchange=()=>loadOpticalHistory(
        Number(select.value),'optical-main');
      $('optical-main-refresh').onclick=()=>loadOpticalHistory(
        Number(select.value),'optical-main');
    }
    if(select.value)await loadOpticalHistory(Number(select.value),'optical-main');
    else{
      empty.textContent='No devices available.';
      $('optical-main-table-wrap').innerHTML='';
    }
  }catch(e){
    empty.textContent='Could not load devices: '+(e.message||'request failed');
  }finally{
    select.disabled=false;
  }
}

async function loadOpticalHistory(deviceId,prefix='optical'){
  const port=$(prefix+'-port'), range=$(prefix+'-range');
  const refresh=$(prefix+'-refresh'), empty=$(prefix+'-empty');
  const wrap=$(prefix+'-table-wrap');
  if(!port||!range||!empty||!wrap)return;
  if(!deviceId){
    empty.textContent='Select a device to load optical readings.';
    wrap.innerHTML='';
    return;
  }
  if(!port.dataset.bound){
    port.dataset.bound='1';
    range.onchange=()=>loadOpticalHistory(
      prefix==='optical'?curDev:Number($('optical-main-device')?.value||0),prefix);
    port.onchange=()=>renderOpticalTable(
      window['__opticalPoints_'+prefix]||[],port.value,prefix);
    if(refresh)refresh.onclick=()=>loadOpticalHistory(
      prefix==='optical'?curDev:Number($('optical-main-device')?.value||0),prefix);
  }
  empty.textContent='Loading optical readings...';
  wrap.innerHTML='';
  try{
    const r=await api.get('optical_history',{device_id:deviceId,range:range.value});
    if(!r||r.error)throw new Error((r&&r.error)||'Could not load optical readings');
    const points=Array.isArray(r.points)?r.points:[];
    window['__opticalPoints_'+prefix]=points;
    const old=port.value;
    const ports=[...new Set(points.map(p=>p.ifname).filter(Boolean))]
      .sort((x,y)=>x.localeCompare(y,undefined,{numeric:true}));
    port.innerHTML='<option value="">All ports</option>'+
      ports.map(p=>'<option value="'+esc(p)+'">'+esc(p)+'</option>').join('');
    if(ports.includes(old))port.value=old;
    renderOpticalTable(points,port.value,prefix);
  }catch(e){
    empty.textContent='Optical readings unavailable: '+(e.message||'request failed');
  }
}

function renderOpticalTable(points,selectedPort,prefix='optical'){
  const empty=$(prefix+'-empty'), wrap=$(prefix+'-table-wrap');
  if(!empty||!wrap)return;
  const rows=(Array.isArray(points)?points:[])
    .filter(p=>!selectedPort||p.ifname===selectedPort);
  const latest=new Map();
  rows.forEach(p=>{
    const key=String(p.ifname||'')+'|'+String(p.direction||'').toLowerCase();
    const old=latest.get(key);
    if(!old||String(p.sampled_at||'')>String(old.sampled_at||''))latest.set(key,p);
  });
  const ports=[...new Set(rows.map(p=>p.ifname).filter(Boolean))]
    .sort((a,b)=>a.localeCompare(b,undefined,{numeric:true}));
  if(!ports.length){
    empty.textContent='No optical power readings for this device and range.';
    wrap.innerHTML='';
    return;
  }
  const fmt=v=>v===null||v===undefined||v===''||!Number.isFinite(Number(v))
    ?'—':Number(v).toFixed(3);
  const fmtTime=v=>v?String(v).replace('T',' ').replace(/Z$/,''):'—';
  const html=ports.map(name=>{
    const rx=latest.get(name+'|rx'),tx=latest.get(name+'|tx');
    const portRow=rows.find(p=>p.ifname===name)||{};
    const description=(rows.find(p=>p.ifname===name&&String(p.description||'').trim())||{}).description||'—';
    const portState=String(portRow.port_status||'unknown').toLowerCase();
    const portStatus=portState==='up'?'UP':portState==='down'?'DOWN':'UNKNOWN';
    const times=[rx&&rx.sampled_at,tx&&tx.sampled_at].filter(Boolean).sort();
    const lastSample=fmtTime(times[times.length-1]);
    let status;
    if(!rx||!tx)status='Partial reading';
    else{
      const states=[rx.sensor_status,tx.sensor_status]
        .filter(v=>v!==null&&v!==undefined&&v!=='').map(Number);
      if(!states.length)status='Unknown';
      else if(states.some(v=>v!==1))status='Sensor status check';
      else if(states.length<2)status='Partial status';
      else status='OK';
    }
    return '<tr><td>'+esc(name)+'</td><td>'+esc(description)+'</td>'+
      '<td>'+(rx?fmt(rx.dbm):'—')+'</td><td>'+(tx?fmt(tx.dbm):'—')+'</td>'+
      '<td>'+esc(lastSample)+'</td><td>'+esc(portStatus)+'</td><td>'+esc(status)+'</td></tr>';
  }).join('');
  empty.textContent='Latest saved RX/TX readings per port. Samples: '+rows.length;
  wrap.innerHTML='<div style="overflow-x:auto"><table><thead><tr>'+
    '<th>Interface</th><th>Description</th><th>RX (dBm)</th><th>TX (dBm)</th>'+
    '<th>Last sample (UTC)</th><th>Port Status</th><th>Sensor Status</th></tr></thead><tbody>'+
    html+'</tbody></table></div>';
}

function drawOpticalChart(points,selectedPort){
  const cv=$('optical-chart'), empty=$('optical-empty'), legend=$('optical-legend');
  if(!cv||!empty)return;
  const rows=(points||[]).filter(p=>
    (!selectedPort||p.ifname===selectedPort)&&
    (p.direction==='rx'||p.direction==='tx')&&
    p.dbm!==null&&p.dbm!==''&&Number.isFinite(Number(p.dbm))
  );
  if(!rows.length){
    cv.style.display='none';
    empty.textContent='No optical power samples for this selection yet.';
    if(legend)legend.textContent='';
    return;
  }

  const rx=rows.filter(p=>p.direction==='rx')
    .sort((a,b)=>String(a.sampled_at).localeCompare(String(b.sampled_at)));
  const tx=rows.filter(p=>p.direction==='tx')
    .sort((a,b)=>String(a.sampled_at).localeCompare(String(b.sampled_at)));
  if(!rx.length&&!tx.length){
    cv.style.display='none';empty.textContent='No RX/TX samples available.';return;
  }

  cv.style.display='block';empty.textContent='';
  const ctx=cv.getContext('2d'),W=cv.width,H=cv.height,pad=42;
  ctx.clearRect(0,0,W,H);
  const vals=rows.map(p=>Number(p.dbm));
  let min=Math.min(...vals),max=Math.max(...vals);
  if(min===max){min-=1;max+=1;}
  const margin=(max-min)*0.12;
  min-=margin;max+=margin;
  const allTimes=rows.map(p=>new Date(p.sampled_at).getTime()).filter(Number.isFinite);
  const t0=Math.min(...allTimes),t1=Math.max(...allTimes);
  const X=p=>allTimes.length&&t1>t0
    ?pad+(new Date(p.sampled_at).getTime()-t0)/(t1-t0)*(W-pad-12)
    :W/2;
  const Y=v=>H-pad-(v-min)/(max-min)*(H-pad-20);

  ctx.font='11px sans-serif';ctx.lineWidth=1;
  for(let k=0;k<=4;k++){
    const v=min+(max-min)*k/4,y=Y(v);
    ctx.strokeStyle='#2a3247';ctx.beginPath();
    ctx.moveTo(pad,y);ctx.lineTo(W-10,y);ctx.stroke();
    ctx.fillStyle='#8b95ab';ctx.fillText(v.toFixed(1),3,y+4);
  }
  for(const [series,label,color] of [[rx,'RX','#45b8ac'],[tx,'TX','#e5a454']]){
    if(!series.length)continue;
    ctx.beginPath();
    series.forEach((p,i)=>{
      const x=X(p),y=Y(Number(p.dbm));
      if(i===0)ctx.moveTo(x,y);else ctx.lineTo(x,y);
    });
    ctx.strokeStyle=color;ctx.lineWidth=2;ctx.stroke();
    for(const p of series){
      ctx.beginPath();ctx.arc(X(p),Y(Number(p.dbm)),2.5,0,Math.PI*2);
      ctx.fillStyle=color;ctx.fill();
    }
  }
  if(legend){
    legend.innerHTML='<span style="color:#45b8ac">● RX</span> &nbsp; '+
      '<span style="color:#e5a454">● TX</span> &nbsp; Samples: '+rows.length;
  }
}

// taby
document.querySelectorAll('.tabs button').forEach(b=>b.onclick=()=>showTab(b.dataset.tab));
function showTab(t){
  document.querySelectorAll('.tabs button').forEach(b=>b.classList.toggle('active',b.dataset.tab===t));
  document.querySelectorAll('.tab-pane').forEach(p=>p.classList.remove('active'));
  $('tab-'+t).classList.add('active');
}
$('dlg-close').onclick=()=>$('dlg-device').classList.add('hidden');
$('link-close').onclick=()=>$('dlg-link').classList.add('hidden');
$('dlg-link').addEventListener('click',e=>{if(e.target===$('dlg-link'))$('dlg-link').classList.add('hidden');});
$('link-if-load').onclick=async()=>{
  const dev=$('link-dev').value; if(!dev){$('link-msg').innerHTML='<span class="st-down">'+TR('Najprv vyber zariadenie.')+'</span>';return;}
  $('link-msg').textContent=TR('Načítavam rozhrania cez SNMP…');
  const r=await api.get('snmp_interfaces',{device_id:dev});
  if(r.error){$('link-msg').innerHTML='<span class="st-down">'+esc(r.error)+'</span>';return;}
  const cur=curLink.snmp_ifindex;
  $('link-if').innerHTML='<option value="">—</option>'+r.map(i=>`<option value="${i.idx}" ${cur==i.idx?'selected':''}>${esc(i.name)} — ${esc(i.description || 'Unassigned')} (ifIndex ${i.idx})</option>`).join('');
  $('link-msg').innerHTML='<span class="st-up">'+TR('@loaded_ifaces',r.length)+'</span>';
};
$('link-save').onclick=async()=>{
  await api.post('update_link',{link:curLink.id,type_id:$('link-type').value,
    snmp_device:$('link-dev').value,snmp_ifindex:$('link-if').value});
  $('dlg-link').classList.add('hidden');selectMap(S.mapId);};
$('link-del').onclick=async()=>{if(confirm(TR('Zmazať spoj?'))){await api.post('del_link',{link:curLink.id});$('dlg-link').classList.add('hidden');selectMap(S.mapId);}};
$('dlg-device').addEventListener('click',e=>{if(e.target===$('dlg-device'))$('dlg-device').classList.add('hidden');});

// ================= PRIDAŤ ZARIADENIE (modál) =================
async function openAddDialog(){
  const types=await api.get('device_types');
  $('add-type').innerHTML=types.map(t=>`<option value="${t.id}">${esc(t.name)}</option>`).join('');
  $('add-ip').value='';$('add-name').value='';$('add-pass').value='';$('add-msg').innerHTML='';
  $('dlg-add').classList.remove('hidden');
}
$('add-close').onclick=()=>$('dlg-add').classList.add('hidden');
$('dlg-add').addEventListener('click',e=>{if(e.target===$('dlg-add'))$('dlg-add').classList.add('hidden');});
$('add-save').onclick=async()=>{
  const name=$('add-name').value.trim()||$('add-ip').value.trim();
  if(!name){$('add-msg').innerHTML='<span class="st-down">'+TR('Zadaj adresu alebo názov.')+'</span>';return;}
  const c={x:(($('canvas').clientWidth/2)-S.view.x)/S.view.k,y:(($('canvas').clientHeight/2)-S.view.y)/S.view.k};
  const r=await api.post('new_device',{map:S.mapId,name,ip:$('add-ip').value.trim(),
    type_id:$('add-type').value,x:Math.round(c.x),y:Math.round(c.y)});
  if(r.ok){$('dlg-add').classList.add('hidden');await selectMap(S.mapId);
    if(r.device_id)openDeviceDialog(r.device_id);}
  else $('add-msg').innerHTML='<span class="st-down">'+esc(r.error||TR('Chyba'))+'</span>';
};

// prepojenia: dvojklik na uzol = okno zariadenia; tlačidlo Zariadenie = modál
$('btn-new-device').onclick=()=>{ if(!S.mapId){alert(TR('Najprv vyber mapu.'));return;} openAddDialog(); };
$('canvas').addEventListener('dblclick',e=>{
  let el=e.target;while(el&&el.tagName!=='g')el=el.parentNode;
  if(el&&el.dataset&&el.dataset.node){
    const n=S.data.nodes.find(x=>x.id==el.dataset.node);
    // Device window disabled on Maps Dashboard.
  }
});

// ================= MOBIL: zásuvka máp + dotykové ovládanie =================
(function(){
  const sb=document.getElementById('sidebar');
  const tg=document.getElementById('btn-maps-toggle');
  if(tg&&sb) tg.onclick=e=>{e.stopPropagation();sb.classList.toggle('open');};

  const cv=document.getElementById('canvas');
  const dist=(a,b)=>Math.hypot(a.clientX-b.clientX,a.clientY-b.clientY);
  let ts=null;

  cv.addEventListener('touchstart',e=>{
    if(sb) sb.classList.remove('open');
    if(e.touches.length===1){
      const t=e.touches[0];
      let g=document.elementFromPoint(t.clientX,t.clientY);
      while(g&&g.tagName!=='g') g=g.parentNode;
      if(g&&g.dataset&&g.dataset.node&&!S.mode){
        const n=S.data.nodes.find(x=>x.id==g.dataset.node);
        ts={type:'node',n,sx:t.clientX,sy:t.clientY,moved:false};
      }else{
        ts={type:'pan',x:t.clientX,y:t.clientY,vx:S.view.x,vy:S.view.y,moved:false};
      }
    }else if(e.touches.length===2){
      ts={type:'pinch',d0:dist(e.touches[0],e.touches[1]),k0:S.view.k,vx:S.view.x,vy:S.view.y,
          mx:(e.touches[0].clientX+e.touches[1].clientX)/2,my:(e.touches[0].clientY+e.touches[1].clientY)/2};
    }
  },{passive:false});

  cv.addEventListener('touchmove',e=>{
    if(!ts)return;
    if(ts.type==='pan'&&e.touches.length===1){
      const t=e.touches[0];e.preventDefault();
      S.view.x=ts.vx+(t.clientX-ts.x);S.view.y=ts.vy+(t.clientY-ts.y);
      if(Math.abs(t.clientX-ts.x)+Math.abs(t.clientY-ts.y)>4)ts.moved=true;
      render();
    }else if(ts.type==='node'&&e.touches.length===1){
      if(!canEdit())return;
      const t=e.touches[0];e.preventDefault();
      ts.n.x+=(t.clientX-ts.sx)/S.view.k;ts.n.y+=(t.clientY-ts.sy)/S.view.k;
      ts.sx=t.clientX;ts.sy=t.clientY;ts.moved=true;render();
    }else if(ts.type==='pinch'&&e.touches.length===2){
      e.preventDefault();
      const d=dist(e.touches[0],e.touches[1]);const nk=Math.max(.2,Math.min(4,ts.k0*(d/ts.d0)));
      const r=cv.getBoundingClientRect();const px=ts.mx-r.left,py=ts.my-r.top;
      S.view.x=px-(px-ts.vx)*(nk/ts.k0);S.view.y=py-(py-ts.vy)*(nk/ts.k0);S.view.k=nk;render();
    }
  },{passive:false});

  cv.addEventListener('touchend',e=>{
    if(!ts)return;
    if(ts.type==='node'){
      if(ts.moved){
        e.preventDefault();
        api.post('move',{node:ts.n.id,x:Math.round(ts.n.x),y:Math.round(ts.n.y)});
      }else{
        // ťuk na uzol
        const n=ts.n;e.preventDefault();
        if(S.mode==='delete'){ if(n.kind==='device'&&confirm(TR('Zmazať uzol?')+'\n\n'+TR('@del_node_hint'))){api.post('del_node',{node:n.id}).then(()=>selectMap(S.mapId));} }
        else if(S.mode==='link'&&n.kind==='device'){
          if(S.linkFrom===null){S.linkFrom=n.id;S.sel=n.id;render();document.getElementById('mode').textContent=TR('Spoj: ťukni druhý uzol');}
          else if(S.linkFrom!==n.id){api.post('add_link',{map:S.mapId,from:S.linkFrom,to:n.id}).then(()=>{S.linkFrom=null;selectMap(S.mapId);});}
        }
        else if(n.kind==='submap'){selectMap(n.submap_id);}
        else if(n.device_id){S.sel=n.id;render();openInspector(n.device_id);}
      }
    }
    ts=null;
  },{passive:false});

  // ťuk mimo zásuvky ju zavrie
  document.getElementById('main').addEventListener('click',()=>{ if(sb) sb.classList.remove('open'); });
})();
