<?php
/* Публичный MCP-сервер mehaplane: тарифы, расчёт цены, работы, вопросы и приём заявки.
   Подключение в клиенте: адрес https://wardenhmehaplane.ru/mcp/ (транспорт HTTP), вход не нужен. */
declare(strict_types=1);
require __DIR__ . '/../api/mcp-core.php';
require __DIR__ . '/../api/pricing.php';

$root = dirname(__DIR__);
$site = rtrim((string)mp_cfg()['site_url'], '/');
$C = mp_site_data();
$consentVersion = (string)mp_cfg()['consent_version'];
$readOnly = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
$tierIds = array_keys($C['tiers']);
$named = static fn(array $list, string $key = 'id'): string => implode('; ', array_map(static fn($x) => $x[$key] . ' — ' . ($x['name'] ?? $x[$key]), $list));

$tools = [];

/* ---------- get_tariffs ---------- */
$tools[] = [
    'def' => [
        'name' => 'get_tariffs',
        'title' => 'Тарифы mehaplane',
        'description' => 'Три тарифа студии mehaplane на проектирование радиоуправляемых самолётов: цена «от» в рублях, срок в рабочих днях, базовый размах крыла и что входит. Для точной цены под параметры вызовите calculate_price.',
        'inputSchema' => mp_mcp_no_args(),
        'annotations' => $readOnly,
    ],
    'call' => static function (array $a) use ($C, $site): array {
        mp_mcp_only($a, []);
        $rows = [];
        $text = [];
        foreach ($C['tiers'] as $id => $t) {
            $inc = [];
            foreach ($C['docs'] as $d) {
                if (!empty($d['included']) || in_array($id, $d['includedIn'] ?? [], true)) {
                    $inc[] = $d['name'];
                }
            }
            $rows[] = [
                'id' => $id, 'name' => $t['name'], 'description' => $t['lead'],
                'price_from_rub' => $t['base'], 'days_from' => $t['days'], 'base_span_mm' => $t['span'],
                'included' => $inc, 'calculator_url' => $site . '/calculator.html#' . $t['hash'],
            ];
            $text[] = '«' . $t['name'] . '» (' . $id . ') — от ' . mp_money((float)$t['base']) . ', от ' . $t['days'] . ' ' . mp_plural((int)$t['days'], 'рабочего дня', 'рабочих дней', 'рабочих дней')
                . '. ' . $t['lead'] . ' Входит: ' . implode('; ', $inc) . '.';
        }
        $text[] = 'Итоговая цена зависит от размаха, материала, состава документации и срочности; окончательная сумма фиксируется в техническом задании.';
        return ['text' => implode("\n", $text), 'data' => ['tariffs' => $rows, 'currency' => 'RUB']];
    },
];

