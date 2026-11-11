/* Главная страница: чертёж на первом экране, витрина, меню, форма */
(function () {
  const C = window.MEHAPLANE;
  const { fmt, store, CALC_KEY } = window.MehaUtil;

  /* ---------- чертежи: карусель, миниатюры, обложки тарифов ---------- */
  const PRESETS = {
    trainer: { tier: 'trainer', span: C.tiers.trainer.span },
    special: { tier: 'special', scheme: 'wing', span: C.tiers.special.span },
    vtol: { tier: 'vtol', motors: 4, tilt: 'nacelle', span: C.tiers.vtol.span },
  };
  document.querySelectorAll('svg[data-slide]').forEach((svg) => MehaPlane.draw(svg, PRESETS[svg.dataset.slide]));
  document.querySelectorAll('svg[data-thumb]').forEach((svg) => MehaPlane.draw(svg, Object.assign({ dims: false }, PRESETS[svg.dataset.thumb])));
  document.querySelectorAll('svg[data-cover]').forEach((svg) => MehaPlane.draw(svg, Object.assign({ dims: false }, PRESETS[svg.dataset.cover])));

  /* ---------- карусель ---------- */
  const feat = document.getElementById('feat');
  if (feat) {
    const slides = Array.from(feat.querySelectorAll('.slide'));
    const items = Array.from(feat.querySelectorAll('.feat-item'));
    const pauseBtn = document.getElementById('feat-pause');
    const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
    let index = 0;
    let stopped = reduce;      // остановлено пользователем или из-за настроек
    let hold = 0;              // наведение / фокус внутри карусели

    function go(i) {
      index = (i + slides.length) % slides.length;
      slides.forEach((s, k) => {
        const on = k === index;
        s.classList.toggle('is-active', on);
        s.inert = !on;
      });
      items.forEach((it, k) => {
        it.classList.toggle('is-active', k === index);
        if (k === index) it.setAttribute('aria-current', 'true'); else it.removeAttribute('aria-current');
        const bar = it.querySelector('.feat-progress');
        bar.style.animation = 'none';
        void bar.offsetWidth;          // перезапуск анимации
        bar.style.animation = '';
      });
    }

    function sync() {
      feat.classList.toggle('is-static', stopped);
      feat.classList.toggle('is-paused', !stopped && hold > 0);
      pauseBtn.classList.toggle('is-stopped', stopped);
      pauseBtn.setAttribute('aria-label', stopped ? 'Запустить прокрутку слайдов' : 'Остановить прокрутку слайдов');
    }

    items.forEach((it) => {
      it.addEventListener('click', () => go(Number(it.dataset.go)));
      it.querySelector('.feat-progress').addEventListener('animationend', () => {
        if (!stopped && it.classList.contains('is-active')) go(index + 1);
      });
    });
    pauseBtn.addEventListener('click', () => { stopped = !stopped; sync(); if (!stopped) go(index); });

    const hot = (d) => () => { hold = Math.max(0, hold + d); sync(); };
    feat.addEventListener('mouseenter', hot(1));
    feat.addEventListener('mouseleave', hot(-1));
    feat.addEventListener('focusin', hot(1));
    feat.addEventListener('focusout', hot(-1));
    document.addEventListener('visibilitychange', () => {
      feat.classList.toggle('is-paused', document.hidden || (!stopped && hold > 0));
    });

    // свайп на телефоне
    let x0 = null;
    const main = document.getElementById('feat-main');
    main.addEventListener('touchstart', (e) => { x0 = e.touches[0].clientX; }, { passive: true });
    main.addEventListener('touchend', (e) => {
      if (x0 === null) return;
      const dx = e.changedTouches[0].clientX - x0;
      if (Math.abs(dx) > 50) go(index + (dx < 0 ? 1 : -1));
      x0 = null;
    });

    go(0);
    sync();
  }

  /* ---------- витрина ---------- */
  const grid = document.getElementById('works-grid');
  const filter = document.getElementById('works-filter');
  const dialog = document.getElementById('work-dialog');
  let lastCard = null;
  let currentWork = null;

  function card(w) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'work';
    btn.dataset.tier = w.tier;
    btn.setAttribute('aria-haspopup', 'dialog');

    const pic = document.createElement('div');
    pic.className = 'work-pic viewport-canvas';
    if (w.photo) {
      const img = document.createElement('img');
      img.src = w.photo; img.alt = w.title; img.loading = 'lazy';
      pic.appendChild(img);
    } else {
      const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      svg.setAttribute('aria-hidden', 'true');
      MehaPlane.draw(svg, Object.assign({ dims: false }, w.drawing));
      pic.appendChild(svg);
    }

    const body = document.createElement('div');
    body.className = 'work-body';
    body.innerHTML = '<div class="work-meta"><span></span><span class="work-status"></span></div><h3 class="h3"></h3><p class="muted"></p>';
    body.querySelector('.work-meta span').textContent = C.tiers[w.tier].name;
    body.querySelector('.work-status').textContent = w.status;
    body.querySelector('h3').textContent = w.title;
    body.querySelector('p').textContent = w.text;

    btn.append(pic, body);
    btn.addEventListener('click', () => openWork(w, btn));
    return btn;
  }

  if (grid) {
    const cta = grid.querySelector('.work-cta');
    const frag = document.createDocumentFragment();
    C.works.forEach((w) => frag.appendChild(card(w)));
    grid.insertBefore(frag, cta);

    const prev = document.getElementById('shelf-prev');
    const next = document.getElementById('shelf-next');
    const step = () => {
      const first = grid.querySelector('.work:not([hidden]), .work-cta');
      return first ? first.getBoundingClientRect().width + 16 : 320;
    };
    const updateArrows = () => {
      const max = grid.scrollWidth - grid.clientWidth - 2;
      prev.disabled = grid.scrollLeft <= 2;
      next.disabled = grid.scrollLeft >= max;
    };
    prev.addEventListener('click', () => grid.scrollBy({ left: -step(), behavior: 'smooth' }));
    next.addEventListener('click', () => grid.scrollBy({ left: step(), behavior: 'smooth' }));
    grid.addEventListener('scroll', updateArrows, { passive: true });
    window.addEventListener('resize', updateArrows);
    requestAnimationFrame(() => { grid.scrollLeft = 0; updateArrows(); });

    filter.addEventListener('click', (e) => {
      const btn = e.target.closest('button[data-filter]');
      if (!btn) return;
      const f = btn.dataset.filter;
      filter.querySelectorAll('button').forEach((b) => b.setAttribute('aria-pressed', String(b === btn)));
      grid.querySelectorAll('.work[data-tier]').forEach((el) => { el.hidden = f !== 'all' && el.dataset.tier !== f; });
      grid.scrollTo({ left: 0 });
      updateArrows();
    });
  }

  function openWork(w, from) {
    currentWork = w;
    lastCard = from;
    document.getElementById('work-dialog-title').textContent = w.title;
    document.getElementById('work-dialog-text').textContent = w.text;
    MehaPlane.draw(document.getElementById('work-dialog-svg'), Object.assign({ dims: false }, w.drawing));
    const specs = document.getElementById('work-dialog-specs');
    specs.innerHTML = '';
    [['Тариф', C.tiers[w.tier].name], ['Статус', w.status]].concat(w.specs || []).forEach(([k, v]) => {
      const dt = document.createElement('dt'); dt.textContent = k;
      const dd = document.createElement('dd'); dd.textContent = v;
      specs.append(dt, dd);
    });
    if (typeof dialog.showModal === 'function') dialog.showModal();
    else dialog.setAttribute('open', '');
  }

  function closeWork() {
    if (dialog.open) dialog.close();
  }

  if (dialog) {
    dialog.addEventListener('click', (e) => {
      if (e.target === dialog || e.target.closest('[data-close]')) closeWork();
    });
    dialog.addEventListener('close', () => lastCard && lastCard.focus());
    document.getElementById('work-dialog-order').addEventListener('click', () => {
      const w = currentWork;
      lastCard = null;
      closeWork();
      if (!w) return;
      document.getElementById('f-tier').value = w.tier;
      const task = document.getElementById('f-task');
      if (!task.value.trim()) task.value = 'Хочу проект, похожий на «' + w.title + '». ';
      document.getElementById('zayavka').scrollIntoView({ behavior: 'smooth' });
      setTimeout(() => document.getElementById('f-name').focus({ preventScroll: true }), 500);
    });
  }

  /* ---------- подсветка пункта меню ---------- */
  const links = Array.from(document.querySelectorAll('.nav-link[href^="#"]'));
  const sections = links.map((a) => document.querySelector(a.getAttribute('href'))).filter(Boolean);
  if ('IntersectionObserver' in window && sections.length) {
    const visible = new Map();
    const io = new IntersectionObserver((entries) => {
      entries.forEach((en) => visible.set(en.target.id, en.isIntersecting));
      const active = sections.find((s) => visible.get(s.id));
      links.forEach((a) => {
        if (active && a.getAttribute('href') === '#' + active.id) a.setAttribute('aria-current', 'true');
        else a.removeAttribute('aria-current');
      });
    }, { rootMargin: '-45% 0px -50% 0px' });
    sections.forEach((s) => io.observe(s));
  }

  /* ---------- форма заявки ---------- */
  const form = document.getElementById('order-form');
  if (!form) return;
  const startedAt = Date.now();

  const attach = document.getElementById('calc-attach');
  const calcField = document.getElementById('f-calc');

  function loadCalc() {
    const raw = store.get(CALC_KEY);
    if (!raw) return;
    try {
      const data = JSON.parse(raw);
      calcField.value = data.text;
      document.getElementById('calc-attach-text').textContent = data.text;
      attach.hidden = false;
      if (data.tier) document.getElementById('f-tier').value = data.tier;
    } catch (e) { store.remove(CALC_KEY); }
  }
  loadCalc();

  document.getElementById('calc-attach-remove').addEventListener('click', () => {
    store.remove(CALC_KEY);
    calcField.value = '';
    attach.hidden = true;
  });

  const rules = {
    name: (v) => (v.trim().length >= 2 ? '' : 'Укажите имя'),
    contact: (v) => {
      const s = v.trim();
      if (!s) return 'Укажите телефон или ник в Telegram';
      const digits = s.replace(/\D/g, '');
      const phone = /^[+\d\s()-]+$/.test(s) && digits.length >= 10 && digits.length <= 15;
      const tg = /^@?[a-zA-Z][a-zA-Z0-9_]{4,31}$/.test(s);
      const mail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(s);
      return phone || tg || mail ? '' : 'Проверьте номер (+7…) или ник в Telegram (@username)';
    },
    task: (v) => (v.trim().length >= 10 ? '' : 'Опишите задачу хотя бы парой предложений'),
    consent: (v, el) => (el.checked ? '' : 'Без согласия на обработку данных мы не можем принять заявку'),
  };

  function check(name) {
    const el = form.elements[name];
    const msg = rules[name](el.value, el);
    const wrap = form.querySelector('[data-field="' + name + '"]');
    wrap.classList.toggle('is-invalid', !!msg);
    document.getElementById('e-' + name).textContent = msg;
    el.setAttribute('aria-invalid', msg ? 'true' : 'false');
    if (msg) el.setAttribute('aria-describedby', 'e-' + name); else el.removeAttribute('aria-describedby');
    return !msg;
  }

  Object.keys(rules).forEach((name) => {
    const el = form.elements[name];
    el.addEventListener(el.type === 'checkbox' ? 'change' : 'blur', () => {
      if (el.type === 'checkbox' || el.value) check(name);
    });
    el.addEventListener('input', () => {
      if (form.querySelector('[data-field="' + name + '"]').classList.contains('is-invalid')) check(name);
    });
  });

  const status = document.getElementById('form-status');
  const submit = document.getElementById('order-submit');

  function setStatus(text, type) {
    status.textContent = text;
    status.className = 'form-status' + (type ? ' ' + type : '');
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    setStatus('');
    const results = Object.keys(rules).map(check);
    if (results.includes(false)) {
      const first = form.querySelector('.is-invalid input, .is-invalid textarea');
      if (first) first.focus();
      return;
    }

    const payload = {
      name: form.elements.name.value.trim(),
      contact: form.elements.contact.value.trim(),
      tier: form.elements.tier.value,
      task: form.elements.task.value.trim(),
      calc: calcField.value,
      consent: true,
      page: location.href.split('#')[0],
      website: form.elements.website.value,
      elapsed: Date.now() - startedAt,
    };

    if (!C.formEndpoint) {
      console.info('[mehaplane] демо-режим, заявка:', payload);
      setStatus('Форма заполнена верно. Сейчас сайт в демо-режиме: заявка никуда не отправляется.', 'ok');
      return;
    }

    submit.disabled = true;
    submit.textContent = 'Отправляем…';
    try {
      const res = await fetch(C.formEndpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      let data = {};
      try { data = await res.json(); } catch (e) { /* не JSON */ }

      if (res.ok && data.ok) {
        form.reset();
        store.remove(CALC_KEY);
        calcField.value = '';
        attach.hidden = true;
        setStatus((data.id ? 'Заявка №' + data.id + ' отправлена.' : 'Заявка отправлена.') + ' Ответим в течение рабочего дня.', 'ok');
        return;
      }
      if (res.status === 422 && data.errors) {
        Object.entries(data.errors).forEach(([name, msg]) => {
          const wrap = form.querySelector('[data-field="' + name + '"]');
          if (!wrap) return;
          wrap.classList.add('is-invalid');
          document.getElementById('e-' + name).textContent = msg;
        });
        const first = form.querySelector('.is-invalid input, .is-invalid textarea');
        if (first) first.focus();
        return;
      }
      setStatus(data.message || 'Не удалось отправить заявку. Попробуйте ещё раз или напишите нам в Telegram.', 'err');
    } catch (err) {
      setStatus('Не удалось отправить заявку. Проверьте интернет и попробуйте ещё раз или напишите нам в Telegram.', 'err');
    } finally {
      submit.disabled = false;
      submit.textContent = 'Отправить заявку';
    }
  });
})();
