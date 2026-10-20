<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * Parsing and validation for the admin CSV import.
 *
 * Kept free of database access so the rules can be unit tested: the caller
 * decides what to do with valid rows and reports the row-level errors back.
 */
final class AdminImportService
{
    public const MAX_ROWS = 300;
    public const MAX_BYTES = 524288;
    public const ROLES = ['student', 'recruiter'];

    /** Canonical column order used by the template and by the importer. */
    public const COLUMNS = ['name', 'email', 'role', 'branch', 'cgpa', 'graduation_year', 'company', 'designation'];

    private const ALIASES = [
        'full name' => 'name',
        'student name' => 'name',
        'recruiter name' => 'name',
        'e-mail' => 'email',
        'mail' => 'email',
        'email address' => 'email',
        'type' => 'role',
        'account type' => 'role',
        'department' => 'branch',
        'course' => 'branch',
        'current cgpa' => 'cgpa',
        'gpa' => 'cgpa',
        'passing year' => 'graduation_year',
        'graduation year' => 'graduation_year',
        'year of graduation' => 'graduation_year',
        'organisation' => 'company',
        'organization' => 'company',
        'company name' => 'company',
        'job title' => 'designation',
    ];

    public function template(): string
    {
        return implode(',', self::COLUMNS) . "\n"
            . "Aarav Sharma,aarav.sharma@example.edu,student,CSE,8.4,2027,,\n"
            . "Neha Kapoor,neha.kapoor@example.com,recruiter,,,,\"Northwind Labs\",Talent Partner\n";
    }

    public static function normaliseHeader(string $header): string
    {
        $raw = strtolower(trim($header));
        $raw = preg_replace('/[\x{00A0}\s]+/u', ' ', $raw) ?? $raw;
        if (isset(self::ALIASES[$raw])) {
            return self::ALIASES[$raw];
        }

        $key = str_replace(['-', '_'], ' ', $raw);
        $key = trim(preg_replace('/\s+/', ' ', $key) ?? $key);

        return self::ALIASES[$key] ?? $key;
    }

    /**
     * Parse a CSV document into rows with per-row validation.
     *
     * @return array{headers: list<string>, rows: list<array{line:int, values:array<string,string>, errors:list<string>, valid:bool}>, errors: list<string>}
     */
    public function parse(string $csv): array
    {
        $errors = [];
        if (trim($csv) === '') {
            return ['headers' => [], 'rows' => [], 'errors' => ['The file is empty.']];
        }
        if (strlen($csv) > self::MAX_BYTES) {
            return ['headers' => [], 'rows' => [], 'errors' => ['The file is larger than 512 KB. Split it into smaller files.']];
        }

        // Strip a UTF-8 BOM that spreadsheet exports often add.
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return ['headers' => [], 'rows' => [], 'errors' => ['The file could not be read.']];
        }
        fwrite($handle, $csv);
        rewind($handle);

        $headerRow = fgetcsv($handle, 0, ',', '"', '\\');
        if (!is_array($headerRow)) {
            fclose($handle);
            return ['headers' => [], 'rows' => [], 'errors' => ['The first line must be a header row.']];
        }

        $headers = [];
        foreach ($headerRow as $index => $cell) {
            $normalised = self::normaliseHeader((string) $cell);
            if ($normalised !== '') {
                $headers[$normalised] = $index;
            }
        }

        if (!isset($headers['name'], $headers['email'], $headers['role'])) {
            fclose($handle);
            return ['headers' => array_keys($headers), 'rows' => [], 'errors' => ['The header row needs at least name, email and role columns.']];
        }

