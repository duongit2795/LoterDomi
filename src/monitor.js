const fs = require('fs');
const path = require('path');
const config = require('./config');
const logger = require('./logger');
const telegram = require('./telegram');
const source = require('./source');
const { render, formatTime, formatCountdown } = require('./util');

const STATE_FILE = path.join(__dirname, '..', 'data', 'state.json');

// state[gameId] = { issue, result, nextIssue, nextTime, notifiedIssue, remindedIssue, lastFetch, lastError }
let state = {};
try { state = JSON.parse(fs.readFileSync(STATE_FILE, 'utf8')); } catch { state = {}; }

function saveState() {
  try { fs.writeFileSync(STATE_FILE, JSON.stringify(state, null, 2)); } catch (e) {
    logger.error(`Lưu state lỗi: ${e.message}`);
  }
}

function vars(game, s, now = Date.now()) {
  const tz = config.get().timezone;
  return {
    game: game.name || game.id,
    issue: s.issue || '',
    result: s.result || '',
    nextIssue: s.nextIssue || '',
    nextTime: formatTime(s.nextTime, tz),
    countdown: s.nextTime ? formatCountdown(s.nextTime - now) : '',
    now: formatTime(now, tz),
  };
}

function message(kind, game, s) {
  const cfg = config.get();
  return render(cfg.templates[kind], vars(game, s), cfg.telegram.parseMode === 'HTML');
}

async function checkGame(game) {
  const cfg = config.get().monitor;
  const s = state[game.id] || (state[game.id] = {});
  try {
    const { data } = await source.fetchGame(game);
    Object.assign(s, data, { lastFetch: Date.now(), lastError: null });
  } catch (e) {
    s.lastError = e.message;
    s.lastFetch = Date.now();
    logger.warn(`[${game.name}] lấy dữ liệu lỗi: ${e.message}`);
    return;
  }

  // Phiên mới có kết quả
  if (s.issue && s.result && s.notifiedIssue !== s.issue) {
    const first = s.notifiedIssue === undefined;
    s.notifiedIssue = s.issue;
    // Lần chạy đầu tiên không gửi để tránh spam khi khởi động
    if (!first && cfg.notifyResult) {
      const n = await telegram.broadcast(message('result', game, s));
      logger.info(`[${game.name}] báo kết quả phiên ${s.issue} → ${n} chat`);
    }
  }
}

async function checkReminders() {
  const cfg = config.get();
  if (!cfg.monitor.enabled || !cfg.monitor.notifyUpcoming) return;
  const now = Date.now();
  for (const game of cfg.games.filter((g) => g.enabled)) {
    const s = state[game.id];
    if (!s || !s.nextIssue || !s.nextTime || s.remindedIssue === s.nextIssue) continue;
    const left = s.nextTime - now;
    if (left <= cfg.monitor.remindBeforeSeconds * 1000 && left > -5000) {
      s.remindedIssue = s.nextIssue;
      saveState();
      const n = await telegram.broadcast(message('upcoming', game, s));
      logger.info(`[${game.name}] nhắc phiên ${s.nextIssue} → ${n} chat`);
    }
  }
}

let pollTimer = null;
let remindTimer = null;
let busy = false;

async function tick() {
  const cfg = config.get();
  if (cfg.monitor.enabled && !busy) {
    busy = true;
    try {
      await Promise.all(cfg.games.filter((g) => g.enabled && g.url).map(checkGame));
      saveState();
    } finally { busy = false; }
  }
  pollTimer = setTimeout(tick, Math.max(2, cfg.monitor.intervalSeconds) * 1000);
}

function start() {
  if (pollTimer) return;
  tick();
  remindTimer = setInterval(() => checkReminders().catch((e) => logger.error(e.message)), 1000);
  logger.info('Monitor đã khởi động');
}

function restart() {
  clearTimeout(pollTimer);
  pollTimer = null;
  clearInterval(remindTimer);
  start();
}

function getState() { return state; }

module.exports = { start, restart, getState, vars, message };
