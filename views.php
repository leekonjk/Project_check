<?php
declare(strict_types=1);

function h(string|int|null $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $location): never
{
    header('Location: ' . $location, true, 303);
    exit;
}

function csrf(): void
{
    echo '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">';
}

function page(string $title, callable $content): void
{
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="stylesheet" href="/style.css"><title>' . h($title) . ' | Football Admin</title>
        <main><header><p class="eyebrow">Football Admin</p><h1>' . h($title) . '</h1></header>';
    $content();
    echo '</main></html>';
}

function actionForm(string $action, int $id): void
{
    echo '<form method="post" class="inline">';
    csrf();
    echo '<input type="hidden" name="action" value="' . h($action) . '"><input type="hidden" name="id" value="' . h($id) . '">';
}

function playerAuthPage(bool $signUp, string $error, bool $registered): void
{
    $title = $signUp ? 'Create a player account' : 'Player sign in';
    page($title, function () use ($signUp, $error, $registered) {
        if ($registered) {
            echo '<p class="notice" role="status">You can now try signing in with your email and password.</p>';
        }
        if ($error !== '') {
            echo '<p class="notice warning" role="alert">' . h($error) . '</p>';
        }
        echo '<form method="post" class="login">';
        csrf();
        $email = is_string($_POST['email'] ?? null) ? substr($_POST['email'], 0, 254) : '';
        echo '<label>Email<input required type="email" name="email" autocomplete="username" maxlength="254" value="' . h($email) . '"></label>
              <label>Password<input required type="password" name="password" maxlength="128" ' .
              ($signUp ? 'minlength="12" autocomplete="new-password"' : 'autocomplete="current-password"') . '></label>';
        if ($signUp) {
            echo '<p class="muted">Use at least 12 characters.</p>
                  <label>Confirm password<input required type="password" name="confirm_password" minlength="12" maxlength="128" autocomplete="new-password"></label>';
        }
        echo '<button>' . ($signUp ? 'Create account' : 'Sign in') . '</button></form>';
        echo $signUp ? '<p><a href="/login">Already have an account? Sign in</a></p>'
            : '<p><a href="/register">Create a player account</a></p><p><a href="/admin/login">Admin sign in</a></p>';
    });
}

function playerAccountPage(array $account): void
{
    page('Player account', function () use ($account) {
        echo '<section class="login"><h2>Signed in</h2><p>' . h($account['email']) . '</p>
              <form method="post" action="/logout">';
        csrf();
        echo '<button>Sign out</button></form></section>';
    });
}

function adminPage(string $title, string $description, string $active, array $admin, callable $content): void
{
    $items = [
        'overview' => ['Overview', '/admin'],
        'revenue' => ['Revenue', '/admin?view=revenue'],
        'traffic' => ['Traffic', '/admin?view=traffic'],
        'formats' => ['Ad Formats', '/admin?view=formats'],
        'placements' => ['Placements', '/admin?view=placements'],
        'reports' => ['Reports', '/admin/reports'],
        'diagnostics' => ['Diagnostics', '/admin?view=diagnostics'],
        'tournaments' => ['Tournaments', '/admin?view=tournaments'],
        'audit' => ['Audit History', '/admin?view=audit'],
        'settings' => ['Settings', '/admin?view=settings'],
    ];
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
          <link rel="stylesheet" href="/style.css"><title>' . h($title) . ' | Football Admin</title></head>
          <body class="workspace"><a class="skip-link" href="#workspace-content">Skip to content</a>
          <aside class="sidebar"><a class="brand" href="/admin"><span class="brand-mark" aria-hidden="true">◴</span>
          <span>Football Admin<small>Game operations</small></span></a>
          <p class="app-label"><span aria-hidden="true">▣</span> Football Android</p>
          <nav aria-label="Dashboard">';
    foreach ($items as $key => [$label, $url]) {
        if ($key === 'tournaments') {
            echo '<small class="nav-group">Game administration</small>';
        }
        echo '<a href="' . h($url) . '"' . ($active === $key ? ' aria-current="page"' : '') . '>' . h($label) . '</a>';
    }
    echo '</nav></aside><div class="workspace-body"><header class="toolbar"><span>Workspace <span aria-hidden="true">/</span> ' .
        h($title) . '</span><form method="post" action="/admin/logout" class="operator">';
    csrf();
    echo '<span class="operator-email">' . h($admin['email']) . '</span><button class="secondary">Sign out</button></form></header>
          <main class="workspace-content" id="workspace-content"><header class="page-heading"><h1>' . h($title) . '</h1><p>' .
          h($description) . '</p></header>';
    $content();
    echo '</main></div></body></html>';
}

