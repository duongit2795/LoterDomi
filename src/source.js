const config = require('./config');
const { getPath, stringify, toTimestamp } = require('./util');

const VNLP_BASE = 'https://www.vnlotteryplan.com/api/front/wl';
const VNLP_STATUS = { 0: '❌ Trượt', 1: '✅ Trúng kỳ {n}', 2: '⏳ Đang nuôi kỳ {n}' };

// Gọi API nguồn của một game, trả về JSON thô
async function fetchRaw(game) {
  const cfg = config.get();
  const headers = { Accept: 'application/json, text/plain, */*', ...cfg.globalHeaders, ...(game.headers || {}) };
  const opts = {
    method: game.method || 'GET',
    headers,
    signal: AbortSignal.timeout((cfg.monitor.requestTimeoutSeconds || 15) * 1000),
  };
  if (opts.method !== 'GET' && game.body) {
    opts.body = game.body;
    if (!Object.keys(headers).some((h) => h.toLowerCase() === 'content-type')) {
      headers['Content-Type'] = game.body.trim().startsWith('{') ? 'application/json' : 'application/x-www-form-urlencoded';
    }
  }
  const url = game.type === 'vnlp' ? vnlpUrl(game) : String(game.url).replace('{ts}', Date.now());
  const res = await fetch(url, opts);
  const text = await res.text();
  if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
  try { return JSON.parse(text); } catch {
    // một số API trả JSONP: callback({...})
    const m = text.match(/^[\w$.]+\(([\s\S]*)\)\s*;?\s*$/);
    if (m) return JSON.parse(m[1]);
    throw new Error(`Phản hồi không phải JSON: ${text.slice(0, 200)}`);
  }
}

function vnlpUrl(game) {
  const v = game.vnlp;
  const qs = new URLSearchParams({
    turnNumType: v.turnNumType, planNumberType: v.planNumberType, page: 1, pageSize: 5,
    gameId: v.gameId, play: v.play, _t: Date.now(),
  });
  return `${VNLP_BASE}/plan/lotteryProject/queryLotteryPlanProject?${qs}`;
}

// Danh sách game + kiểu chơi có sẵn trên vnlotteryplan
async function vnlpGameList() {
  const raw = await fetchRaw({ type: 'custom', url: `${VNLP_BASE}/plan/lotteryProject/planGameList?_t={ts}` });
  if (!raw.success) throw new Error(raw.msg || 'planGameList lỗi');
  return {
    games: (raw.rows || []).map((g) => ({ gameId: g.gameId || g.id, name: g.name, remark: g.remark, cate: g.cateName })),
    plays: (raw.other || []).map((p) => ({ play: p.playCode, playName: p.playName, cate: p.cateName })),
  };
}

function extractVnlp(game, raw, now) {
  const t = raw && raw.t;
  if (!raw || !raw.success || !t) throw new Error(`vnlotteryplan: ${(raw && raw.msg) || 'không có dữ liệu'}`);
  const lot = t.lottery || {};
  const rows = (t.resultDetail && t.resultDetail.data) || [];
  // Dòng kế hoạch bị ảnh hưởng bởi kỳ vừa mở
  const hit = rows.find((r) => r.turnNum === lot.turnNum);
  const spaced = (s) => String(s || '').replace(/,/g, ' ');
  return {
    issue: stringify(lot.turnNum),
    result: stringify(lot.openNum),
    nextIssue: stringify(t.nextTurnNum),
    // Bù lệch đồng hồ giữa máy chủ nguồn và máy chạy bot
    nextTime: t.nextOpenTime ? t.nextOpenTime - (t.serverTime || now) + now : null,
    play: game.vnlp.playName || game.vnlp.play,
    plan: spaced(t.planNumber),
    planRange: rows[0] ? rows[0].turnNumRange : '',
    planCheck: hit ? `${spaced(hit.planNumber)} (${hit.turnNumRange})` : '',
    playResult: hit ? stringify(hit.openNum) : '',
    planStatus: hit && VNLP_STATUS[hit.winStatus] !== undefined
      ? VNLP_STATUS[hit.winStatus].replace('{n}', hit.currentTurnSort) : '',
  };
}

// Trích các trường cần thiết theo cấu hình paths
function extract(game, raw, now = Date.now()) {
  if (game.type === 'vnlp') return extractVnlp(game, raw, now);
  const p = game.paths || {};
  return {
    issue: stringify(getPath(raw, p.issue)),
    result: stringify(getPath(raw, p.result)),
    nextIssue: stringify(getPath(raw, p.nextIssue)),
    nextTime: toTimestamp(getPath(raw, p.nextTime), game.timeMode, now),
  };
}

async function fetchGame(game) {
  const raw = await fetchRaw(game);
  return { raw, data: extract(game, raw) };
}

module.exports = { fetchRaw, extract, fetchGame, vnlpGameList };
