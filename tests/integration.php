<?php
declare(strict_types=1);

// This CLI test creates its own uniquely named database and storage directory.
// Use a local test-only server with CREATE/DROP DATABASE privileges.
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__.'/HttpClient.php';
date_default_timezone_set('Asia/Manila');
$project = dirname(__DIR__);
require $project.'/app/core.php';
$dbPort = getenv('FACULTRACK_TEST_DB_PORT') ?: '33317';
$dbUser = getenv('FACULTRACK_TEST_DB_USER') ?: 'root';
$dbPassword = getenv('FACULTRACK_TEST_DB_PASS') ?: '';
$run = bin2hex(random_bytes(8));
$database = 'facul_track_test_'.$run;
$appAccount = 'ft_test_'.$run;
$appPassword = bin2hex(random_bytes(24));
$directory = sys_get_temp_dir().'/'.$database;
mkdir($directory, 0700);
mkdir($directory.'/files', 0700);
$password = bin2hex(random_bytes(12));
$dsn = "mysql:host=127.0.0.1;port=$dbPort;charset=utf8mb4";
$db = new PDO($dsn, $dbUser, $dbPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$server = null;
$pipes = [];
$checks = 0;
function check(bool $condition, string $name): void {
    global $checks;
    if (!$condition) throw new RuntimeException("FAIL $name");
    ++$checks;
    echo "PASS $name\n";
}
function sql(string $statement, array $params = []): PDOStatement {
    global $db;
    $query = $db->prepare($statement);
    $query->execute($params);
    return $query;
}
function value(string $statement, array $params = []): mixed {
    return sql($statement, $params)->fetchColumn();
}
function addActor(string $key, string $role, int $dept = 1, bool $teaching = false, bool $active = true): int {
    global $password, $db;
    if($role==='Administrator')$teaching=false;
    sql('INSERT INTO users(full_name,faculty_id,email,password_hash,role,department_id,is_teaching,active) VALUES(?,?,?,?,?,?,?,?)',
        ["Test ".ucfirst($key), 'EMP-'.strtoupper($key), "$key@example.test", password_hash($password, PASSWORD_DEFAULT), $role, $dept, (int)$teaching, (int)$active]);
    return (int)$db->lastInsertId();
}
function actor(string $name): HttpClient {
    global $base, $password;
    $client = new HttpClient($base);
    $client->login('EMP-'.strtoupper($name), $password);
    return $client;
}
function addRequirement(string $name, int $days, int $dept, int $creator, array $people): array {
    global $db;
    sql("INSERT INTO requirements(title,type_id,department_id,description,academic_year,semester,deadline,created_by)
      VALUES(?,1,?,'Test assignment','2026–2027','First Semester',?,?)",
      [$name,$dept,date('Y-m-d',strtotime("$days days")),$creator]);
    $requirement = (int)$db->lastInsertId();
    $ids = [];
    foreach ($people as $key => $person) {
        sql('INSERT INTO requirement_assignments(requirement_id,user_id) VALUES(?,?)', [$requirement,$person]);
        $ids[$key] = (int)$db->lastInsertId();
    }
    return $ids;
}
function upload(HttpClient $client, int $assignment, string $file, string $title = 'Teaching plan'): void {
    $client->get('upload');
    $client->post('upload', ['assignment_id'=>$assignment, 'title'=>$title, 'remarks'=>'Test upload',
        'file'=>new CURLFile($file, 'application/octet-stream', basename($file))]);
}
function version(int $assignment): int {
    return (int)value('SELECT MAX(v.id) FROM submission_versions v JOIN submissions s ON s.id=v.submission_id WHERE s.assignment_id=?', [$assignment]);
}
function decision(HttpClient $client, int $assignment, string $status, string $remarks): void {
    $client->get('detail', ['id'=>$assignment]);
    $client->post('detail', ['id'=>$assignment,'version_id'=>version($assignment),'decision'=>$status,'remarks'=>$remarks], ['id'=>$assignment]);
}
function review(int $assignment): string {
    return (string)value('SELECT decision FROM submission_reviews WHERE version_id=?', [version($assignment)]);
}
function removeFixtures(string $root): void {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname());
        else unlink($entry->getPathname());
    }
    // Windows may retain the removed server log briefly after the PHP process exits.
    for ($attempt=0; $attempt<30; $attempt++) {
        if (@rmdir($root)) return;
        usleep(100000);
    }
    throw new RuntimeException("Could not remove test directory $root: ".implode(', ',scandir($root)));
}
try {
    $db->exec("CREATE USER '$appAccount'@'127.0.0.1' IDENTIFIED BY '$appPassword'");
    $db->exec("GRANT SELECT,INSERT,UPDATE,DELETE ON `$database`.* TO '$appAccount'@'127.0.0.1'");
    $db->exec("USE `$database`");
    $db->exec(file_get_contents("$project/database/schema.sql"));
    sql("INSERT INTO departments(name,code,description) VALUES ('Information Technology','IT',''),('Education','ED','')");
    sql("INSERT INTO document_types(name,description) VALUES ('Test document','')");
    $manager = addActor('admin','Administrator');
    $headId = addActor('head','Department Head',1,true);
    $teacher = addActor('teacher','Faculty');
    $peerId = addActor('peer','Faculty',2);
    addActor('inactive','Faculty',1,false,false);
    $assignment = addRequirement('Teaching plan <spring> & schedule',-1,1,$manager,['teacher'=>$teacher,'head'=>$headId,'admin'=>$manager]);
    $future = addRequirement('Next syllabus',5,1,$manager,['teacher'=>$teacher]);
    sql("UPDATE requirements SET academic_year='2026-2027' WHERE title='Next syllabus'");
    $peerAssignment = addRequirement('Education annual record',5,2,$manager,['peer'=>$peerId]);
    $termId=(int)value('SELECT id FROM academic_terms WHERE academic_year=? AND semester=?',['2026–2027','First Semester']);
    sql('INSERT INTO subjects(code,title,section,term_id,department_id,faculty_user_id,units) VALUES(?,?,?,?,?,?,?)',
        ['IT 102','Computer Programming','BSIT 1B',$termId,1,$teacher,3]);
    sql('UPDATE requirements SET subject_id=? WHERE title=?',[$db->lastInsertId(),'Next syllabus']);
    $address = stream_socket_server('tcp://127.0.0.1:0');
    $endpoint = stream_socket_get_name($address,false);
    fclose($address);
    $base = "http://$endpoint/";
    $root = realpath("$project/public");
    $env = array_merge(getenv(), ['FACULTRACK_DB_HOST'=>'127.0.0.1','FACULTRACK_DB_PORT'=>$dbPort,
        'FACULTRACK_DB_NAME'=>$database,'FACULTRACK_DB_USER'=>$appAccount,'FACULTRACK_DB_PASS'=>$appPassword,
        'FACULTRACK_STORAGE'=>$directory.'/files','FACULTRACK_SESSION_DIR'=>$directory]);
    $server = proc_open([PHP_BINARY,'-d','upload_max_filesize=12M',
        '-d','post_max_size=14M','-S',$endpoint,'-t',$root],
        [0=>['pipe','r'],1=>['file',$directory.'/server.log','a'],2=>['file',$directory.'/server.log','a']], $pipes, $project, $env);
    for ($i=0; $i<50; $i++) {
        $ready = @stream_socket_client("tcp://$endpoint",$errno,$error,0.2);
        if ($ready) {fclose($ready);break;}
        usleep(100000);
    }
    require __DIR__.'/scenarios.php';
    echo "\n$checks integration checks passed.\n";
} catch (Throwable $error) {
    $log = $directory.'/server.log';
    if (is_file($log)) {
        $lines = file($log, FILE_IGNORE_NEW_LINES);
        fwrite(STDERR, implode(PHP_EOL,array_slice($lines,-16)).PHP_EOL);
    }
    throw $error;
} finally {
    if (is_resource($server)) {
        if (isset($pipes[0]) && is_resource($pipes[0])) fclose($pipes[0]);
        proc_terminate($server);
        proc_close($server);
    }
    unset($guest,$faculty,$head,$admin,$peer,$coordinatorClient);
    gc_collect_cycles();
    $db->exec("DROP DATABASE `$database`");
    $db->exec("DROP USER IF EXISTS '$appAccount'@'127.0.0.1'");
    removeFixtures($directory);
}
