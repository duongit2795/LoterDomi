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
  if (b.dataset.tab === 'history' && !$('h-rows').innerHTML) loadHistory(1);
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
