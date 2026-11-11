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

[$fields, $errors] = mp_lead_validate($in);
if ($errors) {
    mp_out(422, ['ok' => false, 'errors' => $errors]);
}

$res = mp_lead_save($fields, 'site');
if (!$res['ok']) {
    if ($res['why'] === 'nodb') {
        mp_out(503, ['ok' => false, 'message' => 'Приём заявок временно не работает. Напишите нам в Telegram.']);
    }
    if ($res['why'] === 'rate') {
        mp_out(429, ['ok' => false, 'message' => 'Слишком много заявок подряд. Попробуйте через несколько минут или напишите нам в Telegram.']);
    }
    mp_out(500, ['ok' => false, 'message' => 'Не удалось сохранить заявку. Попробуйте ещё раз или напишите нам в Telegram.']);
}

mp_out(200, ['ok' => true, 'id' => $res['id']]);
