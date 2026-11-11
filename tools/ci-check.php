<?php
/* Проверка сайта перед слиянием: те же правила, что у инструмента check_site.
   Запуск из корня проекта: php tools/ci-check.php
   Настроек с паролями в репозитории нет, поэтому база, Telegram и сертификат здесь не проверяются. */
declare(strict_types=1);
$root = dirname(__DIR__);
$made = false;
if (!is_file($root . '/api/config.php')) {
    copy($root . '/api/config.example.php', $root . '/api/config.php');
    $made = true;
}
require $root . '/api/site-check.php';
$r = mp_site_check();
if ($made) {
    unlink($root . '/api/config.php');
}
$errors = 0;
foreach ($r['problems'] as $p) {
    if (in_array($p['where'], ['api/config.php', 'SSL'], true)) {
        continue;
    }
    echo ($p['level'] === 'error' ? 'Ошибка' : 'Замечание') . ' — ' . $p['where'] . ': ' . $p['text'] . "\n";
    $errors += $p['level'] === 'error' ? 1 : 0;
}
echo 'Проверено страниц: ' . $r['checked']['pages'] . ', ссылок: ' . $r['checked']['links'] . ', ошибок: ' . $errors . "\n";
exit($errors ? 1 : 0);
