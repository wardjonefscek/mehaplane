/* Калькулятор стоимости проекта */
(function () {
  const C = window.MEHAPLANE;
  const { fmt, money, store, CALC_KEY } = window.MehaUtil;
  const $ = (id) => document.getElementById(id);
  const TIERS = Object.keys(C.tiers);
  const kfmt = (k) => '× ' + k.toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const pct = (k) => (k === 1 ? 'базовая' : (k > 1 ? '+' : '−') + Math.round(Math.abs(k - 1) * 100) + ' %');

  function defaults(tier) {
    return {
      tier,
      span: C.tiers[tier].span,
      scheme: C.schemes[0].id,
      motors: 4,
      tilt: C.vtol.tilt[0].id,
      drive: C.vtol.drive[0].id,
      fc: false,
      options: new Set(),
      material: 'epp',
      electronics: C.electronics[0].id,
      docs: new Set(),
      urgency: C.urgency[0].id,
    };
  }

  const fromHash = () => TIERS.find((t) => '#' + C.tiers[t].hash === location.hash);
  let S = defaults(fromHash() || 'trainer');

  /* ---------- построение полей ---------- */
  function radioPill(name, value, text, sub) {
    return `<label class="pill"><input type="radio" name="${name}" value="${value}"><span>${text}${sub ? ` <small>${sub}</small>` : ''}</span></label>`;
  }
  function row(type, name, value, text, priceId) {
    return `<label class="row-opt" data-row="${value}"><input type="${type}" name="${name}" value="${value}"><span>${text}</span><span class="row-price" id="${priceId}"></span></label>`;
  }
  const plus = (v) => (v ? '+' + money(v) : 'входит');

  $('c-tier').innerHTML = TIERS.map((t) => {
    const x = C.tiers[t];
    return `<label class="opt"><input type="radio" name="tier" value="${t}"><span class="opt-box"><span class="opt-name">${x.name}</span><span class="opt-sub num">от ${fmt.format(x.base)} ₽</span></span></label>`;
  }).join('');

  const span = $('c-span');
  span.min = C.span.min; span.max = C.span.max; span.step = C.span.step;
  $('c-span-min').textContent = fmt.format(C.span.min) + ' мм';
  $('c-span-max').textContent = fmt.format(C.span.max) + ' мм';

  $('c-scheme').innerHTML = C.schemes.map((s) => radioPill('scheme', s.id, s.name, s.add ? '+' + fmt.format(s.add) + ' ₽' : '')).join('');
  $('c-motors').innerHTML = C.vtol.motors.map((m) => radioPill('motors', m.n, m.n, m.add ? '+' + fmt.format(m.add) + ' ₽' : '')).join('');
  $('c-tilt').innerHTML = C.vtol.tilt.map((t) => radioPill('tilt', t.id, t.name, t.add ? '+' + fmt.format(t.add) + ' ₽' : '')).join('');
  $('c-drive').innerHTML = C.vtol.drive.map((d) => radioPill('drive', d.id, d.name, d.add ? '+' + fmt.format(d.add) + ' ₽' : '')).join('');
  $('c-fc').innerHTML = row('checkbox', 'fc', 'fc', C.vtol.fc.name, 'p-fc');
  $('p-fc').textContent = plus(C.vtol.fc.add);

  $('c-options').innerHTML = C.options.map((o) => row('checkbox', 'options', o.id, o.name, 'p-opt-' + o.id)).join('');
  C.options.forEach((o) => { $('p-opt-' + o.id).textContent = plus(o.add); });

  $('c-material').innerHTML = C.materials.map((m) => row('radio', 'material', m.id, m.name, 'p-mat-' + m.id)).join('');
  C.materials.forEach((m) => { $('p-mat-' + m.id).textContent = pct(m.k); });

  $('c-electronics').innerHTML = C.electronics.map((e) => row('radio', 'electronics', e.id, e.name, 'p-el-' + e.id)).join('');
  C.electronics.forEach((e) => { $('p-el-' + e.id).textContent = e.add ? '+' + money(e.add) : 'входит'; });

  $('c-docs').innerHTML = C.docs.map((d) => row('checkbox', 'docs', d.id, d.name, 'p-doc-' + d.id)).join('');

  $('c-urgency').innerHTML = C.urgency.map((u) => radioPill('urgency', u.id, u.name, u.k === 1 ? '' : kfmt(u.k))).join('');

  const form = $('calc-form');

  /* ---------- правила доступности ---------- */
  const optionAvailable = (o) => o.tiers.includes(S.tier) && !(S.tier === 'special' && (o.notSchemes || []).includes(S.scheme));
  const docIncluded = (d) => d.included || (d.includedIn || []).includes(S.tier) || d.includedWithMaterial === S.material;

  /* ---------- расчёт ---------- */
  function calc() {
    const t = C.tiers[S.tier];
    const spanK = Math.min(C.span.maxK, Math.max(C.span.minK, 1 + C.span.weight * (S.span - t.span) / t.span));
    const mat = C.materials.find((m) => m.id === S.material);
    const urg = C.urgency.find((u) => u.id === S.urgency);
    const lines = [];
    let add = 0;
    let days = 0;
    const push = (label, value, d) => { lines.push([label, '+' + money(value)]); add += value; days += d || 0; };

    lines.push(['Проект «' + t.name + '»', money(t.base)]);
    lines.push(['Размах ' + fmt.format(S.span) + ' мм', spanK === 1 ? 'базовый' : kfmt(spanK)]);
    lines.push(['Материал: ' + mat.name, mat.k === 1 ? 'базовый' : kfmt(mat.k)]);

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
    C.options.forEach((o) => { if (S.options.has(o.id) && optionAvailable(o)) push(o.name, o.add, o.days); });
    const el = C.electronics.find((x) => x.id === S.electronics);
    if (el.add) push('Подбор электроники', el.add, 1);
    C.docs.forEach((d) => { if (!docIncluded(d) && S.docs.has(d.id)) push(d.name, d.add, d.days); });
    if (urg.k !== 1) lines.push(['Срок: ' + urg.name.toLowerCase(), kfmt(urg.k)]);

    const raw = (t.base * spanK * mat.k + add) * urg.k;
    const total = Math.round(raw / C.rounding) * C.rounding;
    const workDays = Math.max(3, Math.ceil((t.days * (1 + (spanK - 1) * 0.5) + days) * urg.dk));
    return { lines, total, days: workDays };
  }

  const plural = (n, a, b, c) => {
    const m10 = n % 10, m100 = n % 100;
    if (m10 === 1 && m100 !== 11) return a;
    if (m10 >= 2 && m10 <= 4 && (m100 < 10 || m100 >= 20)) return b;
    return c;
  };

  /* ---------- отрисовка ---------- */
  function syncInputs() {
    form.elements.tier.value = S.tier;
    span.value = S.span;
    form.elements.scheme.value = S.scheme;
    form.elements.motors.value = String(S.motors);
    form.elements.tilt.value = S.tilt;
    form.elements.drive.value = S.drive;
    form.elements.fc.checked = S.fc;
    form.elements.material.value = S.material;
    form.elements.electronics.value = S.electronics;
    form.elements.urgency.value = S.urgency;
  }

  function render() {
    const t = C.tiers[S.tier];

    // видимость блоков
    document.querySelectorAll('[data-only]').forEach((fs) => { fs.hidden = fs.dataset.only !== S.tier; });

    // ползунок
    const p = ((S.span - C.span.min) / (C.span.max - C.span.min)) * 100;
    span.style.setProperty('--p', p + '%');
    $('c-span-out').innerHTML = fmt.format(S.span) + ' <small>мм</small>';
    span.setAttribute('aria-valuetext', fmt.format(S.span) + ' миллиметров');

    // опции
    let anyOption = false;
    C.options.forEach((o) => {
      const r = form.querySelector('#c-options [data-row="' + o.id + '"]');
      const ok = optionAvailable(o);
      r.hidden = !ok;
      anyOption = anyOption || ok;
      r.querySelector('input').checked = ok && S.options.has(o.id);
    });
    $('c-options').closest('.fs').hidden = !anyOption;

    // документация
    C.docs.forEach((d) => {
      const input = form.querySelector('#c-docs [data-row="' + d.id + '"] input');
      const inc = docIncluded(d);
      input.disabled = inc;
      input.checked = inc || S.docs.has(d.id);
      const price = $('p-doc-' + d.id);
      price.textContent = inc ? 'входит' : '+' + money(d.add);
      price.classList.toggle('is-included', inc);
    });

    // чертёж
    $('c-view-name').textContent = t.name;
    MehaPlane.draw($('c-svg'), {
      tier: S.tier, span: S.span, scheme: S.scheme, motors: S.motors, tilt: S.tilt,
      vtail: S.options.has('vtail') && optionAvailable(C.options.find((o) => o.id === 'vtail')),
      flaps: S.options.has('flaps') && optionAvailable(C.options.find((o) => o.id === 'flaps')),
    });

    // итог
    const r = calc();
    $('c-lines').innerHTML = '';
    r.lines.forEach(([a, b]) => {
      const div = document.createElement('div');
      div.className = 'sline';
      div.innerHTML = '<span></span><span></span>';
      div.children[0].textContent = a;
      div.children[1].textContent = b;
      $('c-lines').appendChild(div);
    });
    $('c-total').textContent = money(r.total);
    $('c-bar-total').textContent = money(r.total);
    $('c-days').textContent = '≈ ' + r.days + ' ' + plural(r.days, 'рабочий день', 'рабочих дня', 'рабочих дней');

    const hash = '#' + t.hash;
    if (location.hash !== hash) {
      try { history.replaceState(null, '', hash); } catch (e) { /* без смены адреса */ }
    }
    return r;
  }

  /* ---------- события ---------- */
  form.addEventListener('input', (e) => {
    const el = e.target;
    switch (el.name) {
      case 'tier': {
        const keep = S;
        S = defaults(el.value);
        S.material = keep.material; S.electronics = keep.electronics; S.urgency = keep.urgency;
        S.docs = keep.docs; S.options = keep.options;
        syncInputs();
        break;
      }
      case 'span': S.span = Number(el.value); break;
      case 'scheme': S.scheme = el.value; break;
      case 'motors': S.motors = Number(el.value); break;
      case 'tilt': S.tilt = el.value; break;
      case 'drive': S.drive = el.value; break;
      case 'fc': S.fc = el.checked; break;
      case 'options': el.checked ? S.options.add(el.value) : S.options.delete(el.value); break;
      case 'material': S.material = el.value; break;
      case 'electronics': S.electronics = el.value; break;
      case 'docs': el.checked ? S.docs.add(el.value) : S.docs.delete(el.value); break;
      case 'urgency': S.urgency = el.value; break;
      default: return;
    }
    render();
  });

  window.addEventListener('hashchange', () => {
    const t = fromHash();
    if (t && t !== S.tier) { S = defaults(t); syncInputs(); render(); }
  });

  $('c-reset').addEventListener('click', () => {
    S = defaults(S.tier);
    syncInputs();
    render();
  });

  function summaryText() {
    const t = C.tiers[S.tier];
    const r = calc();
    const out = ['Тариф: ' + t.name, 'Размах: ' + fmt.format(S.span) + ' мм'];
    if (S.tier === 'special') out.push('Схема: ' + C.schemes.find((x) => x.id === S.scheme).name);
    if (S.tier === 'vtol') {
      out.push('Моторов: ' + S.motors + ', поворот: ' + C.vtol.tilt.find((x) => x.id === S.tilt).name.toLowerCase() +
        ', привод: ' + C.vtol.drive.find((x) => x.id === S.drive).name.toLowerCase() + (S.fc ? ', настройка контроллера' : ''));
    }
    const opts = C.options.filter((o) => S.options.has(o.id) && optionAvailable(o)).map((o) => o.name.toLowerCase());
    if (opts.length) out.push('Опции: ' + opts.join(', '));
    out.push('Материал: ' + C.materials.find((m) => m.id === S.material).name);
    out.push('Электроника: ' + C.electronics.find((e) => e.id === S.electronics).name.toLowerCase());
    const docs = C.docs.filter((d) => docIncluded(d) || S.docs.has(d.id)).map((d) => d.name);
    out.push('Документация: ' + docs.join('; '));
    out.push('Срок: ' + C.urgency.find((u) => u.id === S.urgency).name.toLowerCase());
    out.push('Итого ≈ ' + money(r.total) + ', ≈ ' + r.days + ' ' + plural(r.days, 'рабочий день', 'рабочих дня', 'рабочих дней'));
    return out.join('\n');
  }

  function order() {
    store.set(CALC_KEY, JSON.stringify({ tier: S.tier, text: summaryText() }));
    location.href = 'index.html#zayavka';
  }
  $('c-order').addEventListener('click', order);
  $('c-bar-order').addEventListener('click', order);

  // нижняя панель прячется, когда итог виден целиком
  const bar = $('calc-bar');
  if ('IntersectionObserver' in window) {
    new IntersectionObserver(([en]) => bar.classList.toggle('is-hidden', en.isIntersecting), { threshold: 0.35 })
      .observe($('c-summary'));
  }

  syncInputs();
  render();
})();
