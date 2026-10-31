// Lấy giá trị theo đường dẫn "a.b[0].c" hoặc "a.b.0.c"
function getPath(obj, p) {
  if (!p) return undefined;
  const keys = String(p).replace(/\[(\w+)\]/g, '.$1').split('.').filter(Boolean);
  let cur = obj;
  for (const k of keys) {
    if (cur === null || cur === undefined) return undefined;
    cur = cur[k];
  }
  return cur;
}

function stringify(v) {
  if (v === undefined || v === null) return '';
  if (Array.isArray(v)) return v.join(',');
  if (typeof v === 'object') return JSON.stringify(v);
  return String(v);
}

// Chuyển giá trị thời gian về ms epoch
function toTimestamp(v, mode, now = Date.now()) {
  if (v === undefined || v === null || v === '') return null;
  const n = Number(v);
  const isNum = !Number.isNaN(n) && String(v).trim() !== '';
  switch (mode) {
    case 'remaining': return isNum ? now + n * 1000 : null;
    case 'seconds': return isNum ? n * 1000 : null;
    case 'milliseconds': return isNum ? n : null;
    default: {
      if (isNum) return n < 1e11 ? n * 1000 : n;
      const t = Date.parse(String(v).replace(' ', 'T'));
      return Number.isNaN(t) ? null : t;
    }
  }
}

function formatTime(ms, tz) {
  if (!ms) return '';
  return new Date(ms).toLocaleString('vi-VN', { timeZone: tz || 'Asia/Ho_Chi_Minh', hour12: false });
}

function formatCountdown(ms) {
  if (ms === null || ms === undefined) return '';
  const s = Math.max(0, Math.round(ms / 1000));
  const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
  return (h ? `${h}h ` : '') + (h || m ? `${m}p ` : '') + `${sec}s`;
}

function escapeHtml(s) {
  return String(s).replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));
}

// Thay {biến}; dòng nào có biến mà tất cả đều rỗng thì bỏ dòng đó
function render(tpl, vars, html) {
  const val = (k) => (vars[k] === undefined || vars[k] === null ? '' : String(vars[k]));
  return String(tpl || '').split('\n').filter((line) => {
    const keys = [...line.matchAll(/\{(\w+)\}/g)].map((m) => m[1]);
    return !keys.length || keys.some((k) => val(k) !== '');
  }).join('\n').replace(/\{(\w+)\}/g, (_, k) => (html ? escapeHtml(val(k)) : val(k)));
}

module.exports = { getPath, stringify, toTimestamp, formatTime, formatCountdown, escapeHtml, render };
