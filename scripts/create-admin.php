<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/app.php';
use App\Core\Database;
if(PHP_SAPI!=='cli'){http_response_code(403);exit("Run this script from the command line only.\n");}
if($argc<4){fwrite(STDERR,'Usage: php scripts/create-admin.php "Admin Name" admin@example.com "StrongPasswordHere"\n');exit(2);}
[$script,$name,$email,$password]=$argv;
if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($password)<14){fwrite(STDERR,"Use a valid email and password of at least 14 characters.\n");exit(2);}
$db=Database::connection();$count=(int)$db->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
if($count>0){fwrite(STDERR,"An administrator already exists; bootstrap is disabled.\n");exit(1);}
$stmt=$db->prepare("INSERT INTO users(name,email,password_hash,role,status) VALUES(?,?,?,'admin','active')");$stmt->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT)]);echo "Initial administrator created. Keep credentials private.\n";
