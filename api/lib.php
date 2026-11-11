<?php
/* Общие функции: настройки, база, Telegram. Ничего не выводит. */
declare(strict_types=1);

date_default_timezone_set('Europe/Moscow');

const MP_TIERS = [
    'trainer' => 'Тренер',
    'special' => 'Нестандартная схема',
    'vtol'    => 'Конвертоплан',
];
const MP_STATUSES = [
    'new'  => 'Новая',
    'work' => 'В работе',
    'done' => 'Закрыта',
];

function mp_cfg(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/config.php';
    }
    return $cfg;
}

/* Настройка, которой может не быть в старом config.php */
function mp_opt(string $key, $default)
{
    $cfg = mp_cfg();
    return array_key_exists($key, $cfg) ? $cfg[$key] : $default;
}

function mp_db_ready(): bool
{
    $d = mp_cfg()['db'];
    return $d['name'] !== '' && $d['user'] !== '';
}

function mp_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $d = mp_cfg()['db'];
        $pdo = new PDO(
            'mysql:host=' . $d['host'] . ';dbname=' . $d['name'] . ';charset=utf8mb4',
            $d['user'],
            $d['pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        mp_schema($pdo);
    }
    return $pdo;
}

function mp_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS leads (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        created_at DATETIME NOT NULL,
        name VARCHAR(80) NOT NULL,
        contact VARCHAR(80) NOT NULL,
        tier VARCHAR(16) NOT NULL DEFAULT '',
        task TEXT NOT NULL,
        calc TEXT NULL,
        consent_at DATETIME NOT NULL,
        consent_version VARCHAR(16) NOT NULL,
        page VARCHAR(255) NOT NULL DEFAULT '',
        ip_hash CHAR(64) NOT NULL,
        status VARCHAR(8) NOT NULL DEFAULT 'new',
        KEY idx_created (created_at),
        KEY idx_ip (ip_hash, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Откуда пришла заявка: site — форма, mcp — нейросеть
    if (!$pdo->query("SHOW COLUMNS FROM leads LIKE 'source'")->fetch()) {
        $pdo->exec("ALTER TABLE leads ADD COLUMN source VARCHAR(8) NOT NULL DEFAULT 'site'");
    }

    // Ошибки сайта: одинаковые склеиваются по отпечатку
    $pdo->exec("CREATE TABLE IF NOT EXISTS site_errors (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        fingerprint CHAR(40) NOT NULL,
        type VARCHAR(8) NOT NULL,
        message VARCHAR(500) NOT NULL,
        file VARCHAR(255) NOT NULL DEFAULT '',
        line INT UNSIGNED NOT NULL DEFAULT 0,
        col INT UNSIGNED NOT NULL DEFAULT 0,
        page VARCHAR(255) NOT NULL DEFAULT '',
        version VARCHAR(16) NOT NULL DEFAULT '',
        hits INT UNSIGNED NOT NULL DEFAULT 1,
        first_at DATETIME NOT NULL,
        last_at DATETIME NOT NULL,
        status VARCHAR(8) NOT NULL DEFAULT 'new',
        pr_url VARCHAR(255) NOT NULL DEFAULT '',
        UNIQUE KEY uq_fp (fingerprint),
        KEY idx_status (status, last_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Журнал обращений к MCP и приёму ошибок: без аргументов и личных данных
    $pdo->exec("CREATE TABLE IF NOT EXISTS req_log (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        created_at DATETIME NOT NULL,
        ip_hash CHAR(64) NOT NULL,
        endpoint VARCHAR(16) NOT NULL,
        method VARCHAR(40) NOT NULL DEFAULT '',
        name VARCHAR(64) NOT NULL DEFAULT '',
        KEY idx_rate (endpoint, ip_hash, created_at),
        KEY idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        ip_hash CHAR(64) NOT NULL,
        created_at DATETIME NOT NULL,
        KEY idx_ip (ip_hash, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function mp_now(): string
{
    return date('Y-m-d H:i:s');
}

/* IP-адрес храним только в виде хэша — для ограничения частоты заявок */
function mp_ip_hash(): string
{
    return hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . mp_cfg()['secret']);
}

/* Убираем управляющие символы, кроме переводов строки */
function mp_clean(string $s, bool $multiline = false): string
{
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = preg_replace($multiline ? '/[^\P{C}\n]/u' : '/\p{C}/u', '', $s) ?? '';
    return trim($s);
}

function mp_len(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : (int)preg_match_all('/./us', $s);
}

function mp_h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* ---------- Telegram ---------- */

function mp_http(string $url, ?array $post = null): ?array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_CONNECTTIMEOUT => 4,
        ]);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $body = curl_exec($ch);
        curl_close($ch);
    } else {
        $opts = ['http' => ['timeout' => 6, 'ignore_errors' => true]];
        if ($post !== null) {
            $opts['http']['method'] = 'POST';
            $opts['http']['header'] = 'Content-Type: application/x-www-form-urlencoded';
            $opts['http']['content'] = http_build_query($post);
        }
        $body = @file_get_contents($url, false, stream_context_create($opts));
    }
    if (!is_string($body) || $body === '') {
        return null;
    }
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

function mp_tg_ready(): bool
{
    $t = mp_cfg()['telegram'];
    return $t['token'] !== '' && $t['chat_id'] !== '';
}

function mp_tg_send(string $text): bool
{
    $t = mp_cfg()['telegram'];
    if ($t['token'] === '' || $t['chat_id'] === '') {
        return false;
    }
    $res = mp_http('https://api.telegram.org/bot' . $t['token'] . '/sendMessage', [
        'chat_id' => $t['chat_id'],
        'text' => $text,
        'disable_web_page_preview' => 'true',
    ]);
    return is_array($res) && !empty($res['ok']);
}

/* Список чатов, которые писали боту, — чтобы узнать chat_id */
function mp_tg_chats(): ?array
{
    $t = mp_cfg()['telegram'];
    if ($t['token'] === '') {
        return null;
    }
    $res = mp_http('https://api.telegram.org/bot' . $t['token'] . '/getUpdates');
    if (!is_array($res) || empty($res['ok'])) {
        return null;
    }
    $chats = [];
    foreach ($res['result'] as $u) {
        $chat = $u['message']['chat'] ?? null;
        if ($chat) {
            $chats[(string)$chat['id']] = trim(($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? '') . ' ' . (isset($chat['username']) ? '@' . $chat['username'] : ''));
        }
    }
    return $chats;
}


/* ---------- Ограничение частоты ---------- */

/* Записывает обращение и говорит, укладывается ли адрес в лимит.
   Если база недоступна — не мешаем работе. */
function mp_rate_hit(string $endpoint, int $max, int $seconds, string $method = '', string $name = ''): bool
{
    if (!mp_db_ready()) {
        return true;
    }
    try {
        $db = mp_db();
        $ip = mp_ip_hash();
        $st = $db->prepare('SELECT COUNT(*) FROM req_log WHERE endpoint = ? AND ip_hash = ? AND created_at > ?');
        $st->execute([$endpoint, $ip, date('Y-m-d H:i:s', time() - $seconds)]);
        if ((int)$st->fetchColumn() >= $max) {
            return false;
        }
        $db->prepare('INSERT INTO req_log (created_at, ip_hash, endpoint, method, name) VALUES (?, ?, ?, ?, ?)')
            ->execute([mp_now(), $ip, $endpoint, mb_substr($method, 0, 40), mb_substr($name, 0, 64)]);
        if (random_int(1, 200) === 1) {
            $old = date('Y-m-d H:i:s', time() - 30 * 86400);
            $db->prepare('DELETE FROM req_log WHERE created_at < ?')->execute([$old]);
            $db->prepare('DELETE FROM site_errors WHERE last_at < ?')->execute([$old]);
        }
        return true;
    } catch (Throwable $e) {
        error_log('[mehaplane] лимит: ' . $e->getMessage());
        return true;
    }
}

/* ---------- Заявки: общая проверка и сохранение для формы и MCP ---------- */

/* Возвращает [поля, ошибки] */
function mp_lead_validate(array $in): array
{
    $f = [
        'name'    => mp_clean((string)($in['name'] ?? '')),
        'contact' => mp_clean((string)($in['contact'] ?? '')),
        'tier'    => (string)($in['tier'] ?? ''),
        'task'    => mp_clean((string)($in['task'] ?? ''), true),
        'calc'    => mp_clean((string)($in['calc'] ?? ''), true),
        'page'    => mp_clean((string)($in['page'] ?? '')),
    ];
    $consent = ($in['consent'] ?? false) === true || ($in['consent'] ?? '') === 'on';

    $errors = [];
    if (mp_len($f['name']) < 2 || mp_len($f['name']) > 80) {
        $errors['name'] = 'Укажите имя';
    }
    $digits = preg_replace('/\D/', '', $f['contact']) ?? '';
    $isPhone = (bool)preg_match('/^[+\d\s()\-]+$/', $f['contact']) && strlen($digits) >= 10 && strlen($digits) <= 15;
    $isTg = (bool)preg_match('/^@?[a-zA-Z][a-zA-Z0-9_]{4,31}$/', $f['contact']);
    $isMail = (bool)filter_var($f['contact'], FILTER_VALIDATE_EMAIL);
    if (mp_len($f['contact']) > 80 || !($isPhone || $isTg || $isMail)) {
        $errors['contact'] = 'Проверьте номер (+7…) или ник в Telegram (@username)';
    }
    if (!array_key_exists($f['tier'], MP_TIERS)) {
        $f['tier'] = '';
    }
    if (mp_len($f['task']) < 10 || mp_len($f['task']) > 2000) {
        $errors['task'] = 'Опишите задачу хотя бы парой предложений';
    }
    if (!$consent) {
        $errors['consent'] = 'Без согласия на обработку данных мы не можем принять заявку';
    }
    return [$f, $errors];
}

/* Сохраняет заявку. Ответ: ['ok' => true, 'id' => N] или ['ok' => false, 'why' => 'nodb'|'rate'|'db'] */
function mp_lead_save(array $f, string $source): array
{
    if (!mp_db_ready()) {
        error_log('[mehaplane] база не настроена в api/config.php');
        return ['ok' => false, 'why' => 'nodb'];
    }
    try {
        $db = mp_db();
        $ip = mp_ip_hash();
        $rl = mp_cfg()['rate_limit'];

        $since = date('Y-m-d H:i:s', time() - 60 * (int)$rl['minutes']);
        $st = $db->prepare('SELECT COUNT(*) FROM leads WHERE ip_hash = ? AND created_at > ?');
        $st->execute([$ip, $since]);
        if ((int)$st->fetchColumn() >= (int)$rl['max']) {
            return ['ok' => false, 'why' => 'rate'];
        }

        $now = mp_now();
        $st = $db->prepare('INSERT INTO leads
            (created_at, name, contact, tier, task, calc, consent_at, consent_version, page, ip_hash, status, source)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $st->execute([
            $now, $f['name'], $f['contact'], $f['tier'], $f['task'],
            $f['calc'] !== '' ? mb_substr($f['calc'], 0, 4000) : null,
            $now, (string)mp_cfg()['consent_version'],
            mb_substr($f['page'], 0, 255), $ip, 'new', $source === 'mcp' ? 'mcp' : 'site',
        ]);
        $id = (int)$db->lastInsertId();
    } catch (Throwable $e) {
        error_log('[mehaplane] ошибка базы: ' . $e->getMessage());
        mp_error_record('php', 'Заявка не сохранилась: ' . $e->getMessage(), $e->getFile(), $e->getLine());
        return ['ok' => false, 'why' => 'db'];
    }

    // Уведомление без личных данных: номер, тариф, время
    $tierName = $f['tier'] !== '' ? MP_TIERS[$f['tier']] : 'не выбран';
    $via = $source === 'mcp' ? ' (через нейросеть)' : '';
    mp_tg_send("Новая заявка №{$id}{$via}\nТариф: {$tierName}\n" . date('d.m.Y H:i') . "\n" . rtrim(mp_cfg()['site_url'], '/') . '/admin/');

    return ['ok' => true, 'id' => $id];
}

/* ---------- Сбор ошибок сайта ---------- */

const MP_ERR_STATUSES = [
    'new'     => 'Новая',
    'fixed'   => 'Исправлена',
    'ignored' => 'Не исправлять',
];

/* Убираем из текста ошибки то, что похоже на личные данные */
function mp_scrub(string $s, int $max = 500): string
{
    $s = mp_clean($s);
    $s = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/u', '[e-mail]', $s) ?? $s;
    $s = preg_replace('/(?<![\w.])\+?\d[\d\s()\-]{8,}\d(?![\w.])/u', '[телефон]', $s) ?? $s;
    return mb_substr($s, 0, $max);
}

function mp_error_record(string $type, string $message, string $file = '', int $line = 0, int $col = 0, string $page = '', string $version = ''): void
{
    static $busy = false;
    if ($busy) {
        return;
    }
    $busy = true;
    try {
        if (!is_file(__DIR__ . '/config.php') || !mp_db_ready()) {
            return;
        }
        $type = $type === 'js' ? 'js' : 'php';
        $message = mp_scrub($message);
        if ($message === '') {
            return;
        }
        if ($type === 'php') {
            $root = dirname(__DIR__) . DIRECTORY_SEPARATOR;
            if (strncmp($file, $root, strlen($root)) === 0) {
                $file = substr($file, strlen($root));
            }
            $message = str_replace($root, '', $message);
        } else {
            $file = (string)(parse_url($file, PHP_URL_PATH) ?? '');
        }
        $file = mb_substr(mp_clean($file), 0, 255);
        $page = mb_substr(mp_clean((string)(parse_url($page, PHP_URL_PATH) ?? '')), 0, 255);
        $version = mb_substr(preg_replace('/[^\w.\-]/', '', $version) ?? '', 0, 16);
        $line = max(0, $line);
        $col = max(0, $col);
        $fp = sha1($type . '|' . $message . '|' . $file . '|' . $line);
        $now = mp_now();
        mp_db()->prepare("INSERT INTO site_errors
            (fingerprint, type, message, file, line, col, page, version, hits, first_at, last_at, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, 'new')
            ON DUPLICATE KEY UPDATE hits = hits + 1, last_at = VALUES(last_at), page = VALUES(page),
                version = VALUES(version), status = IF(status = 'fixed', 'new', status)")
            ->execute([$fp, $type, $message, $file, $line, $col, $page, $version, $now, $now]);
    } catch (Throwable $e) {
        error_log('[mehaplane] не удалось записать ошибку: ' . $e->getMessage());
    } finally {
        $busy = false;
    }
}

function mp_error_handlers(): void
{
    set_error_handler(static function (int $no, string $msg, string $file = '', int $line = 0): bool {
        if ((error_reporting() & $no) && ($no & (E_WARNING | E_USER_WARNING | E_USER_ERROR | E_RECOVERABLE_ERROR))) {
            mp_error_record('php', $msg, $file, $line);
        }
        return false; // дальше — обычная обработка PHP
    });
    set_exception_handler(static function (Throwable $e): void {
        error_log('[mehaplane] ' . get_class($e) . ': ' . $e->getMessage() . ' в ' . $e->getFile() . ':' . $e->getLine());
        mp_error_record('php', get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine());
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => 'Внутренняя ошибка. Попробуйте ещё раз позже.'], JSON_UNESCAPED_UNICODE);
        }
    });
    register_shutdown_function(static function (): void {
        $e = error_get_last();
        if ($e && ($e['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
            mp_error_record('php', $e['message'], $e['file'], (int)$e['line']);
        }
    });
}
mp_error_handlers();
