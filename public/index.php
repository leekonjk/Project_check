<?php
declare(strict_types=1);

$appDir = dirname(__DIR__);
if (is_file($appDir . '/football-app/app.php')) {
    $appDir .= '/football-app';
}
require $appDir . '/app.php';
require $appDir . '/api.php';
require $appDir . '/admin.php';
require $appDir . '/views.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$isApi = is_string($path) && str_starts_with($path, '/api/');
$secure = str_starts_with(setting('APP_ORIGIN'), 'https://');
if ($secure) {
    header('Strict-Transport-Security: max-age=31536000');
}

try {
    if ($isApi) {
        api($path, $method);
    }
    if (!in_array($path, ['/', '/admin/login', '/admin', '/admin/logout'], true)) {
        fail(404, 'Page unavailable.');
    }
    if (!in_array($method, ['GET', 'POST'], true)) {
        fail(405, 'Method not allowed.');
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name($secure ? '__Host-football_admin' : 'football_admin');
    session_set_cookie_params(['path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    if ($method === 'POST' && (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['csrf']))) {
        fail(403, 'Your form expired. Reload the page and try again.');
    }
    $admin = null;
    if (isset($_SESSION['admin']) && ($_SESSION['expires'] ?? 0) > time()) {
        $admin = row('SELECT id,email FROM users WHERE id=? AND is_admin=TRUE', [$_SESSION['admin']]);
    }
    if ($path === '/') {
        redirect($admin ? '/admin' : '/admin/login');
    }
    if ($path === '/admin/login') {
        if ($method === 'POST') {
            $user = credentials($_POST);
            if (!$user['is_admin']) {
                fail(401, 'Invalid credentials.');
            }
            session_regenerate_id(true);
            $_SESSION = ['admin' => $user['id'], 'expires' => time() + 1800, 'csrf' => bin2hex(random_bytes(32))];
            redirect('/admin');
        }
        if ($admin) {
            redirect('/admin');
        }
        page('Admin sign in', function () {
            echo '<form method="post" class="login">';
            csrf();
            echo '<label>Email<input required type="email" name="email" autocomplete="username" maxlength="254"></label>
                  <label>Password<input required type="password" name="password" autocomplete="current-password" maxlength="128"></label>
                  <button>Sign in</button></form>';
        });
        exit;
    }
    if (!$admin) {
        redirect('/admin/login');
    }
    if ($path === '/admin/logout') {
        if ($method !== 'POST') {
            fail(405, 'Method not allowed.');
        }
        session_destroy();
        setcookie(session_name(), '', ['expires' => 1, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
        redirect('/admin/login');
    }
    if ($method === 'POST') {
        adminAction((int)$admin['id']);
        redirect('/admin');
    }
    $selected = $_GET['tournament'] ?? null;
    $event = null;
    if ($selected !== null) {
        $id = filter_var($selected, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id) {
            fail(422, 'Invalid tournament.');
        }
        $event = tournament($id);
        $event['entrants'] = query('SELECT users.id,users.email FROM entries JOIN users ON users.id=entries.user_id
                                    WHERE tournament_id=? ORDER BY users.id', [$id])->fetchAll();
        $event['matches'] = query('SELECT * FROM matches WHERE tournament_id=? ORDER BY round_number,position', [$id])->fetchAll();
    }
    dashboard($admin, $event);
} catch (Throwable $error) {
    $status = $error instanceof HttpError ? $error->getCode() : 500;
    $message = $error instanceof HttpError ? $error->getMessage() : 'Request failed. Try again later.';
    if ($status === 500) {
        error_log('Football request failed: ' . get_class($error) . ' (' . $error->getCode() . ')');
    }
    if ($isApi) {
        json(['error' => $message], $status);
    }
    http_response_code($status);
    page('Request could not be completed', function () use ($message) {
        echo '<p>' . h($message) . '</p><a href="/">Return to dashboard</a>';
    });
}
