// Throwaway UI model. Illustrative stock-rule targets; no production forecast formula or persistence.
const initialProducts = [
  {id:1,name:'Sardines · 155 g',sku:'480000000001',stock:8,pack:24,target:48,basis:'Stock rule · No forecast available',cost:22},
  {id:2,name:'Evaporated milk · 370 ml',sku:'480000000002',stock:0,pack:12,target:24,basis:'Available forecast · Illustrative 24-unit target',cost:38},
  {id:3,name:'Instant noodles · Chicken',sku:'480000000003',stock:15,pack:30,target:30,basis:'Stock rule · Forecast not ready',cost:11},
  {id:4,name:'Bottled water · 500 ml',sku:'480000000004',stock:42,pack:0,target:24,basis:'Stock rule · No forecast available',cost:9}
];
const names = {A:'Receiving worksheet',B:'Guided shopping entry',C:'Stock-first workspace'};
let products, lines, history, metadata, stage, reviewing;
let variant = new URLSearchParams(location.search).get('variant');
if (!names[variant]) variant = 'A';
const escapeText = value => String(value).replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
const product = id => products.find(p => p.id === id);
const units = line => Number(line.quantity) * (line.mode === 'packs' ? product(line.id).pack : 1);
const accepted = line => units(line) - Number(line.damage);
const whole = value => /^\d+$/.test(String(value)) && Number.isSafeInteger(Number(value));
function reset() {
  products = structuredClone(initialProducts); lines = []; history = []; metadata = {store:'',receipt:''}; stage = 0; reviewing = false;
  document.querySelector('#feedback').innerHTML = ''; render();
}
function lookup() {
  return `<div class="lookup"><label>Find a product<select id="choose"><option value="">Select an active product</option>${products.map(p=>`<option value="${p.id}">${p.name}</option>`).join('')}</select></label><button data-action="add">Add product</button></div><div class="lookup"><label>Barcode or product search<input id="scan" placeholder="Try 480000000001 or sardines"></label><button data-action="scan">Find and add</button></div>`;
}
function quantityFields(line) {
  const p = product(line.id);
  return `<label>Quantity<input data-id="${p.id}" data-field="quantity" value="${escapeText(line.quantity)}" inputmode="numeric" aria-label="${p.name} quantity"></label><label>Quantity mode<select data-id="${p.id}" data-field="mode" aria-label="${p.name} quantity mode"><option value="units" ${line.mode==='units'?'selected':''}>Units</option><option value="packs" ${line.mode==='packs'?'selected':''} ${p.pack?'':'disabled'}>Cases (${p.pack} units)</option></select></label><label>Damaged units<input data-id="${p.id}" data-field="damage" value="${escapeText(line.damage)}" inputmode="numeric" aria-label="${p.name} damaged units"></label>`;
}
function optionalLine(line) {
  const p = product(line.id);
  return `<details><summary>Cost, batch and expiry (optional)</summary><div class="fields"><label>Purchase cost per individual unit (₱)<input data-id="${p.id}" data-field="cost" value="${escapeText(line.cost)}" placeholder="Unknown" inputmode="decimal"></label><label>Batch reference<input data-id="${p.id}" data-field="batch" value="${escapeText(line.batch)}"></label><label>Expiry date<input type="date" data-id="${p.id}" data-field="expiry" value="${escapeText(line.expiry)}"></label></div></details>`;
}
function entry(compact=false) {
  if (!lines.length) return '<p class="empty">No products added. Add bought goods, including unplanned purchases.</p>';
  if (compact) return lines.map(line=>`<div class="line-editor"><h3>${product(line.id).name}</h3><p>${product(line.id).stock} on hand · ${product(line.id).pack ? product(line.id).pack+' units per saved case':'Individual units only'}</p><div class="fields">${quantityFields(line)}</div><p><strong>${accepted(line)} accepted units</strong> from ${units(line)} bought · New stock: ${product(line.id).stock+accepted(line)}</p>${optionalLine(line)}<button data-action="remove" data-id="${line.id}">Remove ${product(line.id).name}</button></div>`).join('');
  return `<div class="table-scroll"><table class="entry-table"><thead><tr><th>Product / on hand</th><th>Actual quantity</th><th>Usable stock addition</th><th>Details</th></tr></thead><tbody>${lines.map(line=>`<tr><td><strong>${product(line.id).name}</strong><small>${product(line.id).stock} on hand</small><button data-action="remove" data-id="${line.id}" aria-label="Remove ${product(line.id).name}">Remove</button></td><td>${quantityFields(line)}</td><td><strong>+${accepted(line)} units</strong><small>${units(line)} bought − ${line.damage} damaged</small><small>${product(line.id).stock} → ${product(line.id).stock+accepted(line)} on hand</small></td><td>${optionalLine(line)}</td></tr>`).join('')}</tbody></table></div>`;
}
function receipt() {
  return `<details class="optional"><summary>Shopping store and receipt (optional)</summary><p>No supplier record or purchasing document required. The shopping store does not change preferred suppliers.</p><div class="fields"><label>Shopping store<input data-meta="store" value="${escapeText(metadata.store)}" placeholder="Optional"></label><label>Receipt reference<input data-meta="receipt" value="${escapeText(metadata.receipt)}" placeholder="Optional"></label></div></details>`;
}
function recommendations() {
  return `<section id="recommendations"><h2>Replenishment Recommendations</h2><p>Buying guidance only. The owner shops outside RetailMind; actual purchases may differ.</p><div class="table-scroll"><table><thead><tr><th>Product / basis</th><th>On hand</th><th>Suggested units</th><th>Action</th></tr></thead><tbody>${products.map(p=>`<tr><td><strong>${p.name}</strong><small>${p.basis}</small><span class="tag ${p.stock>=p.target?'covered':''}">${p.stock===0?'Out of stock':p.stock<p.target?'Below planning target':'Covered'}</span></td><td>${p.stock}</td><td>${Math.max(0,p.target-p.stock)}</td><td><button data-action="product" data-id="${p.id}" ${lines.some(l=>l.id===p.id)?'disabled':''}>${lines.some(l=>l.id===p.id)?'In entry':'Add bought goods'}</button></td></tr>`).join('')}</tbody></table></div></section>`;
}
function summary() {
  return `<h2>Shopping entry</h2><p>${lines.length} product${lines.length===1?'':'s'}</p><div class="totals">${lines.reduce((sum,l)=>sum+accepted(l),0)} usable units</div><p>Damaged goods are excluded from stock additions.</p><p>No approval, request, or order required.</p>`;
}
function review() {
  return `<h2>Review bought stock</h2><p>Check actual quantities before saving this entire entry once.</p><div class="table-scroll"><table><thead><tr><th>Product</th><th>Bought</th><th>Damaged</th><th>Accepted</th><th>Stock after save</th></tr></thead><tbody>${lines.map(l=>`<tr><td>${product(l.id).name}<small>${escapeText(l.cost)===''?'Cost unknown; current cost retained':'₱'+escapeText(l.cost)+' per unit'} · Batch ${escapeText(l.batch)||'not entered'} · Expiry ${escapeText(l.expiry)||'not entered'}</small></td><td>${l.quantity} ${l.mode} (${units(l)} units)</td><td>${l.damage}</td><td>${accepted(l)}</td><td>${product(l.id).stock} → ${product(l.id).stock+accepted(l)}</td></tr>`).join('')}</tbody></table></div><p>Shopping store: ${escapeText(metadata.store)||'Not entered'} · Receipt: ${escapeText(metadata.receipt)||'Not entered'}</p><div class="actions"><button data-action="edit">Back to entry</button><button class="primary" data-action="save">Save entire entry (demo)</button></div>`;
}
function historyView() {
  return `<section id="history" class="panel"><h2>Receiving history</h2>${history.length?`<div class="table-scroll"><table><thead><tr><th>Product</th><th>Accepted / damaged</th><th>Recorded by / time</th><th>Store / receipt</th></tr></thead><tbody>${history.map(h=>`<tr><td>${h.name}<small>${h.quantity} ${h.mode} · ${h.pack} units per saved case</small><small>Cost ${h.cost===''?'unknown':'₱'+escapeText(h.cost)+' per unit'} · Batch ${escapeText(h.batch)||'none'} · Expiry ${escapeText(h.expiry)||'none'}</small></td><td>+${h.accepted} / ${h.damage}</td><td>Demo Inventory Manager<small>${h.time}</small></td><td>${escapeText(h.store)||'Not entered'}<small>${escapeText(h.receipt)||'Not entered'}</small></td></tr>`).join('')}</tbody></table></div>`:'<p>No entries recorded in this demo yet.</p>'}</section>`;
}
function render() {
  document.querySelector('#variant-label').textContent = `${variant} · ${names[variant]}`;
  const controls = `<div class="actions"><span>${lines.length} products selected</span><button class="primary" data-action="review" ${lines.length?'':'disabled'}>Review entire entry</button></div>`;
  let content;
  if (reviewing) content = `<section class="panel">${review()}</section>`;
  else if (variant==='A') content = `<h2>Record a shopping trip</h2><p>Enter every product bought, then review and save once.</p><div class="split"><section id="entry" class="panel">${lookup()}${entry()}${receipt()}${controls}</section><aside class="panel">${summary()}</aside></div><section class="panel">${recommendations()}</section>`;
  else if (variant==='B') content = `<h2>Record bought goods</h2><p>Work through one shopping entry in three steps.</p><nav class="steps" aria-label="Entry steps">${['Select products','Enter quantities','Review'].map((label,i)=>`<button data-action="stage" data-stage="${i}" ${stage===i?'aria-current="step"':''} ${i===2&&!lines.length?'disabled':''}>${i+1}. ${label}</button>`).join('')}</nav><section id="entry" class="panel">${stage===0?lookup()+`<p>Selected: ${lines.map(l=>product(l.id).name).join(', ')||'none'}</p><button class="primary" data-action="stage" data-stage="1" ${lines.length?'':'disabled'}>Enter actual quantities</button>`:entry(true)+receipt()+controls}</section><details class="panel"><summary>Check buying guidance</summary>${recommendations()}</details>`;
  else content = `<h2>Check stock, record what came back</h2><p>Use recommendations as a guide, then enter the owner's actual purchases.</p><div class="stock-first"><section id="stock" class="panel">${recommendations()}</section><section id="entry" class="panel"><h2>Bought goods</h2>${lookup()}${entry(true)}${receipt()}${controls}</section></div>`;
  document.querySelector('#layout').innerHTML = content+historyView();
  document.querySelector('#state').innerHTML = `<p>Variant: ${variant} · ${reviewing?'Reviewing entry':stage===1&&variant==='B'?'Entering quantities':'Editing entry'} · No database writes or forecast jobs.</p><ul>${products.map(p=>`<li>${p.name}: ${p.stock} on hand; ${Math.max(0,p.target-p.stock)} suggested; saved case size ${p.pack}; current cost ₱${p.cost}</li>`).join('')}</ul><p>Draft: ${lines.length} lines; ${lines.reduce((n,l)=>n+units(l),0)} bought; ${lines.reduce((n,l)=>n+Number(l.damage),0)} damaged; ${lines.reduce((n,l)=>n+accepted(l),0)} accepted. History: ${history.length} lines.</p><p>Store: ${escapeText(metadata.store)||'not entered'}; receipt: ${escapeText(metadata.receipt)||'not entered'}.</p>`;
}
function feedback(message,error=false) { document.querySelector('#feedback').innerHTML=`<div class="${error?'error':'success'}">${escapeText(message)}</div>`; }
function add(id) {
  if (lines.some(l=>l.id===id)) return feedback('This product is already in the entry. Change its quantity instead.',true);
  if (!product(id)) return feedback('Product not found. Select an active product or check the barcode.',true);
  lines.push({id,quantity:'1',mode:'units',damage:'0',cost:'',batch:'',expiry:''}); render();
}
function validate() {
  if (!lines.length) return 'Add at least one product.';
  for (const l of lines) {
    if (!whole(l.quantity)||Number(l.quantity)===0||!Number.isSafeInteger(units(l))) return `${product(l.id).name}: enter a positive whole-number quantity.`;
    if (!['units','packs'].includes(l.mode)||(l.mode==='packs'&&!product(l.id).pack)) return `${product(l.id).name}: no saved case size. Use individual units.`;
    if (!whole(l.damage)||Number(l.damage)>units(l)) return `${product(l.id).name}: damaged units must be a whole number from 0 to ${units(l)}.`;
    if (l.cost!==''&&(!/^\d+(\.\d{1,2})?$/.test(l.cost)||!Number.isFinite(Number(l.cost)))) return `${product(l.id).name}: enter a valid non-negative cost per unit, or leave it blank.`;
  }
  return '';
}
document.querySelector('#layout').addEventListener('change',event=>{
  if (event.target.dataset.meta) metadata[event.target.dataset.meta]=event.target.value;
  if (event.target.dataset.field) { lines.find(l=>l.id===Number(event.target.dataset.id))[event.target.dataset.field]=event.target.value; render(); }
});
document.querySelector('#layout').addEventListener('keydown',event=>{ if(event.target.id==='scan'&&event.key==='Enter'){event.preventDefault();document.querySelector('[data-action="scan"]').click();} });
document.querySelector('#layout').addEventListener('click',event=>{
  const button=event.target.closest('button[data-action]'); if (!button) return;
  const action=button.dataset.action;
  if(action==='add') add(Number(document.querySelector('#choose').value));
  if(action==='product') add(Number(button.dataset.id));
  if(action==='scan') { const text=document.querySelector('#scan').value.trim().toLowerCase(); const p=products.find(p=>p.sku===text||(text&&p.name.toLowerCase().includes(text))); add(p?.id); }
  if(action==='remove'){lines=lines.filter(l=>l.id!==Number(button.dataset.id));render();}
  if(action==='stage'&&button.dataset.stage!=='2'){stage=Number(button.dataset.stage);render();}
  if(action==='review'||(action==='stage'&&button.dataset.stage==='2')){const error=validate();if(error)return feedback(error+' Nothing saved; entered values retained.',true);reviewing=true;document.querySelector('#feedback').innerHTML='';render();}
  if(action==='edit'){reviewing=false;stage=1;render();}
  if(action==='save'){
    const error=validate();if(error)return feedback(error,true);
    button.disabled=true;
    // Validate all lines before this in-memory commit. Real transaction/concurrency behavior is outside this UI prototype.
    const time=new Intl.DateTimeFormat('en-PH',{timeZone:'Asia/Manila',dateStyle:'medium',timeStyle:'short'}).format(new Date());
    const saved=lines.map(l=>({...l,name:product(l.id).name,accepted:accepted(l),pack:product(l.id).pack,time,...metadata}));
    for(const l of lines){product(l.id).stock+=accepted(l);if(l.cost!=='')product(l.id).cost=Number(l.cost);}
    history.unshift(...saved);lines=[];metadata={store:'',receipt:''};reviewing=false;stage=0;render();
    feedback(`Demo saved once: ${saved.map(l=>l.name+' +'+l.accepted+' units').join('; ')}. Recorded by Demo Inventory Manager at ${time}. Recommendations refreshed from current sample stock.`);
  }
});
function switchVariant(offset){variant=Object.keys(names)[(Object.keys(names).indexOf(variant)+offset+3)%3];const url=new URL(location);url.searchParams.set('variant',variant);window.history.replaceState(null,'',url);render();}
document.querySelector('#previous').onclick=()=>switchVariant(-1);
document.querySelector('#next').onclick=()=>switchVariant(1);
document.querySelector('#reset').onclick=reset;
document.addEventListener('keydown',event=>{if(event.target.closest('input,textarea,select,[contenteditable]'))return;if(event.key==='ArrowLeft'||event.key==='ArrowRight'){event.preventDefault();switchVariant(event.key==='ArrowLeft'?-1:1);}});
reset();
