<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Reads a resume's plain text into reviewable facts, and compares it with a job.
 *
 * Everything it produces is a *suggestion*: the extracted fields are shown to
 * the student, who edits and approves them before any of it reaches the profile.
 * `compare()` never claims the resume proves a skill — it reports where the
 * document mentions one and where it does not.
 */
final class ResumeAnalysisService
{
    private const HEADINGS = [
        'education' => ['education', 'academic', 'qualification'],
        'skills' => ['skills', 'technical skills', 'technologies', 'core competencies'],
        'projects' => ['projects', 'project experience', 'academic projects'],
        'certifications' => ['certification', 'certifications', 'courses', 'licenses'],
        'experience' => ['experience', 'internship', 'work history', 'employment'],
    ];

    public function __construct(private EvidenceService $evidenceService)
    {
    }

    /**
     * @return array{skills:array<int,string>,projects:array<int,string>,certifications:array<int,string>,education:array<int,string>,experience:array<int,string>,sections_found:array<int,string>}
     */
    public function extract(string $text): array
    {
        $lines = preg_split('/\n+/', $text) ?: [];
        $sections = [];
        $current = 'header';
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            $lower = mb_strtolower(rtrim($trimmed, ':'));
            $matched = null;
            foreach (self::HEADINGS as $key => $words) {
                if (in_array($lower, $words, true) || preg_match('/^(' . implode('|', array_map('preg_quote', $words)) . ')\b/i', $trimmed) === 1) {
                    $matched = $key;
                    break;
                }
            }
            if ($matched !== null) {
                $current = $matched;
                continue;
            }
            $sections[$current][] = $trimmed;
        }

        $skills = $this->evidenceService->skillsFromText($text);
        foreach ($sections['skills'] ?? [] as $line) {
            foreach (preg_split('/[,;|•·]/', $line) ?: [] as $token) {
                $token = trim($token, " \t-•");
                if ($token !== '' && mb_strlen($token) <= 40 && preg_match('/^[A-Za-z0-9+#.\/ ]+$/', $token) === 1) {
                    $skills[] = mb_strtolower($token);
                }
            }
        }
        $skills = array_values(array_unique(array_filter($skills)));

