/* Сверяет формулу цены в браузере (assets/js/price.js) и на сервере (api/pricing.php).
   Запуск из корня проекта: node tools/test-pricing.js */
const { execFileSync } = require('child_process');
const path = require('path');
const root = path.join(__dirname, '..');
global.window = {};
require(path.join(root, 'assets/js/config.js'));
const C = window.MEHAPLANE;
const P = require(path.join(root, 'assets/js/price.js'));

// Детерминированный перебор: все тарифы × разные размахи и наборы опций
let seed = 20261111;
const rnd = () => (seed = (seed * 1103515245 + 12345) % 2147483648) / 2147483648;
const pick = (a) => a[Math.floor(rnd() * a.length)];
const some = (a) => a.filter(() => rnd() < 0.4);
const cases = [];
for (const tier of Object.keys(C.tiers)) {
  cases.push({ tier });
  for (let i = 0; i < 80; i++) {
    cases.push({
      tier,
      span_mm: C.span.min + C.span.step * Math.floor(rnd() * ((C.span.max - C.span.min) / C.span.step + 1)),
      scheme: pick(C.schemes).id,
      motors: pick(C.vtol.motors).n,
      tilt: pick(C.vtol.tilt).id,
      drive: pick(C.vtol.drive).id,
      fc_setup: rnd() < 0.5,
      options: some(C.options).map((x) => x.id),
      material: pick(C.materials).id,
      electronics: pick(C.electronics).id,
      docs: some(C.docs).map((x) => x.id),
      urgency: pick(C.urgency).id,
    });
  }
}

const h = { money: String, fmt: String, kfmt: String };
const js = cases.map((c) => {
  const r = P.calc(C, {
    tier: c.tier, span: c.span_mm ?? C.tiers[c.tier].span, scheme: c.scheme ?? C.schemes[0].id,
    motors: c.motors ?? 4, tilt: c.tilt ?? C.vtol.tilt[0].id, drive: c.drive ?? C.vtol.drive[0].id,
    fc: !!c.fc_setup, options: new Set(c.options || []), material: c.material ?? 'epp',
    electronics: c.electronics ?? C.electronics[0].id, docs: new Set(c.docs || []), urgency: c.urgency ?? C.urgency[0].id,
  }, h);
  return [r.total, r.days];
});

const php = JSON.parse(execFileSync('php', ['-r', `
  require '${path.join(root, 'api/pricing.php')}';
  $out = [];
  foreach (json_decode(stream_get_contents(STDIN), true) as $c) {
      $r = mp_price(mp_price_input($c));
      $out[] = [$r['total'], $r['days']];
  }
  echo json_encode($out);
`], { input: JSON.stringify(cases) }).toString());

let bad = 0;
cases.forEach((c, i) => {
  if (js[i][0] !== php[i][0] || js[i][1] !== php[i][1]) {
    bad++;
    console.error('Расхождение:', JSON.stringify(c), 'браузер', js[i], 'сервер', php[i]);
  }
});
console.log(`Проверено наборов: ${cases.length}, расхождений: ${bad}`);
process.exit(bad ? 1 : 0);
