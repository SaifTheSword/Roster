<?php
// ======================= SETTINGS - edit these =======================
$CONFIG = [
    'setup_code'        => 'CHANGE-THIS-CODE', // you type this once, the first time you open the site
    'timezone'          => 'Asia/Dubai',
    'backup_every_days' => 30,                 // 30 = monthly, 7 = weekly
    'backup_keep'       => 12,                 // how many backups to keep on the server
    'session_days'      => 30,                 // how long a sign-in lasts
];
// =====================================================================

date_default_timezone_set($CONFIG['timezone']);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const DATA_DIR = __DIR__ . '/data';
const STORE = DATA_DIR . '/store.php';
// Every data file starts with this line, so that opening it in a browser shows nothing.
define('HEAD', '<' . '?php http_response_code(404); exit; ?' . ">\n");

function out($d, $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function fail($m, $code = 400) { out(['error' => $m], $code); }
function rank($role) { $r = ['user' => 1, 'admin' => 2, 'superadmin' => 3]; return isset($r[$role]) ? $r[$role] : 0; }

function prepare_dir() {
    if (!is_dir(DATA_DIR)) { @mkdir(DATA_DIR, 0755, true); }
    if (!is_dir(DATA_DIR) || !is_writable(DATA_DIR)) {
        fail('The "data" folder could not be created or is not writable. In your file manager, create a folder named data next to api.php and give it permission 755 (or 775).', 500);
    }
    foreach (['backups', 'sessions'] as $s) { if (!is_dir(DATA_DIR . '/' . $s)) { @mkdir(DATA_DIR . '/' . $s, 0755, true); } }
    if (!is_file(DATA_DIR . '/.htaccess')) {
        @file_put_contents(DATA_DIR . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    }
    if (!is_file(DATA_DIR . '/index.html')) { @file_put_contents(DATA_DIR . '/index.html', ''); }
}
function read_file_data($path) {
    if (!is_file($path)) { return null; }
    $raw = file_get_contents($path);
    if ($raw === false) { return null; }
    $nl = strpos($raw, "\n");
    if ($nl === false) { return null; }
    $d = json_decode(substr($raw, $nl + 1), true);
    return is_array($d) ? $d : null;
}
function write_file_data($path, $d) {
    $body = HEAD . json_encode($d, JSON_UNESCAPED_UNICODE);
    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, $body) === false) { fail('Could not write to the data folder.', 500); }
    if (!@rename($tmp, $path)) { file_put_contents($path, $body); @unlink($tmp); }
}
function backup_due($d) {
    global $CONFIG;
    $last = isset($d['last_backup']) ? (int)$d['last_backup'] : 0;
    return (time() - $last) >= $CONFIG['backup_every_days'] * 86400;
}
function backup_if_due(&$d, $force) {
    global $CONFIG;
    if (!$force && !backup_due($d)) { return null; }
    $d['last_backup'] = time();
    $name = 'backup-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.php';
    write_file_data(DATA_DIR . '/backups/' . $name, $d);
    $files = glob(DATA_DIR . '/backups/backup-*.php');
    rsort($files);
    foreach (array_slice($files, (int)$CONFIG['backup_keep']) as $old) { @unlink($old); }
    return $name;
}
function list_backups() {
    $files = glob(DATA_DIR . '/backups/backup-*.php');
    rsort($files);
    $list = [];
    foreach ($files as $f) { $list[] = ['name' => basename($f), 'size' => filesize($f), 'time' => date('Y-m-d H:i', filemtime($f))]; }
    return $list;
}
// Runs $fn on the data while holding a lock, so two people saving at once never overwrite each other.
function mutate($fn, $bump = true) {
    $lock = fopen(DATA_DIR . '/lock.php', 'c');
    flock($lock, LOCK_EX);
    $d = read_file_data(STORE);
    if ($d === null) { fail('This site has not been set up yet.', 409); }
    $res = $fn($d);
    if ($bump) { $d['v'] = (int)$d['v'] + 1; }
    backup_if_due($d, false);
    write_file_data(STORE, $d);
    flock($lock, LOCK_UN);
    fclose($lock);
    return $res;
}
function current_user($d) {
    if (empty($_SESSION['uid'])) { return null; }
    foreach ($d['users'] as $u) { if ($u['id'] === $_SESSION['uid'] && !empty($u['active'])) { return $u; } }
    return null;
}
function require_user($d, $min) {
    $u = current_user($d);
    if (!$u) { fail('Please sign in.', 401); }
    if (rank($u['role']) < $min) { fail('You do not have permission for that.', 403); }
    return $u;
}
function pub_user($u) {
    return ['id' => $u['id'], 'username' => $u['username'], 'name' => $u['name'], 'role' => $u['role'],
            'active' => !empty($u['active']), 'last_login' => isset($u['last_login']) ? $u['last_login'] : null];
}
function state_payload($d, $u) {
    global $CONFIG;
    $s = ['v' => (int)$d['v'], 'company' => $d['company'], 'anchor' => $d['anchor'], 'types' => $d['types'],
          'people' => $d['people'], 'me' => pub_user($u)];
    $r = rank($u['role']);
    if ($r >= 2) {
        $users = [];
        foreach ($d['users'] as $x) { if (rank($x['role']) <= $r) { $users[] = pub_user($x); } }
        $s['users'] = $users;
    }
    if ($r >= 3) {
        $s['backups'] = list_backups();
        $s['backup_days'] = (int)$CONFIG['backup_every_days'];
        $s['last_backup'] = isset($d['last_backup']) ? (int)$d['last_backup'] : 0;
    }
    return $s;
}
function respond($extra = []) {
    $d = read_file_data(STORE);
    $u = $d ? current_user($d) : null;
    if (!$u) { out(['ok' => 1, 'signedOut' => true]); }
    out(array_merge(['ok' => 1, 'state' => state_payload($d, $u)], $extra));
}
function valid_date($s) { $x = DateTime::createFromFormat('Y-m-d', (string)$s); return $x && $x->format('Y-m-d') === $s; }
function find_user_index($d, $id) { foreach ($d['users'] as $i => $u) { if ($u['id'] === $id) { return $i; } } return -1; }
function new_id($p) { return $p . bin2hex(random_bytes(4)); }
function check_password($pw) { if (strlen($pw) < 8) { fail('Passwords need at least 8 characters.'); } }
function sample_data() {
    $T = ['Global Express', 'Challenger 605', 'Challenger 350', 'Dassault 7X', 'Gulfstream 650', 'Learjet 60XR', 'Legacy 650'];
    $eng = [['Omar Haddad','A',[0,1,2]],['Liam Carter','A',[0,3]],['Sanjay Menon','B',[3,4,5]],['Elena Petrova','B',[1,2]],
            ['Khalid Rahman','C',[1,4,6]],['Daniel Okafor','C',[5,4]],['Marcus Webb','D',[0,3]],['Yusuf Karim','D',[6,2,3]]];
    $tech = [['Tomasz Nowak','A'],['Hamza Ali','A'],['Joel Mathew','A'],['Ahmed Salem','B'],['Faisal Noor','B'],['Ben Foster','B'],
             ['Peter Dsouza','C'],['Arjun Nair','C'],['Saeed Omar','C'],['Ravi Kumar','D'],['Imran Sheikh','D'],['Chris Lopez','D']];
    $cl = [['Nasir Uddin','A'],['Rahim Khan','A'],['Jose Ramos','B'],['Bilal Ahmed','B'],['Sunil Perera','C'],['Kamal Hossain','C'],['Raj Patel','D'],['Mohan Das','D']];
    $people = [];
    foreach ($eng as $p) { $r = []; foreach ($p[2] as $i) { $r[] = $T[$i]; } $people[] = ['id' => new_id('p'), 'name' => $p[0], 'role' => 'Engineer', 'shift' => $p[1], 'ratings' => $r]; }
    foreach ($tech as $p) { $people[] = ['id' => new_id('p'), 'name' => $p[0], 'role' => 'Technician', 'shift' => $p[1], 'ratings' => []]; }
    foreach ($cl as $p) { $people[] = ['id' => new_id('p'), 'name' => $p[0], 'role' => 'Cleaner', 'shift' => $p[1], 'ratings' => []]; }
    return [$T, $people];
}

