(function(){
'use strict';
let role='manager', token='', guardName='', stream=null, employees=[], vehicles=[], capturedPhotos=[], recognized=[], deferredPrompt=null, ocrWorkerPromise=null;
const $=s=>document.querySelector(s); const $$=s=>Array.from(document.querySelectorAll(s));
const FACE_MODEL_URL='https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/weights';
const FACE_MATCH_MARGIN=0.06, FACE_THRESHOLD_MIN=0.35, FACE_THRESHOLD_MAX=0.60;
const OCR_PLATE_LETTERS='ABCEHIKMOPTX';
const OCR_CYRILLIC_MAP={А:'A',В:'B',Е:'E',І:'I',К:'K',М:'M',Н:'H',О:'O',Р:'P',С:'C',Т:'T',Х:'X'};
let faceReady=false;
function toast(m){let t=document.createElement('div');t.className='sa-toast';t.textContent=m;document.body.appendChild(t);setTimeout(()=>t.remove(),3200)}
function api(path,opts={}){opts.headers=Object.assign({'Content-Type':'application/json','X-StaffApp-Token':token},opts.headers||{});return fetch(STAFFAPP.api+path,opts).then(async r=>{let txt=await r.text();try{return JSON.parse(txt)}catch(e){throw new Error('API не повернув JSON: '+txt.slice(0,180))}})}
function debug(m){let d=$('#sa-debug'); if(d){d.textContent += new Date().toLocaleTimeString()+' '+m+'\n'} console.log('[StaffApp]',m)}
async function loadFace(){try{if(!window.faceapi)throw new Error('face-api не завантажився');await Promise.all([faceapi.nets.tinyFaceDetector.loadFromUri(FACE_MODEL_URL),faceapi.nets.faceLandmark68Net.loadFromUri(FACE_MODEL_URL),faceapi.nets.faceRecognitionNet.loadFromUri(FACE_MODEL_URL)]);faceReady=true;debug('FaceAPI OK')}catch(e){faceReady=false;debug('FaceAPI ERROR: '+e.message);toast('FaceAPI не завантажився: '+e.message)}}
function render(){
if(!STAFFAPP.licenseActive){document.getElementById('staffapp-root').innerHTML=`<div class="sa-screen active"><div class="sa-login"><div class="staffapp-logo">StaffApp КПП</div><div class="sa-sub">${STAFFAPP.company}</div><div class="sa-card" style="border-color:#ef4444"><b>Плагін не активований</b><br><br>${STAFFAPP.licenseStatus||'Потрібна активація ліцензії'}<br><br>Відкрий WordPress → StaffApp → Активація Payhip / домен, введи License key і натисни “Активувати ліцензію”.</div></div></div>`; bind(); return;}
document.getElementById('staffapp-root').innerHTML=`
<div id="login" class="sa-screen active"><div class="sa-login"><div class="staffapp-logo">StaffApp КПП</div><div class="sa-sub">${STAFFAPP.company}</div><div class="sa-tabs"><button class="sa-tab active" data-role="manager">Керівник</button><button class="sa-tab" data-role="guard">Охоронець</button></div><input id="loginPwd" type="password" class="sa-input" placeholder="Пароль"><button id="loginBtn" class="sa-btn green" style="width:100%">Увійти</button></div></div>
<div id="manager" class="sa-screen"><div class="sa-top"><div><div class="sa-title">StaffApp КПП</div><div class="sa-sub">Керівник</div></div><button class="sa-btn gray" data-logout>Вийти</button></div><div class="sa-nav"><button class="sa-btn" data-tab="m-add">➕ Працівник</button><button class="sa-btn" data-tab="m-emps">👥 Працівники</button><button class="sa-btn" data-tab="m-transport">🚛 Транспорт</button><button class="sa-btn" data-tab="m-journal">📋 Журнал</button><button class="sa-btn" data-tab="m-guards">🔐 Охоронці</button><button class="sa-btn orange" id="pwaBtnMgr">Ярлик</button></div>
<div id="m-add" class="sa-panel active"><div class="sa-card"><div class="sa-card-title">Новий працівник</div><input id="empPib" class="sa-input" placeholder="ПІБ"><input id="empPos" class="sa-input" placeholder="Посада"><input id="empDate" class="sa-input" type="date"><div class="sa-camera"><video id="addVideo" autoplay muted playsinline></video></div><canvas id="addCanvas" style="display:none"></canvas><div id="photoStrip" class="sa-photos"></div><div class="sa-row"><button class="sa-btn" id="captureBtn">📷 Додати фото</button><button class="sa-btn gray" id="clearPhotos">Очистити</button><button class="sa-btn green" id="saveEmp">Зберегти працівника</button></div></div></div>
<div id="m-emps" class="sa-panel"><div class="sa-row" style="margin-bottom:12px"><button class="sa-btn" id="refreshEmps">Оновити</button><button class="sa-btn green" id="exportEmps">Експорт працівників</button></div><div id="empList" class="sa-list"></div></div>
<div id="m-transport" class="sa-panel"><div class="sa-card"><div class="sa-card-title">Транспорт / вагова</div>${vehicleForm('m')}</div><div class="sa-row"><button class="sa-btn" id="mSearchVehicle">Знайти</button><button class="sa-btn green" id="mSaveVehicle">Зберегти авто</button><button class="sa-btn orange" id="mOcrVehicle">OCR номера</button><button class="sa-btn green" id="mVehicleIn">Заїзд</button><button class="sa-btn red" id="mVehicleOut">Виїзд</button></div><div class="sa-card"><div class="sa-card-title">Камера номера</div><div class="sa-camera"><video id="mVehicleVideo" autoplay muted playsinline></video></div><canvas id="mVehicleCanvas" style="display:none"></canvas></div></div>
<div id="m-journal" class="sa-panel"><input id="journalDate" type="date" class="sa-input"><div class="sa-row"><button class="sa-btn" id="loadJournal">Журнал працівників</button><button class="sa-btn" id="loadVJournal">Журнал авто</button><button class="sa-btn green" id="exportAtt">Експорт прихід/вихід</button><button class="sa-btn green" id="exportVLog">Експорт авто</button></div><div id="journalBox"></div></div>
<div id="m-guards" class="sa-panel"><div class="sa-card"><div class="sa-card-title">Додати охоронця</div><input id="guardNewName" class="sa-input" placeholder="ПІБ охоронця"><input id="guardNewPwd" class="sa-input" type="password" placeholder="Пароль"><button class="sa-btn green" id="addGuard">Додати</button></div><div id="guardsList"></div></div></div>
<div id="guard" class="sa-screen"><div class="sa-top"><div><div class="sa-title">StaffApp КПП</div><div id="guardTop" class="sa-sub">Охоронець</div></div><button class="sa-btn gray" data-logout>Вийти</button></div><div class="sa-nav"><button class="sa-btn" data-tab="g-face">👤 Працівники</button><button class="sa-btn" data-tab="g-transport">🚛 Транспорт</button><button class="sa-btn" data-tab="g-today">📋 Сьогодні</button></div>
<div id="g-face" class="sa-panel active"><div class="sa-card"><div class="sa-card-title">Розпізнавання облич</div><div class="sa-camera"><video id="guardVideo" autoplay muted playsinline></video></div><canvas id="guardCanvas" style="display:none"></canvas><button class="sa-btn" id="scanFace" style="width:100%;margin-top:12px">🔍 Розпізнати</button><div id="faceResult" class="sa-result"></div><div id="faceActions" class="sa-row" style="display:none"><button class="sa-btn green" id="faceIn">Прийшов</button><button class="sa-btn red" id="faceOut">Пішов</button></div></div></div>
<div id="g-transport" class="sa-panel"><div class="sa-card"><div class="sa-card-title">Транспорт</div>${vehicleForm('g')}</div><div class="sa-row"><button class="sa-btn" id="gSearchVehicle">Знайти</button><button class="sa-btn green" id="gSaveVehicle">Зберегти авто</button><button class="sa-btn orange" id="gOcrVehicle">OCR номера</button><button class="sa-btn green" id="gVehicleIn">Заїзд</button><button class="sa-btn red" id="gVehicleOut">Виїзд</button></div><div class="sa-card"><div class="sa-card-title">Камера номера</div><div class="sa-camera"><video id="gVehicleVideo" autoplay muted playsinline></video></div><canvas id="gVehicleCanvas" style="display:none"></canvas></div></div>
<div id="g-today" class="sa-panel"><button class="sa-btn" id="guardToday">Оновити сьогодні</button><div id="guardTodayBox"></div></div></div>
<div id="sa-modal" class="sa-modal"><div class="sa-modal-box"><button class="sa-close" id="modalClose">×</button><div id="modalBody"></div></div></div>`;
bind();}
function vehicleForm(p){return `<label class="sa-field-label" for="${p}Plate">Номер авто</label><input id="${p}Plate" class="sa-input sa-mask-input" inputmode="text" autocomplete="off" maxlength="10" placeholder="АА 4444 ББ" aria-describedby="${p}PlateError"><div id="${p}PlateError" class="sa-field-error">Введіть 2 літери, 4 цифри і 2 літери: АА 4444 ББ</div><label class="sa-field-label" for="${p}Trailer">Номер причепа</label><input id="${p}Trailer" class="sa-input sa-mask-input" inputmode="text" autocomplete="off" maxlength="10" placeholder="АА 4444 ББ" aria-describedby="${p}TrailerError"><div id="${p}TrailerError" class="sa-field-error">Формат причепа: АА 4444 ББ</div><input id="${p}Brand" class="sa-input" placeholder="Марка авто"><input id="${p}Company" class="sa-input" placeholder="Фірма"><input id="${p}Driver" class="sa-input" placeholder="Водій"><label class="sa-field-label" for="${p}Phone">Телефон водія</label><div class="sa-row sa-phone-row"><input id="${p}Phone" class="sa-input sa-mask-input" type="tel" inputmode="numeric" autocomplete="tel" maxlength="17" style="flex:1" placeholder="+380 00 000 00 00" aria-describedby="${p}PhoneError"><a id="${p}PhoneCall" class="sa-btn green sa-phone-call is-disabled" href="#" aria-disabled="true">📞 Подзвонити</a></div><div id="${p}PhoneError" class="sa-field-error">Український номер: +380 00 000 00 00</div><input id="${p}Cargo" class="sa-input" list="${p}CargoList" autocomplete="off" placeholder="Вантаж"><datalist id="${p}CargoList"></datalist><div class="sa-row"><input id="${p}Gross" class="sa-input" type="number" inputmode="decimal" step="0.01" min="0" style="flex:1" placeholder="Брутто"><input id="${p}Tare" class="sa-input" type="number" inputmode="decimal" step="0.01" min="0" style="flex:1" placeholder="Тара"><input id="${p}Net" class="sa-input" type="number" inputmode="decimal" step="0.01" min="0" readonly style="flex:1" placeholder="Нетто (авто)"></div><div class="sa-small">Нетто рахується автоматично: Брутто − Тара. Можна спочатку ввести Тару, а після завантаження Брутто; або навпаки.</div><textarea id="${p}Notes" class="sa-textarea" placeholder="Нотатки"></textarea>`}
function bind(){
 $$('.sa-tab').forEach(b=>b.onclick=()=>{role=b.dataset.role;$$('.sa-tab').forEach(x=>x.classList.remove('active'));b.classList.add('active')}); $('#loginBtn').onclick=login; $('#loginPwd').onkeydown=e=>{if(e.key==='Enter')login()};
 $$('[data-logout]').forEach(b=>b.onclick=logout); $$('[data-tab]').forEach(b=>b.onclick=()=>showPanel(b.dataset.tab)); ['pwaBtnMgr'].forEach(id=>{let b=$('#'+id); if(b)b.onclick=installPwa});
 $('#empDate') && ($('#empDate').value=new Date().toISOString().slice(0,10)); $('#journalDate') && ($('#journalDate').value=new Date().toISOString().slice(0,10));
 $('#captureBtn') && ($('#captureBtn').onclick=capturePhoto); $('#clearPhotos') && ($('#clearPhotos').onclick=()=>{capturedPhotos=[];renderPhotoStrip()}); $('#saveEmp') && ($('#saveEmp').onclick=saveEmployee); $('#refreshEmps') && ($('#refreshEmps').onclick=loadEmployees); $('#exportEmps') && ($('#exportEmps').onclick=()=>location.href=STAFFAPP.api+'/export/employees?token='+encodeURIComponent(token));
 $('#scanFace') && ($('#scanFace').onclick=scanFace); $('#faceIn') && ($('#faceIn').onclick=()=>markFaces('in')); $('#faceOut') && ($('#faceOut').onclick=()=>markFaces('out'));
 $('#loadJournal') && ($('#loadJournal').onclick=loadJournal); $('#loadVJournal') && ($('#loadVJournal').onclick=loadVehicleJournal); $('#exportAtt') && ($('#exportAtt').onclick=()=>location.href=STAFFAPP.api+'/export/attendance?date='+$('#journalDate').value+'&token='+encodeURIComponent(token)); $('#exportVLog') && ($('#exportVLog').onclick=()=>location.href=STAFFAPP.api+'/export/vehicle_logs?date='+$('#journalDate').value+'&token='+encodeURIComponent(token));
 $('#addGuard') && ($('#addGuard').onclick=addGuard); $('#guardToday') && ($('#guardToday').onclick=guardToday);
 ['m','g'].forEach(p=>{let s=$('#'+p+'SearchVehicle'), sv=$('#'+p+'SaveVehicle'), o=$('#'+p+'OcrVehicle'), vi=$('#'+p+'VehicleIn'), vo=$('#'+p+'VehicleOut'); if(s)s.onclick=()=>searchVehicle(p,true); if(sv)sv.onclick=()=>saveVehicle(p); if(o)o.onclick=()=>ocrVehicle(p); if(vi)vi.onclick=()=>logVehicle(p,'in'); if(vo)vo.onclick=()=>logVehicle(p,'out'); bindVehicleForm(p)});
 $('#modalClose') && ($('#modalClose').onclick=()=>$('#sa-modal').classList.remove('active'));
}
async function login(){let pwd=$('#loginPwd').value;let d=await fetch(STAFFAPP.api+'/login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({role,password:pwd})}).then(r=>r.json()); if(!d.success){toast('Невірний пароль');return} token=d.token; guardName=d.name; $('#login').classList.remove('active'); if(d.role==='manager'){ $('#manager').classList.add('active'); await loadEmployees(); await loadGuards(); await ensureVehiclesLoaded(); await startCamera('addVideo'); await startCamera('mVehicleVideo'); } else {$('#guard').classList.add('active'); $('#guardTop').textContent=d.name; await loadEmployees(); await ensureVehiclesLoaded(); await startCamera('guardVideo'); await startCamera('gVehicleVideo'); await guardToday();}}
function logout(){stopCamera(); token=''; recognized=[]; render(); loadFace();}
function showPanel(id){$$('.sa-panel').forEach(p=>p.classList.remove('active'));let el=$('#'+id); if(el)el.classList.add('active'); if(id==='m-emps')loadEmployees(); if(id==='m-guards')loadGuards(); if(id==='g-today')guardToday();}
async function startCamera(id){let v=$('#'+id); if(!v)return; try{let s=await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:'environment'},width:{ideal:1920},height:{ideal:1080}},audio:false}); v.srcObject=s; stream=s;}catch(e){debug('camera '+id+': '+e.message);toast('Камера недоступна: '+e.message)}}
function stopCamera(){ $$('video').forEach(v=>{let s=v.srcObject;if(s)s.getTracks().forEach(t=>t.stop());v.srcObject=null}); stream=null; }
function captureFrom(videoId,canvasId,max=1600,quality=.9){let v=$('#'+videoId), c=$('#'+canvasId); if(!v||!v.videoWidth){toast('Камера ще не готова');return null} let w=v.videoWidth, h=v.videoHeight; if(w>max){h=Math.round(h*max/w);w=max} c.width=w;c.height=h;c.getContext('2d').drawImage(v,0,0,w,h);return c.toDataURL('image/jpeg',quality)}
function capturePhoto(){if(capturedPhotos.length>=5){toast('Максимум 5 фото');return}let img=captureFrom('addVideo','addCanvas');if(img){capturedPhotos.push(img);renderPhotoStrip();toast('Фото додано')}}
function renderPhotoStrip(){let h=capturedPhotos.map(p=>`<img class="sa-photo" src="${p}">`).join(''); $('#photoStrip').innerHTML=h}
async function uploadPhoto(data){let r=await api('/upload_photo',{method:'POST',body:JSON.stringify({photo:data})});if(!r.success)throw new Error(r.error||'Фото не завантажено');return r.filename}
async function descriptorFrom(data){if(!faceReady)throw new Error('модель розпізнавання не готова');let img=await new Promise((res,rej)=>{let i=new Image();i.onload=()=>res(i);i.onerror=()=>rej(new Error('не вдалося відкрити фото'));i.src=data});let dets=await faceapi.detectAllFaces(img,new faceapi.TinyFaceDetectorOptions({inputSize:512,scoreThreshold:.55})).withFaceLandmarks().withFaceDescriptors();if(!dets.length)throw new Error('обличчя не знайдено');if(dets.length!==1)throw new Error('на фото має бути рівно одне обличчя');if(dets[0].detection.score<.65)throw new Error('обличчя розпізнано нечітко');if(Math.min(dets[0].detection.box.width,dets[0].detection.box.height)<80)throw new Error('обличчя замале — зроби фото ближче');return Array.from(dets[0].descriptor)}
async function saveEmployee(){
 let pib=$('#empPib').value.trim(), pos=$('#empPos').value.trim(), date=$('#empDate').value;
 let btn=$('#saveEmp');
 if(!pib||!pos){toast('ПІБ і посада обов’язкові');return}
 if(!capturedPhotos.length){toast('Додай хоча б одне фото');return}
 if(btn){btn.disabled=true;btn.textContent='⏳ Збереження...'}
 try{
   let files=[], desc=[], validPhotos=[], rejectedPhotos=[];
   for(let i=0;i<capturedPhotos.length;i++){
     try{validPhotos.push({photo:capturedPhotos[i],descriptor:await descriptorFrom(capturedPhotos[i])})}
     catch(e){rejectedPhotos.push('Фото '+(i+1)+': '+e.message)}
   }
   if(rejectedPhotos.length)debug('Пропущені фото: '+rejectedPhotos.join('; '));
   if(!validPhotos.length){
     toast('Жодне фото не пройшло перевірку. Потрібне чітке фото рівно одного обличчя.');
     return;
   }
   toast('Зберігаю '+validPhotos.length+' чітких фото'+(rejectedPhotos.length?'; пропущено '+rejectedPhotos.length:'')+'...');
   for(const item of validPhotos){files.push(await uploadPhoto(item.photo));desc.push(item.descriptor)}
   let r=await api('/employee',{method:'POST',body:JSON.stringify({pib:pib,posada:pos,date_in:date,main_photo:files[0],photos:files,descriptors:desc})});
   if(r && r.success){
     toast('✅ Працівника додано: '+pib);
     capturedPhotos=[]; renderPhotoStrip();
     $('#empPib').value=''; $('#empPos').value=''; $('#empDate').value=new Date().toISOString().slice(0,10);
     await loadEmployees();
     showPanel('m-emps');
   } else {
     toast('Помилка додавання: '+(r && r.error ? r.error : 'невідома'));
     debug('saveEmployee response: '+JSON.stringify(r));
   }
 } catch(e){
   toast('Помилка збереження: '+e.message);
   debug('saveEmployee error: '+e.stack);
 } finally {
   if(btn){btn.disabled=false;btn.textContent='Зберегти працівника'}
 }
}
async function loadEmployees(){employees=await api('/employees'); renderEmployees();}
function avatar(e){return e.main_photo||''}
function renderEmployees(){let el=$('#empList'); if(!el)return; if(!employees.length){el.innerHTML='<div class="sa-card">Немає працівників</div>';return} el.innerHTML=employees.map(e=>`<div class="sa-item"><img class="sa-avatar" src="${avatar(e)}"><div class="sa-grow"><div class="sa-name">${e.pib} <span class="sa-badge ${e.fired==1?'bad':'ok'}">${e.fired==1?'Звільнений':'Працює'}</span></div><div class="sa-small">${e.posada}</div></div><button class="sa-btn ${e.fired==1?'green':'red'}" onclick="StaffAppUI.fireRestore(${e.id},${e.fired==1})">${e.fired==1?'Відновити':'Звільнити'}</button><button class="sa-btn orange" onclick="StaffAppUI.delEmp(${e.id})">Видалити</button></div>`).join('')}
function faceThreshold(){let value=Number(STAFFAPP.threshold);return Number.isFinite(value)?Math.min(FACE_THRESHOLD_MAX,Math.max(FACE_THRESHOLD_MIN,value)):.48}
function matchFace(descriptor){
 let candidates=[];
 for(const employee of employees){
  if(!Array.isArray(employee.descriptors))continue;
  const distances=employee.descriptors.filter(d=>Array.isArray(d)&&d.length===128&&d.every(Number.isFinite)).map(d=>faceapi.euclideanDistance(descriptor,new Float32Array(d))).filter(Number.isFinite).sort((a,b)=>a-b);
  if(distances.length)candidates.push({employee,distance:distances.length>1?(distances[0]+distances[1])/2:distances[0]});
 }
 candidates.sort((a,b)=>a.distance-b.distance);
 const best=candidates[0];
 if(!best||best.distance>=faceThreshold())return{pib:'Невідомий',unknown:true};
 if(candidates[1]&&candidates[1].distance-best.distance<FACE_MATCH_MARGIN)return{pib:'Неоднозначний збіг',unknown:true,ambiguous:true};
 return Object.assign({},best.employee,{distance:best.distance});
}
async function scanFace(){
 if(!faceReady){toast('FaceAPI ще не готовий');return}
 let btn=$('#scanFace');if(btn)btn.disabled=true;
 try{
  if(!employees.length)await loadEmployees();
  const img=captureFrom('guardVideo','guardCanvas');if(!img)return;
  const dets=await faceapi.detectAllFaces($('#guardCanvas'),new faceapi.TinyFaceDetectorOptions({inputSize:512,scoreThreshold:.55})).withFaceLandmarks().withFaceDescriptors();
  if(!dets.length){toast('Обличчя не знайдено — забезпечте краще освітлення і повторіть скан');return}
  recognized=dets.map(det=>matchFace(det.descriptor));renderFaceResult();
 }catch(e){debug('scanFace: '+e.message);toast('Помилка розпізнавання: '+e.message)}
 finally{if(btn)btn.disabled=false}
}
function renderFaceResult(){let box=$('#faceResult'); box.className='sa-result show '+(recognized.some(x=>x.unknown)?'bad':'ok'); box.innerHTML=recognized.map((e,i)=>`<label class="sa-item"><input type="checkbox" data-face="${i}" ${e.unknown||e.fired==1?'disabled':'checked'}><div class="sa-grow"><div><b>${e.pib}</b></div><div class="sa-small">${e.ambiguous?'Недостатньо впевнений збіг — повторіть скан':e.unknown?'Обличчя не знайдено в базі':e.posada+' • '+(e.fired==1?'Звільнений':'dist '+(e.distance||0).toFixed(3))}</div></div></label>`).join(''); $('#faceActions').style.display=recognized.some(x=>!x.unknown&&x.fired!=1)?'flex':'none';}
async function markFaces(type){let boxes=$$('[data-face]:checked'); for(let b of boxes){let e=recognized[+b.dataset.face]; await api('/attendance',{method:'POST',body:JSON.stringify({emp_id:e.id,emp_name:e.pib,type})})} toast(type==='in'?'Прихід зафіксовано':'Вихід зафіксовано'); await guardToday();}
const UA_PLATE_LETTERS='АВЕІКМНОРСТХ';
const LATIN_PLATE_MAP={A:'А',B:'В',C:'С',E:'Е',H:'Н',I:'І',K:'К',M:'М',O:'О',P:'Р',T:'Т',X:'Х'};
function toUaPlateLetter(ch){ch=String(ch||'').toUpperCase();return LATIN_PLATE_MAP[ch]||ch}
function plateChars(value){
 let out='';
 for(const original of String(value||'').toUpperCase()){
  const ch=toUaPlateLetter(original);
  const pos=out.length;
  const expectsLetter=pos<2||pos>=6;
  if(expectsLetter){if(UA_PLATE_LETTERS.includes(ch))out+=ch}
  else if(/\d/.test(ch))out+=ch;
  if(out.length>=8)break;
 }
 return out;
}
function normalizePlateRaw(value){return plateChars(value)}
function formatPlate(value){let raw=plateChars(value),a=raw.slice(0,2),n=raw.slice(2,6),b=raw.slice(6,8);return [a,n,b].filter(Boolean).join(' ')}
function isValidPlate(value){return /^[АВЕІКМНОРСТХ]{2} \d{4} [АВЕІКМНОРСТХ]{2}$/u.test(String(value||''))}
function setFieldState(input,error,valid,showError){
 if(!input)return valid;
 input.classList.toggle('sa-invalid',!!showError&&!valid);
 input.classList.toggle('sa-valid',valid);
 input.setAttribute('aria-invalid',(!valid&&showError)?'true':'false');
 if(error)error.classList.toggle('show',!!showError&&!valid);
 return valid;
}
function validatePlateField(p,kind='Plate',required=true,force=false){
 const input=$('#'+p+kind),error=$('#'+p+kind+'Error');
 if(!input)return false;
 const empty=!normalizePlateRaw(input.value);
 const valid=empty?!required:isValidPlate(input.value);
 return setFieldState(input,error,valid,force||(!empty&&input.value.length>=1));
}
function nationalPhoneDigits(value){
 let digits=String(value||'').replace(/\D/g,'');
 if(digits.startsWith('380'))digits=digits.slice(3);
 else if(digits.startsWith('0'))digits=digits.slice(1);
 return digits.slice(0,9);
}
function formatPhone(value){
 const digits=nationalPhoneDigits(value);
 if(!digits)return String(value||'').trim()?'+380':'';
 let out='+380';
 out+=' '+digits.slice(0,2);
 if(digits.length>2)out+=' '+digits.slice(2,5);
 if(digits.length>5)out+=' '+digits.slice(5,7);
 if(digits.length>7)out+=' '+digits.slice(7,9);
 return out;
}
function isValidPhone(value){return /^\+380 \d{2} \d{3} \d{2} \d{2}$/.test(String(value||''))}
function phoneHref(value){return isValidPhone(value)?('tel:+'+String(value).replace(/\D/g,'')):'#'}
function validatePhoneField(p,required=false,force=false){
 const input=$('#'+p+'Phone'),error=$('#'+p+'PhoneError');
 if(!input)return false;
 const empty=nationalPhoneDigits(input.value).length===0;
 const valid=empty?!required:isValidPhone(input.value);
 setFieldState(input,error,valid,force||(!empty&&input.value.length>=1));
 updatePhoneLink(p);
 return valid;
}
function calculateNet(p){let g=parseFloat($('#'+p+'Gross').value||0),t=parseFloat($('#'+p+'Tare').value||0),n=(g>0&&t>0)?Math.max(0,g-t):0;$('#'+p+'Net').value=n?n.toFixed(2):'';return n}
function updatePhoneLink(p){
 let input=$('#'+p+'Phone'),link=$('#'+p+'PhoneCall');if(!input||!link)return;
 const valid=isValidPhone(input.value);
 link.href=valid?phoneHref(input.value):'#';
 link.classList.toggle('is-disabled',!valid);
 link.setAttribute('aria-disabled',valid?'false':'true');
 link.title=valid?'Подзвонити '+input.value:'Спочатку введіть повний український номер';
}
let vehicleLookupTimers={};
function bindVehicleForm(p){
 let plate=$('#'+p+'Plate'),trailer=$('#'+p+'Trailer'),phone=$('#'+p+'Phone'),gross=$('#'+p+'Gross'),tare=$('#'+p+'Tare'),cargo=$('#'+p+'Cargo');
 if(plate){
  plate.addEventListener('input',()=>{plate.value=formatPlate(plate.value);validatePlateField(p,'Plate',true,false);clearTimeout(vehicleLookupTimers[p]);if(isValidPlate(plate.value))vehicleLookupTimers[p]=setTimeout(()=>searchVehicle(p,false),450)});
  plate.addEventListener('blur',()=>validatePlateField(p,'Plate',true,true));
 }
 if(trailer){
  trailer.addEventListener('input',()=>{trailer.value=formatPlate(trailer.value);validatePlateField(p,'Trailer',false,false)});
  trailer.addEventListener('blur',()=>validatePlateField(p,'Trailer',false,true));
 }
 if(phone){
  phone.addEventListener('focus',()=>{if(!nationalPhoneDigits(phone.value)){phone.value='+380 ';phone.setSelectionRange(phone.value.length,phone.value.length)}});
  phone.addEventListener('input',()=>{phone.value=formatPhone(phone.value);validatePhoneField(p,false,false)});
  phone.addEventListener('blur',()=>{if(!nationalPhoneDigits(phone.value))phone.value='';validatePhoneField(p,false,true)});
  phone.addEventListener('keydown',e=>{if((e.key==='Backspace'||e.key==='Delete')&&phone.selectionStart<=5&&phone.selectionEnd<=5)e.preventDefault()});
  let call=$('#'+p+'PhoneCall');if(call)call.addEventListener('click',e=>{if(!isValidPhone(phone.value)){e.preventDefault();validatePhoneField(p,false,true);toast('Введіть повний номер +380 00 000 00 00')}});
  updatePhoneLink(p);
 }
 if(gross)gross.oninput=()=>calculateNet(p);if(tare)tare.oninput=()=>calculateNet(p);if(cargo)cargo.oninput=()=>renderCargoSuggestions(p,cargo.value)
}
function renderCargoSuggestions(p,query=''){let list=$('#'+p+'CargoList');if(!list)return;let q=String(query||'').trim().toLowerCase();let cargos=[...new Set((vehicles||[]).map(v=>String(v.cargo||'').trim()).filter(Boolean))].filter(x=>!q||x.toLowerCase().includes(q)).slice(0,30);list.innerHTML=cargos.map(x=>`<option value="${x.replace(/"/g,'&quot;')}"></option>`).join('')}
function vehicleData(p){let gross=parseFloat($('#'+p+'Gross').value||0),tare=parseFloat($('#'+p+'Tare').value||0),net=calculateNet(p),phoneDigits=nationalPhoneDigits($('#'+p+'Phone').value);return{number_plate:formatPlate($('#'+p+'Plate').value),trailer_plate:formatPlate($('#'+p+'Trailer').value),brand:$('#'+p+'Brand').value,company:$('#'+p+'Company').value,driver:$('#'+p+'Driver').value,phone:phoneDigits.length?formatPhone($('#'+p+'Phone').value):'',cargo:$('#'+p+'Cargo').value,gross,tare,net,notes:$('#'+p+'Notes').value}}
function fillVehicle(p,v){$('#'+p+'Plate').value=formatPlate(v.number_plate||'');$('#'+p+'Trailer').value=formatPlate(v.trailer_plate||'');$('#'+p+'Brand').value=v.brand||'';$('#'+p+'Company').value=v.company||'';$('#'+p+'Driver').value=v.driver||'';$('#'+p+'Phone').value=formatPhone(v.phone||'');$('#'+p+'Cargo').value=v.cargo||'';$('#'+p+'Notes').value=v.notes||'';let lw=v.last_log||{};$('#'+p+'Gross').value=lw.gross||'';$('#'+p+'Tare').value=lw.tare||'';calculateNet(p);validatePlateField(p,'Plate',true,false);validatePlateField(p,'Trailer',false,false);validatePhoneField(p,false,false);renderCargoSuggestions(p)}
async function ensureVehiclesLoaded(){if(!vehicles.length)vehicles=await api('/vehicles');['m','g'].forEach(p=>renderCargoSuggestions(p))}
async function searchVehicle(p,showNotFound=true){if(!validatePlateField(p,'Plate',true,showNotFound)){if(showNotFound)toast('Номер авто: АА 4444 ББ');return null}let q=normalizePlateRaw($('#'+p+'Plate').value);let r=await api('/vehicles?q='+encodeURIComponent(q));if(r.length){fillVehicle(p,r[0]);toast('Авто знайдено — дані підставлено, їх можна редагувати');return r[0]}if(showNotFound)toast('Авто не знайдено — заповніть нову картку');return null}
async function saveVehicle(p){if(!validatePlateField(p,'Plate',true,true)){toast('Номер авто має формат АА 4444 ББ');$('#'+p+'Plate').focus();return}if(!validatePlateField(p,'Trailer',false,true)){toast('Номер причепа має формат АА 4444 ББ');$('#'+p+'Trailer').focus();return}if(!validatePhoneField(p,false,true)){toast('Телефон має формат +380 00 000 00 00');$('#'+p+'Phone').focus();return}let d=vehicleData(p);let r=await api('/vehicle',{method:'POST',body:JSON.stringify(d)});if(r.success){toast('Авто збережено');vehicles=[];await ensureVehiclesLoaded()}else toast('Помилка авто: '+(r.error||'невідома'))}
async function logVehicle(p,event_type){if(!validatePlateField(p,'Plate',true,true)){toast('Номер авто має формат АА 4444 ББ');$('#'+p+'Plate').focus();return}if(!validatePlateField(p,'Trailer',false,true)){toast('Номер причепа має формат АА 4444 ББ');$('#'+p+'Trailer').focus();return}if(!validatePhoneField(p,false,true)){toast('Телефон має формат +380 00 000 00 00');$('#'+p+'Phone').focus();return}let d=vehicleData(p);d.event_type=event_type;let r=await api('/vehicle/log',{method:'POST',body:JSON.stringify(d)});if(r.success){$('#'+p+'Net').value=r.net?r.net.toFixed(2):'';toast(event_type==='in'?'Заїзд зафіксовано':'Виїзд зафіксовано')}else toast('Помилка журналу')}
function ocrPlateCandidates(text){
 const compact=Array.from(String(text||'').toUpperCase(),ch=>OCR_CYRILLIC_MAP[ch]||ch).join('').replace(/[^A-Z0-9]/g,'');
 const candidates=new Set();
 for(let i=0;i<=compact.length-8;i++){
  const chars=compact.slice(i,i+8).split('');
  for(const pos of [0,1,6,7])chars[pos]=({0:'O',1:'I',5:'S',8:'B'})[chars[pos]]||chars[pos];
  for(const pos of [2,3,4,5])chars[pos]=({O:'0',Q:'0',D:'0',I:'1',L:'1',Z:'2',S:'5',B:'8',G:'6',T:'7',A:'4'})[chars[pos]]||chars[pos];
  const plate=chars.join('');
  if(OCR_PLATE_LETTERS.includes(plate[0])&&OCR_PLATE_LETTERS.includes(plate[1])&&/^\d{4}$/.test(plate.slice(2,6))&&OCR_PLATE_LETTERS.includes(plate[6])&&OCR_PLATE_LETTERS.includes(plate[7]))candidates.add(plate);
 }
 return Array.from(candidates);
}
async function enhanceOcrImage(data){
 const img=await new Promise((resolve,reject)=>{let image=new Image();image.onload=()=>resolve(image);image.onerror=()=>reject(new Error('не вдалося підготувати кадр'));image.src=data});
 const scale=Math.min(1.5,2400/Math.max(img.width,img.height)),canvas=document.createElement('canvas');
 canvas.width=Math.round(img.width*scale);canvas.height=Math.round(img.height*scale);
 const ctx=canvas.getContext('2d',{willReadFrequently:true});ctx.drawImage(img,0,0,canvas.width,canvas.height);
 const pixels=ctx.getImageData(0,0,canvas.width,canvas.height),dataArray=pixels.data;
 for(let i=0;i<dataArray.length;i+=4){const gray=.299*dataArray[i]+.587*dataArray[i+1]+.114*dataArray[i+2];const contrast=Math.max(0,Math.min(255,(gray-128)*1.6+128));dataArray[i]=dataArray[i+1]=dataArray[i+2]=contrast}
 ctx.putImageData(pixels,0,0);return canvas.toDataURL('image/png');
}
async function getOcrWorker(){
 if(!window.Tesseract||typeof Tesseract.createWorker!=='function')throw new Error('OCR не завантажився');
 if(!ocrWorkerPromise)ocrWorkerPromise=(async()=>{const worker=await Tesseract.createWorker('eng',1);await worker.setParameters({tessedit_char_whitelist:'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789',tessedit_pageseg_mode:'11'});return worker})().catch(e=>{ocrWorkerPromise=null;throw e});
 return ocrWorkerPromise;
}
async function ocrVehicle(p){
 const btn=$('#'+p+'OcrVehicle');if(btn)btn.disabled=true;
 try{
  const img=captureFrom(p+'VehicleVideo',p+'VehicleCanvas',1600,.92);if(!img)return;
  toast('OCR читає номер — не рухайте камеру...');
  const worker=await getOcrWorker(),enhanced=await enhanceOcrImage(img),readings=[];
  for(const image of [img,enhanced]){const result=await worker.recognize(image);readings.push({plates:ocrPlateCandidates(result.data.text),confidence:Number(result.data.confidence)||0})}
  const first=readings[0].plates,second=readings[1].plates;
  let plate=first.length===1&&second.length===1&&first[0]===second[0]?first[0]:'';
  if(!plate){const confident=readings.filter(r=>r.plates.length===1&&r.confidence>=90);if(confident.length===1&&readings.every(r=>r===confident[0]||r.plates.length===0))plate=confident[0].plates[0]}
  if(!plate){toast('Не вдалося впевнено прочитати номер. Наведіть камеру рівно, покращте освітлення або введіть вручну.');return}
  $('#'+p+'Plate').value=formatPlate(plate);validatePlateField(p,'Plate',true,true);toast('Розпізнано: '+$('#'+p+'Plate').value);await searchVehicle(p,false);
 }catch(e){debug('ocrVehicle: '+e.message);toast('Помилка OCR: '+e.message)}
 finally{if(btn)btn.disabled=false}
}
async function loadJournal(){let date=$('#journalDate').value;let r=await api('/attendance?date='+date);$('#journalBox').innerHTML=`<table class="sa-table"><tr><th>ПІБ</th><th>Час</th><th>Подія</th><th>Охоронець</th></tr>${r.map(a=>`<tr><td>${a.emp_name}</td><td>${a.time}</td><td>${a.type==='in'?'Прийшов':'Пішов'}</td><td>${a.guard_name||''}</td></tr>`).join('')}</table>`}
async function loadVehicleJournal(){let date=$('#journalDate').value;let r=await api('/vehicle/logs?date='+date);$('#journalBox').innerHTML=`<table class="sa-table"><tr><th>Номер</th><th>Фірма</th><th>Брутто</th><th>Тара</th><th>Нетто</th><th>Подія</th><th>Час</th></tr>${r.map(v=>`<tr><td>${v.number_plate}</td><td>${v.company||''}</td><td>${v.gross||''}</td><td>${v.tare||''}</td><td>${v.net||''}</td><td>${v.event_type==='in'?'Заїзд':'Виїзд'}</td><td>${v.time}</td></tr>`).join('')}</table>`}
async function guardToday(){let date=new Date().toISOString().slice(0,10);let [a,v]=await Promise.all([api('/attendance?date='+date),api('/vehicle/logs?date='+date)]);let box=$('#guardTodayBox'); if(box)box.innerHTML=`<div class="sa-card"><b>Працівники сьогодні:</b> ${a.length}</div><div class="sa-card"><b>Транспорт сьогодні:</b> ${v.length}</div>`}
async function loadGuards(){let r=await api('/guards');let el=$('#guardsList');if(!el)return;el.innerHTML=`<table class="sa-table"><tr><th>ПІБ</th><th>Дії</th></tr>${r.map(g=>`<tr><td>${g.name}</td><td><input id="gp${g.id}" class="sa-input" type="password" placeholder="Новий пароль"><button class="sa-btn" onclick="StaffAppUI.upGuard(${g.id})">Змінити</button><button class="sa-btn red" onclick="StaffAppUI.delGuard(${g.id})">Видалити</button></td></tr>`).join('')}</table>`}
async function addGuard(){let name=$('#guardNewName').value,pwd=$('#guardNewPwd').value;if(!name||!pwd){toast('Заповни ПІБ і пароль');return}await api('/guard',{method:'POST',body:JSON.stringify({name,password:pwd})});toast('Охоронця додано');loadGuards()}
async function installPwa(){if(deferredPrompt){deferredPrompt.prompt();await deferredPrompt.userChoice;deferredPrompt=null}else toast('Якщо кнопка не спрацювала: Chrome → меню → Додати на головний екран / Встановити додаток')}
window.addEventListener('beforeinstallprompt',e=>{e.preventDefault();deferredPrompt=e});
window.StaffAppUI={async fireRestore(id,restore){await api(restore?'/restore':'/fire',{method:'POST',body:JSON.stringify({id})});toast(restore?'Відновлено':'Звільнено');loadEmployees()},async delEmp(id){if(confirm('Видалити працівника?')){await api('/delete',{method:'POST',body:JSON.stringify({id})});loadEmployees()}},async upGuard(id){let p=$('#gp'+id).value; if(p){await api('/guard/update',{method:'POST',body:JSON.stringify({id,password:p})});toast('Пароль змінено')}},async delGuard(id){if(confirm('Видалити охоронця?')){await api('/guard/delete',{method:'POST',body:JSON.stringify({id})});loadGuards()}}};
render(); loadFace(); if('serviceWorker' in navigator)navigator.serviceWorker.register(STAFFAPP.pluginUrl+'pwa/service-worker.js').catch(()=>{});
})();
