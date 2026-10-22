<?php
declare(strict_types=1);

/**
 * Applies database/migrations/*.sql in filename order.
 *
 * Safe to re-run: each file is recorded in `schema_migrations` once applied, and
 * the migrations themselves are additive (CREATE TABLE IF NOT EXISTS / guarded
 * ALTER), so nothing is dropped, truncated or rewritten.
 *
 * No transaction wraps a file: MySQL implicitly commits DDL, so pretending the
 * file is atomic would be a lie. Statements run in order, and the file is only
 * recorded after the last one succeeds — a failure leaves it pending, and the
 * next run repeats it harmlessly (the duplicate-column guard absorbs the parts
 * that already happened).
 *
 *   php scripts/migrate.php           apply pending files
 *   php scripts/migrate.php --status  list applied and pending files
 */

require dirname(__DIR__) . '/bootstrap/app.php';

use App\Core\Database;

$pdo = Database::connection();
$directory = dirname(__DIR__) . '/database/migrations';
$files = glob($directory . '/*.sql') ?: [];
sort($files);

try {
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (migration VARCHAR(190) PRIMARY KEY, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
    $applied = $pdo->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Could not read schema_migrations: ' . $exception->getMessage() . "\n");
    exit(1);
}

$statusOnly = in_array('--status', $argv, true);
$pending = array_values(array_filter($files, static fn (string $file): bool => !in_array(basename($file), $applied, true)));

if ($statusOnly) {
    foreach ($files as $file) {
        printf("%-46s %s\n", basename($file), in_array(basename($file), $applied, true) ? 'applied' : 'pending');
    }
    exit(0);
}

if ($pending === []) {
    echo "Nothing to apply. Database is up to date.\n";
    exit(0);
}

foreach ($pending as $file) {
    $sql = (string) file_get_contents($file);
    // Split on statement boundaries; the migration files contain no procedures
    // or string literals with semicolons.
    $statements = array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: []));
    try {
        foreach ($statements as $statement) {
            // Drop comment-only lines, then anything still empty.
            $lines = array_filter(
                array_map('trim', preg_split('/\R/', $statement) ?: []),
                static fn (string $line): bool => $line !== '' && !str_starts_with($line, '--'),
            );
            $statement = trim(implode("\n", $lines));
            if ($statement === '') {
                continue;
            }
            try {
                $pdo->exec($statement);
            } catch (PDOException $exception) {
                // Guarded back-fill: an ALTER on a column/table that already
                // exists is what "already applied" looks like on a re-run.
                $duplicate = in_array((int) ($exception->errorInfo[1] ?? 0), [1050, 1060, 1061], true);
                if (!$duplicate) {
                    throw $exception;
                }
                echo '  (already present, skipped) ' . mb_substr($statement, 0, 70) . "\n";
            }
        }
        $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (?)')->execute([basename($file)]);
        echo 'Applied ' . basename($file) . "\n";
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Failed on ' . basename($file) . ': ' . $exception->getMessage() . "\n");
        exit(1);
    }
}
echo "Done.\n";
