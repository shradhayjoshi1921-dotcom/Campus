<?php
declare(strict_types=1);

namespace App\Services;

use App\Ai\AiRequest;
use App\Ai\AiService;

/**
 * The student Career Copilot.
 *
 * It is not a generic chatbot: every answer is assembled from the signed-in
 * student's own authorised data, passed in by the caller. When an intent cannot
 * be matched from that data it says so instead of guessing. A configured model
 * may rewrite the answer in plainer language, but it is given only the compact
 * context below and is told to answer solely from it.
 */
final class CopilotService
{
    public function __construct(private readonly AiService $ai)
    {
    }

    public function isAiEnabled(): bool
    {
        return $this->ai->isConfigured();
    }

    /**
     * @param array $context authorised facts for this student only
     * @return array{answer:string,source:string,intent:string,suggestions:array<int,string>}
     */
    public function ask(string $question, array $context): array
    {
        $question = trim($question);
        if ($question === '') {
            return ['answer' => 'Ask me about your applications, interviews, offers, skill gaps or preparation.', 'source' => 'rules', 'intent' => 'empty', 'suggestions' => []];
        }
        [$intent, $answer, $suggestions] = $this->answerFromData($question, $context);
        $source = 'rules';
        if ($this->ai->isConfigured()) {
            $rewritten = $this->ai->complete(new AiRequest(
                'copilot',
                'You are the Campus career copilot for an SVIET student. Answer ONLY from the JSON facts provided. '
                . 'If the facts do not contain the answer, say that you do not have it. Never invent jobs, deadlines, '
                . 'salaries, eligibility or outcomes. Never promise an interview or an offer. Be concise (max 120 words).',
                'Facts: ' . json_encode($context, JSON_UNESCAPED_SLASHES) . "\n\nQuestion: " . $question,
                400,
                0.2,
                null,
                $context['user_id'] ?? null,
            ));
            if (is_string($rewritten) && trim($rewritten) !== '') {
                $answer = trim($rewritten);
                $source = 'ai';
            }
        }
        return ['answer' => $answer, 'source' => $source, 'intent' => $intent, 'suggestions' => $suggestions];
    }

