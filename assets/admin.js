let cfg = null;
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

// Chạy hàm async, khoá nút trong lúc chờ và báo lỗi
async function run(btn, fn) {
  if (btn) btn.disabled = true;
  try { return await fn(); } catch (e) { toast(e.message, true); } finally { if (btn) btn.disabled = false; }
}

async function logout() { await api('logout', {}); location.reload(); }

document.querySelectorAll('#tabs button').forEach((b) => b.onclick = () => {
  document.querySelectorAll('#tabs button').forEach((x) => x.classList.toggle('active', x === b));
  document.querySelectorAll('[data-page]').forEach((p) => p.classList.toggle('hidden', p.dataset.page !== b.dataset.tab));
  if (b.dataset.tab === 'update') loadUpdate();
  if (b.dataset.tab === 'members') loadMembersTab();
});

async function boot() {
  cfg = await api('config');
  fill(); refresh(); loadSystem();
}

function fill() {
  $('tg-token').value = cfg.telegram.token;
  $('tg-chats').value = cfg.telegram.chatIds.join('\n');
  $('tg-sub').checked = cfg.telegram.allowSubscribe;
  $('tg-parse').value = cfg.telegram.parseMode;
  $('tg-api').value = cfg.telegram.apiBase;
  const m = cfg.monitor;
  $('m-enabled').checked = m.enabled; $('m-interval').value = m.intervalSeconds; $('m-remind').value = m.remindBeforeSeconds;
  $('m-timeout').value = m.requestTimeoutSeconds; $('m-tz').value = cfg.timezone;
  $('m-result').checked = m.notifyResult; $('m-upcoming').checked = m.notifyUpcoming; $('m-combine').checked = m.combineNext; $('m-img').checked = m.roadImage.enabled; $('m-img-every').value = m.roadImage.every;
  const st = m.streak;
  $('st-enabled').checked = st.enabled; $('st-win').value = st.winMin; $('st-lose').value = st.loseMin; $('st-game').checked = st.alsoGameTargets; $('st-targets').value = st.targets.join(', ');
  $('tpl-streak').value = cfg.templates.streak;
  renderMethods(); loadMethodStats();
  $('pr-enabled').checked = cfg.publicRoad.enabled; $('pr-key').value = cfg.publicRoad.key; updatePublicLink();
  $('tpl-result').value = cfg.templates.result; $('tpl-upcoming').value = cfg.templates.upcoming; $('tpl-next').value = cfg.templates.next;
  $('g-headers').value = JSON.stringify(cfg.globalHeaders, null, 2);
  $('u-repo').value = cfg.update.repo; $('u-branch').value = cfg.update.branch; $('u-token').value = cfg.update.token;
  renderGames();
}

// ---- Games ----
const PATHS = [['issue', 'Mã kỳ vừa mở'], ['result', 'Kết quả'], ['nextIssue', 'Mã kỳ sắp mở'], ['nextTime', 'Thời gian mở kỳ sắp tới']];
const sel = (f, cur, opts) => `<select data-f="${f}">${opts.map(([v, l]) => `<option value="${v}" ${String(cur) === String(v) ? 'selected' : ''}>${l}</option>`).join('')}</select>`;

