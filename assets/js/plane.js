/* =========================================================
   Параметрический чертёж самолёта (вид сверху).
   MehaPlane.draw(svg, { tier, span, scheme, motors, tilt, vtail, flaps, dims })
   ========================================================= */
(function () {
  const CX = 320;
  const VB = '0 0 640 400';

  const clamp = (v, a, b) => Math.max(a, Math.min(b, v));
  const n = (v) => Math.round(v * 10) / 10;
  const pts = (arr) => 'M ' + arr.map((p) => n(p[0]) + ' ' + n(p[1])).join(' L ') + ' Z';
  const mirror = (arr) => arr.map((p) => [2 * CX - p[0], p[1]]);
  const rect = (x, y, w, h, r = 3) =>
    `M ${n(x + r)} ${n(y)} H ${n(x + w - r)} Q ${n(x + w)} ${n(y)} ${n(x + w)} ${n(y + r)} V ${n(y + h - r)} Q ${n(x + w)} ${n(y + h)} ${n(x + w - r)} ${n(y + h)} H ${n(x + r)} Q ${n(x)} ${n(y + h)} ${n(x)} ${n(y + h - r)} V ${n(y + r)} Q ${n(x)} ${n(y)} ${n(x + r)} ${n(y)} Z`;

  function wingWidth(span) {
    return clamp(300 + ((span - 600) / 2400) * 250, 280, 560);
  }

  // ---- элементы ----
  const body = (d) => `<path class="pl-body" d="${d}" pathLength="1"/>`;
  const detail = (d) => `<path class="pl-detail" d="${d}"/>`;
  const line = (x1, y1, x2, y2, cls = 'pl-detail') => `<line class="${cls}" x1="${n(x1)}" y1="${n(y1)}" x2="${n(x2)}" y2="${n(y2)}"/>`;
  const prop = (x, y, rx) => `<ellipse class="pl-prop" cx="${n(x)}" cy="${n(y)}" rx="${n(rx)}" ry="3.5"/><circle class="pl-hub" cx="${n(x)}" cy="${n(y)}" r="3.5"/>`;
  const disc = (x, y, r) => `<circle class="pl-disc" cx="${n(x)}" cy="${n(y)}" r="${n(r)}"/>` + prop(x, y, r * 0.92);
  const axis = (x1, x2, y) => `<line class="pl-axis" x1="${n(x1)}" y1="${n(y)}" x2="${n(x2)}" y2="${n(y)}"/>`;

  function ribs(x0, x1, y0, c, step = 30) {
    let s = '';
    const count = Math.max(2, Math.round((x1 - x0) / step));
    for (let i = 1; i < count; i++) {
      const x = x0 + ((x1 - x0) * i) / count;
      if (Math.abs(x - CX) < 20) continue;
      s += line(x, y0 + 2, x, y0 + c - 2, 'pl-rib');
    }
    return s;
  }

  function straightWing(h, y0, c, o) {
    let s = body(rect(CX - h, y0, 2 * h, c, 4));
    s += ribs(CX - h, CX + h, y0, c);
    // элероны
    const ay = y0 + c - 13;
    s += line(CX + h * 0.55, ay, CX + h - 4, ay) + line(CX + h * 0.55, ay, CX + h * 0.55, y0 + c);
    s += line(CX - h * 0.55, ay, CX - h + 4, ay) + line(CX - h * 0.55, ay, CX - h * 0.55, y0 + c);
    if (o.flaps) {
      s += line(CX + 22, ay, CX + h * 0.52, ay) + line(CX + h * 0.52, ay, CX + h * 0.52, y0 + c);
      s += line(CX - 22, ay, CX - h * 0.52, ay) + line(CX - h * 0.52, ay, CX - h * 0.52, y0 + c);
    }
    return s;
  }

  function tail(T, y0, o) {
    if (o.vtail) {
      const r = [[CX + 3, y0 - 4], [CX + T * 0.42, y0 + 20], [CX + T * 0.42, y0 + 34], [CX + 3, y0 + 22]];
      return body(pts(r)) + body(pts(mirror(r)));
    }
    let s = body(rect(CX - T / 2, y0, T, 28, 3));
    s += line(CX - T / 2 + 4, y0 + 18, CX + T / 2 - 4, y0 + 18);
    s += line(CX, y0 - 10, CX, y0 + 36, 'pl-fin');
    return s;
  }

  // ---- схемы. Каждая возвращает { svg, tipY } ----
  function trainer(h, o) {
    let s = body(`M ${CX - 16} 100 Q ${CX} 56 ${CX + 16} 100 L ${CX + 14} 205 L ${CX + 5} 336 L ${CX - 5} 336 L ${CX - 14} 205 Z`);
    s += straightWing(h, 128, 58, o);
    s += tail(h * 0.72, 300, o);
    s += prop(CX, 56, 40);
    return { svg: s, tipY: 128 };
  }

  function flyingWing(h, o) {
    const tipLE = 96 + h * 0.5;
    const r = [[CX, 92], [CX + h, tipLE], [CX + h - 8, tipLE + 36], [CX + 20, 232], [CX, 224]];
    let s = body(pts(r)) + body(pts(mirror(r)));
    s += detail(`M ${CX - 11} 132 Q ${CX} 104 ${CX + 11} 132 L ${CX + 9} 176 Q ${CX} 186 ${CX - 9} 176 Z`);
    // элевоны вдоль задней кромки
    const ex = (t) => [CX + 20 + (h - 28) * t, 232 + (tipLE + 36 - 232) * t - 12];
    const a = ex(0.25), b = ex(0.92);
    s += line(a[0], a[1], b[0], b[1]) + line(2 * CX - a[0], a[1], 2 * CX - b[0], b[1]);
    s += line(CX + h - 4, tipLE + 4, CX + h - 6, tipLE + 32, 'pl-fin') + line(CX - h + 4, tipLE + 4, CX - h + 6, tipLE + 32, 'pl-fin');
    s += prop(CX, 236, 32);
    return { svg: s, tipY: tipLE };
  }

  function canard(h, o) {
    let s = body(`M ${CX - 13} 86 Q ${CX} 50 ${CX + 13} 86 L ${CX + 12} 302 L ${CX - 12} 302 Z`);
    s += body(rect(CX - h * 0.36, 106, h * 0.72, 26, 3));
    const r = [[CX + 12, 226], [CX + h, 258], [CX + h, 290], [CX + 12, 288]];
    s += body(pts(r)) + body(pts(mirror(r)));
    s += line(CX + h * 0.55, 276, CX + h - 4, 278) + line(CX - h * 0.55, 276, CX - h + 4, 278);
    s += line(CX + h - 3, 262, CX + h - 3, 288, 'pl-fin') + line(CX - h + 3, 262, CX - h + 3, 288, 'pl-fin');
    s += prop(CX, 308, 36);
    return { svg: s, tipY: 258 };
  }

  function tandem(h, o) {
    let s = body(`M ${CX - 15} 96 Q ${CX} 54 ${CX + 15} 96 L ${CX + 13} 300 L ${CX + 5} 330 L ${CX - 5} 330 L ${CX - 13} 300 Z`);
    s += body(rect(CX - h * 0.8, 112, h * 1.6, 40, 4));
    s += ribs(CX - h * 0.8, CX + h * 0.8, 112, 40);
    s += body(rect(CX - h, 252, 2 * h, 46, 4));
    s += ribs(CX - h, CX + h, 252, 46);
    s += line(CX, 300, CX, 338, 'pl-fin');
    s += prop(CX, 54, 38);
    return { svg: s, tipY: 252 };
  }

  function twinBoom(h, o) {
    const bx = h * 0.4;
    let s = body(`M ${CX - 15} 98 Q ${CX} 60 ${CX + 15} 98 L ${CX + 15} 226 L ${CX - 15} 226 Z`);
    s += straightWing(h, 140, 56, o);
    s += body(rect(CX + bx - 4, 150, 8, 186, 3)) + body(rect(CX - bx - 4, 150, 8, 186, 3));
    s += body(rect(CX - bx - 4, 318, 2 * bx + 8, 24, 3));
    s += line(CX + bx, 312, CX + bx, 346, 'pl-fin') + line(CX - bx, 312, CX - bx, 346, 'pl-fin');
    s += prop(CX, 232, 34);
    return { svg: s, tipY: 140 };
  }

  function stealth(h, o) {
    const W = 2 * h;
    const r = [
      [CX, 70], [CX + W * 0.14, 168], [CX + h, 250], [CX + h - W * 0.05, 272],
      [CX + W * 0.27, 262], [CX + W * 0.18, 292], [CX + W * 0.09, 268], [CX, 294],
    ];
    let s = body(pts(r)) + body(pts(mirror(r)));
    s += detail(`M ${CX} 104 L ${CX + 10} 136 L ${CX} 160 L ${CX - 10} 136 Z`);
    s += line(CX + W * 0.05, 232, CX + W * 0.13, 286, 'pl-fin') + line(CX - W * 0.05, 232, CX - W * 0.13, 286, 'pl-fin');
    s += line(CX + W * 0.14, 168, CX + W * 0.09, 268) + line(CX - W * 0.14, 168, CX - W * 0.09, 268);
    return { svg: s, tipY: 250 };
  }

  function vtol(h, o) {
    const motors = o.motors || 4;
    let s = body(`M ${CX - 18} 106 Q ${CX} 62 ${CX + 18} 106 L ${CX + 16} 212 L ${CX + 6} 326 L ${CX - 6} 326 L ${CX - 16} 212 Z`);
    s += straightWing(h, 150, 50, o);
    s += tail(h * 0.62, 296, o);

    const inner = 24;
    const fr = motors === 4 ? [0.3, 0.74] : [0.5];
    const xs = fr.map((f) => CX + inner + f * (h - inner));
    const gaps = [xs[0] - CX - 16];
    for (let i = 1; i < xs.length; i++) gaps.push(xs[i] - xs[i - 1]);
    gaps.push(CX + h + 30 - xs[xs.length - 1]);
    const r = clamp(Math.min(...gaps) / 2 - 3, 18, 46);

    xs.forEach((x) => {
      [x, 2 * CX - x].forEach((xx) => {
        s += body(rect(xx - 7, 116, 14, 104, 6));
        s += disc(xx, 116, r);
        if (o.tilt !== 'wing') s += axis(xx - 16, xx + 16, 168);
      });
    });
    if (o.tilt === 'wing') s += axis(CX - h - 12, CX + h + 12, 168);
    if (motors === 3) {
      s += body(rect(CX - 6, 318, 12, 34, 5));
      s += disc(CX, 352, 28);
    }
    return { svg: s, tipY: 150 };
  }

  function dims(h, tipY, span) {
    const y = 22, x1 = CX - h, x2 = CX + h, a = 6;
    let s = '';
    s += `<line class="pl-ext" x1="${n(x1)}" y1="${y - 6}" x2="${n(x1)}" y2="${n(tipY - 4)}"/>`;
    s += `<line class="pl-ext" x1="${n(x2)}" y1="${y - 6}" x2="${n(x2)}" y2="${n(tipY - 4)}"/>`;
    s += `<line class="pl-dim" x1="${n(x1)}" y1="${y}" x2="${n(x2)}" y2="${y}"/>`;
    s += `<path class="pl-arrow" d="M ${n(x1)} ${y} l ${a * 1.8} ${-a / 2} v ${a} Z M ${n(x2)} ${y} l ${-a * 1.8} ${-a / 2} v ${a} Z"/>`;
    const label = new Intl.NumberFormat('ru-RU').format(span) + ' мм';
    s += `<text class="pl-dimtext" x="${CX}" y="${y + 4.5}" text-anchor="middle">${label}</text>`;
    return s;
  }

  const SPECIAL = { wing: flyingWing, canard, tandem, twinboom: twinBoom, stealth };

  function draw(svg, o = {}) {
    const span = o.span || 1400;
    const h = wingWidth(span) / 2;
    let res;
    if (o.tier === 'special') res = (SPECIAL[o.scheme] || flyingWing)(h, o);
    else if (o.tier === 'vtol') res = vtol(h, o);
    else res = trainer(h, o);

    svg.setAttribute('viewBox', VB);
    svg.innerHTML = `<g class="pl">${res.svg}</g>` + (o.dims === false ? '' : dims(h, res.tipY, span));
  }

  window.MehaPlane = { draw };
})();
