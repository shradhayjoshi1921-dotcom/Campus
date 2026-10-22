<?php
declare(strict_types=1);

/**
 * Environment self-check. Run it from the project root:
 *
 *   php scripts/doctor.php                    (or: C:\xampp\php\php.exe scripts\doctor.php)
 *   php scripts/doctor.php --no-db            skip the database section
 *
 * Read-only: it never writes a row, never creates a table and never prints a
 * password. Exit code 0 means the required checks passed; 1 means at least one
 * required item is missing, and the output says which.
 */

require dirname(__DIR__) . '/bootstrap/app.php';

use App\Core\Database;
use App\Core\Env;

$required = 0;
$failures = 0;
$skipDatabase = in_array('--no-db', $argv ?? [], true);

function doctor_line(string $label, string $value, bool $ok, bool $required = true): void
{
    global $failures;
    if (!$ok && $required) {
        $failures++;
    }
    $mark = $ok ? 'OK  ' : ($required ? 'FAIL' : 'note');
    printf("[%s] %-22s %s\n", $mark, $label, $value);
}

echo "Campus environment check\n";
echo str_repeat('-', 68), "\n";

// 1. PHP itself --------------------------------------------------------------
$phpOk = PHP_VERSION_ID >= 80000;
doctor_line('PHP version', PHP_VERSION . ($phpOk ? '' : ' (8.0 or newer required)'), $phpOk);
doctor_line('PHP binary', PHP_BINARY, true, false);
doctor_line('Interface', PHP_SAPI === 'cli' ? 'command line' : PHP_SAPI, true, false);

// 2. Extensions --------------------------------------------------------------
foreach (['pdo' => true, 'pdo_mysql' => true, 'mbstring' => true, 'json' => true] as $extension => $isRequired) {
    $loaded = extension_loaded($extension);
    doctor_line('Extension: ' . $extension, $loaded ? 'loaded' : 'missing', $loaded, $isRequired);
}
foreach (['zlib' => 'PDF text extraction', 'sqlite3' => 'not used', 'curl' => 'optional AI provider', 'openssl' => 'optional AI provider'] as $extension => $purpose) {
    $loaded = extension_loaded($extension);
    doctor_line('Extension: ' . $extension, $loaded ? 'loaded' : 'missing (' . $purpose . ')', $loaded, false);
}

// 3. Configuration -----------------------------------------------------------
$root = dirname(__DIR__);
$envPath = $root . '/.env';
$envExists = is_file($envPath);
doctor_line('.env file', $envExists ? 'found' : 'not found — defaults from .env.example are used', $envExists, false);
doctor_line('DB host / name', (Env::get('DB_HOST', '127.0.0.1') ?? '') . ' / ' . (Env::get('DB_NAME', 'ai_campus_portal') ?? ''), true, false);
doctor_line('DB user', Env::get('DB_USER', 'root') ?? 'root', true, false);
doctor_line('DB password', (Env::get('DB_PASS', '') ?? '') === '' ? 'empty (XAMPP default)' : 'set, not printed', true, false);
doctor_line('AI provider', (Env::get('AI_ENABLED', 'false') ?? 'false') === 'true' ? 'enabled' : 'disabled — rules fallback everywhere', true, false);
doctor_line('APP_DEBUG', Env::get('APP_DEBUG', 'false') ?? 'false', true, false);

// 4. Writable upload directory ----------------------------------------------
$uploads = $root . '/storage/uploads/resumes';
doctor_line('Resume folder', is_dir($uploads) ? (is_writable($uploads) ? 'writable' : 'not writable') : 'missing', is_dir($uploads) && is_writable($uploads), false);

// 5. Database ---------------------------------------------------------------
$pdo = null;
if ($skipDatabase) {
    doctor_line('Database connection', 'skipped (--no-db)', true, false);
} elseif (!extension_loaded('pdo') || !extension_loaded('pdo_mysql')) {
    // Never even construct PDO without its driver: some builds abort the
    // process instead of throwing, which would hide every line below.
    doctor_line('Database connection', 'skipped — PDO MySQL is not loaded', false);
} else {
    try {
        $pdo = Database::connection();
        doctor_line('Database connection', 'connected', true);
    } catch (Throwable $exception) {
        doctor_line('Database connection', $exception->getMessage(), false);
    }
}

if ($pdo instanceof PDO) {
    // Required tables come from schema.sql, so this list cannot drift.
    $schema = (string) file_get_contents($root . '/database/schema.sql');
    preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)?\s+`?([a-z_]+)`?/i', $schema, $matches);
    $expected = array_values(array_unique($matches[1] ?? []));
    $missing = [];
    foreach ($expected as $table) {
        try {
            $pdo->query('SELECT 1 FROM `' . $table . '` LIMIT 1');
        } catch (Throwable $exception) {
            $missing[] = $table;
        }
    }
    doctor_line(
        'Schema tables',
        $missing === [] ? count($expected) . ' present' : 'missing: ' . implode(', ', $missing) . ' — import database/schema.sql',
        $missing === [],
    );

    $pending = [];
    $applied = [];
    try {
        $applied = $pdo->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $exception) {
        // No runner has been used yet on this database.
    }
    foreach (glob($root . '/database/migrations/*.sql') ?: [] as $file) {
        if (!in_array(basename($file), $applied, true)) {
            $pending[] = basename($file);
        }
    }
    doctor_line(
        'Migrations',
        $pending === [] ? 'all applied' : count($pending) . ' pending — run php scripts/migrate.php',
        $pending === [],
        false,
    );
    foreach ($pending as $file) {
        doctor_line('  pending', $file, true, false);
    }

    try {
        $admins = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('admin','tpo')")->fetchColumn();
        doctor_line('Admin account', $admins > 0 ? $admins . ' present' : 'none — run scripts/create-admin.php', $admins > 0, false);
    } catch (Throwable $exception) {
        doctor_line('Admin account', 'could not read users table', true, false);
    }
}

echo str_repeat('-', 68), "\n";
if ($failures === 0) {
    echo "Required checks passed. Open http://localhost/Campus/public/ in the browser.\n";
    exit(0);
}
echo $failures . " required check(s) failed. Fix the FAIL lines above, then run this again.\n";
exit(1);