prepare_dir();
ini_set('session.use_strict_mode', '1');
ini_set('session.gc_maxlifetime', (string)($CONFIG['session_days'] * 86400));
session_save_path(DATA_DIR . '/sessions');
session_name('rp');
session_set_cookie_params(['lifetime' => $CONFIG['session_days'] * 86400, 'path' => '/',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'), 'httponly' => true, 'samesite' => 'Lax']);
session_start();

$d0 = read_file_data(STORE);

// ---------------- GET: live check and backup download ----------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['download'])) {
        $u = $d0 ? current_user($d0) : null;
        if (!$u || rank($u['role']) < 3) { fail('Not allowed.', 403); }
        $name = (string)$_GET['download'];
        $bd = preg_match('/^backup-\d{8}-\d{6}-[0-9a-f]{6}\.php$/', $name) ? read_file_data(DATA_DIR . '/backups/' . $name) : null;
        if (!$bd) { fail('Backup not found.', 404); }
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . substr($name, 0, -4) . '.json"');
        echo json_encode($bd, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (($_GET['a'] ?? '') === 'state') {
        if ($d0 === null) { out(['needsSetup' => true]); }
        $u = current_user($d0);
        if (!$u) { out(['signedOut' => true]); }
        if (backup_due($d0)) { mutate(function (&$d) {}, false); $d0 = read_file_data(STORE); }
        if (isset($_GET['v']) && (int)$_GET['v'] === (int)$d0['v']) { out(['same' => true]); }
        out(['state' => state_payload($d0, $u)]);
    }
    fail('Not found.', 404);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { fail('Method not allowed.', 405); }
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') { fail('Bad request.'); }
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) { fail('Bad request.'); }
$a = (string)($in['a'] ?? '');

try {
    // ---------------- first-time setup ----------------
    if ($a === 'setup') {
        if ($d0 !== null) { fail('This site is already set up.', 409); }
        $code = (string)($in['setup_code'] ?? '');
        if ($CONFIG['setup_code'] === 'CHANGE-THIS-CODE' || !hash_equals((string)$CONFIG['setup_code'], $code)) {
            fail('The setup code is wrong, or you have not changed it inside api.php yet.');
        }
        $company = trim((string)($in['company'] ?? ''));
        $username = trim((string)($in['username'] ?? ''));
        $name = trim((string)($in['name'] ?? ''));
        $pw = (string)($in['password'] ?? '');
        if ($company === '' || mb_strlen($company) > 100) { fail('Enter the company name.'); }
        if (!preg_match('/^[A-Za-z0-9._@+-]{3,60}$/', $username)) { fail('Username can use letters, numbers and . _ @ + - (3 to 60 characters).'); }
        check_password($pw);
        $lock = fopen(DATA_DIR . '/lock.php', 'c');
        flock($lock, LOCK_EX);
        if (is_file(STORE)) { fail('This site is already set up.', 409); }
        $types = []; $people = [];
        if (!empty($in['sample'])) { list($types, $people) = sample_data(); }
        $uid = new_id('u');
        $d = ['v' => 1, 'company' => $company, 'anchor' => date('Y-m-d'), 'types' => $types, 'people' => $people,
              'users' => [['id' => $uid, 'username' => $username, 'name' => $name !== '' ? mb_substr($name, 0, 100) : $username,
                           'role' => 'superadmin', 'hash' => password_hash($pw, PASSWORD_DEFAULT), 'active' => true, 'last_login' => date('Y-m-d H:i')]],
              'last_backup' => 0, 'attempts' => []];
        write_file_data(STORE, $d);
        flock($lock, LOCK_UN);
        fclose($lock);
        session_regenerate_id(true);
        $_SESSION['uid'] = $uid;
        respond();
    }
    if ($d0 === null) { fail('This site has not been set up yet.', 409); }

    // ---------------- sign in / out ----------------
    if ($a === 'login') {
        $username = strtolower(trim((string)($in['username'] ?? '')));
        $pw = (string)($in['password'] ?? '');
        $key = $username . '|' . ($_SERVER['REMOTE_ADDR'] ?? '');
        $now = time();
        $recent = function ($list) use ($now) { return array_values(array_filter((array)$list, function ($t) use ($now) { return $t > $now - 600; })); };
        $att = $recent(isset($d0['attempts'][$key]) ? $d0['attempts'][$key] : []);
        if (count($att) >= 5) { fail('Too many attempts. Please wait 10 minutes and try again.', 429); }
        $found = null;
        foreach ($d0['users'] as $u) { if (strtolower($u['username']) === $username) { $found = $u; break; } }
        if (!$found || empty($found['active']) || !password_verify($pw, $found['hash'])) {
            mutate(function (&$d) use ($key, $now, $recent) {
                if (!isset($d['attempts']) || !is_array($d['attempts'])) { $d['attempts'] = []; }
                $list = isset($d['attempts'][$key]) ? $d['attempts'][$key] : [];
                $list[] = $now;
                $d['attempts'][$key] = $recent($list);
                foreach ($d['attempts'] as $k => $l) { $r = $recent($l); if ($r) { $d['attempts'][$k] = $r; } else { unset($d['attempts'][$k]); } }
            }, false);
            fail('Wrong username or password.', 401);
        }
        session_regenerate_id(true);
        $_SESSION['uid'] = $found['id'];
        mutate(function (&$d) use ($found, $key) {
            foreach ($d['users'] as $i => $u) { if ($u['id'] === $found['id']) { $d['users'][$i]['last_login'] = date('Y-m-d H:i'); } }
            if (isset($d['attempts'][$key])) { unset($d['attempts'][$key]); }
        }, false);
        respond();
    }
    if ($a === 'logout') { $_SESSION = []; session_destroy(); out(['ok' => 1]); }

    // ---------------- everything below needs a signed-in user ----------------
    switch ($a) {

    case 'me_password':
        mutate(function (&$d) use ($in) {
            $me = require_user($d, 1);
            if (!password_verify((string)($in['current'] ?? ''), $me['hash'])) { fail('Your current password is wrong.'); }
            $new = (string)($in['password'] ?? '');
            check_password($new);
            $i = find_user_index($d, $me['id']);
            $d['users'][$i]['hash'] = password_hash($new, PASSWORD_DEFAULT);
        });
        respond();

    case 'person_save':
        mutate(function (&$d) use ($in) {
            require_user($d, 2);
            $name = trim((string)($in['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 100) { fail('Enter a name.'); }
            $role = $in['role'] ?? '';
            $sh = $in['shift'] ?? '';
            if (!in_array($role, ['Engineer', 'Technician', 'Cleaner'], true) || !in_array($sh, ['A', 'B', 'C', 'D'], true)) { fail('Choose a role and a shift.'); }
            $ratings = [];
            if ($role === 'Engineer') {
                foreach ((array)($in['ratings'] ?? []) as $x) { if (in_array($x, $d['types'], true) && !in_array($x, $ratings, true)) { $ratings[] = $x; } }
            }
            $id = (string)($in['id'] ?? '');
            $found = false;
            foreach ($d['people'] as $i => $p) {
                if ($p['id'] === $id) { $d['people'][$i] = ['id' => $id, 'name' => $name, 'role' => $role, 'shift' => $sh, 'ratings' => $ratings]; $found = true; }
            }
            if (!$found) {
                if (count($d['people']) >= 3000) { fail('Too many people.'); }
                $d['people'][] = ['id' => new_id('p'), 'name' => $name, 'role' => $role, 'shift' => $sh, 'ratings' => $ratings];
            }
        });
        respond();

    case 'person_delete':
        mutate(function (&$d) use ($in) {
            require_user($d, 2);
            $id = (string)($in['id'] ?? '');
            $d['people'] = array_values(array_filter($d['people'], function ($p) use ($id) { return $p['id'] !== $id; }));
        });
        respond();

    case 'types_save':
        mutate(function (&$d) use ($in) {
            require_user($d, 2);
            $types = [];
            foreach ((array)($in['types'] ?? []) as $t) {
                $t = trim((string)$t);
                if ($t !== '' && mb_strlen($t) <= 60 && !in_array($t, $types, true)) { $types[] = $t; }
            }
            if (count($types) > 60) { fail('Too many type ratings.'); }
            $d['types'] = $types;
            foreach ($d['people'] as $i => $p) { $d['people'][$i]['ratings'] = array_values(array_intersect($p['ratings'], $types)); }
        });
        respond();

    case 'settings_save':
        mutate(function (&$d) use ($in) {
            require_user($d, 2);
            $company = trim((string)($in['company'] ?? ''));
            if ($company === '' || mb_strlen($company) > 100) { fail('Enter the company name.'); }
            if (!valid_date($in['anchor'] ?? '')) { fail('Choose a valid shift start date.'); }
            $d['company'] = $company;
            $d['anchor'] = $in['anchor'];
        });
        respond();

    // ---- accounts: you can manage anyone ranked below you (Admin: users; SuperAdmin: admins and users) ----
    case 'user_save':
        mutate(function (&$d) use ($in) {
            $me = require_user($d, 2);
            $username = trim((string)($in['username'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9._@+-]{3,60}$/', $username)) { fail('Username can use letters, numbers and . _ @ + - (3 to 60 characters).'); }
            $role = (string)($in['role'] ?? 'user');
            if (!in_array($role, ['admin', 'user'], true) || rank($role) >= rank($me['role'])) { fail('You cannot create that kind of account.', 403); }
            $pw = (string)($in['password'] ?? '');
            check_password($pw);
            foreach ($d['users'] as $u) { if (strtolower($u['username']) === strtolower($username)) { fail('That username already exists.'); } }
            $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 100);
            $d['users'][] = ['id' => new_id('u'), 'username' => $username, 'name' => $name !== '' ? $name : $username, 'role' => $role,
                             'hash' => password_hash($pw, PASSWORD_DEFAULT), 'active' => true, 'last_login' => null];
        });
        respond();

    case 'user_update':
    case 'user_reset':
    case 'user_delete':
        mutate(function (&$d) use ($in, $a) {
            $me = require_user($d, 2);
            $i = find_user_index($d, (string)($in['id'] ?? ''));
            if ($i < 0) { fail('User not found.', 404); }
            $t = $d['users'][$i];
            if ($t['id'] === $me['id']) { fail('Use the Account page for your own account.'); }
            if (rank($t['role']) >= rank($me['role'])) { fail('You cannot change that account.', 403); }
            if ($a === 'user_reset') {
                $pw = (string)($in['password'] ?? '');
                check_password($pw);
                $d['users'][$i]['hash'] = password_hash($pw, PASSWORD_DEFAULT);
            } elseif ($a === 'user_delete') {
                array_splice($d['users'], $i, 1);
            } else {
                if (isset($in['role'])) {
                    $nr = (string)$in['role'];
                    if (!in_array($nr, ['admin', 'user'], true) || rank($nr) >= rank($me['role'])) { fail('You cannot give that access level.', 403); }
                    $d['users'][$i]['role'] = $nr;
                }
                if (isset($in['active'])) { $d['users'][$i]['active'] = !empty($in['active']); }
                if (isset($in['name'])) { $d['users'][$i]['name'] = mb_substr(trim((string)$in['name']), 0, 100); }
            }
        });
        respond();

    // ---- backups (SuperAdmin only) ----
    case 'backup_now':
        mutate(function (&$d) { require_user($d, 3); backup_if_due($d, true); });
        respond();

    case 'restore':
        $bd = null;
        if (isset($in['name'])) {
            $name = (string)$in['name'];
            if (preg_match('/^backup-\d{8}-\d{6}-[0-9a-f]{6}\.php$/', $name)) { $bd = read_file_data(DATA_DIR . '/backups/' . $name); }
        } elseif (isset($in['data']) && is_array($in['data'])) {
            $bd = $in['data'];
        }
        if (!$bd || !isset($bd['users'], $bd['people'], $bd['types'], $bd['company'], $bd['anchor']) || !is_array($bd['users'])) { fail('That backup could not be read.'); }
        mutate(function (&$d) use ($bd) {
            require_user($d, 3);
            backup_if_due($d, true); // safety copy of what is there now
            $v = (int)$d['v'];
            $d = $bd;
            $d['v'] = $v;
            $d['last_backup'] = time();
            if (!isset($d['attempts']) || !is_array($d['attempts'])) { $d['attempts'] = []; }
        });
        respond();

    default:
        fail('Unknown action.', 404);
    }
} catch (Throwable $e) {
    error_log('roster: ' . $e->getMessage());
    fail('Server error. Please try again.', 500);
}
