<?php
declare(strict_types=1);
namespace App\Services;
final class RoadmapService {
    private const TASKS=['sql'=>['priority'=>'High','practice'=>'Design a normalized schema and write 15 JOIN/GROUP BY queries.','project'=>'Build a placement reporting database.'],'php'=>['priority'=>'High','practice'=>'Build validated CRUD endpoints using PDO and CSRF protection.','project'=>'Create a secure job application tracker.'],'javascript'=>['priority'=>'Medium','practice'=>'Build accessible form validation and Fetch API requests.','project'=>'Create an interactive job search page.'],'react'=>['priority'=>'Medium','practice'=>'Practice components, props, state, and API consumption.','project'=>'Build a job listing UI.'],'python'=>['priority'=>'Medium','practice'=>'Solve data transformation and scripting exercises.','project'=>'Create a CSV career report generator.']];
    public function build(array $missingSkills): array { $items=[]; foreach($missingSkills as $skill){$key=strtolower(trim((string)$skill));$items[]=['skill'=>$skill]+(self::TASKS[$key]??['priority'=>'Medium','practice'=>'Learn the fundamentals, then complete a small verified exercise.','project'=>'Build a focused project demonstrating '.$skill.'.']);} return $items; }
}