function renderGames() {
  $('games').innerHTML = cfg.games.map((g, i) => {
    const v = g.vnlp || {};
    const body = g.type === 'vnlp' ? `
      <div class="grid">
        <div><label>Game ID (vnlotteryplan)</label><input data-v="gameId" value="${esc(v.gameId)}"></div>
        <div><label>Kiểu chơi (play code)</label><input data-v="play" value="${esc(v.play)}"></div>
        <div><label>Tên kiểu chơi</label><input data-v="playName" value="${esc(v.playName)}"></div>
        <div><label>Kế hoạch nuôi</label><select data-v="turnNumType">${[3, 4, 5].map((n) => `<option value="${n}" ${+v.turnNumType === n ? 'selected' : ''}>${n} kỳ</option>`).join('')}</select></div>
        <div><label>Loại dàn số (planNumberType)</label><select data-v="planNumberType">${[1, 2, 3, 4, 5].map((n) => `<option ${+v.planNumberType === n ? 'selected' : ''}>${n}</option>`).join('')}</select></div>
      </div>` : `
      <div class="grid">
        <div><label>Method</label>${sel('method', g.method, [['GET', 'GET'], ['POST', 'POST']])}</div>
        <div><label>Kiểu thời gian</label>${sel('timeMode', g.timeMode, [['auto', 'Tự nhận'], ['seconds', 'Unix giây'], ['milliseconds', 'Unix ms'], ['remaining', 'Số giây còn lại']])}</div>
      </div>
      <label>URL API</label><input data-f="url" value="${esc(g.url)}" placeholder="https://...">
      <div class="grid">${PATHS.map(([k, l]) => `<div><label>${l}</label><input data-p="${k}" value="${esc(g.paths[k])}" placeholder="vd: data.${k}"></div>`).join('')}</div>
      <div class="grid">
        <div><label>Headers riêng (JSON)</label><textarea data-f="headers">${esc(JSON.stringify(g.headers || {}, null, 2))}</textarea></div>
        <div><label>Body (cho POST)</label><textarea data-f="body">${esc(g.body)}</textarea></div>
      </div>`;
    return `
    <div class="game" data-i="${i}">
      <div class="grid">
        <div><label>Tên game</label><input data-f="name" value="${esc(g.name)}"></div>
        <div><label>Loại nguồn</label><select data-f="type" onchange="changeType()">${[['vnlp', 'vnlotteryplan.com (có sẵn)'], ['custom', 'API tuỳ chỉnh']].map(([t, l]) => `<option value="${t}" ${g.type === t ? 'selected' : ''}>${l}</option>`).join('')}</select></div>
      </div>
      <label>Gửi tới (chat_id hoặc chat_id:chủ_đề_id, cách nhau bởi dấu phẩy) – để trống = gửi tới các chat chung ở tab Telegram</label>
      <input data-f="targets" value="${esc((g.targets || []).join(', '))}" placeholder="trống = chat chung · vd: -1001234567890:12">
      ${body}
      <div class="row">
        <label class="check" style="margin:0"><input type="checkbox" data-f="enabled" ${g.enabled ? 'checked' : ''}> Bật</label>
        <button class="btn sec" onclick="testGame(${i}, this)">Thử gọi API</button>
        <button class="btn danger" onclick="removeGame(${i})">Xoá</button>
      </div>
    </div>`;
  }).join('') || '<p class="muted">Chưa có game nào. Bấm "Nhập game từ vnlotteryplan".</p>';
}

function readGames() {
  document.querySelectorAll('.game').forEach((el) => {
    const g = cfg.games[el.dataset.i];
    el.querySelectorAll('[data-f]').forEach((inp) => {
      const f = inp.dataset.f;
      if (f === 'enabled') g.enabled = inp.checked;
      else if (f === 'targets') g.targets = inp.value.split(/[\s,]+/).filter(Boolean);
      else if (f === 'headers') {
        try { g.headers = JSON.parse(inp.value || '{}'); } catch { throw new Error(`Headers của "${g.name}" không phải JSON hợp lệ`); }
      } else g[f] = inp.value.trim();
    });
    g.paths = g.paths || {};
    el.querySelectorAll('[data-p]').forEach((inp) => g.paths[inp.dataset.p] = inp.value.trim());
    g.vnlp = g.vnlp || {};
    el.querySelectorAll('[data-v]').forEach((inp) => {
      const k = inp.dataset.v;
      g.vnlp[k] = ['turnNumType', 'planNumberType'].includes(k) ? +inp.value : inp.value.trim();
    });
  });
}

function changeType() {
  try { readGames(); } catch (e) { return toast(e.message, true); }
  renderGames();
}

