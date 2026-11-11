<?php
/* Страница заявок mehaplane. Вход по паролю из api/config.php */
declare(strict_types=1);
require __DIR__ . '/../api/lib.php';

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('mp_admin');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/admin/',
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

$nonce = base64_encode(random_bytes(12));
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; script-src 'nonce-$nonce'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

$cfg = mp_cfg();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function mp_redirect(string $query = ''): void
{
    header('Location: ./' . $query, true, 303);
    exit;
}
function mp_flash(string $text, string $type = 'ok'): void
{
    $_SESSION['flash'] = ['text' => $text, 'type' => $type];
}
function mp_csrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

/* ---------- проверки настроек ---------- */
$setupError = '';
if ($cfg['admin_password'] === '') {
    $setupError = 'Пароль не задан. Впиши его в поле admin_password в файле api/config.php.';
} elseif (mp_len($cfg['admin_password']) < 12) {
    $setupError = 'Пароль слишком короткий. В api/config.php нужен пароль от 12 символов.';
} elseif (!mp_db_ready()) {
    $setupError = 'База не настроена. Впиши имя базы, пользователя и пароль в api/config.php.';
} else {
    try {
        mp_db();
    } catch (Throwable $e) {
        error_log('[mehaplane] admin db: ' . $e->getMessage());
        $setupError = 'Не удалось подключиться к базе. Проверь имя базы, пользователя и пароль в api/config.php.';
    }
}

$authed = !empty($_SESSION['auth']);
$action = $_POST['action'] ?? '';

