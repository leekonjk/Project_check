<?php
declare(strict_types=1);

function audit(int $admin, string $action, array $details): void
{
    query('INSERT INTO audit_log (admin_id,action,details) VALUES (?,?,?)',
        [$admin, $action, json_encode($details, JSON_THROW_ON_ERROR)]);
}

function formId(string $name): int
{
    $id = filter_var($_POST[$name] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $id ?: fail(422, 'Invalid identifier.');
}

function adminAction(int $admin): void
{
    transaction(function () use ($admin) {
        switch ($_POST['action'] ?? '') {
            case 'banner':
                $enabled = ($_POST['enabled'] ?? '') === '1';
                $unit = field($_POST, 'unit', 1, 80);
                if (!preg_match('~^ca-app-pub-[0-9]+/[0-9]+$~D', $unit)) {
                    fail(422, 'Invalid banner ad unit ID.');
                }
                $before = row('SELECT * FROM ad_settings WHERE id=1 FOR UPDATE');
                query('UPDATE ad_settings SET version=version+1,banner_enabled=?,banner_ad_unit_id=? WHERE id=1', [(int)$enabled, $unit]);
                audit($admin, 'banner.update', ['before' => $before, 'enabled' => $enabled, 'unit' => $unit]);
                break;
            case 'create':
                $name = trim(field($_POST, 'name', 1, 120));
                if ($name === '') {
                    fail(422, 'Enter a tournament name.');
                }
                query('INSERT INTO tournaments (name) VALUES (?)', [$name]);
                audit($admin, 'tournament.create', ['id' => db()->lastInsertId(), 'name' => $name]);
                break;
            case 'publish':
                $id = formId('id');
                if (query("UPDATE tournaments SET status='open' WHERE id=? AND status='draft'", [$id])->rowCount() !== 1) {
                    fail(409, 'Only drafts can be published.');
                }
                audit($admin, 'tournament.publish', ['id' => $id]);
                break;
            case 'start':
                $id = formId('id');
                if (tournament($id, true)['status'] !== 'open') {
                    fail(409, 'Tournament enrollment must be open.');
                }
                $players = query('SELECT user_id FROM entries WHERE tournament_id=? ORDER BY user_id LIMIT 65', [$id])->fetchAll(PDO::FETCH_COLUMN);
                $count = count($players);
                if (!in_array($count, [2, 4, 8, 16, 32, 64], true)) {
                    fail(422, 'A bracket requires 2, 4, 8, 16, 32 or 64 players.');
                }
                shuffle($players);
                for ($round = 1, $size = intdiv($count, 2); $size > 0; $round++, $size = intdiv($size, 2)) {
                    for ($position = 0; $position < $size; $position++) {
                        query('INSERT INTO matches (tournament_id,round_number,position,player_one,player_two) VALUES (?,?,?,?,?)',
                            [$id, $round, $position, $round === 1 ? $players[2 * $position] : null,
                                $round === 1 ? $players[2 * $position + 1] : null]);
                    }
                }
                query("UPDATE tournaments SET status='running' WHERE id=?", [$id]);
                audit($admin, 'tournament.start', ['id' => $id, 'players' => $players]);
                break;
            case 'approve':
                approveResult($admin, formId('id'), field($_POST, 'attempt', 64, 64), field($_POST, 'reason', 3, 500));
                break;
            case 'reject':
                $id = formId('id');
                $attempt = field($_POST, 'attempt', 64, 64);
                $reason = trim(field($_POST, 'reason', 3, 500));
                if (strlen($reason) < 3) {
                    fail(422, 'Enter a review note.');
                }
                $match = row('SELECT * FROM matches WHERE id=? FOR UPDATE', [$id]) ?? fail(404, 'Match unavailable.');
                matchOpen($match);
                $submission = row('SELECT user_id,score_one,score_two FROM attempts WHERE id=? AND match_id=? AND request_id IS NOT NULL',
                    [$attempt, $id]) ?? fail(404, 'Result unavailable.');
                query('DELETE FROM attempts WHERE id=?', [$attempt]);
                audit($admin, 'match.reject', ['id' => $id, 'submission' => $submission, 'reason' => $reason]);
                break;
            default:
                fail(422, 'Unknown action.');
        }
    });
}

function approveResult(int $admin, int $id, string $attemptId, string $reason): void
{
    $ref = row('SELECT tournament_id FROM matches WHERE id=?', [$id]) ?? fail(404, 'Match unavailable.');
    $tournament = tournament((int)$ref['tournament_id'], true);
    $match = row('SELECT * FROM matches WHERE id=? FOR UPDATE', [$id]);
    matchOpen($match);
    $attempt = row('SELECT * FROM attempts WHERE id=? AND match_id=? AND request_id IS NOT NULL', [$attemptId, $id])
        ?? fail(404, 'Result unavailable.');
    $one = (int)$attempt['score_one'];
    $two = (int)$attempt['score_two'];
    if ($one === $two || strlen(trim($reason)) < 3) {
        fail(422, 'Approval requires a winner and a review note.');
    }
    $winner = $one > $two ? $match['player_one'] : $match['player_two'];
    query('UPDATE matches SET score_one=?,score_two=?,winner_id=? WHERE id=?', [$one, $two, $winner, $id]);
    $next = row('SELECT id FROM matches WHERE tournament_id=? AND round_number=? AND position=?',
        [$tournament['id'], $match['round_number'] + 1, intdiv((int)$match['position'], 2)]);
    if ($next) {
        $slot = $match['position'] % 2 === 0 ? 'player_one' : 'player_two';
        query("UPDATE matches SET $slot=? WHERE id=?", [$winner, $next['id']]);
    } else {
        query("UPDATE tournaments SET winner_id=?,status='finished' WHERE id=?", [$winner, $tournament['id']]);
    }
    audit($admin, 'match.approve', ['id' => $id, 'attempt' => $attemptId, 'winner' => $winner,
        'score_one' => $one, 'score_two' => $two, 'reason' => trim($reason)]);
}
