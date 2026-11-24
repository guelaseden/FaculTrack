<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(1);
require __DIR__.'/../app/core.php';
function ask(string $label): string {fwrite(STDOUT,$label.' ');return trim((string)fgets(STDIN));}
try {
    $employeeId=ask('Employee ID [Admin01]:');$employeeId=$employeeId!==''?$employeeId:'Admin01';
    $_POST['employee_id']=$employeeId;$employeeId=employeeIdInput();
    $name=ask('Full name:');$email=ask('Email:');
    valid($name!=='' && mb_strlen($name)<=140 && filter_var($email,FILTER_VALIDATE_EMAIL),'Enter a valid name and email.');
    $password=bin2hex(random_bytes(12));
    db()->beginTransaction();
    one('SELECT id FROM system_settings WHERE id=1 FOR UPDATE');
    valid(!(bool)one("SELECT id FROM users WHERE role='Administrator' LIMIT 1"),'An Administrator account already exists.');
    query("INSERT INTO users(full_name,faculty_id,email,password_hash,role,department_id,is_teaching) VALUES(?,?,?,?,'Administrator',NULL,0)",
        [$name,$employeeId,$email,password_hash($password,PASSWORD_DEFAULT)]);
    audit('Account created','CLI administrator · '.$email);db()->commit();
    echo "Initial password (change under Profile): ".$password.PHP_EOL;
} catch(Throwable $e){if(isset($password) && db()->inTransaction())db()->rollBack();fwrite(STDERR,"Administrator creation failed. Check the database connection, unique email, and whether an Administrator already exists.\n");exit(1);}