/* ---------- вход ---------- */
if (!$setupError && !$authed && $action === 'login') {
    $db = mp_db();
    $ip = mp_ip_hash();
    $st = $db->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip_hash = ? AND created_at > ?');
    $st->execute([$ip, date('Y-m-d H:i:s', time() - 900)]);
    if ((int)$st->fetchColumn() >= 5) {
        mp_flash('Слишком много попыток входа. Подожди 15 минут.', 'err');
        mp_redirect();
    }
    if (hash_equals((string)$cfg['admin_password'], (string)($_POST['password'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['auth'] = true;
        $db->prepare('DELETE FROM login_attempts WHERE ip_hash = ?')->execute([$ip]);
        mp_redirect();
    }
    $db->prepare('INSERT INTO login_attempts (ip_hash, created_at) VALUES (?, ?)')->execute([$ip, mp_now()]);
    sleep(1);
    mp_flash('Неверный пароль.', 'err');
    mp_redirect();
}

/* ---------- действия ---------- */
$tgChats = null;
if (!$setupError && $authed && $action !== '') {
    if (!hash_equals(mp_csrf(), (string)($_POST['csrf'] ?? ''))) {
        mp_flash('Страница устарела. Повтори действие.', 'err');
        mp_redirect();
    }
    $back = isset($_POST['back']) && preg_match('/^\?status=(new|work|done|all)$/', $_POST['back']) ? $_POST['back'] : '';
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        mp_redirect();
    }
    if ($action === 'status' && isset(MP_STATUSES[$_POST['status'] ?? ''])) {
        mp_db()->prepare('UPDATE leads SET status = ? WHERE id = ?')->execute([$_POST['status'], $id]);
        mp_flash('Заявка №' . $id . ': статус «' . MP_STATUSES[$_POST['status']] . '».');
        mp_redirect($back);
    }
    if ($action === 'delete') {
        mp_db()->prepare('DELETE FROM leads WHERE id = ?')->execute([$id]);
        mp_flash('Заявка №' . $id . ' удалена вместе с личными данными.');
        mp_redirect($back);
    }
    if ($action === 'tg_test') {
        $ok = mp_tg_send("Проверка уведомлений mehaplane: всё работает.");
        mp_flash($ok ? 'Тестовое уведомление отправлено в Telegram.' : 'Не получилось отправить. Проверь токен и chat_id в api/config.php и что ты написал боту /start.', $ok ? 'ok' : 'err');
        mp_redirect($back);
    }
    if ($action === 'tg_find') {
        $tgChats = mp_tg_chats();
        if ($tgChats === null) {
            mp_flash('Не получилось связаться с ботом. Проверь токен в api/config.php.', 'err');
            mp_redirect($back);
        }
    }
}

/* ---------- данные для страницы ---------- */
$filter = $_GET['status'] ?? 'new';
if ($filter !== 'all' && !isset(MP_STATUSES[$filter])) {
    $filter = 'new';
}
$leads = [];
$counts = ['new' => 0, 'work' => 0, 'done' => 0, 'all' => 0];
if (!$setupError && $authed) {
    $db = mp_db();
    foreach ($db->query('SELECT status, COUNT(*) AS n FROM leads GROUP BY status') as $r) {
        $counts[$r['status']] = (int)$r['n'];
        $counts['all'] += (int)$r['n'];
    }
    if ($filter === 'all') {
        $leads = $db->query('SELECT * FROM leads ORDER BY id DESC LIMIT 500')->fetchAll();
    } else {
        $st = $db->prepare('SELECT * FROM leads WHERE status = ? ORDER BY id DESC LIMIT 500');
        $st->execute([$filter]);
        $leads = $st->fetchAll();
    }
}

function mp_contact_link(string $c): string
{
    $digits = preg_replace('/\D/', '', $c) ?? '';
    if (preg_match('/^@?[a-zA-Z][a-zA-Z0-9_]{4,31}$/', $c)) {
        return '<a href="https://t.me/' . mp_h(ltrim($c, '@')) . '" rel="noopener">' . mp_h($c) . '</a>';
    }
    if (filter_var($c, FILTER_VALIDATE_EMAIL)) {
        return '<a href="mailto:' . mp_h($c) . '">' . mp_h($c) . '</a>';
    }
    if (strlen($digits) >= 10) {
        return '<a href="tel:+' . mp_h($digits) . '">' . mp_h($c) . '</a>';
    }
    return mp_h($c);
}
$back = '?status=' . $filter;
?><!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Заявки — mehaplane</title>
  <link rel="icon" href="../assets/img/favicon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="../assets/css/style.css">
  <style>
    .adm { padding-block: 28px 64px; display: grid; gap: 20px; }
    .adm-top { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
    .adm-card { background: var(--bg-2); border-radius: var(--r-m); padding: 20px; display: grid; gap: 14px; }
    .adm-login { max-width: 420px; margin: 8vh auto 0; }
    .adm-flash { border-radius: var(--r-s); padding: 12px 14px; }
    .adm-flash.ok { background: var(--accent-soft); color: #cfe0f8; }
    .adm-flash.err { background: rgba(239, 125, 117, .12); color: var(--error); }
    .adm-seg a { text-decoration: none; white-space: nowrap; flex: none; }
    .adm-count { color: var(--text-3); margin-left: 4px; }
    .lead-head { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; flex-wrap: wrap; }
    .lead-no { font-weight: 700; font-size: 1.1rem; }
    .lead-meta { color: var(--text-2); font-size: .9rem; }
    .lead-grid { display: grid; grid-template-columns: 160px 1fr; gap: 6px 16px; }
    .lead-grid dt { color: var(--text-3); }
    .lead-task { white-space: pre-line; }
    .lead-calc summary { cursor: pointer; color: var(--text-2); }
    .lead-calc p { white-space: pre-line; color: var(--text-2); margin-top: 8px; font-size: .925rem; }
    .lead-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; border-top: 1px solid var(--line); padding-top: 14px; }
    .lead-actions form { display: flex; gap: 8px; align-items: center; }
    .lead-actions .select { min-height: 38px; padding-block: 6px; width: auto; }
    .btn-danger { background: rgba(239, 125, 117, .12); color: var(--error); }
    .btn-danger:hover { background: rgba(239, 125, 117, .22); color: #ffb0aa; }
    .tg-row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
    .tg-chats { display: grid; gap: 6px; }
    .tg-chats code { background: var(--bg-3); padding: 2px 8px; border-radius: 6px; user-select: all; }
    .empty { color: var(--text-2); padding: 28px; text-align: center; background: var(--bg-2); border-radius: var(--r-m); }
    @media (max-width: 560px) { .lead-grid { grid-template-columns: 1fr; } .lead-grid dd { margin-bottom: 8px; } }
  </style>
</head>
<body>
<main class="wrap adm">

<?php if ($setupError): ?>
  <div class="adm-card adm-login">
    <h1 class="h2">Заявки mehaplane</h1>
    <p class="adm-flash err"><?= mp_h($setupError) ?></p>
  </div>

<?php elseif (!$authed): ?>
  <form class="adm-card adm-login form" method="post" action="./">
    <h1 class="h2">Заявки mehaplane</h1>
    <?php if ($flash): ?><p class="adm-flash <?= mp_h($flash['type']) ?>"><?= mp_h($flash['text']) ?></p><?php endif; ?>
    <div class="field">
      <label for="pw">Пароль</label>
      <input class="input" id="pw" name="password" type="password" autocomplete="current-password" required autofocus>
    </div>
    <input type="hidden" name="action" value="login">
    <button class="btn btn-primary" type="submit">Войти</button>
  </form>

<?php else: ?>
  <div class="adm-top">
    <h1 class="h2">Заявки</h1>
    <div class="tg-row">
      <a class="btn btn-secondary btn-small" href="../">На сайт</a>
      <form method="post" action="./">
        <input type="hidden" name="csrf" value="<?= mp_h(mp_csrf()) ?>">
        <button class="btn btn-secondary btn-small" name="action" value="logout">Выйти</button>
      </form>
    </div>
  </div>

  <?php if ($flash): ?><p class="adm-flash <?= mp_h($flash['type']) ?>" role="status"><?= mp_h($flash['text']) ?></p><?php endif; ?>

  <nav class="seg adm-seg" aria-label="Фильтр по статусу">
    <?php foreach (['new' => 'Новые', 'work' => 'В работе', 'done' => 'Закрытые', 'all' => 'Все'] as $k => $label): ?>
      <a href="?status=<?= $k ?>" aria-pressed="<?= $filter === $k ? 'true' : 'false' ?>"<?= $filter === $k ? ' aria-current="page"' : '' ?> style="padding:8px 14px;border-radius:7px;color:inherit"><?= $label ?><span class="adm-count"><?= $counts[$k] ?></span></a>
    <?php endforeach; ?>
  </nav>

  <?php if (!$leads): ?>
    <p class="empty">Здесь пока пусто. Новые заявки с сайта появятся в разделе «Новые».</p>
  <?php endif; ?>

  <?php foreach ($leads as $l): ?>
    <article class="adm-card">
      <div class="lead-head">
        <span class="lead-no">№<?= (int)$l['id'] ?> · <?= mp_h($l['name']) ?></span>
        <span class="lead-meta"><?= mp_h(date('d.m.Y H:i', strtotime($l['created_at']))) ?> · <?= mp_h(MP_STATUSES[$l['status']] ?? $l['status']) ?></span>
      </div>
      <dl class="lead-grid">
        <dt>Контакт</dt><dd><?= mp_contact_link($l['contact']) ?></dd>
        <dt>Тариф</dt><dd><?= mp_h($l['tier'] !== '' ? MP_TIERS[$l['tier']] : 'Не выбран') ?></dd>
        <dt>Задача</dt><dd class="lead-task"><?= mp_h($l['task']) ?></dd>
        <dt>Согласие</dt><dd class="muted"><?= mp_h(date('d.m.Y H:i', strtotime($l['consent_at']))) ?>, версия <?= mp_h($l['consent_version']) ?></dd>
      </dl>
      <?php if (!empty($l['calc'])): ?>
        <details class="lead-calc"><summary>Расчёт из калькулятора</summary><p><?= mp_h($l['calc']) ?></p></details>
      <?php endif; ?>
      <div class="lead-actions">
        <form method="post" action="./">
          <input type="hidden" name="csrf" value="<?= mp_h(mp_csrf()) ?>">
          <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
          <input type="hidden" name="back" value="<?= mp_h($back) ?>">
          <label class="visually-hidden" for="st<?= (int)$l['id'] ?>">Статус заявки №<?= (int)$l['id'] ?></label>
          <select class="select" id="st<?= (int)$l['id'] ?>" name="status">
            <?php foreach (MP_STATUSES as $k => $label): ?>
              <option value="<?= $k ?>"<?= $l['status'] === $k ? ' selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-secondary btn-small" name="action" value="status">Сохранить статус</button>
        </form>
        <form method="post" action="./" class="js-delete">
          <input type="hidden" name="csrf" value="<?= mp_h(mp_csrf()) ?>">
          <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
          <input type="hidden" name="back" value="<?= mp_h($back) ?>">
          <button class="btn btn-danger btn-small" name="action" value="delete">Удалить заявку</button>
        </form>
      </div>
    </article>
  <?php endforeach; ?>

  <section class="adm-card" aria-labelledby="tg-title">
    <h2 class="h3" id="tg-title">Уведомления в Telegram</h2>
    <p class="muted">
      <?php if (mp_tg_ready()): ?>
        Включены. В уведомлении только номер заявки, тариф и время — личные данные остаются на сервере.
      <?php elseif ($cfg['telegram']['token'] !== ''): ?>
        Токен бота вписан, осталось узнать chat_id: напиши своему боту /start и нажми «Найти chat_id».
      <?php else: ?>
        Выключены. Создай бота через @BotFather и впиши его токен в api/config.php.
      <?php endif; ?>
    </p>
    <?php if (is_array($tgChats)): ?>
      <div class="tg-chats">
        <?php if (!$tgChats): ?>
          <p class="adm-flash err">Бот пока не получал сообщений. Напиши ему /start и нажми кнопку ещё раз.</p>
        <?php else: ?>
          <p>Скопируй нужный chat_id в api/config.php:</p>
          <?php foreach ($tgChats as $cid => $who): ?>
            <p><code><?= mp_h((string)$cid) ?></code> <span class="muted"><?= mp_h($who) ?></span></p>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <?php if ($cfg['telegram']['token'] !== ''): ?>
      <form method="post" action="./" class="tg-row">
        <input type="hidden" name="csrf" value="<?= mp_h(mp_csrf()) ?>">
        <input type="hidden" name="back" value="<?= mp_h($back) ?>">
        <button class="btn btn-secondary btn-small" name="action" value="tg_find">Найти chat_id</button>
        <?php if (mp_tg_ready()): ?>
          <button class="btn btn-secondary btn-small" name="action" value="tg_test">Отправить тестовое уведомление</button>
        <?php endif; ?>
      </form>
    <?php endif; ?>
  </section>

<?php endif; ?>
</main>
<script nonce="<?= mp_h($nonce) ?>">
  document.querySelectorAll('.js-delete').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (!confirm('Удалить заявку безвозвратно? Личные данные заявителя тоже будут удалены.')) e.preventDefault();
    });
  });
</script>
</body>
</html>