function overviewPage(array $admin, bool $demo): void
{
    $cached = $demo ? null : ($_SESSION['last_admob_report'] ?? null);
    $totals = $demo ? ['earnings' => 1248600000, 'requests' => 840000, 'matched' => 756000, 'impressions' => 624300, 'clicks' => 12486]
        : ($cached['data']['totals'] ?? null);
    $currency = $demo ? 'USD' : ($cached['data']['currency'] ?? '');
    $period = $demo ? '7 complete days · Sample figures' : ($cached['data']['period'] ?? 'Choose a period in Reports');
    $days = $demo ? [155.20, 164.80, 172.80, 191.60, 198.20, 182.10, 183.90] : [];
    if (($cached['dimension'] ?? '') === 'DATE') {
        $days = array_column($cached['data']['rows'], 'earnings');
    }
    $formats = $demo ? [
        ['label' => 'Banner', 'earnings' => 210000000, 'impressions' => 300000],
        ['label' => 'Interstitial', 'earnings' => 375000000, 'impressions' => 150000],
        ['label' => 'Rewarded', 'earnings' => 420000000, 'impressions' => 100000],
        ['label' => 'Rewarded interstitial', 'earnings' => 150000000, 'impressions' => 50000],
        ['label' => 'Native', 'earnings' => 48600000, 'impressions' => 20000],
        ['label' => 'App open', 'earnings' => 45000000, 'impressions' => 4300],
    ] : (($cached['dimension'] ?? '') === 'FORMAT' ? $cached['data']['rows'] : []);
    $countries = $demo ? [
        ['label' => 'United States', 'earnings' => 524400000, 'impressions' => 174800],
        ['label' => 'Brazil', 'earnings' => 224400000, 'impressions' => 149600],
        ['label' => 'India', 'earnings' => 180000000, 'impressions' => 180000],
        ['label' => 'United Kingdom', 'earnings' => 159600000, 'impressions' => 53200],
        ['label' => 'Other', 'earnings' => 160200000, 'impressions' => 66700],
    ] : (($cached['dimension'] ?? '') === 'COUNTRY' ? array_slice($cached['data']['rows'], 0, 5) : []);

    adminPage('Overview', 'The complete picture of your game’s ad performance.', 'overview', $admin,
        function () use ($demo, $cached, $totals, $currency, $period, $days, $formats, $countries) {
            echo '<section class="filter-bar"><div class="toolbar-actions"><div><span class="tag">' . ($demo ? 'Demo data' : 'AdMob Network') .
                '</span><p>' . h($period) . '</p></div><div class="toolbar-actions"><a class="button secondary" href="' .
                ($demo ? '/admin' : '/admin?demo=1') . '">' . ($demo ? 'Exit demo' : 'Preview demo data') .
                '</a><a class="button" href="/admin/reports">Build a report</a></div></div></section>';
            if ($demo || !$cached) {
                echo '<p class="notice">' . ($demo ? 'Sample figures for preview. Banner settings use the actual PHP configuration.'
                    : 'Generate an AdMob report to populate this overview.') . '</p>';
            } else {
                echo '<p class="notice">Report generated ' . h($cached['generated']) . ' UTC. Totals reflect the selected period.</p>';
            }
            if (!$demo && !admobConfigured()) {
                echo '<p class="notice warning">AdMob reporting is disconnected. <a href="/admin?view=settings">View connection status</a></p>';
            }
            $money = static fn ($value) => $currency . ' ' . number_format($value, 2);
            $values = $totals ? [
                $money($totals['earnings'] / 1000000), number_format($totals['impressions']), number_format($totals['requests']),
                $totals['impressions'] ? $money($totals['earnings'] / 1000 / $totals['impressions']) : '—',
                reportRate($totals['matched'], $totals['requests']), reportRate($totals['impressions'], $totals['matched']),
            ] : array_fill(0, 6, '—');
            echo '<div class="metric-grid">';
            foreach (['Estimated earnings', 'Impressions', 'Ad requests', 'Blended eCPM', 'Match rate', 'Show rate'] as $i => $label) {
                echo '<article class="metric-card"><p>' . h($label) . '</p><strong>' . h($values[$i]) . '</strong></article>';
            }
            echo '</div><div class="analytics-grid"><section><div class="toolbar-actions"><h2>Daily performance</h2>
                <a href="/admin/reports?group=DATE">Daily report</a></div><p class="muted">Estimated earnings</p>';
            earningsChart($days);
            echo '</section><section><h2>Ad delivery funnel</h2>';
            $previous = $totals['requests'] ?? 0;
            foreach (['requests' => 'Ad requests', 'matched' => 'Matched requests', 'impressions' => 'Impressions', 'clicks' => 'Clicks'] as $key => $label) {
                $count = $totals[$key] ?? 0;
                echo '<div class="funnel-step"><div class="toolbar-actions"><span>' . h($label) . '</span><strong>' .
                    ($totals ? number_format($count) : '—') . '</strong></div><progress aria-label="' . h($label) .
                    '" value="' . min($count, $previous) . '" max="' . max(1, $previous) . '"></progress><small>' .
                    reportRate($count, $previous) . '</small></div>';
                $previous = $count;
            }
            echo '</section></div>';
            overviewBreakdown('Performance by format', 'FORMAT', $formats, $currency);
            overviewBreakdown('Top countries', 'COUNTRY', $countries, $currency);
        });
}

