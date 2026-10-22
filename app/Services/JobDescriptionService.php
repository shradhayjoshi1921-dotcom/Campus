<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Turns a recruiter's rough vacancy text into a structured draft.
 *
 * Rules first: it only extracts what is written. A field it cannot find is
 * returned empty with a note, so the recruiter fills it in through the existing
 * job form. Salary, policies and eligibility rules are never invented.
 */
final class JobDescriptionService
{
    public function __construct(private readonly SkillListService $skills)
    {
    }

    /**
     * @return array{title:string,location:string,employment_type:string,min_cgpa:?float,graduation_year:?int,eligible_branch:string,closes_at:string,description:string,required_skills:array<int,string>,preferred_skills:array<int,string>,responsibilities:array<int,string>,missing:array<int,string>}
     */
    public function draft(string $text): array
    {
        $text = trim($text);
        $lines = array_values(array_filter(array_map('trim', preg_split('/\n+/', $text) ?: []), static fn ($line): bool => $line !== ''));
        $missing = [];

        $title = '';
        foreach ($lines as $line) {
            if (preg_match('/^(job title|role|position)\s*[:\-]\s*(.+)$/i', $line, $match) === 1) {
                $title = trim($match[2]);
                break;
            }
        }
        if ($title === '' && $lines !== [] && mb_strlen($lines[0]) <= 90) {
            $title = $lines[0];
        }
        if ($title === '') {
            $missing[] = 'Job title';
        }

        $location = $this->labelled($lines, ['location', 'place', 'city']);
        if ($location === '') {
            if (preg_match('/\b(remote|hybrid|on[- ]site|work from home)\b/i', $text, $match) === 1) {
                $location = ucfirst(strtolower($match[1]));
            }
        }
        $employment = $this->labelled($lines, ['employment type', 'type', 'job type']);
        if ($employment === '' && preg_match('/\b(full[- ]time|part[- ]time|internship|contract|trainee)\b/i', $text, $match) === 1) {
            $employment = ucfirst(strtolower($match[1]));
        }
        $cgpa = null;
        if (preg_match('/(?:min(?:imum)?\s*cgpa|cgpa)\s*[:\-]?\s*(\d(?:\.\d{1,2})?)/i', $text, $match) === 1) {
            $cgpa = min(10.0, max(0.0, (float) $match[1]));
        } else {
            $missing[] = 'Minimum CGPA';
        }
        $year = null;
        if (preg_match('/\b(20\d{2})\b/', $text, $match) === 1) {
            $candidate = (int) $match[1];
            if ($candidate >= 2000 && $candidate <= 2150) {
                $year = $candidate;
            }
        }
        $branch = $this->labelled($lines, ['branch', 'eligible branch', 'stream']);
        if ($branch === '' && preg_match('/\b(cse|it|ece|ee|me|ce|civil|mechanical|computer science)\b/i', $text, $match) === 1) {
            $branch = mb_strtoupper($match[1]);
        }
        $closes = '';
        if (preg_match('/(?:deadline|apply by|closing)\s*[:\-]?\s*(\d{4}-\d{2}-\d{2})/i', $text, $match) === 1) {
            $closes = $match[1];
        } elseif (preg_match('/(\d{4}-\d{2}-\d{2})/', $text, $match) === 1) {
            $closes = $match[1];
        }

        $required = [];
        $preferred = [];
        foreach ($lines as $line) {
            if (preg_match('/^(required skills?|must have|requirements?)\s*[:\-]\s*(.+)$/i', $line, $match) === 1) {
                $required = array_merge($required, $this->skills->parse($match[2]));
            } elseif (preg_match('/^(preferred skills?|nice to have|good to have|bonus)\s*[:\-]\s*(.+)$/i', $line, $match) === 1) {
                $preferred = array_merge($preferred, $this->skills->parse($match[2]));
            } elseif (preg_match('/^(responsibilities|what you will do|role)\s*[:\-]\s*(.*)$/i', $line, $match) === 1 && trim($match[2]) !== '') {
                $required = $required;
            }
        }
        if ($required === []) {
            // Fall back to the shared vocabulary found anywhere in the text.
            $required = $this->skills->parse(implode(', ', $this->detectKnownSkills($text)));
            if ($required !== []) {
                $missing[] = 'Required skills (guessed from the text — check the list)';
            } else {
                $missing[] = 'Required skills';
            }
        }
        $preferred = array_values(array_filter($preferred, static fn (string $skill): bool => !in_array(mb_strtolower($skill), array_map('mb_strtolower', $required), true)));

        $responsibilities = [];
        $collect = false;
        foreach ($lines as $line) {
            if (preg_match('/^(responsibilities|what you will do|about the role|the role)\s*[:\-]?/i', $line) === 1) {
                $collect = true;
                continue;
            }
            if ($collect) {
                if (preg_match('/^(requirements?|required skills?|preferred skills?|location|cgpa)\b/i', $line) === 1) {
                    $collect = false;
                    continue;
                }
                $responsibilities[] = trim(preg_replace('/^[-•*·]\s*/u', '', $line) ?? '');
                if (count($responsibilities) >= 8) {
                    $collect = false;
                }
            }
        }

        $description = $this->description($text);
        return [
            'title' => $title,
            'location' => $location,
            'employment_type' => $employment,
            'min_cgpa' => $cgpa,
            'graduation_year' => $year,
            'eligible_branch' => $branch,
            'closes_at' => $closes,
            'description' => $description,
            'required_skills' => array_slice($required, 0, 20),
            'preferred_skills' => array_slice($preferred, 0, 20),
            'responsibilities' => array_values(array_filter($responsibilities)),
            'missing' => $missing,
        ];
    }

    private function labelled(array $lines, array $keys): string
    {
        foreach ($lines as $line) {
            foreach ($keys as $key) {
                if (preg_match('/^' . preg_quote($key, '/') . '\s*[:\-]\s*(.+)$/i', $line, $match) === 1) {
                    return trim($match[1]);
                }
            }
        }
        return '';
    }

    private function detectKnownSkills(string $text): array
    {
        return (new EvidenceService(new ScoringModel()))->skillsFromText($text);
    }

    /** Keep the recruiter's own prose; only normalise whitespace. */
    private function description(string $text): string
    {
        $clean = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $clean = preg_replace("/\n{3,}/", "\n\n", $clean) ?? $clean;
        return mb_substr(trim($clean), 0, 12000);
    }
}
