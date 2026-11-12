<?php
// Safety Care Home API (single file). Data lives in SQLite OUTSIDE public_html,
// because the Hostinger Git deploy wipes public_html on every push.
declare(strict_types=1);

const PLANS = [
  'bronze' => ['name' => 'Bronze', 'price' => 49, 'hours' => 4,  'priority' => 'Standard scheduling'],
  'silver' => ['name' => 'Silver', 'price' => 59, 'hours' => 8,  'priority' => 'Intermediate priority'],
  'gold'   => ['name' => 'Gold',   'price' => 69, 'hours' => 12, 'priority' => 'Maximum priority, urgent & emergency service'],
];
const CALL_FEE = 70;
const EXTRA_HOUR = 100;
// bcrypt of the agency default (clovis@cmdigital1). Changing it in Settings stores a new hash in the DB.
const ADMIN_HASH = '$2y$10$WCtug84srhnUaAD2WZ3OP.wIEv.2JA9hgSjhTYx3rducxTyL1xhKi';
const COMPANY = 'Safety Care Home';
const LEGAL = 'C2EK Construction LLC';

// ---------- bootstrap ----------
function data_dir(): string {
  if ($d = getenv('C2EK_DATA')) return $d;
  $p = __DIR__;
  while (($up = dirname($p)) !== $p) { // .../domains/<site>/public_html/... -> .../domains/<site>/dados-sites/<folder>
    if (basename($p) === 'public_html') return $up . '/dados-sites/' . basename(dirname(__DIR__));
    $p = $up;
  }
  return __DIR__ . '/data'; // ponytail: dev fallback, protected by api/.htaccess
}
$DATA = data_dir();
if (!is_dir($DATA)) mkdir($DATA . '/sessions', 0700, true);
if (!is_dir($DATA . '/sessions')) mkdir($DATA . '/sessions', 0700, true);

function db(): PDO {
  static $pdo;
  if ($pdo) return $pdo;
  global $DATA;
  $pdo = new PDO('sqlite:' . $DATA . '/app.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
  $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;');
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS clients (
      id INTEGER PRIMARY KEY, created_at TEXT NOT NULL, name TEXT NOT NULL, email TEXT NOT NULL UNIQUE COLLATE NOCASE,
      phone TEXT, pass_hash TEXT NOT NULL, street TEXT, city TEXT, state TEXT, zip TEXT, plan TEXT NOT NULL,
      status TEXT NOT NULL DEFAULT 'contract', signed_at TEXT, signed_name TEXT, signed_ip TEXT, signature TEXT, contract_html TEXT,
      start_date TEXT, stripe_customer TEXT, stripe_sub TEXT, stripe_status TEXT, next_billing TEXT, synced_at INTEGER,
      demo INTEGER NOT NULL DEFAULT 0, notes TEXT);
    CREATE TABLE IF NOT EXISTS visits (id INTEGER PRIMARY KEY, client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
      date TEXT NOT NULL, hours REAL NOT NULL, description TEXT, created_at TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT);
    CREATE TABLE IF NOT EXISTS attempts (ip TEXT NOT NULL, ts INTEGER NOT NULL);
  ");
  return $pdo;
}
function q(string $sql, array $a = []): PDOStatement { $s = db()->prepare($sql); $s->execute($a); return $s; }
function setting(string $k): ?string { $v = q('SELECT v FROM settings WHERE k=?', [$k])->fetchColumn(); return $v === false ? null : $v; }
function set_setting(string $k, ?string $v): void { q('INSERT INTO settings(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v', [$k, $v]); }

function out($data, int $code = 200): never {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_SLASHES);
  exit;
}
function fail(string $msg, int $code = 400): never { out(['error' => $msg], $code); }
function now(): string { return gmdate('Y-m-d H:i:s'); }
function today(): string { return date('Y-m-d'); }
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function is_https(): bool { return ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || ($_SERVER['SERVER_PORT'] ?? '') == 443; }
function base_url(): string { // site root, e.g. https://previewdo.site/423-clovis/
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $scheme = (is_https() || !preg_match('/^(localhost|127\.)/', $host)) ? 'https' : 'http';
  return $scheme . '://' . $host . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\') . '/';
}

date_default_timezone_set('America/New_York');
header('Cache-Control: no-store');
ini_set('session.gc_maxlifetime', '28800');
session_save_path($DATA . '/sessions');
session_name('SCHSESS');
session_set_cookie_params(['lifetime' => 0, 'path' => rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\') . '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => is_https()]);
session_start();

