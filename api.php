<?php
declare(strict_types=1);

function api(string $path, string $method): never
{
    if (in_array($path, ['/api/v1/ads/config', '/api/v1/ads/config.php'], true)) {
        if ($method !== 'GET') {
            header('Allow: GET');
            fail(405, 'Method not allowed.');
        }
        if (($_GET['platform'] ?? '') !== 'android') {
            fail(400, 'Expected platform=android.');
        }
        $config = row('SELECT version,banner_enabled,banner_ad_unit_id FROM ad_settings WHERE id=1');
        if (!$config) {
            fail(503, 'Banner configuration unavailable.');
        }
        $config['banner_enabled'] = (bool)$config['banner_enabled'];
        $config['version'] = (int)$config['version'];
        json($config);
    }
    if ($method === 'POST') {
        switch ($path) {
            case '/api/v1/auth/register':
                register(body());
                json(['message' => 'Registration request processed.'], 202);
            case '/api/v1/auth/login':
                json(newTokens((int)credentials(body())['id']));
            case '/api/v1/auth/refresh':
                $token = field(body(), 'refresh_token', 64, 64);
                json(transaction(function () use ($token) {
                    $session = row('SELECT id,user_id FROM sessions WHERE refresh_hash=? AND refresh_until>UTC_TIMESTAMP() FOR UPDATE',
                        [hash('sha256', $token)]) ?? fail(401, 'Session expired.');
                    return newTokens((int)$session['user_id'], (int)$session['id']);
                }));
        }
    }
    $user = player();
    $userId = (int)$user['id'];
    if ($method === 'POST' && $path === '/api/v1/auth/logout') {
        query('DELETE FROM sessions WHERE id=?', [$user['session_id']]);
        json(['status' => 'signed_out']);
    }
    if ($method === 'GET' && $path === '/api/v1/me') {
        json(['id' => $userId, 'email' => $user['email']]);
    }
    if ($method === 'GET' && $path === '/api/v1/tournaments') {
        json(['tournaments' => query("SELECT id,name,status,winner_id FROM tournaments WHERE status<>'draft' ORDER BY id DESC")->fetchAll()]);
    }
    if (preg_match('~^/api/v1/tournaments/([1-9][0-9]{0,17})(/join)?$~D', $path, $parts)) {
        $id = (int)$parts[1];
        if ($method === 'POST' && isset($parts[2])) {
            transaction(function () use ($id, $userId) {
                if (tournament($id, true)['status'] !== 'open') {
                    fail(409, 'Enrollment is closed.');
                }
                if (row('SELECT 1 FROM entries WHERE tournament_id=? AND user_id=?', [$id, $userId])) {
                    return;
                }
                if ((int)row('SELECT COUNT(*) AS total FROM entries WHERE tournament_id=?', [$id])['total'] >= 64) {
                    fail(409, 'Tournament is full.');
                }
                query('INSERT INTO entries VALUES (?,?)', [$id, $userId]);
            });
            json(['status' => 'joined']);
        }
        if ($method === 'GET' && !isset($parts[2])) {
            json(transaction(function () use ($id, $userId) {
                $event = tournament($id, true);
                if ($event['status'] === 'draft') {
                    fail(404, 'Tournament unavailable.');
                }
                $matches = query('SELECT * FROM matches WHERE tournament_id=? ORDER BY round_number,position', [$id])->fetchAll();
                $next = null;
                foreach ($matches as $match) {
                    if (!$match['winner_id'] && $match['player_one'] && $match['player_two']
                        && in_array($userId, [(int)$match['player_one'], (int)$match['player_two']], true)) {
                        $next = $match['id'];
                        break;
                    }
                }
                $event['joined'] = row('SELECT 1 FROM entries WHERE tournament_id=? AND user_id=?', [$id, $userId]) !== null;
                $event['matches'] = $matches;
                $event['next_match_id'] = $next;
                return $event;
            }));
        }
    }
    if ($method === 'POST' && preg_match('~^/api/v1/matches/([1-9][0-9]{0,17})/(start|finish)$~D', $path, $parts)) {
        $id = (int)$parts[1];
        json($parts[2] === 'start' ? startMatch($id, $userId) : finishMatch($id, $userId, body()));
    }
    fail(404, 'Route unavailable.');
}
