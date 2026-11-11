<?php
/* Приём заявки с сайта. Принимает POST (JSON), отвечает JSON. */
declare(strict_types=1);
require __DIR__ . '/lib.php';

header('X-Content-Type-Options: nosniff');

function mp_out(int $code, array $data): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    mp_out(405, ['ok' => false, 'message' => 'Заявки принимаются только через форму на сайте.']);
}

// Заявка должна прийти с этого же сайта
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    $originHost = strtolower((string)parse_url($origin, PHP_URL_HOST));
    $host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
    if ($originHost !== $host) {
        mp_out(403, ['ok' => false, 'message' => 'Заявка отправлена не с сайта mehaplane.']);
    }
}

$raw = file_get_contents('php://input') ?: '';
if (strlen($raw) > 20000) {
    mp_out(413, ['ok' => false, 'message' => 'Слишком длинная заявка. Сократите описание задачи.']);
}
$in = json_decode($raw, true);
if (!is_array($in)) {
    $in = $_POST;
}

// Ловушка для ботов: скрытое поле и слишком быстрая отправка.
// Боту отвечаем «успехом», но ничего не сохраняем.
$honeypot = trim((string)($in['website'] ?? ''));
$elapsed = (int)($in['elapsed'] ?? 0);
if ($honeypot !== '' || $elapsed < 3000) {
    mp_out(200, ['ok' => true, 'id' => 0]);
}

$name    = mp_clean((string)($in['name'] ?? ''));
$contact = mp_clean((string)($in['contact'] ?? ''));
$tier    = (string)($in['tier'] ?? '');
$task    = mp_clean((string)($in['task'] ?? ''), true);
$calc    = mp_clean((string)($in['calc'] ?? ''), true);
$page    = mp_clean((string)($in['page'] ?? ''));
$consent = ($in['consent'] ?? false) === true || ($in['consent'] ?? '') === 'on';

$errors = [];
if (mp_len($name) < 2 || mp_len($name) > 80) {
    $errors['name'] = 'Укажите имя';
}
$digits = preg_replace('/\D/', '', $contact) ?? '';
$isPhone = (bool)preg_match('/^[+\d\s()\-]+$/', $contact) && strlen($digits) >= 10 && strlen($digits) <= 15;
$isTg = (bool)preg_match('/^@?[a-zA-Z][a-zA-Z0-9_]{4,31}$/', $contact);
$isMail = (bool)filter_var($contact, FILTER_VALIDATE_EMAIL);
if (mp_len($contact) > 80 || !($isPhone || $isTg || $isMail)) {
    $errors['contact'] = 'Проверьте номер (+7…) или ник в Telegram (@username)';
}
if (!array_key_exists($tier, MP_TIERS)) {
    $tier = '';
}
if (mp_len($task) < 10 || mp_len($task) > 2000) {
    $errors['task'] = 'Опишите задачу хотя бы парой предложений';
}
if (!$consent) {
    $errors['consent'] = 'Без согласия на обработку данных мы не можем принять заявку';
}
if ($errors) {
    mp_out(422, ['ok' => false, 'errors' => $errors]);
}

if (!mp_db_ready()) {
    error_log('[mehaplane] база не настроена в api/config.php');
    mp_out(503, ['ok' => false, 'message' => 'Приём заявок временно не работает. Напишите нам в Telegram.']);
}

try {
    $db = mp_db();
    $ip = mp_ip_hash();
    $rl = mp_cfg()['rate_limit'];

    $since = date('Y-m-d H:i:s', time() - 60 * (int)$rl['minutes']);
    $st = $db->prepare('SELECT COUNT(*) FROM leads WHERE ip_hash = ? AND created_at > ?');
    $st->execute([$ip, $since]);
    if ((int)$st->fetchColumn() >= (int)$rl['max']) {
        mp_out(429, ['ok' => false, 'message' => 'Слишком много заявок подряд. Попробуйте через несколько минут или напишите нам в Telegram.']);
    }

    $now = mp_now();
    $st = $db->prepare('INSERT INTO leads
        (created_at, name, contact, tier, task, calc, consent_at, consent_version, page, ip_hash, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $st->execute([
        $now, $name, $contact, $tier, $task,
        $calc !== '' ? mb_substr($calc, 0, 4000) : null,
        $now, (string)mp_cfg()['consent_version'],
        mb_substr($page, 0, 255), $ip, 'new',
    ]);
    $id = (int)$db->lastInsertId();
} catch (Throwable $e) {
    error_log('[mehaplane] ошибка базы: ' . $e->getMessage());
    mp_out(500, ['ok' => false, 'message' => 'Не удалось сохранить заявку. Попробуйте ещё раз или напишите нам в Telegram.']);
}

// Уведомление без личных данных: номер, тариф, время
$tierName = $tier !== '' ? MP_TIERS[$tier] : 'не выбран';
mp_tg_send("Новая заявка №{$id}\nТариф: {$tierName}\n" . date('d.m.Y H:i') . "\n" . rtrim(mp_cfg()['site_url'], '/') . '/admin/');

mp_out(200, ['ok' => true, 'id' => $id]);
