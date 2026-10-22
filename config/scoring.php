<?php
declare(strict_types=1);

/**
 * Career-fit scoring model.
 *
 * Every weight here is a documented, configurable number; `docs/SCORING.md`
 * explains each one and `app/Services/ScoringModel.php` resolves overrides from
 * `system_settings` without touching this file. Components that cannot be
 * measured (an employer listed no skills, a student listed none) are reported
 * as null and their weight is redistributed over the remaining components, so
 * missing data neither fabricates nor destroys a score.
 */
return [
    // Must sum to 1.0. A null component is dropped and the rest re-normalised.
    'weights' => [
        'skills' => 0.45,    // required skills the student actually has
        'evidence' => 0.20,  // how strongly those skills are demonstrated
        'academic' => 0.20,  // CGPA relative to the role's minimum
        'role' => 0.15,      // branch / graduation / location alignment
    ],

    // Used only when a job lists no required skills at all.
    'fallback_weights' => [
        'academic' => 0.55,
        'role' => 0.45,
    ],

    /**
     * Evidence strength per skill, 0–1. "Claimed" is a skill the student added
     * to their profile and nothing more; each step above it needs a real row
     * (a project, a certification, an assessment, or staff verification).
     */
    'evidence_levels' => [
        'none' => 0.0,
        'claimed' => 0.25,
        'project' => 0.60,
        'certification' => 0.70,
        'assessment' => 0.85,
        'verified' => 1.0,
    ],

    // A skill at or above this level counts as demonstrated on the readiness
    // scorecard; below it the student is told what evidence would help.
    'demonstrated_threshold' => 0.60,

    // Readiness scorecard weights. Kept separate from career fit: readiness is
    // about the student's preparation, fit is about one specific job.
    'readiness_weights' => [
        'profile' => 0.15,
        'resume' => 0.15,
        'skills' => 0.25,
        'projects' => 0.15,
        'assessments' => 0.15,
        'interview' => 0.15,
    ],

    // Scores are estimates. Bands exist so the UI never implies precision.
    'bands' => [
        ['min' => 80, 'label' => 'Strong alignment'],
        ['min' => 60, 'label' => 'Good alignment'],
        ['min' => 40, 'label' => 'Partial alignment'],
        ['min' => 0, 'label' => 'Early alignment'],
    ],
];
