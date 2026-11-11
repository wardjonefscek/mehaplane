<?php
/* Ядро MCP-сервера: транспорт Streamable HTTP, сообщения JSON-RPC 2.0.
   Основная версия протокола — 2026-07-28 (без сессий: каждый запрос сам несёт
   версию в _meta и заголовках). Для клиентов постарше работает и прежний
   порядок с initialize (2025-03-26 … 2025-11-25).
   Сам файл ничего не выводит; его подключают mcp/index.php и mcp/service/index.php. */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

const MP_MCP_MODERN = '2026-07-28';
const MP_MCP_LEGACY = ['2025-11-25', '2025-06-18', '2025-03-26'];
const MP_MCP_META_VERSION = 'io.modelcontextprotocol/protocolVersion';

/* Ошибка, которую инструмент показывает нейросети обычным текстом */
class MpToolError extends RuntimeException
{
}

function mp_mcp_send(int $http, array $body): void
{
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mp_mcp_error(int $http, $id, int $code, string $message, ?array $data = null): void
{
    $err = ['code' => $code, 'message' => $message];
    if ($data !== null) {
        $err['data'] = $data;
    }
    mp_mcp_send($http, ['jsonrpc' => '2.0', 'id' => $id, 'error' => $err]);
}

/* Значение заголовка Mcp-Name может прийти в виде =?base64?…?= */
function mp_mcp_header_value(string $v): string
{
    if (preg_match('/^=\?base64\?(.*)\?=$/s', $v, $m)) {
        $dec = base64_decode($m[1], true);
        return $dec === false ? $v : $dec;
    }
    return $v;
}

/* Заголовок Authorization: на общем хостинге Apache иногда прячет его от PHP */
function mp_mcp_bearer(): string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) {
                $h = $v;
            }
        }
    }
    return preg_match('/^Bearer\s+(\S+)$/i', trim((string)$h), $m) ? $m[1] : '';
}

/* Запрос из браузера с чужого сайта отклоняем (требование спецификации) */
function mp_mcp_origin_ok(): bool
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') {
        return true; // программы-клиенты заголовок Origin не присылают
    }
    $allowed = array_map('strtolower', (array)mp_opt('mcp_origins', []));
    if (in_array(strtolower(rtrim($origin, '/')), $allowed, true)) {
        return true;
    }
    $originHost = strtolower((string)parse_url($origin, PHP_URL_HOST));
    $host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '') ?? '');
    return $originHost !== '' && $originHost === $host;
}

/*
 * $srv = [
 *   'endpoint' => 'mcp' | 'mcp-svc',        метка для журнала и лимита
 *   'name', 'title', 'version', 'instructions',
 *   'auth' => bool,                          нужен ли токен
 *   'rate' => [запросов, секунд],
 *   'tools' => [ ['def' => [...], 'call' => fn(array $args): array{text, data} ], … ],
 *   'resources' => [ ['def' => [...], 'read' => fn(): string ], … ],
 * ]
 */
