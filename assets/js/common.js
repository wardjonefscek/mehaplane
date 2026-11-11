/* Общее для всех страниц: меню, контакты, форматирование, хранилище */
(function () {
  const C = window.MEHAPLANE;

  const fmt = new Intl.NumberFormat('ru-RU');
  const money = (v) => fmt.format(Math.round(v)) + ' ₽';

  // безопасное хранилище: в некоторых браузерах доступ к нему запрещён
  const store = {
    get(key) {
      try { return sessionStorage.getItem(key) ?? localStorage.getItem(key); } catch (e) { return null; }
    },
    set(key, val) {
      try { sessionStorage.setItem(key, val); return; } catch (e) { /* пробуем localStorage */ }
      try { localStorage.setItem(key, val); } catch (e) { /* без хранилища */ }
    },
    remove(key) {
      try { sessionStorage.removeItem(key); } catch (e) {}
      try { localStorage.removeItem(key); } catch (e) {}
    },
  };

  // меню на телефоне
  const header = document.querySelector('.header');
  const burger = document.querySelector('.burger');
  function setNav(open) {
    if (!header || !burger) return;
    header.classList.toggle('nav-open', open);
    burger.setAttribute('aria-expanded', String(open));
    burger.setAttribute('aria-label', open ? 'Закрыть меню' : 'Открыть меню');
  }
  if (burger) {
    burger.addEventListener('click', () => setNav(!header.classList.contains('nav-open')));
    header.querySelectorAll('.nav a').forEach((a) => a.addEventListener('click', () => setNav(false)));
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && header.classList.contains('nav-open')) { setNav(false); burger.focus(); }
    });
    document.addEventListener('click', (e) => {
      if (header.classList.contains('nav-open') && !header.contains(e.target)) setNav(false);
    });
  }

  // контакты из настроек
  if (C && C.contacts) {
    document.querySelectorAll('[data-tg]').forEach((a) => {
      a.href = 'https://t.me/' + C.contacts.telegram;
      a.textContent = '@' + C.contacts.telegram;
    });
    document.querySelectorAll('[data-email]').forEach((a) => {
      a.href = 'mailto:' + C.contacts.email;
      a.textContent = C.contacts.email;
    });
  }

  // цены и сроки тарифов из настроек
  if (C && C.tiers) {
    document.querySelectorAll('[data-price]').forEach((el) => {
      const t = C.tiers[el.dataset.price];
      if (t) el.textContent = fmt.format(t.base);
    });
    document.querySelectorAll('[data-days]').forEach((el) => {
      const t = C.tiers[el.dataset.days];
      if (t) el.textContent = t.days;
    });
  }

  // кнопка «наверх»
  const toTop = document.getElementById('to-top');
  if (toTop) {
    const onScroll = () => toTop.classList.toggle('is-visible', window.scrollY > 900);
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
    toTop.addEventListener('click', () => {
      window.scrollTo({ top: 0, behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
      document.querySelector('.logo')?.focus({ preventScroll: true });
    });
  }

  window.MehaUtil = { fmt, money, store, CALC_KEY: 'mehaplane-calc' };
})();
