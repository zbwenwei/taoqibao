<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function json_in(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}
function reply($data, int $status=200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(string $msg, int $status=400): void { reply(['error'=>$msg], $status); }

$dataDir = dirname(__DIR__) . '/data';
if (!is_dir($dataDir) && !mkdir($dataDir, 0770, true)) fail('Cannot create data directory', 500);
$dbFile = $dataDir . '/family.sqlite';

try {
    $db = new PDO('sqlite:' . $dbFile, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (Throwable $e) {
    fail('SQLite/PDO is not available in this PHP installation: '.$e->getMessage(), 500);
}

$db->exec("CREATE TABLE IF NOT EXISTS users (
  name TEXT PRIMARY KEY,
  role TEXT NOT NULL,
  avatar TEXT NOT NULL,
  pin_hash TEXT NOT NULL
)");
$db->exec("CREATE TABLE IF NOT EXISTS app_state (
  id INTEGER PRIMARY KEY CHECK(id=1),
  json TEXT NOT NULL,
  updated_at TEXT NOT NULL
)");

$count = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
if ($count === 0) {
    $seedUsers = [
      ['Mom','parent','👩','2580'],
      ['Dad','parent','👨','3580'],
      ['Jerry','child','👦','1234'],
      ['Henrik','child','👦','5678']
    ];
    $st = $db->prepare("INSERT INTO users(name,role,avatar,pin_hash) VALUES(?,?,?,?)");
    foreach ($seedUsers as $u) $st->execute([$u[0],$u[1],$u[2],password_hash($u[3], PASSWORD_DEFAULT)]);
}

$stateExists = (int)$db->query("SELECT COUNT(*) FROM app_state WHERE id=1")->fetchColumn();
if ($stateExists === 0) {
    $seed = [
      'wallets'=>[
        'Jerry'=>['points'=>50,'coins'=>8,'color'=>'#4f7cff'],
        'Henrik'=>['points'=>30,'coins'=>5,'color'=>'#39a96b'],
        'Mom'=>['points'=>0,'coins'=>0,'color'=>'#f59e0b'],
        'Dad'=>['points'=>0,'coins'=>0,'color'=>'#8b5cf6']
      ],
      'selected'=>null,
      'currentWeekStart'=>'2026-08-10',
      'events'=>[
        ['id'=>1,'title'=>'Piano Practice','member'=>'Jerry','day'=>'Mon','date'=>'2026-08-10','time'=>'17:00','endTime'=>'17:45','points'=>10,'status'=>'pending','category'=>'fixed','color'=>'#39a96b','location'=>'Malmö Music School','address'=>'Föreningsgatan 35, Malmö','web'=>'https://www.google.com','email'=>'teacher@example.com','description'=>'Bring piano book and practice scales before lesson.','reminder'=>'30 min before'],
        ['id'=>2,'title'=>'Homework','member'=>'Jerry','day'=>'Tue','date'=>'2026-08-11','time'=>'16:30','endTime'=>'17:15','points'=>15,'status'=>'planned','category'=>'work_school','color'=>'#4f7cff','location'=>'Home','address'=>'','web'=>'','email'=>'','description'=>'Math and reading homework.','reminder'=>'10 min before'],
        ['id'=>3,'title'=>'Football','member'=>'Henrik','day'=>'Wed','date'=>'2026-08-12','time'=>'18:00','endTime'=>'19:30','points'=>10,'status'=>'planned','category'=>'fixed','color'=>'#39a96b','location'=>'Sports Field','address'=>'Stadiongatan 25, Malmö','web'=>'https://www.google.com','email'=>'coach@example.com','description'=>'Training session. Bring water bottle and football shoes.','reminder'=>'1 hour before'],
        ['id'=>4,'title'=>'Swimming','member'=>'Family','day'=>'Sat','date'=>'2026-08-15','time'=>'14:00','endTime'=>'16:00','points'=>5,'status'=>'planned','category'=>'spin_wheel','color'=>'#8b5cf6','location'=>'Hylliebadet','address'=>'Hyllievångsvägen 20, Malmö','web'=>'https://www.google.com','email'=>'','description'=>'Family swimming activity.','reminder'=>'1 hour before']
      ],
      'activities'=>[
        ['name'=>'Movie Night','points'=>0,'weight'=>10],['name'=>'Swimming','points'=>5,'weight'=>15],['name'=>'Board Game','points'=>0,'weight'=>25],
        ['name'=>'Bike Ride','points'=>5,'weight'=>15],['name'=>'Hiking','points'=>5,'weight'=>10],['name'=>'Restaurant','points'=>0,'weight'=>5],['name'=>'Ice Cream','points'=>0,'weight'=>10]
      ],
      'rewards'=>[
        ['name'=>'Ice Cream','emoji'=>'🍦','price'=>5],['name'=>'Movie Choice','emoji'=>'🎬','price'=>8],['name'=>'Game Time 30m','emoji'=>'🎮','price'=>10],
        ['name'=>'Choose Dinner','emoji'=>'🍕','price'=>15],['name'=>'Small Toy','emoji'=>'🎁','price'=>30],['name'=>'Theme Park','emoji'=>'🎢','price'=>100]
      ],
      'history'=>['+10 Points · Piano Practice','+15 Points · Homework','-50 Points · Exchanged for 5 Coins']
    ];
    $st = $db->prepare("INSERT INTO app_state(id,json,updated_at) VALUES(1,?,datetime('now'))");
    $st->execute([json_encode($seed, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
}

function user_row(PDO $db, string $name): ?array {
    $st=$db->prepare("SELECT name,role,avatar,pin_hash FROM users WHERE name=?");
    $st->execute([$name]);
    $u=$st->fetch();
    return $u ?: null;
}
function require_user(PDO $db): array {
    $name=$_SESSION['user'] ?? '';
    if (!$name) fail('Login required',401);
    $u=user_row($db,$name);
    if (!$u) fail('Unknown session user',401);
    return $u;
}
function public_user(array $u): array {
    return ['name'=>$u['name'],'role'=>$u['role'],'avatar'=>$u['avatar']];
}
function require_parent(PDO $db): array {
    $u=require_user($db);
    if ($u['role'] !== 'parent') fail('Parent account required',403);
    return $u;
}

$action = $_GET['action'] ?? 'session';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($action === 'session') {
    if (!isset($_SESSION['user'])) reply(['user'=>null]);
    $u=user_row($db,(string)$_SESSION['user']);
    reply(['user'=>$u?public_user($u):null]);
}

if ($action === 'login' && $method === 'POST') {
    $in=json_in(); $name=trim((string)($in['name']??'')); $pin=(string)($in['pin']??'');
    $u=user_row($db,$name);
    if (!$u || !password_verify($pin,$u['pin_hash'])) fail('Incorrect PIN',401);
    session_regenerate_id(true);
    $_SESSION['user']=$u['name'];
    reply(['user'=>public_user($u)]);
}

if ($action === 'logout' && $method === 'POST') {
    $_SESSION=[];
    if (ini_get('session.use_cookies')) {
        $p=session_get_cookie_params();
        setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']);
    }
    session_destroy();
    reply(['ok'=>true]);
}

if ($action === 'state' && $method === 'GET') {
    require_user($db);
    $r=$db->query("SELECT json,updated_at FROM app_state WHERE id=1")->fetch();
    reply(['state'=>json_decode($r['json'],true),'updated_at'=>$r['updated_at']]);
}

if ($action === 'state' && $method === 'PUT') {
    $u=require_user($db);
    $in=json_in(); $state=$in['state']??null;
    if (!is_array($state)) fail('Invalid state payload');
    // Children may save only normal app state; server still prevents PIN/user changes by keeping those in a separate table.
    $json=json_encode($state, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if ($json===false || strlen($json)>2_000_000) fail('State too large');
    $st=$db->prepare("UPDATE app_state SET json=?,updated_at=datetime('now') WHERE id=1");
    $st->execute([$json]);
    reply(['state'=>$state,'ok'=>true]);
}


function is_public_ip(string $ip): bool {
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}
function validate_calendar_url(string $url): string {
    $url = trim($url);
    if (stripos($url, 'webcal://') === 0) $url = 'https://' . substr($url, 9);
    $parts = parse_url($url);
    if (!$parts || !isset($parts['scheme'], $parts['host'])) fail('Invalid calendar URL');
    $scheme = strtolower((string)$parts['scheme']);
    if (!in_array($scheme, ['http','https'], true)) fail('Calendar URL must use http, https or webcal');
    if (isset($parts['user']) || isset($parts['pass'])) fail('Calendar URL must not contain embedded credentials');
    $host = (string)$parts['host'];
    if (strcasecmp($host, 'localhost') === 0 || str_ends_with(strtolower($host), '.local')) fail('Local/private calendar URLs are not allowed');
    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) $ips[] = $host;
    else {
        $a = @gethostbynamel($host);
        if (is_array($a)) $ips = array_merge($ips, $a);
        if (function_exists('dns_get_record')) {
            $aaaa = @dns_get_record($host, DNS_AAAA);
            if (is_array($aaaa)) foreach ($aaaa as $r) if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
        }
    }
    if (!$ips) fail('Could not resolve calendar host');
    foreach (array_unique($ips) as $ip) if (!is_public_ip($ip)) fail('Local/private calendar URLs are not allowed');
    return $url;
}
function absolute_redirect_url(string $base, string $location): string {
    if (preg_match('~^https?://~i', $location)) return $location;
    $b = parse_url($base);
    if (!$b || empty($b['host'])) return $location;
    $scheme = $b['scheme'] ?? 'https'; $host = $b['host']; $port = isset($b['port']) ? ':'.$b['port'] : '';
    if (str_starts_with($location, '//')) return $scheme.':'.$location;
    if (str_starts_with($location, '/')) return $scheme.'://'.$host.$port.$location;
    $path = $b['path'] ?? '/'; $dir = preg_replace('~/[^/]*$~', '/', $path);
    return $scheme.'://'.$host.$port.$dir.$location;
}
function fetch_calendar_url(string $url): string {
    $maxBytes = 3 * 1024 * 1024;
    for ($hop=0; $hop<5; $hop++) {
        $url = validate_calendar_url($url);
        $status = 0; $headers = []; $body = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>8,
                CURLOPT_TIMEOUT=>20, CURLOPT_USERAGENT=>'TaoqibaoCalendarImporter/1.0', CURLOPT_HEADER=>true,
                CURLOPT_HTTPHEADER=>['Accept: text/calendar, application/ics, text/plain;q=0.9, */*;q=0.1'],
                CURLOPT_ENCODING=>'', CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,
            ]);
            $raw = curl_exec($ch);
            if ($raw === false) { $err = curl_error($ch); curl_close($ch); fail('Calendar URL request failed: '.$err, 502); }
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);
            $headerText = substr($raw,0,$headerSize); $body = substr($raw,$headerSize);
            foreach (preg_split('/\r?\n/', trim($headerText)) as $line) { $pos=strpos($line,':'); if($pos!==false)$headers[strtolower(trim(substr($line,0,$pos)))]=trim(substr($line,$pos+1)); }
        } else {
            $ctx = stream_context_create(['http'=>['method'=>'GET','timeout'=>20,'ignore_errors'=>true,'max_redirects'=>0,'header'=>"Accept: text/calendar, application/ics, text/plain;q=0.9, */*;q=0.1\r\nUser-Agent: TaoqibaoCalendarImporter/1.0\r\n"], 'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
            $body = @file_get_contents($url, false, $ctx, 0, $maxBytes+1);
            if ($body === false) fail('Calendar URL request failed. PHP cURL or allow_url_fopen is required.', 502);
            $rh = $http_response_header ?? [];
            if ($rh && preg_match('~\s(\d{3})\s~', $rh[0], $m)) $status=(int)$m[1];
            foreach ($rh as $line) { $pos=strpos($line,':'); if($pos!==false)$headers[strtolower(trim(substr($line,0,$pos)))]=trim(substr($line,$pos+1)); }
        }
        if (in_array($status,[301,302,303,307,308],true) && !empty($headers['location'])) { $url=absolute_redirect_url($url,$headers['location']); continue; }
        if ($status < 200 || $status >= 300) fail('Calendar server returned HTTP '.$status, 502);
        if (strlen($body) > $maxBytes) fail('Calendar file is too large (maximum 3 MB)', 413);
        if (stripos($body,'BEGIN:VCALENDAR') === false) fail('The URL did not return an iCalendar (.ics) calendar', 422);
        return $body;
    }
    fail('Too many calendar URL redirects', 502);
}

if ($action === 'calendar_url' && $method === 'POST') {
    require_parent($db);
    $in=json_in(); $url=(string)($in['url']??'');
    if ($url==='') fail('Calendar URL is required');
    $ics=fetch_calendar_url($url);
    reply(['ok'=>true,'ics'=>$ics]);
}

if ($action === 'change_pin' && $method === 'POST') {
    $u=require_user($db);
    $in=json_in(); $current=(string)($in['currentPin']??''); $new=(string)($in['newPin']??'');
    if (!preg_match('/^\d{4}$/',$new)) fail('New PIN must be exactly 4 digits');
    if (!password_verify($current,$u['pin_hash'])) fail('Current PIN is incorrect',403);
    $st=$db->prepare("UPDATE users SET pin_hash=? WHERE name=?");
    $st->execute([password_hash($new,PASSWORD_DEFAULT),$u['name']]);
    reply(['ok'=>true]);
}

if ($action === 'reset_child_pin' && $method === 'POST') {
    require_parent($db);
    $in=json_in(); $child=(string)($in['child']??''); $new=(string)($in['newPin']??'');
    if (!preg_match('/^\d{4}$/',$new)) fail('New PIN must be exactly 4 digits');
    $c=user_row($db,$child);
    if (!$c || $c['role']!=='child') fail('Choose a child account');
    $st=$db->prepare("UPDATE users SET pin_hash=? WHERE name=?");
    $st->execute([password_hash($new,PASSWORD_DEFAULT),$child]);
    reply(['ok'=>true]);
}

fail('Unknown API action',404);
