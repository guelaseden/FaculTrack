<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(1);
require __DIR__.'/../app/core.php';

function demoPdf(): string {
    $stream="BT /F1 18 Tf 50 740 Td (FaculTrack demo submission) Tj ET";
    $objects=[
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream"
    ];
    $pdf="%PDF-1.4\n";$offsets=[];
    foreach($objects as $index=>$object){
        $offsets[$index+1]=strlen($pdf);
        $pdf.=($index+1)." 0 obj\n".$object."\nendobj\n";
    }
    $xref=strlen($pdf);$pdf.="xref\n0 6\n0000000000 65535 f \n";
    for($index=1;$index<=5;$index++)$pdf.=sprintf("%010d 00000 n \n",$offsets[$index]);
    return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
}

function seedDemoSubmission(
    int $assignmentId,
    string $title,
    string $remarks,
    string $deadline,
    string $storagePath,
    array &$createdFiles,
    ?int $reviewerId=null,
    ?string $decision=null,
    string $reviewRemarks=''
): void {
    $storageKey=bin2hex(random_bytes(32));
    $filePath=$storagePath.DIRECTORY_SEPARATOR.$storageKey;
    valid(file_put_contents($filePath,demoPdf(),LOCK_EX)!==false,'Cannot write a demo submission file.');
    $createdFiles[]=$filePath;
    query('INSERT INTO submissions(assignment_id,created_at) VALUES(?,NOW())',[$assignmentId]);
    $submissionId=(int)db()->lastInsertId();
    query("INSERT INTO submission_versions(submission_id,version_no,title,storage_key,original_name,format,remarks,submitted_at,deadline_snapshot)
        VALUES(?,1,?,?,?,'pdf',?,NOW(),?)",
        [$submissionId,$title,$storageKey,'Demo-submission.pdf',$remarks,$deadline]);
    if($decision!==null){
        query('INSERT INTO submission_reviews(version_id,reviewer_id,decision,remarks,reviewed_at) VALUES(?,?,?,?,NOW())',
            [(int)db()->lastInsertId(),$reviewerId,$decision,$reviewRemarks]);
    }
}

