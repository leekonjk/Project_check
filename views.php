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

function dashboard(array $admin, ?array $selected): void
{
    $ads = row('SELECT * FROM ad_settings WHERE id=1');
    $events = query('SELECT tournaments.*, (SELECT COUNT(*) FROM entries WHERE tournament_id=tournaments.id) AS entrants
                    FROM tournaments ORDER BY id DESC LIMIT 50')->fetchAll();
    $pending = query('SELECT attempts.*,matches.player_one,matches.player_two,matches.round_number,tournaments.name
                     FROM attempts JOIN matches ON matches.id=attempts.match_id
                     JOIN tournaments ON tournaments.id=matches.tournament_id
                     WHERE attempts.request_id IS NOT NULL AND matches.winner_id IS NULL ORDER BY attempts.match_id LIMIT 100')->fetchAll();
    $history = query('SELECT audit_log.*, users.email FROM audit_log JOIN users ON users.id=audit_log.admin_id
                      ORDER BY audit_log.id DESC LIMIT 30')->fetchAll();
    page('Tournament dashboard', function () use ($admin, $ads, $events, $pending, $history, $selected) {
        echo '<nav><a href="/admin/reports">AdMob reports</a><a href="#tournaments">Tournaments</a><a href="#results">Result review</a><a href="#ads">Ad settings</a><a href="#audit">Audit history</a></nav>
              <form method="post" action="/admin/logout" class="inline account">';
        csrf();
        echo '<span>' . h($admin['email']) . '</span><button>Sign out</button></form>
              <section id="tournaments"><h2>Tournaments</h2><form method="post" class="inline">';
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
        echo '</section><section id="ads"><h2>Ad settings</h2><p>Android banner · Config version ' . h($ads['version']) . '</p><form method="post">';
        csrf();
        echo '<input type="hidden" name="action" value="banner"><label class="check"><input type="checkbox" name="enabled" value="1" '
            . ($ads['banner_enabled'] ? 'checked' : '') . '> Banner enabled</label>
            <label>AdMob banner unit ID<input required name="unit" maxlength="80" pattern="ca-app-pub-[0-9]+/[0-9]+" value="' .
            h($ads['banner_ad_unit_id']) . '"></label><button>Save ad settings</button></form></section>
            <section id="audit"><h2>Audit history</h2><ul>';
        foreach ($history as $item) {
            echo '<li><strong>' . h($item['action']) . '</strong> · ' . h($item['email']) . ' · ' . h($item['created_at']) .
                '<details><summary>Details</summary><pre>' . h($item['details']) . '</pre></details></li>';
        }
        if (!$history) {
            echo '<li>No recorded changes yet.</li>';
        }
        echo '</ul></section>';
    });
}

function renderAdmobPage(?array $report, int $days, string $dimension): void
{
    page('AdMob reports', function () use ($report, $days, $dimension) {
        echo '<nav><a href="/admin">Dashboard</a></nav><section><h2>AdMob Network</h2>
            <p>Ad delivery and estimated earnings for the configured Android app.</p>';
        if (!admobConfigured()) {
            echo '<p>Reporting is disconnected. Configure read-only AdMob credentials on the server.</p></section>';
            return;
        }
        echo '<form class="inline" method="post" action="/admin/reports">';
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
            return;
        }
        echo '<section><h2>Report results</h2><p>' . h($report['period']) . ' · ' . h($report['currency']) . '</p>';
        if ($report['warnings']) {
            echo '<p role="status">Google flagged this report. Check AdMob before relying on the totals.</p>';
        }
        if (!$report['rows']) {
            echo '<p>AdMob returned no activity for this app and period.</p></section>';
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
        echo '</tfoot></table></div></section>';
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
