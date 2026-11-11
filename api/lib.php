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