        return [
            'skills' => array_slice($skills, 0, 40),
            'projects' => array_slice($this->entries($sections['projects'] ?? []), 0, 12),
            'certifications' => array_slice($this->entries($sections['certifications'] ?? []), 0, 12),
            'education' => array_slice($this->entries($sections['education'] ?? []), 0, 8),
            'experience' => array_slice($this->entries($sections['experience'] ?? []), 0, 8),
            'sections_found' => array_keys($sections),
        ];
    }

    /** @param array<int,string> $lines */
    private function entries(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $clean = trim(preg_replace('/^[-•*·]\s*/u', '', $line) ?? '');
            if ($clean !== '') {
                $out[] = mb_substr($clean, 0, 180);
            }
        }
        return $out;
    }

    /**
     * Resume ↔ job comparison.
     *
     * @param array{required_skills?:array,preferred_skills?:array,title?:string} $job
     * @return array{matched:array,missing:array,preferred_matched:array,evidence:array,summary:string}
     */
    public function compare(string $text, array $job): array
    {
        $lower = mb_strtolower($text);
        $required = $job['required_skills'] ?? [];
        $preferred = $job['preferred_skills'] ?? [];
        $matched = [];
        $missing = [];
        $evidence = [];
        foreach ($required as $skill) {
            $needle = mb_strtolower(trim((string) $skill));
            $found = $needle !== '' && preg_match('/(?<![a-z0-9+#.])' . preg_quote($needle, '/') . '(?![a-z0-9+#])/', $lower) === 1;
            if ($found) {
                $matched[] = (string) $skill;
                $evidence[(string) $skill] = $this->context($text, $needle);
            } else {
                $missing[] = (string) $skill;
            }
        }
        $preferredMatched = [];
        foreach ($preferred as $skill) {
            $needle = mb_strtolower(trim((string) $skill));
            if ($needle !== '' && preg_match('/(?<![a-z0-9+#.])' . preg_quote($needle, '/') . '(?![a-z0-9+#])/', $lower) === 1) {
                $preferredMatched[] = (string) $skill;
            }
        }
        $summary = sprintf(
            'The resume mentions %d of %d required skills%s.',
            count($matched),
            count($required),
            $required === [] ? ' (the employer listed none)' : '',
        );
        return [
            'matched' => $matched,
            'missing' => $missing,
            'preferred_matched' => $preferredMatched,
            'evidence' => $evidence,
            'summary' => $summary,
        ];
    }

    /** The sentence or line where a skill appears, so a human can check it. */
    private function context(string $text, string $needle): string
    {
        foreach (preg_split('/\n+/', $text) ?: [] as $line) {
            if (mb_stripos($line, $needle) !== false) {
                return mb_substr(trim($line), 0, 200);
            }
        }
        return 'Mentioned in the resume.';
    }

    /**
     * Formatting and clarity observations. These are heuristics on plain text,
     * not a layout engine: each one is phrased as something to check.
     *
     * @return array<int,array{level:string,message:string}>
     */
    public function review(string $text): array
    {
        $notes = [];
        $lines = array_values(array_filter(array_map('trim', preg_split('/\n+/', $text) ?: []), static fn ($line): bool => $line !== ''));
        $words = str_word_count($text);

        if ($words < 120) {
            $notes[] = ['level' => 'warn', 'message' => 'The resume is short (' . $words . ' words). Recruiters usually expect a clear page of detail.'];
        } elseif ($words > 900) {
            $notes[] = ['level' => 'warn', 'message' => 'The resume is long (' . $words . ' words). Consider moving detail into your Campus profile.'];
        }
        if (preg_match('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $text) !== 1) {
            $notes[] = ['level' => 'warn', 'message' => 'No email address was found. Add one so a recruiter can reach you.'];
        }
        if (preg_match('/(\+?\d[\d\s-]{8,})/', $text) !== 1) {
            $notes[] = ['level' => 'warn', 'message' => 'No phone number was found.'];
        }
        foreach (self::HEADINGS as $key => $words_) {
            $found = false;
            foreach ($words_ as $word) {
                if (preg_match('/' . preg_quote($word, '/') . '/i', $text) === 1) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $notes[] = ['level' => 'info', 'message' => 'No "' . ucfirst($key) . '" heading was detected. Add one if you have relevant material.'];
            }
        }
        $longLines = array_filter($lines, static fn ($line): bool => mb_strlen($line) > 160);
        if ($longLines !== []) {
            $notes[] = ['level' => 'info', 'message' => count($longLines) . ' line(s) are very long; bullet points read better.'];
        }
        $quantified = preg_grep('/\b\d+([.,]\d+)?\s*(%|percent|ms|users|students|rows|records|hours)\b/i', $lines);
        if ($quantified === []) {
            $notes[] = ['level' => 'info', 'message' => 'No measurable result was found. Add numbers to projects you actually completed.'];
        }
        return array_slice($notes, 0, 8);
    }

    /**
     * Rewrites a weak bullet using the student's own words. Rules only: it never
     * invents a metric or a technology that is not already in the line.
     *
     * @return array{original:string,suggestion:string,reason:string}
     */
    public function improveBullet(string $bullet): array
    {
        $clean = trim(preg_replace('/^[-•*·]\s*/u', '', $bullet) ?? '');
        $verbs = ['built', 'designed', 'implemented', 'led', 'created', 'developed', 'improved', 'automated', 'analysed', 'analyzed', 'migrated', 'tested'];
        $first = strtolower(strtok($clean, ' ') ?: '');
        $startsWithVerb = in_array($first, $verbs, true);
        $hasMetric = preg_match('/\d/', $clean) === 1;
        $suggestion = $clean;
        if (!$startsWithVerb) {
            $suggestion = 'Built ' . lcfirst($clean);
        }
        if (!$hasMetric) {
            $suggestion = rtrim($suggestion, '.') . ', and record the measurable result here (for example how many users, records or hours it covered).';
        }
        return [
            'original' => $bullet,
            'suggestion' => $suggestion,
            'reason' => !$startsWithVerb
                ? 'Start with a concrete action verb so the reader sees what you did.'
                : 'Add the result you can actually verify; do not invent a number.',
        ];
    }
}