$r = $_GET['r'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$in = [];
if ($method === 'POST') {
  // CSRF: a custom header can't be sent cross-site without a CORS preflight, which we never allow.
  if (($_SERVER['HTTP_X_REQ'] ?? '') !== '1') fail('Bad request', 403);
  $in = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
}
function str_in(string $k, int $max = 300): string { global $in; return mb_substr(trim((string)($in[$k] ?? '')), 0, $max); }

function throttle(): void { // 8 failed logins / 15 min per IP
  $ip = $_SERVER['REMOTE_ADDR'] ?? '';
  q('DELETE FROM attempts WHERE ts < ?', [time() - 900]);
  if ((int)q('SELECT COUNT(*) FROM attempts WHERE ip=?', [$ip])->fetchColumn() >= 8) fail('Too many attempts. Please wait 15 minutes.', 429);
}
function failed_login(): never { q('INSERT INTO attempts VALUES(?,?)', [$_SERVER['REMOTE_ADDR'] ?? '', time()]); usleep(400000); fail('Wrong email or password.', 401); }

function me(): array {
  $id = $_SESSION['cid'] ?? 0;
  $c = $id ? q('SELECT * FROM clients WHERE id=?', [$id])->fetch() : false;
  if (!$c) fail('Please sign in.', 401);
  return $c;
}
function admin(): void { if (empty($_SESSION['admin'])) fail('Admin sign-in required.', 401); }

// ---------- business rules ----------
function term(?string $start): ?array { // contract year that contains today; renews every 12 months
  if (!$start) return null;
  $from = new DateTime($start); $t = new DateTime(today());
  while ((clone $from)->modify('+1 year') <= $t) $from->modify('+1 year');
  $to = (clone $from)->modify('+1 year -1 day');
  return [$from->format('Y-m-d'), $to->format('Y-m-d')];
}
function public_client(array $c, bool $full = false): array {
  $p = PLANS[$c['plan']] ?? PLANS['bronze'];
  $t = term($c['start_date']);
  $used = $t ? (float)q('SELECT COALESCE(SUM(hours),0) FROM visits WHERE client_id=? AND date BETWEEN ? AND ?', [$c['id'], $t[0], $t[1]])->fetchColumn() : 0.0;
  $o = [
    'id' => (int)$c['id'], 'name' => $c['name'], 'email' => $c['email'], 'phone' => $c['phone'],
    'street' => $c['street'], 'city' => $c['city'], 'state' => $c['state'], 'zip' => $c['zip'],
    'plan' => $c['plan'], 'planName' => $p['name'], 'price' => $p['price'], 'hoursIncluded' => $p['hours'],
    'hoursUsed' => $used, 'hoursLeft' => max(0, $p['hours'] - $used), 'extraHours' => max(0, $used - $p['hours']),
    'status' => $c['status'], 'signedAt' => $c['signed_at'], 'startDate' => $c['start_date'],
    'termStart' => $t[0] ?? null, 'termEnd' => $t[1] ?? null, 'nextBilling' => $c['next_billing'],
    'stripeStatus' => $c['stripe_status'], 'demo' => (bool)$c['demo'], 'createdAt' => $c['created_at'],
  ];
  if ($full) $o += ['notes' => $c['notes'], 'stripeCustomer' => $c['stripe_customer'], 'stripeSub' => $c['stripe_sub'], 'signedName' => $c['signed_name'], 'signedIp' => $c['signed_ip']];
  return $o;
}

// ---------- Stripe (plain HTTPS, no SDK) ----------
function stripe_key(): ?string { return setting('stripe_key') ?: null; }
function stripe(string $method, string $path, array $params = []): array {
  $key = stripe_key();
  if (!$key) throw new RuntimeException('Stripe is not connected yet.');
  $url = 'https://api.stripe.com/v1/' . $path;
  $body = http_build_query($params);
  if ($method === 'GET' && $body) { $url .= '?' . $body; $body = ''; }
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_USERPWD => $key . ':', CURLOPT_TIMEOUT => 25]);
  if ($body) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
  $res = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  if ($res === false) throw new RuntimeException('Could not reach Stripe: ' . curl_error($ch));
  $j = json_decode($res, true) ?: [];
  if ($code >= 400) throw new RuntimeException($j['error']['message'] ?? ('Stripe error ' . $code));
  return $j;
}
function apply_subscription(int $cid, array $sub): void {
  $end = $sub['current_period_end'] ?? ($sub['items']['data'][0]['current_period_end'] ?? null);
  $map = ['active' => 'active', 'trialing' => 'active', 'past_due' => 'past_due', 'unpaid' => 'past_due', 'canceled' => 'canceled', 'incomplete_expired' => 'canceled'];
  $st = $map[$sub['status']] ?? null;
  q('UPDATE clients SET stripe_sub=?, stripe_status=?, next_billing=?, synced_at=?' . ($st ? ', status=?' : '') . ' WHERE id=?',
    array_merge([$sub['id'], $sub['status'], $end ? date('Y-m-d', (int)$end) : null, time()], $st ? [$st] : [], [$cid]));
}
// ponytail: polls Stripe on page load (10 min cache) instead of webhooks; fine for a few hundred clients, add a webhook endpoint past that.
function sync_clients(array $rows): void {
  if (!stripe_key()) return;
  foreach ($rows as $c) {
    if (!$c['stripe_sub'] || ($c['synced_at'] && time() - (int)$c['synced_at'] < 600)) continue;
    try { apply_subscription((int)$c['id'], stripe('GET', 'subscriptions/' . $c['stripe_sub'])); } catch (Throwable $e) { /* keep last known state */ }
  }
}

