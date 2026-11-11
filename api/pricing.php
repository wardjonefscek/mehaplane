<?php
/* Данные сайта и формула цены для MCP. Источник — тот же assets/js/config.js,
   что читает калькулятор, поэтому цены на сайте и в ответах нейросети совпадают.
   Формула повторяет assets/js/price.js: меняешь там — меняй и здесь. */
declare(strict_types=1);

class MpInputError extends RuntimeException
{
}

/* Настройки сайта из config.js: всё, что после «window.MEHAPLANE =», — строгий JSON */
function mp_site_data(): array
{
    static $data = null;
    if ($data === null) {
        $src = @file_get_contents(dirname(__DIR__) . '/assets/js/config.js');
        if (!is_string($src)) {
            throw new RuntimeException('Не найден assets/js/config.js');
        }
        $pos = strpos($src, 'window.MEHAPLANE');
        $from = $pos === false ? false : strpos($src, '{', $pos);
        $to = strrpos($src, '}');
        $data = ($from === false || $to === false) ? null : json_decode(substr($src, $from, $to - $from + 1), true);
        if (!is_array($data)) {
            $data = null;
            throw new RuntimeException('assets/js/config.js не читается как JSON: ' . json_last_error_msg());
        }
    }
    return $data;
}

function mp_find(array $list, string $key, $value): ?array
{
    foreach ($list as $x) {
        if (($x[$key] ?? null) === $value) {
            return $x;
        }
    }
    return null;
}

function mp_ids(array $list, string $key = 'id'): array
{
    return array_map(static fn($x) => $x[$key], $list);
}

function mp_money(float $v): string
{
    return number_format(round($v), 0, ',', "\u{00A0}") . ' ₽';
}

function mp_kfmt(float $k): string
{
    return '× ' . number_format($k, 2, ',', '');
}

function mp_plural(int $n, string $a, string $b, string $c): string
{
    $m10 = $n % 10;
    $m100 = $n % 100;
    if ($m10 === 1 && $m100 !== 11) {
        return $a;
    }
    if ($m10 >= 2 && $m10 <= 4 && ($m100 < 10 || $m100 >= 20)) {
        return $b;
    }
    return $c;
}

function mp_option_available(array $o, string $tier, string $scheme): bool
{
    return in_array($tier, $o['tiers'], true)
        && !($tier === 'special' && in_array($scheme, $o['notSchemes'] ?? [], true));
}

function mp_doc_included(array $d, string $tier, string $material): bool
{
    return !empty($d['included'])
        || in_array($tier, $d['includedIn'] ?? [], true)
        || (($d['includedWithMaterial'] ?? null) === $material);
}

/* Приводит параметры к полному набору, как defaults() в калькуляторе.
   Неверное значение → MpInputError со списком допустимых. */
function mp_price_input(array $in): array
{
    $C = mp_site_data();
    $pick = static function (string $field, $value, array $allowed) {
        if (!in_array($value, $allowed, true)) {
            throw new MpInputError("Параметр {$field}: недопустимое значение. Допустимые: " . implode(', ', $allowed) . '.');
        }
        return $value;
    };
    $tier = $pick('tier', $in['tier'] ?? null, array_keys($C['tiers']));
    $sp = $C['span'];
    $span = $in['span_mm'] ?? $C['tiers'][$tier]['span'];
    if (!is_int($span) && !is_float($span)) {
        throw new MpInputError('Параметр span_mm: нужно число в миллиметрах.');
    }
    if ($span < $sp['min'] || $span > $sp['max']) {
        throw new MpInputError("Параметр span_mm: размах от {$sp['min']} до {$sp['max']} мм.");
    }
    $set = static function (string $field, $value, array $allowed) use ($pick): array {
        if ($value === null) {
            return [];
        }
        if (!is_array($value)) {
            throw new MpInputError("Параметр {$field}: нужен список.");
        }
        foreach ($value as $v) {
            $pick($field, $v, $allowed);
        }
        return array_values(array_unique($value));
    };
    return [
        'tier' => $tier,
        'span' => $span,
        'scheme' => $pick('scheme', $in['scheme'] ?? $C['schemes'][0]['id'], mp_ids($C['schemes'])),
        'motors' => $pick('motors', $in['motors'] ?? 4, mp_ids($C['vtol']['motors'], 'n')),
        'tilt' => $pick('tilt', $in['tilt'] ?? $C['vtol']['tilt'][0]['id'], mp_ids($C['vtol']['tilt'])),
        'drive' => $pick('drive', $in['drive'] ?? $C['vtol']['drive'][0]['id'], mp_ids($C['vtol']['drive'])),
        'fc' => ($in['fc_setup'] ?? false) === true,
        'options' => $set('options', $in['options'] ?? null, mp_ids($C['options'])),
        'material' => $pick('material', $in['material'] ?? 'epp', mp_ids($C['materials'])),
        'electronics' => $pick('electronics', $in['electronics'] ?? $C['electronics'][0]['id'], mp_ids($C['electronics'])),
        'docs' => $set('docs', $in['docs'] ?? null, mp_ids($C['docs'])),
        'urgency' => $pick('urgency', $in['urgency'] ?? $C['urgency'][0]['id'], mp_ids($C['urgency'])),
    ];
}