function addGame() {
  try { readGames(); } catch (e) { return toast(e.message, true); }
  cfg.games.push({ name: 'Game mới', type: 'custom', enabled: true, url: '', method: 'GET', headers: {}, body: '', timeMode: 'auto', paths: { issue: '', result: '', nextIssue: '', nextTime: '' } });
  renderGames();
}

function removeGame(i) {
  if (!confirm('Xoá game này?')) return;
  try { readGames(); } catch {}
  cfg.games.splice(i, 1); renderGames();
}

async function importVnlp() {
  await run(null, async () => {
    readGames();
    const { games, plays } = await api('vnlp-games');
    const play = plays[0] || { play: 'DI', playName: 'Đuôi' };
    const have = new Set(cfg.games.filter((g) => g.type === 'vnlp').map((g) => String(g.vnlp.gameId)));
    const fresh = games.filter((g) => !have.has(String(g.gameId)));
    if (!fresh.length) return toast('Tất cả game đã có trong danh sách');
    for (const g of fresh) {
      cfg.games.push({ name: g.name, type: 'vnlp', enabled: true, vnlp: { gameId: g.gameId, play: play.play, playName: play.playName, turnNumType: 3, planNumberType: 1 }, paths: {}, headers: {} });
    }
    renderGames();
    toast(`Đã thêm ${fresh.length} game – nhớ bấm Lưu`);
  });
}

async function saveGames() {
  await run(null, async () => {
    readGames();
    cfg = await api('save', { games: cfg.games });
    renderGames(); toast('Đã lưu');
  });
}

async function testGame(i, btn) {
  await run(btn, async () => {
    readGames();
    const r = await api('test-fetch', { game: cfg.games[i] });
    $('test-box').classList.remove('hidden');
    const ex = { ...r.extracted, nextTimeText: r.extracted.nextTime ? new Date(r.extracted.nextTime).toLocaleString('vi-VN') : null };
    $('test-ext').textContent = JSON.stringify(ex, null, 2);
    $('test-raw').textContent = JSON.stringify(r.raw, null, 2);
    $('test-box').scrollIntoView({ behavior: 'smooth' });
  });
}

// ---- Telegram ----
async function saveTele() {
  await run(null, async () => {
    cfg = await api('save', { telegram: {
      token: $('tg-token').value.trim(),
      chatIds: $('tg-chats').value.split(/[\s,]+/).filter(Boolean),
      allowSubscribe: $('tg-sub').checked,
      parseMode: $('tg-parse').value,
      apiBase: $('tg-api').value.trim() || 'https://api.telegram.org',
    } });
    toast('Đã lưu');
  });
}

async function botInfo() {
  try { const b = await api('bot-info', { token: $('tg-token').value.trim() }); $('bot-info').textContent = `✅ @${b.username} (${b.first_name})`; }
  catch (e) { $('bot-info').textContent = '❌ ' + e.message; }
}

async function testSend() {
  await run(null, async () => { await api('test-send', {}); toast('Đã gửi tin thử'); });
}

// ---- Settings ----
async function saveSettings(silent) {
  let headers;
  try { headers = JSON.parse($('g-headers').value || '{}'); } catch { return toast('Header chung không phải JSON hợp lệ', true); }
  await run(null, async () => {
    cfg = await api('save', {
      monitor: {
        enabled: $('m-enabled').checked, intervalSeconds: +$('m-interval').value || 5,
        remindBeforeSeconds: +$('m-remind').value || 0, requestTimeoutSeconds: +$('m-timeout').value || 15,
        notifyResult: $('m-result').checked, notifyUpcoming: $('m-upcoming').checked, combineNext: $('m-combine').checked, roadImage: { enabled: $('m-img').checked, every: Math.max(1, +$('m-img-every').value || 1) },
        streak: {
          enabled: $('st-enabled').checked, winMin: +$('st-win').value || 0, loseMin: +$('st-lose').value || 0, alsoGameTargets: $('st-game').checked,
          targets: $('st-targets').value.split(/[\s,]+/).filter(Boolean),
        },
      },
      publicRoad: { enabled: $('pr-enabled').checked, key: $('pr-key').value.trim() },
      timezone: $('m-tz').value.trim() || 'Asia/Ho_Chi_Minh',
      templates: { result: $('tpl-result').value, upcoming: $('tpl-upcoming').value, next: $('tpl-next').value, streak: $('tpl-streak').value },
      globalHeaders: headers,
    });
    if (!silent) toast('Đã lưu');
  });
}