/* ---------- calculate_price ---------- */
$tools[] = [
    'def' => [
        'name' => 'calculate_price',
        'title' => 'Расчёт цены проекта',
        'description' => 'Предварительная цена и срок проекта по той же формуле, что онлайн-калькулятор на сайте. Обязателен только tier; остальные параметры по умолчанию такие же, как в калькуляторе. Цена предварительная, окончательная фиксируется в техническом задании.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'tier' => ['type' => 'string', 'enum' => $tierIds, 'description' => 'Тариф: ' . implode('; ', array_map(static fn($k) => $k . ' — ' . $C['tiers'][$k]['name'], $tierIds))],
                'span_mm' => ['type' => 'integer', 'minimum' => $C['span']['min'], 'maximum' => $C['span']['max'], 'description' => 'Размах крыла в миллиметрах. По умолчанию базовый размах тарифа.'],
                'scheme' => ['type' => 'string', 'enum' => mp_ids($C['schemes']), 'description' => 'Схема, учитывается только для тарифа special: ' . $named($C['schemes'])],
                'motors' => ['type' => 'integer', 'enum' => mp_ids($C['vtol']['motors'], 'n'), 'description' => 'Число поворотных моторов, только для vtol. По умолчанию 4, как в калькуляторе.'],
                'tilt' => ['type' => 'string', 'enum' => mp_ids($C['vtol']['tilt']), 'description' => 'Что поворачивается, только для vtol: ' . $named($C['vtol']['tilt'])],
                'drive' => ['type' => 'string', 'enum' => mp_ids($C['vtol']['drive']), 'description' => 'Привод поворота, только для vtol: ' . $named($C['vtol']['drive'])],
                'fc_setup' => ['type' => 'boolean', 'description' => 'Помощь с настройкой полётного контроллера, только для vtol.'],
                'options' => ['type' => 'array', 'uniqueItems' => true, 'items' => ['type' => 'string', 'enum' => mp_ids($C['options'])], 'description' => 'Опции: ' . $named($C['options'])],
                'material' => ['type' => 'string', 'enum' => mp_ids($C['materials']), 'description' => 'Материал (по умолчанию epp): ' . $named($C['materials'])],
                'electronics' => ['type' => 'string', 'enum' => mp_ids($C['electronics']), 'description' => 'Электроника: ' . $named($C['electronics'])],
                'docs' => ['type' => 'array', 'uniqueItems' => true, 'items' => ['type' => 'string', 'enum' => mp_ids($C['docs'])], 'description' => 'Дополнительная документация: ' . $named($C['docs']) . '. Часть входит в тариф бесплатно.'],
                'urgency' => ['type' => 'string', 'enum' => mp_ids($C['urgency']), 'description' => 'Срочность: ' . $named($C['urgency'])],
            ],
            'required' => ['tier'],
            'additionalProperties' => false,
        ],
        'annotations' => $readOnly,
    ],
    'call' => static function (array $a) use ($C, $site): array {
        mp_mcp_only($a, ['tier', 'span_mm', 'scheme', 'motors', 'tilt', 'drive', 'fc_setup', 'options', 'material', 'electronics', 'docs', 'urgency']);
        $S = mp_price_input($a);
        $r = mp_price($S);
        $t = $C['tiers'][$S['tier']];
        $url = $site . '/calculator.html#' . $t['hash'];
        $text = ['Предварительно: ' . mp_money((float)$r['total']) . ', примерно ' . $r['days'] . ' ' . mp_plural($r['days'], 'рабочий день', 'рабочих дня', 'рабочих дней') . '.'];
        foreach ($r['lines'] as [$label, $value]) {
            $text[] = '— ' . $label . ': ' . $value;
        }
        foreach ($r['notes'] as $n) {
            $text[] = $n;
        }
        $text[] = 'Окончательная сумма фиксируется в техническом задании. Калькулятор: ' . $url;
        return [
            'text' => implode("\n", $text),
            'data' => [
                'total_rub' => $r['total'], 'days' => $r['days'], 'currency' => 'RUB',
                'lines' => array_map(static fn($l) => ['label' => $l[0], 'value' => $l[1]], $r['lines']),
                'notes' => $r['notes'], 'calculator_url' => $url,
            ],
        ];
    },
];

/* ---------- list_works ---------- */
$tools[] = [
    'def' => [
        'name' => 'list_works',
        'title' => 'Работы студии',
        'description' => 'Работы с витрины сайта: название, тариф, статус, описание и характеристики. Можно отобрать по тарифу.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => ['tier' => ['type' => 'string', 'enum' => $tierIds, 'description' => 'Показать работы только этого тарифа.']],
            'additionalProperties' => false,
        ],
        'annotations' => $readOnly,
    ],
    'call' => static function (array $a) use ($C, $site, $tierIds): array {
        mp_mcp_only($a, ['tier']);
        $tier = $a['tier'] ?? null;
        if ($tier !== null && !in_array($tier, $tierIds, true)) {
            throw new MpToolError('Параметр tier: недопустимое значение. Допустимые: ' . implode(', ', $tierIds) . '.');
        }
        $rows = [];
        $text = [];
        foreach ($C['works'] as $w) {
            if ($tier !== null && $w['tier'] !== $tier) {
                continue;
            }
            $specs = [];
            foreach ($w['specs'] as [$k, $v]) {
                $specs[] = ['name' => $k, 'value' => $v];
            }
            $rows[] = [
                'id' => $w['id'], 'title' => $w['title'], 'tier' => $w['tier'], 'tier_name' => $C['tiers'][$w['tier']]['name'],
                'status' => $w['status'], 'span_mm' => $w['drawing']['span'] ?? null, 'description' => $w['text'],
                'specs' => $specs, 'url' => $site . '/#raboty',
            ];
            $text[] = $w['title'] . ' — тариф «' . $C['tiers'][$w['tier']]['name'] . '», ' . mb_strtolower($w['status']) . '. ' . $w['text'];
        }
        return ['text' => $rows ? implode("\n", $text) : 'По этому тарифу работ на витрине пока нет.', 'data' => ['works' => $rows]];
    },
];

