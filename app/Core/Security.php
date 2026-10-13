<?php
declare(strict_types=1);
namespace App\Core;
final class Security {
    public static function escape(?string $value): string { return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    public static function csrfToken(): string { if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32)); return $_SESSION['_csrf']; }
    public static function verifyCsrf(?string $token): bool { return is_string($token) && isset($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $token); }
    public static function requireLogin(): void { if (empty($_SESSION['user'])) { header('Location: index.php?page=login'); exit; } }
    public static function requireRole(array $roles): void { self::requireLogin(); if (!in_array($_SESSION['user']['role'] ?? '', $roles, true)) { http_response_code(403); exit('403 — You do not have permission to access this page.'); } }
    public static function currentUser(): ?array { return $_SESSION['user'] ?? null; }
}
