const $ = (id) => document.getElementById(id);
const layouts = { a: 'Inline form', b: 'Guided steps', c: 'Side by side' };
const fixtures = {
  match: { label: 'Product match with photo', code: '0748485100401', name: 'Century Tuna 155 g', brand: 'Century Tuna', size: '155 g', category: 'Canned Goods', photo: true },
  category: { label: 'Product match without store category', code: '4801111000009', name: 'Calamansi Concentrate 250 mL', brand: 'Calamansi House', size: '250 mL' },
  missing: { label: 'No catalog match', code: '4801111000016', message: 'No catalog match. Enter product details manually.' },
  offline: { label: 'No internet', code: '4801111000023', message: 'No internet connection. Enter product details manually.' },
  unavailable: { label: 'Lookup service unavailable', code: '4801111000030', message: 'Lookup service unavailable. Try again or enter details manually.' },
  existing: { label: 'Already used by a Sales Pack', code: '4801111000047' },
  inactive: { label: 'Inactive Product', code: '4801111000054' },
  carton: { label: 'Receiving Carton scanned first', code: '14801111000044', kind: 'case' },
  pack: { label: 'New Sales Pack barcode', code: '4801111000061', kind: 'pack' },
  photoError: { label: 'Photo fails when saving', code: '0748485100401', failPhoto: true },
};
const existing = new Map([
  [fixtures.existing.code, { name: '555 Sardines 155 g', kind: 'Sales Pack', status: 'Active', automatic: false }],
  [fixtures.inactive.code, { name: 'Century Tuna Hot & Spicy 155 g', kind: 'Individual Item', status: 'Inactive', automatic: true }],
]);
const samplePhoto = $('photo-image').getAttribute('src');
const draftFields = ['name', 'brand', 'size', 'category', 'unit', 'cost', 'price', 'pack-code', 'pack-size', 'pack-price', 'case-code', 'case-size'];
const unitSuggestions = ['name', 'brand', 'size'];
const auto = new Map();
let activeCode = '';
let usedCode = null;
let result = null;
let displayedPhoto = '';
let sequence = 0;
let step = 0;
let variant = 'a';
let photoObjectUrl = '';
let photoAttempted = false;

function setStep(value) {
  step = value;
  $('panel').dataset.step = value;
  $('back').hidden = variant !== 'b' || value === 0;
  $('next').hidden = variant !== 'b' || value === 2;
  $('save').hidden = variant === 'b' && value !== 2;
  $('steps').querySelectorAll('button').forEach((button) => {
    if (Number(button.dataset.step) === value) button.setAttribute('aria-current', 'step');
    else button.removeAttribute('aria-current');
  });
  $('body').scrollTop = 0;
}

function setVariant(key) {
  variant = Object.hasOwn(layouts, key) ? key : 'a';
  $('panel').dataset.variant = variant;
  $('layout-label').textContent = variant.toUpperCase() + ' · ' + layouts[variant];
  const url = new URL(location.href);
  url.searchParams.set('variant', variant);
  history.replaceState(null, '', url);
  setStep(step);
}

function cycle(offset) {
  const keys = Object.keys(layouts);
  setVariant(keys[(keys.indexOf(variant) + offset + keys.length) % keys.length]);
}

function showForm(open) {
  $('overlay').hidden = !open;
  document.querySelector('.workspace').inert = open;
  (open ? $('barcode') : $('open')).focus({ preventScroll: true });
}

function notice(message = '', tone = '') {
  const element = $('lookup-status');
  element.textContent = message;
  element.dataset.tone = tone;
  element.hidden = !message;
}

function showPhoto(source = '') {
  displayedPhoto = source;
  $('photo').hidden = !source;
  $('add-photo').hidden = !!source;
  if (!source) return;
  $('photo-image').src = source;
  $('photo-image').alt = source === samplePhoto ? 'Front of the Century Tuna can' : 'Uploaded product photo';
  $('photo-credit').hidden = source !== samplePhoto;
}

function refreshPackaging() {
  $('pack-fields').hidden = !$('sell-pack').checked;
  $('pack-summary').textContent = $('sell-pack').checked ? ($('pack-size').value ? $('pack-size').value + ' units' : 'Enabled') : 'Optional';
  $('case-summary').textContent = $('case-size').value ? $('case-size').value + ' units' : 'Optional';
}

function clearSuggestion() {
  for (const [id, value] of auto) {
    if ($(id).value === value) $(id).value = '';
  }
  auto.clear();
  if (displayedPhoto === samplePhoto) showPhoto();
  $('category-suggestion').hidden = true;
  $('category-help').hidden = true;
  $('category-help').textContent = 'Choose a store category.';
  $('field-suggestion').hidden = true;
  result = null;
  photoAttempted = false;
}

