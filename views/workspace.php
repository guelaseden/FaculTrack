<?php
function activeRows(array $records): array {return array_values(array_filter($records,fn($r)=>$r['assigned']));}
function historyRows(array $records): array {
    $out=[];foreach($records as $r)foreach(versions((int)$r['id']) as $v){
        $out[]=array_merge($r,['title'=>$v['title'],'version_no'=>$v['version_no'],'submitted_at'=>$v['submitted_at'],'original_date'=>$v['original_date'],'review_status'=>$v['decision']?:'Pending Review','remarks'=>$v['review_remarks'],'updated'=>$v['reviewed_at']?:$v['submitted_at'],'record_state'=>$v['retired']?'Retired':'Retained','submission_status'=>'Submitted','timeliness'=>substr($v['submitted_at'],0,10)>$v['deadline_snapshot']?'Late':'On Time']);
    }
    usort($out,fn($a,$b)=>strcmp($b['submitted_at'],$a['submitted_at']));return $out;
}
function listView(array $u,string $page,string $title): void {
    if(!manager($u) && in_array($page,['requirements','submissions'],true)){facultyDirectoryView($u,$page);return;}
    if($page==='review' && !admin($u)){departmentReviewView($u);return;}
    $personal=in_array($page,['requirements','history','submissions','personal-deadlines'],true);
    $items=assignmentRows($u,$personal);$isHistory=$page==='history';
    if($isHistory)$items=historyRows($items);
    elseif(in_array($page,['submissions','review'],true))$items=array_filter($items,fn($r)=>$r['submission_id']);
    else $items=activeRows($items);
    if(isset($_GET['faculty_id']))$items=array_filter($items,fn($r)=>$r['user_id']==(int)$_GET['faculty_id']);
    pageHead($title,'',($page==='submissions'?linkTo('upload','Upload Document',[],'btn btn-primary'):''));
    $items=filterRows($items,['academic_year'=>'Academic year','semester'=>'Semester','document_type'=>'Document type','requirement'=>'Requirement','submission_status'=>'Submission','review_status'=>'Review','timeliness'=>'Timeliness']);
    $body=[];foreach(paginate($items,$footer) as $r){
        $cells=[];if(!$personal)$cells[]=h($r['faculty']);
        $cells[]=h($r['requirement']);$cells[]=h($r['document_type']);
        if($isHistory){$cells[]=h($r['title'].' · v'.$r['version_no']);$cells[]=h($r['original_date']);$cells[]=h($r['updated']);$cells[]=h($r['remarks']);$cells[]=h($r['record_state']);}
        elseif(in_array($page,['submissions','review'],true)){ $cells[]=h($r['title']);$cells[]=h($r['submitted_at']);}
        else {$cells[]=h($r['academic_year'].' · '.$r['semester']);$cells[]=h(deadlineLabel($r['deadline']));$cells[]=h($r['days_left']);}
        $cells[]=badge($r['submission_status']);$cells[]=badge($r['timeliness']);$cells[]=badge($r['review_status']);
        $requirementName=h($r['requirement']);
        $action='<div class="department-actions"><a class="department-action" href="'.url('detail',['id'=>$r['id']]).'" aria-label="View details for '.$requirementName.'" title="View details">'.icon('eye').'</a>';
        if($page==='deadlines' && (admin($u) || $r['target_department']==$u['department_id'])) {
            $action.='<a class="department-action" href="'.url('manage',['id'=>$r['requirement_id']]).'" data-edit-modal aria-label="Edit deadline for '.$requirementName.'" title="Edit deadline">'.icon('pencil').'</a>';
        }
        $cells[]=$action.'</div>';$body[]=$cells;
    }
    $cols=$personal?[]:['Faculty'];$cols=[...$cols,'Requirement','Document type'];
    $cols=array_merge($cols,$isHistory?['Document / version','Original submission','Updated','Review remarks','Record']: (in_array($page,['submissions','review'],true)?['Document','Submitted on']:['Term','Deadline','Days left']),['Submission','Timeliness','Review','Action']);
    tableView($cols,$body);
    echo $footer;
}
function departmentReviewView(array $u): void {
    $sent=array_filter(assignmentRows($u),fn($r)=>$r['submission_id']);
    $recordYears=array_map(fn($r)=>str_replace('–','-',$r['academic_year']),$sent);
    [$semesters,$semester,$years,$year]=directoryTermFilters($recordYears);
    $items=array_filter($sent,fn($r)=>$r['semester']===$semester && str_replace('–','-',$r['academic_year'])===$year);
    usort($items,fn($a,$b)=>strcmp($b['submitted_at'],$a['submitted_at']) ?: $b['version_id']<=>$a['version_id']);
    $subjects=[];$faculty=[];
    foreach($items as &$r){
        $r['subject']=$r['subject_id']?$r['subject_code'].' – '.$r['subject_name']:'—';
        if($r['subject_id'])$subjects[$r['subject_id']]=$r['subject'];
        $faculty[$r['user_id']]=$r['faculty'];
    }
    unset($r);
    asort($subjects);asort($faculty);
    $statuses=['Approved'=>'Approved','Pending Review'=>'For Review','Needs Revision'=>'Returned'];
    $filters=['semester'=>$semester,'academic_year'=>$year];
    foreach(['subject_id'=>$subjects,'faculty_id'=>$faculty,'review_status'=>$statuses] as $key=>$options){
        $selected=$_GET[$key]??'';
        $filters[$key]=is_string($selected) && isset($options[$selected])?$selected:'';
    }
    pageHead('Submissions');
    echo '<form class="directory-toolbar review-toolbar" method="get" aria-label="Filter submissions"><input type="hidden" name="p" value="review">';
    selectBox('semester','Semester',$semesters,$semester,'',true);
    selectBox('academic_year','Academic Year',$years,$year,'',true);
    selectBox('subject_id','Subject',[''=>'All Subjects']+$subjects,$filters['subject_id'],'',true);
    selectBox('faculty_id','Faculty',[''=>'All Faculty']+$faculty,$filters['faculty_id'],'',true);
    selectBox('review_status','Approval Status',[''=>'All Statuses']+$statuses,$filters['review_status'],'',true);
    echo '</form>';
    $items=array_filter($items,fn($r)=>($filters['subject_id']==='' || $filters['subject_id']==$r['subject_id'])
        && ($filters['faculty_id']==='' || $filters['faculty_id']==$r['user_id'])
        && ($filters['review_status']==='' || $filters['review_status']===$r['review_status']));
    $body=[];$visible=paginate($items,$footer,'submissions');
    foreach($visible as $r){
        $name=h($r['requirement'].' · '.$r['faculty']);
        $actions='<div class="review-actions"><button type="button" class="department-action" data-open-review="submission-'.$r['id'].'" aria-label="View '.$name.'" title="View submission">'.icon('eye').'</button>';
        if($r['assigned'] && $r['user_id']!=$u['id'] && $r['review_status']==='Pending Review'){
            $actions.=reviewDecisionForm($r,$filters,'Approved').'<button type="button" class="department-action department-action-delete" data-open-review="return-'.$r['id'].'" aria-label="Return '.$name.'" title="Return for revision">'.icon('arrow-counterclockwise').'</button>';
        }
        $body[]=[h($r['faculty']),h($r['subject']),h($r['requirement']),h(date('M j, Y · g:i A',strtotime($r['submitted_at']))),badge($r['timeliness']),badge($statuses[$r['review_status']]),$actions.'</div>'];
    }
    tableView(['Faculty','Subject','Requirement','Submitted Date & Time','Submission Status','Approval Status','Actions'],$body,'No submissions match these filters.','review-table');
    echo $footer;
    foreach($visible as $r){
        reviewDialogStart('submission-'.$r['id'],$r['requirement']);
        echo '<dl class="submission-details">';
        foreach(['Faculty'=>$r['faculty'],'Subject'=>$r['subject'],'Document'=>$r['title'].' · v'.$r['version_no'],'Submitted'=>date('M j, Y · g:i A',strtotime($r['submitted_at'])),'Approval status'=>$statuses[$r['review_status']],'Faculty remarks'=>$r['author_remarks'],'Reviewer remarks'=>$r['review_remarks']] as $label=>$value){
            if($value!==null && $value!=='')echo '<dt>'.h($label).'</dt><dd>'.h($value).'</dd>';
        }
        echo '</dl>'.linkTo('download','Download '.$r['original_name'],['id'=>$r['version_id']],'btn btn-primary').' '.linkTo('detail','Submission history',['id'=>$r['id']]).'</div></dialog>';
        if($r['assigned'] && $r['user_id']!=$u['id'] && $r['review_status']==='Pending Review'){
            reviewDialogStart('return-'.$r['id'],'Return for revision');
            echo '<p>'.h($r['requirement'].' · '.$r['faculty']).'</p>'.reviewDecisionForm($r,$filters,'Needs Revision').'</div></dialog>';
        }
    }
}
function reviewDialogStart(string $id,string $title): void {
    echo '<dialog class="edit-modal" id="'.h($id).'" aria-labelledby="'.h($id).'-title"><div class="edit-modal-header"><h2 id="'.h($id).'-title">'.h($title).'</h2><form method="dialog"><button class="edit-modal-close" aria-label="Close">'.icon('x-lg').'</button></form></div><div class="review-dialog-body">';
}
function reviewDecisionForm(array $r,array $filters,string $decision): string {
    $url=url('review',$filters+['pg'=>(int)($_GET['pg']??1)]);
    $form='<form method="post" action="'.$url.'"><input type="hidden" name="csrf" value="'.h($_SESSION['csrf']).'"><input type="hidden" name="id" value="'.h($r['id']).'"><input type="hidden" name="version_id" value="'.h($r['version_id']).'"><input type="hidden" name="decision" value="'.h($decision).'">';
    if($decision==='Approved')return $form.'<button class="department-action review-approve" aria-label="Approve '.h($r['requirement'].' · '.$r['faculty']).'" title="Approve submission">'.icon('check-lg').'</button></form>';
    return $form.'<label class="form-label">What needs to change?<textarea class="form-control" name="remarks" required maxlength="4000" rows="3"></textarea></label><button class="btn btn-danger">Return to faculty</button></form>';
}
function uploadView(array $u): void {
    $items=array_filter(activeRows(assignmentRows($u,true)),fn($r)=>!$r['submission_id'] || $r['review_status']==='Needs Revision');
    pageHead('Upload Document','Submit a file for an assigned requirement.');panel('Document information');
    if(!$items){echo '<p>No requirements are currently open for upload.</p>';endPanel();return;}
    startForm('upload',0,'enctype="multipart/form-data"');echo '<label class="form-label">Requirement<select class="form-select" name="assignment_id" data-requirement>';
    foreach($items as $r)echo '<option value="'.h($r['id']).'" data-year="'.h($r['academic_year']).'" data-semester="'.h($r['semester']).'" data-formats="'.h($r['allowed_formats']).'" '.(($r['id']==($_GET['assignment_id']??0))?'selected':'').'>'.h($r['requirement'].' · '.deadlineLabel($r['deadline'])).'</option>';
    echo '</select></label><div class="field-grid"><label>Academic year<input class="form-control" readonly data-year-output></label><label>Semester<input class="form-control" readonly data-semester-output></label></div>';
    field('title','Document title (optional)','','text',false);echo '<label class="form-label">File attachment<input class="form-control" type="file" name="file" accept=".pdf,.docx,.xlsx"><small data-formats-output></small> · Maximum file size: '.MAX_UPLOAD_MB.' MB</label>';textarea('remarks','Submission remarks (optional)');saveButton('Submit Document');endPanel();
}
function detailView(array $u,int $id): void {
    $r=record($u,$id);pageHead($r['requirement'],$r['faculty'].' · '.$r['department']);panel('Requirement details');
    echo '<p>'.h($r['description']).'</p><p>'.h($r['document_type'].' · '.$r['academic_year'].' · '.$r['semester'].' · Due '.deadlineLabel($r['deadline'])).'</p><p>'.badge($r['submission_status']).' '.badge($r['review_status']).' '.badge($r['timeliness']).'</p>';
    if(teaching($u) && $u['id']==$r['user_id'] && $r['assigned'] && (!$r['submission_id']||$r['review_status']==='Needs Revision'))echo linkTo('upload',$r['submission_id']?'Replace file':'Upload Document',['assignment_id'=>$id],'btn btn-primary');
    endPanel();panel('Submission and review history');$items=[];
    foreach(versions($id) as $v)$items[]=[h($v['title'].' · v'.$v['version_no'].($v['retired']?' (retired)':'')),h($v['submitted_at']),h($v['remarks']),badge($v['decision']?:'Pending Review'),h(trim(($v['reviewer']??'').' '.($v['reviewed_at']??''))),h($v['review_remarks']),'<a class="department-action" href="'.url('download',['id'=>$v['id']]).'" aria-label="Download '.h($v['original_name']).'" title="Download file">'.icon('download').'</a>'];
    $visible=paginate($items,$footer,'versions');
    tableView(['Document','Submitted','Author remarks','Review','Reviewed by','Reviewer remarks','File'],$visible,'No document submitted yet.');
    echo $footer;endPanel();
    if(manager($u) && $u['id']!=$r['user_id'] && $r['submission_id']){
        if($r['review_status']==='Pending Review'){
            panel('Review document');startForm('detail',$id);echo '<input type="hidden" name="version_id" value="'.h($r['version_id']).'">';
            selectBox('decision','Decision',array_combine(['Approved','Needs Revision'],['Approve','Return for revision']));textarea('remarks','Reviewer remarks (required for revision)');saveButton('Save review decision');endPanel();
        }
        if(admin($u)){startForm('detail',$id,'data-confirm="Remove this submission from active records? Its history will be retained." data-confirm-label="Remove" data-confirm-variant="danger"');echo '<input type="hidden" name="action" value="retire"><input type="hidden" name="version_id" value="'.h($r['version_id']).'">';saveButton('Retire submission');}
    }
}
function legacyReportView(array $u,bool $csv=false): void {
    $reports=['Faculty Submission Summary','Pending Requirements','Late Submissions','Department Compliance','Submission History'];
    $report=$_GET['report']??$reports[0];valid(in_array($report,$reports,true),'Unknown report.');
    $all=assignmentRows($u);$items=$report==='Submission History'?historyRows($all):activeRows($all);
    if(isset($_GET['faculty_id']))$items=array_filter($items,fn($r)=>$r['user_id']==(int)$_GET['faculty_id']);
    if($report==='Pending Requirements')$items=array_filter($items,fn($r)=>!$r['submission_id']);
    if($report==='Late Submissions')$items=array_filter($items,fn($r)=>$r['timeliness']==='Late');
    if(!$csv){pageHead('Reports');echo '<form method="get" class="filters"><input type="hidden" name="p" value="reports">';selectBox('report','Report',array_combine($reports,$reports),$report,'',true);echo '<button class="btn btn-primary">Generate Report</button></form>';}
    $items=filterRows($items,['department'=>'Department','academic_year'=>'Academic year','semester'=>'Semester','requirement'=>'Requirement','submission_status'=>'Submission','review_status'=>'Review','timeliness'=>'Timeliness'],!$csv,true);
    $fields=['faculty'=>'Faculty','department'=>'Department','requirement'=>'Requirement','academic_year'=>'Academic year','semester'=>'Semester','deadline'=>'Deadline','submission_status'=>'Submission','timeliness'=>'Timeliness','review_status'=>'Review'];
    if($report==='Submission History')$fields+=['version_no'=>'Version','submitted_at'=>'Submitted on','remarks'=>'Reviewer remarks','record_state'=>'Record'];
    if($report==='Department Compliance'){
        $groups=[];foreach($items as $r){$d=$r['department'];$groups[$d]??=['department'=>$d,'assigned'=>0,'submitted'=>0,'approved'=>0];$groups[$d]['assigned']++;$groups[$d]['submitted']+=(int)(bool)$r['submission_id'];$groups[$d]['approved']+=(int)($r['review_status']==='Approved');}
        foreach($groups as &$g)$g['rate']=round(100*$g['submitted']/$g['assigned']).'%';unset($g);
        $items=array_values($groups);$fields=['department'=>'Department','assigned'=>'Assigned','submitted'=>'Submitted','approved'=>'Approved','rate'=>'Submission rate'];
    }
    if($csv){header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="facul-track-report.csv"');$f=fopen('php://output','w');fputcsv($f,array_values($fields));foreach($items as $r){$line=[];foreach($fields as $key=>$label){$v=(string)($r[$key]??'');$line[]=preg_match('/^[\s]*[=+@-]/u',$v)?"'".$v:$v;}fputcsv($f,$line);}fclose($f);exit;}
    panel($report,linkTo('reports','Export CSV',array_merge($_GET,['csv'=>1]),'btn btn-outline-secondary').' <button type="button" class="btn btn-outline-secondary" data-print>Print</button>');
    $body=[];foreach($items as $r){$cells=[];foreach($fields as $key=>$label)$cells[]=h($r[$key]??'');$body[]=$cells;}
    $visible=paginate($body,$footer);
    if(count($visible)<count($body)){
        echo '<div class="d-none d-print-block">';tableView(array_values($fields),$body);echo '</div><div class="d-print-none">';
        tableView(array_values($fields),$visible);echo '</div>';
    }else tableView(array_values($fields),$visible);
    echo $footer;endPanel();
}
function reportView(array $u,bool $csv=false,bool $excel=false): void {
    if(isset($_GET['report'])){legacyReportView($u,$csv);return;}
    if($u['role']==='Department Head' && !$csv){departmentReportView($u);return;}
    $cfg=settings();$terms=rows('SELECT id,academic_year,semester FROM academic_terms WHERE active=1 ORDER BY academic_year DESC,id DESC');
    $termOptions=[''=>'All Academic Terms'];$termById=[];
    foreach($terms as $term){
        $termOptions[$term['id']]=academicTermLabel($term);
        $termById[(string)$term['id']]=$term;
    }
    $termId=(string)($_GET['term_id']??'');
    if($termId==='' || !isset($termById[$termId])){
        $termId='';
        foreach($termById as $id=>$term)if(str_replace('–','-',$term['academic_year'])===str_replace('–','-',$cfg['academic_year']) && $term['semester']===$cfg['semester']){$termId=$id;break;}
    }
    $departmentOptions=[''=>'All Departments'];
    foreach(rows('SELECT name FROM departments WHERE active=1 ORDER BY name') as $department)$departmentOptions[$department['name']]=$department['name'];
    $typeOptions=[''=>'All Document Types'];
    foreach(rows('SELECT name FROM document_types WHERE active=1 ORDER BY name') as $type)$typeOptions[$type['name']]=$type['name'];
    $statusOptions=[''=>'All Statuses','Approved'=>'Approved','Pending'=>'Pending','Needs Revision'=>'Needs Revision','Not Submitted'=>'Not Submitted'];
    $department=(string)($_GET['department']??'');if(!array_key_exists($department,$departmentOptions))$department='';
    $documentType=(string)($_GET['document_type']??'');if(!array_key_exists($documentType,$typeOptions))$documentType='';
    $status=(string)($_GET['status']??'');if(!array_key_exists($status,$statusOptions))$status='';
    $items=activeRows(assignmentRows($u));
    if(isset($_GET['faculty_id']))$items=array_filter($items,fn($r)=>$r['user_id']==(int)$_GET['faculty_id']);
    if($termId!==''){
        $term=$termById[$termId];
        $items=array_filter($items,fn($r)=>str_replace('–','-',$r['academic_year'])===str_replace('–','-',$term['academic_year']) && $r['semester']===$term['semester']);
    }
    if($department!=='')$items=array_filter($items,fn($r)=>$r['department']===$department);
    if($documentType!=='')$items=array_filter($items,fn($r)=>$r['document_type']===$documentType);
    foreach($items as &$item){
        $item['report_status']=!$item['submission_id']?'Not Submitted':($item['review_status']==='Approved'?'Approved':($item['review_status']==='Needs Revision'?'Needs Revision':'Pending'));
        $item['submitted_date']=$item['submitted_at']?dateLabel($item['submitted_at']):'—';
    }
    unset($item);
    if($status!=='')$items=array_filter($items,fn($r)=>$r['report_status']===$status);
    $items=array_values($items);
    $fields=['faculty'=>'Faculty','department_code'=>'Department','document_type'=>'Document Type','report_status'=>'Status','submitted_date'=>'Submitted Date'];
    if($csv){
        header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="facul-track-report.csv"');
        $file=fopen('php://output','w');fputcsv($file,array_values($fields));
        foreach($items as $item){
            $line=[];foreach($fields as $key=>$label){$value=(string)($item[$key]??'');$line[]=preg_match('/^[\s]*[=+@-]/u',$value)?"'".$value:$value;}
            fputcsv($file,$line);
        }
        fclose($file);exit;
    }
    [$summaryRows,$totals]=facultySubmissionSummary($items);
    $selectedTerm=$termId!==''?academicTermLabel($termById[$termId]):'All Academic Terms';
    $details=[
        'Academic Term'=>$selectedTerm,
        'Department'=>$department!==''?$department:'All Departments',
        'Document Type'=>$documentType!==''?$documentType:'All Document Types',
        'Submission Status'=>$status!==''?$status:'All Statuses'
    ];
    $generated=date('M d, Y · g:i A');
    if($excel){exportFacultyReportWorkbook($summaryRows,$details,$totals,$generated);return;}
    $exportActions='<div class="report-actions"><a class="btn btn-primary" href="'.url('reports',array_merge($_GET,['export'=>'xlsx'])).'">'.icon('file-earmark-spreadsheet').' Export Excel</a><button type="button" class="btn btn-primary" data-print>'.icon('file-earmark-pdf').' Export PDF</button></div>';
    pageHead('Reports','',$exportActions);
    ob_start();
    echo '<form method="get" class="directory-toolbar report-toolbar no-print" data-directory="reports" role="search"><input type="hidden" name="p" value="reports">';
    selectBox('term_id','Academic Term',$termOptions,$termId);
    selectBox('department','Department',$departmentOptions,$department);
    selectBox('document_type','Document Type',$typeOptions,$documentType);
    selectBox('status','Submission Status',$statusOptions,$status);
    echo '</form>';
    echo ob_get_clean();
    $footer=null;$body=[];
    foreach(paginate($items,$footer,'report entries') as $item)$body[]=[
        h($item['faculty']),h($item['department_code']),h($item['document_type']),badge($item['report_status']),h($item['submitted_date'])
    ];
    facultySubmissionPrintLayout($summaryRows,$totals,$details,$generated);
    echo '<div class="report-screen-layout">';
    tableView(array_values($fields),$body,'No report entries match these filters.','report-table');
    echo $footer.'</div>';
}


function departmentReportView(array $u,bool $excel=false): void {
    $records=activeRows(assignmentRows($u));
    $recordYears=array_map(fn($row)=>str_replace('–','-',$row['academic_year']),$records);
    [$semesters,$semester,$years,$year]=directoryTermFilters($recordYears);
    $records=array_filter($records,fn($row)=>$row['semester']===$semester && str_replace('–','-',$row['academic_year'])===$year);
    $subjects=[];$faculty=[];
    foreach($records as $row){
        if($row['subject_id'])$subjects[$row['subject_id']]=$row['subject_code'].' – '.$row['subject_name'];
        $faculty[$row['user_id']]=$row['faculty'];
    }
    asort($subjects);asort($faculty);
    $statuses=['On Time'=>'On Time','Late'=>'Late','Not Submitted'=>'Not Submitted'];
    $filters=['semester'=>$semester,'academic_year'=>$year];
    foreach(['subject_id'=>$subjects,'faculty_id'=>$faculty,'submission_status'=>$statuses] as $key=>$options){
        $value=$_GET[$key]??'';
        $filters[$key]=is_string($value) && isset($options[$value])?$value:'';
    }
    $columns=['Faculty','Subject','Requirement','Due Date & Time','Submitted Date & Time','Submission Status','Approval Status'];
    $approval=['Approved'=>'Approved','Needs Revision'=>'Returned','Pending Review'=>'For Review'];
    $data=[];$filteredRecords=[];
    foreach($records as $row){
        $status=$row['submission_id']?$row['timeliness']:'Not Submitted';
        if($filters['subject_id']!=='' && $row['subject_id']!=$filters['subject_id'])continue;
        if($filters['faculty_id']!=='' && $row['user_id']!=$filters['faculty_id'])continue;
        if($filters['submission_status']!=='' && $status!==$filters['submission_status'])continue;
        $filteredRecords[]=$row;
        $data[]=[$row['faculty'],$row['subject_code']??'—',$row['requirement'],deadlineLabel($row['deadline']).' 11:59 PM',
            $row['submitted_at']?date('M j, Y g:i A',strtotime($row['submitted_at'])):'—',
            $status,$row['submission_id']?$approval[$row['review_status']]:'Pending'];
    }
    [$summaryRows,$totals]=facultySubmissionSummary($filteredRecords);
    $details=[
        'Academic Term'=>academicTermLabel(['semester'=>$semester,'academic_year'=>$year]),
        'Department'=>$u['department']??'—',
        'Subject'=>$filters['subject_id']!==''?($subjects[$filters['subject_id']]??'—'):'All Subjects',
        'Faculty / Status'=>($filters['faculty_id']!==''?($faculty[$filters['faculty_id']]??'—'):'All Faculty')
            .' · '.($filters['submission_status']!==''?$filters['submission_status']:'All Statuses')
    ];
    $generated=date('M d, Y · g:i A');
    if($excel){exportFacultyReportWorkbook($summaryRows,$details,$totals,$generated);return;}
    $actions='<div class="report-actions no-print"><button type="button" class="btn btn-primary" data-print>'.icon('file-earmark-pdf').' Export PDF</button>';
    $actions.='<a class="btn btn-primary" href="'.url('reports',$filters+['export'=>'xlsx']).'">'.icon('file-earmark-spreadsheet').' Export Excel</a></div>';
    pageHead('Reports','',$actions);
    echo '<form method="get" class="directory-toolbar report-toolbar no-print"><input type="hidden" name="p" value="reports">';
    selectBox('semester','Semester',$semesters,$semester);
    selectBox('academic_year','Academic Year',$years,$year);
    selectBox('subject_id','Subject',[''=>'All Subjects']+$subjects,$filters['subject_id']);
    selectBox('faculty_id','Faculty',[''=>'All Faculty']+$faculty,$filters['faculty_id']);
    selectBox('submission_status','Submission Status',[''=>'All Statuses']+$statuses,$filters['submission_status']);
    echo '</form>';
    facultySubmissionPrintLayout($summaryRows,$totals,$details,$generated);
    $body=[];
    foreach($data as $row)$body[]=[...array_map('h',array_slice($row,0,5)),
        '<span data-report-status="'.h($row[5]).'">'.badge($row[5]).'</span>',
        '<span data-report-status="'.h($row[6]).'">'.badge($row[6]).'</span>'];
    $visible=paginate($body,$footer,'report entries');
    echo '<div class="report-screen-layout">';
    if(count($visible)<count($body)){
        echo '<div class="d-none d-print-block">';tableView($columns,$body,'','department-report-table');echo '</div><div class="d-print-none">';
    }
    tableView($columns,$visible,'No report entries match these filters.','department-report-table');
    if(count($visible)<count($body))echo '</div>';
    echo $footer.'</div>';
}

function facultySubmissionSummary(array $records): array {
    $summary=[];$totals=['required'=>0,'on_time'=>0,'late'=>0,'missing'=>0];
    foreach($records as $record){
        $id=(int)$record['user_id'];
        $summary[$id]??=['faculty'=>$record['faculty'],'required'=>0,'on_time'=>0,'late'=>0,'missing'=>0,'not_yet_due'=>0];
        $summary[$id]['required']++;$totals['required']++;
        if($record['submission_id'] && $record['timeliness']==='On Time'){$summary[$id]['on_time']++;$totals['on_time']++;}
        elseif($record['submission_id'] && $record['timeliness']==='Late'){$summary[$id]['late']++;$totals['late']++;}
        elseif(!$record['submission_id'] && $record['timeliness']==='Overdue'){$summary[$id]['missing']++;$totals['missing']++;}
        elseif(!$record['submission_id'])$summary[$id]['not_yet_due']++;
    }
    $summary=array_values($summary);
    usort($summary,fn($a,$b)=>strcmp($a['faculty'],$b['faculty']));
    $rows=[];
    foreach($summary as $index=>$faculty){
        $submitted=$faculty['on_time']+$faculty['late'];
        $rate=$faculty['required']?number_format(100*$submitted/$faculty['required'],2).'%':'0.00%';
        $rows[]=[$index+1,$faculty['faculty'],$faculty['required'],$faculty['on_time'],$faculty['late'],
            $faculty['missing'],$faculty['not_yet_due'],$rate];
    }
    return [$rows,$totals];
}

function facultySubmissionPrintLayout(array $summaryRows,array $totals,array $details,string $generated): void {
    echo '<section class="report-print-layout" aria-label="Faculty submission report export">';
    echo '<header class="report-print-header"><div class="report-print-brand"><img src="assets/images/facultrack-logo.png" alt=""><div><strong>FaculTrack</strong><span>FACULTY SUBMISSION REPORT</span></div></div><div class="report-print-generated">Generated on:<br>'.h($generated).'</div></header>';
    echo '<h1 class="report-print-title">Summary</h1><div class="report-print-metrics">';
    foreach([
        ['Total Required',$totals['required'],'total'],
        ['Submitted On Time',$totals['on_time'],'ontime'],
        ['Submitted Late',$totals['late'],'late'],
        ['Missing / Overdue',$totals['missing'],'missing']
    ] as [$label,$value,$class]){
        echo '<article class="report-print-metric report-metric-'.$class.'"><span>'.icon(match($class){'total'=>'file-earmark-text','ontime'=>'check-circle','late'=>'clock','missing'=>'exclamation-triangle'}).'</span><strong>'.h($value).'</strong><small>'.h($label).'</small></article>';
    }
    echo '</div><h2 class="report-print-section-title">Report Details</h2><div class="report-print-details">';
    foreach($details as $label=>$value)echo '<div><span>'.h($label).'</span><strong>'.h($value).'</strong></div>';
    echo '</div><h2 class="report-print-section-title">Faculty Submission Summary</h2>';
    $body=[];
    foreach($summaryRows as $row)$body[]=[h($row[0]),h($row[1]),h($row[2]),h($row[3]),h($row[4]),h($row[5]),h($row[6]),'<span class="report-print-rate">'.h($row[7]).'</span>'];
    tableView(['No.','Faculty Name','Total Required','On Time','Late','Missing','Not Yet Due','Compliance Rate'],$body,'No faculty records match these filters.','faculty-summary-print-table');
    echo '<footer class="report-print-footer"><span>'.icon('info-circle').' Compliance rate = (On Time + Late) / Total Required.</span></footer></section>';
}

function exportFacultyReportWorkbook(array $summaryRows,array $details,array $totals,string $generated): void {
    $file=tempnam(sys_get_temp_dir(),'ft-report-');
    if($file===false)throw new RuntimeException('Cannot create report workbook.');
    try{
        $zip=new ZipArchive();
        if($zip->open($file,ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Cannot open report workbook.');
        $main='http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $rel='http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $package='http://schemas.openxmlformats.org/package/2006/relationships';
        $zip->addFromString('[Content_Types].xml','<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels','<Relationships xmlns="'.$package.'"><Relationship Id="rId1" Type="'.$rel.'/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml','<workbook xmlns="'.$main.'" xmlns:r="'.$rel.'"><sheets><sheet name="Faculty Report" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels','<Relationships xmlns="'.$package.'"><Relationship Id="rId1" Type="'.$rel.'/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="'.$rel.'/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml','<styleSheet xmlns="'.$main.'">
            <fonts count="6">
                <font><sz val="10"/><name val="Arial"/></font>
                <font><b/><color rgb="FFFFFFFF"/><sz val="16"/><name val="Arial"/></font>
                <font><color rgb="FFFFFFFF"/><sz val="9"/><name val="Arial"/></font>
                <font><b/><color rgb="FFFFFFFF"/><sz val="10"/><name val="Arial"/></font>
                <font><b/><color rgb="FF155744"/><sz val="10"/><name val="Arial"/></font>
                <font><b/><color rgb="FF155744"/><sz val="16"/><name val="Arial"/></font>
            </fonts>
            <fills count="6">
                <fill><patternFill patternType="none"/></fill>
                <fill><patternFill patternType="gray125"/></fill>
                <fill><patternFill patternType="solid"><fgColor rgb="FF0F604A"/><bgColor indexed="64"/></patternFill></fill>
                <fill><patternFill patternType="solid"><fgColor rgb="FFEDF4EF"/><bgColor indexed="64"/></patternFill></fill>
                <fill><patternFill patternType="solid"><fgColor rgb="FFFFF7E5"/><bgColor indexed="64"/></patternFill></fill>
                <fill><patternFill patternType="solid"><fgColor rgb="FFFFEFED"/><bgColor indexed="64"/></patternFill></fill>
            </fills>
            <borders count="2">
                <border><left/><right/><top/><bottom/><diagonal/></border>
                <border><left style="thin"><color rgb="FFD7E1DA"/></left><right style="thin"><color rgb="FFD7E1DA"/></right><top style="thin"><color rgb="FFD7E1DA"/></top><bottom style="thin"><color rgb="FFD7E1DA"/></bottom><diagonal/></border>
            </borders>
            <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
            <cellXfs count="15">
                <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
                <xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center"/></xf>
                <xf numFmtId="0" fontId="2" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
                <xf numFmtId="0" fontId="3" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center"/></xf>
                <xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment vertical="center"/></xf>
                <xf numFmtId="0" fontId="4" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
                <xf numFmtId="0" fontId="5" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
                <xf numFmtId="0" fontId="4" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
                <xf numFmtId="0" fontId="4" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
                <xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>
                <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
                <xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
                <xf numFmtId="0" fontId="4" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
                <xf numFmtId="0" fontId="5" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
                <xf numFmtId="0" fontId="5" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
            </cellXfs>
            <cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
        </styleSheet>');
        $cells=[];
        $setCell=static function(int $row,int $column,mixed $value,int $style) use (&$cells): void {
            $cells[$row][$column]=[$value,$style];
        };
        $setCell(1,1,'FaculTrack',1);$setCell(1,7,'Generated on: '.$generated,2);
        $setCell(2,1,'FACULTY SUBMISSION REPORT',3);
        $setCell(4,1,'Summary',4);
        $metrics=[
            ['Total Required',$totals['required'],5,6],
            ['Submitted On Time',$totals['on_time'],5,6],
            ['Submitted Late',$totals['late'],7,13],
            ['Missing / Overdue',$totals['missing'],8,14]
        ];
        foreach($metrics as $index=>[$label,$value,$labelStyle,$valueStyle]){
            $column=1+$index*2;
            $setCell(5,$column,$label,$labelStyle);$setCell(6,$column,$value,$valueStyle);
        }
        $setCell(8,1,'Report Details',4);
        $detailLabels=array_keys($details);$detailValues=array_values($details);
        for($index=0;$index<4;$index++){
            $column=1+$index*2;
            $setCell(9,$column,$detailLabels[$index]??'',5);
            $setCell(10,$column,$detailValues[$index]??'',9);
        }
        $headers=['No.','Faculty Name','Total Required','On Time','Late','Missing','Not Yet Due','Compliance Rate'];
        foreach($headers as $index=>$label)$setCell(12,$index+1,$label,11);
        foreach($summaryRows as $index=>$row){
            $rowNumber=13+$index;
            foreach($row as $column=>$value){
                $style=$column===1?9:($column===7?12:10);
                $setCell($rowNumber,$column+1,$value,$style);
            }
        }
        $lastRow=12+count($summaryRows);
        $sheet='<worksheet xmlns="'.$main.'"><sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><dimension ref="A1:H'.$lastRow.'"/><sheetViews><sheetView workbookViewId="0"><pane ySplit="12" topLeftCell="A13" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft" activeCell="A13" sqref="A13"/></sheetView></sheetViews><sheetFormatPr defaultRowHeight="20"/><cols>';
        foreach([7,27,15,12,11,12,15,17] as $index=>$width){
            $column=$index+1;
            $sheet.='<col min="'.$column.'" max="'.$column.'" width="'.$width.'" customWidth="1"/>';
        }
        $sheet.='</cols><sheetData>';
        foreach($cells as $rowNumber=>$rowCells){
            ksort($rowCells);
            $height=match($rowNumber){1=>30,2=>22,4,8=>24,12=>32,default=>20};
            $sheet.='<row r="'.$rowNumber.'" ht="'.$height.'" customHeight="1">';
            foreach($rowCells as $column=>$cell){
                [$value,$style]=$cell;$reference=chr(64+$column).$rowNumber;
                if(is_int($value)||is_float($value))$sheet.='<c r="'.$reference.'" s="'.$style.'"><v>'.$value.'</v></c>';
                else{
                    $text=preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u','',(string)$value);
                    $sheet.='<c r="'.$reference.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.htmlspecialchars($text,ENT_XML1|ENT_QUOTES,'UTF-8').'</t></is></c>';
                }
            }
            $sheet.='</row>';
        }
        $merges=['A1:F1','G1:H1','A2:H2','A4:H4','A5:B5','C5:D5','E5:F5','G5:H5','A6:B6','C6:D6','E6:F6','G6:H6','A8:H8',
            'A9:B9','C9:D9','E9:F9','G9:H9','A10:B10','C10:D10','E10:F10','G10:H10'];
        $sheet.='</sheetData><autoFilter ref="A12:H'.$lastRow.'"/><mergeCells count="'.count($merges).'">';
        foreach($merges as $merge)$sheet.='<mergeCell ref="'.$merge.'"/>';
        $sheet.='</mergeCells><pageMargins left="0.25" right="0.25" top="0.5" bottom="0.5" header="0.2" footer="0.2"/><pageSetup paperSize="9" orientation="portrait" fitToWidth="1" fitToHeight="0"/></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml',$sheet);
        if(!$zip->close())throw new RuntimeException('Cannot finish report workbook.');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="faculty-submission-report.xlsx"');
        readfile($file);
    }finally{unlink($file);}
    exit;
}