/* Расчёт: ['total' => ₽, 'days' => рабочих дней, 'lines' => [[название, значение], …], 'notes' => […]] */
function mp_price(array $S): array
{
    $C = mp_site_data();
    $t = $C['tiers'][$S['tier']];
    $sp = $C['span'];
    $spanK = min($sp['maxK'], max($sp['minK'], 1 + $sp['weight'] * ($S['span'] - $t['span']) / $t['span']));
    $mat = mp_find($C['materials'], 'id', $S['material']);
    $urg = mp_find($C['urgency'], 'id', $S['urgency']);
    $lines = [];
    $notes = [];
    $add = 0;
    $days = 0;
    $push = static function (string $label, $value, $d = 0) use (&$lines, &$add, &$days): void {
        $lines[] = [$label, '+' . mp_money((float)$value)];
        $add += $value;
        $days += $d ?: 0;
    };

    $lines[] = ['Проект «' . $t['name'] . '»', mp_money((float)$t['base'])];
    $lines[] = ['Размах ' . number_format((float)$S['span'], 0, ',', "\u{00A0}") . ' мм', $spanK == 1 ? 'базовый' : mp_kfmt($spanK)];
    $lines[] = ['Материал: ' . $mat['name'], $mat['k'] == 1 ? 'базовый' : mp_kfmt((float)$mat['k'])];

    if ($S['tier'] === 'special') {
        $s = mp_find($C['schemes'], 'id', $S['scheme']);
        if ($s['add']) {
            $push('Схема: ' . mb_strtolower($s['name']), $s['add'], $s['days'] ?? 0);
        } else {
            $lines[] = ['Схема: ' . mb_strtolower($s['name']), 'входит'];
        }
    }
    if ($S['tier'] === 'vtol') {
        $m = mp_find($C['vtol']['motors'], 'n', $S['motors']);
        if ($m['add']) {
            $push('Поворотных моторов: ' . $m['n'], $m['add'], $m['days'] ?? 0);
        }
        $tl = mp_find($C['vtol']['tilt'], 'id', $S['tilt']);
        if ($tl['add']) {
            $push('Поворот: ' . mb_strtolower($tl['name']), $tl['add'], $tl['days'] ?? 0);
        }
        $dr = mp_find($C['vtol']['drive'], 'id', $S['drive']);
        if ($dr['add']) {
            $push('Привод: ' . mb_strtolower($dr['name']), $dr['add'], $dr['days'] ?? 0);
        }
        if ($S['fc']) {
            $push('Настройка полётного контроллера', $C['vtol']['fc']['add'], $C['vtol']['fc']['days'] ?? 0);
        }
    }
    foreach ($C['options'] as $o) {
        if (!in_array($o['id'], $S['options'], true)) {
            continue;
        }
        if (mp_option_available($o, $S['tier'], $S['scheme'])) {
            $push($o['name'], $o['add'], $o['days'] ?? 0);
        } else {
            $notes[] = 'Опция «' . $o['name'] . '» недоступна для этого тарифа или схемы и в расчёт не вошла.';
        }
    }
    $el = mp_find($C['electronics'], 'id', $S['electronics']);
    if ($el['add']) {
        $push('Подбор электроники', $el['add'], 1);
    }
    foreach ($C['docs'] as $d) {
        if (!mp_doc_included($d, $S['tier'], $S['material']) && in_array($d['id'], $S['docs'], true)) {
            $push($d['name'], $d['add'], $d['days'] ?? 0);
        }
    }
    if ($urg['k'] != 1) {
        $lines[] = ['Срок: ' . mb_strtolower($urg['name']), mp_kfmt((float)$urg['k'])];
    }

    $raw = ($t['base'] * $spanK * $mat['k'] + $add) * $urg['k'];
    $total = (int)(floor($raw / $C['rounding'] + 0.5) * $C['rounding']); // как Math.round в браузере
    $workDays = (int)max(3, ceil(($t['days'] * (1 + ($spanK - 1) * 0.5) + $days) * $urg['dk']));
    return ['total' => $total, 'days' => $workDays, 'lines' => $lines, 'notes' => $notes];
}