function reset() {
  sequence++;
  clearSuggestion();
  HTMLFormElement.prototype.reset.call($('product-form'));
  $('case').open = false;
  $('pack').open = false;
  $('existing-actions').hidden = true;
  $('package-next').hidden = true;
  $('lookup').disabled = false;
  $('form-status').textContent = '';
  $('category').removeAttribute('aria-invalid');
  if (photoObjectUrl) URL.revokeObjectURL(photoObjectUrl);
  photoObjectUrl = '';
  activeCode = '';
  usedCode = null;
  showPhoto();
  notice();
  refreshPackaging();
  setStep(0);
}

function action(title, message, buttons, fieldLabel = '', initialValue = '') {
  const dialog = $('action-dialog');
  $('action-title').textContent = title;
  const content = $('action-content');
  content.replaceChildren();
  const paragraph = document.createElement('p');
  paragraph.textContent = message;
  content.append(paragraph);
  let field;
  if (fieldLabel) {
    const label = document.createElement('label');
    label.textContent = fieldLabel;
    field = document.createElement('input');
    field.value = initialValue;
    label.append(field);
    content.append(label);
  }
  $('action-buttons').replaceChildren();
  for (const [label, run, primary] of buttons) {
    const button = document.createElement('button');
    button.type = 'button';
    button.textContent = label;
    if (primary) button.className = 'primary';
    button.onclick = () => {
      const error = run(field?.value.trim());
      if (error) {
        paragraph.textContent = error;
        field?.focus();
      } else if (dialog.open) dialog.close();
    };
    $('action-buttons').append(button);
  }
  if (!dialog.open) dialog.showModal();
  field?.focus();
}

function applyLookup({ code, kind, fixture }) {
  const previous = ++sequence;
  $('lookup').disabled = false;
  $('existing-actions').hidden = true;
  $('package-next').hidden = true;
  $('form-status').textContent = '';
  $('barcode').value = code;
  $('kind').value = kind;
  const registered = existing.get(code);
  if (registered) {
    usedCode = code;
    notice('Already used: ' + registered.name + ' · ' + registered.kind + ' · ' + registered.status + '.', 'warning');
    $('existing-actions').hidden = false;
    return;
  }
  usedCode = null;
  if (kind !== 'unit') {
    const isCarton = kind === 'case';
    const id = isCarton ? 'case-code' : 'pack-code';
    const other = isCarton ? 'pack-code' : 'case-code';
    if (code === activeCode || code === $(other).value.trim()) {
      notice('This barcode is already assigned to another field in this draft.', 'warning');
      return;
    }
    if ($(id).value && $(id).value !== code) {
      const target = isCarton ? 'Receiving Carton' : 'Sales Pack';
      action('Replace ' + target + ' barcode?', 'Confirm the new barcode before replacing the one in this draft.', [
        ['Keep current barcode', () => { $('barcode').value = $(id).value; notice(); }],
        ['Replace barcode', () => { $(id).value = ''; applyLookup({ code, kind, fixture }); }, true],
      ]);
      return;
    }
    $(id).value = code;
    $(kind).open = true;
    if (!isCarton) $('sell-pack').checked = true;
    refreshPackaging();
    notice(isCarton ? 'Receiving Carton barcode added. Confirm the units per carton.' : 'Sales Pack barcode added. Confirm the pack size and price.', 'success');
    $('package-next').hidden = !!activeCode;
    return;
  }
  if ([$('case-code').value.trim(), $('pack-code').value.trim()].includes(code)) {
    notice('This code belongs to a package in this draft. Scan the individual item.', 'warning');
    return;
  }
  activeCode = code;
  $('lookup').disabled = true;
  notice('Checking barcode…');
  setTimeout(() => {
    if (previous !== sequence) return;
    $('lookup').disabled = false;
    if (fixture.failPhoto) fixture = { ...fixtures.match, failPhoto: true };
    if (!fixture.name) {
      notice(fixture.message, 'warning');
      return;
    }
    result = fixture;
    notice('Catalog match found. Verify the details against the package.', 'success');
    const conflicts = [];
    for (const id of unitSuggestions) {
      if (!$(id).value) {
        $(id).value = fixture[id];
        auto.set(id, fixture[id]);
      } else if ($(id).value !== fixture[id]) {
        conflicts.push(fixture[id]);
      }
    }
    $('field-suggestion').hidden = !conflicts.length;
    $('field-suggestion-text').textContent = conflicts.length ? 'Catalog suggests ' + conflicts.join(' · ') + '.' : '';
    if (fixture.category && !$('category').value) {
      $('category-suggestion').textContent = 'Use ' + fixture.category;
      $('category-suggestion').hidden = false;
    }
    if (!fixture.category && !$('category').value) {
      $('category-help').textContent = 'No matching store category. Choose one.';
      $('category-help').hidden = false;
    }
    if (fixture.photo && !displayedPhoto) showPhoto(samplePhoto);
  }, 300);
}

