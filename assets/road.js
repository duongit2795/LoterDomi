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
    return `Kỳ ${issue}\nDàn ${plan} (kỳ thứ ${nth} của dàn)\nSố về: ${open || '–'}\n${wl === 'W' ? 'W – trúng' : 'L – không trúng'}`;
  }

  function card(g) {
    const items = g.road.map((raw) => ({ raw, type: raw[1] }));
    const wins = items.filter((i) => i.type === 'W').length, losses = items.length - wins;
    const lastType = items.length ? items[items.length - 1].type : null;
    let streak = 0;
    for (let i = items.length - 1; i >= 0 && items[i].type === lastType; i--) streak++;
    const placed = layout(items);
    const cols = Math.max(...placed.map((p) => p.c + 1), 1);
    const cells = placed.map((p, i) => `<div class="cell" style="grid-column:${p.c + 1};grid-row:${p.r + 1}"><span class="ball ${p.it.type === 'W' ? 'w' : 'l'}${i === placed.length - 1 ? ' last' : ''}" title="${esc(title(p.it.raw))}">${p.it.type}</span></div>`).join('');
    const streakTxt = lastType ? `Đang ${lastType === 'W' ? '🔥 WIN' : '🧊 LOST'} ${streak} kỳ` : '';
    return `<div class="card">
      <div class="road-head">
        <h2>${esc(g.name)}</h2>
        <span class="pill w">WIN: ${wins}</span><span class="pill l">LOST: ${losses}</span>
        <span class="muted">${streakTxt}${g.plan ? ' · Kế hoạch kỳ tiếp: <b>' + esc(g.plan) + '</b>' : ''}</span>
      </div>
      <div class="road-wrap"><div class="road" style="grid-template-columns:repeat(${cols}, ${CELL}px)">${cells}</div></div>
      ${items.length ? '' : '<p class="muted">Chưa có dữ liệu – bảng sẽ có sau vài phút bot chạy.</p>'}
    </div>`;
  }

  // Vẽ lại; bảng nào đang ở cuối (hoặc lần đầu) thì cuộn tới kết quả mới nhất, còn đang kéo xem thì giữ nguyên
  function render(container, games) {
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

  window.RoadView = { render, layout };
})();
