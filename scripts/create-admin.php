<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this script from the command line only.\n");
}

require dirname(__DIR__) . '/bootstrap/app.php';

use App\Core\Database;

if ($argc !== 4) {
    fwrite(STDERR, "Usage: php scripts/create-admin.php \"Admin Name\" admin@example.com \"StrongPasswordAtLeast14\"\n");
    exit(2);
}

[, $name, $email, $password] = $argv;
$name = trim($name);
$email = strtolower(trim($email));
if ($name === '' || mb_strlen($name) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 14) {
    fwrite(STDERR, "Provide a valid name/email and a password of at least 14 characters.\n");
    exit(2);
}

try {
    $db = Database::connection();
    $db->beginTransaction();
    $existing = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $existing->execute([$email]);
    if ($existing->fetchColumn()) {
        $db->rollBack();
        fwrite(STDERR, "That email already belongs to an account. No changes made.\n");
        exit(1);
    }

    $count = (int) $db->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
    if ($count > 0) {
        $db->rollBack();
        fwrite(STDERR, "An administrator already exists; initial bootstrap is disabled.\n");
        exit(1);
    }

    $stmt = $db->prepare("INSERT INTO users (name,email,password_hash,role,status) VALUES (?,?,?,'admin','active')");
    $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
    $db->commit();
    echo "Initial administrator created successfully.\nEmail: {$email}\nKeep the password private.\n";
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, "Admin setup failed. Check .env, database/schema.sql, and MySQL service.\n");
    exit(1);
}