function lookup(fixture) {
  const code = (fixture?.code ?? $('barcode').value).trim();
  const kind = fixture?.kind ?? (fixture ? 'unit' : $('kind').value);
  if (!code) {
    notice('Enter a barcode first.', 'warning');
    $('barcode').focus();
    return;
  }
  const matching = fixture ?? Object.values(fixtures).find((entry) => entry.code === code) ?? fixtures.missing;
  const request = { code, kind, fixture: matching };
  setStep(0);
  if (kind === 'unit' && activeCode && activeCode !== code && (displayedPhoto || draftFields.some((id) => $(id).value))) {
    action('Use this barcode instead?', 'Check that any details you keep belong to the newly scanned product.', [
      ['Keep current barcode', () => { $('barcode').value = activeCode; $('kind').value = 'unit'; }],
      ['Keep entered details', () => { clearSuggestion(); applyLookup(request); }],
      ['Start a new draft', () => { reset(); applyLookup(request); }, true],
    ]);
    return;
  }
  if (kind === 'unit' && activeCode && activeCode !== code) clearSuggestion();
  applyLookup(request);
}

function registerFixture(key, fixture) {
  const button = document.createElement('button');
  button.type = 'button';
  button.textContent = fixture.label;
  button.onclick = () => {
    $('demo-dialog').close();
    if ($('overlay').hidden) showForm(true);
    if (key === 'photoError') photoAttempted = false;
    lookup(fixture);
  };
  $('cases').append(button);
}

function finishSave(withPhoto) {
  if (!withPhoto) showPhoto();
  const pendingItem = $('kind').value === 'unit' ? $('barcode').value.trim() : '';
  const code = activeCode || pendingItem || 'RM-DEMO-001';
  $('form-status').textContent = 'Demo saved ' + $('name').value.trim() + ' · ' + code + (displayedPhoto ? ' · with photo.' : '.');
}

function validateForm() {
  if (usedCode || existing.has(activeCode) || existing.has($('barcode').value.trim())) {
    setStep(0);
    $('form-status').textContent = 'This barcode is already assigned.';
    $('barcode').focus();
    return false;
  }
  const category = $('category');
  const validCategory = [...$('categories').options].some((option) => option.value.toLowerCase() === category.value.trim().toLowerCase());
  category.setAttribute('aria-invalid', String(!validCategory));
  for (const id of ['name', 'category', 'unit', 'cost', 'price']) {
    const field = $(id);
    if (field.value.trim() && field.validity.valid && (id !== 'category' || validCategory)) continue;
    setStep(['cost', 'price'].includes(id) ? 2 : 1);
    $('form-status').textContent = id === 'category' ? 'Choose a store category.' : 'Complete the required fields.';
    field.focus();
    return false;
  }
  const packCode = $('sell-pack').checked ? $('pack-code').value.trim() : '';
  const caseCode = $('case-code').value.trim();
  const pendingCode = $('kind').value === 'unit' ? $('barcode').value.trim() : '';
  if (pendingCode && activeCode && pendingCode !== activeCode) {
    setStep(0);
    $('form-status').textContent = 'Look up or confirm the changed item barcode.';
    $('barcode').focus();
    return false;
  }
  const codes = [activeCode || pendingCode, packCode, caseCode].filter(Boolean);
  if (new Set(codes).size !== codes.length || codes.some((code) => existing.has(code))) {
    setStep(2);
    $('form-status').textContent = 'Use a different barcode for each item, pack, and carton.';
    return false;
  }
  if ($('sell-pack').checked && (!packCode || Number($('pack-size').value) < 2 || !$('pack-size').validity.valid || Number($('pack-price').value) <= 0 || !$('pack-price').validity.valid || Number($('pack-price').value) >= Number($('pack-size').value) * Number($('price').value))) {
    setStep(2);
    $('pack').open = true;
    $('form-status').textContent = 'Enter the pack barcode, size, and a price below the individual total.';
    return false;
  }
  if (caseCode && (Number($('case-size').value) < 2 || !$('case-size').validity.valid)) {
    setStep(2);
    $('case').open = true;
    $('form-status').textContent = 'Confirm the Base Units per receiving carton.';
    $('case-size').focus();
    return false;
  }
  return true;
}

$('product-form').onsubmit = (event) => {
  event.preventDefault();
  if (!validateForm()) return;
  if (result?.failPhoto && displayedPhoto === samplePhoto && !photoAttempted) {
    photoAttempted = true;
    action('Product photo unavailable', 'Your product details are still in the form.', [
      ['Save without photo', () => finishSave(false)],
      ['Retry photo', () => finishSave(true), true],
    ]);
    return;
  }
  finishSave(true);
};

