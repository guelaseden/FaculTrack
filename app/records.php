<?php
// Each row starts with the assignment, including those without a file.
function assignmentRows(array $user,bool $personal=false,int $id=0): array {
    $where=[]; $args=[];
    if ($personal || !manager($user)) {$where[]='a.user_id=?'; $args[]=$user['id'];}
    elseif (!admin($user)) {$where[]='u.department_id=? AND (r.department_id IS NULL OR r.department_id=u.department_id)';$args[]=$user['department_id'];}
    if ($id) {$where[]='a.id=?'; $args[]=$id;}
    $sql="SELECT a.id,a.user_id,a.requirement_id,r.department_id target_department,r.title requirement,r.deadline,r.academic_year,r.semester,r.description,
        (a.active AND r.active AND u.active AND (r.department_id IS NULL OR r.department_id=u.department_id)
         AND (u.role='Faculty' OR u.is_teaching)) assigned,
        t.id type_id,t.name document_type,t.template_name,(t.template_key IS NOT NULL) has_template,t.formats allowed_formats,u.full_name faculty,d.code department_code,d.name department,s.id submission_id,s.created_at original_date,
        r.subject_id,subj.code subject_code,subj.title subject_name,subj.section subject_section,v.original_name,v.remarks author_remarks,rv.remarks review_remarks,
        v.id version_id,v.title,v.version_no,v.submitted_at,v.deadline_snapshot,
        IF(s.id IS NULL,'Not Submitted','Submitted') submission_status,
        IF(s.id IS NULL,'—',COALESCE(rv.decision,'Pending Review')) review_status,
        CASE WHEN s.id IS NULL THEN IF(CURDATE()>r.deadline,'Overdue','Upcoming')
             ELSE IF(DATE(v.submitted_at)>v.deadline_snapshot,'Late','On Time') END timeliness,
        DATEDIFF(r.deadline,CURDATE()) days_left
        FROM requirement_assignments a JOIN users u ON u.id=a.user_id JOIN departments d ON d.id=u.department_id
        JOIN requirements r ON r.id=a.requirement_id JOIN document_types t ON t.id=r.type_id
        LEFT JOIN subjects subj ON subj.id=r.subject_id
        LEFT JOIN submissions s ON s.assignment_id=a.id AND s.retired=0
        LEFT JOIN submission_versions v ON v.id=(SELECT v2.id FROM submission_versions v2 WHERE v2.submission_id=s.id ORDER BY version_no DESC LIMIT 1)
        LEFT JOIN submission_reviews rv ON rv.version_id=v.id";
    return rows($sql.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY r.deadline,a.id',$args);
}
function record(array $user,int $id): array {
    allow($id>0); $r=assignmentRows($user,false,$id)[0]??[];allow((bool)$r);return $r;
}
function versions(int $assignment): array {
    return rows('SELECT v.*,s.retired,s.created_at original_date,rv.decision,rv.remarks review_remarks,rv.reviewed_at,u.full_name reviewer FROM submissions s JOIN submission_versions v ON v.submission_id=s.id LEFT JOIN submission_reviews rv ON rv.version_id=v.id LEFT JOIN users u ON u.id=rv.reviewer_id WHERE s.assignment_id=? ORDER BY v.id DESC',[$assignment]);
}
function verifyUpload(array $file,array $allowedFormats): array {
    valid(($file['error']??4)===UPLOAD_ERR_OK,'Choose a file no larger than '.MAX_UPLOAD_MB.' MB.');
    valid(is_string($file['tmp_name']??null) && is_string($file['name']??null),'Invalid file upload.');
    $tmp=$file['tmp_name'];$name=basename(str_replace(chr(92),'/',$file['name']));
    valid(is_uploaded_file($tmp) && filesize($tmp)>0 && filesize($tmp)<=MAX_UPLOAD_MB*1048576,'File must be no larger than '.MAX_UPLOAD_MB.' MB.');
    $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));valid(in_array($ext,$allowedFormats,true),'Choose a file format allowed for this document type.');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    if ($ext==='pdf') {valid($mime==='application/pdf' && file_get_contents($tmp,false,null,0,5)==='%PDF-','The file must contain a PDF document.');}
    else {
        $mainPart=$ext==='docx'?'word/document.xml':'xl/workbook.xml';
        $mainType=$ext==='docx'
            ?'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml'
            :'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml';
        $expectedMime=$ext==='docx'
            ?'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            :'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        $zip=new ZipArchive();
        valid(in_array($mime,['application/zip',$expectedMime,'application/octet-stream'],true) && $zip->open($tmp)===true,'Invalid Office document.');
        $types=$zip->statName('[Content_Types].xml');$contentTypes=$types && $types['size']<1048576?$zip->getFromName('[Content_Types].xml'):'';
        $ok=$contentTypes!==false && $zip->locateName($mainPart)!==false && str_contains((string)$contentTypes,$mainType);
        $zip->close();valid((bool)$ok,'The file contents do not match the selected Office format.');
    }
    return [$tmp,mb_substr($name,0,240),$ext];
}
function submitFile(array $user): string {
    allow(teaching($user));$id=(int)($_POST['assignment_id']??0);
    one('SELECT id FROM requirement_assignments WHERE id=? FOR UPDATE',[$id]);
    $r=record($user,$id);allow($r['user_id']==$user['id'] && (bool)$r['assigned']);
    valid(!$r['submission_id'] || $r['review_status']==='Needs Revision','Only missing or returned documents can be submitted.');
    [$tmp,$name,$format]=verifyUpload($_FILES['file']??[],explode(',',$r['allowed_formats']));
    $title=input('title',180,false)?:$r['requirement'];$remarks=input('remarks',4000,false);
    $sid=$r['submission_id'];
    if (!$sid) {query('INSERT INTO submissions(assignment_id,created_at) VALUES(?,NOW())',[$id]);$sid=db()->lastInsertId();}
    $key=bin2hex(random_bytes(32));$dir=realpath(storage());$public=realpath(ROOT.'/public');
    valid($dir && $dir!==$public && !str_starts_with($dir.DIRECTORY_SEPARATOR,$public.DIRECTORY_SEPARATOR),'Private storage is unavailable.');
    query('INSERT INTO submission_versions(submission_id,version_no,title,storage_key,original_name,format,remarks,submitted_at,deadline_snapshot) VALUES(?,?,?,?,?,?,?,NOW(),?)',[$sid,1+(int)$r['version_no'],$title,$key,$name,$format,$remarks,$r['deadline']]);
    valid(move_uploaded_file($tmp,$dir.'/'.$key),'File storage failed. Please retry.');
    $GLOBALS['pending_file']=$dir.'/'.$key;
    audit('Document submitted',$title.' · assignment '.$id);return 'Document submitted for review.';
}
function reviewFile(array $user,int $id): string {
    allow(manager($user));one('SELECT id FROM requirement_assignments WHERE id=? FOR UPDATE',[$id]);
    $r=record($user,$id);allow($r['user_id']!=$user['id']);
    valid($r['submission_id'] && $r['version_id']==($_POST['version_id']??0),'The document changed. Refresh and review the current version.');
    if (($_POST['action']??'')==='retire') {
        allow(admin($user));query('UPDATE submissions SET retired=1 WHERE id=?',[$r['submission_id']]);audit('Submission retired',$r['title']);return 'Submission removed from active records. History retained.';
    }
    valid($r['review_status']==='Pending Review','This version already has a decision.');
    $decision=choice('decision',['Approved','Needs Revision']);$remarks=input('remarks',4000,$decision==='Needs Revision');
    query('INSERT INTO submission_reviews(version_id,reviewer_id,decision,remarks,reviewed_at) VALUES(?,?,?,?,NOW())',[$r['version_id'],$user['id'],$decision,$remarks]);audit('Submission reviewed',$decision.' · '.$r['title']);return 'Review decision saved.';
}
function downloadFile(array $user,int $id): never {
    $version=one('SELECT v.*,s.assignment_id FROM submission_versions v JOIN submissions s ON s.id=v.submission_id WHERE v.id=?',[$id]);
    allow((bool)$version);record($user,(int)$version['assignment_id']);
    $path=storage().'/'.$version['storage_key'];valid(is_file($path),'This file is unavailable.');
    $mime=match($version['format']){
        'pdf'=>'application/pdf',
        'docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    };
    header('Content-Type: '.$mime);
    header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode($version['original_name']));header('Content-Length: '.filesize($path));readfile($path);exit;
}
function downloadTemplate(array $user,int $id): never {
    $template=one('SELECT template_key,template_name FROM document_types WHERE id=?',[$id]);
    allow((bool)$template && (bool)preg_match('/\A[a-f0-9]{64}\z/',$template['template_key']??''));
    $path=storage().DIRECTORY_SEPARATOR.$template['template_key'];valid(is_file($path),'This template is unavailable.');
    $mime=match(strtolower(pathinfo($template['template_name'],PATHINFO_EXTENSION))){
        'pdf'=>'application/pdf',
        'docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        default=>throw new RuntimeException('Stored template format is invalid.')
    };
    header('Content-Type: '.$mime);
    header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode($template['template_name']));
    header('Content-Length: '.filesize($path));readfile($path);exit;
}