/* ---------- get_faq ---------- */
$tools[] = [
    'def' => [
        'name' => 'get_faq',
        'title' => 'Частые вопросы',
        'description' => 'Вопросы и ответы с сайта mehaplane: как проходит работа, оплата, сроки, что нужно от заказчика.',
        'inputSchema' => mp_mcp_no_args(),
        'annotations' => $readOnly,
    ],
    'call' => static function (array $a) use ($root): array {
        mp_mcp_only($a, []);
        $faq = [];
        foreach (mp_jsonld((string)file_get_contents($root . '/index.html')) as $node) {
            if (($node['@type'] ?? '') !== 'FAQPage') {
                continue;
            }
            foreach ($node['mainEntity'] ?? [] as $q) {
                $faq[] = ['question' => (string)($q['name'] ?? ''), 'answer' => (string)($q['acceptedAnswer']['text'] ?? '')];
            }
        }
        if (!$faq) {
            throw new MpToolError('Раздел вопросов на сайте сейчас не читается.');
        }
        return [
            'text' => implode("\n\n", array_map(static fn($q) => $q['question'] . "\n" . $q['answer'], $faq)),
            'data' => ['faq' => $faq],
        ];
    },
];

/* ---------- submit_request ---------- */
$tools[] = [
    'def' => [
        'name' => 'submit_request',
        'title' => 'Оставить заявку',
        'description' => 'Отправляет заявку на проект в студию mehaplane от имени человека. ВАЖНО: это передача персональных данных. Вызывайте только после того, как человек сам назвал имя и контакт, прочитал согласие ' . $site . '/consent.html и прямо подтвердил, что согласен на обработку данных и хочет отправить заявку. Не подставляйте данные сами и не отправляйте заявку без явной просьбы человека.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 80, 'description' => 'Как обращаться к человеку.'],
                'contact' => ['type' => 'string', 'maxLength' => 80, 'description' => 'Телефон (+7…), ник в Telegram (@username) или e-mail.'],
                'tier' => ['type' => 'string', 'enum' => $tierIds, 'description' => 'Тариф, если человек уже выбрал.'],
                'task' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 2000, 'description' => 'Задача своими словами: для чего самолёт, какая электроника есть, пожелания.'],
                'calc_summary' => ['type' => 'string', 'maxLength' => 4000, 'description' => 'Текст расчёта из calculate_price, если он был.'],
                'consent' => ['type' => 'boolean', 'const' => true, 'description' => 'true только если человек прямо согласился на обработку персональных данных по тексту ' . $site . '/consent.html'],
                'consent_version' => ['type' => 'string', 'const' => $consentVersion, 'description' => 'Версия текста согласия, с которой согласился человек. Сейчас действует ' . $consentVersion . '.'],
            ],
            'required' => ['name', 'contact', 'task', 'consent', 'consent_version'],
            'additionalProperties' => false,
        ],
        'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false],
    ],
    'call' => static function (array $a) use ($site, $consentVersion): array {
        mp_mcp_only($a, ['name', 'contact', 'tier', 'task', 'calc_summary', 'consent', 'consent_version']);
        if (($a['consent'] ?? null) !== true) {
            throw new MpToolError('Заявка не принята: нужно явное согласие человека на обработку персональных данных (consent: true). Покажите ему текст согласия: ' . $site . '/consent.html');
        }
        if (($a['consent_version'] ?? null) !== $consentVersion) {
            throw new MpToolError('Заявка не принята: действует версия согласия ' . $consentVersion . '. Покажите человеку текст ' . $site . '/consent.html и передайте consent_version: "' . $consentVersion . '".');
        }
        foreach (['name', 'contact', 'task', 'tier', 'calc_summary'] as $k) {
            if (isset($a[$k]) && !is_string($a[$k])) {
                throw new MpToolError('Параметр ' . $k . ' должен быть строкой.');
            }
        }
        [$f, $errors] = mp_lead_validate([
            'name' => $a['name'] ?? '', 'contact' => $a['contact'] ?? '', 'tier' => $a['tier'] ?? '',
            'task' => $a['task'] ?? '', 'calc' => $a['calc_summary'] ?? '', 'page' => '/mcp/', 'consent' => true,
        ]);
        if ($errors) {
            throw new MpToolError('Заявка не принята. ' . implode('. ', array_map(static fn($k, $v) => $k . ': ' . $v, array_keys($errors), $errors)) . '.');
        }
        $res = mp_lead_save($f, 'mcp');
        if (!$res['ok']) {
            throw new MpToolError($res['why'] === 'rate'
                ? 'Слишком много заявок подряд. Попробуйте через несколько минут или оставьте заявку на сайте: ' . $site . '/#zayavka'
                : 'Приём заявок временно не работает. Оставьте заявку на сайте: ' . $site . '/#zayavka');
        }
        return [
            'text' => 'Заявка №' . $res['id'] . ' принята. Студия ответит в течение рабочего дня по указанному контакту.',
            'data' => ['id' => $res['id'], 'reply' => 'в течение рабочего дня'],
        ];
    },
];