async function preview(kind) {
  await saveSettings(true);
  await run(null, async () => {
    const r = await api('preview', { kind, gameId: cfg.games[0]?.id });
    $('tpl-preview').classList.remove('hidden'); $('tpl-preview').textContent = r.text;
  });
}

async function resetState() {
  if (!confirm('Xoá trạng thái theo dõi? Bot sẽ ghi nhận lại kỳ hiện tại mà không gửi tin.')) return;
  await run(null, async () => { await api('reset-state', {}); toast('Đã xoá'); });
}

async function changePassword() {
  await run(null, async () => {
    await api('change-password', { current: $('pw-cur').value, new: $('pw-new').value });
    $('pw-cur').value = $('pw-new').value = '';
    toast('Đã đổi mật khẩu');
  });
}

// ---- Phương pháp tự dự đoán ----
const METHOD_TYPES = { random: 'Ngẫu nhiên' };
function renderMethods() {
  $('methods').innerHTML = cfg.methods.map((m, i) => `<tr data-i="${i}">
    <td><input data-k="name" value="${esc(m.name)}" style="min-width:140px"></td>
    <td><select data-k="type">${Object.entries(METHOD_TYPES).map(([v, l]) => `<option value="${v}" ${m.type === v ? 'selected' : ''}>${l}</option>`).join('')}</select></td>
    <td><input data-k="size" type="number" min="1" max="9" value="${m.size}" style="width:80px"></td>
    <td><input data-k="enabled" type="checkbox" ${m.enabled ? 'checked' : ''} style="width:auto"></td>
    <td><input data-k="notify" type="checkbox" ${m.notify ? 'checked' : ''} style="width:auto"></td>
    <td><button class="btn danger" type="button" onclick="removeMethod(${i})">Xoá</button></td>
  </tr>`).join('') || '<tr><td colspan="6" class="muted">Chưa có phương pháp.</td></tr>';
}
function readMethods() {
  document.querySelectorAll('#methods tr[data-i]').forEach((tr) => {
    const m = cfg.methods[tr.dataset.i];
    tr.querySelectorAll('[data-k]').forEach((el) => {
      const k = el.dataset.k;
      m[k] = el.type === 'checkbox' ? el.checked : k === 'size' ? +el.value : el.value.trim();
    });
  });
}
function addMethod() { readMethods(); cfg.methods.push({ id: '', name: 'Ngẫu nhiên ' + (cfg.methods.length + 1), type: 'random', size: 5, enabled: true, notify: false }); renderMethods(); }
function removeMethod(i) { if (!confirm('Xoá phương pháp này? Lịch sử dự đoán của nó vẫn giữ trong data/ nhưng không hiển thị nữa.')) return; readMethods(); cfg.methods.splice(i, 1); renderMethods(); }
async function saveMethods() {
  await run(null, async () => { readMethods(); cfg = await api('save', { methods: cfg.methods }); renderMethods(); loadMethodStats(); toast('Đã lưu'); });
}
async function loadMethodStats() {
  const st = await api('methods-stats').catch(() => []);
  if (!st.length) { $('methods-stats').innerHTML = ''; return; }
  const games = st[0].games;
  $('methods-stats').innerHTML = `<thead><tr><th>Phương pháp</th>${games.map((g) => `<th>${esc(g.name)}</th>`).join('')}</tr></thead><tbody>`
    + st.map((m) => `<tr><td><b>${esc(m.name)}</b></td>${m.games.map((g) => g.n
      ? `<td><span style="color:var(--c-good)">${g.w}W</span> / <span style="color:var(--c-bad)">${g.n - g.w}L</span><div class="muted">${Math.round(g.w * 100 / g.n)}% · ${g.n} kỳ</div></td>`
      : '<td class="muted">–</td>').join('')}</tr>`).join('') + '</tbody>';
}