// ---------- contract ----------
function contract_html(array $c, bool $withSignature): string {
  $p = PLANS[$c['plan']];
  $date = $c['signed_at'] ? date('F j, Y', strtotime($c['signed_at'] . ' UTC')) : date('F j, Y');
  $addr = trim("{$c['street']}, {$c['city']}, {$c['state']} {$c['zip']}", ', ');
  $box = fn($k) => $c['plan'] === $k ? '&#9746;' : '&#9744;';
  $html = '<article class="contract">
<h1>' . COMPANY . ' &middot; Service Agreement</h1>
<p class="tagline">&ldquo;Your home protected all year long, without the headache.&rdquo;</p>
<table class="parties">
<tr><th>Effective date</th><td>' . h($date) . '</td></tr>
<tr><th>Provider</th><td>' . COMPANY . ', operated by ' . LEGAL . '</td></tr>
<tr><th>Client</th><td>' . h($c['name']) . '</td></tr>
<tr><th>Covered property</th><td>' . h($addr) . '</td></tr>
</table>
<h2>1. Purpose</h2>
<p>This agreement covers preventive maintenance, a seasonal Winter Inspection and small residential repairs using the labor hours included in the subscription plan selected by the CLIENT, to keep the property protected and in full working condition throughout the year.</p>
<h2>2. Subscription plans and scope</h2>
<p>The CLIENT has selected the plan marked below. Its benefits and limits apply for the term of this agreement:</p>
<ul class="plans">
<li>' . $box('bronze') . ' <b>BRONZE PLAN</b> ($' . PLANS['bronze']['price'] . '/month): up to 4 (four) labor hours per year and the Winter Inspection.</li>
<li>' . $box('silver') . ' <b>SILVER PLAN</b> ($' . PLANS['silver']['price'] . '/month): up to 8 (eight) labor hours per year and the Winter Inspection, with intermediate priority.</li>
<li>' . $box('gold') . ' <b>GOLD PLAN</b> ($' . PLANS['gold']['price'] . '/month): up to 12 (twelve) labor hours per year and the Winter Inspection, with urgent service and maximum priority.</li>
</ul>
<h2>3. Winter Inspection (included in every plan)</h2>
<p>Regardless of the plan, the PROVIDER will perform once a year, before the harsh winter season, the Winter Inspection, which covers exclusively the following items:</p>
<ol>
<li>Thorough check of gaps and drafts around doors and windows.</li>
<li>Visual inspection of gutters.</li>
<li>Disconnection and proper storage of garden hoses.</li>
<li>Protection and insulation of exposed outdoor pipes.</li>
<li>Complete flush (draining and technical cleaning) of the water heater.</li>
</ol>
<h2>4. Use of hours, service call fee and response times</h2>
<p>To perform repairs using the plan hours, a fixed service call fee of <b>$' . CALL_FEE . '.00 (seventy dollars) per visit</b> applies.</p>
<p><b>Response times:</b> calls classified as urgent (such as a burst pipe, an active water leak or an equivalent critical situation) will be attended within 24 hours. Other services and routine repairs will be attended within 48 hours.</p>
<p>Each visit has a minimum charge/deduction of 1 (one) labor hour, even if the actual time spent is shorter.</p>
<p><b>When plan hours run out:</b> after the included labor hours are used, new repairs may be hired at <b>$' . EXTRA_HOUR . '.00 (one hundred dollars) per labor hour</b>, keeping the 1 hour minimum per call and the $' . CALL_FEE . '.00 service call fee.</p>
<p>Replacement materials (parts, paint, screws, piping, etc.) are not included and will be charged separately, with the CLIENT&rsquo;s prior approval.</p>
<h2>5. Exclusions</h2>
<p>The following are expressly excluded from this preventive maintenance agreement and the subscription plans:</p>
<ul>
<li><b>Major structural damage:</b> serious damage to the foundation, structural walls, columns and main beams of the home.</li>
<li><b>Major flooding and natural disasters:</b> large-scale flooding, severe storms, hurricanes or widespread water intrusion caused by major structural failures.</li>
</ul>
<p>Such events are the sole responsibility of the homeowner&rsquo;s insurance policy (Homeowner&rsquo;s Insurance) held by the owner and do not create any repair obligation for ' . COMPANY . '.</p>
<h2>6. Term and cancellation</h2>
<p>This agreement is valid for 12 (twelve) months and renews automatically unless either party gives notice to the contrary at least 30 days in advance.</p>
<p>Failure to comply with the payment clauses or misuse of the subscription terms may result in immediate termination of the agreement.</p>
<p>The monthly subscription is charged automatically to the payment method provided by the CLIENT at checkout.</p>
<p>In witness whereof, the parties sign this agreement.</p>
<div class="sigs">
<div><div class="sigline sigtext">' . COMPANY . '</div><small>Provider &middot; Authorized representative</small></div>';
  if ($withSignature && $c['signature']) {
    $html .= '<div><img class="sigimg" src="' . h($c['signature']) . '" alt="Client signature"><div class="sigline"></div><small>CLIENT: ' . h($c['signed_name']) . '<br>Signed electronically on ' . h(date('F j, Y \a\t g:i A T', strtotime($c['signed_at'] . ' UTC'))) . '<br>IP ' . h($c['signed_ip']) . ' &middot; ' . h($c['email']) . '</small></div>';
  } else {
    $html .= '<div><div class="sigline"></div><small>CLIENT: ' . h($c['name']) . '</small></div>';
  }
  return $html . '</div></article>';
}
const CONTRACT_CSS = 'body{font:15px/1.6 Georgia,serif;color:#111;max-width:800px;margin:32px auto;padding:0 20px}h1{font-size:22px;margin:0}h2{font-size:16px;margin:22px 0 6px}.tagline{font-style:italic;color:#555}.parties{border-collapse:collapse;margin:14px 0;width:100%}.parties th{text-align:left;width:170px;font-weight:600}.parties td,.parties th{padding:4px 8px;border-bottom:1px solid #ddd}.plans{list-style:none;padding:0}.sigs{display:grid;grid-template-columns:1fr 1fr;gap:40px;margin-top:40px;align-items:end}.sigline{border-top:1px solid #111;margin-top:8px}.sigtext{border:0;font-style:italic;font-size:20px;border-bottom:1px solid #111;padding-bottom:4px}.sigimg{max-height:90px;max-width:100%;display:block}small{color:#444}.print{position:fixed;top:12px;right:12px;font:14px sans-serif;padding:8px 14px;cursor:pointer}@media print{.print{display:none}body{margin:0}}';