function earningsChart(array $series): void
{
    if (count($series) < 2) {
        echo '<p class="empty-state">Generate a daily report with at least two days of activity to see the chart.</p>';
        return;
    }
    $low = min(0, min($series));
    $span = max(1, max($series) - $low);
    $points = [];
    foreach ($series as $i => $value) {
        $points[] = round(12 + $i * 576 / (count($series) - 1), 2) . ',' . round(178 - 156 * ($value - $low) / $span, 2);
    }
    echo '<svg class="earnings-chart" viewBox="0 0 600 195" role="img" aria-label="Daily estimated earnings trend">
          <path class="chart-grid" d="M12 22H588 M12 74H588 M12 126H588 M12 178H588"/>
          <polyline class="chart-line" points="' . h(implode(' ', $points)) . '"/></svg>';
}

function overviewBreakdown(string $title, string $dimension, array $rows, string $currency): void
{
    echo '<section><div class="toolbar-actions"><h2>' . h($title) . '</h2><a href="/admin/reports?group=' . h($dimension) .
        '">Full report</a></div>';
    if (!$rows) {
        echo '<p class="muted">Generate this breakdown in Reports to see results here.</p></section>';
        return;
    }
    echo '<div class="table-scroll"><table><thead><tr><th scope="col">Name</th><th scope="col">Est. earnings (' . h($currency) .
        ')</th><th scope="col">Impressions</th><th scope="col">eCPM</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $ecpm = $row['impressions'] ? number_format($row['earnings'] / 1000 / $row['impressions'], 2) : '—';
        echo '<tr><th scope="row">' . h($row['label']) . '</th><td>' . number_format($row['earnings'] / 1000000, 2) .
            '</td><td>' . number_format($row['impressions']) . '</td><td>' . h($ecpm) . '</td></tr>';
    }
    echo '</tbody></table></div></section>';
}

function reportRate(int $count, int $total): string
{
    return $total > 0 ? number_format($count / $total * 100, 2) . '%' : '—';
}

