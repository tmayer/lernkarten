'use strict';

// ===================== Grundlagen =====================

const app = document.getElementById('app');
let csrf = document.querySelector('meta[name="csrf-token"]').content;
let cleanup = null; // Aufräumfunktion der aktuellen Ansicht

const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

const ICON = {
  back: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>',
  gear: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>',
  undo: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 14L4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/></svg>',
  plus: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>',
};

async function api(action, { method = 'GET', params = {}, body = null, form = null, retry = true } = {}) {
  const qs = new URLSearchParams({ action, ...params });
  const opts = { method, headers: {}, credentials: 'same-origin' };
  if (method !== 'GET') opts.headers['X-CSRF-Token'] = csrf;
  if (form) opts.body = form;
  else if (body) { opts.body = JSON.stringify(body); opts.headers['Content-Type'] = 'application/json'; }
  const res = await fetch('api.php?' + qs, opts);
  let data = null;
  try { data = await res.json(); } catch { /* leer */ }
  if (res.status === 401) { location.reload(); throw new Error('Nicht angemeldet'); }
  if (res.status === 403 && data?.error === 'csrf' && retry) {
    csrf = (await api('csrf')).csrf;
    return api(action, { method, params, body, form, retry: false });
  }
  if (!res.ok) throw new Error(data?.message || `Fehler ${res.status}`);
  return data;
}

let toastTimer;
function toast(msg, ms = 2600) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.hidden = false;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => (t.hidden = true), ms);
}

function topbar(title, { back = null, right = '' } = {}) {
  return `<header class="topbar">
    ${back ? `<a class="icon-btn" href="${back}" aria-label="Zurück">${ICON.back}</a>` : '<span class="icon-btn"></span>'}
    <h1>${esc(title)}</h1>
    ${right || '<span class="icon-btn"></span>'}
  </header>`;
}

function countsHtml(c) {
  const n = (v, cls, label) => `<span class="${v ? cls : 'c-zero'}" title="${label}">${v}</span>`;
  return `<span class="counts">${n(c.new, 'c-new', 'neu')}${n(c.learning, 'c-learn', 'in der Lernphase')}${n(c.review, 'c-due', 'fällig')}</span>`;
}

function formatInterval(seconds) {
  if (seconds < 60) return '< 1 Min';
  if (seconds < 3600) return `${Math.round(seconds / 60)} Min`;
  if (seconds < 86400 * 0.75) return `${Math.round(seconds / 3600)} Std`;
  const d = seconds / 86400;
  if (d < 30) return `${Math.max(1, Math.round(d))} T`;
  if (d < 365) return `${(d / 30.4).toFixed(1).replace('.', ',').replace(',0', '')} Mo`;
  return `${(d / 365).toFixed(1).replace('.', ',').replace(',0', '')} J`;
}

