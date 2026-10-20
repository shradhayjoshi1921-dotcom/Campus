<?php
declare(strict_types=1);

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
require_once dirname(__DIR__) . '/app/Services/CareerFitService.php';
require_once dirname(__DIR__) . '/app/Services/SkillGapService.php';
require_once dirname(__DIR__) . '/app/Services/RecommendationService.php';
require_once dirname(__DIR__) . '/app/Services/RoadmapService.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = filter_var(Env::get('SESSION_SECURE', 'false'), FILTER_VALIDATE_BOOLEAN);
    session_name('campus_portal_session');
    session_set_cookie_params(['httponly'=>true, 'secure'=>$secure, 'samesite'=>'Lax', 'path'=>'/']);
    ini_set('session.use_strict_mode', '1');
    session_start();
}