function updatePublicLink() {
  const k = $('pr-key').value.trim();
  const url = new URL('xemcau.php' + (k ? '?k=' + encodeURIComponent(k) : ''), location.href).href;
  $('pr-link').textContent = url; $('pr-open').href = url;
}
document.addEventListener('input', (e) => { if (e.target.id === 'pr-key') updatePublicLink(); });

// ---- Thành viên ----
const MB_STATUS = { active: ['Hoạt động', 'b-ok'], pending: ['Chờ duyệt', 'b-warn'], locked: ['Bị khoá', 'b-err'] };
const fmtDate = (d) => d ? new Date(d.replace(' ', 'T')).toLocaleString('vi-VN') : '';

async function loadMembersTab() {
  const d = cfg.db;
  $('db-host').value = d.host; $('db-port').value = d.port; $('db-name').value = d.name; $('db-user').value = d.user;
  $('db-pass').value = ''; $('db-pass').placeholder = d.hasPass ? '(đã lưu – để trống để giữ nguyên)' : '';
  $('mb-register').checked = cfg.members.allowRegister; $('mb-approval').checked = cfg.members.requireApproval;
  const st = await api('db-status').catch((e) => ({ configured: true, ok: false, error: e.message }));
  $('db-status').innerHTML = !st.configured ? '<span class="badge b-off">Chưa cấu hình</span>'
    : st.ok ? `<span class="badge b-ok">Đã kết nối</span> ${st.members} thành viên · ${st.draws} kỳ đã lưu`
    : `<span class="badge b-err">Lỗi</span> ${esc(st.error)}`;
  if (st.configured && st.ok) loadMembers(1);
}

async function saveDb(btn) {
  await run(btn, async () => {
    const r = await api('db-save', { host: $('db-host').value.trim(), port: +$('db-port').value || 3306, name: $('db-name').value.trim(), user: $('db-user').value.trim(), pass: $('db-pass').value });
    cfg = await api('config');
    toast(r.message); loadMembersTab();
  });
}

async function saveMemberCfg() {
  await run(null, async () => {
    cfg = await api('save', { members: { allowRegister: $('mb-register').checked, requireApproval: $('mb-approval').checked } });
    toast('Đã lưu');
  });
}

let mbPage = 1;
async function loadMembers(page) {
  mbPage = page || mbPage;
  await run(null, async () => {
    const r = await api(`members&q=${encodeURIComponent($('mb-q').value.trim())}&status=${$('mb-status').value}&page=${mbPage}`);
    $('mb-counts').textContent = Object.entries(r.counts).map(([k, v]) => `${(MB_STATUS[k] || [k])[0]}: ${v}`).join(' · ');
    $('mb-rows').innerHTML = r.rows.map((m) => {
      const [label, cls] = MB_STATUS[m.status] || [m.status, 'b-off'];
      const acts = [];
      if (m.status !== 'active') acts.push(`<button class="btn sec" onclick="setMemberStatus(${m.id}, 'active')">${m.status === 'pending' ? 'Duyệt' : 'Mở khoá'}</button>`);
      if (m.status !== 'locked') acts.push(`<button class="btn sec" onclick="setMemberStatus(${m.id}, 'locked')">Khoá</button>`);
      acts.push(`<button class="btn sec" onclick="resetMemberPw(${m.id}, '${esc(m.username)}')">Đổi MK</button>`);
      acts.push(`<button class="btn danger" onclick="deleteMember(${m.id}, '${esc(m.username)}')">Xoá</button>`);
      return `<tr><td>${m.id}</td><td><b>${esc(m.username)}</b></td><td>${esc(m.display_name)}</td><td><span class="badge ${cls}">${label}</span></td>
        <td class="muted">${fmtDate(m.created_at)}</td><td class="muted">${fmtDate(m.last_login_at)}<br>${esc(m.last_login_ip)}</td>
        <td><div class="row" style="margin:0;gap:4px">${acts.join('')}</div></td></tr>`;
    }).join('') || '<tr><td colspan="7" class="muted">Không có thành viên.</td></tr>';
    const pages = Math.ceil(r.total / r.perPage);
    $('mb-pager').innerHTML = pages > 1 ? Array.from({ length: pages }, (_, i) => `<button class="btn ${i + 1 === r.page ? '' : 'sec'}" onclick="loadMembers(${i + 1})">${i + 1}</button>`).join('') : '';
  });
}