foreach ($tools as &$t) {
    if (!isset($t['def']['outputSchema'])) {
        $t['def']['outputSchema'] = ['type' => 'object'];
    }
}
unset($t);

/* ---------- ресурсы ---------- */
$doc = static fn(string $file): Closure => static fn(): string => mp_html_text((string)file_get_contents($root . '/' . $file));
$resources = [
    [
        'def' => ['uri' => $site . '/llms.txt', 'name' => 'llms.txt', 'title' => 'О студии mehaplane', 'description' => 'Описание студии, услуг, цен и отличий — для нейросетей.', 'mimeType' => 'text/plain'],
        'read' => static fn(): string => (string)file_get_contents($root . '/llms.txt'),
    ],
    [
        'def' => ['uri' => $site . '/offer.html', 'name' => 'offer', 'title' => 'Договор-оферта', 'description' => 'Условия оказания услуг.', 'mimeType' => 'text/plain'],
        'read' => $doc('offer.html'),
    ],
    [
        'def' => ['uri' => $site . '/privacy.html', 'name' => 'privacy', 'title' => 'Политика обработки персональных данных', 'description' => 'Какие данные собираются, зачем и где хранятся.', 'mimeType' => 'text/plain'],
        'read' => $doc('privacy.html'),
    ],
    [
        'def' => ['uri' => $site . '/consent.html', 'name' => 'consent', 'title' => 'Согласие на обработку персональных данных', 'description' => 'Текст согласия, версия ' . $consentVersion . '. Покажите человеку перед submit_request.', 'mimeType' => 'text/plain'],
        'read' => $doc('consent.html'),
    ],
];

mp_mcp_serve([
    'endpoint' => 'mcp',
    'name' => 'mehaplane',
    'title' => 'mehaplane — проектирование RC-самолётов',
    'version' => '1.0.0',
    'instructions' => 'Сервер студии mehaplane: проектирование радиоуправляемых самолётов на заказ. get_tariffs — тарифы, calculate_price — предварительная цена и срок, list_works — работы, get_faq — частые вопросы. submit_request отправляет заявку и передаёт персональные данные: вызывайте его только по прямой просьбе человека и после его согласия с текстом ' . $site . '/consent.html. Цены в рублях, предварительные.',
    'auth' => false,
    'rate' => [60, 60],
    'tools' => $tools,
    'resources' => $resources,
]);
