/* PKD V7 progressive enhancement, using original rpc(), S, and view navigation. */
(function () {
  'use strict';
  const E = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  let enabled=false, role='admin', section='events', eventName='', events=[], selectedSid='', lastImport=null, pollBusy=false;
  const $v = (id) => document.getElementById(id);
  const h = (text) => `<span class="v7-muted">${E(text)}</span>`;
  const allowed = (roles) => roles.includes(role);
  const admin = () => role==='admin';
  const request = async (action,payload={}) => {
    const res=await rpc(action,payload);
    if (res.error) throw new Error(res.error);
    return res;
  };
  function err(ex) { toast(ex && ex.message ? ex.message : String(ex)); }
  const actionButton = (id,label,kind='gh') => `<button class="${kind}" id="${id}">${E(label)}</button>`;
  function show(text) { const box=$v('v7Content');if(box)box.innerHTML=text; }
  function safeURL(s){try{const u=new URL(s);return u.protocol==='https:'?u.href:'#';}catch{return '#';}}
  async function refresh() {
    if(cloudBusy || cloudPending) throw new Error('Tunggu penyimpanan yang sedang berjalan.');
    const result=await request('load'); S=norm(result);cloudRevision=result.revision;cloudError=false;cloudReady=true;
    role=result.role||role;window.pk7Role=role;window.pk7Mode=true;
    render();applyRole();
    return result;
  }
  function mount() {
    if(!enabled)return;
    if(!$v('v7Launcher')){
      const card=document.createElement('div');card.className='card';card.innerHTML=`<h3>Manajemen PKD V7</h3><p class="mut">Multi-kegiatan, PAC, nilai per sesi, materi, hak akses, impor V6, dan audit.</p>${actionButton('v7Launcher','Buka manajemen V7','btn w')}`;
      $v('vMore').appendChild(card);
      $v('v7Launcher').onclick=()=>open('events');
    }
    if(!$v('v7EventStrip')){
      const strip=document.createElement('div');strip.id='v7EventStrip';strip.className='v7bar';
      $v('vHome').prepend(strip);
    }
    applyRole();paintStrip();
  }
  function applyRole() {
    if (!enabled)return;
    const canEdit=admin();
    for (const id of ['fab','mRun','mClr','mCards','mBak','mCert','mCert','mVer']) {
      const b=$v(id);if(b)b.hidden=!canEdit;
    }
    const rest=$v('rest');if(rest&&rest.closest('label')) rest.closest('label').style.display='none';
    const ev=$v('evName');if(ev)ev.disabled=!canEdit;
    if (!canEdit && $v('hRun')) $v('hRun').style.display='none';
    if (!canEdit && $v('hS')) $v('hS').disabled=true;
    const editButtons=['#vRun [data-a]','#vRun [data-e]','#vRun [data-x]'];
    if(!canEdit) for(const sel of editButtons)document.querySelectorAll(sel).forEach(b=>b.disabled=true);
    const rec=$v('csv');if(rec)rec.disabled=false;
  }
  function paintStrip(){
    const strip=$v('v7EventStrip');if(!strip)return;
    strip.innerHTML=`<strong>Kegiatan</strong><select id="v7TopEvent" aria-label="Pilih kegiatan">${events.filter(x=>!x.archived).map(x=>`<option value="${E(x.id)}" ${x.id===S.event_id?'selected':''}>${E(x.name)}</option>`).join('')}</select>${actionButton('v7TopManage','Kelola V7','gh')}`;
    $v('v7TopManage').onclick=()=>open('events');
    $v('v7TopEvent').onchange=async e=>{try{await switchEvent(e.target.value);}catch(ex){err(ex);e.target.value=S.event_id;}};
  }
  async function switchEvent(id){await request('v7.events.switch',{id});await refresh();await eventList();toast('Kegiatan diganti');}
  async function eventList(){const data=await request('v7.events.list');events=data.events;role=data.role;window.pk7Role=role;paintStrip();return data;}
  function open(tab){section=tab;go('vV7');draw();}
  const tabs=[['events','Kegiatan'],['pac','PAC'],['scores','Nilai per sesi'],['materials','Materi'],['attendance','Kelola absensi'],['users','Pengguna'],['audit','Audit'],['import','Impor V6'],['diag','Diagnostik']];
  async function draw() {
    const root=$v('vV7');if(!root)return;
    root.innerHTML=`<h1>Manajemen PKD V7</h1><p class="sub">${E(S.ev || 'Pilih kegiatan')} · ${E(role==='admin'?'Administrator':role==='scanner'?'Petugas Absensi':'Penilai')}</p>
      <div class="v7-nav">${tabs.filter(([id])=>admin()||!['users','audit','import','attendance'].includes(id)).map(([id,t])=>`<button data-v7tab="${id}" class="${id===section?'on':''}">${E(t)}</button>`).join('')}</div><div class="v7-panel" id="v7Content">Memuat data...</div>`;
    root.querySelectorAll('[data-v7tab]').forEach(b=>b.onclick=()=>{section=b.dataset.v7tab;draw();});
    try {
      await ({events:drawEvents,pac:drawPac,scores:drawScores,materials:drawMaterials,attendance:drawAttendance,users:drawUsers,audit:drawAudit,import:drawImport,diag:drawDiag}[section]||drawEvents)();
    } catch(ex){show(`<div class="bn er">${E(ex.message)}</div>${actionButton('v7Retry','Coba lagi')}`);$v('v7Retry').onclick=draw;}
  }
  async function drawEvents(){
    await eventList();
    const items=events.map(x=>`<div class="v7-item"><div><b>${E(x.name)}</b><small>${E(x.id)} ${x.archived?'· Diarsipkan':''}</small></div><div class="v7-right">${!x.archived?`<button class="gh" data-sw="${E(x.id)}">Pilih</button>`:''}${admin()&&!x.archived?`<button class="gh" data-rename="${E(x.id)}">Ubah</button><button class="gh" data-archive="${E(x.id)}">Arsip</button>`:''}</div></div>`).join('');
    show(`<h2>Kegiatan</h2><p class="v7-muted">Setiap kegiatan menyimpan peserta, sesi, nilai, materi, sertifikat, dan kehadiran secara terpisah.</p><div class="v7-list">${items||h('Belum ada kegiatan')}</div>${admin()?`<hr><label>Nama kegiatan baru</label><div class="v7-field"><input id="v7NewEvent" placeholder="PKD Angkatan ..."><input id="v7IdPrefix" placeholder="Prefix ID (PKD)" value="PKD"><input id="v7IdSuffix" placeholder="Akhiran ID (NGK)" value="NGK">${actionButton('v7CreateEvent','Tambah kegiatan','btn')}</div>`:''}`);
    document.querySelectorAll('[data-sw]').forEach(b=>b.onclick=()=>switchEvent(b.dataset.sw).then(draw).catch(err));
    document.querySelectorAll('[data-rename]').forEach(b=>b.onclick=async()=>{const name=prompt('Nama kegiatan baru:');if(!name)return;try{await request('v7.events.rename',{id:b.dataset.rename,name});await eventList();await refresh();draw();}catch(ex){err(ex);}});
    document.querySelectorAll('[data-archive]').forEach(b=>b.onclick=async()=>{if(!confirm('Arsipkan kegiatan? Data tidak dihapus.'))return;try{await request('v7.events.archive',{id:b.dataset.archive});await eventList();await refresh();draw();}catch(ex){err(ex);}});
    if($v('v7CreateEvent'))$v('v7CreateEvent').onclick=async()=>{try{const name=$v('v7NewEvent').value.trim();if(!name)throw new Error('Isi nama kegiatan.');const result=await request('v7.events.create',{name,prefix:$v('v7IdPrefix').value,suffix:$v('v7IdSuffix').value});await switchEvent(result.id);draw();}catch(ex){err(ex);}};
  }
  function drawPac(){
    const grouped=new Map();for(const p of S.people){const key=(p.unit||'Tidak diketahui').trim()||'Tidak diketahui';if(!grouped.has(key))grouped.set(key,[]);grouped.get(key).push(p);}
    const keys=[...grouped.keys()].sort((a,b)=>a.localeCompare(b,'id'));
    show(`<h2>Peserta per PAC / Asal</h2><div class="v7-grid"><div class="v7-kpi"><small>Total peserta</small><strong>${S.people.length}</strong></div><div class="v7-kpi"><small>Kelompok PAC / Asal</small><strong>${keys.length}</strong></div></div>
      <div class="v7-field"><select id="v7PacFilter"><option value="">Semua PAC</option>${keys.map(k=>`<option value="${E(k)}">${E(k)} (${grouped.get(k).length})</option>`).join('')}</select><input id="v7PacSearch" placeholder="Cari nama atau ID"></div>
      <div id="v7PacRows" class="v7-list"></div>${admin()?`<hr><h3>Tambah peserta dengan ID PKD otomatis</h3><div class="v7-field"><input id="v7PersonName" placeholder="Nama peserta"><input id="v7PersonUnit" placeholder="Asal / PAC">${actionButton('v7PersonAdd','Tambah','btn')}</div><p class="v7-muted">ID dihasilkan di server secara atomik. ID lama tidak diubah.</p><h3>Migrasi alias ID peserta</h3><div class="v7-field"><select id="v7OldId">${S.people.map(p=>`<option value="${E(p.id)}">${E(p.id)} — ${E(p.name)}</option>`).join('')}</select><input id="v7NewId" placeholder="ID baru">${actionButton('v7RenameId','Ubah ID','gh')}</div>`:''}`);
    function filter(){const unit=$v('v7PacFilter').value;const q=$v('v7PacSearch').value.toLowerCase();
      const rows=S.people.filter(p=>(!unit||(p.unit||'Tidak diketahui').trim()===unit)&&`${p.name} ${p.id} ${p.unit}`.toLowerCase().includes(q));
      $v('v7PacRows').innerHTML=rows.map(p=>`<div class="v7-item"><span><b>${E(p.name)}</b><small>${E(p.id)} · ${E(p.unit||'Tidak diketahui')}</small></span></div>`).join('')||h('Tidak ada hasil');}
    $v('v7PacFilter').onchange=filter;$v('v7PacSearch').oninput=filter;filter();
    if($v('v7PersonAdd'))$v('v7PersonAdd').onclick=async()=>{try{const result=await request('v7.participants.add',{name:$v('v7PersonName').value,unit:$v('v7PersonUnit').value});await refresh();toast('Peserta dibuat: '+result.id);draw();}catch(ex){err(ex);}};
    if($v('v7RenameId'))$v('v7RenameId').onclick=async()=>{const old_id=$v('v7OldId').value,new_id=$v('v7NewId').value.trim();if(!confirm(`Ubah ID ${old_id} menjadi ${new_id}? QR lama tetap diterima sebagai alias.`))return;try{await request('v7.participants.rename_id',{old_id,new_id});await refresh();draw();}catch(ex){err(ex);}};
  }
  async function drawScores(){
    if(!allowed(['admin','assessor'])){show(h('Hanya Admin dan Penilai dapat mengisi nilai.'));return;}
    if(!S.sessions.length){show(h('Buat sesi kegiatan terlebih dahulu.'));return;}
    if(!selectedSid||!S.sessions.some(s=>s.id===selectedSid))selectedSid=S.active||S.sessions[0].id;
    const scores=S.sg?.[selectedSid]||{};
    show(`<h2>Nilai peserta per sesi (0–100)</h2><p class="v7-muted">Empat kriteria V6 digunakan sebagai panduan (Disiplin, Partisipasi, Sikap & Etika, Ideologi & Komitmen). Nilai tersimpan adalah satu angka per peserta per sesi. Jika diisi, nilai ini digunakan pada halaman belakang sertifikat.</p>
      <div class="v7-field"><select id="v7ScoreSid">${S.sessions.map(s=>`<option value="${E(s.id)}" ${s.id===selectedSid?'selected':''}>${E(s.name)}</option>`).join('')}</select><input id="v7ScoreSearch" placeholder="Cari peserta">${actionButton('v7ScoreExport','Unduh nilai CSV','gh')}</div>
      <div class="v7-list" id="v7ScoreList"></div><div class="v7-kpi"><small>Arti skala panduan</small><p class="v7-muted">4 Sangat Baik · 3 Baik · 2 Cukup · 1 Kurang. Nilai 0–100 disimpan tanpa mengarang skor per kriteria yang tidak ada di data lama.</p></div>`);
    const list=$v('v7ScoreList');function paint(){const q=$v('v7ScoreSearch').value.toLowerCase();list.innerHTML=S.people.filter(p=>`${p.name} ${p.unit} ${p.id}`.toLowerCase().includes(q)).map(p=>`<div class="v7-item"><div><b>${E(p.name)}</b><small>${E(p.id)} · ${E(p.unit)}</small></div><div class="v7-right"><input type="number" min="0" max="100" step="0.01" style="width:85px;min-height:38px" data-score="${E(p.id)}" value="${scores[p.id]??''}" aria-label="Nilai ${E(p.name)}"><button class="btn" data-save="${E(p.id)}">Simpan</button></div></div>`).join('')||h('Belum ada peserta');list.querySelectorAll('[data-save]').forEach(b=>b.onclick=async()=>{const input=list.querySelector(`[data-score="${CSS.escape(b.dataset.save)}"]`);const value=input.value.trim();try{await request('v7.scores.save',{sid:selectedSid,pid:b.dataset.save,score:value===''?null:Number(value)});await refresh();toast('Nilai disimpan ke VPS');}catch(ex){err(ex);}});}
    $v('v7ScoreSid').onchange=e=>{selectedSid=e.target.value;draw();};$v('v7ScoreSearch').oninput=paint;paint();
    $v('v7ScoreExport').onclick=()=>{const rows=[['ID','Nama','Asal','Sesi','Nilai'],...S.people.map(p=>[p.id,p.name,p.unit,S.sessions.find(s=>s.id===selectedSid)?.name||'',scores[p.id]??''])];saveFile('nilai-peserta-'+selectedSid+'.csv','\ufeff'+rows.map(r=>r.map(csv).join(',')).join('\r\n'));};
  }
  function csv(v){const s=String(v??'');const safe=/^[\s]*[=+@\-\t\r]/.test(s)?"'"+s:s;return '"'+safe.replace(/"/g,'""')+'"';}
  async function drawMaterials(){
    const {materials}=await request('v7.materials.list');const sOpt=S.sessions.map(s=>`<option value="${E(s.id)}">${E(s.name)}</option>`).join('');
    show(`<h2>Materi per sesi</h2><div class="v7-list">${materials.map(m=>`<div class="v7-item"><div><b>${E(m.title)}</b><small>${E(S.sessions.find(s=>s.id===m.sid)?.name||'Sesi')} · ${m.kind==='file'?'File VPS':'Tautan HTTPS'}</small></div><a class="gh" href="${m.kind==='file'?'material.php?id='+encodeURIComponent(m.id):E(safeURL(m.uri))}" ${m.kind==='link'?'target="_blank" rel="noopener noreferrer"':''}>${m.kind==='file'?'Unduh':'Buka'}</a></div>`).join('')||h('Belum ada materi.')}</div>
      ${allowed(['admin','assessor'])&&S.sessions.length?`<hr><h3>Tambah tautan materi</h3><div class="v7-field"><select id="v7LinkSid">${sOpt}</select><input id="v7LinkTitle" placeholder="Judul materi"><input id="v7LinkUrl" placeholder="https://drive.google.com/...">${actionButton('v7AddLink','Simpan tautan','btn')}</div><h3>Unggah berkas materi ke VPS</h3><div class="v7-field"><select id="v7UpSid">${sOpt}</select><input id="v7UpTitle" placeholder="Judul materi"><input id="v7UpFile" type="file" accept=".pdf,.jpg,.jpeg,.png,.docx,.xlsx">${actionButton('v7Upload','Unggah','btn')}</div><p class="v7-muted">PDF, JPG, PNG, DOCX, XLSX; maks. 8 MB. Berkas disimpan di luar public web root.</p>`:''}`);
    if($v('v7AddLink'))$v('v7AddLink').onclick=async()=>{try{await request('v7.materials.link',{sid:$v('v7LinkSid').value,title:$v('v7LinkTitle').value,url:$v('v7LinkUrl').value});toast('Tautan tersimpan');draw();}catch(ex){err(ex);}};
    if($v('v7Upload'))$v('v7Upload').onclick=async()=>{const f=$v('v7UpFile').files[0];if(!f){toast('Pilih file');return;}if(f.size>8*1024*1024){toast('File melebihi 8 MB');return;}const fd=new FormData();fd.append('action','v7.materials.upload');fd.append('sid',$v('v7UpSid').value);fd.append('title',$v('v7UpTitle').value);fd.append('file',f);try{const r=await fetch('api.php',{method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':csrf},body:fd});const data=await r.json();if(!r.ok)throw new Error(data.error||'Upload gagal');toast('Materi tersimpan di VPS');draw();}catch(ex){err(ex);}};
  }
  async function drawAttendance(){
    if(!admin()){show(h('Hanya Admin dapat mengubah kehadiran.'));return;}
    const sidOpt=S.sessions.map(s=>`<option value="${E(s.id)}">${E(s.name)}</option>`).join('');const pidOpt=S.people.map(p=>`<option value="${E(p.id)}">${E(p.name)} (${E(p.id)})</option>`).join('');
    show(`<h2>Batalkan absensi dengan audit log</h2><p class="v7-muted">Data peserta dan sesi tidak terhapus. Operasi dicatat lengkap dengan pelaku, waktu, serta alasan.</p>
      <div class="v7-field"><select id="v7DelScope"><option value="one">Satu peserta per sesi</option><option value="session">Semua kehadiran dalam satu sesi</option><option value="event">Semua kehadiran kegiatan ini</option></select><select id="v7DelSid">${sidOpt}</select><select id="v7DelPid">${pidOpt}</select></div>
      <label>Alasan pembatalan (minimal 8 karakter)</label><textarea id="v7DelReason" rows="2" placeholder="Contoh: koreksi scan panitia ..."></textarea>${actionButton('v7DoDelete','Batalkan kehadiran','btn red')}<p class="v7-muted">Untuk menghapus semua kehadiran kegiatan, sistem mewajibkan konfirmasi tambahan HAPUS.</p>`);
    $v('v7DelScope').onchange=()=>{$v('v7DelSid').hidden=$v('v7DelScope').value==='event';$v('v7DelPid').hidden=$v('v7DelScope').value!=='one';};$v('v7DelScope').onchange();
    $v('v7DoDelete').onclick=async()=>{const scope=$v('v7DelScope').value,reason=$v('v7DelReason').value.trim(),sid=$v('v7DelSid').value,pid=$v('v7DelPid').value;if(reason.length<8){toast('Alasan minimal 8 karakter');return;}if(!confirm('Yakin membatalkan kehadiran? Tindakan ini dicatat pada audit log.'))return;
      let confirmText='';if(scope==='event'){confirmText=prompt('Ketik HAPUS untuk mengonfirmasi penghapusan semua kehadiran kegiatan:')||'';if(confirmText!=='HAPUS')return;}
      try{const r=await request('v7.attendance.delete',{scope,sid,pid,reason,confirm:confirmText});await refresh();toast(`${r.count} catatan kehadiran dibatalkan`);draw();}catch(ex){err(ex);}};
  }
  async function drawUsers(){
    if(!admin()){show(h('Hanya Admin.'));return;}
    const [data,ev]=await Promise.all([request('v7.users.list'),eventList()]);
    show(`<h2>Akun panitia</h2><div class="v7-list">${data.users.map(u=>`<div class="v7-item"><div><b>${E(u.name)}</b><small>${E(u.username)} · ${E(u.role)} · ${u.enabled?'Aktif':'Nonaktif'}</small></div>${u.enabled?`<button class="gh" data-disable="${E(u.id)}">Nonaktifkan</button>`:''}</div>`).join('')||h('Akun legacy admin masih menggunakan private/config.php.')}</div>
      <hr><h3>Tambah akun</h3><div class="v7-field"><input id="v7Un" placeholder="Username"><input id="v7Full" placeholder="Nama petugas"><input type="password" id="v7Pwd" placeholder="Password min. 12 karakter"><select id="v7URole"><option value="scanner">Petugas Absensi</option><option value="assessor">Penilai</option><option value="admin">Admin</option></select></div>${actionButton('v7UserAdd','Buat akun','btn')}
      <hr><h3>Tetapkan kegiatan untuk pengguna</h3><div class="v7-field"><select id="v7AssignUser">${data.users.map(u=>`<option value="${E(u.id)}">${E(u.username)} (${E(u.role)})</option>`).join('')}</select><select id="v7AssignEvent">${events.filter(e=>!e.archived).map(e=>`<option value="${E(e.id)}">${E(e.name)}</option>`).join('')}</select>${actionButton('v7Assign','Izinkan akses','btn')}</div>`);
    $v('v7UserAdd').onclick=async()=>{try{await request('v7.users.create',{username:$v('v7Un').value,name:$v('v7Full').value,password:$v('v7Pwd').value,role:$v('v7URole').value});toast('Akun dibuat. Tetapkan kegiatannya sebelum login.');draw();}catch(ex){err(ex);}};
    if($v('v7Assign'))$v('v7Assign').onclick=async()=>{try{await request('v7.users.assign',{user_id:$v('v7AssignUser').value,event_id:$v('v7AssignEvent').value});toast('Akses kegiatan diberikan');draw();}catch(ex){err(ex);}};
    document.querySelectorAll('[data-disable]').forEach(b=>b.onclick=async()=>{if(!confirm('Nonaktifkan akun?'))return;try{await request('v7.users.disable',{user_id:b.dataset.disable});draw();}catch(ex){err(ex);}});
  }
  async function drawAudit(){if(!admin()){show(h('Hanya Admin.'));return;}const d=await request('v7.audit');show(`<h2>Audit log (150 terbaru)</h2><div class="v7-list">${d.audit.map(x=>`<div class="v7-item"><div><b>${E(x.action)}</b><small>${E(x.actor)} (${E(x.role)}) · ${new Date(Number(x.at_ms)).toLocaleString('id-ID')}</small><details><summary class="v7-muted">Detail</summary><pre class="v7-out">${E(x.details_json)}</pre></details></div></div>`).join('')||h('Belum ada aktivitas') }</div>`);}
  async function drawImport(){if(!admin()){show(h('Hanya Admin.'));return;}show(`<h2>Impor cadangan PKD V6</h2><p class="v7-muted">Impor membuat kegiatan baru; tidak menghapus data atau menggabungkan data produksi. Backup yang sama tidak akan diimpor dua kali. File fisik IndexedDB tetap harus diunggah terpisah.</p>
    <input type="file" id="v7ImportFile" accept=".json,application/json">${actionButton('v7ImportPreview','Periksa file','gh')}<div id="v7ImportOut" class="v7-out">Belum ada file.</div>${actionButton('v7ImportApply','Impor sebagai kegiatan baru','btn')} `);
    $v('v7ImportApply').disabled=true;
    $v('v7ImportPreview').onclick=async()=>{try{const f=$v('v7ImportFile').files[0];if(!f)throw new Error('Pilih file JSON.');if(f.size>10*1024*1024)throw new Error('File melebihi 10 MB.');lastImport=JSON.parse(await f.text());const result=await request('v7.import.preview',{data:lastImport});$v('v7ImportOut').textContent=JSON.stringify(result,null,2);$v('v7ImportApply').disabled=!!result.existing;}catch(ex){lastImport=null;err(ex);}};
    $v('v7ImportApply').onclick=async()=>{if(!lastImport||!confirm('Impor sebagai kegiatan baru? Data kegiatan sekarang tidak diubah.'))return;try{const r=await request('v7.import.apply',{data:lastImport});await eventList();toast('Impor berhasil');await switchEvent(r.event_id);draw();}catch(ex){err(ex);}};
  }
  async function drawDiag(){const d=await request('v7.diagnostics');show(`<h2>Diagnostik server</h2><p class="v7-muted">Status koneksi dan ringkasan data kegiatan yang dipilih.</p><pre class="v7-out">${E(JSON.stringify(d,null,2))}</pre>${actionButton('v7RefreshNow','Segarkan dari VPS','btn')}`);$v('v7RefreshNow').onclick=async()=>{try{await refresh();draw();}catch(ex){err(ex);}};}
  async function init(){
    try{const r=await request('session');if(!r.v7)return;enabled=true;window.pk7Mode=true;role=r.role||'admin';window.pk7Role=role;
      await eventList();mount();
    }catch(ex){console.error('V7 init:',ex);}
  }
  window.v7Init=init;window.v7Redraw=draw;window.v7Refresh=refresh;window.v7Maintain=()=>{if(enabled)mount();};
  setInterval(async()=>{
    if(!enabled||!cloudReady||cloudPending||cloudBusy||pollBusy||document.hidden||!navigator.onLine)return;
    if(document.activeElement?.matches('input,textarea,select'))return;
    pollBusy=true;try{const s=await request('v7.poll',{revision:cloudRevision});if(s.event_id===S.event_id&&s.changed){await refresh();paintStrip();if(cur==='vV7')draw();}}
    catch(ex){console.warn('V7 polling gagal:',ex.message);}finally{pollBusy=false;}
  },25000);
})();