function mp_mcp_serve(array $srv): void
{
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        mp_mcp_error(405, null, -32600, 'Это адрес MCP-сервера mehaplane. Он принимает только POST-запросы JSON-RPC от программ-клиентов (Claude, ChatGPT, Cursor и других).');
    }
    if (!mp_mcp_origin_ok()) {
        mp_mcp_error(403, null, -32600, 'Запрос с этого сайта не разрешён.');
    }

    if (!empty($srv['auth'])) {
        $token = (string)mp_opt('mcp_token', '');
        if (strlen($token) < 32) {
            mp_mcp_error(503, null, -32000, 'Служебный адрес выключен: в api/config.php не задан mcp_token (от 32 символов).');
        }
        if (!hash_equals($token, mp_mcp_bearer())) {
            header('WWW-Authenticate: Bearer realm="mehaplane"');
            mp_mcp_error(401, null, -32000, 'Нужен токен: заголовок Authorization: Bearer <mcp_token>.');
        }
    }

    $raw = file_get_contents('php://input') ?: '';
    if (strlen($raw) > 20000) {
        mp_mcp_error(413, null, -32600, 'Слишком большой запрос: не больше 20 000 байт.');
    }
    $msg = json_decode($raw, true);
    if (!is_array($msg)) {
        mp_mcp_error(400, null, -32700, 'Тело запроса не читается как JSON.');
    }
    if (array_is_list($msg) || ($msg['jsonrpc'] ?? '') !== '2.0' || !is_string($msg['method'] ?? null)) {
        mp_mcp_error(400, null, -32600, 'Нужен один запрос JSON-RPC 2.0 с полем method.');
    }
    $method = $msg['method'];
    $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];

    // Уведомление (без id): принять и ничего не отвечать
    if (!array_key_exists('id', $msg)) {
        http_response_code(202);
        exit;
    }
    $id = $msg['id'];
    if (!is_string($id) && !is_int($id)) {
        mp_mcp_error(400, null, -32600, 'Поле id должно быть строкой или целым числом.');
    }

    $target = (string)($params['name'] ?? $params['uri'] ?? '');
    [$max, $sec] = $srv['rate'];
    if (!mp_rate_hit($srv['endpoint'], $max, $sec, $method, in_array($method, ['tools/call', 'resources/read'], true) ? $target : '')) {
        header('Retry-After: ' . $sec);
        mp_mcp_error(429, $id, -32000, 'Слишком много запросов. Повторите через минуту.');
    }

    /* ---------- версия протокола ---------- */
    $hdrVer = $_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? null;
    $metaVer = is_array($params['_meta'] ?? null) ? ($params['_meta'][MP_MCP_META_VERSION] ?? null) : null;
    $supported = array_merge([MP_MCP_MODERN], MP_MCP_LEGACY);

    if ($method === 'initialize') {
        $modern = false;
    } elseif ($metaVer !== null || $method === 'server/discover') {
        $modern = true;
    } elseif ($hdrVer !== null && !in_array($hdrVer, MP_MCP_LEGACY, true)) {
        $modern = true;
    } else {
        $modern = false;
    }

    if ($modern) {
        if ($hdrVer === null || $metaVer === null || $hdrVer !== $metaVer) {
            mp_mcp_error(400, $id, -32020, 'Header mismatch: заголовок MCP-Protocol-Version и поле _meta["' . MP_MCP_META_VERSION . '"] обязательны и должны совпадать.');
        }
        if ($metaVer !== MP_MCP_MODERN) {
            mp_mcp_error(400, $id, -32022, 'Unsupported protocol version', ['supported' => $supported, 'requested' => (string)$metaVer]);
        }
        if (($_SERVER['HTTP_MCP_METHOD'] ?? null) !== $method) {
            mp_mcp_error(400, $id, -32020, 'Header mismatch: заголовок Mcp-Method обязателен и должен совпадать с полем method.');
        }
        if (in_array($method, ['tools/call', 'resources/read', 'prompts/get'], true)
            && mp_mcp_header_value((string)($_SERVER['HTTP_MCP_NAME'] ?? '')) !== $target) {
            mp_mcp_error(400, $id, -32020, 'Header mismatch: заголовок Mcp-Name обязателен и должен совпадать с params.name или params.uri.');
        }
    }

    $info = ['name' => $srv['name'], 'title' => $srv['title'], 'version' => $srv['version']];
    $ok = static function (array $result) use ($id, $modern, $info): void {
        if ($modern) {
            $result = ['resultType' => 'complete'] + $result;
            $result['_meta'] = ['io.modelcontextprotocol/serverInfo' => $info];
        }
        mp_mcp_send(200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result ?: new stdClass()]);
    };
    $cache = $modern ? ['ttlMs' => 3600000, 'cacheScope' => empty($srv['auth']) ? 'public' : 'private'] : [];

    switch ($method) {
        case 'initialize':
            $want = (string)($params['protocolVersion'] ?? '');
            $ok([
                'protocolVersion' => in_array($want, MP_MCP_LEGACY, true) ? $want : MP_MCP_LEGACY[0],
                'capabilities' => [
                    'tools' => ['listChanged' => false],
                    'resources' => ['subscribe' => false, 'listChanged' => false],
                ],
                'serverInfo' => $info,
                'instructions' => $srv['instructions'],
            ]);
            // no break — $ok завершает работу

        case 'server/discover':
            $ok([
                'supportedVersions' => $supported,
                'capabilities' => ['tools' => new stdClass(), 'resources' => new stdClass()],
                'instructions' => $srv['instructions'],
            ] + $cache);

        case 'ping':
            if (!$modern) {
                $ok([]);
            }
            break;

        case 'tools/list':
            $ok(['tools' => array_map(static fn($t) => $t['def'], $srv['tools'])] + $cache);

        case 'tools/call':
            foreach ($srv['tools'] as $t) {
                if ($t['def']['name'] !== $target) {
                    continue;
                }
                $args = $params['arguments'] ?? [];
                if (!is_array($args)) {
                    mp_mcp_error(400, $id, -32602, 'Поле arguments должно быть объектом.');
                }
                try {
                    $res = ($t['call'])($args);
                    $out = ['content' => [['type' => 'text', 'text' => $res['text']]]];
                    if (isset($res['data'])) {
                        $out['structuredContent'] = $res['data'];
                    }
                    $out['isError'] = false;
                } catch (MpToolError | MpInputError $e) {
                    $out = ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true];
                } catch (Throwable $e) {
                    error_log('[mehaplane] MCP ' . $target . ': ' . $e->getMessage());
                    mp_error_record('php', 'MCP ' . $target . ': ' . $e->getMessage(), $e->getFile(), $e->getLine());
                    $out = ['content' => [['type' => 'text', 'text' => 'Инструмент временно не работает. Попробуйте позже.']], 'isError' => true];
                }
                $ok($out);
            }
            mp_mcp_error($modern ? 400 : 200, $id, -32602, 'Нет такого инструмента: ' . mb_substr($target, 0, 64));

        case 'resources/list':
            $ok(['resources' => array_map(static fn($r) => $r['def'], $srv['resources'])] + $cache);

        case 'resources/templates/list':
            $ok(['resourceTemplates' => []] + $cache);

        case 'resources/read':
            foreach ($srv['resources'] as $r) {
                if ($r['def']['uri'] !== $target) {
                    continue;
                }
                try {
                    $text = ($r['read'])();
                } catch (Throwable $e) {
                    error_log('[mehaplane] MCP ресурс ' . $target . ': ' . $e->getMessage());
                    mp_mcp_error($modern ? 500 : 200, $id, -32603, 'Не удалось прочитать ресурс.');
                }
                $ok(['contents' => [['uri' => $target, 'mimeType' => $r['def']['mimeType'], 'text' => $text]]] + $cache);
            }
            mp_mcp_error($modern ? 400 : 200, $id, -32602, 'Нет такого ресурса: ' . mb_substr($target, 0, 200));
    }

    // Неизвестный метод: в новой версии протокола — HTTP 404, в прежних — обычная ошибка JSON-RPC
    mp_mcp_error($modern ? 404 : 200, $id, -32601, 'Method not found: ' . mb_substr($method, 0, 64));
}

