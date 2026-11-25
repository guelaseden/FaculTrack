<?php
function managementView(array $u,string $page,string $title,int $id): void {
    $label=match($page){'users'=>'User','faculty'=>'Faculty','departments'=>'Department','types'=>'Document Type',default=>'Requirement'};
    if ($id || isset($_GET['new'])){editView($u,$page,$label,$id);return;}
    if($page==='manage' && !admin($u)){departmentRequirementsView($u);return;}
    $add='<a class="btn btn-primary" href="'.url($page,['new'=>1]).'" data-form-modal>'.icon('plus-lg').' Add '.h($label).'</a>';
    $headerFilters=in_array($page,['users','faculty','departments','types'],true);
    $items=[];$fields=[];$filters=[];
    if(!$headerFilters)pageHead($title,'',$add);
    if ($page==='users'){
        $items=rows("SELECT u.id,u.faculty_id employee_id,u.full_name,u.email,u.role,IF(u.role='Administrator','—',COALESCE(d.code,'—')) department,IF(u.registration_status='Approved',IF(u.active,'Active','Inactive'),u.registration_status) status,IF(u.role='Faculty' OR u.is_teaching,'Enabled','Disabled') teaching FROM users u LEFT JOIN departments d ON d.id=u.department_id ORDER BY full_name");
        $fields=['employee_id'=>'Employee ID','full_name'=>'Full Name','email'=>'Email Address','role'=>'Role','department'=>'Department','status'=>'Status'];
        $filters=array_intersect_key($fields,array_flip(['role','department','status']));
    } elseif ($page==='faculty'){
        $items=rows("SELECT id,faculty_id employee_id,full_name,email,IF(active,'Active','Inactive') status FROM users WHERE department_id=? AND role='Faculty' AND registration_status='Approved' ORDER BY full_name,id",[$u['department_id']]);
        $fields=['employee_id'=>'Employee ID','full_name'=>'Full Name','email'=>'Email Address','status'=>'Status'];
        $filters=['status'=>'Status'];
    } elseif ($page==='departments'){
        $items=rows("SELECT c.id,c.code,c.name,(SELECT COALESCE(GROUP_CONCAT(full_name ORDER BY full_name SEPARATOR ', '),'—') FROM users WHERE department_id=c.id AND role='Department Head' AND active=1) department_head,(SELECT COUNT(*) FROM users WHERE department_id=c.id AND role='Faculty' AND active=1) total_faculty FROM departments c ORDER BY c.name");
        $fields=['code'=>'Code','name'=>'Department Name','department_head'=>'Department Head','total_faculty'=>'Total Faculty'];
        $filters=[];
    } elseif ($page==='types'){
        $items=rows("SELECT c.id,c.name,c.description,c.formats,c.template_name,(c.template_key IS NOT NULL) has_template,c.active FROM document_types c ORDER BY c.name");
        $fields=['name'=>'Document Name','description'=>'Instructions','template'=>'Template','formats'=>'Allowed Formats'];$filters=[];
    } elseif ($page==='manage'){
        $items=rows("SELECT r.id,r.title,t.name document_type,COALESCE(d.name,'Institution-wide') department,r.academic_year,r.semester,r.deadline,IF(r.active,'Active','Archived') status FROM requirements r JOIN document_types t ON t.id=r.type_id LEFT JOIN departments d ON d.id=r.department_id ".(admin($u)?'':'WHERE r.department_id=?').' ORDER BY r.deadline',admin($u)?[]:[$u['department_id']]);
        $fields=['title'=>'Requirement','document_type'=>'Document type','department'=>'Scope','academic_year'=>'Academic year','semester'=>'Semester','deadline'=>'Deadline','status'=>'Status'];$filters=array_diff_key($fields,array_flip(['title','deadline']));
    }
    if($headerFilters){
        ob_start();
        $searchLabel=$page==='types'?'document types':$page;
        echo '<form class="directory-toolbar no-print" data-directory="'.h($page).'" method="get" role="search"><input type="hidden" name="p" value="'.h($page).'">';
        echo '<div class="directory-search"><input type="search" name="q" class="form-control" aria-label="Search '.h($searchLabel).'" placeholder="Search '.h($searchLabel).'" value="'.h($_GET['q']??'').'"><button type="submit" aria-label="Search '.h($searchLabel).'">'.icon('search').'</button></div>';
        if($page==='types'){
            echo '<a class="btn btn-primary" href="'.url('types',['new'=>1]).'" data-form-modal>'.icon('plus-lg').' Add Document Type</a>';
        }else{
            foreach($filters as $key=>$name){
                $options=filterOptions($items,$key);
                if(in_array($page,['users','faculty'],true) && $key==='status')$options=['Active'=>'Active','Inactive'=>'Inactive']+$options;
                if($page==='users' && $key==='role')$options=array_combine(ROLES,ROLES);
                $allLabel=$page==='users'?match($key){'role'=>'All roles','department'=>'All departments','status'=>'All statuses',default=>'All '.$name}:'All '.$name;
                selectBox($key,$name,[''=>$page==='faculty'?'All Statuses':$allLabel]+$options,$_GET[$key]??'','',true);
            }
            echo $add;
        }
        echo '</form>';
        pageHead($title,'',ob_get_clean());
    }
    $noun=match($page){'types'=>'document types','manage'=>'requirements',default=>$page};
    $body=[];
    foreach(paginate(filterRows($items,$filters,!$headerFilters,$page==='types'),$footer,$noun) as $r){
        $cells=[];
        foreach($fields as $key=>$name){
            $cells[]=match($key){
                'role'=>'<span class="badge user-role" data-role="'.h($r[$key]).'">'.h($r[$key]).'</span>',
                'status'=>badge($r[$key]),
                'deadline'=>h(deadlineLabel($r[$key])),
                'template'=>!empty($r['has_template'])?'<a class="document-template-link" href="'.url('template-download',['id'=>$r['id']]).'">'.icon('file-earmark-arrow-down').h($r['template_name']).'</a>':'—',
                'formats'=>h(strtoupper(str_replace(',',' · ',$r['formats']))),
                default=>h($r[$key])
            };
        }
        if($page==='departments'){
            $actions='<div class="department-actions"><a class="department-action" href="'.url($page,['id'=>$r['id']]).'" data-edit-modal aria-label="Edit '.h($r['name']).'" title="Edit department">'.icon('pencil').'</a>';
            ob_start();
            startForm('departments',(int)$r['id'],'class="department-action-form" data-confirm="Delete this department? It will be archived and retained in existing records." data-confirm-label="Delete" data-confirm-variant="danger"');
            echo '<input type="hidden" name="action" value="archive"><button class="department-action department-action-delete" type="submit" aria-label="Delete '.h($r['name']).'" title="Delete department">'.icon('trash3').'</button></form>';
            $cells[]=$actions.ob_get_clean().'</div>';
        }elseif(in_array($page,['users','faculty'],true)){
            $actions='<div class="user-actions"><a class="user-action" href="'.url($page,['id'=>$r['id']]).'" data-edit-modal aria-label="Edit '.h($r['full_name']).'" title="Edit user">'.icon('pencil').'</a>';
            $actions.='<button class="user-action" type="button" data-reset-user data-user-id="'.h($r['id']).'" data-user-name="'.h($r['full_name']).'" data-employee-id="'.h($r['employee_id']??'').'" aria-label="Reset password for '.h($r['full_name']).'" title="Reset password">'.icon('key').'</button>';
            if(in_array($r['status'],['Active','Inactive'],true)){
                $activate=$r['status']==='Inactive';$verb=$activate?'Activate':'Deactivate';
                if((int)$r['id']===(int)$u['id']){
                    $actions.='<button class="user-action-switch '.($activate?'user-action-switch-off':'user-action-switch-on').'" type="button" disabled aria-pressed="'.($activate?'false':'true').'" aria-label="Your account status cannot be changed" title="Your account status cannot be changed"></button>';
                }else{
                    ob_start();
                    startForm($page,(int)$r['id'],'class="user-action-form" data-confirm="'.h($verb.' this account?').'" data-confirm-label="'.h($verb).'" data-confirm-variant="'.($activate?'primary':'danger').'"');
                    echo '<input type="hidden" name="action" value="toggle_status"><button class="user-action-switch '.($activate?'user-action-switch-off':'user-action-switch-on').'" type="submit" aria-pressed="'.($activate?'false':'true').'" aria-label="'.h($verb.' '.$r['full_name']).'" title="'.h($verb.' account').'"></button></form>';
                    $actions.=ob_get_clean();
                }
            }
            $cells[]=$actions.'</div>';
        }elseif($page==='types'){
            $actions='<div class="document-type-actions"><a class="document-type-action" href="'.url($page,['id'=>$r['id']]).'" data-edit-modal aria-label="Edit '.h($r['name']).'" title="Edit document type">'.icon('pencil').'</a>';
            ob_start();
            startForm('types',(int)$r['id'],'class="document-type-action-form" data-confirm="Delete this document type? It will be archived and retained in existing records." data-confirm-label="Delete" data-confirm-variant="danger"');
            echo '<input type="hidden" name="action" value="archive"><button class="document-type-action document-type-action-delete" type="submit" aria-label="Delete '.h($r['name']).'" title="Delete document type">'.icon('trash3').'</button></form>';
            $cells[]=$actions.ob_get_clean().'</div>';
        }else{
            $edit=($r['status']??'')!=='Pending';
            $verb=$edit?'Edit':'Review';
            $cells[]='<div class="department-actions"><a class="department-action" href="'.url($page,['id'=>$r['id']]).'"'.($edit?' data-edit-modal':'').' aria-label="'.h($verb.' '.$r['title']).'" title="'.h($verb.' requirement').'">'.icon($edit?'pencil':'eye').'</a></div>';
        }
        $body[]=$cells;
    }
    tableView([...array_values($fields),in_array($page,['departments','users','faculty','types'],true)?'Actions':'Action'],$body,$page==='types'?'No document types match your search.':($page==='faculty'?'No faculty match this view.':'No records match this view.'),$page==='departments'?'department-table':($page==='types'?'document-type-table':''));
    if(in_array($page,['users','faculty'],true)){
        echo '<dialog class="reset-password-dialog" data-reset-password-dialog aria-labelledby="reset-password-title"><div class="reset-password-heading"><h2 id="reset-password-title">Reset password</h2><button class="reset-password-close" type="button" data-close-reset aria-label="Close dialog">'.icon('x-lg').'</button></div>';
        echo '<dl class="reset-password-user"><dt>Name</dt><dd data-reset-user-name></dd><dt>Employee ID</dt><dd data-reset-employee-id></dd></dl>';
        startForm($page,0,'id="reset-password-form"');
        echo '<input type="hidden" name="id" data-reset-user-id><input type="hidden" name="action" value="reset_password">';
        passwordField('password','New password','new-password',true);
        echo '<ul class="password-requirements" data-password-requirements aria-label="Password requirements"><li data-password-rule="length"><i class="bi bi-check-lg password-requirement-check" aria-hidden="true"></i><span>At least 8 characters</span></li><li data-password-rule="character"><i class="bi bi-check-lg password-requirement-check" aria-hidden="true"></i><span>Include at least one number or special character</span></li></ul>';
        echo '<div class="reset-password-actions"><button class="btn btn-primary" type="submit">Reset Password</button><button class="btn btn-outline-secondary" type="button" data-close-reset>Cancel</button></div></form></dialog>';
    }
    echo $footer;
}
function academicTermLabel(array $term): string {
    $semester=match($term['semester']){'First Semester'=>'1st Semester','Second Semester'=>'2nd Semester','Summer'=>'Midyear',default=>$term['semester']};
    return $semester.' '.$term['academic_year'];
}
function academicTermStatus(array $term): string {
    $today=date('Y-m-d');
    if(!(bool)$term['active'] || ($term['end_date'] && $term['end_date']<$today))return 'Inactive';
    if($term['start_date'] && $term['start_date']>$today)return 'Upcoming';
    return 'Active';
}
function academicTermsView(array $u,int $id): void {
    allow(admin($u));
    if($id || isset($_GET['new'])){academicTermEditView($id);return;}
    $items=rows('SELECT id,academic_year,semester,start_date,end_date,active FROM academic_terms ORDER BY academic_year DESC,FIELD(semester,\'First Semester\',\'Second Semester\',\'Summer\'),id DESC');
    foreach($items as &$term){
        $term['term_name']=academicTermLabel($term);
        $term['start_label']=$term['start_date']?dateLabel($term['start_date']):'—';
        $term['end_label']=$term['end_date']?dateLabel($term['end_date']):'—';
        $term['status']=academicTermStatus($term);
    }
    unset($term);
    $items=filterRows($items,[],false);
    $footer=null;$body=[];
    foreach(paginate($items,$footer,'academic terms') as $term){
        $actions='<div class="term-actions"><a class="term-action" href="'.url('academic-terms',['id'=>$term['id']]).'" data-edit-modal aria-label="Edit '.h($term['term_name']).'" title="Edit academic term">'.icon('pencil').'</a>';
        ob_start();
        startForm('academic-terms',(int)$term['id'],'class="term-action-form" data-confirm="Delete this academic term? It will be archived and retained for existing records." data-confirm-label="Delete" data-confirm-variant="danger"');
        echo '<input type="hidden" name="action" value="archive"><button class="term-action term-action-delete" type="submit" aria-label="Delete '.h($term['term_name']).'" title="Delete academic term">'.icon('trash3').'</button></form>';
        $body[]=[h($term['term_name']),h($term['start_label']),h($term['end_label']),badge($term['status']),$actions.ob_get_clean().'</div>'];
    }
    ob_start();
    $addTerm='<a class="btn btn-primary" href="'.url('academic-terms',['new'=>1]).'" data-form-modal>'.icon('plus-lg').' Add Term</a>';
    echo '<form class="directory-toolbar no-print" data-directory="academic-terms" method="get" role="search"><input type="hidden" name="p" value="academic-terms"><div class="directory-search"><input type="search" name="q" class="form-control" aria-label="Search academic terms" placeholder="Search academic terms" value="'.h($_GET['q']??'').'"><button type="submit" aria-label="Search academic terms">'.icon('search').'</button></div>'.$addTerm.'</form>';
    pageHead('Academic Terms','',ob_get_clean());
    tableView(['Term Name','Start Date','End Date','Status','Actions'],$body,'No academic terms match your search.','academic-term-table');
    echo $footer;
}
function academicTermEditView(int $id): void {
    $term=$id?one('SELECT * FROM academic_terms WHERE id=?',[$id]):[];
    allow(!$id || (bool)$term);
    $cfg=settings();
    $term+=['academic_year'=>$cfg['academic_year'],'semester'=>$cfg['semester'],'start_date'=>'','end_date'=>'','active'=>1];
    if(preg_match('/\A(\d{4})([-–])(\d{4})\z/u',$cfg['academic_year'],$match)){
        $startYear=(int)$match[1];$separator=$match[2];$currentYear=$cfg['academic_year'];
    }else{
        $startYear=(int)date('Y');$separator='–';$currentYear=sprintf('%04d–%04d',$startYear,$startYear+1);
    }
    $previousYear=sprintf('%04d%s%04d',$startYear-1,$separator,$startYear);
    $yearOptions=[$currentYear=>$currentYear,$previousYear=>$previousYear];
    if($id && !isset($yearOptions[$term['academic_year']]))$yearOptions[$term['academic_year']]=$term['academic_year'];
    pageHead(($id?'Edit':'Add').' Academic Term','',linkTo('academic-terms','Back to Academic Terms'));
    panel('Academic term details');startForm('academic-terms',$id);
    selectBox('academic_year','Academic year',$yearOptions,$term['academic_year'],'required');
    $semesterOptions=array_combine(TERMS,['1st Semester','2nd Semester','Midyear']);
    selectBox('semester','Term name',$semesterOptions,$term['semester']);
    field('start_date','Start date',$term['start_date'],'date');
    field('end_date','End date',$term['end_date'],'date');
    checkBox('active','Available for use (clear to archive)',(bool)$term['active']);
    saveButton('Save Term');endPanel();
}
function directoryTermFilters(array $additionalYears=[],array $defaults=[]): array {
    $cfg=settings();
    $yearLabels=array_column(rows("SELECT DISTINCT REPLACE(academic_year,'–','-') year_label FROM academic_terms"),'year_label');
    $currentYear=str_replace('–','-',$cfg['academic_year']);
    $yearLabels=array_merge($yearLabels,$additionalYears,[$currentYear]);
    if(preg_match('/^(\d{4})-(\d{4})$/',$currentYear,$parts)){
        $yearLabels[]=($parts[1]-1).'-'.($parts[2]-1);
    }
    sort($yearLabels,SORT_STRING);
    $years=array_combine($yearLabels,$yearLabels);
    $requestedYear=str_replace('–','-',(string)($_GET['academic_year']??''));
    $defaultYear=str_replace('–','-',$defaults['academic_year']??'');
    $year=$years[$requestedYear]??$years[$defaultYear]??end($years);
    $semesters=array_combine(TERMS,['1st Semester','2nd Semester','Midyear']);
    $semester=$_GET['semester']??$defaults['semester']??$cfg['semester'];
    if(!is_string($semester) || !isset($semesters[$semester]))$semester=TERMS[0];
    return [$semesters,$semester,$years,$year];
}
function departmentRequirementsView(array $u): void {
    $dep=$u['department_id'];
    $storedYears=array_column(rows("SELECT DISTINCT REPLACE(academic_year,'–','-') year_label FROM requirements WHERE department_id=?",[$dep]),'year_label');
    [$semesters,$semester,$years,$year]=directoryTermFilters($storedYears);
    $rows=rows("SELECT r.id,r.title,COALESCE(s.code,'—') subject,r.subject_id,r.deadline,d.name document_type,
        CASE WHEN r.active=0 THEN 'Archived'
             WHEN counts.assigned>0 AND counts.assigned=counts.submitted THEN 'Completed'
             WHEN EXISTS(SELECT 1 FROM academic_terms t WHERE REPLACE(t.academic_year,'–','-')=REPLACE(r.academic_year,'–','-') AND t.semester=r.semester AND t.start_date>CURDATE()) THEN 'Upcoming'
             ELSE 'Active' END status
        FROM requirements r JOIN document_types d ON d.id=r.type_id LEFT JOIN subjects s ON s.id=r.subject_id
        LEFT JOIN (SELECT a.requirement_id,COUNT(*) assigned,
            SUM(EXISTS(SELECT 1 FROM submissions f WHERE f.assignment_id=a.id AND f.retired=0)) submitted
            FROM requirement_assignments a JOIN users u ON u.id=a.user_id
            WHERE a.active=1 AND u.active=1 AND u.department_id=? AND (u.role='Faculty' OR u.is_teaching)
            GROUP BY a.requirement_id) counts ON counts.requirement_id=r.id
        WHERE r.department_id=? AND r.semester=? AND REPLACE(r.academic_year,'–','-')=?
        ORDER BY r.deadline,r.title,r.id",[$dep,$dep,$semester,$year]);
    $subjects=rows("SELECT s.id,s.code,s.section FROM subjects s JOIN academic_terms t ON t.id=s.term_id
        WHERE s.department_id=? AND t.semester=? AND REPLACE(t.academic_year,'–','-')=? ORDER BY s.code,s.section",[$dep,$semester,$year]);
    $options=[''=>'All Subjects'];
    foreach($subjects as $subject)$options[$subject['id']]=$subject['code'].' · '.$subject['section'];
    $subjectId=(string)($_GET['subject_id']??'');
    if(!array_key_exists($subjectId,$options))$subjectId='';
    $statuses=['Active','Upcoming','Completed','Archived'];
    $addUrl=url('manage',['new'=>1,'semester'=>$semester,'academic_year'=>$year,'subject_id'=>$subjectId]);
    pageHead('Requirements','','<a class="btn btn-primary" data-form-modal href="'.$addUrl.'">'.icon('plus-lg').' Add Requirement</a>');
    echo '<form class="directory-toolbar requirements-toolbar" method="get" role="search"><input type="hidden" name="p" value="manage">';
    echo '<div class="directory-search"><input type="search" name="q" class="form-control" aria-label="Search requirements" placeholder="Search requirements" value="'.h($_GET['q']??'').'"><button type="submit" aria-label="Search requirements">'.icon('search').'</button></div>';
    selectBox('semester','Semester',$semesters,$semester,'',true);
    selectBox('academic_year','Academic Year',$years,$year,'',true);
    selectBox('subject_id','Subject',$options,$subjectId,'',true);
    selectBox('status','Status',[''=>'All Statuses']+array_combine($statuses,$statuses),$_GET['status']??'','',true);
    echo '</form>';
    if($subjectId!=='')$rows=array_filter($rows,fn($r)=>(string)$r['subject_id']===$subjectId);
    $body=[];
    foreach(paginate(filterRows($rows,['status'=>'Status'],false),$footer,'requirements') as $r){
        $name=h($r['title']);
        ob_start();
        echo '<div class="department-actions"><a class="department-action" href="'.url('manage',['id'=>$r['id']]).'" data-edit-modal aria-label="Edit '.$name.'" title="Edit requirement">'.icon('pencil').'</a>';
        if($r['status']!=='Archived'){
            startForm('manage',(int)$r['id'],'class="department-action-form" data-confirm="Archive '.$name.'? Its submission history will be kept." data-confirm-label="Archive"');
            echo '<input type="hidden" name="action" value="archive"><button class="department-action department-action-delete" aria-label="Archive '.$name.'" title="Archive requirement">'.icon('trash3').'</button></form>';
        }
        echo '</div>';
        $body[]=[$name,h($r['subject']),h(deadlineLabel($r['deadline']).' 11:59 PM'),h($r['document_type']),badge($r['status']),ob_get_clean()];
    }
    tableView(['Requirement Name','Subject','Due Date & Time','Required Document','Status','Actions'],$body,'No requirements match this view.','subject-directory');
    echo $footer;
}
function subjectsView(array $u,int $id): void {
    allow(manager($u));
    if($id || isset($_GET['new'])){subjectEditView($u,$id);return;}
    if(!admin($u)){
        [$semesters,$semester,$years,$year]=directoryTermFilters();
        ob_start();
        echo '<form class="directory-toolbar" data-directory="department-subjects" method="get" role="search"><input type="hidden" name="p" value="subjects">';
        echo '<div class="directory-search"><input type="search" name="q" class="form-control" aria-label="Search subjects" placeholder="Search subjects" value="'.h($_GET['q']??'').'"><button type="submit" aria-label="Search subjects">'.icon('search').'</button></div>';
        selectBox('semester','Semester',$semesters,$semester,'',true);
        selectBox('academic_year','Academic Year',$years,$year,'',true);
        echo '<a class="btn btn-primary" href="'.url('subjects',['new'=>1]).'" data-form-modal>'.icon('plus-lg').' Add Subject</a></form>';
        pageHead('Subjects','',ob_get_clean());
        $sql="SELECT s.id,s.code,s.title,s.units,COALESCE(u.full_name,'Unassigned') faculty
            FROM subjects s JOIN academic_terms t ON t.id=s.term_id LEFT JOIN users u ON u.id=s.faculty_user_id
            WHERE s.department_id=? AND s.active=1 AND t.semester=? AND REPLACE(t.academic_year,'–','-')=?";
        $params=[$u['department_id'],$semester,$year];
        $subjects=rows($sql.' ORDER BY s.code,s.title,s.section,s.id',$params);
        $body=[];
        foreach(paginate(filterRows($subjects,[],false),$footer,'subjects') as $row){
            $name=h($row['code'].' · '.$row['title']);
            ob_start();
            echo '<div class="department-actions"><a href="'.url('subjects',['id'=>$row['id']]).'" class="department-action" data-edit-modal title="Edit subject" aria-label="Edit '.$name.'">'.icon('pencil').'</a>';
            startForm('subjects',(int)$row['id'],'class="department-action-form" data-confirm="Remove '.$name.' from the active subjects list?" data-confirm-label="Delete" data-confirm-variant="danger"');
            echo '<input type="hidden" name="action" value="archive"><button type="submit" class="department-action department-action-delete" title="Delete subject" aria-label="Delete '.$name.'">'.icon('trash3').'</button></form></div>';
            $body[]=[h($row['code']),h($row['title']),$row['units']===null?'—':h((float)$row['units']),h($row['faculty']),ob_get_clean()];
        }
        tableView(['Subject Code','Subject Name','Units','Faculty','Actions'],$body,'No subjects match this view.','subject-directory');
        echo $footer;
        return;
    }
    $cfg=settings();$terms=rows('SELECT id,academic_year,semester FROM academic_terms WHERE active=1 ORDER BY academic_year DESC,id DESC');
    $hasTermFilter=array_key_exists('term_id',$_GET);$termId=(int)($_GET['term_id']??0);
    if(!$hasTermFilter && !$termId && $terms){foreach($terms as $term)if($term['academic_year']===$cfg['academic_year'] && $term['semester']===$cfg['semester']){$termId=(int)$term['id'];break;}if(!$termId)$termId=(int)$terms[0]['id'];}
    $departmentId=(int)($_GET['department_id']??0);$search=trim((string)($_GET['q']??''));
    $termOptions=[''=>'All terms'];foreach($terms as $term)$termOptions[$term['id']]=$term['academic_year'].' · '.$term['semester'];
    $departments=rows('SELECT id,name FROM departments WHERE active=1 ORDER BY name');$departmentOptions=[''=>'All departments'];foreach($departments as $department)$departmentOptions[$department['id']]=$department['name'];
    ob_start();
    echo '<form class="directory-toolbar no-print" data-directory="subjects" method="get" role="search"><input type="hidden" name="p" value="subjects">';
    echo '<div class="directory-search"><input type="search" name="q" class="form-control" aria-label="Search subjects" placeholder="Search subjects" value="'.h($search).'"><button type="submit" aria-label="Apply filters">'.icon('search').'</button></div>';
    selectBox('term_id','Academic term',$termOptions,$termId?:'','',true);
    selectBox('department_id','Department',$departmentOptions,$departmentId?:'','',true);
    echo '<a class="btn btn-primary" href="'.url('subjects',['new'=>1]).'">'.icon('plus-lg').' Add Subject</a></form>';
    pageHead('Subjects','',ob_get_clean());
    $sql="SELECT s.id,s.code,s.title,s.section,d.name department,COALESCE(u.full_name,'Unassigned') faculty,IF(s.active,'Active','Archived') status
        FROM subjects s JOIN academic_terms t ON t.id=s.term_id JOIN departments d ON d.id=s.department_id
        LEFT JOIN users u ON u.id=s.faculty_user_id WHERE 1=1";$params=[];
    foreach(['s.term_id'=>$termId,'s.department_id'=>$departmentId] as $column=>$value)if($value){$sql.=" AND $column=?";$params[]=$value;}
    if($search!==''){$sql.=" AND (s.code LIKE ? OR s.title LIKE ? OR s.section LIKE ?)";array_push($params,"%$search%","%$search%","%$search%");}
    $items=rows($sql.' ORDER BY s.code,s.section',$params);$body=[];
    foreach(paginate($items,$footer,'subjects') as $item)$body[]=[h($item['code']),h($item['title']),h($item['section']),h($item['department']),h($item['faculty']),badge($item['status']),'<div class="department-actions"><a href="'.url('subjects',['id'=>$item['id']]).'" class="department-action" data-edit-modal title="Edit subject" aria-label="Edit '.h($item['code'].' · '.$item['title']).'">'.icon('pencil').'</a></div>'];
    tableView(['Subject Code','Subject Title','Section','Department','Assigned Faculty','Status','Actions'],$body,'No subjects match these filters.');
    echo $footer;
}
function subjectEditView(array $u,int $id): void {
    $record=subjectRecord($u,$id);
    $terms=rows('SELECT id,academic_year,semester FROM academic_terms WHERE active=1 ORDER BY academic_year DESC,id DESC');
    $departments=rows('SELECT id,name FROM departments WHERE active=1 ORDER BY name');
    $faculty=rows("SELECT u.id,u.full_name,d.code FROM users u JOIN departments d ON d.id=u.department_id WHERE u.active=1 AND (u.role='Faculty' OR u.is_teaching=1) ".(admin($u)?'':'AND u.department_id=?').' ORDER BY d.code,u.full_name',admin($u)?[]:[$u['department_id']]);
    pageHead(($id?'Edit':'Add').' Subject','',linkTo('subjects','Back to Subjects'));
    panel('Subject details');startForm('subjects',$id);
    if(!$terms){echo '<p>An administrator must add an active academic term before a subject can be saved.</p></form>';endPanel();return;}
    echo '<div class="subject-edit-grid">';
    field('code','Subject code',$record['code']??'');
    field('title','Subject name',$record['title']??'');
    echo '<label class="form-label">Units<input class="form-control" type="number" name="units" min="0" max="999.99" step="0.01" required value="'.h($record['units']??'').'"></label>';
    field('section','Section',$record['section']??'','text',true,'Section');
    $termOptions=[];foreach($terms as $term)$termOptions[$term['id']]=$term['academic_year'].' · '.$term['semester'];
    selectBox('term_id','Academic term',[''=>'Select academic term']+$termOptions,$record['term_id']??'','',true);
    if(admin($u))selectBox('department_id','Department',[''=>'Select department']+array_column($departments,'name','id'),$record['department_id']??'','',true);
    $facultyOptions=[''=>'Select assigned faculty'];foreach($faculty as $person)$facultyOptions[$person['id']]=$person['full_name'].' · '.$person['code'];
    selectBox('faculty_user_id','Assigned faculty',$facultyOptions,$record['faculty_user_id']??'','',true);
    echo '</div>';
    checkBox('active','Subject active',(bool)($record['active']??true));saveButton('Save Subject');endPanel();
}
function editView(array $u,string $page,string $label,int $id): void {
    $table=match($page){'users','faculty'=>'users','departments'=>'departments','types'=>'document_types',default=>'requirements'};
    if($page==='faculty')$r=facultyAccount($u,$id);
    else $r=$id?($page==='manage'?scopedRequirement($u,$id):one("SELECT * FROM $table WHERE id=?",[$id])):[];allow(!$id || (bool)$r);
    $departments=array_column(rows('SELECT id,name FROM departments WHERE active=1 ORDER BY name'),'name','id');
    $formAttributes=$page==='types'?'enctype="multipart/form-data"':(in_array($page,['users','faculty'],true)?'data-user-form':($page==='departments'?'data-department-form':($page==='manage'?'data-requirement-form':'')));
    pageHead(($id?'Edit ':'Add ').$label,'',linkTo($page,'Back to list'));panel($label.' details');startForm($page,$id,$formAttributes);
    if(in_array($page,['users','faculty'],true)){
        field('full_name','Full name',$r['full_name']??'');field('employee_id','Employee ID',$r['faculty_id']??'');
        echo '<div class="user-email-group">';
        field('email','Email address',$r['email']??'','email');
        echo '<p class="user-email-error" data-email-error hidden aria-live="polite">Invalid email format</p></div>';
        if(!$id){
            passwordField('password','Initial password','new-password',true,'user-add');
            echo '<ul class="password-requirements" data-password-requirements aria-label="Password requirements"><li data-password-rule="length"><i class="bi bi-check-lg password-requirement-check" aria-hidden="true"></i><span>At least 8 characters</span></li><li data-password-rule="character"><i class="bi bi-check-lg password-requirement-check" aria-hidden="true"></i><span>Include at least one number or special character</span></li></ul>';
        }
        if($page==='users'){
        $selectedRole=$r['role']??'Faculty';
        $roleOptions=array_combine(ROLES,ROLES);
        $otherAdministrator=one("SELECT id FROM users WHERE role='Administrator' AND id<>? LIMIT 1",[$id]);
        if($otherAdministrator && $selectedRole!=='Administrator')unset($roleOptions['Administrator']);
        selectBox('role','Primary role',$roleOptions,$selectedRole,'data-role-select');
        $adminRole=$selectedRole==='Administrator';
        echo '<div data-department-field'.($adminRole?' hidden':'').'>';
        selectBox('department_id','Department',[''=>'Choose a department']+$departments,$r['department_id']??'',$adminRole?'disabled':'required');
        echo '</div><div data-teaching-field'.($adminRole?' hidden':'').'>';
        checkBox('is_teaching','Has Teaching Responsibilities',(bool)($r['is_teaching']??false),'data-teaching'.($adminRole?' disabled':''));
        echo '</div>';
        }
    } elseif ($page==='manage'){
        $cfg=settings();field('title','Requirement title',$r['title']??'');
        selectBox('type_id','Document type',array_column(rows('SELECT id,name FROM document_types WHERE active=1 ORDER BY name'),'name','id'),$r['type_id']??'');
        if(admin($u))selectBox('department_id','Target department',[''=>'Institution-wide']+$departments,$r['department_id']??'');
        if(!admin($u)){
            [$semesters,$semester,$years,$year]=directoryTermFilters(isset($r['academic_year'])?[str_replace('–','-',$r['academic_year'])]:[]);
            selectBox('semester','Semester',$semesters,$r['semester']??$semester,'data-requirement-semester');
            $yearValue=$r['academic_year']??$year;
            $yearOptions=[];
            foreach($years as $label)$yearOptions[str_replace('-','–',$label)]=$label;
            selectBox('academic_year','Academic Year',$yearOptions,str_replace('-','–',$yearValue),'data-requirement-year');
            $subjects=rows("SELECT s.id,s.code,s.section,s.faculty_user_id,t.semester,t.academic_year FROM subjects s JOIN academic_terms t ON t.id=s.term_id
                WHERE s.department_id=? AND (s.active=1 OR s.id=?) ORDER BY s.code,s.section",[$u['department_id'],$r['subject_id']??0]);
            $selectedSubject=(string)($r['subject_id']??$_GET['subject_id']??'');
            echo '<label class="form-label">Subject<select class="form-select" name="subject_id" data-requirement-subject><option value="">No specific subject</option>';
            foreach($subjects as $subject){
                $selected=(string)$subject['id']===$selectedSubject?' selected':'';
                echo '<option value="'.h($subject['id']).'" data-year="'.h(str_replace('–','-',$subject['academic_year'])).'" data-semester="'.h($subject['semester']).'" data-faculty-id="'.h($subject['faculty_user_id']??'').'"'.$selected.'>'.h($subject['code'].' · '.$subject['section'].' · '.$subject['semester'].' '.$subject['academic_year']).'</option>';
            }
            echo '</select></label>';
        }else{
            field('academic_year','Academic year',$r['academic_year']??$cfg['academic_year']);selectBox('semester','Semester',array_combine(TERMS,TERMS),$r['semester']??$cfg['semester']);
        }
        echo '<label class="form-label">Deadline (Manila time, MM-DD-YYYY)<input class="form-control" name="deadline" type="text" value="'.h(isset($r['deadline'])?deadlineLabel($r['deadline']):'').'" placeholder="MM-DD-YYYY" aria-label="Deadline (Manila time, MM-DD-YYYY)"></label>';
        textarea('description','Description',$r['description']??'');
        $people=rows("SELECT u.id,u.full_name,d.code FROM users u JOIN departments d ON d.id=u.department_id WHERE u.active=1 AND (u.role='Faculty' OR u.is_teaching=1) ".(admin($u)?'':'AND u.department_id=?').' ORDER BY d.code,u.full_name',admin($u)?[]:[$u['department_id']]);
        $selected=array_column(rows('SELECT user_id FROM requirement_assignments WHERE requirement_id=? AND active=1',[$id]),'user_id');
        $subjectFacultyId='';
        if(!admin($u)){
            foreach($subjects as $subject)if((string)$subject['id']===$selectedSubject){$subjectFacultyId=(string)($subject['faculty_user_id']??'');break;}
            if(!$id && $subjectFacultyId!=='')$selected[]=$subjectFacultyId;
        }
        $assigneeWarning=$subjectFacultyId!=='' && !in_array($subjectFacultyId,$selected)
            ?'The faculty member assigned to this subject is not selected. Add them to ensure they receive the requirement.':'';
        echo '<fieldset data-requirement-assignees><legend>Assigned faculty</legend>';
        foreach($people as $person)echo '<label class="form-check"><input class="form-check-input" type="checkbox" name="assignees[]" data-assignee-id="'.h($person['id']).'" value="'.h($person['id']).'" '.(in_array($person['id'],$selected)?'checked':'').'> '.h($person['full_name'].' · '.$person['code']).'</label>';
        echo '<p class="subject-assignee-warning" data-subject-assignee-warning role="status"'.($assigneeWarning===''?' hidden':'').'>'.h($assigneeWarning).'</p></fieldset>';
    } elseif($page==='types') {
        $cfg=settings();field('name','Document type name',$r['name']??'');textarea('description','Instructions',$r['description']??'',true);
        $selectedFormats=explode(',',$r['formats']??$cfg['formats']);
        echo '<fieldset class="document-format-options"><legend>Allowed faculty submission formats</legend>';
        foreach(['pdf','docx','xlsx'] as $format)echo '<label class="form-check"><input type="checkbox" class="form-check-input" name="formats[]" value="'.$format.'" '.(in_array($format,$selectedFormats,true)?'checked':'').'> '.strtoupper($format).'</label>';
        echo '</fieldset><label class="form-label">Template file<input class="form-control" type="file" name="template" accept=".pdf,.docx,.xlsx"><small>Optional. Faculty can download the uploaded template. Maximum file size: '.MAX_UPLOAD_MB.' MB.</small></label>';
        if(!empty($r['template_key'])){
            echo '<p class="document-current-template">Current template: <a class="document-template-link" href="'.url('template-download',['id'=>$id]).'">'.icon('file-earmark-arrow-down').h($r['template_name']).'</a></p>';
            echo '<label class="form-check"><input class="form-check-input" type="checkbox" name="remove_template" value="1"> Remove current template</label>';
        }
    } else {
        field('name',$label.' name',$r['name']??'');field('code','Department code',$r['code']??'');textarea('description','Description',$r['description']??'',true);
    }
    if($page==='users' && $id && $r['registration_status']!=='Approved') {
        echo '<p>Registration status: '.h($r['registration_status']).'</p><button class="btn btn-primary" name="registration_decision" value="approve">Approve registration</button> <button class="btn btn-outline-secondary" name="registration_decision" value="decline" data-confirm="Decline this registration?" data-confirm-label="Decline" data-confirm-variant="danger">Decline registration</button></form>';
        endPanel();return;
    }
    checkBox('active',in_array($page,['users','faculty'],true)?'Account active':'Active (clear to archive)',(bool)($r['active']??true));saveButton();endPanel();
}
function settingsView(array $u,string $page): void {
    $tab=in_array($_GET['tab']??'profile',['profile','password'],true)?(string)($_GET['tab']??'profile'):'profile';
    pageHead('Settings');
    echo '<nav class="settings-tabs" aria-label="Settings sections">'.linkTo('settings',admin($u)?'Admin Profile':$u['role'].' Profile',['tab'=>'profile'],'settings-tab'.($tab==='profile'?' active':'')).linkTo('settings','Change Password',['tab'=>'password'],'settings-tab'.($tab==='password'?' active':'')).'</nav>';
    if($tab==='profile') {
        panel($u['role'].' Profile');startForm('profile');echo '<input type="hidden" name="settings_tab" value="profile">';
        field('employee_id','Employee ID',$u['faculty_id']??'');
        field('full_name','Full name',$u['full_name']);field('email','Email address',$u['email'],'email');saveButton('Save Profile');endPanel();
    } else {
        panel('Change Password');startForm('profile');echo '<input type="hidden" name="settings_tab" value="password">';
        passwordField('current_password','Current password','current-password',true);passwordField('password','New password','new-password',true);
        echo '<ul class="password-requirements" data-password-requirements aria-label="Password requirements"><li data-password-rule="length"><i class="bi bi-check-lg password-requirement-check" aria-hidden="true"></i><span>At least 8 characters</span></li><li data-password-rule="character"><i class="bi bi-check-lg password-requirement-check" aria-hidden="true"></i><span>Include at least one number or special character</span></li></ul>';
        saveButton('Update Password');endPanel();
    }
}
function logsView(): void {
    pageHead('Activity Logs');$items=rows("SELECT l.created_at,COALESCE(u.full_name,'System') actor,l.action,l.detail FROM activity_logs l LEFT JOIN users u ON u.id=l.actor_id ORDER BY l.id DESC");
    $body=[];foreach(paginate(filterRows($items,['action'=>'Action']),$footer,'log entries') as $r)$body[]=array_map('h',array_values($r));tableView(['Date and time','Actor','Action','Details'],$body);echo $footer;
}