// ---------- routes ----------
try {
switch ($r) {

case 'plans':
  out(['plans' => PLANS, 'callFee' => CALL_FEE, 'extraHour' => EXTRA_HOUR, 'stripe' => (bool)stripe_key()]);

case 'register': {
  $plan = str_in('plan', 10);
  $d = ['name' => str_in('name', 120), 'email' => strtolower(str_in('email', 160)), 'phone' => str_in('phone', 40), 'street' => str_in('street', 160),
        'city' => str_in('city', 80), 'state' => strtoupper(str_in('state', 2)), 'zip' => str_in('zip', 10)];
  $pw = (string)($in['password'] ?? '');
  if (!isset(PLANS[$plan])) fail('Please choose a plan.');
  foreach ($d as $k => $v) if ($v === '') fail('Please fill in all fields.');
  if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) fail('Please enter a valid email.');
  if (strlen($pw) < 8) fail('Password must have at least 8 characters.');
  if (!preg_match('/^\d{5}(-\d{4})?$/', $d['zip'])) fail('Please enter a valid ZIP code.');
  if (q('SELECT 1 FROM clients WHERE email=?', [$d['email']])->fetchColumn()) fail('This email already has an account. Please sign in.', 409);
  q('INSERT INTO clients(created_at,name,email,phone,pass_hash,street,city,state,zip,plan) VALUES(?,?,?,?,?,?,?,?,?,?)',
    [now(), $d['name'], $d['email'], $d['phone'], password_hash($pw, PASSWORD_DEFAULT), $d['street'], $d['city'], $d['state'], $d['zip'], $plan]);
  session_regenerate_id(true);
  $_SESSION['cid'] = (int)db()->lastInsertId();
  out(['client' => public_client(me())]);
}

case 'login': {
  throttle();
  $c = q('SELECT * FROM clients WHERE email=?', [strtolower(str_in('email', 160))])->fetch();
  if (!$c || !password_verify((string)($in['password'] ?? ''), $c['pass_hash'])) failed_login();
  session_regenerate_id(true);
  $_SESSION['cid'] = (int)$c['id'];
  out(['client' => public_client($c)]);
}

case 'logout':
  $_SESSION = []; session_destroy(); out(['ok' => true]);

case 'me': { // client dashboard data
  $c = me();
  sync_clients([$c]);
  $c = me();
  out([
    'client' => public_client($c),
    'visits' => q('SELECT date,hours,description FROM visits WHERE client_id=? ORDER BY date DESC', [$c['id']])->fetchAll(),
    'stripe' => (bool)stripe_key(),
  ]);
}

case 'set_plan': { // before signing, the client may still switch plans
  $c = me();
  if ($c['status'] !== 'contract') fail('Your plan is already active. Please contact us to change it.');
  $plan = str_in('plan', 10);
  if (!isset(PLANS[$plan])) fail('Unknown plan.');
  q('UPDATE clients SET plan=? WHERE id=?', [$plan, $c['id']]);
  out(['client' => public_client(me())]);
}

case 'contract': { // HTML page: preview before signing, signed copy after. Admin may pass &id=
  $c = !empty($_SESSION['admin']) && isset($_GET['id']) ? q('SELECT * FROM clients WHERE id=?', [(int)$_GET['id']])->fetch() : me();
  if (!$c) fail('Not found', 404);
  $body = $c['contract_html'] ?: contract_html($c, false);
  header('Content-Type: text/html; charset=utf-8');
  if (isset($_GET['fragment'])) { echo $body; exit; }
  echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Service Agreement</title><style>' . CONTRACT_CSS . '</style></head><body><button class="print" onclick="print()">Print / Save PDF</button>' . $body . '</body></html>';
  exit;
}

case 'sign': {
  $c = me();
  if ($c['status'] !== 'contract') fail('This agreement is already signed.');
  $name = str_in('name', 120);
  $sig = (string)($in['signature'] ?? '');
  if (mb_strlen($name) < 3) fail('Please type your full name.');
  if (empty($in['agree'])) fail('Please confirm you have read and agree to the agreement.');
  if (!preg_match('#^data:image/png;base64,[A-Za-z0-9+/=]+$#', $sig) || strlen($sig) > 400000) fail('Please draw your signature.');
  q('UPDATE clients SET signed_at=?, signed_name=?, signed_ip=?, signature=?, status=? WHERE id=?', [now(), $name, $_SERVER['REMOTE_ADDR'] ?? '', $sig, 'payment', $c['id']]);
  $c = me();
  q('UPDATE clients SET contract_html=? WHERE id=?', [contract_html($c, true), $c['id']]); // frozen snapshot of what was signed
  out(['client' => public_client(me())]);
}

case 'checkout': {
  $c = me();
  if (!in_array($c['status'], ['payment', 'canceled'], true) || !$c['signed_at']) fail('Please sign the agreement first.');
  if (!stripe_key()) out(['demo' => true]);
  $p = PLANS[$c['plan']];
  $base = base_url();
  $params = [
    'mode' => 'subscription', 'client_reference_id' => (string)$c['id'],
    'line_items' => [['quantity' => 1, 'price_data' => ['currency' => 'usd', 'unit_amount' => $p['price'] * 100, 'recurring' => ['interval' => 'month'],
      'product_data' => ['name' => COMPANY . ' ' . $p['name'] . ' Plan', 'description' => $p['hours'] . ' labor hours per year + Winter Inspection']]]],
    'subscription_data' => ['metadata' => ['client_id' => $c['id'], 'plan' => $c['plan']], 'description' => COMPANY . ' ' . $p['name'] . ' Plan'],
    'metadata' => ['client_id' => $c['id']],
    'success_url' => $base . 'account.html?paid={CHECKOUT_SESSION_ID}',
    'cancel_url' => $base . 'join.html?canceled=1',
  ];
  if ($c['stripe_customer']) $params['customer'] = $c['stripe_customer']; else $params['customer_email'] = $c['email'];
  $s = stripe('POST', 'checkout/sessions', $params);
  out(['url' => $s['url']]);
}

case 'confirm': { // back from Stripe Checkout
  $c = me();
  $s = stripe('GET', 'checkout/sessions/' . rawurlencode(str_in('session_id', 200)), ['expand' => ['subscription']]);
  if (($s['client_reference_id'] ?? '') !== (string)$c['id']) fail('This payment belongs to another account.', 403);
  if (($s['status'] ?? '') !== 'complete') fail('Payment not completed yet.');
  q('UPDATE clients SET stripe_customer=?, start_date=COALESCE(start_date,?), demo=0 WHERE id=?', [$s['customer'], today(), $c['id']]);
  if (is_array($s['subscription'] ?? null)) apply_subscription((int)$c['id'], $s['subscription']);
  out(['client' => public_client(me())]);
}

case 'demo_pay': { // only while Stripe is not connected, so the flow can be previewed end to end
  $c = me();
  if (stripe_key()) fail('Payments are live. Please use the secure checkout.');
  if ($c['status'] !== 'payment') fail('Nothing to pay.');
  q("UPDATE clients SET status='active', demo=1, start_date=?, next_billing=? WHERE id=?", [today(), date('Y-m-d', strtotime('+1 month')), $c['id']]);
  out(['client' => public_client(me())]);
}

case 'portal': { // Stripe customer portal: update card, see invoices
  $c = me();
  if (!$c['stripe_customer']) fail('No billing account yet.');
  $s = stripe('POST', 'billing_portal/sessions', ['customer' => $c['stripe_customer'], 'return_url' => base_url() . 'account.html']);
  out(['url' => $s['url']]);
}

case 'password': { // client changes own password
  $c = me();
  if (!password_verify((string)($in['current'] ?? ''), $c['pass_hash'])) fail('Current password is wrong.');
  if (strlen((string)($in['next'] ?? '')) < 8) fail('New password must have at least 8 characters.');
  q('UPDATE clients SET pass_hash=? WHERE id=?', [password_hash((string)$in['next'], PASSWORD_DEFAULT), $c['id']]);
  out(['ok' => true]);
}

// ----- admin -----
case 'admin_login': {
  throttle();
  if (!password_verify((string)($in['password'] ?? ''), setting('admin_hash') ?: ADMIN_HASH)) failed_login();
  session_regenerate_id(true);
  $_SESSION['admin'] = true;
  out(['ok' => true]);
}

case 'admin_data': {
  admin();
  $rows = q('SELECT * FROM clients ORDER BY id DESC')->fetchAll();
  sync_clients($rows);
  $rows = q('SELECT * FROM clients ORDER BY id DESC')->fetchAll();
  $clients = array_map(fn($c) => public_client($c, true), $rows);
  out(['clients' => $clients, 'stripe' => (bool)stripe_key(), 'stripeMode' => str_starts_with((string)stripe_key(), 'sk_live') || str_starts_with((string)stripe_key(), 'rk_live') ? 'live' : (stripe_key() ? 'test' : null), 'plans' => PLANS]);
}

case 'admin_client': {
  admin();
  $id = (int)($_GET['id'] ?? 0);
  $c = q('SELECT * FROM clients WHERE id=?', [$id])->fetch() ?: fail('Not found', 404);
  out(['client' => public_client($c, true), 'visits' => q('SELECT id,date,hours,description FROM visits WHERE client_id=? ORDER BY date DESC, id DESC', [$id])->fetchAll()]);
}

case 'admin_update': {
  admin();
  $id = (int)($in['id'] ?? 0);
  $st = $in['status'] ?? null;
  if ($st !== null && !in_array($st, ['contract', 'payment', 'active', 'past_due', 'canceled'], true)) fail('Unknown status.');
  q('UPDATE clients SET notes=COALESCE(?,notes), status=COALESCE(?,status), phone=COALESCE(?,phone) WHERE id=?',
    [isset($in['notes']) ? str_in('notes', 5000) : null, $st, isset($in['phone']) ? str_in('phone', 40) : null, $id]);
  out(['ok' => true]);
}

case 'visit_add': {
  admin();
  $id = (int)($in['client_id'] ?? 0);
  $date = str_in('date', 10);
  $hours = max(1, round((float)($in['hours'] ?? 0) * 4) / 4); // 1 hour minimum per visit (contract clause 4)
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) fail('Pick a date.');
  q('INSERT INTO visits(client_id,date,hours,description,created_at) VALUES(?,?,?,?,?)', [$id, $date, $hours, str_in('description', 1000), now()]);
  out(['ok' => true]);
}

