/* Формула цены и срока. Общая для калькулятора на сайте и для проверки
   в tools/test-pricing.js. Та же формула на PHP — в api/pricing.php:
   меняешь здесь — меняй и там, тест покажет расхождение. */
(function (root) {
  function optionAvailable(C, S, o) {
    return o.tiers.includes(S.tier) && !(S.tier === 'special' && (o.notSchemes || []).includes(S.scheme));
  }
  function docIncluded(C, S, d) {
    return !!d.included || (d.includedIn || []).includes(S.tier) || d.includedWithMaterial === S.material;
  }

  /* S: { tier, span, scheme, motors, tilt, drive, fc, options:Set, material, electronics, docs:Set, urgency }
     h: { money(n), fmt(n), kfmt(k) } — форматирование строк расчёта */
  function calc(C, S, h) {
    const t = C.tiers[S.tier];
    const spanK = Math.min(C.span.maxK, Math.max(C.span.minK, 1 + C.span.weight * (S.span - t.span) / t.span));
    const mat = C.materials.find((m) => m.id === S.material);
    const urg = C.urgency.find((u) => u.id === S.urgency);
    const lines = [];
    let add = 0;
    let days = 0;
    const push = (label, value, d) => { lines.push([label, '+' + h.money(value)]); add += value; days += d || 0; };

    lines.push(['Проект «' + t.name + '»', h.money(t.base)]);
    lines.push(['Размах ' + h.fmt(S.span) + ' мм', spanK === 1 ? 'базовый' : h.kfmt(spanK)]);
    lines.push(['Материал: ' + mat.name, mat.k === 1 ? 'базовый' : h.kfmt(mat.k)]);

    if (S.tier === 'special') {
      const s = C.schemes.find((x) => x.id === S.scheme);
      if (s.add) push('Схема: ' + s.name.toLowerCase(), s.add, s.days); else lines.push(['Схема: ' + s.name.toLowerCase(), 'входит']);
    }
    if (S.tier === 'vtol') {
      const m = C.vtol.motors.find((x) => x.n === S.motors);
      if (m.add) push('Поворотных моторов: ' + m.n, m.add, m.days);
      const tl = C.vtol.tilt.find((x) => x.id === S.tilt);
      if (tl.add) push('Поворот: ' + tl.name.toLowerCase(), tl.add, tl.days);
      const dr = C.vtol.drive.find((x) => x.id === S.drive);
      if (dr.add) push('Привод: ' + dr.name.toLowerCase(), dr.add, dr.days);
      if (S.fc) push('Настройка полётного контроллера', C.vtol.fc.add, C.vtol.fc.days);
    }
    C.options.forEach((o) => { if (S.options.has(o.id) && optionAvailable(C, S, o)) push(o.name, o.add, o.days); });
    const el = C.electronics.find((x) => x.id === S.electronics);
    if (el.add) push('Подбор электроники', el.add, 1);
    C.docs.forEach((d) => { if (!docIncluded(C, S, d) && S.docs.has(d.id)) push(d.name, d.add, d.days); });
    if (urg.k !== 1) lines.push(['Срок: ' + urg.name.toLowerCase(), h.kfmt(urg.k)]);

    const raw = (t.base * spanK * mat.k + add) * urg.k;
    const total = Math.round(raw / C.rounding) * C.rounding;
    const workDays = Math.max(3, Math.ceil((t.days * (1 + (spanK - 1) * 0.5) + days) * urg.dk));
    return { lines, total, days: workDays };
  }

  const api = { calc, optionAvailable, docIncluded };
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  else root.MehaPrice = api;
})(typeof window !== 'undefined' ? window : globalThis);