async function setMemberStatus(id, status) {
  await run(null, async () => { await api('member-update', { id, status }); loadMembers(); });
}

async function resetMemberPw(id, name) {
  const pw = prompt(`Mật khẩu mới cho ${name} (tối thiểu 6 ký tự):`);
  if (!pw) return;
  await run(null, async () => { await api('member-password', { id, password: pw }); toast('Đã đổi mật khẩu'); });
}

async function deleteMember(id, name) {
  if (!confirm(`Xoá thành viên ${name}?`)) return;
  await run(null, async () => { await api('member-delete', { id }); loadMembers(); });
}

async function createMember(btn) {
  await run(btn, async () => {
    await api('member-create', { username: $('nm-user').value.trim(), password: $('nm-pass').value, display_name: $('nm-name').value.trim() });
    $('nm-user').value = $('nm-pass').value = $('nm-name').value = '';
    toast('Đã thêm thành viên'); loadMembers(1);
  });
}

// ---- Cập nhật ----
const shortSha = (s) => s ? s.slice(0, 7) : '';
function verText(v) {
  if (!v || (!v.sha && !v.message)) return '<span class="muted">chưa rõ</span>';
  return `<code>${esc(shortSha(v.sha))}</code> ${esc((v.message || '').split('\n')[0])} <span class="muted">${v.date ? new Date(v.date).toLocaleString('vi-VN') : ''}</span>`;
}

async function loadSystem() {
  const s = await api('system').catch(() => null);
  if (!s) return;
  $('cron-cmd').textContent = s.cronCommand;
  $('cron-url').textContent = s.cronUrl;
  $('tick-url').textContent = s.tickUrl;
  $('v-cur').innerHTML = verText(s.version);
  $('sys').innerHTML = [
    ['Phiên bản app', esc(s.appVersion)], ['PHP', esc(s.php)],
    ['Worker', s.worker.alive ? `<span class="badge b-ok">Đang chạy (${esc(s.worker.mode)})</span>` : '<span class="badge b-err">Không chạy</span>'],
    ['Lệnh cron (1 phút)', `<code>${esc(s.cronCommand)}</code>`],
    ['Link cron 1 phút', `<code>${esc(s.cronUrl)}</code>`],
    ['Link cron 5 giây (tick)', `<code>${esc(s.tickUrl)}</code>`],
  ].map(([k, v]) => `<tr><td>${k}</td><td>${v}</td></tr>`).join('');
}

async function loadUpdate() {
  loadSystem();
  const list = await api('backups').catch(() => []);
  $('backups').innerHTML = list.map((b) => `<tr><td>${esc(b.name)}</td><td class="muted">${new Date(b.time).toLocaleString('vi-VN')} · ${(b.size / 1024).toFixed(0)} KB</td><td><button class="btn sec" onclick="restore('${esc(b.name)}', this)">Khôi phục</button></td></tr>`).join('')
    || '<tr><td class="muted">Chưa có bản sao lưu (tự tạo khi cập nhật).</td></tr>';
}