const dateFmt = new Intl.DateTimeFormat('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
const dateTimeFmt = new Intl.DateTimeFormat('de-DE', { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit' });
const fmtDate = ts => (ts ? dateFmt.format(new Date(ts * 1000)) : '–');
const fmtDateTime = ts => (ts ? dateTimeFmt.format(new Date(ts * 1000)) : '–');

function relativeDue(card) {
  if (card.suspended) return 'pausiert';
  if (card.state === 'new') return 'neu';
  const s = card.due_at - Date.now() / 1000;
  if (s <= 0) return 'fällig';
  return 'in ' + formatInterval(s);
}

const STATE_LABEL = { new: 'Neu', learning: 'Lernphase', review: 'Wiederholung', relearning: 'Neu lernen' };
const RATING_LABEL = { 1: 'Nochmal', 2: 'Schwer', 3: 'Gut', 4: 'Einfach' };

// ===================== Router =====================

const routes = {
  '': viewHome,
  learn: viewLearn,
  upload: viewUpload,
  library: viewLibrary,
  card: viewCard,
  settings: viewSettings,
};

function parseHash() {
  const [path, query] = location.hash.replace(/^#\/?/, '').split('?');
  return { path, params: Object.fromEntries(new URLSearchParams(query || '')) };
}

async function route() {
  if (cleanup) { cleanup(); cleanup = null; }
  const { path, params } = parseHash();
  const view = routes[path] || viewHome;
  try {
    await view(params);
  } catch (e) {
    app.innerHTML = `${topbar('Fehler', { back: '#/' })}<div class="page"><p class="empty">${esc(e.message)}</p></div>`;
  }
  window.scrollTo(0, 0);
}
window.addEventListener('hashchange', route);
route();

// ===================== Start =====================

async function viewHome() {
  const d = await api('overview');
  const total = d.counts.new + d.counts.learning + d.counts.review;
  const tagRows = d.tags.map(t => {
    const sum = t.counts.new + t.counts.learning + t.counts.review;
    return `<li><a href="#/learn?tag=${t.id}">
      <span class="grow">${esc(t.name)}</span>
      ${countsHtml(t.counts)}
      <span class="chev">${sum ? '›' : ''}</span></a></li>`;
  }).join('');

  app.innerHTML = `
    ${topbar('Lernkarten', { right: `<a class="icon-btn" href="#/settings" aria-label="Einstellungen">${ICON.gear}</a>` })}
    <div class="page">
      <div class="card hero">
        <div class="big">${total}</div>
        <div class="sub">${total === 1 ? 'Karte' : 'Karten'} für heute</div>
        <div>${countsHtml(d.counts)}</div>
        <a class="btn btn-primary btn-block" href="#/learn" ${total ? '' : 'aria-disabled="true"'}>${total ? 'Alle lernen' : 'Alles erledigt – trotzdem öffnen'}</a>
      </div>
      <div class="quick">
        <a class="btn" href="#/upload">${ICON.plus} Hochladen</a>
        <a class="btn" href="#/library">Archiv</a>
      </div>
      ${d.tags.length ? `<div class="section-title">Nach Kategorie</div><ul class="card list">${tagRows}</ul>` : ''}
      <p class="stats-line">
        <span class="c-new">neu</span> · <span class="c-learn">Lernphase</span> · <span class="c-due">fällig</span><br>
        ${d.total_cards} Karten insgesamt · heute ${d.reviews_today} Wiederholungen
      </p>
    </div>`;
}

// ===================== Lernen =====================

async function viewLearn(params) {
  const tag = params.tag || '';
  let tagName = 'Alle Kategorien';
  if (tag) {
    const tags = await api('tags');
    tagName = tags.find(t => String(t.id) === tag)?.name || 'Kategorie';
  }

  let current = null;
  let shownAt = 0;
  let busy = false;
  let refreshTimer = null;

  async function load() {
    clearTimeout(refreshTimer);
    const d = await api('next', { params: tag ? { tag } : {} });
    current = d.card;
    render(d);
  }

  function render(d) {
    const undoBtn = `<button class="icon-btn" id="undo" aria-label="Rückgängig" ${d.can_undo ? '' : 'disabled'}>${ICON.undo}</button>`;
    if (!d.card) {
      let extra = '';
      if (d.next_learning_due) {
        const s = d.next_learning_due - Date.now() / 1000;
        extra = `<p>Die nächste Karte aus der Lernphase kommt in ${formatInterval(Math.max(60, s))} wieder.</p>`;
        refreshTimer = setTimeout(load, Math.max(30, s - 1200 + 2) * 1000);
      }
      app.innerHTML = `${topbar(tagName, { back: '#/', right: undoBtn })}
        <div class="done"><div class="big">✓</div><h2>Fertig für jetzt</h2>${extra}
        <p><a class="btn" href="#/">Zur Übersicht</a></p></div>`;
      bindUndo();
      return;
    }
    const c = d.card;
    const ratio = c.width && c.height ? `aspect-ratio:${c.width}/${c.height};` : '';
    app.innerHTML = `<div class="learn">
      ${topbar(tagName, { back: '#/', right: undoBtn })}
      <div class="learn-image-wrap"><div>
        <p class="learn-meta">${countsHtml(d.counts)}</p>
        <img class="learn-image" id="learn-img" src="${c.image}" alt="${esc(c.title || 'Lernkarte')}" style="${ratio}">
        <p class="learn-meta"><a href="#/card?id=${c.id}&from=learn${tag ? `&tag=${encodeURIComponent(tag)}` : ''}">${c.title ? esc(c.title) + ' · ' : ''}Details & Verlauf ›</a></p>
      </div></div>
      <nav class="rating-bar" id="rating">
        ${[1, 2, 3, 4].map(g => `<button class="rate rate-${g}" data-g="${g}">${RATING_LABEL[g]}<small>${formatInterval(c.options[g].seconds)}</small></button>`).join('')}
      </nav>
    </div>`;
    shownAt = performance.now();
    document.getElementById('learn-img').addEventListener('click', () => openZoom(c.image));
    document.getElementById('rating').addEventListener('click', e => {
      const b = e.target.closest('.rate');
      if (b) rate(Number(b.dataset.g));
    });
    bindUndo();
  }

  function bindUndo() {
    document.getElementById('undo')?.addEventListener('click', undo);
  }

  async function rate(g) {
    if (!current || busy) return;
    busy = true;
    document.getElementById('rating')?.classList.add('busy');
    try {
      await api('review', { method: 'POST', body: { card_id: current.id, rating: g, duration_ms: Math.round(performance.now() - shownAt) } });
      await load();
    } catch (e) {
      toast(e.message);
      document.getElementById('rating')?.classList.remove('busy');
    } finally {
      busy = false;
    }
  }

  async function undo() {
    if (busy) return;
    busy = true;
    try {
      const r = await api('undo', { method: 'POST' });
      if (r.ok) toast('Letzte Bewertung rückgängig gemacht');
      await load();
    } catch (e) { toast(e.message); } finally { busy = false; }
  }

  function onKey(e) {
    if (document.querySelector('.zoom')) return;
    if (['1', '2', '3', '4'].includes(e.key)) rate(Number(e.key));
    else if (e.key === ' ') { e.preventDefault(); rate(3); }
    else if (e.key === 'z' || e.key === 'u') undo();
  }
  document.addEventListener('keydown', onKey);
  cleanup = () => { document.removeEventListener('keydown', onKey); clearTimeout(refreshTimer); closeZoom(); };

  await load();
}

function openZoom(src) {
  const z = document.createElement('div');
  z.className = 'zoom';
  z.innerHTML = `<img src="${src}" alt=""><button class="zoom-close" aria-label="Schließen">×</button>`;
  z.querySelector('img').addEventListener('click', () => z.classList.toggle('wide'));
  z.querySelector('.zoom-close').addEventListener('click', closeZoom);
  document.body.appendChild(z);
}
function closeZoom() { document.querySelector('.zoom')?.remove(); }
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeZoom(); });

// ===================== Hochladen =====================

async function viewUpload() {
  const allTags = await api('tags');
  const selected = new Set(JSON.parse(localStorage.getItem('lk_last_tags') || '[]').filter(n => allTags.some(t => t.name === n)));
  let files = []; // {file, url, status}

  app.innerHTML = `${topbar('Hochladen', { back: '#/' })}
    <div class="page">
      <label class="dropzone" id="drop">
        <strong>Bilder auswählen</strong>
        <span>Screenshots oder Fotos, mehrere gleichzeitig möglich</span>
        <input type="file" id="file" accept="image/*" multiple hidden>
      </label>
      <div class="preview-grid" id="previews"></div>
      <div class="section-title">Kategorien</div>
      <div class="card pad">
        <div class="chips" id="tagchips"></div>
        <div class="tag-add">
          <input class="input" id="newtag" placeholder="Neue Kategorie" enterkeyhint="done" autocapitalize="sentences">
          <button class="btn" id="addtag">Hinzufügen</button>
        </div>
      </div>
      <div class="progress" id="progress" hidden><div></div></div>
      <p style="margin-top:16px"><button class="btn btn-primary btn-block" id="send" disabled>Hochladen</button></p>
    </div>`;

  const $ = id => document.getElementById(id);
  const tagNames = () => [...new Set([...allTags.map(t => t.name), ...selected])].sort((a, b) => a.localeCompare(b, 'de'));

  function renderTags() {
    $('tagchips').innerHTML = tagNames().map(n =>
      `<button class="chip ${selected.has(n) ? 'active' : ''}" data-n="${esc(n)}">${esc(n)}</button>`).join('')
      || '<span class="help">Noch keine Kategorien – leg unten eine an.</span>';
  }
  function renderPreviews() {
    $('previews').innerHTML = files.map((f, i) => `<div class="preview">
      <img src="${f.url}" alt="">
      ${f.status ? `<div class="state">${esc(f.status)}</div>` : `<button data-i="${i}" aria-label="Entfernen">×</button>`}
    </div>`).join('');
    $('send').disabled = !files.length || files.some(f => f.status === 'lädt …');
    $('send').textContent = files.length ? `${files.length} ${files.length === 1 ? 'Bild' : 'Bilder'} hochladen` : 'Hochladen';
  }
  function addFiles(list) {
    for (const file of list) {
      if (!file.type.startsWith('image/') && !/\.(heic|heif)$/i.test(file.name)) continue;
      files.push({ file, url: URL.createObjectURL(file), status: '' });
    }
    renderPreviews();
  }
  function addTag() {
    const n = $('newtag').value.trim().replace(/[|,]+/g, ' ');
    if (!n) return;
    const existing = tagNames().find(x => x.toLowerCase() === n.toLowerCase());
    selected.add(existing || n);
    $('newtag').value = '';
    renderTags();
  }

  $('file').addEventListener('change', e => { addFiles(e.target.files); e.target.value = ''; });
  const drop = $('drop');
  drop.addEventListener('dragover', e => { e.preventDefault(); drop.classList.add('drag'); });
  drop.addEventListener('dragleave', () => drop.classList.remove('drag'));
  drop.addEventListener('drop', e => { e.preventDefault(); drop.classList.remove('drag'); addFiles(e.dataTransfer.files); });
  $('previews').addEventListener('click', e => {
    const b = e.target.closest('button[data-i]');
    if (!b) return;
    const [f] = files.splice(Number(b.dataset.i), 1);
    URL.revokeObjectURL(f.url);
    renderPreviews();
  });
  $('tagchips').addEventListener('click', e => {
    const c = e.target.closest('.chip');
    if (!c) return;
    selected.has(c.dataset.n) ? selected.delete(c.dataset.n) : selected.add(c.dataset.n);
    renderTags();
  });
  $('addtag').addEventListener('click', addTag);
  $('newtag').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); addTag(); } });

  $('send').addEventListener('click', async () => {
    const tags = [...selected];
    localStorage.setItem('lk_last_tags', JSON.stringify(tags));
    const bar = $('progress');
    bar.hidden = false;
    let ok = 0;
    const todo = files.filter(f => f.status !== 'fertig');
    for (const [i, f] of todo.entries()) {
      f.status = 'lädt …';
      renderPreviews();
      try {
        const blob = await prepareImage(f.file);
        const fd = new FormData();
        fd.append('files[]', blob, f.file.name.replace(/\.\w+$/, '') + (blob.type === 'image/jpeg' ? '.jpg' : ''));
        fd.append('tags', JSON.stringify(tags));
        const r = await api('upload', { method: 'POST', form: fd });
        if (r.errors?.length) throw new Error(r.errors[0]);
        f.status = 'fertig';
        ok++;
      } catch (e) {
        f.status = 'Fehler';
        toast(e.message, 5000);
      }
      bar.firstElementChild.style.width = `${((i + 1) / todo.length) * 100}%`;
      renderPreviews();
    }
    if (ok) toast(`${ok} ${ok === 1 ? 'Karte' : 'Karten'} gespeichert`);
    files.filter(f => f.status === 'fertig').forEach(f => URL.revokeObjectURL(f.url));
    files = files.filter(f => f.status !== 'fertig');
    files.forEach(f => (f.status = ''));
    renderPreviews();
    setTimeout(() => { bar.hidden = true; bar.firstElementChild.style.width = 0; }, 800);
  });

  cleanup = () => files.forEach(f => URL.revokeObjectURL(f.url));
  renderTags();
  renderPreviews();
}

