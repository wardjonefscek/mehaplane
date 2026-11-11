<?php
/* Проверка сайта по файлам на сервере: страницы из sitemap.xml, ссылки, разметка, цены, сертификат.
   Используется инструментом MCP check_site и страницей /admin/. Ничего не меняет. */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/mcp-core.php';
require_once __DIR__ . '/pricing.php';

/* Возвращает ['checked' => […], 'problems' => [['level' => 'error'|'warning', 'where' => …, 'text' => …], …]] */
function mp_site_check(): array
{
    $root = dirname(__DIR__);
    $site = rtrim((string)mp_cfg()['site_url'], '/');
    $host = strtolower((string)parse_url($site, PHP_URL_HOST));
    $problems = [];
    $bad = static function (string $where, string $text, string $level = 'error') use (&$problems): void {
        $problems[] = ['level' => $level, 'where' => $where, 'text' => $text];
    };
    $checked = ['pages' => 0, 'links' => 0];

    /* Локальный файл для адреса сайта; null — адрес внешний */
    $local = static function (string $url, string $fromDir) use ($root, $host): ?string {
        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($url === '' || $url[0] === '#' || preg_match('/^(mailto|tel|data|javascript):/i', $url)) {
            return null;
        }
        $p = parse_url($url);
        if ($p === false) {
            return null;
        }
        if (isset($p['host'])) {
            if (strtolower($p['host']) !== $host) {
                return null;
            }
            $path = $p['path'] ?? '/';
        } else {
            $path = $p['path'] ?? '';
            if ($path === '') {
                return null;
            }
            if ($path[0] !== '/') {
                $path = $fromDir . '/' . $path;
            }
        }
        $parts = [];
        foreach (explode('/', rawurldecode($path)) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $seg;
        }
        return $root . ($parts ? '/' . implode('/', $parts) : '');
    };
    $exists = static function (string $file): bool {
        return is_file($file) || (is_dir($file) && (is_file($file . '/index.html') || is_file($file . '/index.php')));
    };

    /* ---------- настройки и цены ---------- */
    $C = null;
    try {
        $C = mp_site_data();
        foreach (['tiers', 'span', 'materials', 'schemes', 'options', 'vtol', 'electronics', 'docs', 'urgency', 'works', 'rounding'] as $k) {
            if (!isset($C[$k])) {
                $bad('assets/js/config.js', 'Нет раздела «' . $k . '».');
            }
        }
        foreach (array_keys($C['tiers'] ?? []) as $tier) {
            mp_price(mp_price_input(['tier' => $tier]));
        }
    } catch (Throwable $e) {
        $bad('assets/js/config.js', $e->getMessage() . ' Калькулятор и MCP считают по этому файлу.');
        $C = null;
    }

    /* ---------- текстовые файлы ---------- */
    foreach (['robots.txt', 'sitemap.xml', 'llms.txt'] as $f) {
        $body = @file_get_contents($root . '/' . $f);
        if (!is_string($body) || $body === '') {
            $bad($f, 'Файл не найден или пуст.');
        } elseif (!mb_check_encoding($body, 'UTF-8')) {
            $bad($f, 'Файл не в кодировке UTF-8.');
        }
    }
    $robots = (string)@file_get_contents($root . '/robots.txt');
    if ($robots !== '' && stripos($robots, 'Sitemap:') === false) {
        $bad('robots.txt', 'Нет строки Sitemap.', 'warning');
    }

    /* ---------- страницы из карты сайта ---------- */
    $pages = [];
    if (preg_match_all('/<loc>\s*([^<\s]+)\s*<\/loc>/i', (string)@file_get_contents($root . '/sitemap.xml'), $m)) {
        $pages = $m[1];
    } else {
        $bad('sitemap.xml', 'В карте сайта нет ни одной страницы.');
    }

    foreach ($pages as $url) {
        $file = $local($url, '');
        if ($file === null) {
            $bad('sitemap.xml', 'Адрес не с этого сайта: ' . $url);
            continue;
        }
        if (is_dir($file)) {
            $file .= '/index.html';
        }
        $rel = ltrim(substr($file, strlen($root)), '/');
        if (!is_file($file)) {
            $bad('sitemap.xml', 'Страницы нет на сервере: ' . $rel);
            continue;
        }
        $checked['pages']++;
        $html = (string)file_get_contents($file);
        $dir = trim(dirname($rel), './');

        if (!preg_match('/<title>\s*\S.*?<\/title>/is', $html)) {
            $bad($rel, 'Нет заголовка <title>.');
        }
        if (!preg_match('/<meta\s+name=["\']description["\']\s+content=["\']\s*\S/i', $html)) {
            $bad($rel, 'Нет описания <meta name="description">.');
        }
        if (!preg_match('/<link\s+rel=["\']canonical["\']\s+href=["\']([^"\']+)/i', $html, $c)) {
            $bad($rel, 'Нет <link rel="canonical">.', 'warning');
        } elseif (rtrim($c[1], '/') !== rtrim($url, '/')) {
            $bad($rel, 'canonical (' . $c[1] . ') не совпадает с адресом в sitemap.xml.', 'warning');
        }
        if (substr_count(strtolower($html), '<h1') !== 1) {
            $bad($rel, 'На странице должен быть ровно один заголовок <h1>.', 'warning');
        }

        // разметка JSON-LD
        $broken = [];
        $nodes = mp_jsonld($html, $broken);
        foreach ($broken as $n) {
            $bad($rel, 'Блок JSON-LD №' . $n . ' не читается как JSON.');
        }
        if ($C !== null) {
            foreach ($nodes as $node) {
                if (($node['@type'] ?? '') !== 'Service') {
                    continue;
                }
                foreach ($C['tiers'] as $t) {
                    if (substr((string)($node['@id'] ?? ''), -strlen('#' . $t['hash'])) === '#' . $t['hash']
                        && (int)($node['offers']['price'] ?? -1) !== (int)$t['base']) {
                        $bad($rel, 'Цена тарифа «' . $t['name'] . '» в разметке (' . ($node['offers']['price'] ?? '—') . ' ₽) не совпадает с config.js (' . $t['base'] . ' ₽).');
                    }
                }
            }
        }

        // якоря и внутренние ссылки
        preg_match_all('/\sid=["\']([^"\']+)["\']/i', $html, $ids);
        $ids = array_flip($ids[1]);
        preg_match_all('/<(?:a|link|script|img|source)\b[^>]*?\s(?:href|src)=["\']([^"\']*)["\']/i', $html, $links);
        foreach (array_unique($links[1]) as $href) {
            if ($href !== '' && $href[0] === '#') {
                if (strlen($href) > 1 && !isset($ids[substr($href, 1)])) {
                    $bad($rel, 'Ссылка на раздел ' . $href . ', которого нет на странице.');
                }
                $checked['links']++;
                continue;
            }
            $target = $local($href, $dir);
            if ($target === null) {
                continue;
            }
            $checked['links']++;
            if (!$exists($target)) {
                $bad($rel, 'Битая ссылка: ' . $href);
            }
        }
    }

    /* ---------- приём заявок и база ---------- */
    foreach (['api/submit.php', 'api/log-error.php', 'mcp/index.php', 'mcp/service/index.php', 'admin/index.php'] as $f) {
        if (!is_file($root . '/' . $f)) {
            $bad($f, 'Файла нет на сервере.');
        }
    }
    if (!mp_db_ready()) {
        $bad('api/config.php', 'База не настроена: заявки не сохраняются.');
    } else {
        try {
            mp_db()->query('SELECT 1 FROM leads LIMIT 1');
        } catch (Throwable $e) {
            $bad('api/config.php', 'Нет связи с базой: заявки не сохраняются.');
        }
    }
    if (!mp_tg_ready()) {
        $bad('api/config.php', 'Уведомления в Telegram выключены.', 'warning');
    }

    /* ---------- сертификат ---------- */
    if (stripos($site, 'https://') === 0 && function_exists('openssl_x509_parse')) {
        $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $sock = @stream_socket_client('ssl://' . $host . ':443', $en, $es, 5, STREAM_CLIENT_CONNECT, $ctx);
        $cert = $sock ? (stream_context_get_params($sock)['options']['ssl']['peer_certificate'] ?? null) : null;
        $parsed = $cert ? openssl_x509_parse($cert) : false;
        if ($sock) {
            fclose($sock);
        }
        if (!$parsed) {
            $bad('SSL', 'Не удалось проверить сертификат с сервера.', 'warning');
        } else {
            $daysLeft = (int)floor(($parsed['validTo_time_t'] - time()) / 86400);
            $checked['ssl_days_left'] = $daysLeft;
            if ($daysLeft < 14) {
                $bad('SSL', 'Сертификат истекает через ' . $daysLeft . ' дн.', $daysLeft < 3 ? 'error' : 'warning');
            }
        }
    }

    return ['checked' => $checked, 'problems' => $problems];
}
