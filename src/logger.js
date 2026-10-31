const MAX = 300;
const entries = [];

function log(level, msg) {
  const e = { time: new Date().toISOString(), level, msg: String(msg) };
  entries.push(e);
  if (entries.length > MAX) entries.shift();
  (level === 'error' ? console.error : console.log)(`[${e.time}] ${level.toUpperCase()} ${e.msg}`);
}

module.exports = {
  info: (m) => log('info', m),
  warn: (m) => log('warn', m),
  error: (m) => log('error', m),
  list: () => entries.slice().reverse(),
};