case 'visit_delete':
  admin(); q('DELETE FROM visits WHERE id=?', [(int)($in['id'] ?? 0)]); out(['ok' => true]);

case 'reset_password': { // admin generates a temporary password to send to the client by text
  admin();
  $tmp = 'SCH-' . strtoupper(bin2hex(random_bytes(3)));
  q('UPDATE clients SET pass_hash=? WHERE id=?', [password_hash($tmp, PASSWORD_DEFAULT), (int)($in['id'] ?? 0)]);
  out(['password' => $tmp]);
}

case 'cancel_subscription': {
  admin();
  $c = q('SELECT * FROM clients WHERE id=?', [(int)($in['id'] ?? 0)])->fetch() ?: fail('Not found', 404);
  if ($c['stripe_sub']) apply_subscription((int)$c['id'], stripe('DELETE', 'subscriptions/' . $c['stripe_sub']));
  q("UPDATE clients SET status='canceled' WHERE id=?", [$c['id']]);
  out(['ok' => true]);
}

case 'delete_client': { // only for test/abandoned sign-ups that never paid
  admin();
  $c = q('SELECT * FROM clients WHERE id=?', [(int)($in['id'] ?? 0)])->fetch() ?: fail('Not found', 404);
  if ($c['stripe_sub'] && $c['status'] !== 'canceled') fail('Cancel the subscription first.');
  q('DELETE FROM clients WHERE id=?', [$c['id']]);
  out(['ok' => true]);
}

