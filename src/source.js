const config = require('./config');
const { getPath, stringify, toTimestamp } = require('./util');

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
  const url = String(game.url).replace('{ts}', Date.now());
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

// Trích các trường cần thiết theo cấu hình paths
function extract(game, raw, now = Date.now()) {
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

module.exports = { fetchRaw, extract, fetchGame };
