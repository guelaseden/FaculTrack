<?php
function facultyAccount(array $head,int $id,bool $lock=false): array {
    allow($head['role']==='Department Head' && (int)$head['department_id']>0);
    if(!$id)return [];
    $record=one("SELECT * FROM users WHERE id=? AND department_id=? AND role='Faculty' AND registration_status='Approved'".($lock?' FOR UPDATE':''),[$id,$head['department_id']]);
    allow((bool)$record);
    return $record;
}
function saveUser(array $user,int $id,bool $facultyOnly=false): string {
    if($facultyOnly){
        $existing=facultyAccount($user,$id,true);
        allow(in_array($_POST['action']??'',['','toggle_status','reset_password'],true));
    }else{
        allow(admin($user));
        $existing=$id?one('SELECT * FROM users WHERE id=? FOR UPDATE',[$id]):[];
        allow(!$id || (bool)$existing);
    }
    if(($_POST['action']??'')==='toggle_status'){
        valid($id!==(int)$user['id'],'You cannot change your own account status.');
        valid($id>0 && $existing['registration_status']==='Approved','Only approved accounts can be activated or deactivated.');
        $active=(int)!$existing['active'];
        query('UPDATE users SET active=? WHERE id=?',[$active,$id]);
        audit('Account active changed',$existing['email'].' · '.$existing['active'].' → '.$active);
        return $active?'Account activated.':'Account deactivated.';
    }
    if(($_POST['action']??'')==='reset_password'){
        valid($id>0,'Choose an account to reset.');
        query('UPDATE users SET password_hash=? WHERE id=?',[passwordHash(),$id]);
        query('DELETE FROM remember_tokens WHERE user_id=?',[$id]);
        audit('Password reset',$existing['email']);return 'Password reset successfully.';
    }
    $review=$id && $existing['registration_status']!=='Approved';
    if ($review) {
        $decision=choice('registration_decision',['approve','decline']);
        if ($decision==='decline') {
            query("UPDATE users SET active=0,registration_status='Declined' WHERE id=?",[$id]);
            audit('Registration declined',$existing['email']);return 'Registration declined.';
        }
    } else valid(!isset($_POST['registration_decision']),'This registration has already been reviewed.');
    $name=fullNameInput();$employeeId=employeeIdInput();$email=emailInput();
    valid(!(bool)one('SELECT id FROM users WHERE faculty_id=? AND id<>?',[$employeeId,$id]),'This Employee ID is already in use.');
    valid(!(bool)one('SELECT id FROM users WHERE email=? AND id<>?',[$email,$id]),'This email address is already in use.');
    $role=$facultyOnly?'Faculty':choice('role',ROLES);
    $department=$role==='Administrator'?null:($facultyOnly?(int)$user['department_id']:(int)($_POST['department_id']??0));
    if($role!=='Administrator')valid((bool)one('SELECT id FROM departments WHERE id=? AND active=1',[$department]),'Choose an active department.');
    $teaches=(int)($role==='Faculty' || ($role!=='Administrator' && isset($_POST['is_teaching'])));$active=(int)isset($_POST['active']);
    if ($review) $active=1;
    if($role==='Administrator'){
        one('SELECT id FROM system_settings WHERE id=1 FOR UPDATE');
        $otherAdministrator=one("SELECT id FROM users WHERE role='Administrator' AND id<>? LIMIT 1",[$id]);
        valid(!$otherAdministrator,'An Administrator account already exists.');
    }
    if ($id==$user['id']) {
        $unchangedAdmin=$role==='Administrator' && $teaches===0 && $department===null;
        $unchangedOther=$role!=='Administrator' && $teaches==(int)$existing['is_teaching'] && $department==(int)$existing['department_id'];
        allow($role===$existing['role'] && $active===1 && ($unchangedAdmin || $unchangedOther));
    }
    if ($id) {
        query('UPDATE users SET full_name=?,faculty_id=?,email=?,role=?,department_id=?,is_teaching=?,active=? WHERE id=?',[$name,$employeeId,$email,$role,$department,$teaches,$active,$id]);
        foreach (['role'=>$role,'is_teaching'=>$teaches,'active'=>$active,'department_id'=>$department] as $key=>$value) {
            if ($existing[$key]!=$value) audit('Account '.$key.' changed',$email.' · '.$existing[$key].' → '.$value);
        }
    } else query('INSERT INTO users(full_name,faculty_id,email,role,department_id,is_teaching,active,password_hash) VALUES(?,?,?,?,?,?,?,?)',[$name,$employeeId,$email,$role,$department,$teaches,$active,passwordHash()]);
    if ($review) {
        query("UPDATE users SET registration_status='Approved' WHERE id=?",[$id]);
        audit('Registration approved',$email.' · '.$role);return 'Registration approved. The account can now sign in.';
    }
    audit($id?'Account updated':'Account created',$email);return 'Account saved.';
}
function subjectRecord(array $user,int $id,bool $lock=false): array {
    allow(admin($user) || ($user['role']==='Department Head' && (int)$user['department_id']>0));
    if(!$id)return [];
    $sql='SELECT * FROM subjects WHERE id=?';
    $params=[$id];
    if(!admin($user)){$sql.=' AND department_id=? AND active=1';$params[]=$user['department_id'];}
    $record=one($sql.($lock?' FOR UPDATE':''),$params);
    allow((bool)$record);
    return $record;
}
function saveSubject(array $user,int $id): string {
    $old=subjectRecord($user,$id,true);
    if(($_POST['action']??'')==='archive'){
        allow((bool)$old);
        query('UPDATE subjects SET active=0 WHERE id=?',[$id]);
        audit('Subject archived',$old['code'].' · '.$old['section']);
        return 'Subject removed.';
    }
    $units=trim((string)($_POST['units']??''));
    valid((bool)preg_match('/^[0-9]{1,3}(?:\.[0-9]{1,2})?$/D',$units),'Enter units from 0 to 999.99 with up to two decimal places.');
    $code=strtoupper(input('code',30));$title=input('title',180);$section=input('section',48);
    $term=(int)($_POST['term_id']??0);$department=admin($user)?(int)($_POST['department_id']??0):(int)$user['department_id'];
    valid((bool)one('SELECT id FROM academic_terms WHERE id=? AND active=1',[$term]),'Choose an active academic term.');
    valid((bool)one('SELECT id FROM departments WHERE id=? AND active=1',[$department]),'Choose an active department.');
    $facultyId=(int)($_POST['faculty_user_id']??0)?:null;
    if ($facultyId) valid((bool)one("SELECT id FROM users WHERE id=? AND department_id=? AND active=1 AND (role='Faculty' OR is_teaching=1)",[$facultyId,$department]),'Choose an active faculty member from the selected department.');
    $active=(int)isset($_POST['active']);
    $values=[$code,$title,$units,$section,$term,$department,$facultyId,$active];
    if ($id) query('UPDATE subjects SET code=?,title=?,units=?,section=?,term_id=?,department_id=?,faculty_user_id=?,active=? WHERE id=?',[...$values,$id]);
    else query('INSERT INTO subjects(code,title,units,section,term_id,department_id,faculty_user_id,active) VALUES(?,?,?,?,?,?,?,?)',$values);
    audit($active?'Subject saved':'Subject archived',$code.' · '.$section);return $active?'Subject saved.':'Subject archived.';
}
function saveAcademicTerm(array $user,int $id): string {
    allow(admin($user));
    $existing=$id?one('SELECT * FROM academic_terms WHERE id=? FOR UPDATE',[$id]):[];allow(!$id || (bool)$existing);
    if(($_POST['action']??'')==='archive'){
        valid($id>0,'Choose an academic term to archive.');
        query('UPDATE academic_terms SET active=0 WHERE id=?',[$id]);
        audit('Academic term archived',$existing['semester'].' · '.$existing['academic_year']);return 'Academic term archived.';
    }
    $year=input('academic_year',20);
    valid((bool)preg_match('/^\d{4}[-–]\d{4}$/u',$year),'Enter an academic year such as 2026–2027.');
    $semester=choice('semester',TERMS);
    $start=input('start_date',10);$end=input('end_date',10);
    $startDate=DateTimeImmutable::createFromFormat('!Y-m-d',$start);$endDate=DateTimeImmutable::createFromFormat('!Y-m-d',$end);
    valid($startDate && $startDate->format('Y-m-d')===$start,'Enter a valid start date.');
    valid($endDate && $endDate->format('Y-m-d')===$end,'Enter a valid end date.');
    valid($end>=$start,'The end date must be on or after the start date.');
    valid(!(bool)one("SELECT id FROM academic_terms
        WHERE REPLACE(academic_year,'–','-')=REPLACE(?,'–','-') AND semester=? AND id<>?",[$year,$semester,$id]),
        'That academic term already exists.');
    $active=(int)isset($_POST['active']);
    if($id)query('UPDATE academic_terms SET academic_year=?,semester=?,start_date=?,end_date=?,active=? WHERE id=?',[$year,$semester,$start,$end,$active,$id]);
    else query('INSERT INTO academic_terms(academic_year,semester,start_date,end_date,active) VALUES(?,?,?,?,?)',[$year,$semester,$start,$end,$active]);
    audit($active?'Academic term saved':'Academic term archived',$semester.' · '.$year);
    return 'Academic term saved.';
}
function saveCatalog(array $user,string $page,int $id): string {
    allow(admin($user));allow(in_array($page,['types','departments'],true));
    $table=$page==='types'?'document_types':'departments';
    $existing=$id?one("SELECT * FROM $table WHERE id=? FOR UPDATE",[$id]):[];allow(!$id || (bool)$existing);
    if(($_POST['action']??'')==='archive'){
        allow((bool)$existing);
        query("UPDATE $table SET active=0 WHERE id=?",[$id]);
        audit($page==='types'?'Document type archived':'Catalog archived',$existing['name']);
        return $page==='types'?'Document type archived.':'Department archived.';
    }
    $name=input('name',120,$page!=='departments');$description=input('description',4000,false);$active=(int)isset($_POST['active']);
    if ($page==='types') {
        $formats=$_POST['formats']??[];$validFormats=is_array($formats) && (bool)$formats;
        if($validFormats)foreach($formats as $format)if(!is_string($format) || !in_array($format,['pdf','docx','xlsx'],true))$validFormats=false;
        valid($validFormats,'Select at least one allowed file format.');
        $formats=array_values(array_unique($formats));$allowedFormats=implode(',',$formats);
        $templateKey=$existing['template_key']??null;$templateName=$existing['template_name']??null;
        $upload=$_FILES['template']??[];$hasUpload=($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE;
        if($hasUpload || isset($_POST['remove_template'])){
            $directory=realpath(storage());$public=realpath(ROOT.'/public');
            valid($directory && $directory!==$public && !str_starts_with($directory.DIRECTORY_SEPARATOR,$public.DIRECTORY_SEPARATOR),'Private storage is unavailable.');
            if($hasUpload){
                [$tmp,$templateName]=verifyUpload($upload,['pdf','docx','xlsx']);
                $templateKey=bin2hex(random_bytes(32));
                valid(move_uploaded_file($tmp,$directory.DIRECTORY_SEPARATOR.$templateKey),'Template storage failed. Please retry.');
                $GLOBALS['pending_file']=$directory.DIRECTORY_SEPARATOR.$templateKey;
            }else{$templateKey=null;$templateName=null;}
            $oldKey=$existing['template_key']??null;
            if($oldKey && $oldKey!==$templateKey){
                valid((bool)preg_match('/\A[a-f0-9]{64}\z/',$oldKey),'The stored template key is invalid.');
                $GLOBALS['pending_delete_files'][]=$oldKey;
            }
        }
        if($id)query('UPDATE document_types SET name=?,description=?,formats=?,template_key=?,template_name=?,active=? WHERE id=?',[$name,$description,$allowedFormats,$templateKey,$templateName,$active,$id]);
        else query('INSERT INTO document_types(name,description,formats,template_key,template_name,active) VALUES(?,?,?,?,?,?)',[$name,$description,$allowedFormats,$templateKey,$templateName,$active]);
        audit($active?'Document type saved':'Document type archived',$name);return 'Document type saved.';
    } else {
        valid($name!=='','Department name is required.');
        valid((bool)preg_match('/^[\p{L}\p{N}][\p{L}\p{M}\p{N} ]*$/uD',$name),'Use letters, numbers, and spaces only for the department name.');
        $code=input('code',25,false);
        valid($code!=='','Department code is required.');
        valid((bool)preg_match('/^[A-Za-z0-9-]+$/D',$code),'Use letters, numbers, and hyphens only for the department code.');
        if ($id) query('UPDATE departments SET name=?,code=?,description=?,active=? WHERE id=?',[$name,$code,$description,$active,$id]);
        else query('INSERT INTO departments(name,code,description,active) VALUES(?,?,?,?)',[$name,$code,$description,$active]);
    }
    audit($active?'Catalog saved':'Catalog archived',$name);return 'Record saved.';
}
function scopedRequirement(array $user,int $id,bool $lock=false): array {
    $r=one('SELECT * FROM requirements WHERE id=?'.($lock?' FOR UPDATE':''),[$id]);allow((bool)$r && (admin($user) || $r['department_id']==$user['department_id']));return $r;
}
function parseDeadlineInput(string $value): string {
    $formats=preg_match('/^\d{2}-\d{2}-\d{4}$/D',$value)?['!m-d-Y','m-d-Y']
        :(preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value)?['!Y-m-d','Y-m-d']:null);
    valid($formats!==null,'Enter a valid deadline in MM-DD-YYYY format.');
    $date=DateTimeImmutable::createFromFormat($formats[0],$value);
    $errors=DateTimeImmutable::getLastErrors();
    valid($date && ($errors===false || ($errors['warning_count']===0 && $errors['error_count']===0))
        && $date->format($formats[1])===$value,'Enter a valid deadline in MM-DD-YYYY format.');
    return $date->format('Y-m-d');
}
function saveRequirement(array $user,int $id): string {
    allow(manager($user));$old=$id?scopedRequirement($user,$id,true):[];
    if(($_POST['action']??'')==='archive'){
        allow((bool)$old);
        query('UPDATE requirements SET active=0 WHERE id=?',[$id]);
        audit('Requirement archived',$old['title']);
        return 'Requirement archived.';
    }
    $title=input('title');$type=(int)($_POST['type_id']??0);$dep=admin($user)?((int)($_POST['department_id']??0)?:null):$user['department_id'];
    valid(!$dep || (bool)one('SELECT id FROM departments WHERE id=? AND active=1',[$dep]),'Choose an active department.');
    valid((bool)one('SELECT id FROM document_types WHERE id=? AND active=1',[$type]),'Select an active document type.');
    $year=input('academic_year',20);$term=choice('semester',TERMS);$deadline=parseDeadlineInput(input('deadline',10));
    $normalizedYear=str_replace('–','-',$year);
    $academicTerm=one("SELECT academic_year,active FROM academic_terms
        WHERE semester=? AND REPLACE(academic_year,'–','-')=?
        ORDER BY active DESC,id DESC LIMIT 1",[$term,$normalizedYear]);
    $sameExistingTerm=$old && $old['semester']===$term
        && str_replace('–','-',$old['academic_year'])===$normalizedYear;
    valid(($academicTerm && (bool)$academicTerm['active']) || $sameExistingTerm,
        'Select an active academic year and semester.');
    $year=$academicTerm['academic_year']??$old['academic_year'];
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$deadline);valid($date && $date->format('Y-m-d')===$deadline,'Enter a valid deadline.');
    $desc=input('description',4000,false);$active=(int)isset($_POST['active']);
    $subjectId=(int)($_POST['subject_id']??$old['subject_id']??0)?:null;
    if($subjectId){
        valid((bool)one("SELECT s.id FROM subjects s JOIN academic_terms t ON t.id=s.term_id
            WHERE s.id=? AND s.department_id=? AND (s.active=1 OR s.id=?) AND t.semester=?
            AND REPLACE(t.academic_year,'–','-')=?",[$subjectId,$dep,$old['subject_id']??0,$term,str_replace('–','-',$year)]),'Choose a subject from the same department, semester and academic year.');
    }
    $values=[$title,$type,$dep,$year,$term,$deadline,$desc,$active,$subjectId];
    if ($id) {query('UPDATE requirements SET title=?,type_id=?,department_id=?,academic_year=?,semester=?,deadline=?,description=?,active=?,subject_id=? WHERE id=?',[...$values,$id]);}
    else {query('INSERT INTO requirements(title,type_id,department_id,academic_year,semester,deadline,description,active,subject_id,created_by) VALUES(?,?,?,?,?,?,?,?,?,?)',[...$values,$user['id']]);$id=(int)db()->lastInsertId();}
    $people=$_POST['assignees']??[];valid(is_array($people) && (!$active || $people),'Select at least one eligible assignee.');
    query('UPDATE requirement_assignments SET active=0 WHERE requirement_id=?',[$id]);
    foreach (array_unique($people) as $person) {
        $u=one('SELECT * FROM users WHERE id=? AND active=1',[(int)$person]);valid($u && teaching($u) && (!$dep || $dep==$u['department_id']),'Each assignee must teach in the selected scope.');
        query('INSERT INTO requirement_assignments(requirement_id,user_id) VALUES(?,?) ON DUPLICATE KEY UPDATE active=1',[$id,$u['id']]);
    }
    if ($old && $old['deadline']!==$deadline) audit('Deadline changed',$title.' · '.deadlineLabel($old['deadline']).' → '.deadlineLabel($deadline));
    audit($active?'Requirement saved':'Requirement archived',$title);return 'Requirement saved.';
}
function saveProfile(array $user): string {
    $passwordTab=($_POST['settings_tab']??'')==='password';
    if ($passwordTab || !empty($_POST['password'])) {
        valid(password_verify($_POST['current_password']??'',$user['password_hash']),'Current password is incorrect.');
        query('UPDATE users SET password_hash=? WHERE id=?',[passwordHash(),$user['id']]);session_regenerate_id(true);
        if($passwordTab){audit('Password changed',$user['full_name']);return 'Password updated.';}
    }
    $employeeId=isset($_POST['employee_id'])?employeeIdInput():(string)($user['faculty_id']??'');
    query('UPDATE users SET faculty_id=?,full_name=?,email=? WHERE id=?',[$employeeId,fullNameInput(),emailInput(),$user['id']]);
    audit('Profile updated',$user['full_name']);return 'Profile saved.';
}

