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
require $appDir . '/admob.php';

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
    if (!in_array($method, ['GET', 'POST'], true)) {
        fail(405, 'Method not allowed.');
    }
    if (in_array($path, ['/login', '/register', '/account', '/logout'], true)) {
        playerWeb($path, $method, $secure);
    }
    if (!in_array($path, ['/', '/admin/login', '/admin', '/admin/logout', '/admin/reports'], true)) {
        fail(404, 'Page unavailable.');
    }
    $allowedIps = setting('ADMIN_ALLOWED_IPS');
    if ($allowedIps !== '' && !in_array($_SERVER['REMOTE_ADDR'] ?? '', array_map('trim', explode(',', $allowedIps)), true)) {
        fail(403, 'Admin access is restricted.');
    }
    startWebSession('admin', $secure);
    if ($method === 'POST') {
        checkWebForm();
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
            if (!session_regenerate_id(true)) {
                fail(503, 'Could not start your session.');
            }
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
                  <button>Sign in</button></form><p><a href="/login">Player sign in</a></p>';
        });
        exit;
    }
    if (!$admin) {
        redirect('/admin/login');
    }
    $view = $_GET['view'] ?? 'overview';
    if ($path === '/admin/logout') {
        if ($method !== 'POST') {
            fail(405, 'Method not allowed.');
        }
        endWebSession();
        redirect('/admin/login');
    }
    if ($path === '/admin/reports') {
        if ($method === 'GET' && ($_GET['download'] ?? '') === 'csv') {
            $cached = $_SESSION['last_admob_report'] ?? fail(404, 'Generate a report before downloading.');
            exportAdmobCsv($cached);
        }
        $days = 7;
        $dimensions = ['DATE', 'FORMAT', 'COUNTRY', 'AD_UNIT'];
        $dimension = in_array($_GET['group'] ?? null, $dimensions, true) ? $_GET['group'] : 'FORMAT';
        $report = null;
        if ($method === 'POST') {
            $range = $_POST['range'] ?? '';
            $dimension = $_POST['group'] ?? '';
            if (!in_array($range, ['7', '30'], true) || !in_array($dimension, $dimensions, true)) {
                fail(422, 'Choose a valid report period and breakdown.');
            }
            if (($_SESSION['report_allowed_after'] ?? 0) > time()) {
                fail(429, 'Wait a few seconds before generating another report.');
            }
            $_SESSION['report_allowed_after'] = time() + 20;
            $days = (int)$range;
            $report = admobReport($days, $dimension);
            $_SESSION['last_admob_report'] = ['dimension' => $dimension, 'generated' => gmdate('Y-m-d H:i'), 'data' => $report];
        }
        renderAdmobPage($admin, $report, $days, $dimension);
        exit;
    }
    if ($method === 'POST') {
        if ($path !== '/admin') {
            fail(405, 'Method not allowed.');
        }
        if (($_POST['action'] ?? '') === 'review-banner') {
            [$enabled, $unit] = bannerInput();
            $current = row('SELECT * FROM ad_settings WHERE id=1') ?? fail(503, 'Banner configuration unavailable.');
            if ((int)$current['version'] !== formId('version')) {
                fail(409, 'The banner configuration changed. Review the latest settings before publishing.');
            }
            $_SESSION['banner_draft'] = [(int)$current['version'], $enabled, $unit];
            placementsPage($admin, $current, [$enabled, $unit]);
            exit;
        }
        if (($_POST['action'] ?? '') === 'banner' && ($_SESSION['banner_draft'] ?? null) !== [formId('version'), ...bannerInput()]) {
            fail(409, 'Review the banner changes before publishing.');
        }
        adminAction((int)$admin['id']);
        if (($_POST['action'] ?? '') === 'banner') {
            unset($_SESSION['banner_draft']);
        }
        redirect(($_POST['action'] ?? '') === 'banner' ? '/admin?view=placements&saved=1' : '/admin?view=tournaments');
    }
    if (in_array($view, ['placements', 'revenue', 'traffic', 'formats', 'diagnostics', 'settings'], true)) {
        if ($view === 'placements') {
            $ads = row('SELECT * FROM ad_settings WHERE id=1') ?? fail(503, 'Banner configuration unavailable.');
            placementsPage($admin, $ads, null, ($_GET['saved'] ?? '') === '1');
        } else {
            infoPage($admin, $view);
        }
        exit;
    }
    if ($view === 'overview' && !isset($_GET['tournament'])) {
        overviewPage($admin, ($_GET['demo'] ?? '') === '1');
        exit;
    }
    if (!in_array($view, ['overview', 'tournaments', 'audit'], true)) {
        fail(404, 'Page unavailable.');
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
    dashboard($admin, $event, $view === 'audit');
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
    page('Request could not be completed', function () use ($message, $path) {
        echo '<p>' . h($message) . '</p>';
        echo in_array($path, ['/login', '/register', '/account', '/logout'], true)
            ? '<a href="/login">Return to player sign in</a>' : '<a href="/admin">Return to dashboard</a>';
    });
}