$('manage').onclick = () => {
  const record = existing.get(usedCode);
  if (!record) return;
  const old = usedCode;
  const description = record.name + ' · ' + record.kind + ' · ' + record.status;
  action('Manage Product', description, [
    ['Close', () => {}],
    ['Change barcode', () => {
      $('action-dialog').close();
      requestAnimationFrame(() => {
        action('Change barcode', description + (record.automatic ? '. The automatic SKU will change too.' : '.'), [
          ['Cancel', () => {}],
          ['Save barcode', (value) => {
            if (!value || value === old) return 'Enter a different barcode.';
            if (existing.has(value) || [activeCode, $('pack-code').value.trim(), $('case-code').value.trim()].includes(value)) {
              return 'This barcode is already in use. Enter a unique barcode.';
            }
            existing.delete(old);
            existing.set(value, record);
            usedCode = value;
            $('barcode').value = value;
            notice('Barcode changed from ' + old + ' to ' + value + '. Reprint any affected labels.', 'success');
          }, true],
        ], 'New barcode', old);
      });
    }, true],
  ]);
};

$('add-category').onclick = () => action('Add Category', 'Create a store category for this product.', [
  ['Cancel', () => {}],
  ['Add category', (value) => {
    if (!value) return 'Enter a category name.';
    const found = [...$('categories').options].find((option) => option.value.toLowerCase() === value.toLowerCase());
    if (!found) $('categories').append(new Option('', value));
    $('category').value = found?.value ?? value;
    $('category').removeAttribute('aria-invalid');
    $('category-help').hidden = true;
    $('category-suggestion').hidden = true;
  }, true],
], 'Category name');

$('category-suggestion').onclick = () => {
  $('category').value = result.category;
  $('category').removeAttribute('aria-invalid');
  $('category-suggestion').hidden = true;
};
$('category').oninput = () => $('category').removeAttribute('aria-invalid');
$('use-suggestions').onclick = () => {
  for (const id of unitSuggestions) {
    $(id).value = result[id];
    auto.set(id, result[id]);
  }
  $('field-suggestion').hidden = true;
};
for (const id of unitSuggestions) $(id).addEventListener('input', () => auto.delete(id));
$('add-photo').onclick = $('replace-photo').onclick = () => $('photo-file').click();
$('remove-photo').onclick = () => {
  $('photo-file').value = '';
  showPhoto();
};
$('photo-file').onchange = () => {
  const image = $('photo-file').files[0];
  if (!image) return;
  if (photoObjectUrl) URL.revokeObjectURL(photoObjectUrl);
  photoObjectUrl = URL.createObjectURL(image);
  showPhoto(photoObjectUrl);
};
$('scan-unit').onclick = () => {
  $('kind').value = 'unit';
  $('barcode').value = activeCode;
  $('package-next').hidden = true;
  $('barcode').focus();
};
$('lookup').onclick = () => lookup();
$('barcode').onkeydown = (event) => {
  if (event.key !== 'Enter') return;
  event.preventDefault();
  lookup();
};
$('scan').onclick = $('demo').onclick = () => $('demo-dialog').showModal();
$('kind').onchange = () => {
  $('barcode').value = { unit: activeCode, case: $('case-code').value, pack: $('pack-code').value }[$('kind').value];
  $('existing-actions').hidden = true;
  $('package-next').hidden = true;
  usedCode = null;
  notice();
};
$('reset').onclick = reset;
$('open').onclick = () => showForm(true);
$('close').onclick = () => showForm(false);
$('next').onclick = () => setStep(step + 1);
$('back').onclick = () => setStep(step - 1);
$('steps').querySelectorAll('button').forEach((button) => { button.onclick = () => setStep(Number(button.dataset.step)); });
$('next-layout').onclick = () => cycle(1);
$('previous-layout').onclick = () => cycle(-1);
for (const id of ['sell-pack', 'pack-size', 'case-size']) $(id).oninput = refreshPackaging;
document.querySelectorAll('[data-dismiss]').forEach((button) => { button.onclick = () => button.closest('dialog').close(); });
document.addEventListener('keydown', (event) => {
  if (event.defaultPrevented || document.querySelector('dialog[open]')) return;
  if (event.key === 'Escape' && !$('overlay').hidden) showForm(false);
  if (event.target.closest('input, textarea, select, [contenteditable]')) return;
  if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
    event.preventDefault();
    cycle(event.key === 'ArrowRight' ? 1 : -1);
  }
});
Object.entries(fixtures).forEach(([key, fixture]) => registerFixture(key, fixture));
const search = new URLSearchParams(location.search);
setVariant(search.get('variant')?.toLowerCase() ?? 'a');
showForm(true);
if (Object.hasOwn(fixtures, search.get('case'))) lookup(fixtures[search.get('case')]);