        $rows = [];
        $seenEmails = [];
        $line = 1;
        while (($record = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $line++;
            if ($record === [null] || (count($record) === 1 && trim((string) $record[0]) === '')) {
                continue;
            }

            $values = [];
            foreach ($headers as $key => $index) {
                $values[$key] = trim((string) ($record[$index] ?? ''));
            }

            $result = $this->validateRow($values);
            $emailKey = strtolower($values['email'] ?? '');
            if ($result['clean']['email'] !== '' && isset($seenEmails[$emailKey])) {
                $result['errors'][] = 'Duplicate email in this file (also on line ' . $seenEmails[$emailKey] . ').';
            }
            if ($result['clean']['email'] !== '') {
                $seenEmails[$emailKey] ??= $line;
            }

            $rows[] = [
                'line' => $line,
                'values' => $result['clean'],
                'errors' => $result['errors'],
                'valid' => $result['errors'] === [],
            ];

            if (count($rows) > self::MAX_ROWS) {
                fclose($handle);
                return [
                    'headers' => array_keys($headers),
                    'rows' => [],
                    'errors' => ['This file has more than ' . self::MAX_ROWS . ' rows. Split it into batches of ' . self::MAX_ROWS . '.'],
                ];
            }
        }
        fclose($handle);

        if ($rows === []) {
            $errors[] = 'No data rows were found below the header.';
        }

        return ['headers' => array_keys($headers), 'rows' => $rows, 'errors' => $errors];
    }

    /**
     * @param array<string,string> $values
     * @return array{errors: list<string>, clean: array<string,string>}
     */
    public function validateRow(array $values): array
    {
        $errors = [];
        $clean = [
            'name' => trim((string) ($values['name'] ?? '')),
            'email' => strtolower(trim((string) ($values['email'] ?? ''))),
            'role' => strtolower(trim((string) ($values['role'] ?? ''))),
            'branch' => trim((string) ($values['branch'] ?? '')),
            'cgpa' => trim((string) ($values['cgpa'] ?? '')),
            'graduation_year' => trim((string) ($values['graduation_year'] ?? '')),
            'company' => trim((string) ($values['company'] ?? '')),
            'designation' => trim((string) ($values['designation'] ?? '')),
        ];

        if ($clean['name'] === '') {
            $errors[] = 'Name is required.';
        } elseif (mb_strlen($clean['name']) > 120) {
            $errors[] = 'Name must be 120 characters or fewer.';
        }

        if ($clean['email'] === '') {
            $errors[] = 'Email is required.';
        } elseif (mb_strlen($clean['email']) > 190 || !filter_var($clean['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email is not a valid address.';
        }

        if (!in_array($clean['role'], self::ROLES, true)) {
            $errors[] = 'Role must be student or recruiter.';
        }

        if ($clean['role'] === 'recruiter' && $clean['company'] === '') {
            $errors[] = 'Company is required for recruiter accounts.';
        }
        if (mb_strlen($clean['company']) > 180) {
            $errors[] = 'Company must be 180 characters or fewer.';
        }
        if (mb_strlen($clean['designation']) > 120) {
            $errors[] = 'Designation must be 120 characters or fewer.';
        }
        if (mb_strlen($clean['branch']) > 120) {
            $errors[] = 'Branch must be 120 characters or fewer.';
        }

        if ($clean['cgpa'] !== '') {
            $cgpa = filter_var($clean['cgpa'], FILTER_VALIDATE_FLOAT);
            if ($cgpa === false || $cgpa < 0 || $cgpa > 10) {
                $errors[] = 'CGPA must be a number between 0 and 10.';
            } else {
                $clean['cgpa'] = number_format((float) $cgpa, 2, '.', '');
            }
        }

        if ($clean['graduation_year'] !== '') {
            $year = filter_var($clean['graduation_year'], FILTER_VALIDATE_INT);
            if ($year === false || $year < 2000 || $year > 2100) {
                $errors[] = 'Graduation year must be a four-digit year.';
            } else {
                $clean['graduation_year'] = (string) $year;
            }
        }

        if ($clean['role'] === 'student' && $clean['graduation_year'] === '') {
            $clean['graduation_year'] = '';
        }

        return ['errors' => $errors, 'clean' => $clean];
    }

    /** A temporary password that satisfies the portal's 10-character minimum. */
    public function generatePassword(int $length = 14): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $max = strlen($alphabet) - 1;
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, $max)];
        }
        return $password;
    }

    /**
     * One-time credential sheet for accounts created by an import.
     *
     * @param list<array{name:string,email:string,role:string,password:string}> $accounts
     */
    public static function credentialsCsv(array $accounts): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new InvalidArgumentException('The credential sheet could not be generated.');
        }
        fputcsv($handle, ['name', 'email', 'role', 'temporary password'], ',', '"', '\\');
        foreach ($accounts as $account) {
            fputcsv($handle, [
                $account['name'],
                $account['email'],
                $account['role'],
                $account['password'],
            ], ',', '"', '\\');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);
        return $csv;
    }
}
