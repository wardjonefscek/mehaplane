/* Сбор ошибок страницы: отправляет текст ошибки, файл и строку на сервер,
   чтобы владелец увидел поломку. Личных данных и адресов с параметрами не шлёт.
   Подключается первым, до остальных скриптов. */
(function () {
  var URL_ = 'api/log-error.php';
  var VERSION = '5';
  var MAX = 5; // не больше пяти сообщений за одно открытие страницы
  var sent = 0;
  var seen = {};

  function send(message, file, line, col) {
    message = String(message || '').slice(0, 500);
    if (!message || sent >= MAX) return;
    var key = message + '|' + file + '|' + line;
    if (seen[key]) return;
    seen[key] = true;
    sent++;
    var body = JSON.stringify({
      message: message,
      file: String(file || '').split('?')[0].slice(0, 255),
      line: line || 0,
      col: col || 0,
      page: location.pathname,
      v: VERSION,
    });
    try {
      if (navigator.sendBeacon && navigator.sendBeacon(URL_, body)) return;
      fetch(URL_, { method: 'POST', body: body, keepalive: true }).catch(function () {});
    } catch (e) { /* сбор ошибок не должен ломать страницу */ }
  }

  window.addEventListener('error', function (e) {
    if (e.message) {
      // «Script error.» без подробностей — ошибка чужого скрипта (расширения браузера), пропускаем
      if (e.message === 'Script error.' && !e.filename) return;
      send(e.message, e.filename, e.lineno, e.colno);
    } else if (e.target && e.target !== window && (e.target.src || e.target.href)) {
      var tag = (e.target.tagName || '').toLowerCase();
      if (tag === 'script' || tag === 'link' || tag === 'img') {
        send('Не загрузился файл <' + tag + '>', e.target.src || e.target.href, 0, 0);
      }
    }
  }, true);

  window.addEventListener('unhandledrejection', function (e) {
    var r = e.reason;
    var stack = r && r.stack ? String(r.stack) : '';
    var m = stack.match(/(https?:\/\/[^\s)]+?):(\d+):(\d+)/);
    send('Unhandled rejection: ' + (r && r.message ? r.message : String(r)), m ? m[1] : '', m ? +m[2] : 0, m ? +m[3] : 0);
  });
})();