function placementsPage(array $admin, array $current, ?array $proposed, bool $saved = false): void
{
    $title = $proposed === null ? 'Edit Android banner' : 'Review banner changes';
    adminPage($title, 'Manage the fixed Android banner slot and its PHP configuration.', 'placements', $admin,
        function () use ($current, $proposed, $saved) {
            $version = (int)$current['version'];
            if ($saved) {
                echo '<p class="notice" role="status">Banner configuration published. The endpoint is now on version ' . h($version) . '.</p>';
            }
            echo '<p class="notice warning">Publishing updates the Android banner endpoint. This Unity test build accepts Google’s fixed banner test ad unit.</p>
                <div class="placement-grid"><section><h2>Android banner configuration</h2><p><span class="tag">Version ' . h($version) .
                '</span></p><form method="post" action="/admin">';
            csrf();
            echo '<input type="hidden" name="version" value="' . h($version) . '">';
            if ($proposed === null) {
                echo '<label class="check"><input type="checkbox" role="switch" name="enabled" value="1"' .
                    ($current['banner_enabled'] ? ' checked' : '') . '> Enable Android banner</label>
                    <label>Android banner ad unit ID<input required name="unit" maxlength="80" pattern="ca-app-pub-[0-9]+/[0-9]+" value="' .
                    h($current['banner_ad_unit_id']) . '"></label>
                    <p class="muted">The AdMob App ID is managed in Unity’s Google Mobile Ads settings.</p>
                    <button name="action" value="review-banner">Review changes</button>';
            } else {
                [$enabled, $unit] = $proposed;
                echo '<dl class="detail-list"><div><dt>Banner status</dt><dd>' . ($current['banner_enabled'] ? 'Enabled' : 'Disabled') .
                    ' → ' . ($enabled ? 'Enabled' : 'Disabled') . '</dd></div><div><dt>Current unit</dt><dd>' .
                    h($current['banner_ad_unit_id']) . '</dd></div><div><dt>Proposed unit</dt><dd>' . h($unit) . '</dd></div></dl>
                    <input type="hidden" name="enabled" value="' . ($enabled ? '1' : '0') . '">
                    <input type="hidden" name="unit" value="' . h($unit) . '">
                    <div class="toolbar-actions"><button name="action" value="banner">Publish configuration</button>
                    <a class="button secondary" href="/admin?view=placements">Cancel</a></div>';
            }
            echo '</form></section><div class="stack"><section><h2>Fixed Android slot</h2><p class="muted">320 × 50 · Placement preview</p>
                  <div class="pitch"><span>HOME <b>2 : 1</b> AWAY</span><div class="pitch-lines" aria-hidden="true"></div>
                  <div class="banner-preview"><strong>Android banner</strong><span>320 × 50</span></div></div></section>
                  <section><h2>Config response</h2><dl class="detail-list"><div><dt>Platform</dt><dd>Android</dd></div>
                  <div><dt>Current version</dt><dd>' . h($version) . '</dd></div></dl>
                  <a href="/api/v1/ads/config?platform=android">Open PHP config endpoint</a></section></div></div>';
        });
}