case 'settings': {
  admin();
  $k = stripe_key();
  $acct = null;
  if ($k) { try { $a = stripe('GET', 'account'); $acct = $a['settings']['dashboard']['display_name'] ?? ($a['business_profile']['name'] ?? $a['email'] ?? $a['id']); } catch (Throwable $e) { $acct = 'Error: ' . $e->getMessage(); } }
  out(['stripeKey' => $k ? substr($k, 0, 8) . '...' . substr($k, -4) : null, 'account' => $acct]);
}

case 'settings_save': {
  admin();
  if (array_key_exists('stripe_key', $in)) {
    $k = str_in('stripe_key', 300);
    if ($k !== '' && !preg_match('/^(sk|rk)_(test|live)_[A-Za-z0-9]+$/', $k)) fail('That does not look like a Stripe secret key (starts with sk_live_ or sk_test_).');
    set_setting('stripe_key', $k ?: null);
    if ($k) { try { stripe('GET', 'checkout/sessions', ['limit' => 1]); } catch (Throwable $e) { set_setting('stripe_key', null); fail('Stripe rejected this key: ' . $e->getMessage()); } }
  }
  if (!empty($in['admin_password'])) {
    if (strlen((string)$in['admin_password']) < 10) fail('Admin password must have at least 10 characters.');
    set_setting('admin_hash', password_hash((string)$in['admin_password'], PASSWORD_DEFAULT));
  }
  out(['ok' => true]);
}

case 'health':
  out(['ok' => true, 'php' => PHP_VERSION, 'dataOutsideSite' => !str_starts_with($DATA, dirname(__DIR__))]);

default:
  fail('Not found', 404);
}
} catch (RuntimeException $e) {
  fail($e->getMessage(), 502);
}
