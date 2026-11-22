<?php
/* ------------------------------------------------------------------
 * 粉爪银行 · 卡包 —— 服务端 api.php
 * 许可：GNU General Public License v3.0（GPL-3.0）
 * 上游：https://gitee.com/hiteex/haitecard-web3 （Copyright (C) 2026 Hiteex Team）
 * 修改声明：本文件为改造版（2026-11-22 起）：新增 MySQL/file 双存储、账户操作与电子回单、
 *   一二类账户限额、卡号+户名转账、方斯/环石结售汇、卡片状态管理、全库备份等；
 *   修改日期 2026-11-22。完整对应源码见站点 /api.php?a=source 与首页「开源许可」入口。
 * 免责声明：本项目为虚构银行，仅用于演示与娱乐，不涉及真实资金。
 * ------------------------------------------------------------------ */

/* 粉爪银行 · 卡包 —— 站内后端（PHP）
   存储优先级：MySQL（凭据正确时）→ 文件存储（data/store.php，PHP 前缀保护，不可直接访问）
   接口：ping / auth / get / push / list / op / del / rates / findcard / p2p / conv / card / limit
   调用方式：fetch('api.php', {method:'POST', body: JSON}) 或 GET ?a=ping */

declare(strict_types=1);
ini_set('serialize_precision', '-1');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

/* ---------- 配置（下列默认值只是占位，真实凭据放同目录 config.php，绝不入库） ---------- */
$CFG = [
  'host' => '127.0.0.1',
  'port' => 3306,
  'user' => '',
  'pass' => '',
  'db'   => '',                 // 留空 = 自动探测用户名同名库
  'timeout' => 6,
];
$SECRET      = 'change-me-to-a-random-secret';
$ADMIN_USER  = 'admin';
$ADMIN_PASS  = 'change-me';
$RECEIPT_MAX = 30;
$NOTIF_MAX   = 60;
$DATA_DIR    = __DIR__ . '/data';
$CARD_BIN    = '888888';

/* 私有配置加载：config.php 返回数组即可覆盖上面几项（该文件不进仓库、只在服务器上）
   例：<?php return ['CFG'=>['host'=>'127.0.0.1','user'=>'u','pass'=>'p'], 'SECRET'=>'...',
                       'ADMIN_USER'=>'...', 'ADMIN_PASS'=>'...']; */
$__cfgFile = __DIR__ . '/config.php';
if (is_file($__cfgFile)) {
  $__cfg = include $__cfgFile;
  if (is_array($__cfg)) {
    if (!empty($__cfg['CFG']) && is_array($__cfg['CFG'])) $CFG = array_merge($CFG, $__cfg['CFG']);
    foreach (['SECRET', 'ADMIN_USER', 'ADMIN_PASS'] as $__k) {
      if (isset($__cfg[$__k]) && $__cfg[$__k] !== '') $GLOBALS[$__k] = (string)$__cfg[$__k];
    }
  }
}

/* 主管理员账密：后台可改，持久化在 data/root.json（文件存在即以它为准，否则用上面的出厂默认） */
$rootCfgFile = $DATA_DIR . '/root.json';
if (is_file($rootCfgFile)) {
  $rootCfg = json_decode((string)@file_get_contents($rootCfgFile), true);
  if (is_array($rootCfg) && !empty($rootCfg['user']) && !empty($rootCfg['pass'])) {
    $ADMIN_USER = (string)$rootCfg['user'];
    $ADMIN_PASS = (string)$rootCfg['pass'];
  }
}

/* 账户等级限额（单位：HFS 记价，Ⅱ类参照央行个人Ⅱ类户：单日1万、年累计20万） */
$LIMITS = [
  2 => ['single' => 10000, 'day' => 10000, 'year' => 200000, 'cnt' => 10, 'name' => 'Ⅱ类账户',
        'note' => '未开通 Haitecard：单笔 1 万 / 日累计 1 万 / 年累计 20 万，每日最多 10 笔，不可购汇'],
  1 => ['single' => 500000, 'day' => 2000000, 'year' => 0, 'cnt' => 0, 'name' => 'Ⅰ类账户',
        'note' => '已开通 Haitecard：单笔 50 万 / 日累计 200 万，年限额不限'],
];

/* ---------- 请求解析 ---------- */
$raw = file_get_contents('php://input');
$in  = [];
if (is_string($raw) && $raw !== '') {
  $bodyTmp = json_decode($raw, true);
  if (is_array($bodyTmp)) $in = $bodyTmp;
}
/* 动作名：URL ?a= 优先，其次 body.act（body.a 是账号名，不能当动作） */
$a = (isset($_GET['a']) && $_GET['a'] !== '')
  ? (string)$_GET['a']
  : ((isset($in['act']) && $in['act'] !== '') ? (string)$in['act'] : 'ping');
$token = $in['t'] ?? ($_GET['t'] ?? null);

function jout(array $o): void { echo json_encode($o, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); exit; }
function now_ms(): int { return (int)round(microtime(true) * 1000); }
function rootSave(string $u, string $p): bool {
  global $DATA_DIR;
  if (!is_dir($DATA_DIR)) @mkdir($DATA_DIR, 0705, true);
  $d = ['user' => $u, 'pass' => $p, 'ts' => (int)round(microtime(true) * 1000)];
  return (bool)@file_put_contents($DATA_DIR . '/root.json', json_encode($d, JSON_UNESCAPED_UNICODE), LOCK_EX);
}
/* 管理员令牌与当前主管理员账密绑定：改完账密旧令牌立即失效，必须重新登录 */
function admToken(): string {
  global $SECRET, $ADMIN_USER, $ADMIN_PASS;
  return hash_hmac('sha256', 'admin|v1|' . $ADMIN_USER . '|' . hash('sha256', $ADMIN_PASS), $SECRET);
}
function isAdmin($t): bool { return is_string($t) && $t !== '' && hash_equals(admToken(), $t); }
function rid(int $n = 8): string { return substr(bin2hex(random_bytes($n)), 0, $n); }
function fnum($v): string { return number_format((float)$v, 2, '.', ''); }
function sealOf(string $no, string $ty, float $amt, float $b0, float $b1, string $k): string {
  return strtoupper(substr(hash('sha256', $no . '|' . $ty . '|' . fnum($amt) . '|' . fnum($b0) . '|' . fnum($b1) . '|' . $k), 0, 16));
}
function rcpNoSrv(): string {
  return 'PC' . date('ymd') . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}
function digitsOf($s): string { return preg_replace('/\D/', '', (string)$s); }
function luhnOk(string $d): bool {
  if (strlen($d) < 8) return false;
  $sum = 0; $alt = false;
  for ($i = strlen($d) - 1; $i >= 0; $i--) {
    $n = (int)$d[$i];
    if ($alt) { $n *= 2; if ($n > 9) $n -= 9; }
    $sum += $n; $alt = !$alt;
  }
  return $sum % 10 === 0;
}
function luhnDigit(string $d): int {
  $sum = 0; $alt = true;
  for ($i = strlen($d) - 1; $i >= 0; $i--) {
    $n = (int)$d[$i];
    if ($alt) { $n *= 2; if ($n > 9) $n -= 9; }
    $sum += $n; $alt = !$alt;
  }
  return (10 - ($sum % 10)) % 10;
}
function genCardNo(): string {
  global $CARD_BIN;
  $mid = (string)random_int(100000000, 999999999);
  $base = $CARD_BIN . $mid;
  return $base . luhnDigit($base);
}
function cvcOf(string $no): string {
  /* 与前端 cvcFromNo() 完全同算法（FNV-1a 32bit + xorshift），保证服务端发卡与卡面一致 */
  $s = 'HAITECARD-CVC1|' . digitsOf($no);
  $h = 0x811c9dc5 & 0xFFFFFFFF;
  $len = strlen($s);
  for ($i = 0; $i < $len; $i++) {
    $h ^= ord($s[$i]);
    $h = ($h + (($h << 1) + ($h << 4) + ($h << 7) + ($h << 8) + ($h << 24))) & 0xFFFFFFFF;
  }
  $x = ($h ^ 0x9e3779b9) & 0xFFFFFFFF;
  for ($k = 0; $k < 4; $k++) {
    $x ^= ($x << 13); $x &= 0xFFFFFFFF;
    $x ^= ($x >> 17);
    $x ^= ($x << 5);  $x &= 0xFFFFFFFF;
  }
  $v = ($x ^ ($h >> 7)) & 0xFFFFFFFF;
  return str_pad((string)($v % 1000), 3, '0', STR_PAD_LEFT);
}
function maskName(string $n): string {
  $n = trim($n);
  if ($n === '') return '—';
  $len = mb_strlen($n, 'UTF-8');
  if ($len <= 1) return $n;
  if ($len === 2) return mb_substr($n, 0, 1, 'UTF-8') . '*';
  return mb_substr($n, 0, 1, 'UTF-8') . str_repeat('*', min(3, $len - 2)) . mb_substr($n, -1, 1, 'UTF-8');
}
function maskCard(string $no): string {
  $d = digitsOf($no);
  if (strlen($d) < 8) return $no;
  return substr($d, 0, 4) . ' **** **** ' . substr($d, -4);
}

/* ---------- 汇率：服务端按日缓存真实 USD/CNY，含中行式买入/卖出价 ---------- */
function ratesPath(): string { global $DATA_DIR; return $DATA_DIR . '/rates.json'; }
function ratesLoad(): array {
  $f = ratesPath();
  if (!is_file($f)) return [];
  $j = json_decode((string)@file_get_contents($f), true);
  return is_array($j) ? $j : [];
}
function ratesSave(array $d): void {
  global $DATA_DIR;
  if (!is_dir($DATA_DIR)) @mkdir($DATA_DIR, 0705, true);
  @file_put_contents(ratesPath(), json_encode($d, JSON_UNESCAPED_UNICODE), LOCK_EX);
}
function ratesFetch(): ?float {
  $urls = [
    'https://open.er-api.com/v6/latest/USD',
    'https://api.exchangerate-api.com/v4/latest/USD',
  ];
  foreach ($urls as $u) {
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'header' => "User-Agent: PinkPawlBank/1.0\r\n"]]);
    $raw = @file_get_contents($u, false, $ctx);
    if (!is_string($raw) || $raw === '') continue;
    $j = json_decode($raw, true);
    if (!is_array($j)) continue;
    $cny = $j['rates']['CNY'] ?? ($j['conversion_rates']['CNY'] ?? null);
    if (is_numeric($cny) && $cny > 4 && $cny < 12) return round((float)$cny, 4);
  }
  return null;
}
function ratesToday(): array {
  $d = ratesLoad();
  $today = date('Y-m-d');
  if (($d['date'] ?? '') === $today && isset($d['mid'])) return $d;
  $mid = ratesFetch();
  $src = 'live';
  if ($mid === null) { $mid = $d['mid'] ?? 7.10; $src = 'fallback'; }
  $d = ['date' => $today, 'mid' => $mid, 'src' => $src, 'ts' => now_ms()];
  ratesSave($d);
  return $d;
}
function ratesQuote(): array {
  $d = ratesToday();
  $mid = (float)$d['mid'];
  /* 中行风格：银行现汇买入价 < 中间价 < 现汇卖出价；现钞买入更低 */
  $buy  = round($mid * (1 - 0.0035), 4);   // 结汇（客户卖环石，银行买入）
  $sell = round($mid * (1 + 0.0035), 4);   // 购汇（客户买环石，银行卖出）
  $cash = round($mid * (1 - 0.0080), 4);
  return ['mid' => $mid, 'buy' => $buy, 'sell' => $sell, 'cash' => $cash,
          'date' => $d['date'], 'src' => $d['src'], 'ts' => $d['ts'] ?? now_ms()];
}