function infoPage(array $admin, string $view): void
{
    $titles = [
        'revenue' => ['Revenue', 'Review estimated earnings for Football Android.'],
        'traffic' => ['Traffic', 'See where ad activity comes from and what player analytics still need.'],
        'formats' => ['Ad Formats', 'See the six formats supported by the Unity game.'],
        'diagnostics' => ['Diagnostics', 'Check the banner endpoint and reporting connection.'],
        'settings' => ['Settings', 'Manage your game’s reporting and configuration sources.'],
    ];
    [$title, $description] = $titles[$view];
    adminPage($title, $description, $view, $admin, function () use ($view) {
        if ($view === 'revenue') {
            $cached = $_SESSION['last_admob_report'] ?? null;
            $report = $cached['data'] ?? null;
            echo '<section><div class="toolbar-actions"><h2>Estimated earnings</h2><a class="button" href="/admin/reports?group=DATE">Daily earnings report</a></div>';
            if (!$report) {
                echo '<p class="empty-state">Generate an AdMob report to see earnings here.</p>';
            } else {
                echo '<p>' . h($report['currency']) . ' <strong>' . number_format($report['totals']['earnings'] / 1000000, 2) .
                    '</strong> · ' . h($report['period']) . '</p>';
                if ($cached['dimension'] === 'DATE') {
                    earningsChart(array_column($report['rows'], 'earnings'));
                }
            }
            echo '</section>';
            if ($report && $cached['dimension'] !== 'DATE') {
                overviewBreakdown('Earnings breakdown', $cached['dimension'], $report['rows'], $report['currency']);
            }
            return;
        }
        if ($view === 'formats') {
            $formats = [
                'Banner' => 'Fixed Android slot. The enable switch and ad unit ID are available in PHP.',
                'Interstitial' => 'Full-screen match breaks. Placement rules are set in Unity.',
                'Rewarded' => 'Optional reward offers. Reward events require game telemetry.',
                'Rewarded interstitial' => 'Rewarded full-screen breaks. Placement rules are set in Unity.',
                'Native' => 'Native overlay. Template and placement are set in Unity.',
                'App open' => 'App launch and foreground placements. Timing is set in Unity.',
            ];
            echo '<div class="metric-grid">';
            foreach ($formats as $name => $description) {
                echo '<section><h2>' . h($name) . '</h2><p>' . h($description) . '</p><a href="' .
                    ($name === 'Banner' ? '/admin?view=placements">Edit banner' : '/admin/reports?group=FORMAT">View format reporting') . '</a></section>';
            }
            echo '</div>';
            return;
        }
        if ($view === 'traffic') {
            echo '<div class="analytics-grid"><section><h2>Ad traffic</h2><p>See requests, matches, impressions and clicks from the AdMob Network.</p>
                  <a class="button" href="/admin/reports?group=COUNTRY">Open country report</a></section>
                  <section><h2>Player traffic</h2><p class="notice">Analytics connection required</p>
                  <p>Active players, sessions and retention require a game analytics source.</p></section></div>';
            return;
        }
        $configured = admobConfigured();
        echo '<div class="analytics-grid"><section><h2>AdMob Network</h2><p class="notice' . ($configured ? '' : ' warning') . '">' .
            ($configured ? 'Reporting credentials configured. Generate a report to check the connection.' : 'Reporting disconnected.') .
            '</p><a href="/admin/reports">Open reports</a></section><section><h2>Android banner</h2>
            <p>Inspect the PHP settings returned to the Unity app.</p>
            <a href="/api/v1/ads/config?platform=android">Check config endpoint</a></section></div>';
        if ($view === 'diagnostics') {
            echo '<p class="notice">Device config fetches, SDK errors, and rewarded completions require Unity telemetry.</p>';
        }
    });
}

