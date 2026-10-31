const fs = require('fs');
const path = require('path');

// Nạp .env đơn giản (không cần thư viện)
const envFile = path.join(__dirname, '..', '.env');
if (fs.existsSync(envFile)) {
  for (const line of fs.readFileSync(envFile, 'utf8').split('\n')) {
    const m = line.match(/^\s*([\w.]+)\s*=\s*(.*)\s*$/);
    if (m && process.env[m[1]] === undefined) process.env[m[1]] = m[2].replace(/^['"]|['"]$/g, '');
  }
}

const config = require('./config');
const logger = require('./logger');
const admin = require('./admin');
const monitor = require('./monitor');
const bot = require('./bot');

config.load();
if (!process.env.ADMIN_PASSWORD) logger.warn('ADMIN_PASSWORD chưa đặt, đang dùng mặc định "changeme"');

const port = Number(process.env.PORT) || 3000;
admin.create().listen(port, () => logger.info(`Web admin: http://localhost:${port}`));
monitor.start();
bot.setup();