/* ---------------- 存储层：MySQL ---------------- */
$pdo = null; $dbName = ''; $dbs = []; $dbErr = '';
try {
  $pdo = new PDO(
    "mysql:host={$CFG['host']};port={$CFG['port']};charset=utf8mb4",
    $CFG['user'], $CFG['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
     PDO::ATTR_TIMEOUT => $CFG['timeout'], PDO::ATTR_EMULATE_PREPARES => false]
  );
  /* 免费主机通常禁用 SHOW DATABASES（1227），改用 information_schema 列库 + 直接 USE 校验；
     选定的库名缓存到 data/db.json，后续请求直接 USE，避免偶发查询失败导致「一秒 mysql 一秒 file」的数据分叉 */
  global $DATA_DIR;
  $dbCache = $DATA_DIR . '/db.json';
  $cached = '';
  if (is_file($dbCache)) {
    $cj = json_decode((string)@file_get_contents($dbCache), true);
    if (is_array($cj) && !empty($cj['db'])) $cached = (string)$cj['db'];
  }
  if ($cached !== '') {
    try {
      $pdo->exec('USE `' . str_replace('`', '', $cached) . '`');
      $pdo->query('SELECT 1');
      $dbName = $cached;
      $dbs = [$cached];
    } catch (Throwable $e) { $cached = ''; }
  }
  if ($dbName === '') {
  $sys = ['information_schema','mysql','performance_schema','sys','phpmyadmin','test'];
  foreach ($pdo->query('SELECT SCHEMA_NAME s FROM information_schema.SCHEMATA') as $r) { $dbs[] = $r['s']; }
  $cand = array_values(array_filter($dbs, fn($d) => !in_array(strtolower($d), $sys, true)));
  sort($cand);
  /* 候选顺序：用户名同名库 → 其余库 → 用户名本身（有些主机库名只在授权里，不在 SCHEMATA 可见列表） */
  $try = [];
  foreach ($cand as $d) { if (strcasecmp($d, $CFG['user']) === 0) array_unshift($try, $d); else $try[] = $d; }
  if (!in_array($CFG['user'], $try, true)) $try[] = $CFG['user'];
  foreach ($try as $d) {
    try { $pdo->exec("USE `" . str_replace('`', '', $d) . "`"); $dbName = $d; break; } catch (Throwable $e) { /* 下一个 */ }
  }
  if ($dbName === '') throw new RuntimeException('no usable database (checked: ' . implode(',', $try) . ')');
  $pdo->query('SELECT 1');
  if (!is_dir($DATA_DIR)) @mkdir($DATA_DIR, 0705, true);
  @file_put_contents($dbCache, json_encode(['db' => $dbName, 'ts' => now_ms()], JSON_UNESCAPED_UNICODE), LOCK_EX);
  }
  $pdo->exec("CREATE TABLE IF NOT EXISTS pc_meta (
    mkey VARCHAR(64) NOT NULL PRIMARY KEY, mval TEXT NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  $pdo->exec("CREATE TABLE IF NOT EXISTS pc_users (
    k VARCHAR(64) NOT NULL PRIMARY KEY, p VARCHAR(16) NOT NULL DEFAULT '', a VARCHAR(64) NOT NULL DEFAULT '',
    name VARCHAR(64) NOT NULL DEFAULT '', avatar TEXT NULL, pin VARCHAR(128) NOT NULL DEFAULT '',
    st MEDIUMTEXT NULL, ver INT NOT NULL DEFAULT 0, upd BIGINT NOT NULL DEFAULT 0
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  /* MySQL 首次可用时把文件存储里的账号搬进库；只做一次（pc_meta.seeded 记住）。
     旧实现只要表为空就回灌，管理员清空用户后会把旧库里的人一个个拉回来（删号复活） */
  try {
    $seeded = false;
    try {
      $q = $pdo->prepare('SELECT mval FROM pc_meta WHERE mkey=?'); $q->execute(['seeded']);
      $rr = $q->fetch(); $seeded = ($rr && (string)$rr['mval'] === '1');
    } catch (Throwable $e2) { $seeded = false; }
    if (!$seeded) {
      $cnt = (int)$pdo->query('SELECT COUNT(*) c FROM pc_users')->fetch()['c'];
      if ($cnt === 0 && is_file(storePath())) {
        $sd = storeRead();
        if (!empty($sd['users'])) {
          $ins = $pdo->prepare('INSERT INTO pc_users (k,p,a,name,avatar,pin,st,ver,upd) VALUES (?,?,?,?,?,?,?,?,?)');
          foreach ($sd['users'] as $u) {
            $ins->execute([$u['k'], $u['p'], $u['a'], $u['name'], $u['avatar'], $u['pin'], $u['st'], (int)$u['ver'], (int)$u['upd']]);
          }
        }
      }
      $pdo->prepare('REPLACE INTO pc_meta (mkey,mval) VALUES (?,?)')->execute(['seeded', '1']);
    }
  } catch (Throwable $e) { /* 迁移失败不影响主流程 */ }
} catch (Throwable $e) {
  $dbErr = $e->getMessage();
  $pdo = null;
}
$MODE = $pdo ? 'mysql' : 'file';

/* ---------------- 存储层：文件（PHP 前缀保护） ---------------- */
function storePath(): string { global $DATA_DIR; return $DATA_DIR . '/store.php'; }
function storeRead(): array {
  $f = storePath();
  if (!is_file($f)) return ['users' => []];
  $s = @file_get_contents($f);
  if (!is_string($s)) return ['users' => []];
  $p = strpos($s, '?>');
  if ($p !== false) $s = substr($s, $p + 2);
  $j = json_decode($s, true);
  if (!is_array($j) || !isset($j['users'])) return ['users' => []];
  return $j;
}
function storeWrite(array $d): bool {
  global $DATA_DIR;
  if (!is_dir($DATA_DIR)) @mkdir($DATA_DIR, 0705, true);
  $f = storePath();
  $body = "<?php http_response_code(204); exit; ?>\n" . json_encode($d, JSON_UNESCAPED_UNICODE);
  return @file_put_contents($f, $body, LOCK_EX) !== false;
}
/* 读改写事务（单文件加锁） */
function storeTx(callable $fn) {
  global $DATA_DIR;
  if (!is_dir($DATA_DIR)) @mkdir($DATA_DIR, 0705, true);
  $lock = @fopen($DATA_DIR . '/.lock', 'c');
  if ($lock) flock($lock, LOCK_EX);
  $d = storeRead();
  $r = $fn($d);
  storeWrite($d);
  if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
  return $r;
}

/* ---------------- 统一 CRUD ---------------- */
function pcGet(string $k): ?array {
  global $pdo, $MODE;
  if ($MODE === 'mysql') {
    $s = $pdo->prepare('SELECT k,p,a,name,avatar,pin,st,ver,upd FROM pc_users WHERE k=?');
    $s->execute([$k]);
    $r = $s->fetch();
    return $r ?: null;
  }
  $d = storeRead();
  return $d['users'][$k] ?? null;
}
function pcList(): array {
  global $pdo, $MODE;
  if ($MODE === 'mysql') {
    $out = [];
    foreach ($pdo->query('SELECT k,p,a,name,avatar,pin,st,ver,upd FROM pc_users ORDER BY upd DESC') as $r) $out[] = $r;
    return $out;
  }
  $d = storeRead();
  $out = array_values($d['users']);
  usort($out, fn($x, $y) => ((int)$y['upd']) <=> ((int)$x['upd']));
  return $out;
}
/* 写库前把当前这一版落盘备份，防止任何一次误覆盖造成不可逆丢数据 */
function pcBackup(?array $cur, string $k): void {
  global $DATA_DIR;
  if (!$cur) return;
  $dir = $DATA_DIR . '/bak';
  if (!is_dir($dir)) @mkdir($dir, 0705, true);
  $safe = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $k);
  $f = $dir . '/' . $safe . '-' . date('Ymd-His') . '-' . substr((string)(int)$cur['ver'], 0, 4) . '.json';
  @file_put_contents($f, json_encode($cur, JSON_UNESCAPED_UNICODE), LOCK_EX);
  $all = glob($dir . '/' . $safe . '-*.json') ?: [];
  if (count($all) > 20) {
    sort($all);
    foreach (array_slice($all, 0, count($all) - 20) as $old) @unlink($old);
  }
}
function pcPut(string $k, array $row, bool $force, int $baseVer): array {
  global $pdo, $MODE;
  $cur = pcGet($k);
  pcBackup($cur, $k);
  if ($cur && !$force && $baseVer !== (int)$cur['ver']) return ['ok' => 1, 'conflict' => 1, 'rec' => $cur];
  $ver = $cur ? ((int)$cur['ver'] + 1) : 1;
  $row['ver'] = $ver; $row['upd'] = now_ms(); $row['k'] = $k;
  if ($MODE === 'mysql') {
    if ($cur) {
      $q = $pdo->prepare('UPDATE pc_users SET p=?,a=?,name=?,avatar=?,pin=?,st=?,ver=?,upd=? WHERE k=?');
      $q->execute([$row['p'],$row['a'],$row['name'],$row['avatar'],$row['pin'],$row['st'],$ver,$row['upd'],$k]);
    } else {
      $q = $pdo->prepare('INSERT INTO pc_users (k,p,a,name,avatar,pin,st,ver,upd) VALUES (?,?,?,?,?,?,?,?,?)');
      $q->execute([$k,$row['p'],$row['a'],$row['name'],$row['avatar'],$row['pin'],$row['st'],$ver,$row['upd']]);
    }
  } else {
    storeTx(function (&$d) use ($row) { $d['users'][$row['k']] = $row; });
  }
  return ['ok' => 1, 'ver' => $ver];
}
/* ---------- 删除墓碑：被删账号禁止被陈旧前台同步“复活” ---------- */
function tombPath(): string { global $DATA_DIR; return $DATA_DIR . '/tomb.json'; }
/* 读取墓碑：加共享锁，避免读到只写了一半的文件 */
function tombAll(): array {
  $f = tombPath();
  if (!is_file($f)) return [];
  $fh = @fopen($f, 'r');
  if ($fh) {
    @flock($fh, LOCK_SH);
    $raw = (string)stream_get_contents($fh);
    @flock($fh, LOCK_UN);
    fclose($fh);
  } else {
    $raw = (string)@file_get_contents($f);
  }
  $j = json_decode($raw, true);
  return is_array($j) ? $j : [];
}
/* 墓碑写入一律走「打开 -> 排他锁 -> 读 -> 改 -> 覆写 -> 解锁」的原子改写。
   旧实现是 read-modify-write 后 file_put_contents，两次删除并发时会互相覆盖，
   丢掉的条目就等于「删号可以复活」，这里从跟上修掉。 */
function tombMutate(callable $fn) {
  global $DATA_DIR;
  if (!is_dir($DATA_DIR)) @mkdir($DATA_DIR, 0705, true);
  $f = tombPath();
  $fh = @fopen($f, 'c+');
  if (!$fh) return null;
  if (!@flock($fh, LOCK_EX)) { fclose($fh); return null; }
  $raw = '';
  rewind($fh);
  while (!feof($fh)) { $chunk = fread($fh, 8192); if ($chunk === false) break; $raw .= $chunk; }
  $t = json_decode($raw, true);
  if (!is_array($t)) $t = [];
  $out = $fn($t);
  if (!is_array($out)) $out = $t;
  rewind($fh);
  ftruncate($fh, 0);
  fwrite($fh, json_encode($out, JSON_UNESCAPED_UNICODE));
  fflush($fh);
  flock($fh, LOCK_UN);
  fclose($fh);
  return $out;
}
function tombSet(string $k, int $ver, string $by = ''): void {
  tombMutate(function (array $t) use ($k, $ver, $by) {
    $t[$k] = ['ts' => now_ms(), 'ver' => $ver, 'by' => $by];
    return $t;
  });
}
function tombClear(string $k): bool {
  $had = isset(tombAll()[$k]);
  tombMutate(function (array $t) use ($k) {
    unset($t[$k]);
    return $t;
  });
  return $had;
}

function pcDel(string $k): int {
  global $pdo, $MODE;
  /* 无论当前存储模式，都把文件库镜像里的同一行一起清掉：
     MySQL 抖动回落到文件库时，被删的账号不会从旧库里复活 */
  $mirror = function () use ($k): int {
    return (int)storeTx(function (&$d) use ($k) { $n = isset($d['users'][$k]) ? 1 : 0; unset($d['users'][$k]); return $n; });
  };
  if ($MODE === 'mysql') {
    $q = $pdo->prepare('DELETE FROM pc_users WHERE k=?'); $q->execute([$k]);
    $r = (int)$q->rowCount(); $mirror(); return $r;
  }
  return $mirror();
}

/* ---------------- 业务辅助 ---------------- */
function tierOf(array $st): int { return !empty($st['cardOpened']) ? 1 : 2; }
function limOf(array $st): array {
  $lim = $st['lim'] ?? [];
  if (!is_array($lim)) $lim = [];
  $today = date('Y-m-d'); $month = date('Y-m'); $year = date('Y');
  if (($lim['d'] ?? '') !== $today) $lim['d'] = $today;
  if (($lim['m'] ?? '') !== $month) $lim['m'] = $month;
  if (($lim['y'] ?? '') !== $year) $lim['y'] = $year;
  foreach (['dAmt','dCnt','mAmt','mCnt','yAmt'] as $f) if (!isset($lim[$f])) $lim[$f] = 0;
  return $lim;
}
function limCheck(array &$st, float $amt, string $kind = 'out'): ?string {
  global $LIMITS;
  $tier = tierOf($st);
  $rule = $LIMITS[$tier];
  if ($amt > $rule['single'] + 1e-6) {
    return '超出单笔限额：' . $rule['name'] . '单笔上限 ' . number_format($rule['single']) . ' HFS';
  }
  $lim = limOf($st);
  if ($rule['day'] > 0 && $lim['dAmt'] + $amt > $rule['day'] + 1e-6) {
    return '超出日累计限额：今日已用 ' . number_format($lim['dAmt']) . ' / ' . number_format($rule['day']) . ' HFS';
  }
  if ($rule['year'] > 0 && $lim['yAmt'] + $amt > $rule['year'] + 1e-6) {
    return '超出年累计限额：本年已用 ' . number_format($lim['yAmt']) . ' / ' . number_format($rule['year']) . ' HFS';
  }
  if ($rule['cnt'] > 0 && $lim['dCnt'] + 1 > $rule['cnt']) {
    return '超出每日笔数上限（' . $rule['cnt'] . ' 笔）';
  }
  return null;
}
function limAdd(array &$st, float $amt): void {
  $lim = limOf($st);
  $lim['dAmt'] = round($lim['dAmt'] + $amt, 2);
  $lim['dCnt'] = (int)$lim['dCnt'] + 1;
  $lim['mAmt'] = round(($lim['mAmt'] ?? 0) + $amt, 2);
  $lim['mCnt'] = (int)($lim['mCnt'] ?? 0) + 1;
  $lim['yAmt'] = round($lim['yAmt'] + $amt, 2);
  $st['lim'] = $lim;
}
function pushRcp(array &$st, array $rcp): void {
  global $RECEIPT_MAX;
  $st['receipts'] = is_array($st['receipts'] ?? null) ? $st['receipts'] : [];
  array_unshift($st['receipts'], $rcp);
  $st['receipts'] = array_slice($st['receipts'], 0, $RECEIPT_MAX);
}
function pushNotif(array &$st, string $kind, string $ti, string $b, string $rc = ''): void {
  global $NOTIF_MAX;
  $st['notifs'] = is_array($st['notifs'] ?? null) ? $st['notifs'] : [];
  array_unshift($st['notifs'], ['id' => rid(), 't' => now_ms(), 'k' => $kind,
    'ti' => mb_substr($ti, 0, 40, 'UTF-8'), 'b' => mb_substr($b, 0, 120, 'UTF-8'), 'r' => false, 'rc' => $rc]);
  $st['notifs'] = array_slice($st['notifs'], 0, $NOTIF_MAX);
}
function pushTx(array &$st, string $dir, float $amt, string $cur, string $name, string $rcp): void {
  $st['tx'] = is_array($st['tx'] ?? null) ? $st['tx'] : [];
  array_unshift($st['tx'], ['dir' => $dir, 'amt' => $amt, 'cur' => $cur, 'name' => $name,
    't' => now_ms(), 'st' => '已执行', 'rcp' => $rcp]);
  $st['tx'] = array_slice($st['tx'], 0, 60);
}
function saveRow(array $row, array $st, bool $force, int $baseVer): array {
  return pcPut($row['k'], ['p' => $row['p'], 'a' => $row['a'], 'name' => $row['name'],
    'avatar' => $row['avatar'], 'pin' => $row['pin'], 'st' => json_encode($st, JSON_UNESCAPED_UNICODE)], $force, $baseVer);
}
function stOf(array $row): array {
  $st = json_decode((string)$row['st'], true);
  return is_array($st) ? $st : [];
}
/* 服务端按卡号索引：每次调用现查（账号数量小，够用） */
function findByCard(string $card, string $name = ''): ?array {
  $d = digitsOf($card);
  if ($d === '') return null;
  $name = trim($name);
  foreach (pcList() as $r) {
    $st = stOf($r);
    $no = digitsOf($st['card']['no'] ?? '');
    if ($no !== '' && $no === $d) {
      if ($name !== '' && trim((string)($st['user'] ?? $r['name'])) !== $name) continue;
      return ['row' => $r, 'st' => $st];
    }
  }
  return null;
}

/* 昵称全站唯一：与各账号存档里的 user（回退到行里的 name）比对 */
function nickTaken(string $name, string $exceptKey = ''): bool {
  $n = trim($name);
  if ($n === '') return false;
  foreach (pcList() as $r) {
    if ((string)$r['k'] === $exceptKey) continue;
    $st = stOf($r);
    $cur = trim((string)($st['user'] ?? ''));
    if ($cur === '') $cur = trim((string)($r['name'] ?? ''));
    if ($cur !== '' && $cur === $n) return true;
  }
  return false;
}

/* 卡号全站唯一：按数字比对各账号存档里的卡号（忽略空格） */
function cardTaken(string $no, string $exceptKey = ''): bool {
  $d = digitsOf($no);
  if ($d === '') return false;
  foreach (pcList() as $r) {
    if ((string)$r['k'] === $exceptKey) continue;
    $st = stOf($r);
    $cur = digitsOf($st['card']['no'] ?? '');
    if ($cur !== '' && $cur === $d) return true;
  }
  return false;
}
function groupDigits(string $d): string { return trim(chunk_split($d, 4, ' ')); }
/* 云端下发：撞号的卡号一律换成随机可用卡号（BIN 固定 888888 + Luhn） */
function genCardNoUnique(string $exceptKey = ''): string {
  for ($i = 0; $i < 40; $i++) {
    $n = genCardNo();
    if (!cardTaken($n, $exceptKey)) return $n;
  }
  return genCardNo();
}
/* 云端下发：撞名的昵称一律换成随机可用昵称 */
function genNickUnique(string $exceptKey = ''): string {
  for ($i = 0; $i < 40; $i++) {
    $n = '用户' . substr(bin2hex(random_bytes(4)), 0, 6);
    if (!nickTaken($n, $exceptKey)) return $n;
  }
  return '用户' . substr(bin2hex(random_bytes(4)), 0, 6);
}

function boundOwnerOf(string $k): string {
  foreach (subAll() as $n => $v) {
    if ($k !== '' && (string)($v['bound'] ?? '') === $k) return $n;
  }
  return '';
}

/* ---------------- 全站唯一 UID（6 位起，随机，永不复用） ---------------- */
function frozenPath(): string { global $DATA_DIR; return $DATA_DIR . '/frozen.json'; }
function frozenAll(): array {
  $f = frozenPath();
  if (!is_file($f)) return [];
  $j = json_decode((string)@file_get_contents($f), true);
  return is_array($j) ? $j : [];
}
function freezeUid(string $uid, string $k = ''): void {
  global $DATA_DIR;
  if ($uid === '') return;
  if (!is_dir($DATA_DIR)) @mkdir($DATA_DIR, 0705, true);
  $a = frozenAll();
  $a[$uid] = ['ts' => now_ms(), 'k' => $k];
  @file_put_contents(frozenPath(), json_encode($a, JSON_UNESCAPED_UNICODE), LOCK_EX);
}
function uidTaken(string $uid): bool {
  if ($uid === '') return false;
  if (isset(frozenAll()[$uid])) return true;
  foreach (pcList() as $r) {
    $st = stOf($r);
    if ((string)($st['pcuid'] ?? '') === $uid) return true;
  }
  return false;
}
function newUid(): string {
  for ($len = 6; $len <= 9; $len++) {
    for ($i = 0; $i < 60; $i++) {
      $uid = '';
      for ($j = 0; $j < $len; $j++) $uid .= (string)random_int(0, 9);
      if (!uidTaken($uid)) return $uid;
    }
  }
  return (string)random_int(100000000, 999999999);
}

/* ---------------- 二级管理员（默认不注册，自助注册时绑定一个个人账户） ---------------- */
function subPath(): string { global $DATA_DIR; return $DATA_DIR . '/admins.json'; }
function subAll(): array {
  $f = subPath();
  if (!is_file($f)) return [];
  $j = json_decode((string)@file_get_contents($f), true);
  return is_array($j) ? $j : [];
}
function subSave(array $a): void {
  global $DATA_DIR;
  if (!is_dir($DATA_DIR)) @mkdir($DATA_DIR, 0705, true);
  @file_put_contents(subPath(), json_encode($a, JSON_UNESCAPED_UNICODE), LOCK_EX);
}
function subHash(string $pass): string { return hash('sha256', 'pinkclaw::subadmin::' . $pass); }
function subToken(string $name): string {
  global $SECRET;
  return 'sub.' . rawurlencode($name) . '.' . hash_hmac('sha256', 'admin|sub|' . $name, $SECRET);
}
function scopeOf($t): ?array {
  global $SECRET;
  if (!is_string($t) || $t === '') return null;
  if (isAdmin($t)) return ['role' => 'root'];
  if (strpos($t, 'sub.') !== 0) return null;
  $p = explode('.', $t);
  if (count($p) !== 3) return null;
  $name = rawurldecode($p[1]);
  if (!hash_equals(hash_hmac('sha256', 'admin|sub|' . $name, $SECRET), (string)$p[2])) return null;
  $subs = subAll();
  if (!isset($subs[$name])) return null;
  return ['role' => 'sub', 'name' => $name, 'bound' => (string)($subs[$name]['bound'] ?? '')];
}
function scopeCan(?array $sc, string $k): bool {
  if (!$sc) return false;
  if ($sc['role'] === 'root') return true;
  return $k !== '' && $k === (string)($sc['bound'] ?? '');
}
function boundTaken(string $k, string $except = ''): bool {
  foreach (subAll() as $n => $v) {
    if ($n === $except) continue;
    if ((string)($v['bound'] ?? '') === $k) return true;
  }
  return false;
}

/* ---------------- 用户级凭据：utk.<b64u(k)>.<hmac> ----------------
   管理员令牌管的是「后台」，这里解决的是「账户本身」。
   整改前 get 把整条存档（含 PIN 摘要）直接回给任何人，push 对不存在的 key 也能凭空建档，
   于是「知道账号 key」就等于「拥有这个账号」。
   现在：匿名 get 只回元数据；读写存档需要用户令牌（凭 PIN 换取），
   或与云端一致的 PIN 摘要（兼容旧客户端）。 */
function userToken(string $k): string {
  global $SECRET;
  $b = rtrim(strtr(base64_encode($k), '+/', '-_'), '=');
  return 'utk.' . $b . '.' . hash_hmac('sha256', 'user|' . $k, $SECRET);
}
function userScopeOf($t): ?array {
  global $SECRET;
  if (!is_string($t) || strpos($t, 'utk.') !== 0) return null;
  $p = explode('.', $t);
  if (count($p) !== 3) return null;
  $k = base64_decode(strtr($p[1], '-_', '+/'), true);
  if ($k === false || $k === '') return null;
  if (!hash_equals(hash_hmac('sha256', 'user|' . $k, $SECRET), (string)$p[2])) return null;
  return ['role' => 'user', 'k' => $k];
}

/* ---------------- PIN 摘要 v2：PBKDF2 加盐，惰性升级 ----------------
   旧库存的是无盐 sha256，4 位 PIN 只有 1 万种，拿到摘要即可离线枚举。
   新登录成功时用提交上来的摘要派生一份 PBKDF2 存到 data/pinv2.json；
   下次登录优先用 v2 比对，失败再回退旧摘要（兼容改过 PIN 的老账号）。 */
function pinV2Path(): string { global $DATA_DIR; return $DATA_DIR . '/pinv2.json'; }
function pinV2All(): array {
  $f = pinV2Path();
  if (!is_file($f)) return [];
  $j = json_decode((string)@file_get_contents($f), true);
  return is_array($j) ? $j : [];
}
function pinV2Save(array $a): void {
  global $DATA_DIR;
  if (!is_dir($DATA_DIR)) @mkdir($DATA_DIR, 0705, true);
  @file_put_contents(pinV2Path(), json_encode($a, JSON_UNESCAPED_UNICODE), LOCK_EX);
}
function pinV2Make(string $pin): array {
  $salt = bin2hex(random_bytes(16));
  return ['v' => 1, 's' => $salt, 'i' => 100000, 'h' => hash_pbkdf2('sha256', $pin, $salt, 100000, 32)];
}
function pinV2Check(array $m, string $pin): bool {
  if ((int)($m['v'] ?? 0) !== 1) return false;
  $h = hash_pbkdf2('sha256', $pin, (string)($m['s'] ?? ''), (int)($m['i'] ?? 100000), 32);
  return hash_equals((string)($m['h'] ?? ''), $h);
}
function pinVerifyRow(array $row, string $pin): int {
  $k = (string)($row['k'] ?? '');
  $v2 = pinV2All();
  if (isset($v2[$k]) && is_array($v2[$k]) && pinV2Check($v2[$k], $pin)) return 1;   // v2 命中
  $legacy = trim((string)($row['pin'] ?? ''));
  if ($legacy !== '' && hash_equals($legacy, $pin)) return 2;                       // 旧摘要命中
  return 0;
}
function pinUpgrade(string $k, string $pin): void {
  $v2 = pinV2All();
  if (isset($v2[$k]) && is_array($v2[$k]) && pinV2Check($v2[$k], $pin)) return;
  $v2[$k] = pinV2Make($pin);
  pinV2Save($v2);
}

/* ---------------- 登录失败限流：4 位 PIN 只有 1 万种，不限速等于没有密码 ---------------- */
function rlPath(): string { global $DATA_DIR; return $DATA_DIR . '/rl.json'; }
function rlAll(): array {
  $f = rlPath(); if (!is_file($f)) return [];
  $j = json_decode((string)@file_get_contents($f), true);
  return is_array($j) ? $j : [];
}
function rlSave(array $a): void {
  global $DATA_DIR;
  if (!is_dir($DATA_DIR)) @mkdir($DATA_DIR, 0705, true);
  @file_put_contents(rlPath(), json_encode($a, JSON_UNESCAPED_UNICODE), LOCK_EX);
}
function rlState(string $k): array {
  $a = rlAll();
  return is_array($a[$k] ?? null) ? ['n' => (int)($a[$k]['n'] ?? 0), 'until' => (int)($a[$k]['until'] ?? 0)] : ['n' => 0, 'until' => 0];
}
function rlLocked(string $k): int {
  $s = rlState($k);
  $left = $s['until'] - now_ms();
  return $left > 0 ? (int)ceil($left / 1000) : 0;
}
function rlFail(string $k): array {
  $a = rlAll(); $s = rlState($k);
  $s['n']++;
  if ($s['n'] >= 5) { $s['n'] = 0; $s['until'] = now_ms() + 600000; }
  $a[$k] = $s; rlSave($a);
  return ['left' => max(0, 5 - $s['n']), 'locked' => $s['until'] > now_ms() ? 600 : 0];
}
function rlClear(string $k): void {
  $a = rlAll(); if (isset($a[$k])) { unset($a[$k]); rlSave($a); }
}

/* ---------------- 审计：只记失败与拒绝，超过 300KB 自动截断 ---------------- */
function auditLog(string $s): void {
  global $DATA_DIR;
  if (!is_dir($DATA_DIR)) @mkdir($DATA_DIR, 0705, true);
  $f = $DATA_DIR . '/audit.log';
  if (is_file($f) && filesize($f) > 307200) {
    $lines = @file($f, FILE_IGNORE_NEW_LINES);
    if (is_array($lines)) @file_put_contents($f, implode("\n", array_slice($lines, -2000)) . "\n", LOCK_EX);
  }
  @file_put_contents($f, date('c') . ' ' . $s . "\n", FILE_APPEND | LOCK_EX);
}

/* ---------------- 路由 ---------------- */
/* 临时诊断：逐个候选主机测试连通性（需 probe key，避免公开暴露） */
if ($a === 'dbprobe') {
  if (!isAdmin($token)) jout(['ok' => 0, 'err' => 'auth']);
  $hosts = [$CFG['host'], 'localhost', '127.0.0.1'];
  $users = [$CFG['user'], 'root'];
  $res = [];
  foreach ($hosts as $h) {
    $s = @fsockopen($h, 3306, $en, $es, 1);
    $res[$h]['tcp'] = $s ? 'open' : ('fail ' . $en . ' ' . $es);
    if ($s) fclose($s);
    if (!$s) continue;
    foreach ($users as $u) {
      try {
        $p = new PDO("mysql:host=$h;port=3306;charset=utf8mb4", $u, $CFG['pass'],
          [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
        $db = [];
        foreach ($p->query('SHOW DATABASES') as $r) $db[] = $r['Database'];
        $cu = $p->query('SELECT CURRENT_USER()')->fetchColumn();
        $res[$h]['users'][$u] = ['ok' => 1, 'dbs' => $db, 'current' => $cu];
      } catch (Throwable $e) {
        $info = $e instanceof PDOException ? (array)$e->errorInfo : [];
        $res[$h]['users'][$u] = ['ok' => 0, 'err' => $e->getMessage(), 'code' => $info[1] ?? 0];
      }
    }
  }
  jout(['ok' => 1, 'probe' => $res, 'ip' => $_SERVER['SERVER_ADDR'] ?? '', 'remote' => $_SERVER['REMOTE_ADDR'] ?? '',
        'user' => $CFG['user'], 'host' => $CFG['host'], 'dbname' => $dbName, 'dberr' => $dbErr]);
}

/* ---------- 对应源码（GPL-3.0 合规：公开本文件源码，凭据打码） ---------- */
if ($a === 'source') {
  header('Content-Type: text/plain; charset=utf-8');
  header('X-Robots-Tag: noindex, nofollow');
  $src = (string)file_get_contents(__FILE__);
  /* 直接按实际值打码，避免正则转义踩坑：库名/库用户/库密码/令牌密钥/后台账号密码 */
  $secrets = array_filter([$CFG['user'], $CFG['pass'], $SECRET, $ADMIN_USER, $ADMIN_PASS], function ($v) {
    return is_string($v) && strlen($v) >= 4;
  });
  $src = str_replace($secrets, '********', $src);
  echo "/* 源码中的数据库与后台凭据已打码（********），部署时请填写你自己的值。 */\n";
  echo $src;
  exit;
}

if ($a === 'ping') {
  $out = ['ok' => 1, 'php' => PHP_VERSION, 'store' => $MODE, 'db' => $dbName];
  if ($token !== '' && $token !== null) {
    $out['dberr'] = $MODE === 'mysql' ? '' : $dbErr;
    $out['dbs'] = $dbs;
    $out['users'] = count(pcList());
    $out['tables'] = [];
    if ($MODE === 'mysql') { try { foreach ($pdo->query('SHOW TABLES') as $r) $out['tables'][] = array_values($r)[0]; } catch (Throwable $e) { $out['taberr'] = $e->getMessage(); } }
  }
  jout($out);
}

/* 令牌 -> 权限范围：null / ['role'=>'root'] / ['role'=>'sub','name'=>..,'bound'=>..] */
$SCOPE = scopeOf($token);
$SUBW  = $SCOPE && $SCOPE['role'] === 'sub' ? (string)$SCOPE['name'] : '';

switch ($a) {

  case 'auth': {
    $u = trim((string)($in['u'] ?? '')); $pw = (string)($in['p'] ?? '');
    if ($u === $ADMIN_USER && $pw === $ADMIN_PASS) {
      jout(['ok' => 1, 't' => admToken(), 'role' => 'root', 'name' => $ADMIN_USER, 'store' => $MODE]);
    }
    $subs = subAll();
    if ($u !== '' && isset($subs[$u])) {
      $h = (string)($subs[$u]['pin'] ?? '');
      if ($h !== '' && hash_equals($h, subHash($pw))) {
        jout(['ok' => 1, 't' => subToken($u), 'role' => 'sub', 'name' => $u,
          'bound' => (string)($subs[$u]['bound'] ?? ''), 'store' => $MODE]);
      }
    }
    jout(['ok' => 0, 'err' => 'bad_credentials']);
  }

  /* ---------- 用户登录换令牌（PIN 校验搬到服务端） ----------
     客户端只提交 sha256(PIN)，服务端用 PBKDF2（或旧摘要）比对；
     成功返回 utk 用户令牌 + 存档，之后 get / push 都凭令牌，不再需要每次带 PIN。 */
  case 'userauth': {
    $k   = (string)($in['k'] ?? '');
    $pin = trim((string)($in['pin'] ?? ''));
    if ($k === '' || $pin === '') jout(['ok' => 0, 'err' => 'bad_params']);
    if (strlen($pin) !== 64 || !ctype_xdigit($pin)) {
      jout(['ok' => 0, 'err' => 'pin_format', 'msg' => 'PIN 需传 64 位 sha256 摘要']);
    }
    $wait = rlLocked($k);
    if ($wait > 0) {
      auditLog('userauth locked k=' . $k . ' wait=' . $wait);
      jout(['ok' => 0, 'err' => 'locked', 'wait' => $wait,
        'msg' => '尝试次数过多，请 ' . $wait . ' 秒后再试']);
    }
    if (isset(tombAll()[$k])) jout(['ok' => 0, 'err' => 'gone']);
    $row = pcGet($k);
    if (!$row) jout(['ok' => 0, 'err' => 'no_user']);
    $r = pinVerifyRow($row, $pin);
    if ($r === 0) {
      $f = rlFail($k);
      auditLog('userauth fail k=' . $k . ' left=' . $f['left']);
      jout(['ok' => 0, 'err' => 'bad_pin', 'left' => $f['left'], 'wait' => $f['locked']]);
    }
    rlClear($k);
    pinUpgrade($k, $pin);   // 旧无盐摘要 → PBKDF2，用户无感
    $st = json_decode((string)$row['st'], true);
    jout(['ok' => 1, 't' => userToken($k),
      'rec' => ['k' => $row['k'], 'p' => $row['p'], 'a' => $row['a'], 'name' => $row['name'],
        'avatar' => $row['avatar'], 'pin' => $row['pin'], 'ver' => (int)$row['ver'],
        'upd' => (int)$row['upd'], 'st' => is_array($st) ? $st : null]]);
  }

  /* ---------- 修改主管理员账密（仅主管理员本人；改完旧令牌失效，需用新账密重登） ---------- */
  case 'rootset': {
    if (!$SCOPE || $SCOPE['role'] !== 'root') jout(['ok' => 0, 'err' => 'auth', 'msg' => '仅主管理员可修改']);
    $nu = trim((string)($in['u'] ?? ''));
    $np = (string)($in['p'] ?? '');
    if ($nu === '' && $np === '') jout(['ok' => 0, 'err' => 'nothing', 'msg' => '没有需要保存的改动']);
    $curU = $ADMIN_USER;
    $curP = $ADMIN_PASS;
    if ($nu !== '') {
      if (!preg_match('/^[A-Za-z0-9_.-]{3,20}$/', $nu)) {
        jout(['ok' => 0, 'err' => 'bad_name', 'msg' => '主管理员账号需 3–20 位字母 / 数字 / 下划线']);
      }
      if (isset(subAll()[$nu])) jout(['ok' => 0, 'err' => 'name_taken', 'msg' => '该名称已被二级管理员占用']);
      $curU = $nu;
    }
    if ($np !== '') {
      if (strlen($np) < 6) jout(['ok' => 0, 'err' => 'bad_pass', 'msg' => '密码至少 6 位']);
      $curP = $np;
    }
    if (!rootSave($curU, $curP)) jout(['ok' => 0, 'err' => 'write_fail', 'msg' => '写入 data/root.json 失败，请检查目录权限']);
    jout(['ok' => 1, 'user' => $curU, 'reload' => 1, 'msg' => '主管理员账密已更新，请用新账密重新登录']);
  }

  /* ---------- 二级管理员注册（无需登录，必须绑定一个个人账户） ---------- */
  case 'subreg': {
    $name  = trim((string)($in['name'] ?? ''));
    $pass  = (string)($in['pass'] ?? '');
    $k     = trim((string)($in['k'] ?? ''));
    $akpin = (string)($in['pin'] ?? '');
    if ($name === '' || mb_strlen($name, 'UTF-8') < 3 || mb_strlen($name, 'UTF-8') > 20) {
      jout(['ok' => 0, 'err' => 'bad_name', 'msg' => '管理员名需 3–20 个字符']);
    }
    if ($pass === '' || strlen($pass) < 6) jout(['ok' => 0, 'err' => 'bad_pass', 'msg' => '密码至少 6 位']);
    $row = pcGet($k);
    if (!$row && $k !== '') {
      /* 也允许填「账号名」或「站内 UID」，服务端自己解析成存档 key */
      $needle = strtolower($k);
      foreach (pcList() as $r0) {
        $st0 = stOf($r0);
        if ((string)($st0['pcuid'] ?? '') === $k || strtolower((string)$r0['a']) === $needle) { $row = $r0; break; }
      }
    }
    if (!$row) jout(['ok' => 0, 'err' => 'no_user', 'msg' => '绑定账户不存在（可填账号名、账号 key 或站内 UID）']);
    $k = (string)$row['k'];
    $pinPrev = trim((string)($row['pin'] ?? ''));
    if ($pinPrev === '' || !hash_equals($pinPrev, $akpin)) {
      jout(['ok' => 0, 'err' => 'bad_pin', 'msg' => '绑定账户 PIN 不正确']);
    }
    $subs = subAll();
    if (isset($subs[$name]) || $name === $ADMIN_USER) jout(['ok' => 0, 'err' => 'name_taken', 'msg' => '该管理员名已被占用']);
    if (boundTaken($k)) jout(['ok' => 0, 'err' => 'bound_taken', 'msg' => '该账户已被其他二级管理员绑定']);
    $subs[$name] = ['pin' => subHash($pass), 'bound' => $k, 'ts' => now_ms(), 'by' => 'self'];
    subSave($subs);
    jout(['ok' => 1, 't' => subToken($name), 'role' => 'sub', 'name' => $name, 'bound' => $k]);
  }

  /* ---------- 管理员编辑：用户账户（昵称 / 账号 / 登录 PIN）与二级管理员 ---------- */
  case 'adminedit': {
    if (!$SCOPE) jout(['ok' => 0, 'err' => 'auth']);
    $target = (string)($in['target'] ?? 'user');

    if ($target === 'sub') {
      if ($SCOPE['role'] !== 'root') jout(['ok' => 0, 'err' => 'auth', 'msg' => '仅主管理员可管理二级管理员']);
      $sname = trim((string)($in['sub'] ?? ''));
      $subs = subAll();
      if ($sname === '' || !isset($subs[$sname])) jout(['ok' => 0, 'err' => 'no_sub']);
      $one = $subs[$sname];
      $newName = trim((string)($in['name'] ?? ''));
      $newPass = (string)($in['pass'] ?? '');
      $newBound = trim((string)($in['bound'] ?? ''));
      if ($newBound !== '' && $newBound !== (string)($one['bound'] ?? '')) {
        if (!pcGet($newBound)) jout(['ok' => 0, 'err' => 'no_user', 'msg' => '绑定账户不存在']);
        if (boundTaken($newBound, $sname)) jout(['ok' => 0, 'err' => 'bound_taken']);
        $one['bound'] = $newBound;
      }
      if ($newPass !== '') {
        if (strlen($newPass) < 6) jout(['ok' => 0, 'err' => 'bad_pass']);
        $one['pin'] = subHash($newPass);
      }
      $one['ts'] = now_ms();
      if ($newName !== '' && $newName !== $sname) {
        if (isset($subs[$newName]) || $newName === $ADMIN_USER) jout(['ok' => 0, 'err' => 'name_taken']);
        unset($subs[$sname]);
        $subs[$newName] = $one;
        subSave($subs);
        jout(['ok' => 1, 'sub' => $newName, 'renamed' => 1, 'msg' => '管理员名已更新，需重新登录']);
      }
      $subs[$sname] = $one;
      subSave($subs);
      jout(['ok' => 1, 'sub' => $sname]);
    }

    $k = (string)($in['k'] ?? '');
    $row = pcGet($k);
    if (!$row) jout(['ok' => 0, 'err' => 'no_user']);
    if (!scopeCan($SCOPE, $k)) jout(['ok' => 0, 'err' => 'auth', 'msg' => '越权：只能修改绑定账户']);
    $st = stOf($row);
    $newName = trim((string)($in['name'] ?? ''));
    $newAcct = trim((string)($in['acct'] ?? ''));
    $newPin  = trim((string)($in['pin'] ?? ''));
    $changed = [];
    if ($newName !== '' && $newName !== (string)($st['user'] ?? '') && nickTaken($newName, $k)) {
      jout(['ok' => 0, 'err' => 'name_taken', 'msg' => '该昵称已被其他账户占用', 'rand' => genNickUnique($k)]);
    }
    if ($newName !== '' && $newName !== (string)($st['user'] ?? '')) {
      $st['user'] = $newName;
      $row['name'] = $newName;
      $changed[] = 'user';
    }
    if ($newPin !== '' && strlen($newPin) < 32) {
      // 前端只应传 64 位 sha256 摘要；短串（如明文 "123456"）以前会被静默忽略并返回 ok:1，调用方误以为改成功
      jout(['ok' => 0, 'err' => 'pin_format', 'msg' => 'PIN 需传 64 位 sha256 摘要（收到 ' . strlen($newPin) . ' 位）']);
    }
    if ($newPin !== '' && $newPin !== (string)($row['pin'] ?? '')) {
      $row['pin'] = $newPin;         // 前端传 sha256 摘要
      $st['pin'] = $newPin;          // 存档里的 pin 与行保持一致，避免登录后又被旧值覆盖
      $changed[] = 'pin';
    }
    $newKey = $k;
    if ($newAcct !== '' && $newAcct !== (string)($row['a'] ?? '')) {
      if (!preg_match('/^[A-Za-z0-9_.-]{2,64}$/', $newAcct)) jout(['ok' => 0, 'err' => 'bad_acct', 'msg' => '账号只能含字母数字下划线']);
      $newKey = ((string)($row['p'] ?? '') !== '' ? (string)$row['p'] : 'local') . ':' . strtolower($newAcct);
      if ($newKey !== $k && pcGet($newKey)) jout(['ok' => 0, 'err' => 'acct_taken', 'msg' => '该账号已被占用']);
      $row['a'] = $newAcct;
      $changed[] = 'acct';
    }
    $st['acct'] = $newKey;
    $row['st'] = json_encode($st, JSON_UNESCAPED_UNICODE);

    if ($newKey !== $k) {
      pcPut($newKey, $row, true, -1);
      tombClear($newKey);          // 改名目标若残留旧墓碑（改回来时会撞上），这里一并解除
      $verOld = (int)($row['ver'] ?? 0);
      pcDel($k);
      tombSet($k, $verOld, 'rename:' . ($SCOPE['role'] === 'root' ? 'root' : $SCOPE['name']));
      /* 二级管理员的绑定指向同步迁移 */
      $subs = subAll(); $touched = false;
      foreach ($subs as $n => $v) {
        if ((string)($v['bound'] ?? '') === $k) { $subs[$n]['bound'] = $newKey; $touched = true; }
      }
      if ($touched) subSave($subs);
      jout(['ok' => 1, 'k' => $newKey, 'oldKey' => $k, 'changed' => $changed]);
    }
    $r = saveRow($row, $st, true, -1);
    jout(['ok' => 1, 'k' => $k, 'changed' => $changed, 'ver' => $r['ver'] ?? 0]);
  }

  /* ---------- 删除二级管理员（仅主管理员） ---------- */
  case 'subdel': {
    if (!$SCOPE || $SCOPE['role'] !== 'root') jout(['ok' => 0, 'err' => 'auth']);
    $n = trim((string)($in['sub'] ?? ''));
    $subs = subAll();
    if (!isset($subs[$n])) jout(['ok' => 0, 'err' => 'no_sub']);
    unset($subs[$n]);
    subSave($subs);
    jout(['ok' => 1, 'removed' => $n]);
  }

  /* ---------- 昵称占用预检（前端保存前调用，避免直接撞名） ---------- */
  case 'nick': {
    $n = trim((string)($in['name'] ?? ''));
    $k = (string)($in['k'] ?? '');
    $t = ($n !== '' && nickTaken($n, $k));
    jout(['ok' => 1, 'taken' => $t ? 1 : 0, 'rand' => $t ? genNickUnique($k) : '']);
  }

  /* ---------- 卡号占用预检（前端保存前调用，撞号则云端下发随机可用卡号） ---------- */
  case 'cardchk': {
    $c = digitsOf((string)($in['card'] ?? ''));
    $k = (string)($in['k'] ?? '');
    $t = ($c !== '' && cardTaken($c, $k));
    jout(['ok' => 1, 'taken' => $t ? 1 : 0, 'rand' => $t ? groupDigits(genCardNoUnique($k)) : '']);
  }

  case 'get': {
    $k = (string)($in['k'] ?? $_GET['k'] ?? '');
    if ($k === '') jout(['ok' => 0, 'err' => 'no_key']);
    $t = tombAll();
    $gone = isset($t[$k]) ? 1 : 0;
    /* 只有「本人令牌」或「管理员范围」才能拿整条存档；
       匿名只能查到「存在 / 已删 / 有没有设 PIN / 昵称」——
       整改前这里会把含 PIN 摘要的整条 rec 吐给任何人，等于公开了密码。 */
    $us = userScopeOf($token);
    $owner = ($us && $us['k'] === $k) || ($SCOPE && scopeCan($SCOPE, $k));
    $r = pcGet($k);
    if ($owner) {
      if ($r) jout(['ok' => 1, 'found' => 1, 'rec' => $r]);
      jout(['ok' => 1, 'found' => 0, 'gone' => $gone]);
    }
    jout(['ok' => 1, 'found' => $r ? 1 : 0, 'gone' => $gone,
      'hasPin' => ($r && trim((string)$r['pin']) !== '') ? 1 : 0,
      'name' => $r ? (string)$r['name'] : '']);
  }

  /* ---------- 忘记密码：服务端同步重置（清空该账号存档并重新开户） ----------
     前端不能只清 localStorage：那样云端还留着旧余额 / 旧 PIN，两边永久对不上。
     此动作不带鉴权（等同用户自己销毁自己的数据），会解除同名账号的删除墓碑。 */
  case 'reset': {
    $k = (string)($in['k'] ?? '');
    if ($k === '' || strlen($k) > 128) jout(['ok' => 0, 'err' => 'bad_params']);
    /* 鉴权（B5）：reset 会清余额、改昵称、清 PIN 并解除墓碑，等同销毁账户。
       以前不带任何凭据即可调用，只要知道账号 key（provider:account）就能接管他人数据。
       现在必须满足其一：① 带与云端一致的 PIN 摘要（本人自助重置）；
       ② 主管理员令牌执行（后台重置）；③ 该账户尚未设置 PIN（无数据可保护）。 */
    $rowReset = pcGet($k);
    if (!($SCOPE && ((string)($SCOPE['role'] ?? '') === 'root'))) {
      if (!$rowReset) jout(['ok' => 0, 'err' => 'no_user']);
      $pinPrevR = trim((string)($rowReset['pin'] ?? ''));
      if ($pinPrevR !== '') {
        $pinInR = trim((string)($in['pin'] ?? ''));
        if ($pinInR === '' || !hash_equals($pinPrevR, $pinInR)) {
          jout(['ok' => 0, 'err' => 'auth', 'msg' => '重置需要提供当前登录 PIN，或由主管理员在后台执行']);
        }
      }
    }
    $name   = substr((string)($in['name'] ?? ''), 0, 64);
    $avatar = (string)($in['avatar'] ?? '');
    $p      = substr((string)($in['p'] ?? ''), 0, 16);
    $a      = substr((string)($in['a'] ?? ''), 0, 64);
    $st = [
      'user' => $name, 'avatar' => $avatar, 'onboarded' => false,
      'cardOpened' => false, 'cardState' => 'inactive',
      'fons' => 0, 'ring' => 0, 'tx' => [], 'notifs' => [], 'receipts' => [],
      'pin' => '', 'acct' => $k, 'lang' => 'zh',
    ];
    $oldRow = pcGet($k);
    if ($oldRow) {
      $oldSt = stOf($oldRow);
      if (!empty($oldSt['pcuid'])) freezeUid((string)$oldSt['pcuid'], $k);   // 销号 → 站内 UID 冻结
    }
    tombClear($k);
    $r = pcPut($k, [
      'p' => $p, 'a' => $a, 'name' => $name, 'avatar' => $avatar, 'pin' => '',
      'st' => json_encode($st, JSON_UNESCAPED_UNICODE),
    ], true, 0);
    jout(['ok' => 1, 'ver' => (int)($r['ver'] ?? 0)]);
  }

  case 'push': {
    $k  = (string)($in['k'] ?? '');
    $st = (string)($in['st'] ?? '');
    if ($k === '' || $st === '') jout(['ok' => 0, 'err' => 'no_key_or_st']);
    if (strlen($st) > 400000) jout(['ok' => 0, 'err' => 'too_large']);
    /* ---------- 写入鉴权（本次整改的核心） ----------
       放行条件三选一：
       ① 用户令牌 utk 且令牌里的 key 与请求一致；
       ② 管理员令牌且该 key 在授权范围内（后台改余额走这里）；
       ③ 提交与云端一致的 PIN 摘要（兼容旧客户端 / 新号首次建档，此时库里还没有记录，
          必须带 64 位摘要，避免「随便一个 key 就能凭空造号」被滥用）。
       否则一律 err:auth —— 以前这里是完全裸奔的，谁都能覆盖别人的存档。 */
    $prevRow0 = pcGet($k);
    $us = userScopeOf($token);
    $okUser  = ($us && $us['k'] === $k);
    $okAdmin = ($SCOPE && scopeCan($SCOPE, $k));
    $pinIn0  = trim((string)($in['pin'] ?? ''));
    $okPin   = false;
    if ($prevRow0) {
      $pinPrev0 = trim((string)($prevRow0['pin'] ?? ''));
      if ($pinPrev0 === '') {
        /* 云端这条还没有 PIN（忘记密码重置后 / 老库遗留）：允许带合法摘要的客户端首次设 PIN */
        $okPin = (strlen($pinIn0) === 64 && ctype_xdigit($pinIn0));
      } else {
        $okPin = ($pinIn0 !== '' && hash_equals($pinPrev0, $pinIn0));
      }
    } else {
      $okPin = (strlen($pinIn0) === 64 && ctype_xdigit($pinIn0));
    }
    if (!$okUser && !$okAdmin && !$okPin) {
      auditLog('push auth reject k=' . $k);
      jout(['ok' => 0, 'err' => 'auth', 'msg' => '写存档需要登录令牌，或与云端一致的 PIN 摘要']);
    }
    /* 新建账号的节流：不带令牌的建档每小时最多 200 个，防止被刷垃圾账号 */
    if (!$okUser && !$okAdmin && !$prevRow0) {
      $ck = '!create:' . date('YmdH');
      $cs = rlState($ck);
      if ($cs['n'] >= 200) {
        auditLog('push create throttle');
        jout(['ok' => 0, 'err' => 'too_many', 'msg' => '建档过于频繁，请稍后再试']);
      }
      $ca = rlAll(); $ca[$ck] = ['n' => $cs['n'] + 1, 'until' => 0]; rlSave($ca);
    }
    /* 服务端权威字段保护：客户端存档里没有 lim / cardState 时，沿用库里的值，
       否则一次前台同步就会把「今日/本年已用额度」清零，限额形同失效 */
    $prevRow = $prevRow0;
    if ($prevRow) {
      $stIn = json_decode($st, true);
      $stPrev = json_decode((string)$prevRow['st'], true);
      if (is_array($stIn) && is_array($stPrev)) {
        foreach (['lim', 'cardState'] as $keep) {
          if (isset($stPrev[$keep]) && !isset($stIn[$keep])) $stIn[$keep] = $stPrev[$keep];
        }
        /* 一致性兜底（B2）：旧版本开卡只写了 cardOpened，cardState 仍停在 inactive，
           结果前台显示「已激活」、后台列表显示「卡未激活」。规则统一为：
           cardOpened=true ⇒ cardState ∈ {active, frozen}，遇到 inactive 就地纠正。 */
        if (!empty($stIn['cardOpened'])
            && (!isset($stIn['cardState']) || (string)$stIn['cardState'] === 'inactive')) {
          $stIn['cardState'] = 'active';
        }
        $st = json_encode($stIn, JSON_UNESCAPED_UNICODE);
      }
    }
    /* 只有管理员显式声明 force 才允许强制写入；否则哪怕请求带了管理员令牌，
       普通同步也不能越过 PIN 校验（前端同步请求会自动携带后台令牌） */
    /* 全站唯一 UID：新账号入库时由服务端分配；已有 UID 不允许被客户端改掉 */
    $stDec = json_decode($st, true);
    if (is_array($stDec)) {
      $prevSt = $prevRow ? json_decode((string)($prevRow['st'] ?? ''), true) : null;
      if (is_array($prevSt) && !empty($prevSt['pcuid'])) $stDec['pcuid'] = (string)$prevSt['pcuid'];
      if (empty($stDec['pcuid'])) $stDec['pcuid'] = newUid();
      $st = json_encode($stDec, JSON_UNESCAPED_UNICODE);
    }
    /* 强制写入：主管理员可任意；二级管理员仅限自己绑定的那个账户（后台改余额要用）
       （前台同步不会带 force，所以二级管理员令牌不会让普通同步越过 PIN 校验） */
    $force = !empty($in['force']) && $SCOPE
      && ($SCOPE['role'] === 'root' || ($SCOPE['role'] === 'sub' && $k === (string)($SCOPE['bound'] ?? '')));
    $tomb = tombAll();
    if (!$force && isset($tomb[$k])) jout(['ok' => 0, 'err' => 'gone', 'k' => $k]);
    /* 服务端权威：已建档账号的写入必须带与云端一致的 PIN 摘要，
       否则本机残留 / 脏存档会把服务端数据盖掉（改 PIN 请走 reset，后台走管理员强制写入） */
    if ($prevRow && !$force && !$okUser && !$okAdmin) {
      $pinPrev = trim((string)($prevRow['pin'] ?? ''));
      $pinIn   = (string)($in['pin'] ?? '');
      if ($pinPrev !== '' && $pinIn !== $pinPrev) {
        $stPrev = json_decode((string)($prevRow['st'] ?? ''), true);
        jout(['ok' => 0, 'err' => 'pin_mismatch', 'k' => $k,
          'msg' => '本机 PIN 摘要与云端不一致，服务端拒绝覆盖（以云端为准）',
          'rec' => ['k' => $prevRow['k'], 'p' => $prevRow['p'], 'a' => $prevRow['a'],
            'name' => $prevRow['name'], 'avatar' => $prevRow['avatar'], 'pin' => $prevRow['pin'],
            'ver' => (int)$prevRow['ver'], 'upd' => (int)$prevRow['upd'],
            'st' => is_array($stPrev) ? $stPrev : null]]);
      }
    }
    /* 昵称 / 卡号全站唯一：撞了不拒绝整份存档（否则余额/回单会丢），
       由云端随机下发一个可用值写回存档，并在站内通知里告诉用户改了什么 */
    $nameIn = substr((string)($in['name'] ?? ''), 0, 64);
    $nameFix = '';
    $cardFix = '';
    if ($nameIn !== '' && nickTaken($nameIn, $k)) {
      $nameFix = genNickUnique($k);
      if (is_array($stDec)) {
        $stDec['user'] = $nameFix;
        pushNotif($stDec, 'sys', '昵称已重新分配',
          '您设置的昵称「' . $nameIn . '」已被其他账户占用。为保证全站昵称唯一，系统已随机分配新昵称「' . $nameFix . '」，可在个人资料中另行修改。');
        $st = json_encode($stDec, JSON_UNESCAPED_UNICODE);
      }
    }
    if (is_array($stDec) && !empty($stDec['card']['no']) && cardTaken((string)$stDec['card']['no'], $k)) {
      $oldNo = (string)$stDec['card']['no'];
      $newNo = groupDigits(genCardNoUnique($k));
      $stDec['card']['no'] = $newNo;
      $stDec['card']['cvc'] = cvcOf($newNo);
      pushNotif($stDec, 'card', '卡号已重新分配',
        '您填写的卡号 ' . $oldNo . ' 已被其他账户使用。系统已随机分配新卡号 ' . $newNo . '（CVC 已同步更新），原卡号未生效。');
      $st = json_encode($stDec, JSON_UNESCAPED_UNICODE);
      $cardFix = $newNo;
    }
    $base  = isset($in['ver']) ? (int)$in['ver'] : -1;
    $r = pcPut($k, [
      'p' => substr((string)($in['p'] ?? ''), 0, 16),
      'a' => substr((string)($in['a'] ?? ''), 0, 64),
      'name' => ($nameFix !== '' ? $nameFix : $nameIn),
      'avatar' => (string)($in['avatar'] ?? ''),
      'pin' => substr((string)($in['pin'] ?? ''), 0, 128),
      'st' => $st,
    ], $force, $base);
    if (isset($stDec['pcuid'])) $r['pcuid'] = (string)$stDec['pcuid'];
    /* 这次是凭 PIN 摘要通过的，顺手发一个用户令牌：客户端之后走令牌，不必每次上送 PIN */
    if (!$okUser && !$okAdmin && $okPin) $r['t'] = userToken($k);
    if ($nameFix !== '' && $nameFix !== $nameIn) { $r['nameFix'] = $nameFix; $r['err'] = 'name_taken'; }
    if ($cardFix !== '') { $r['cardFix'] = $cardFix; $r['err'] = 'card_taken'; }
    jout($r);
  }

  case 'list': {
    if (!$SCOPE) jout(['ok' => 0, 'err' => 'auth']);
    $users = [];
    $boundNow = ($SCOPE['role'] === 'sub') ? (string)($SCOPE['bound'] ?? '') : '';
    foreach (pcList() as $r) {
      if ($boundNow !== '' && $r['k'] !== $boundNow) continue;
      $st = stOf($r);
      $tier = tierOf($st);
      $lim = limOf($st);
      $users[] = [
        'k' => $r['k'], 'p' => $r['p'], 'a' => $r['a'], 'name' => $r['name'], 'avatar' => $r['avatar'],
        'pin' => $r['pin'], 'ver' => (int)$r['ver'], 'upd' => (int)$r['upd'],
        'fons' => (float)($st['fons'] ?? 0), 'ring' => (float)($st['ring'] ?? 0),
        'uid' => (string)($st['uid'] ?? ''), 'pcuid' => (string)($st['pcuid'] ?? ''),
        'onboarded' => !empty($st['onboarded']),
        'cardOpened' => !empty($st['cardOpened']), 'tier' => $tier,
        'cardState' => (string)($st['cardState'] ?? (!empty($st['cardOpened']) ? 'active' : 'inactive')),
        'lim' => $lim,
        'cardNo' => (string)($st['card']['no'] ?? ''), 'cardDate' => (string)($st['card']['date'] ?? ''),
        'notif' => is_array($st['notifs'] ?? null) ? count($st['notifs']) : 0,
        'tx' => is_array($st['tx'] ?? null) ? count($st['tx']) : 0,
        'rcp' => is_array($st['receipts'] ?? null) ? count($st['receipts']) : 0,
        'st' => $st,
        'role' => 'user',
        'subAdmin' => boundOwnerOf($r['k']),
      ];
    }
    $out = ['ok' => 1, 'store' => $MODE, 'users' => $users, 'gone' => array_keys(tombAll()),
      'role' => $SCOPE['role']];
    if ($SCOPE['role'] === 'sub') {
      $out['me'] = ['name' => $SCOPE['name'], 'bound' => $boundNow];
    } else {
      $admins = [];
      foreach (subAll() as $n => $v) {
        $bk = (string)($v['bound'] ?? '');
        $br = $bk !== '' ? pcGet($bk) : null;
        $bst = $br ? stOf($br) : [];
        $admins[] = ['name' => $n, 'bound' => $bk, 'boundName' => (string)($bst['user'] ?? ($br['name'] ?? '')),
          'boundUid' => (string)($bst['pcuid'] ?? ''), 'boundExists' => $br ? 1 : 0, 'ts' => (int)($v['ts'] ?? 0)];
      }
      $out['admins'] = $admins;
      $out['root'] = $ADMIN_USER;
    }
    jout($out);
  }

  /* ---------- 汇率（公开，按日缓存真实 USD/CNY） ---------- */
  case 'rates': {
    $q = ratesQuote();
    jout(['ok' => 1, 'mid' => $q['mid'], 'buy' => $q['buy'], 'sell' => $q['sell'], 'cash' => $q['cash'],
      'date' => $q['date'], 'src' => $q['src'], 'base' => '1 HHS = ? HFS',
      'policy' => ['name' => '中行式牌价', 'note' => '现汇买入价用于结汇（环石→方斯），现汇卖出价用于购汇（方斯→环石），价差 0.35%']]);
  }

  /* ---------- 限额查询 ---------- */
  case 'limit': {
    global $LIMITS;
    $k = (string)($in['k'] ?? '');
    $r = pcGet($k);
    if (!$r) jout(['ok' => 0, 'err' => 'no_user']);
    $st = stOf($r); $tier = tierOf($st); $lim = limOf($st);
    $rule = $LIMITS[$tier];
    jout(['ok' => 1, 'tier' => $tier, 'tierName' => $rule['name'], 'single' => $rule['single'],
      'day' => $rule['day'], 'year' => $rule['year'], 'cnt' => $rule['cnt'],
      'used' => ['day' => $lim['dAmt'], 'year' => $lim['yAmt'], 'cnt' => $lim['dCnt'], 'month' => $lim['mAmt']],
      'note' => $rule['note'], 'cardOpened' => !empty($st['cardOpened'])]);
  }

  /* ---------- 卡号 + 户名：查询收款账户 ---------- */
  case 'findcard': {
    $card = (string)($in['card'] ?? '');
    $name = trim((string)($in['name'] ?? ''));
    $d = digitsOf($card);
    if (strlen($d) < 12) jout(['ok' => 0, 'err' => 'bad_card', 'msg' => '卡号位数不足']);
    if (!luhnOk($d)) jout(['ok' => 0, 'err' => 'bad_luhn', 'msg' => '卡号校验位不正确']);
    $hit = findByCard($d, $name);
    if (!$hit) jout(['ok' => 1, 'found' => 0, 'err' => 'not_found',
      'msg' => $name === '' ? '未找到该卡号账户' : '卡号与户名不匹配']);
    $st = $hit['st'];
    jout(['ok' => 1, 'found' => 1, 'cardMasked' => maskCard($d),
      'nameMasked' => maskName((string)($st['user'] ?? $hit['row']['name'])),
      'nameMatch' => $name !== '', 'tier' => tierOf($st),
      'bank' => '粉爪银行 Haitecard', 'cur' => ['HFS', 'HHS']]);
  }

  /* ---------- 卡号 + 户名转账（站内实时入账） ---------- */
  case 'p2p': {
    $k    = (string)($in['k'] ?? '');
    $pin  = (string)($in['pin'] ?? '');
    $card = (string)($in['card'] ?? '');
    $name = trim((string)($in['name'] ?? ''));
    $cur  = ((string)($in['cur'] ?? 'HFS')) === 'HHS' ? 'HHS' : 'HFS';
    $amt  = round((float)($in['amt'] ?? 0), 2);
    $memo = mb_substr((string)($in['memo'] ?? ''), 0, 60, 'UTF-8');
    if ($k === '' || $amt <= 0) jout(['ok' => 0, 'err' => 'bad_params']);
    $from = pcGet($k);
    if (!$from) jout(['ok' => 0, 'err' => 'no_user']);
    if ($pin === '' || !hash_equals((string)$from['pin'], $pin)) jout(['ok' => 0, 'err' => 'bad_pin', 'msg' => '支付密码不正确']);
    $d = digitsOf($card);
    if (strlen($d) < 12 || !luhnOk($d)) jout(['ok' => 0, 'err' => 'bad_card', 'msg' => '收款卡号无效']);
    $hit = findByCard($d, $name);
    if (!$hit) jout(['ok' => 0, 'err' => 'not_found', 'msg' => '卡号与户名不匹配']);
    if ($hit['row']['k'] === $k) jout(['ok' => 0, 'err' => 'same_account', 'msg' => '不能向本账户转账']);

    $fs = stOf($from); $ts = $hit['st'];
    $fKey = ($cur === 'HHS') ? 'ring' : 'fons';
    $b0 = round((float)($fs[$fKey] ?? 0), 2);
    if ($amt > $b0 + 1e-6) jout(['ok' => 0, 'err' => 'insufficient', 'msg' => '余额不足，可用 ' . number_format($b0, 2), 'b0' => $b0]);
    if (!empty($fs['frozen']) && (int)($fs['freezeUntil'] ?? 0) > now_ms()) jout(['ok' => 0, 'err' => 'frozen', 'msg' => '账户风控冻结中']);
    if (!empty($fs['risk']['blocked'])) jout(['ok' => 0, 'err' => 'risk', 'msg' => '账户已被风控限制']);
    /* Ⅱ类账户转账需已开通卡片（卡未激活时对外转账按Ⅱ类限额；此处限额统一校验） */
    $bad = limCheck($fs, $amt, 'out');
    if ($bad !== null) jout(['ok' => 0, 'err' => 'limit', 'msg' => $bad, 'tier' => tierOf($fs)]);

    $tb0 = round((float)($ts[$fKey] ?? 0), 2);
    $b1 = round($b0 - $amt, 2);
    $tb1 = round($tb0 + $amt, 2);
    $fs[$fKey] = $b1; $ts[$fKey] = $tb1;
    limAdd($fs, $amt);

    $no = rcpNoSrv();
    $toName = (string)($ts['user'] ?? $hit['row']['name']);
    $fromName = (string)($fs['user'] ?? $from['name']);
    $peerOut = $toName . ' · ' . maskCard($d);
    $fromCard = digitsOf((string)($fs['card']['no'] ?? ''));
    $peerIn  = $fromName . ' · ' . ($fromCard !== '' ? maskCard($fromCard) : '账号 ' . $from['a']);
    $sealOut = sealOf($no, '转账支出', $amt, $b0, $b1, $k);
    $sealIn  = sealOf($no . 'I', '转账收入', $amt, $tb0, $tb1, $hit['row']['k']);
    $rcpOut = ['no' => $no, 't' => now_ms(), 'ty' => '卡号转账 · 转出', 'dir' => -1, 'amt' => $amt, 'cur' => $cur,
      'chan' => 'mb', 'dev' => 'P2P-' . substr($no, -6), 'peer' => mb_substr($peerOut, 0, 40),
      'memo' => $memo !== '' ? $memo : '按卡号与户名转账', 'op' => mb_substr($fromName, 0, 24),
      'b0' => $b0, 'b1' => $b1, 'seal' => $sealOut, 'tier' => tierOf($fs)] + ['limitAfter' => limOf($fs)['dAmt']];
    $rcpIn = ['no' => $no . 'I', 't' => now_ms(), 'ty' => '卡号转账 · 转入', 'dir' => 1, 'amt' => $amt, 'cur' => $cur,
      'chan' => 'mb', 'dev' => 'P2P-' . substr($no, -6), 'peer' => mb_substr($peerIn, 0, 40),
      'memo' => $memo !== '' ? $memo : '他行/他户按卡号转入', 'op' => mb_substr($fromName, 0, 24),
      'b0' => $tb0, 'b1' => $tb1, 'seal' => $sealIn, 'tier' => tierOf($ts)];

    pushRcp($fs, $rcpOut); pushRcp($ts, $rcpIn);
    pushTx($fs, 'out', $amt, $cur, '卡号转账 · ' . $toName, $no);
    pushTx($ts, 'in', $amt, $cur, '卡号转入 · ' . $fromName, $no . 'I');
    $sign = '-' . fnum($amt);
    pushNotif($fs, 'tx', '电子回单 · 转账支出',
      $sign . ' ' . $cur . ' → ' . $toName . '，余额 ' . number_format($b1, 2) . ' ' . $cur . '，回单 ' . $no, $no);
    pushNotif($ts, 'tx', '电子回单 · 转账收入',
      '+' . fnum($amt) . ' ' . $cur . ' ← ' . $fromName . '，余额 ' . number_format($tb1, 2) . ' ' . $cur . '，回单 ' . ($no . 'I'), $no . 'I');

    saveRow($from, $fs, true, -1);
    saveRow($hit['row'], $ts, true, -1);
    jout(['ok' => 1, 'rcp' => $rcpOut, 'rcpIn' => $rcpIn, 'st' => $fs, 'to' => ['name' => $toName, 'masked' => maskCard($d)],
      'tier' => tierOf($fs), 'lim' => limOf($fs)]);
  }

  /* ---------- 购汇 / 结汇（方斯 ↔ 环石，中行式买卖价） ---------- */
  case 'conv': {
    $k    = (string)($in['k'] ?? '');
    $pin  = (string)($in['pin'] ?? '');
    $side = ((string)($in['side'] ?? 'buy')) === 'sell' ? 'sell' : 'buy';  // buy=购汇(方斯买环石) sell=结汇(环石卖方斯)
    $amt  = round((float)($in['amt'] ?? 0), 4);
    if ($k === '' || $amt <= 0) jout(['ok' => 0, 'err' => 'bad_params']);
    $row = pcGet($k);
    if (!$row) jout(['ok' => 0, 'err' => 'no_user']);
    if ($pin === '' || !hash_equals((string)$row['pin'], $pin)) jout(['ok' => 0, 'err' => 'bad_pin', 'msg' => '支付密码不正确']);
    $st = stOf($row);
    if (!empty($st['frozen']) && (int)($st['freezeUntil'] ?? 0) > now_ms()) jout(['ok' => 0, 'err' => 'frozen']);
    if ($side === 'buy' && tierOf($st) === 2) jout(['ok' => 0, 'err' => 'tier', 'msg' => 'Ⅱ类账户不可购汇，请先激活 Haitecard 升级为Ⅰ类']);

    $q = ratesQuote();
    $rate = $side === 'buy' ? $q['sell'] : $q['buy'];
    $fons0 = round((float)($st['fons'] ?? 0), 2);
    $ring0 = round((float)($st['ring'] ?? 0), 2);
    if ($side === 'buy') {
      $cost = round($amt * $rate, 2);          // 支付方斯
      if ($cost > $fons0 + 1e-6) jout(['ok' => 0, 'err' => 'insufficient', 'msg' => '方斯余额不足，需 ' . number_format($cost, 2)]);
      $bad = limCheck($st, $cost, 'out');
      if ($bad !== null) jout(['ok' => 0, 'err' => 'limit', 'msg' => $bad]);
      $fons1 = round($fons0 - $cost, 2); $ring1 = round($ring0 + $amt, 2);
      limAdd($st, $cost);
      $ty = '购汇 · 方斯买入环石';
      $b0 = $fons0; $b1 = $fons1;
    } else {
      if ($amt > $ring0 + 1e-6) jout(['ok' => 0, 'err' => 'insufficient', 'msg' => '环石余额不足']);
      $gain = round($amt * $rate, 2);          // 得到方斯
      $fons1 = round($fons0 + $gain, 2); $ring1 = round($ring0 - $amt, 2);
      $ty = '结汇 · 环石卖出得方斯';
      $b0 = $ring0; $b1 = $ring1;
    }
    $st['fons'] = $fons1; $st['ring'] = $ring1;

    $no = rcpNoSrv();
    $seal = sealOf($no, $ty, $amt, $b0, $b1, $k);
    $rcp = ['no' => $no, 't' => now_ms(), 'ty' => $ty, 'dir' => -1,
      'amt' => ($side === 'buy' ? round($amt * $rate, 2) : $amt), 'cur' => ($side === 'buy' ? 'HFS' : 'HHS'),
      'chan' => 'eb', 'dev' => 'FX-' . substr($no, -6),
      'peer' => '粉爪银行外汇牌价 · 1 HHS = ' . $rate . ' HFS', 'memo' => '牌价日 ' . $q['date'] . ' · ' . $q['src'],
      'op' => '系统自动 · 结售汇', 'b0' => $b0, 'b1' => $b1, 'seal' => $seal, 'rate' => $rate,
      'outAmt' => ($side === 'buy' ? round($amt * $rate, 2) : $amt),
      'inAmt' => ($side === 'buy' ? $amt : round($amt * $rate, 2))];
    pushRcp($st, $rcp);
    pushTx($st, 'out', $rcp['outAmt'], ($side === 'buy' ? 'HFS' : 'HHS'), $ty . ' · 卖出', $no);
    pushTx($st, 'in', $rcp['inAmt'], ($side === 'buy' ? 'HHS' : 'HFS'), $ty . ' · 买入', $no);
    pushNotif($st, 'tx', '电子回单 · ' . $ty,
      ($side === 'buy' ? '-' . fnum($rcp['outAmt']) . ' HFS' : '-' . fnum($rcp['outAmt']) . ' HHS') .
      '，成交价 1 HHS = ' . $rate . ' HFS，余额 ' . number_format($fons1, 2) . ' HFS / ' . number_format($ring1, 2) . ' HHS，回单 ' . $no, $no);

    saveRow($row, $st, true, -1);
    jout(['ok' => 1, 'rcp' => $rcp, 'st' => $st, 'rate' => $rate, 'quote' => $q]);
  }

  /* ---------- 后台：卡片激活状态 / 账户等级 ---------- */
  case 'card': {
    $k = (string)($in['k'] ?? '');
    if (!scopeCan($SCOPE, $k)) jout(['ok' => 0, 'err' => 'auth', 'msg' => '无权操作该账户']);
    $row = pcGet($k);
    if (!$row) jout(['ok' => 0, 'err' => 'no_user']);
    $st = stOf($row);
    $state = (string)($in['state'] ?? '');        // active / frozen / inactive
    if (!in_array($state, ['active', 'frozen', 'inactive'], true)) jout(['ok' => 0, 'err' => 'bad_state']);
    $reason = mb_substr((string)($in['reason'] ?? ''), 0, 40, 'UTF-8');
    $st['card'] = is_array($st['card'] ?? null) ? $st['card'] : [];
    if ($state === 'active' && (string)($st['card']['no'] ?? '') === '') {
      $no = genCardNo();
      $st['card']['no'] = $no;
      $st['card']['date'] = date('m/y', strtotime('+5 years'));
      $st['card']['cvc'] = cvcOf($no);
      $st['card']['issued'] = date('Y-m-d');
    }
    $noNow = (string)($st['card']['no'] ?? '');
    if ($noNow !== '') $st['card']['cvc'] = cvcOf($noNow);
    $st['cardState'] = $state;
    $st['cardOpened'] = ($state !== 'inactive');
    if ($state !== 'active') $st['cardFrozen'] = ($state === 'frozen');
    else unset($st['cardFrozen']);
    $tier = tierOf($st);
    $label = ['active' => '已激活', 'frozen' => '已冻结', 'inactive' => '未激活'][$state];
    $limTxt = $tier === 1 ? 'Ⅰ类账户：单笔 50 万 / 日 200 万' : 'Ⅱ类账户：单笔 1 万 / 日 1 万 / 年 20 万';
    pushNotif($st, 'card', '卡片状态变更 · ' . $label,
      '您的 Haitecard 状态已变更为「' . $label . '」，账户等级 ' . $limTxt . ($reason !== '' ? '，原因：' . $reason : ''));
    saveRow($row, $st, true, -1);
    jout(['ok' => 1, 'st' => $st, 'tier' => $tier, 'cardState' => $state, 'label' => $label,
      'cardNo' => (string)($st['card']['no'] ?? ''), 'lim' => limOf($st)]);
  }

  case 'op': {
    global $RECEIPT_MAX, $NOTIF_MAX;
    $k = (string)($in['k'] ?? '');
    if (!scopeCan($SCOPE, $k)) jout(['ok' => 0, 'err' => 'auth', 'msg' => '无权操作该账户']);
    $cur0 = pcGet($k);
    if (!$cur0) jout(['ok' => 0, 'err' => 'no_user']);
    $st = stOf($cur0);

    $ty   = (string)($in['ty'] ?? '');
    $dir  = (int)($in['dir'] ?? 0);
    $amt  = round((float)($in['amt'] ?? 0), 2);
    $cur  = ((string)($in['cur'] ?? 'HFS')) === 'HHS' ? 'HHS' : 'HFS';
    $chan = preg_replace('/[^a-z]/', '', (string)($in['chan'] ?? 'sys'));
    $dev  = substr((string)($in['dev'] ?? ''), 0, 32);
    $peer = substr((string)($in['peer'] ?? ''), 0, 40);
    $memo = substr((string)($in['memo'] ?? ''), 0, 60);
    $opBy = substr((string)($in['op'] ?? 'xiaozhi888'), 0, 24);
    if ($ty === '' || $amt < 0) jout(['ok' => 0, 'err' => 'bad_params']);

    $b0 = round((float)(($cur === 'HHS') ? ($st['ring'] ?? 0) : ($st['fons'] ?? 0)), 2);
    $b1 = ($dir === 0) ? $amt : round($b0 + $dir * $amt, 2);
    if ($b1 < 0) jout(['ok' => 0, 'err' => 'insufficient', 'b0' => $b0]);

    if ($cur === 'HHS') $st['ring'] = $b1; else $st['fons'] = $b1;

    $no = rcpNoSrv();
    $rcp = ['no'=>$no,'t'=>now_ms(),'ty'=>$ty,'dir'=>$dir,'amt'=>$amt,'cur'=>$cur,
      'chan'=>$chan,'dev'=>$dev,'peer'=>$peer,'memo'=>$memo,'op'=>$opBy,
      'b0'=>$b0,'b1'=>$b1,'seal'=>sealOf($no,$ty,$amt,$b0,$b1,$k)];

    pushRcp($st, $rcp);
    pushTx($st, $dir > 0 ? 'in' : 'out', $amt, $cur, $ty, $no);

    $sign = ($dir < 0 ? '-' : ($dir > 0 ? '+' : '')) . number_format($amt, 2, '.', ',');
    pushNotif($st, 'tx', '电子回单 · ' . $ty,
      $sign . ' ' . $cur . '，余额 ' . number_format($b1, 2, '.', ',') . ' ' . $cur . '，回单 ' . $no, $no);

    $r = saveRow($cur0, $st, true, -1);
    jout(['ok' => 1, 'rcp' => $rcp, 'st' => $st, 'ver' => $r['ver'] ?? 0]);
  }

  case 'del': {
    if (!$SCOPE || $SCOPE['role'] !== 'root') jout(['ok' => 0, 'err' => 'auth', 'msg' => '仅主管理员可删除账户']);
    $k = (string)($in['k'] ?? '');
    $cur = pcGet($k);
    if ($cur) {
      $oldSt = stOf($cur);
      if (!empty($oldSt['pcuid'])) freezeUid((string)$oldSt['pcuid'], $k);   // 销号 → 站内 UID 冻结，永不复用
    }
    $n = pcDel($k);
    tombSet($k, $cur ? (int)$cur['ver'] : 0, $ADMIN_USER);
    jout(['ok' => 1, 'n' => $n, 'gone' => $k]);
  }

  /* 管理员撤销删除（清墓碑），之后该账号才能重新注册 / 被同步创建 */
  case 'undel': {
    if (!$SCOPE || $SCOPE['role'] !== 'root') jout(['ok' => 0, 'err' => 'auth']);
    jout(['ok' => 1, 'cleared' => tombClear((string)($in['k'] ?? ''))]);
  }

  /* ---------- 后台：全库备份（含完整存档 / 回单 / 通知） ---------- */
  case 'dump': {
    if (!$SCOPE || $SCOPE['role'] !== 'root') jout(['ok' => 0, 'err' => 'auth']);
    $users = pcList();
    jout([
      'ok' => 1,
      'gen' => now_ms(),
      'mode' => $MODE,
      'db' => $dbName,
      'count' => count($users),
      'rates' => ratesQuote(),
      'users' => $users,
      'gone' => array_keys(tombAll()),
    ]);
  }

  default:
    jout(['ok' => 0, 'err' => 'unknown_action', 'a' => $a]);
}
