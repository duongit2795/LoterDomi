const fs = require('fs');
const path = require('path');

const DATA_DIR = path.join(__dirname, '..', 'data');
const CONFIG_FILE = path.join(DATA_DIR, 'config.json');

const DEFAULT_GAME = {
  id: '',
  name: '',
  enabled: true,
  url: '',
  method: 'GET',
  headers: {},
  body: '',
  // Đường dẫn JSON tới các trường, dạng "data.list[0].issue"
  paths: {
    issue: '',          // mã phiên vừa mở thưởng
    result: '',         // kết quả phiên vừa mở
    nextIssue: '',      // mã phiên sắp tới
    nextTime: '',       // thời điểm mở thưởng phiên sắp tới (timestamp/chuỗi ngày) hoặc số giây còn lại
  },
  // auto | seconds | milliseconds | remaining
  timeMode: 'auto',
};

const DEFAULT_CONFIG = {
  telegram: {
    token: process.env.TELEGRAM_BOT_TOKEN || '',
    chatIds: [],
    allowSubscribe: true,
    parseMode: 'HTML',
  },
  monitor: {
    enabled: false,
    intervalSeconds: 10,
    notifyResult: true,
    notifyUpcoming: true,
    remindBeforeSeconds: 60,
    requestTimeoutSeconds: 15,
  },
  globalHeaders: {
    'User-Agent': 'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 Chrome/120 Mobile Safari/537.36',
    Referer: 'https://www.vnlotteryplan.com/mobile.html',
  },
  templates: {
    result: '🎯 <b>{game}</b>\nPhiên: <code>{issue}</code>\nKết quả: <b>{result}</b>',
    upcoming: '⏰ <b>{game}</b>\nPhiên sắp tới: <code>{nextIssue}</code>\nMở thưởng lúc: {nextTime}\nCòn lại: {countdown}',
  },
  timezone: 'Asia/Ho_Chi_Minh',
  games: [],
};

let config = null;

function merge(base, over) {
  if (Array.isArray(base) || typeof base !== 'object' || base === null) return over === undefined ? base : over;
  const out = { ...base };
  for (const k of Object.keys(over || {})) out[k] = merge(base[k], over[k]);
  return out;
}

function normalizeGame(g) {
  const game = merge(DEFAULT_GAME, g);
  game.paths = merge(DEFAULT_GAME.paths, g.paths || {});
  if (!game.id) game.id = Math.random().toString(36).slice(2, 10);
  return game;
}

function load() {
  if (!fs.existsSync(DATA_DIR)) fs.mkdirSync(DATA_DIR, { recursive: true });
  let raw = {};
  if (fs.existsSync(CONFIG_FILE)) {
    try { raw = JSON.parse(fs.readFileSync(CONFIG_FILE, 'utf8')); } catch (e) {
      console.error('[config] config.json lỗi, dùng mặc định:', e.message);
    }
  }
  config = merge(DEFAULT_CONFIG, raw);
  config.games = (config.games || []).map(normalizeGame);
  save();
  return config;
}

function save() {
  const tmp = CONFIG_FILE + '.tmp';
  fs.writeFileSync(tmp, JSON.stringify(config, null, 2));
  fs.renameSync(tmp, CONFIG_FILE);
}

function get() {
  return config || load();
}

function update(partial) {
  config = merge(get(), partial);
  if (partial.games) config.games = partial.games.map(normalizeGame);
  save();
  return config;
}

module.exports = { load, save, get, update, normalizeGame };
