<?php
declare(strict_types=1);
namespace App\Services;

/**
 * Turns a free-text skills field into a clean, de-duplicated list.
 *
 * Skills are free text on purpose: a fixed catalogue would stall a recruiter
 * who needs "SAP ABAP" on a job today. The same parser is used for the required
 * and preferred fields, so the two lists are normalised identically before they
 * ever reach `skills` / `job_skills`.
 */
final class SkillListService
{
    public function parse(mixed $value, int $limit = 20, int $maxLength = 60): array
    {
        if (!is_string($value)) {
            return [];
        }
        $parts = preg_split('/[,;\n]+/', $value) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $name = trim((string) preg_replace('/\s+/', ' ', $part));
            if ($name === '') {
                continue;
            }
            if (mb_strlen($name) > $maxLength) {
                $name = mb_substr($name, 0, $maxLength);
            }
            $key = mb_strtolower($name);
            if (!isset($out[$key])) {
                $out[$key] = $name;
            }
            if (count($out) >= $limit) {
                break;
            }
        }
        return array_values($out);
    }
}
