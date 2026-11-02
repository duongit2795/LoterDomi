// Vẽ bảng cầu W/L kiểu "big road" – dùng chung cho trang thành viên và trang xem cầu công khai
(function () {
  const ROWS = 6, CELL = 30;
  const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

  // Cùng kết quả đi xuống, hết hàng hoặc vướng ô thì rẽ phải; đổi kết quả sang cột mới
  function layout(items) {
    const used = new Set(), key = (c, r) => c + ',' + r, out = [];
    let c = -1, r = 0, startCol = -1, prev = null;
    for (const it of items) {
      if (it.type !== prev) {
        c = startCol + 1;
        while (used.has(key(c, 0))) c++;
        startCol = c; r = 0;
      } else if (r + 1 < ROWS && !used.has(key(c, r + 1))) {
        r++;
      } else {
        c++;
        while (used.has(key(c, r))) c++;
      }
      used.add(key(c, r));
      out.push({ c, r, it });
      prev = it.type;
    }
    return out;
  }

  function title(raw) {
    const [issue, wl, plan, open, nth] = raw;
    return `Kỳ ${issue}\nDàn ${plan}${nth ? ` (kỳ thứ ${nth} của dàn)` : ''}\nSố về: ${open || '–'}\n${wl === 'W' ? 'W – trúng' : 'L – không trúng'}`;
  }

  let skew = 0; // lệch giờ máy người xem so với server

  function countdownText(ms) {
    if (ms == null) return '';
    const sec = Math.round(ms / 1000);
    if (sec <= 0) return 'đang mở thưởng…';
    const m = Math.floor(sec / 60), s2 = sec % 60;
    return 'còn ' + (m ? m + ':' + String(s2).padStart(2, '0') : s2 + 's');
  }

  function card(g) {
    const items = g.road.map((raw) => ({ raw, type: raw[1] }));
    const wins = items.filter((i) => i.type === 'W').length, losses = items.length - wins;
    const pctW = items.length ? Math.round(wins * 100 / items.length) : 0;
    const lastType = items.length ? items[items.length - 1].type : null;
    let streak = 0;
    for (let i = items.length - 1; i >= 0 && items[i].type === lastType; i--) streak++;
    const placed = layout(items);
    const cols = Math.max(...placed.map((p) => p.c + 1), 1);
    const cells = placed.map((p, i) => `<div class="cell" style="grid-column:${p.c + 1};grid-row:${p.r + 1}"><span class="ball ${p.it.type === 'W' ? 'w' : 'l'}${i === placed.length - 1 ? ' last' : ''}" title="${esc(title(p.it.raw))}">${p.it.type}</span></div>`).join('');
    const streakHtml = lastType ? `<span class="rstreak ${lastType === 'W' ? 'w' : 'l'}">${lastType === 'W' ? '🔥 WIN' : '🧊 LOST'} ${streak} kỳ</span>` : '';
    const nums = (g.plan || '').split(/\s+/).filter(Boolean).map((n) => `<span class="num">${esc(n)}</span>`).join('');
    const issueShort = (g.nextIssue || '').replace(/^.*-/, '');
    return `<div class="card rcard" data-id="${esc(g.id)}">
      <div class="rcard-top"><div><div class="rname">${esc(g.name)}</div>${g.srcName ? `<div class="muted" style="font-size:12px">${esc(g.srcName)}</div>` : ''}</div>${streakHtml}</div>
      ${items.length ? `<div class="rbar" title="WIN ${pctW}%"><span class="bw" style="width:${pctW}%"></span><span class="bl"></span></div>
      <div class="rnums"><span class="tw">WIN <b>${wins}</b> · ${pctW}%</span><span class="tl">${100 - pctW}% · <b>${losses}</b> LOST</span></div>` : ''}
      <div class="road-wrap"><div class="road" style="grid-template-columns:repeat(${cols}, var(--cell))">${cells}</div></div>
      ${items.length ? '' : '<p class="muted">Chưa có dữ liệu – bảng sẽ có sau vài phút bot chạy.</p>'}
      ${g.nextIssue ? `<div class="rnext">Kỳ tiếp <b>${esc(issueShort)}</b> · <span class="cd" data-t="${g.nextTime || ''}">${countdownText(g.nextTime ? g.nextTime - (Date.now() + skew) : null)}</span>${nums ? '<span class="nums">Dàn ' + nums + '</span>' : ''}</div>` : ''}
    </div>`;
  }

  // Đếm ngược tới kỳ tiếp (cập nhật mỗi giây, không cần tải lại dữ liệu)
  setInterval(() => {
    document.querySelectorAll('.rnext .cd[data-t]').forEach((el) => {
      const t = +el.dataset.t;
      if (t) el.textContent = countdownText(t - (Date.now() + skew));
    });
  }, 1000);

  // Vẽ lại; bảng nào đang ở cuối (hoặc lần đầu) thì cuộn tới kết quả mới nhất, còn đang kéo xem thì giữ nguyên
  function render(container, games, serverTime) {
    if (serverTime) skew = serverTime - Date.now();
    const atEnd = {};
    container.querySelectorAll('.road-wrap').forEach((w, i) => { atEnd[i] = w.scrollLeft + w.clientWidth >= w.scrollWidth - 40; });
    const first = !container.children.length;
    container.innerHTML = games.map(card).join('');
    container.querySelectorAll('.road-wrap').forEach((w, i) => { if (first || atEnd[i] !== false) w.scrollLeft = w.scrollWidth; });
  }

  // Xoay màn hình / đổi cỡ cửa sổ: đưa bảng về kết quả mới nhất
  let resizeTimer;
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => document.querySelectorAll('.road-wrap').forEach((w) => { w.scrollLeft = w.scrollWidth; }), 150);
  });

  // Theo dõi cache/ver.json (worker ghi mỗi khi có kỳ mới): kiểm tra mỗi giây, có thay đổi mới gọi reload().
  // File tĩnh nên rất nhẹ; nếu chưa có file thì tự tải lại mỗi 15 giây.
  function live(verUrl, reload, isActive) {
    let last = null, lastLoad = Date.now();
    setInterval(async () => {
      if (isActive && !isActive()) return;
      try {
        const r = await fetch(verUrl + '?t=' + Date.now(), { cache: 'no-store' });
        if (!r.ok) throw new Error();
        const v = (await r.json()).v;
        if (last !== null && v !== last) { lastLoad = Date.now(); reload(); }
        last = v;
      } catch {
        if (Date.now() - lastLoad > 15000) { lastLoad = Date.now(); reload(); }
      }
    }, 1000);
  }

  window.RoadView = { render, layout, live };
})();
