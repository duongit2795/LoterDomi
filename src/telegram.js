const config = require('./config');
const logger = require('./logger');

async function call(method, params = {}, token) {
  const t = token || config.get().telegram.token;
  if (!t) throw new Error('Chưa cấu hình Telegram bot token');
  const res = await fetch(`https://api.telegram.org/bot${t}/${method}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(params),
    signal: AbortSignal.timeout(method === 'getUpdates' ? 40000 : 15000),
  });
  const data = await res.json();
  if (!data.ok) throw new Error(`Telegram ${method}: ${data.description || res.status}`);
  return data.result;
}

async function sendMessage(chatId, text) {
  return call('sendMessage', {
    chat_id: chatId,
    text,
    parse_mode: config.get().telegram.parseMode || undefined,
    disable_web_page_preview: true,
  });
}

async function broadcast(text) {
  const ids = config.get().telegram.chatIds;
  let sent = 0;
  for (const id of ids) {
    try { await sendMessage(id, text); sent++; } catch (e) {
      logger.error(`Gửi tới ${id} lỗi: ${e.message}`);
    }
  }
  return sent;
}

// Long-polling nhận lệnh
let running = false;
let offset = 0;
let activeToken = null;
const handlers = [];

function onMessage(fn) { handlers.push(fn); }

async function loop() {
  while (running) {
    const token = config.get().telegram.token;
    if (!token) { await sleep(5000); continue; }
    if (token !== activeToken) { activeToken = token; offset = 0; }
    try {
      const updates = await call('getUpdates', { offset, timeout: 30, allowed_updates: ['message'] }, token);
      for (const u of updates) {
        offset = u.update_id + 1;
        if (!u.message || !u.message.text) continue;
        for (const h of handlers) {
          try { await h(u.message); } catch (e) { logger.error(`Xử lý lệnh lỗi: ${e.message}`); }
        }
      }
    } catch (e) {
      logger.warn(`getUpdates: ${e.message}`);
      await sleep(5000);
    }
  }
}

function start() { if (!running) { running = true; loop(); } }
function stop() { running = false; }
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

module.exports = { call, sendMessage, broadcast, onMessage, start, stop };