/** Verkleinert große Bilder im Browser und speichert sie als JPEG (wandelt dabei auch HEIC um). */
async function prepareImage(file, maxWidth = 1400, maxHeight = 14000) {
  let source;
  try {
    source = await createImageBitmap(file);
  } catch {
    try {
      source = await new Promise((res, rej) => {
        const img = new Image();
        img.onload = () => res(img);
        img.onerror = rej;
        img.src = URL.createObjectURL(file);
      });
    } catch {
      return file; // nicht lesbar → Original schicken, der Server entscheidet
    }
  }
  const w = source.width, h = source.height;
  // Breite begrenzen (lange Bilder bleiben scharf); iOS erlaubt Canvas nur bis ca. 16 Megapixel
  const scale = Math.min(1, maxWidth / w, maxHeight / h, Math.sqrt(16e6 / (w * h)));
  const canvas = document.createElement('canvas');
  canvas.width = Math.round(w * scale);
  canvas.height = Math.round(h * scale);
  const ctx = canvas.getContext('2d');
  ctx.fillStyle = '#fff';
  ctx.fillRect(0, 0, canvas.width, canvas.height);
  ctx.drawImage(source, 0, 0, canvas.width, canvas.height);
  source.close?.();
  const blob = await new Promise(res => canvas.toBlob(res, 'image/jpeg', 0.86));
  return blob || file;
}