function dashboard(array $admin, ?array $selected, bool $auditOnly): void
{
    if ($auditOnly) {
        $history = query('SELECT audit_log.*, users.email FROM audit_log JOIN users ON users.id=audit_log.admin_id
                          ORDER BY audit_log.id DESC LIMIT 30')->fetchAll();
        adminPage('Audit History', 'Recent changes to tournaments and ad configuration.', 'audit', $admin, function () use ($history) {
            echo '<section><h2>Recent activity</h2><ul>';
            foreach ($history as $item) {
                echo '<li><strong>' . h($item['action']) . '</strong> · ' . h($item['email']) . ' · ' . h($item['created_at']) .
                    '<details><summary>Details</summary><pre>' . h($item['details']) . '</pre></details></li>';
            }
            if (!$history) {
                echo '<li>No recorded changes yet.</li>';
            }
            echo '</ul></section>';
        });
        return;
    }
    $events = query('SELECT tournaments.*, (SELECT COUNT(*) FROM entries WHERE tournament_id=tournaments.id) AS entrants
                    FROM tournaments ORDER BY id DESC LIMIT 50')->fetchAll();
    $pending = query('SELECT attempts.*,matches.player_one,matches.player_two,matches.round_number,tournaments.name
                     FROM attempts JOIN matches ON matches.id=attempts.match_id
                     JOIN tournaments ON tournaments.id=matches.tournament_id
                     WHERE attempts.request_id IS NOT NULL AND matches.winner_id IS NULL ORDER BY attempts.match_id LIMIT 100')->fetchAll();
    adminPage('Tournaments', 'Manage enrollment, brackets, and result review.', 'tournaments', $admin, function () use ($events, $pending, $selected) {
        echo '<section id="tournaments"><h2>Manage tournaments</h2><form method="post" action="/admin" class="inline">';
        csrf();
        echo '<input type="hidden" name="action" value="create"><label>Tournament name<input required name="name" maxlength="120"></label>
              <button>Create draft</button></form><ul>';
        foreach ($events as $event) {
            echo '<li><a href="/admin?tournament=' . h($event['id']) . '#bracket">' . h($event['name']) .
                '</a> <span class="tag">' . h($event['status']) . '</span> · ' .
                h($event['entrants']) . ' entrants';
            if ($event['winner_id']) {
                echo ' · Winner #' . h($event['winner_id']);
            }
            if ($event['status'] === 'draft' || $event['status'] === 'open') {
                $draft = $event['status'] === 'draft';
                actionForm($draft ? 'publish' : 'start', (int)$event['id']);
                echo '<button>' . ($draft ? 'Open enrollment' : 'Generate bracket') . '</button></form>';
            }
            echo '</li>';
        }
        echo '</ul></section>';
        if ($selected !== null) {
            renderBracket($selected);
        }
        echo '<section id="results"><h2>Result review</h2>';
        if (!$pending) {
            echo '<p>No submissions awaiting review.</p>';
        }
        foreach ($pending as $result) {
            echo '<article><strong>' . h($result['name']) . '</strong> · Round ' . h($result['round_number']) . ' · Match #' . h($result['match_id'])
                . '<p>Player #' . h($result['player_one']) . ' ' . h($result['score_one']) . '–' . h($result['score_two'])
                . ' Player #' . h($result['player_two']) . ' · Submitted by #' . h($result['user_id']) . '</p>';
            echo '<form method="post" class="inline">';
            csrf();
            echo '<input type="hidden" name="id" value="' . h($result['match_id']) . '">
                  <input type="hidden" name="attempt" value="' . h($result['id']) . '">
                  <label>Review note<input required name="reason" minlength="3" maxlength="500"></label>
                  <button name="action" value="approve">Approve and advance</button>
                  <button name="action" value="reject">Reject and allow retry</button></form></article>';
        }
        echo '</section>';
    });
}

function renderAdmobPage(array $admin, ?array $report, int $days, string $dimension): void
{
    adminPage('Reports', 'Build a report from a clearly identified source.', 'reports', $admin, function () use ($report, $days, $dimension) {
        echo '<section class="filter-bar"><p><strong>Source</strong> · AdMob Network · Pacific time</p></section>';
        echo '<nav class="report-presets" aria-label="Report presets">';
        $presets = ['DATE' => 'Daily earnings', 'FORMAT' => 'Revenue by format', 'COUNTRY' => 'Country performance', 'AD_UNIT' => 'Ad delivery by unit'];
        foreach ($presets as $value => $label) {
            echo '<a class="button' . ($value === $dimension ? '' : ' secondary') . '" href="/admin/reports?group=' .
                h($value) . '">' . h($label) . '</a>';
        }
        echo '</nav><div class="report-grid"><section><h2>Build report</h2>';
        if (!admobConfigured()) {
            echo '<p class="notice warning">Reporting is disconnected.</p><p>Configure read-only AdMob credentials on the server.</p>
                <a href="/admin?view=settings">Connection status</a></section>
                <section><h2>' . h($presets[$dimension]) . '</h2><p class="empty-state">No report is available until AdMob is connected.</p></section></div>';
            return;
        }
        echo '<form method="post" action="/admin/reports">';
        csrf();
        echo '<label>Period<select name="range">';
        foreach ([7, 30] as $range) {
            echo '<option value="' . $range . '"' . ($range === $days ? ' selected' : '') . '>Last ' . $range . ' complete days</option>';
        }
        echo '</select></label><label>Breakdown<select name="group">';
        foreach (['DATE' => 'Date', 'FORMAT' => 'Ad format', 'COUNTRY' => 'Country', 'AD_UNIT' => 'Ad unit'] as $value => $label) {
            echo '<option value="' . $value . '"' . ($value === $dimension ? ' selected' : '') . '>' . h($label) . '</option>';
        }
        echo '</select></label><button>Generate report</button></form></section>';
        if ($report === null) {
            echo '<section><h2>' . h($presets[$dimension]) . '</h2><p class="empty-state">Choose a period and generate a report.</p></section></div>';
            return;
        }
        echo '<section><div class="toolbar-actions"><h2>' . h($presets[$dimension]) . '</h2>
              <a class="button secondary" href="/admin/reports?download=csv">Download CSV</a></div><p>' .
              h($report['period']) . ' · ' . h($report['currency']) . '</p>';
        if ($report['warnings']) {
            echo '<p role="status">Google flagged this report. Check AdMob before relying on the totals.</p>';
        }
        if (!$report['rows']) {
            echo '<p>AdMob returned no activity for this app and period.</p></section></div>';
            return;
        }
        echo '<div class="table-scroll"><table><thead><tr><th scope="col">Breakdown</th><th scope="col">Est. earnings</th>
            <th scope="col">Requests</th><th scope="col">Matched</th><th scope="col">Impressions</th><th scope="col">Clicks</th>
            <th scope="col">eCPM</th><th scope="col">Match rate</th><th scope="col">Show rate</th><th scope="col">CTR</th></tr></thead><tbody>';
        foreach ($report['rows'] as $item) {
            renderReportRow($item['label'], $item);
        }
        echo '</tbody><tfoot>';
        renderReportRow('Total', $report['totals']);
        echo '</tfoot></table></div></section></div>';
    });
}

function renderReportRow(string $label, array $counts): void
{
    $ratio = static fn ($count, $total) => $total > 0 ? number_format($count / $total * 100, 2) . '%' : '—';
    $earnings = $counts['earnings'] / 1000000;
    $cells = [
        number_format($earnings, 2),
        number_format($counts['requests']),
        number_format($counts['matched']),
        number_format($counts['impressions']),
        number_format($counts['clicks']),
        $counts['impressions'] > 0 ? number_format($earnings / $counts['impressions'] * 1000, 2) : '—',
        $ratio($counts['matched'], $counts['requests']),
        $ratio($counts['impressions'], $counts['matched']),
        $ratio($counts['clicks'], $counts['impressions']),
    ];
    echo '<tr><th scope="row">' . h($label) . '</th>';
    foreach ($cells as $cell) {
        echo '<td>' . h($cell) . '</td>';
    }
    echo '</tr>';
}

function renderBracket(array $event): void
{
    echo '<section id="bracket"><h2>' . h($event['name']) . '</h2><h3>Entrants</h3><ul>';
    $names = [];
    foreach ($event['entrants'] as $entrant) {
        $names[$entrant['id']] = $entrant['email'];
        echo '<li>#' . h($entrant['id']) . ' · ' . h($entrant['email']) . '</li>';
    }
    if (!$names) {
        echo '<li>No entries yet.</li>';
    }
    echo '</ul><h3>Bracket</h3><ul>';
    foreach ($event['matches'] as $match) {
        echo '<li>Round ' . h($match['round_number']) . ' · Match #' . h($match['id']) . ' · '
            . h($names[$match['player_one']] ?? 'TBD') . ' vs ' . h($names[$match['player_two']] ?? 'TBD');
        if ($match['winner_id']) {
            echo ' · ' . h($match['score_one']) . '–' . h($match['score_two']) .
                ' · Winner ' . h($names[$match['winner_id']]);
        }
        echo '</li>';
    }
    if (!$event['matches']) {
        echo '<li>Bracket pending.</li>';
    }
    echo '</ul></section>';
}
