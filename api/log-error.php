<?php
/* Приём ошибок из браузера (assets/js/errors.js). Отвечает пустым 204. */
declare(strict_types=1);
require __DIR__ . '/lib.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}

// Только со страниц этого сайта
$originHost = strtolower((string)parse_url($_SERVER['HTTP_ORIGIN'] ?? '', PHP_URL_HOST));
$host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '') ?? '');
if ($originHost === '' || $originHost !== $host) {
    http_response_code(403);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$in = strlen($raw) <= 2048 ? json_decode($raw, true) : null;
if (!is_array($in) || !is_string($in['message'] ?? null)) {
    http_response_code(400);
    exit;
}

// Не больше 20 сообщений с одного адреса за 10 минут
if (mp_rate_hit('errlog', 20, 600)) {
    mp_error_record(
        'js',
        $in['message'],
        is_string($in['file'] ?? null) ? $in['file'] : '',
        (int)($in['line'] ?? 0),
        (int)($in['col'] ?? 0),
        is_string($in['page'] ?? null) ? $in['page'] : '',
        is_string($in['v'] ?? null) ? $in['v'] : ''
    );
}
http_response_code(204);