// ===================== Archiv =====================

async function viewLibrary(params) {
  const state = {
    tag: params.tag || '',
    sort: sessionStorage.getItem('lk_sort') || 'uploaded',
    q: '',
  };
  const tags = await api('tags');

  app.innerHTML = `${topbar('Archiv', { back: '#/', right: `<a class="icon-btn" href="#/upload" aria-label="Hochladen">${ICON.plus}</a>` })}
    <div class="page">
      <div class="filters">
        <input class="input" id="q" type="search" placeholder="Titel oder Notiz suchen">
        <select class="input" id="sort">
          <option value="uploaded">Neueste</option>
          <option value="due">Fälligkeit</option>
          <option value="lapses">Oft vergessen</option>
          <option value="difficulty">Schwierigste</option>
        </select>
      </div>
      <div class="chips scroll" id="tagfilter"></div>
      <p class="count-line" id="countline"></p>
      <div class="grid" id="grid"></div>
    </div>`;

  const $ = id => document.getElementById(id);
  $('sort').value = state.sort;

  function renderFilter() {
    $('tagfilter').innerHTML = [{ id: '', name: 'Alle' }, ...tags].map(t =>
      `<button class="chip ${String(t.id) === state.tag ? 'active' : ''}" data-id="${t.id}">${esc(t.name)}</button>`).join('');
  }
  async function load() {
    const p = { sort: state.sort };
    if (state.tag) p.tag = state.tag;
    if (state.q) p.q = state.q;
    const cards = await api('cards', { params: p });
    $('countline').textContent = `${cards.length} ${cards.length === 1 ? 'Karte' : 'Karten'}`;
    $('grid').innerHTML = cards.length ? cards.map(c => {
      const due = relativeDue(c);
      const cls = c.state === 'new' ? 'b-new' : c.state !== 'review' ? 'b-learn' : due === 'fällig' ? 'b-due' : '';
      return `<a class="tile ${c.suspended ? 'paused' : ''}" href="#/card?id=${c.id}">
        <img src="${c.thumb}" alt="${esc(c.title)}" loading="lazy">
        <span class="badge ${cls}">${esc(due)}</span>
        ${c.lapses >= 2 ? `<span class="lapse" title="${c.lapses}× vergessen">${c.lapses}×</span>` : ''}
      </a>`;
    }).join('') : '<p class="empty">Keine Karten gefunden.</p>';
  }

  $('tagfilter').addEventListener('click', e => {
    const c = e.target.closest('.chip');
    if (!c) return;
    state.tag = c.dataset.id;
    history.replaceState(null, '', state.tag ? `#/library?tag=${state.tag}` : '#/library');
    renderFilter();
    load();
  });
  $('sort').addEventListener('change', e => { state.sort = e.target.value; sessionStorage.setItem('lk_sort', state.sort); load(); });
  let t;
  $('q').addEventListener('input', e => { clearTimeout(t); t = setTimeout(() => { state.q = e.target.value.trim(); load(); }, 250); });

  renderFilter();
  await load();
}

