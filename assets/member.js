const $ = (id) => document.getElementById(id);
const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

function toast(msg, err) {
  const t = $('toast'); t.textContent = msg; t.className = 'show' + (err ? ' err' : '');
  clearTimeout(t._h); t._h = setTimeout(() => t.className = '', 3000);
}

async function api(action, body) {
  const r = await fetch('api.php?action=' + action, {
    method: body === undefined ? 'GET' : 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  const data = await r.json().catch(() => ({}));
  if (r.status === 401) { location.reload(); throw new Error('Chưa đăng nhập'); }
  if (!r.ok) throw new Error(data.error || r.statusText);
  return data;
}

document.querySelectorAll('#tabs button').forEach((b) => b.onclick = () => {
  document.querySelectorAll('#tabs button').forEach((x) => x.classList.toggle('active', x === b));
  document.querySelectorAll('[data-page]').forEach((p) => p.classList.toggle('hidden', p.dataset.page !== b.dataset.tab));
  if (b.dataset.tab === 'history' && !$('h-rows').innerHTML) { loadHistory(1); loadDaily(); }
  if (b.dataset.tab === 'road') loadRoad();
});

function countdown(ms) {
  if (ms == null) return '';
  const s = Math.max(0, Math.round(ms / 1000));
  const h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60);
  return (h ? h + 'h ' : '') + (h || m ? m + 'p ' : '') + (s % 60) + 's';
}

function streakBadge(g) {
  if (!g.streakType || !g.streakLabel || g.streakLabel === '0') return '';
  const [label, cls] = g.streakType === 'win' ? ['🔥 Thắng', 'b-ok'] : ['🧊 Thua', 'b-err'];
  return `<span class="badge ${cls}">${label} ${esc(g.streakLabel)}</span>`;
}

// ---- Bảng kỳ ----
let board = null, skew = 0;
async function loadBoard() {
  try { board = await api('board'); skew = board.serverTime - Date.now(); } catch { return; }
  if (!$('h-game').options.length) {
    $('h-game').innerHTML = board.games.map((g) => `<option value="${esc(g.id)}">${esc(g.name)}</option>`).join('');
  }
  renderBoard();
}

function renderBoard() {
  if (!board) return;
  const now = Date.now() + skew;
  $('board').innerHTML = board.games.map((g) => `<tr>
    <td><b>${esc(g.name)}</b></td>
    <td>${esc(g.issue)}<div><b>${esc(g.result)}</b></div></td>
    <td>${esc(g.planCheck)}<div class="muted">${g.playResult ? 'về <b>' + esc(g.playResult) + '</b> · ' : ''}${esc(g.planStatus)}</div></td>
    <td>${esc(g.nextIssue)}<div class="muted">${g.nextTime ? new Date(g.nextTime).toLocaleTimeString('vi-VN') : ''}</div></td>
    <td><b>${esc(g.plan)}</b><div class="muted">${esc(g.play)} ${esc(g.planRange)}${g.planTurn ? ' · nuôi kỳ ' + esc(g.planTurn) : ''}</div></td>
    <td>${g.nextTime ? countdown(g.nextTime - now) : ''}</td>
    <td>${streakBadge(g)}</td>
  </tr>`).join('') || '<tr><td colspan="7" class="muted">Chưa có dữ liệu.</td></tr>';
}

// ---- Lịch sử ----
async function loadHistory(page) {
  const game = $('h-game').value;
  if (!game) return;
  try {
    const r = await api(`history&game=${encodeURIComponent(game)}&page=${page}`);
    const st = r.stats;
    $('h-stats').innerHTML = st.plans
      ? `${st.plans} dàn gần nhất: <b>${st.win1}</b> trúng kỳ 1 (${Math.round(st.win1 * 100 / st.plans)}%) · ${st.winLate} trúng muộn · <b>${st.lose}</b> trượt`
      : 'Chưa có dàn nào kết thúc trong lịch sử đã lưu.';
    $('h-rows').innerHTML = r.rows.map((d) => {
      const cls = d.win_status === 1 ? (d.hit_turn <= 1 ? 'b-ok' : 'b-warn') : d.win_status === 0 ? 'b-err' : 'b-off';
      return `<tr><td>${esc(d.issue)}</td><td><b>${esc(d.result)}</b></td><td>${esc(d.plan_check)}</td><td><b>${esc(d.play_result)}</b></td>
        <td><span class="badge ${cls}">${esc(d.plan_status) || '–'}</span></td><td>${esc(d.next_plan)} <span class="muted">${esc(d.next_range)}</span></td>
        <td class="muted">${new Date(d.created_at.replace(' ', 'T')).toLocaleString('vi-VN')}</td></tr>`;
    }).join('') || '<tr><td colspan="7" class="muted">Chưa có lịch sử. Lịch sử được lưu từ khi bật database.</td></tr>';
    const pages = Math.min(20, Math.ceil(r.total / r.perPage));
    $('h-pager').innerHTML = pages > 1 ? Array.from({ length: pages }, (_, i) => `<button class="btn ${i + 1 === r.page ? '' : 'sec'}" onclick="loadHistory(${i + 1})">${i + 1}</button>`).join('') : '';
  } catch (e) { toast(e.message, true); }
}

// ---- Biểu đồ theo ngày ----
let daily = [];
const SVG = 'http://www.w3.org/2000/svg';
const ddmm = (d) => d.slice(8, 10) + '/' + d.slice(5, 7);
const pct = (r) => { const n = r.win1 + r.late + r.lose; return n ? Math.round(r.win1 * 100 / n) : null; };

async function loadDaily() {
  const game = $('h-game').value;
  if (!game) return;
  try {
    daily = (await api(`daily&game=${encodeURIComponent(game)}&days=${$('d-days').value}`)).days;
    renderDaily();
  } catch (e) { toast(e.message, true); }
}

function toggleDailyTable() { $('d-table').classList.toggle('hidden'); }

// Trục y "đẹp": 4 vạch, mỗi vạch là một số tròn × 10^n
function niceMax(v) {
  if (v <= 4) return 4;
  const raw = v / 4, p = Math.pow(10, Math.floor(Math.log10(raw)));
  const step = [1, 1.2, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10].map((m) => m * p).find((m) => m >= raw);
  return step * 4;
}

function svgEl(tag, attrs, parent) {
  const el = document.createElementNS(SVG, tag);
  for (const k in attrs) el.setAttribute(k, attrs[k]);
  if (parent) parent.appendChild(el);
  return el;
}

function tipFor(box) {
  let tip = box.querySelector('.tip');
  if (!tip) { tip = document.createElement('div'); tip.className = 'tip hidden'; box.appendChild(tip); }
  return tip;
}

function showTip(box, tip, html, x) {
  tip.innerHTML = html;
  tip.classList.remove('hidden');
  const w = tip.offsetWidth, bw = box.clientWidth;
  tip.style.left = Math.max(0, Math.min(bw - w, x - w / 2)) + 'px';
  tip.style.top = '-8px';
}

function tipHtml(r) {
  const n = r.win1 + r.late + r.lose, p = pct(r);
  return `<b>${ddmm(r.day)}</b> · ${n} dàn<br>✅ Trúng kỳ 1: <b>${r.win1}</b><br>⏳ Trúng muộn: <b>${r.late}</b><br>❌ Trượt: <b>${r.lose}</b>`
    + (p === null ? '' : `<br>% trúng kỳ 1: <b>${p}%</b>`);
}

// Khung chung: trục y với đường lưới, nhãn ngày ở dưới; trả về hàm toạ độ
function frame(box, height, yMax, yFmt) {
  box.querySelectorAll('svg').forEach((e) => e.remove());
  const W = Math.max(300, box.clientWidth), H = height, L = 34, R = 8, T = 8, B = 22;
  const svg = svgEl('svg', { viewBox: `0 0 ${W} ${H}`, role: 'img' });
  box.prepend(svg);
  const pw = W - L - R, ph = H - T - B, n = daily.length, step = pw / n;
  for (let i = 0; i <= 4; i++) {
    const v = yMax * i / 4, y = T + ph - ph * i / 4;
    svgEl('line', { x1: L, x2: W - R, y1: y, y2: y, stroke: i ? 'var(--grid)' : 'var(--axis)', 'stroke-width': 1 }, svg);
    svgEl('text', { x: L - 6, y: y + 4, 'text-anchor': 'end' }, svg).textContent = yFmt(v);
  }
  const every = Math.ceil(n / Math.floor(pw / 44));
  daily.forEach((r, i) => {
    if ((n - 1 - i) % every === 0) svgEl('text', { x: L + step * (i + 0.5), y: H - 6, 'text-anchor': 'middle' }, svg).textContent = ddmm(r.day);
  });
  return { svg, W, L, T, ph, step, y: (v) => T + ph - ph * v / yMax };
}

// Cột chồng có góc bo 4px ở đầu trên cùng
function barPath(x, y, w, h, round) {
  const r = round ? Math.min(4, w / 2, h) : 0;
  return `M${x},${y + h}V${y + r}` + (r ? `Q${x},${y} ${x + r},${y}H${x + w - r}Q${x + w},${y} ${x + w},${y + r}` : `H${x + w}`) + `V${y + h}Z`;
}

function renderDaily() {
  if (!daily.length) return;
  // --- Cột chồng: số dàn mỗi ngày ---
  const box = $('d-bars'), tip = tipFor(box);
  const max = niceMax(Math.max(...daily.map((r) => r.win1 + r.late + r.lose)));
  const f = frame(box, 200, max, (v) => Math.round(v));
  const bw = Math.min(28, f.step * 0.6);
  daily.forEach((r, i) => {
    const x = f.L + f.step * i + (f.step - bw) / 2;
    const segs = [['win1', 'var(--c-good)'], ['late', 'var(--c-warn)'], ['lose', 'var(--c-bad)']].filter(([k]) => r[k] > 0);
    let acc = 0;
    segs.forEach(([k, color], j) => {
      const y0 = f.y(acc), y1 = f.y(acc + r[k]);
      acc += r[k];
      const gap = j ? 2 : 0; // khe 2px giữa các đoạn
      const h = Math.max(1, y0 - y1 - gap);
      svgEl('path', { d: barPath(x, y1, bw, h, j === segs.length - 1), fill: color }, f.svg);
    });
    const hit = svgEl('rect', { class: 'hit', x: f.L + f.step * i, y: f.T, width: f.step, height: f.ph }, f.svg);
    hit.addEventListener('mouseenter', () => showTip(box, tip, tipHtml(r), (f.L + f.step * (i + 0.5)) * box.clientWidth / f.W));
    hit.addEventListener('mouseleave', () => tip.classList.add('hidden'));
  });

  // --- Đường: % trúng kỳ 1 ---
  const rbox = $('d-rate'), rtip = tipFor(rbox);
  const g = frame(rbox, 150, 100, (v) => v + '%');
  const pts = daily.map((r, i) => [g.L + g.step * (i + 0.5), pct(r)]);
  // Ngày không có dàn nào thì ngắt đường, không nối qua
  let d = '', pen = false;
  pts.forEach(([x, p]) => {
    if (p === null) { pen = false; return; }
    d += (pen ? 'L' : 'M') + x + ',' + g.y(p);
    pen = true;
  });
  svgEl('path', { d, fill: 'none', stroke: 'var(--c-line)', 'stroke-width': 2, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' }, g.svg);
  pts.forEach(([x, p], i) => {
    if (p !== null) svgEl('circle', { cx: x, cy: g.y(p), r: 4, fill: 'var(--c-line)', stroke: 'var(--card)', 'stroke-width': 2 }, g.svg);
    const hit = svgEl('rect', { class: 'hit', x: g.L + g.step * i, y: g.T, width: g.step, height: g.ph }, g.svg);
    hit.addEventListener('mouseenter', () => showTip(rbox, rtip, tipHtml(daily[i]), x * rbox.clientWidth / g.W));
    hit.addEventListener('mouseleave', () => rtip.classList.add('hidden'));
  });
  // Nhãn trực tiếp cho điểm mới nhất có dữ liệu
  const last = [...pts].reverse().find(([, p]) => p !== null);
  if (last) svgEl('text', { x: last[0], y: g.y(last[1]) - 10, 'text-anchor': 'middle', style: 'fill:var(--text);font-weight:600' }, g.svg).textContent = last[1] + '%';

  // --- Bảng số liệu ---
  $('d-table').innerHTML = `<table><thead><tr><th>Ngày</th><th>✅ Trúng kỳ 1</th><th>⏳ Trúng muộn</th><th>❌ Trượt</th><th>Tổng dàn</th><th>% trúng kỳ 1</th></tr></thead><tbody>`
    + [...daily].reverse().map((r) => { const p = pct(r); return `<tr><td>${ddmm(r.day)}</td><td>${r.win1}</td><td>${r.late}</td><td>${r.lose}</td><td>${r.win1 + r.late + r.lose}</td><td>${p === null ? '–' : p + '%'}</td></tr>`; }).join('')
    + '</tbody></table>';
}

let resizeTimer;
window.addEventListener('resize', () => { clearTimeout(resizeTimer); resizeTimer = setTimeout(renderDaily, 150); });

// ---- Bảng cầu WIN / LOST ----
const ROAD_ROWS = 6, CELL = 30;
let roadTimer = null;

// Phân loại 1 dàn: W thắng (trúng trong winMaxTurn kỳ đầu), L thua; trúng muộn tuỳ cài đặt
function classify(it, winMaxTurn, lateAs) {
  const [turnNum, ws, turn] = it;
  if (ws === 1 && turn <= winMaxTurn) return 'W';
  if (ws === 1) return lateAs === 'lose' ? 'L' : 'M';
  return 'L';
}

// Xếp kiểu "big road": cùng kết quả đi xuống, hết hàng hoặc vướng ô thì rẽ phải; đổi kết quả sang cột mới
function layoutRoad(items) {
  const used = new Set(), key = (c, r) => c + ',' + r, out = [];
  let c = -1, r = 0, startCol = -1, prev = null;
  for (const it of items) {
    if (it.type !== prev) {
      c = startCol + 1;
      while (used.has(key(c, 0))) c++;
      startCol = c; r = 0;
    } else if (r + 1 < ROAD_ROWS && !used.has(key(c, r + 1))) {
      r++;
    } else {
      c++;
      while (used.has(key(c, r))) c++;
    }
    used.add(key(c, r));
    out.push({ c, r, it });
    prev = it.type;
  }
  return out;
}

function roadTitle(it) {
  const [turnNum, ws, turn, range, plan, open] = it.raw;
  const st = ws === 0 ? 'Trượt cả dàn' : `Trúng kỳ ${turn}` + (it.type === 'L' ? ' (muộn – tính là thua)' : it.type === 'M' ? ' (muộn – không tính)' : '');
  return `Dàn ${plan} (${range})\nKết thúc kỳ ${turnNum}\nSố về: ${open || '–'}\n${st}`;
}

async function loadRoad() {
  clearTimeout(roadTimer);
  try {
    const r = await api('road&limit=' + $('road-limit').value);
    $('road-late-legend').innerHTML = r.lateAs === 'lose'
      ? '<span class="ball l late">L</span> Trúng muộn – tính là thua'
      : '<span class="ball" style="background:var(--muted)">M</span> Trúng muộn – không tính';
    $('roads').innerHTML = r.games.map((g) => {
      const items = g.road.map((raw) => ({ raw, type: classify(raw, r.winMaxTurn, r.lateAs) }));
      const wins = items.filter((i) => i.type === 'W').length, losses = items.filter((i) => i.type === 'L').length;
      let streak = 0;
      for (let i = items.length - 1; i >= 0 && items[i].type === items[items.length - 1].type; i--) streak++;
      const lastType = items.length ? items[items.length - 1].type : null;
      const placed = layoutRoad(items);
      const cols = Math.max(...placed.map((p) => p.c + 1), 1);
      const cells = placed.map((p, i) => {
        const cls = p.it.type === 'W' ? 'w' : p.it.type === 'L' ? 'l' + (p.it.raw[1] === 1 ? ' late' : '') : '';
        const style = p.it.type === 'M' ? ' style="background:var(--muted)"' : '';
        return `<div class="cell" style="grid-column:${p.c + 1};grid-row:${p.r + 1}"><span class="ball ${cls}${i === placed.length - 1 ? ' last' : ''}"${style} title="${esc(roadTitle(p.it))}">${p.it.type}</span></div>`;
      }).join('');
      const streakTxt = lastType && lastType !== 'M' ? `Đang ${lastType === 'W' ? '🔥 WIN' : '🧊 LOST'} ${streak} ván` : '';
      return `<div class="card">
        <div class="road-head">
          <h2>${esc(g.name)}</h2>
          <span class="pill w">WIN: ${wins}</span><span class="pill l">LOST: ${losses}</span>
          <span class="muted">${streakTxt}${g.plan ? ' · Kế hoạch kỳ tiếp: <b>' + esc(g.plan) + '</b>' : ''}</span>
        </div>
        <div class="road-wrap"><div class="road" style="grid-template-columns:repeat(${cols}, ${CELL}px)">${cells}</div></div>
        ${items.length ? '' : '<p class="muted">Chưa có dữ liệu – bảng sẽ có sau vài phút worker chạy.</p>'}
      </div>`;
    }).join('');
    // Cuộn về cuối để thấy kết quả mới nhất
    document.querySelectorAll('.road-wrap').forEach((w) => { w.scrollLeft = w.scrollWidth; });
  } catch (e) { toast(e.message, true); }
  // Tự làm mới khi đang mở tab Cầu
  if (!document.querySelector('[data-page="road"]').classList.contains('hidden')) roadTimer = setTimeout(loadRoad, 15000);
}

// ---- Tài khoản ----
async function changePassword(btn) {
  btn.disabled = true;
  try {
    await api('change-password', { current: $('pw-cur').value, new: $('pw-new').value });
    $('pw-cur').value = $('pw-new').value = '';
    toast('Đã đổi mật khẩu');
  } catch (e) { toast(e.message, true); } finally { btn.disabled = false; }
}

loadBoard();
setInterval(loadBoard, 5000);
setInterval(renderBoard, 1000);