/* ---------- помощники для инструментов ---------- */

/* Схема «без параметров» */
function mp_mcp_no_args(): array
{
    return ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false];
}

/* Лишние и неизвестные параметры — понятная ошибка вместо молчания */
function mp_mcp_only(array $args, array $allowed): void
{
    $extra = array_diff(array_keys($args), $allowed);
    if ($extra) {
        throw new MpToolError('Неизвестные параметры: ' . implode(', ', $extra) . '. Допустимые: ' . ($allowed ? implode(', ', $allowed) : 'нет') . '.');
    }
}

/* Страница сайта простым текстом, без HTML */
function mp_html_text(string $html): string
{
    if (preg_match('/<main\b[^>]*>(.*)<\/main>/is', $html, $m)) {
        $html = $m[1];
    }
    $html = preg_replace('/<(script|style|nav|svg)\b[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
    $html = preg_replace('/<li\b[^>]*>/i', "\n— ", $html) ?? $html;
    $html = preg_replace('/<\/(p|div|section|article|h[1-6]|li|ul|ol|tr|table|header|footer)>|<br\s*\/?>/i', "\n", $html) ?? $html;
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/ *\n */', "\n", $text) ?? $text;
    $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
    return trim($text);
}

/* Все блоки JSON-LD страницы одним плоским списком объектов */
function mp_jsonld(string $html, ?array &$broken = null): array
{
    $out = [];
    $broken = [];
    preg_match_all('/<script\b[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $m);
    foreach ($m[1] as $i => $json) {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            $broken[] = $i + 1;
            continue;
        }
        $items = isset($data['@graph']) && is_array($data['@graph']) ? $data['@graph'] : (array_is_list($data) ? $data : [$data]);
        foreach ($items as $it) {
            if (is_array($it)) {
                $out[] = $it;
            }
        }
    }
    return $out;
}