// ===================== Karte =====================

async function viewCard(params) {
  const c = await api('card', { params: { id: params.id } });
  const allTags = await api('tags');
  const selected = new Set(c.tags.map(t => t.name));
  const r = c.retrievability;

  const history = c.reviews.map(rv => `<tr>
      <td>${fmtDateTime(rv.reviewed_at)}</td>
      <td><span class="pill pill-${rv.rating}">${RATING_LABEL[rv.rating]}</span></td>
      <td>${rv.interval_days ? rv.interval_days + ' T' : formatInterval(rv.next_due_at - rv.reviewed_at)}</td>
      <td>${rv.duration_ms != null ? Math.round(rv.duration_ms / 1000) + ' s' : '–'}</td>
    </tr>`).join('');

  // Bewertungen zusammenfassen: Schwer, Gut, Einfach zählen als gewusst
  const byRating = { 1: 0, 2: 0, 3: 0, 4: 0 };
  c.reviews.forEach(rv => byRating[rv.rating]++);
  const total = c.reviews.length;
  const known = total - byRating[1];
  const quote = total ? Math.round((known / total) * 100) : null;

  const back = params.from === 'learn' ? (params.tag ? `#/learn?tag=${encodeURIComponent(params.tag)}` : '#/learn') : '#/library';

  app.innerHTML = `${topbar(c.title || 'Karte', { back })}
    <div class="page">
      <div class="card summary">
        <div class="summary-top">
          <div><div class="big-num">${total}</div><div class="help">× gesehen</div></div>
          <div><div class="big-num">${quote != null ? quote + ' %' : '–'}</div><div class="help">gewusst${total ? ` (${known} von ${total})` : ''}</div></div>
          <div><div class="big-num">${c.lapses}</div><div class="help">× vergessen</div></div>
        </div>
        <div class="rating-counts">
          ${[1, 2, 3, 4].map(g => `<div class="rc rc-${g}"><b>${byRating[g]}</b><span>${RATING_LABEL[g]}</span></div>`).join('')}
        </div>
      </div>

      <img class="detail-image" id="img" src="${c.image}" alt="">

      <div class="section-title">Lernstand</div>
      <div class="card">
        <dl class="facts">
          <dt>Status</dt><dd>${c.suspended ? 'pausiert' : STATE_LABEL[c.state]}</dd>
          <dt>Nächste Wiederholung</dt><dd>${c.state === 'new' ? '–' : fmtDateTime(c.due_at)} ${c.state !== 'new' ? `(${esc(relativeDue(c))})` : ''}</dd>
          <dt>Hochgeladen</dt><dd>${fmtDateTime(c.uploaded_at)}</dd>
          <dt>Zuletzt gelernt</dt><dd>${fmtDateTime(c.last_reviewed_at)}</dd>
          <dt>Wiederholungen</dt><dd>${c.reps}</dd>
          <dt>Vergessen</dt><dd>${c.lapses}×</dd>
          <dt>Aktueller Abstand</dt><dd>${c.interval_days ? c.interval_days + ' Tage' : '–'}</dd>
          <dt>Stabilität</dt><dd>${c.stability != null ? c.stability.toLocaleString('de-DE') + ' Tage' : '–'}</dd>
          <dt>Schwierigkeit</dt><dd>${c.difficulty != null ? c.difficulty.toLocaleString('de-DE', { maximumFractionDigits: 1 }) + ' / 10' : '–'}</dd>
          <dt>Erinnerung jetzt</dt><dd>${r != null ? Math.round(r * 100) + ' %<div class="r-meter"><div style="width:' + Math.round(r * 100) + '%"></div></div>' : '–'}</dd>
        </dl>
      </div>

      <div class="section-title">Details</div>
      <div class="card pad">
        <label class="field"><span>Titel</span><input class="input" id="title" value="${esc(c.title)}" maxlength="200"></label>
        <label class="field"><span>Notiz</span><textarea class="input" id="note" maxlength="5000">${esc(c.note)}</textarea></label>
        <span class="help">Kategorien</span>
        <div class="chips" id="tagchips" style="margin-top:6px"></div>
        <div class="tag-add">
          <input class="input" id="newtag" placeholder="Neue Kategorie" enterkeyhint="done">
          <button class="btn" id="addtag">Hinzufügen</button>
        </div>
        <p style="margin:16px 0 0"><button class="btn btn-primary btn-block" id="save">Speichern</button></p>
      </div>

      <div class="section-title">Verlauf (${c.reviews.length})</div>
      <div class="card">
        ${c.reviews.length ? `<table class="history"><thead><tr><th>Wann</th><th>Bewertung</th><th>Abstand</th><th>Dauer</th></tr></thead><tbody>${history}</tbody></table>`
          : '<p class="empty">Noch nie gelernt.</p>'}
      </div>

      <div class="section-title">Aktionen</div>
      <div class="btn-row">
        <button class="btn" id="suspend">${c.suspended ? 'Fortsetzen' : 'Pausieren'}</button>
        <button class="btn" id="reset">Lernstand zurücksetzen</button>
        <button class="btn btn-danger" id="delete">Löschen</button>
      </div>
    </div>`;

  const $ = id => document.getElementById(id);
  const tagNames = () => [...new Set([...allTags.map(t => t.name), ...selected])].sort((a, b) => a.localeCompare(b, 'de'));
  const renderTags = () => {
    $('tagchips').innerHTML = tagNames().map(n => `<button class="chip ${selected.has(n) ? 'active' : ''}" data-n="${esc(n)}">${esc(n)}</button>`).join('');
  };
  renderTags();

  $('img').addEventListener('click', () => openZoom(c.image));
  $('tagchips').addEventListener('click', e => {
    const b = e.target.closest('.chip');
    if (!b) return;
    selected.has(b.dataset.n) ? selected.delete(b.dataset.n) : selected.add(b.dataset.n);
    renderTags();
  });
  const addTag = () => {
    const n = $('newtag').value.trim();
    if (!n) return;
    selected.add(tagNames().find(x => x.toLowerCase() === n.toLowerCase()) || n);
    $('newtag').value = '';
    renderTags();
  };
  $('addtag').addEventListener('click', addTag);
  $('newtag').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); addTag(); } });

  const post = (action, body) => api(action, { method: 'POST', body: { id: c.id, ...body } });
  $('save').addEventListener('click', async () => {
    try {
      await post('card_update', { title: $('title').value, note: $('note').value, tags: [...selected] });
      toast('Gespeichert');
    } catch (e) { toast(e.message); }
  });
  $('suspend').addEventListener('click', async () => { await post('card_update', { suspended: !c.suspended }); route(); });
  $('reset').addEventListener('click', async () => {
    if (!confirm('Lernstand und Verlauf dieser Karte zurücksetzen? Sie gilt danach wieder als neu.')) return;
    await post('card_reset');
    toast('Zurückgesetzt');
    route();
  });
  $('delete').addEventListener('click', async () => {
    if (!confirm('Karte samt Bild und Verlauf endgültig löschen?')) return;
    await post('card_delete');
    toast('Gelöscht');
    location.hash = '#/library';
  });
}