    /** @return array{0:string,1:string,2:array<int,string>} */
    private function answerFromData(string $question, array $context): array
    {
        $q = mb_strtolower($question);
        $has = static fn (string ...$words): bool => (bool) array_filter($words, static fn (string $word): bool => str_contains($q, $word));

        if ($has('application', 'apply', 'applied', 'status')) {
            $applications = $context['applications'] ?? [];
            if ($applications === []) {
                return ['applications', 'You have no applications on record yet. Open Jobs to see the published roles you are eligible for.', ['Show me the jobs I can apply to', 'What skills am I missing?']];
            }
            $lines = array_map(static fn ($row): string => '- ' . $row['title'] . ' at ' . $row['company'] . ': ' . ucwords(str_replace('_', ' ', (string) $row['status'])), $applications);
            return ['applications', "Here is where your applications stand:\n" . implode("\n", $lines), ['What should I do next?', 'Show my upcoming interviews']];
        }
        if ($has('interview')) {
            $interviews = $context['interviews'] ?? [];
            if ($interviews === []) {
                return ['interviews', 'You have no scheduled interviews on record. A mock interview is a useful next step while you wait.', ['Start a mock interview', 'What skills am I missing?']];
            }
            $lines = array_map(static fn ($row): string => '- ' . $row['title'] . ' (' . $row['company'] . ') on ' . $row['when'] . ($row['mode'] ? ' · ' . $row['mode'] : ''), $interviews);
            return ['interviews', "Your scheduled interviews:\n" . implode("\n", $lines), ['How should I prepare?', 'Show my offers']];
        }
        if ($has('offer', 'ctc', 'salary', 'package')) {
            $offers = $context['offers'] ?? [];
            if ($offers === []) {
                return ['offers', 'No offer has been recorded for you yet. Offers appear here once a recruiter records one after selection.', ['Show my applications']];
            }
            $lines = array_map(static fn ($row): string => '- ' . $row['title'] . ' at ' . $row['company'] . ': ' . ($row['ctc'] !== null ? $row['ctc'] . ' LPA' : 'CTC not disclosed') . ' · ' . $row['status'], $offers);
            return ['offers', "Offers recorded for you:\n" . implode("\n", $lines), ['What should I improve next?']];
        }
        if ($has('missing', 'gap', 'learn', 'weak', 'improve my skills')) {
            $gaps = $context['gaps'] ?? [];
            if ($gaps === []) {
                return ['gaps', 'I do not have a skill-gap analysis for a target role yet. Choose a role in Career insights and I can list exactly what is missing.', ['Show me recommended jobs']];
            }
            $lines = array_map(static fn ($row): string => '- ' . $row['skill'] . ' (' . $row['priority'] . ' priority): ' . $row['why'], array_slice($gaps, 0, 6));
            return ['gaps', "Against " . ($context['target_role'] ?? 'your target role') . ", these are the current gaps:\n" . implode("\n", $lines), ['Build me a study plan', 'Which of these should I do first?']];
        }
        if ($has('roadmap', 'plan', 'prepare', 'study', 'week')) {
            $plan = $context['plan'] ?? [];
            if ($plan === []) {
                return ['plan', 'No study plan exists yet. Generate one from your target role and it will appear in Career insights.', ['What skills am I missing?']];
            }
            $lines = array_map(static fn ($row): string => 'Week ' . $row['week'] . ': ' . $row['theme'], array_slice($plan, 0, 6));
            return ['plan', "Your current plan:\n" . implode("\n", $lines) . "\nOpen Career insights to edit it or mark tasks done.", ['What should I do this week?']];
        }
        if ($has('resume', 'cv')) {
            $resume = $context['resume'] ?? null;
            if (!$resume || empty($resume['uploaded'])) {
                return ['resume', 'You have not uploaded a PDF resume yet. Upload one in Profile, then review the extracted details.', ['What skills am I missing?']];
            }
            return ['resume', 'Your resume is uploaded' . (!empty($resume['reviewed']) ? ' and you have reviewed the extracted details. ' : ', but you have not reviewed the extracted details yet. ') . ($resume['notes'] ?? ''), ['How does my resume compare to a job?']];
        }
        if ($has('job', 'recommend', 'match', 'opportunit')) {
            $jobs = $context['recommendations'] ?? [];
            if ($jobs === []) {
                return ['recommendations', 'There are no published roles to recommend right now. Recommendations only ever use real published jobs.', []];
            }
            $lines = array_map(static fn ($row): string => '- ' . $row['title'] . ' at ' . $row['company'] . ' · ' . $row['fit'] . '% estimate · ' . ($row['eligible'] ? 'you appear eligible' : 'eligibility not met'), array_slice($jobs, 0, 6));
            return ['recommendations', "Recommended from the roles currently open:\n" . implode("\n", $lines), ['Why is my fit that number?', 'What skills am I missing?']];
        }
        if ($has('eligible', 'eligibility', 'can i apply')) {
            $jobs = $context['recommendations'] ?? [];
            $eligible = array_values(array_filter($jobs, static fn ($row): bool => !empty($row['eligible'])));
            if ($jobs === []) {
                return ['eligibility', 'There are no open roles to check against yet.', []];
            }
            return ['eligibility', count($eligible) . ' of ' . count($jobs) . ' open roles list criteria you currently meet. Eligibility is decided by each employer\'s stated rules, not by the fit estimate.', ['Show me those jobs']];
        }
        if ($has('readiness', 'ready', 'prepared')) {
            $readiness = $context['readiness'] ?? null;
            if ($readiness === null) {
                return ['readiness', 'Readiness needs a little more data. Complete your profile, upload a resume and take one assessment.', []];
            }
            return ['readiness', 'Your readiness is ' . $readiness['total'] . '% (' . $readiness['band'] . '). Weakest area: ' . $readiness['weakest'] . '. ' . $readiness['next'], ['What should I do next?']];
        }
        if ($has('hello', 'hi ', 'hey', 'help', 'what can you')) {
            return ['help', 'I can answer questions about your own Campus record: applications, interviews, offers, skill gaps, your study plan, your resume and recommended jobs. I only use your data, and I will say when I do not know something.', ['Show my applications', 'What skills am I missing?']];
        }
        return ['unknown', 'I do not have that answer in your Campus record. I can help with applications, interviews, offers, skills and gaps, your study plan, your resume and recommended jobs.', ['Show my applications', 'What skills am I missing?']];
    }
}
