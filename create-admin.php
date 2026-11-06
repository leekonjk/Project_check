<?php
declare(strict_types=1);

require __DIR__ . '/app.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

try {
    $email = email(['email' => $argv[1] ?? null]);
    $password = field(['password' => rtrim(stream_get_contents(STDIN, 131), "\r\n")], 'password', 12, 128);
    query('INSERT INTO users (email,password_hash,is_admin) VALUES (?,?,TRUE)',
        [$email, password_hash($password, PASSWORD_ARGON2ID)]);
    fwrite(STDOUT, "Admin created.\n");
} catch (Throwable $error) {
    fwrite(STDERR, $error instanceof HttpError ? $error->getMessage() . "\n" : "Could not create admin. Use a new email and check the database connection.\n");
    exit(1);
}