// ===================== Einstellungen =====================

async function viewSettings() {
  const s = await api('settings');
  const tags = await api('tags');
  const retentions = [0.8, 0.85, 0.9, 0.93, 0.95];

  app.innerHTML = `${topbar('Einstellungen', { back: '#/' })}
    <div class="page">
      <div class="section-title">Lernen</div>
      <div class="card pad">
        <label class="field"><span>Ziel: Wie viel soll ich beim Wiederholen noch wissen?</span>
          <select class="input" id="ret">${retentions.map(r => `<option value="${r}" ${Math.abs(r - s.desired_retention) < 0.001 ? 'selected' : ''}>${Math.round(r * 100)} %${r === 0.9 ? ' (empfohlen)' : ''}</option>`).join('')}</select>
        </label>
        <p class="help">Höher heißt: Karten kommen früher wieder, du wiederholst mehr und vergisst weniger.</p>
        <label class="field"><span>Neue Karten pro Tag</span><input class="input" id="npd" type="number" min="0" max="500" inputmode="numeric" value="${s.new_per_day}"></label>
        <button class="btn btn-primary btn-block" id="save">Speichern</button>
      </div>

      <div class="section-title">Kategorien</div>
      <ul class="card list" id="taglist">
        ${tags.length ? tags.map(t => `<li><div class="row"><span class="grow">${esc(t.name)}</span>
          <button class="btn" data-rename="${t.id}" data-name="${esc(t.name)}">Umbenennen</button>
          <button class="btn btn-danger" data-del="${t.id}" data-name="${esc(t.name)}">Entfernen</button></div></li>`).join('')
          : '<li><div class="row help">Noch keine Kategorien.</div></li>'}
      </ul>

      <div class="section-title">iPhone-Kurzbefehl</div>
      <div class="card pad help">
        Damit lädst du einen Screenshot direkt über „Teilen“ hoch, ohne die App zu öffnen.
        <ol>
          <li>Kurzbefehle-App → neuer Kurzbefehl, unter Details „Im Share-Sheet anzeigen“ (Typ: Bilder).</li>
          <li>Aktion <b>Bild konvertieren</b> → JPEG.</li>
          <li>Aktion <b>Inhalte von URL abrufen</b>: URL unten, Methode POST, Header <code>X-Api-Token</code> = Token unten, Hauptteil „Formular“, Feld <code>file</code> (Typ Datei) = konvertiertes Bild, optional Feld <code>tags</code> (Text), z. B. <code>Spanisch</code>.</li>
        </ol>
        <p>URL</p><span class="code">${esc(s.upload_url)}</span>
        <p>Token</p><span class="code">${esc(s.api_token)}</span>
        <p><button class="btn" id="regen">Neuen Token erzeugen</button></p>
      </div>

      <div class="section-title">Anmeldung</div>
      <div class="card pad">
        <p class="help">Angemeldete Geräte: ${s.sessions}</p>
        <div class="btn-row">
          <button class="btn" id="logout">Abmelden</button>
          <button class="btn btn-danger" id="logoutall">Alle Geräte abmelden</button>
        </div>
      </div>
    </div>`;

  const $ = id => document.getElementById(id);
  $('save').addEventListener('click', async () => {
    await api('settings', { method: 'POST', body: { desired_retention: Number($('ret').value), new_per_day: Number($('npd').value) } });
    toast('Gespeichert');
  });
  $('taglist').addEventListener('click', async e => {
    const rn = e.target.closest('[data-rename]');
    const del = e.target.closest('[data-del]');
    if (rn) {
      const name = prompt('Neuer Name', rn.dataset.name);
      if (!name || name.trim() === rn.dataset.name) return;
      await api('tag_rename', { method: 'POST', body: { id: Number(rn.dataset.rename), name } });
      route();
    } else if (del) {
      if (!confirm(`Kategorie „${del.dataset.name}“ entfernen? Die Karten bleiben erhalten.`)) return;
      await api('tag_delete', { method: 'POST', body: { id: Number(del.dataset.del) } });
      route();
    }
  });
  $('regen').addEventListener('click', async () => {
    if (!confirm('Neuen Token erzeugen? Der bisherige Kurzbefehl funktioniert dann nicht mehr.')) return;
    await api('settings', { method: 'POST', body: { regenerate_token: true } });
    route();
  });
  $('logout').addEventListener('click', async () => { await api('logout', { method: 'POST', body: {} }); location.reload(); });
  $('logoutall').addEventListener('click', async () => {
    if (!confirm('Alle Geräte abmelden (auch dieses)?')) return;
    await api('logout', { method: 'POST', body: { everywhere: true } });
    location.reload();
  });
}
