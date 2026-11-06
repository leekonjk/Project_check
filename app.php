<?php
declare(strict_types=1);

final class HttpError extends RuntimeException {}

function fail(int $status, string $message): never
{
    throw new HttpError($message, $status);
}

function setting(string $key, string $default = ''): string
{
    $env = getenv($key);
    if ($env !== false) {
        return $env;
    }

    static $settings;
    $file = __DIR__ . '/config.local.php';
    $settings ??= is_file($file) ? require $file : [];
    return $settings[$key] ?? $default;
}

function db(): PDO
{
    static $pdo;
    return $pdo ??= new PDO(
        'mysql:host=' . setting('DB_HOST', '127.0.0.1') . ';dbname=' . setting('MYSQL_DATABASE') . ';charset=utf8mb4',
        setting('MYSQL_USER'),
        setting('MYSQL_PASSWORD'),
        [PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

function query(string $sql, array $params = []): PDOStatement
{
    $statement = db()->prepare($sql);
    $statement->execute($params);
    return $statement;
}

function row(string $sql, array $params = []): ?array
{
    return query($sql, $params)->fetch() ?: null;
}

function transaction(callable $work): mixed
{
    db()->beginTransaction();
    try {
        $result = $work();
        db()->commit();
        return $result;
    } catch (Throwable $error) {
        db()->rollBack();
        throw $error;
    }
}

function json(array $body, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_THROW_ON_ERROR);
    exit;
}

function body(): array
{
    if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
        fail(415, 'Expected JSON.');
    }
    $data = json_decode(file_get_contents('php://input', false, null, 0, 16385), true);
    if (!is_array($data)) {
        fail(400, 'Invalid JSON.');
    }
    return $data;
}

function field(array $data, string $name, int $min, int $max): string
{
    $value = $data[$name] ?? null;
    if (!is_string($value) || strlen($value) < $min || strlen($value) > $max) {
        fail(422, "Invalid $name.");
    }
    return $value;
}

function email(array $data): string
{
    $email = strtolower(trim(field($data, 'email', 3, 254)));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail(422, 'Invalid email address.');
    }
    return $email;
}

function register(array $data): void
{
    $email = email($data);
    $hash = password_hash(field($data, 'password', 12, 128), PASSWORD_ARGON2ID);
    // Duplicate registrations return the same response.
    query('INSERT INTO users (email,password_hash) VALUES (?,?) ON DUPLICATE KEY UPDATE id=id', [$email, $hash]);
}

function credentials(array $data): array
{
    $user = row('SELECT * FROM users WHERE email=?', [email($data)]);
    $password = field($data, 'password', 1, 128);
    $hash = $user['password_hash'] ?? '$argon2id$v=19$m=65536,t=4,p=1$eWZSV1NqdXF2OWh3N2Y0dQ$TRqXL1aNsdQ+PoTI1YGVukBz5vBTcZNpOjsrYvjOwJo';
    if (!password_verify($password, $hash) || !$user) {
        fail(401, 'Invalid credentials.');
    }
    return $user;
}

