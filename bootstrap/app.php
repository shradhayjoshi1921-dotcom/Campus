<?php
declare(strict_types=1);

// Fail with a readable message instead of a raw parse error. Everything below
// uses PHP 8.0 syntax (constructor promotion, match, str_contains), so an older
// server would otherwise die halfway through an include with something like
// "unexpected identifier, expecting variable" and no hint about the cause.
if (PHP_VERSION_ID < 80000) {
    $campusMinimum = '8.0';
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, sprintf(
            "Campus needs PHP %s or newer; this is PHP %s. Upgrade PHP and run the command again.\n",
            $campusMinimum,
            PHP_VERSION,
        ));
    } else {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>PHP upgrade required</title></head>'
            . '<body style="font-family:system-ui,-apple-system,Segoe UI,sans-serif;padding:2.5rem 1.25rem;max-width:44rem;margin:0 auto;line-height:1.6">'
            . '<h1>PHP upgrade required</h1>'
            . '<p>This site needs <strong>PHP ' . htmlspecialchars($campusMinimum, ENT_QUOTES) . ' or newer</strong>, '
            . 'but the web server is running <strong>PHP ' . htmlspecialchars(PHP_VERSION, ENT_QUOTES) . '</strong>. '
            . 'That is a server setting, so upgrading your browser will not help.</p>'
            . '<p>On XAMPP: install a current XAMPP (8.2 or newer) or point Apache at a newer PHP folder, '
            . 'then restart Apache from the XAMPP Control Panel. Check with '
            . '<code>php -v</code> in the XAMPP shell.</p>'
            . '</body></html>';
    }
    exit(1);
}

use App\Core\Env;
use App\Core\Database;
use App\Core\Security;

require_once dirname(__DIR__) . '/app/Core/Env.php';
Env::load(dirname(__DIR__) . '/.env');
$appTimezone = Env::get('APP_TIMEZONE', 'Asia/Kolkata') ?? 'Asia/Kolkata';
if (!in_array($appTimezone, timezone_identifiers_list(), true)) {
    throw new RuntimeException('APP_TIMEZONE must be a recognized timezone identifier.');
}
date_default_timezone_set($appTimezone);
require_once dirname(__DIR__) . '/app/Core/Database.php';
require_once dirname(__DIR__) . '/app/Core/Security.php';
require_once dirname(__DIR__) . '/app/Services/EligibilityService.php';
require_once dirname(__DIR__) . '/app/Services/SkillListService.php';
require_once dirname(__DIR__) . '/app/Services/ScoringModel.php';
require_once dirname(__DIR__) . '/app/Services/EvidenceService.php';
require_once dirname(__DIR__) . '/app/Services/CareerFitService.php';
require_once dirname(__DIR__) . '/app/Services/SkillGapService.php';
require_once dirname(__DIR__) . '/app/Services/RecommendationService.php';
require_once dirname(__DIR__) . '/app/Services/RoadmapService.php';
require_once dirname(__DIR__) . '/app/Services/ReadinessService.php';
require_once dirname(__DIR__) . '/app/Services/WhatIfService.php';
require_once dirname(__DIR__) . '/app/Services/CareerGraphService.php';
require_once dirname(__DIR__) . '/app/Services/ResumeTextService.php';
require_once dirname(__DIR__) . '/app/Services/ResumeAnalysisService.php';
require_once dirname(__DIR__) . '/app/Services/JobDescriptionService.php';
require_once dirname(__DIR__) . '/app/Services/InterviewKitService.php';
require_once dirname(__DIR__) . '/app/Services/MockInterviewService.php';
require_once dirname(__DIR__) . '/app/Services/AssessmentService.php';
require_once dirname(__DIR__) . '/app/Services/InstitutionService.php';
require_once dirname(__DIR__) . '/app/Ai/AiRequest.php';
require_once dirname(__DIR__) . '/app/Ai/AiResult.php';
require_once dirname(__DIR__) . '/app/Ai/AiProvider.php';
require_once dirname(__DIR__) . '/app/Ai/NullProvider.php';
require_once dirname(__DIR__) . '/app/Ai/HttpProvider.php';
require_once dirname(__DIR__) . '/app/Ai/AiService.php';
require_once dirname(__DIR__) . '/app/Services/CopilotService.php';
require_once dirname(__DIR__) . '/app/Services/AdminImportService.php';
require_once dirname(__DIR__) . '/app/Data/StudentData.php';
require_once dirname(__DIR__) . '/app/Data/CareerStore.php';
require_once dirname(__DIR__) . '/app/Data/InstitutionReport.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = filter_var(Env::get('SESSION_SECURE', 'false'), FILTER_VALIDATE_BOOLEAN);
    session_name('campus_portal_session');
    session_set_cookie_params(['httponly'=>true, 'secure'=>$secure, 'samesite'=>'Lax', 'path'=>'/']);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

/**
 * One cache-busting token for every local asset.
 *
 * The link and script tags append this to the file name, so a browser keeps
 * using its cached copy until *any* stylesheet or script changes. Deriving it
 * from app.css alone meant a new dock engine could sit behind an old cache
 * entry, which looks exactly like "the design did not change".
 */
function asset_version(): string
{
    static $version = null;
    if ($version !== null) {
        return $version;
    }
    $newest = 0;
    foreach (['css', 'js'] as $kind) {
        foreach (glob(dirname(__DIR__) . '/public/assets/' . $kind . '/*.' . $kind) ?: [] as $file) {
            $newest = max($newest, (int) filemtime($file));
        }
    }
    return $version = (string) ($newest ?: 1);
}
