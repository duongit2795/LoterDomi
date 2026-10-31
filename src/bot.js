const config = require('./config');
const telegram = require('./telegram');
const monitor = require('./monitor');
const logger = require('./logger');
const { escapeHtml, formatTime, formatCountdown } = require('./util');

const HELP = [
  '<b>LoterDomi bot</b>',
  '/next – các phiên sắp tới',
  '/last – kết quả phiên gần nhất',
  '/subscribe – nhận thông báo tại chat này',
  '/unsubscribe – huỷ nhận thông báo',
  '/id – xem chat id',
].join('\n');

function games() {
  return config.get().games.filter((g) => g.enabled);
}

function nextText() {
  const st = monitor.getState();
  const tz = config.get().timezone;
  const lines = games().map((g) => {
    const s = st[g.id] || {};
    if (!s.nextIssue) return `• <b>${escapeHtml(g.name)}</b>: chưa có dữ liệu`;
    return `• <b>${escapeHtml(g.name)}</b>: phiên <code>${escapeHtml(s.nextIssue)}</code>` +
      (s.nextTime ? ` – ${formatTime(s.nextTime, tz)} (còn ${formatCountdown(s.nextTime - Date.now())})` : '') +
      (s.plan ? `\n   📋 ${escapeHtml(s.play || '')} ${escapeHtml(s.planRange || '')}: <b>${escapeHtml(s.plan)}</b>` : '');
  });
  return lines.length ? '⏰ <b>Phiên sắp tới</b>\n' + lines.join('\n') : 'Chưa cấu hình game nào.';
}

function lastText() {
  const st = monitor.getState();
  const lines = games().map((g) => {
    const s = st[g.id] || {};
    if (!s.issue) return `• <b>${escapeHtml(g.name)}</b>: chưa có dữ liệu`;
    return `• <b>${escapeHtml(g.name)}</b>: phiên <code>${escapeHtml(s.issue)}</code> → <b>${escapeHtml(s.result)}</b>` +
      (s.planStatus ? `\n   📋 ${escapeHtml(s.planCheck)} → ${escapeHtml(s.planStatus)}` : '');
  });
  return lines.length ? '🎯 <b>Kết quả gần nhất</b>\n' + lines.join('\n') : 'Chưa cấu hình game nào.';
}

async function reply(msg, text) {
  await telegram.call('sendMessage', { chat_id: msg.chat.id, text, parse_mode: 'HTML', disable_web_page_preview: true });
}

function setup() {
  telegram.onMessage(async (msg) => {
    const cmd = msg.text.trim().split(/\s+/)[0].split('@')[0].toLowerCase();
    const cfg = config.get();
    const id = String(msg.chat.id);
    switch (cmd) {
      case '/start':
      case '/help': return reply(msg, HELP);
      case '/next': return reply(msg, nextText());
      case '/last': return reply(msg, lastText());
      case '/id': return reply(msg, `Chat id: <code>${id}</code>`);
      case '/subscribe':
        if (!cfg.telegram.allowSubscribe) return reply(msg, 'Đăng ký đang bị tắt, liên hệ admin.');
        if (!cfg.telegram.chatIds.includes(id)) {
          config.update({ telegram: { chatIds: [...cfg.telegram.chatIds, id] } });
          logger.info(`Chat ${id} đã đăng ký`);
        }
        return reply(msg, '✅ Đã đăng ký nhận thông báo.');
      case '/unsubscribe':
        config.update({ telegram: { chatIds: cfg.telegram.chatIds.filter((c) => c !== id) } });
        return reply(msg, 'Đã huỷ nhận thông báo.');
      default: return undefined;
    }
  });
  telegram.start();
}

module.exports = { setup, nextText, lastText };