function startWebSession(string $role, bool $secure): void
{
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name(($secure ? '__Host-' : '') . 'football_' . $role);
    session_set_cookie_params(['path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
    if (!session_start()) {
        fail(503, 'Could not start your session.');
    }
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function checkWebForm(): void
{
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
        fail(403, 'Your form expired. Reload the page and try again.');
    }
}

function endWebSession(): void
{
    $cookie = session_get_cookie_params();
    $_SESSION = [];
    session_destroy();
    setcookie(session_name(), '', ['expires' => 1, 'path' => '/', 'secure' => $cookie['secure'],
        'httponly' => true, 'samesite' => 'Strict']);
}

function playerWeb(string $path, string $method, bool $secure): never
{
    startWebSession('player', $secure);
    if ($method === 'POST') {
        checkWebForm();
    }
    if ($path === '/logout') {
        if ($method !== 'POST') {
            fail(405, 'Method not allowed.');
        }
        endWebSession();
        redirect('/login');
    }

    $account = null;
    if (isset($_SESSION['player']) && ($_SESSION['expires'] ?? 0) > time()) {
        $account = row('SELECT id,email FROM users WHERE id=? AND is_admin=FALSE', [$_SESSION['player']]);
    }
    if ($path === '/account') {
        if ($method !== 'GET') {
            fail(405, 'Method not allowed.');
        }
        if (!$account) {
            redirect('/login');
        }
        playerAccountPage($account);
        exit;
    }
    if ($account) {
        redirect('/account');
    }

    $signUp = $path === '/register';
    $error = '';
    if ($method === 'POST') {
        try {
            if ($signUp) {
                $password = field($_POST, 'password', 12, 128);
                if ($password !== ($_POST['confirm_password'] ?? null)) {
                    fail(422, 'Passwords must match.');
                }
                register($_POST);
                $_SESSION['registered'] = true;
                redirect('/login');
            }
            $user = credentials($_POST);
            if ($user['is_admin']) {
                fail(401, 'Invalid credentials.');
            }
            if (!session_regenerate_id(true)) {
                fail(503, 'Could not start your session.');
            }
            $_SESSION = ['player' => (int)$user['id'], 'expires' => time() + 3600, 'csrf' => bin2hex(random_bytes(32))];
            redirect('/account');
        } catch (HttpError $failure) {
            if (!in_array($failure->getCode(), [401, 422], true)) {
                throw $failure;
            }
            http_response_code($failure->getCode());
            $error = $failure->getMessage();
        }
    }
    $registered = !$signUp && !empty($_SESSION['registered']);
    unset($_SESSION['registered']);
    playerAuthPage($signUp, $error, $registered);
    exit;
}

const PLAYER_ACCESS_SECONDS = 900;
const PLAYER_JWT_HEADER = '{"alg":"HS256","typ":"at+jwt"}';
const PLAYER_JWT_AUDIENCE = 'football-player-api';

function playerJwtKey(): ?string
{
    $hex = setting('JWT_SIGNING_KEY');
    if ($hex === '') {
        return null;
    }
    if (!preg_match('/^[a-f0-9]{64}$/iD', $hex) || setting('APP_ORIGIN') === '') {
        fail(503, 'Player token signing is not configured correctly.');
    }
    return hex2bin($hex);
}

function jwtBase64(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function signPlayerAccess(int $userId, string $key, int $now): string
{
    $claims = jwtBase64(json_encode([
        'iss' => setting('APP_ORIGIN'), 'aud' => PLAYER_JWT_AUDIENCE, 'sub' => (string)$userId,
        'iat' => $now, 'exp' => $now + PLAYER_ACCESS_SECONDS, 'jti' => bin2hex(random_bytes(16)),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    $message = jwtBase64(PLAYER_JWT_HEADER) . '.' . $claims;
    return $message . '.' . jwtBase64(hash_hmac('sha256', $message, $key, true));
}

function verifyPlayerAccess(string $token, string $key, int $now): string
{
    $parts = explode('.', $token);
    if (count($parts) !== 3 || $parts[0] !== jwtBase64(PLAYER_JWT_HEADER) || strlen($parts[1]) > 1024) {
        fail(401, 'Session expired.');
    }
    [$header, $payload, $signature] = $parts;
    $expected = jwtBase64(hash_hmac('sha256', $header . '.' . $payload, $key, true));
    if (!hash_equals($expected, $signature)) {
        fail(401, 'Session expired.');
    }
    $decoded = base64_decode(strtr($payload, '-_', '+/'), true);
    $claims = $decoded === false ? null : json_decode($decoded, true, 8);
    if (!is_array($claims) || ($claims['iss'] ?? null) !== setting('APP_ORIGIN')
        || ($claims['aud'] ?? null) !== PLAYER_JWT_AUDIENCE
        || !is_string($claims['sub'] ?? null) || !preg_match('/^[1-9][0-9]*$/D', $claims['sub'])
        || !is_int($claims['iat'] ?? null) || !is_int($claims['exp'] ?? null)
        || $claims['iat'] > $now || $claims['exp'] <= $now || $claims['exp'] - $claims['iat'] !== PLAYER_ACCESS_SECONDS
        || !is_string($claims['jti'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $claims['jti'])) {
        fail(401, 'Session expired.');
    }
    return $claims['sub'];
}

function newTokens(int $userId, ?int $sessionId = null): array
{
    $key = playerJwtKey();
    $access = $key === null ? bin2hex(random_bytes(32)) : signPlayerAccess($userId, $key, time());
    $refresh = bin2hex(random_bytes(32));
    $values = [hash('sha256', $access), hash('sha256', $refresh)];
    if ($sessionId === null) {
        query('INSERT INTO sessions (access_hash,refresh_hash,user_id,access_until,refresh_until)
               VALUES (?,?,?,UTC_TIMESTAMP()+INTERVAL 15 MINUTE,UTC_TIMESTAMP()+INTERVAL 30 DAY)',
            [...$values, $userId]);
    } else {
        query('UPDATE sessions SET access_hash=?,refresh_hash=?,access_until=UTC_TIMESTAMP()+INTERVAL 15 MINUTE WHERE id=?',
            [...$values, $sessionId]);
    }
    return ['access_token' => $access, 'refresh_token' => $refresh, 'expires_in' => PLAYER_ACCESS_SECONDS];
}

function player(): array
{
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer ([A-Za-z0-9_.-]{1,1200})$/iD', $authorization, $match)) {
        fail(401, 'Sign in to continue.');
    }
    $token = $match[1];
    $subject = null;
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
        $key = playerJwtKey() ?? fail(401, 'Session expired.');
        $subject = verifyPlayerAccess($token, $key, time());
    }
    $user = row('SELECT users.id,users.email,sessions.id AS session_id FROM sessions JOIN users ON users.id=sessions.user_id
                WHERE access_hash=? AND access_until>UTC_TIMESTAMP() AND refresh_until>UTC_TIMESTAMP()',
        [hash('sha256', $token)]) ?? fail(401, 'Session expired.');
    if ($subject !== null && $subject !== (string)$user['id']) {
        fail(401, 'Session expired.');
    }
    return $user;
}

function tournament(int $id, bool $lock = false): array
{
    return row('SELECT * FROM tournaments WHERE id=?' . ($lock ? ' FOR UPDATE' : ''), [$id])
        ?? fail(404, 'Tournament unavailable.');
}

function eligibleMatch(int $id, int $player): array
{
    $match = row('SELECT * FROM matches WHERE id=? FOR UPDATE', [$id]);
    if (!$match || !in_array($player, [(int)$match['player_one'], (int)$match['player_two']], true)) {
        fail(404, 'Match unavailable.');
    }
    return $match;
}

function matchOpen(array $match): void
{
    if (!$match['player_one'] || !$match['player_two'] || $match['winner_id']) {
        fail(409, 'Match is not open.');
    }
}

function startMatch(int $id, int $player): array
{
    return transaction(function () use ($id, $player) {
        matchOpen(eligibleMatch($id, $player));
        $existing = row('SELECT id,request_id,expires_at>UTC_TIMESTAMP() AS active FROM attempts WHERE match_id=? AND user_id=?',
            [$id, $player]);
        if ($existing && ($existing['active'] || $existing['request_id'])) {
            return ['attempt_id' => $existing['id']];
        }
        $attempt = bin2hex(random_bytes(32));
        query('DELETE FROM attempts WHERE match_id=? AND user_id=?', [$id, $player]);
        query('INSERT INTO attempts (id,match_id,user_id,expires_at) VALUES (?,?,?,UTC_TIMESTAMP()+INTERVAL 2 HOUR)',
            [$attempt, $id, $player]);
        return ['attempt_id' => $attempt];
    });
}

function finishMatch(int $id, int $player, array $data): array
{
    $attemptId = field($data, 'attempt_id', 64, 64);
    $requestId = field($data, 'request_id', 1, 64);
    $scores = [$data['score_one'] ?? null, $data['score_two'] ?? null];
    foreach ($scores as $score) {
        if (!is_int($score) || $score < 0 || $score > 99) {
            fail(422, 'Scores must be integers from 0 to 99.');
        }
    }
    return transaction(function () use ($id, $player, $attemptId, $requestId, $scores) {
        $match = eligibleMatch($id, $player);
        $attempt = row('SELECT *,expires_at>UTC_TIMESTAMP() AS active FROM attempts WHERE id=? AND match_id=? AND user_id=?',
            [$attemptId, $id, $player]);
        if (!$attempt) {
            fail(404, 'Attempt unavailable.');
        }
        if ($attempt['request_id'] !== null) {
            if ($attempt['request_id'] !== $requestId || [(int)$attempt['score_one'], (int)$attempt['score_two']] !== $scores) {
                fail(409, 'Result already submitted.');
            }
        } else {
            matchOpen($match);
            if (!$attempt['active']) {
                fail(409, 'Attempt expired.');
            }
            query('UPDATE attempts SET request_id=?,score_one=?,score_two=? WHERE id=?', [$requestId, ...$scores, $attemptId]);
        }
        return ['status' => $match['winner_id'] ? 'finalized' : 'pending_review', 'winner_id' => $match['winner_id']];
    });
}
