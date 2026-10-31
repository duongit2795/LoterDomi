const express = require('express');
const crypto = require('crypto');
const path = require('path');
const config = require('./config');
const logger = require('./logger');
const telegram = require('./telegram');
const source = require('./source');
const monitor = require('./monitor');

const sessions = new Map(); // token -> expires
const SESSION_TTL = 12 * 3600 * 1000;

function password() {
  return process.env.ADMIN_PASSWORD || 'changeme';
}

function safeEqual(a, b) {
  const ha = crypto.createHash('sha256').update(String(a)).digest();
  const hb = crypto.createHash('sha256').update(String(b)).digest();
  return crypto.timingSafeEqual(ha, hb);
}

function getCookie(req, name) {
  const m = (req.headers.cookie || '').match(new RegExp(`(?:^|;\\s*)${name}=([^;]+)`));
  return m ? decodeURIComponent(m[1]) : null;
}

function auth(req, res, next) {
  const t = getCookie(req, 'sid');
  const exp = t && sessions.get(t);
  if (exp && exp > Date.now()) return next();
  if (t) sessions.delete(t);
  res.status(401).json({ error: 'Chưa đăng nhập' });
}

const wrap = (fn) => (req, res) => Promise.resolve(fn(req, res)).catch((e) => res.status(400).json({ error: e.message }));

function create() {
  const app = express();
  app.use(express.json({ limit: '1mb' }));
  app.use(express.static(path.join(__dirname, '..', 'public')));

  app.post('/api/login', (req, res) => {
    if (!safeEqual(req.body.password || '', password())) {
      logger.warn(`Đăng nhập admin thất bại từ ${req.ip}`);
      return res.status(401).json({ error: 'Sai mật khẩu' });
    }
    const t = crypto.randomBytes(24).toString('hex');
    sessions.set(t, Date.now() + SESSION_TTL);
    res.setHeader('Set-Cookie', `sid=${t}; HttpOnly; SameSite=Strict; Path=/; Max-Age=${SESSION_TTL / 1000}`);
    res.json({ ok: true });
  });

  app.post('/api/logout', (req, res) => {
    sessions.delete(getCookie(req, 'sid'));
    res.setHeader('Set-Cookie', 'sid=; Path=/; Max-Age=0');
    res.json({ ok: true });
  });

  app.use('/api', auth);

  app.get('/api/config', (req, res) => res.json(config.get()));

  app.put('/api/config', wrap((req, res) => {
    const cfg = config.update(req.body);
    monitor.restart();
    logger.info('Cấu hình đã được cập nhật');
    res.json(cfg);
  }));

  app.get('/api/status', (req, res) => {
    const cfg = config.get();
    const st = monitor.getState();
    res.json({
      monitorEnabled: cfg.monitor.enabled,
      subscribers: cfg.telegram.chatIds.length,
      games: cfg.games.map((g) => ({ id: g.id, name: g.name, enabled: g.enabled, state: st[g.id] || null })),
      logs: logger.list().slice(0, 100),
      serverTime: Date.now(),
    });
  });

  // Thử gọi API của 1 game (dùng cấu hình gửi lên, chưa cần lưu)
  app.post('/api/test-fetch', wrap(async (req, res) => {
    const game = config.normalizeGame(req.body.game || {});
    if (game.type === 'vnlp' ? !game.vnlp.gameId : !game.url) throw new Error(game.type === 'vnlp' ? 'Thiếu gameId' : 'Thiếu URL');
    const raw = await source.fetchRaw(game);
    res.json({ raw, extracted: source.extract(game, raw) });
  }));

  app.get('/api/vnlp/games', wrap(async (req, res) => res.json(await source.vnlpGameList())));

  app.post('/api/preview', wrap((req, res) => {
    const game = config.get().games.find((g) => g.id === req.body.gameId) || { name: 'Demo', id: 'demo' };
    const s = monitor.getState()[game.id] || { issue: '20261031001', result: '1,2,3,4,5', nextIssue: '20261031002', nextTime: Date.now() + 60000 };
    res.json({ text: monitor.message(req.body.kind === 'upcoming' ? 'upcoming' : 'result', game, s) });
  }));

  app.post('/api/bot-info', wrap(async (req, res) => {
    res.json(await telegram.call('getMe', {}, req.body.token || undefined));
  }));

  app.post('/api/test-send', wrap(async (req, res) => {
    const text = req.body.text || '✅ Tin nhắn thử từ LoterDomi admin';
    if (req.body.chatId) await telegram.sendMessage(req.body.chatId, text);
    else if (!(await telegram.broadcast(text))) throw new Error('Không gửi được tới chat nào');
    res.json({ ok: true });
  }));

  return app;
}

module.exports = { create };