async function checkUpdate() {
  await run(null, async () => {
    const r = await api('update-check');
    $('v-cur').innerHTML = verText(r.current);
    $('v-latest').innerHTML = verText(r.latest) + (r.hasUpdate ? ' <span class="badge b-warn">Có bản mới</span>' : ' <span class="badge b-ok">Đã mới nhất</span>');
    $('btn-update').disabled = false;
    $('btn-update').textContent = r.hasUpdate ? 'Cập nhật ngay' : 'Cài lại bản mới nhất';
  });
}

async function applyUpdate() {
  if (!confirm('Cập nhật code từ GitHub? Code hiện tại sẽ được sao lưu trước.')) return;
  await run($('btn-update'), async () => {
    const r = await api('update-apply', {});
    toast(`Đã cập nhật ${r.updated} file lên ${shortSha(r.version.sha)}`);
    setTimeout(() => location.reload(), 1500);
  });
}

async function restore(name, btn) {
  if (!confirm(`Khôi phục code từ ${name}?`)) return;
  await run(btn, async () => { await api('restore', { name }); toast('Đã khôi phục'); setTimeout(() => location.reload(), 1500); });
}

async function saveUpdateCfg() {
  await run(null, async () => {
    cfg = await api('save', { update: { repo: $('u-repo').value.trim(), branch: $('u-branch').value.trim() || 'master', token: $('u-token').value.trim() } });
    toast('Đã lưu');
  });
}

// ---- Dashboard ----
function countdown(ms) {
  if (ms == null) return '';
  const s = Math.max(0, Math.round(ms / 1000));
  const h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60);
  return (h ? h + 'h ' : '') + (h || m ? m + 'p ' : '') + (s % 60) + 's';
}

let status = null, skew = 0;
async function refresh() {
  try { status = await api('status'); skew = status.serverTime - Date.now(); } catch { return; }
  const w = status.worker;
  $('s-worker').innerHTML = w.alive ? '<span class="badge b-ok">Đang chạy</span>' : '<span class="badge b-err">Không chạy</span>';
  if (status.outbox) $('s-worker').innerHTML += ` <span class="badge b-warn" title="Tin gửi lỗi tạm thời, sẽ tự gửi lại">${status.outbox} tin chờ gửi lại</span>`;
  $('cron-help').classList.toggle('hidden', w.alive);
  $('s-monitor').innerHTML = status.monitorEnabled ? '<span class="badge b-ok">Bật</span>' : '<span class="badge b-off">Tắt</span>';
  $('s-subs').textContent = status.subscribers;
  $('s-games').textContent = status.games.filter((g) => g.enabled).length + ' / ' + status.games.length;
  $('logs').innerHTML = status.logs.map((l) => `<span class="log-${l.level}">${new Date(l.time).toLocaleString('vi-VN')} [${l.level}] ${esc(l.msg)}</span>`).join('\n');
  renderDash();
}

function renderDash() {
  if (!status) return;
  const now = Date.now() + skew;
  $('t-games').innerHTML = status.games.map((g) => {
    const s = g.state || {};
    const st = !g.enabled ? '<span class="badge b-off">Tắt</span>' : s.lastError ? `<span class="badge b-err" title="${esc(s.lastError)}">Lỗi</span>` : s.lastFetch ? '<span class="badge b-ok">OK</span>' : '<span class="badge b-off">Chờ</span>';
    return `<tr><td>${esc(g.name)}</td><td>${esc(s.issue)}</td><td><b>${esc(s.result)}</b></td><td>${esc(s.nextIssue)}<div class="muted">${s.nextTime ? new Date(s.nextTime).toLocaleTimeString('vi-VN') : ''}</div></td><td><b>${esc(s.plan)}</b><div class="muted">${esc(s.planStatus)}</div></td><td>${s.nextTime ? countdown(s.nextTime - now) : ''}</td><td>${st}</td></tr>`;
  }).join('') || '<tr><td colspan="7" class="muted">Chưa có game. Thêm ở tab "Nguồn API".</td></tr>';
}

setInterval(refresh, 5000);
setInterval(renderDash, 1000);
boot().catch((e) => toast(e.message, true));