$createdFiles=[];$transactionStarted=false;
try {
    $database=config('DB_NAME','facul_track');
    fwrite(STDOUT,"This adds fictional demo records to database '{$database}'. Type SEED to continue: ");
    if(trim((string)fgets(STDIN))!=='SEED'){fwrite(STDOUT,"Cancelled.\n");exit(0);}

    $admin=one("SELECT id FROM users WHERE role='Administrator' AND active=1 LIMIT 1");
    valid((bool)$admin,'Sign in as an active Administrator and create demo data from the configured database.');
    $departments=rows('SELECT id,name,code FROM departments WHERE active=1 ORDER BY id');
    valid((bool)$departments,'Create at least one active department before seeding demo data.');
    $defaults=settings();
    $term=one("SELECT id,academic_year,semester FROM academic_terms
        WHERE active=1 AND REPLACE(academic_year,'–','-')=REPLACE(?,'–','-') AND semester=? LIMIT 1",
        [$defaults['academic_year'],$defaults['semester']]);
    if(!$term)$term=one('SELECT id,academic_year,semester FROM academic_terms WHERE active=1 ORDER BY id DESC LIMIT 1');
    valid((bool)$term,'Create an active academic term before seeding demo data.');
    valid(!(bool)one("SELECT id FROM document_types WHERE name='Demo - Faculty Submission' LIMIT 1"),
        'Demo document type already exists. The seed script will not add duplicates.');
    foreach($departments as $department){
        $prefix='DEMO-D'.(int)$department['id'].'-';
        valid(!(bool)one('SELECT id FROM users WHERE faculty_id LIKE ? OR email LIKE ? LIMIT 1',
            [$prefix.'%','demo.d'.(int)$department['id'].'.%@example.test']),
            'Demo accounts already exist for department '.$department['code'].'. The script will not add duplicates.');
    }

    db()->beginTransaction();$transactionStarted=true;
    query("INSERT INTO document_types(name,description,formats) VALUES('Demo - Faculty Submission','Sample document type for demo requirements.','pdf,docx')");
    $typeId=(int)db()->lastInsertId();

    $storageDirectory=storage();
    if(!is_dir($storageDirectory) && !mkdir($storageDirectory,0700,true) && !is_dir($storageDirectory))
        throw new RuntimeException('Cannot create private upload storage.');
    $storagePath=realpath($storageDirectory);$publicPath=realpath(ROOT.'/public');
    valid($storagePath && $publicPath && $storagePath!==$publicPath
        && !str_starts_with($storagePath.DIRECTORY_SEPARATOR,$publicPath.DIRECTORY_SEPARATOR),
        'Private storage must be outside the public directory.');
    $credentials=[];$demoFacultyCount=0;$demoHeadCount=0;
    foreach($departments as $department){
        $departmentId=(int)$department['id'];$departmentCode=(string)$department['code'];
        $prefix='DEMO-D'.$departmentId;
        $headPassword=bin2hex(random_bytes(12));
        query("INSERT INTO users(full_name,faculty_id,email,password_hash,role,department_id,is_teaching,active)
            VALUES(?,?,?,?,'Department Head',?,0,1)",
            ['Demo Department Head - '.$departmentCode,$prefix.'-HEAD','demo.d'.$departmentId.'.head@example.test',
                password_hash($headPassword,PASSWORD_DEFAULT),$departmentId]);
        $headId=(int)db()->lastInsertId();$demoHeadCount++;
        $credentials[]=['Demo Department Head - '.$departmentCode,$prefix.'-HEAD',$headPassword];
        $faculty=[];
        for($index=1;$index<=3;$index++){
            $facultyPassword=bin2hex(random_bytes(12));
            $employeeId=$prefix.'-F'.$index;
            query("INSERT INTO users(full_name,faculty_id,email,password_hash,role,department_id,is_teaching,active)
                VALUES(?,?,?,?,'Faculty',?,1,1)",
                ['Demo Faculty '.$index.' - '.$departmentCode,$employeeId,
                    'demo.d'.$departmentId.'.faculty'.$index.'@example.test',
                    password_hash($facultyPassword,PASSWORD_DEFAULT),$departmentId]);
            $facultyId=(int)db()->lastInsertId();$faculty[]=$facultyId;$demoFacultyCount++;
            $credentials[]=['Demo Faculty '.$index.' - '.$departmentCode,$employeeId,$facultyPassword];
        }

        $subjects=[];
        foreach($faculty as $index=>$facultyId){
            $number=$index+1;$code='D'.$departmentId.' 10'.$number;$section='D'.$departmentId.' 1'.chr(65+$index);
            query('INSERT INTO subjects(code,title,section,term_id,department_id,faculty_user_id,units) VALUES(?,?,?,?,?,?,?)',
                [$code,'Demo Course '.$number.' - '.$departmentCode,$section,$term['id'],$departmentId,$facultyId,3]);
            $subjects[]=(int)db()->lastInsertId();
        }

        $requirementRows=[
            ['Syllabus','Submit a current course syllabus.',$faculty[0],$subjects[0],date('Y-m-d',strtotime('+14 days'))],
            ['Lesson Plan','Submit a lesson plan for the current term.',$faculty[1],$subjects[1],date('Y-m-d',strtotime('+7 days'))],
            ['Course Portfolio','Submit a course portfolio for review.',$faculty[2],$subjects[2],date('Y-m-d',strtotime('+5 days'))],
            ['Term Reflection','Submit a reflection for the current term.',$faculty[2],$subjects[2],date('Y-m-d',strtotime('-1 day'))]
        ];
        foreach($requirementRows as $index=>[$label,$description,$facultyId,$subjectId,$deadline]){
            $title='Demo '.$departmentCode.' - '.$label;
            query('INSERT INTO requirements(title,type_id,department_id,description,academic_year,semester,deadline,active,created_by,subject_id)
                VALUES(?,?,?,?,?,?,?,1,?,?)',
                [$title,$typeId,$departmentId,$description,$term['academic_year'],$term['semester'],$deadline,$admin['id'],$subjectId]);
            $requirementId=(int)db()->lastInsertId();
            query('INSERT INTO requirement_assignments(requirement_id,user_id,active) VALUES(?,?,1)',[$requirementId,$facultyId]);
            $assignmentId=(int)db()->lastInsertId();
            if($index===0)seedDemoSubmission($assignmentId,$title,'Sample submission awaiting review.',$deadline,$storagePath,$createdFiles);
            if($index===1)seedDemoSubmission($assignmentId,$title,'Sample approved submission.',$deadline,$storagePath,$createdFiles,
                $headId,'Approved','Approved for demo purposes.');
            if($index===2)seedDemoSubmission($assignmentId,$title,'Sample submission returned for revision.',$deadline,$storagePath,$createdFiles,
                $headId,'Needs Revision','Please add a short reflection on the learning outcomes.');
        }
    }
    query('INSERT INTO activity_logs(actor_id,action,detail) VALUES(?,?,?)',
        [$admin['id'],'Demo data seeded',$demoHeadCount.' department heads and '.$demoFacultyCount.' faculty accounts']);
    db()->commit();$transactionStarted=false;
    fwrite(STDOUT,'Demo data added across '.count($departments)." departments for {$term['semester']} {$term['academic_year']}.\n");
    foreach($credentials as [$name,$employeeId,$password])fwrite(STDOUT,"{$name} ID: {$employeeId}\nPassword: {$password}\n");
} catch(Throwable $error) {
    if($transactionStarted && db()->inTransaction())db()->rollBack();
    foreach($createdFiles as $filePath)if(is_file($filePath) && !unlink($filePath))error_log('Unable to remove an uncommitted demo file: '.$filePath);
    throw $error;
}
