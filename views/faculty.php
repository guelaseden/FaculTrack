<?php
function facultyApproval(array $row): string {
    return match($row['review_status']){
        'Approved'=>'Approved','Needs Revision'=>'Returned',default=>'For Review'
    };
}
function facultySubjectLabel(array $row): string {
    return $row['subject_id']?$row['subject_code'].' – '.$row['subject_name']:'—';
}
function facultyDashboardPanels(array $items,array $sent,array $term): void {
    $pending=array_filter($items,fn($r)=>!$r['submission_id'] && $r['days_left']>=0);
    panel('Upcoming Deadlines',linkTo('requirements','View All',$term+['status'=>'Pending'],'dashboard-view-all'));
    $body=[];
    foreach(array_slice($pending,0,3) as $r)$body[]=[h($r['requirement']),h(facultySubjectLabel($r)),h(deadlineLabel($r['deadline']).', 11:59 PM'),badge('Pending')];
    tableView(['Requirement','Subject','Due Date & Time','Status'],$body,'No upcoming deadlines for this term.');endPanel();
    panel('Recent Submissions',linkTo('submissions','View All',$term,'dashboard-view-all'));
    $body=[];
    foreach(array_slice($sent,0,3) as $r)$body[]=[linkTo('detail',$r['title'],['id'=>$r['id']]),h(facultySubjectLabel($r)),h(date('M j, Y, g:i A',strtotime($r['submitted_at']))),badge(facultyApproval($r))];
    tableView(['Document','Subject','Submitted Date & Time','Approval Status'],$body,'No submissions for this term.');endPanel();
}
function facultyToolbar(string $page,string $search,array $term,?array $subjects=null,array $statuses=[]): void {
    [$semesters,$semester,$years,$year]=$term;
    echo '<form class="directory-toolbar faculty-toolbar" method="get" role="search"><input type="hidden" name="p" value="'.h($page).'">';
    echo '<div class="directory-search"><input type="search" class="form-control" name="q" aria-label="'.h($search).'" placeholder="'.h($search).'" value="'.h($_GET['q']??'').'"><button type="submit" aria-label="'.h($search).'">'.icon('search').'</button></div>';
    selectBox('semester','Semester',$semesters,$semester,'',true);
    selectBox('academic_year','Academic Year',$years,$year,'',true);
    if($subjects!==null){
        selectBox('subject_id','Subject',[''=>'All Subjects']+$subjects,$_GET['subject_id']??'','',true);
        selectBox('status','Status',[''=>'All Statuses']+array_combine($statuses,$statuses),$_GET['status']??'','',true);
    }
    echo '</form>';
}
function facultySubjectsView(array $u): void {
    $term=directoryTermFilters([],settings());
    $subjects=rows("SELECT s.id,s.code,s.title,s.section FROM subjects s JOIN academic_terms t ON t.id=s.term_id
        WHERE s.active=1 AND s.faculty_user_id=? AND s.department_id=? AND t.semester=? AND REPLACE(t.academic_year,'–','-')=?
        ORDER BY s.code,s.section",[$u['id'],$u['department_id'],$term[1],$term[3]]);
    $counts=[];
    foreach(activeRows(assignmentRows($u,true)) as $r){
        if($r['subject_id'] && $r['semester']===$term[1] && str_replace('–','-',$r['academic_year'])===$term[3]){
            $counts[$r['subject_id']]=1+($counts[$r['subject_id']]??0);
        }
    }
    pageHead('My Subjects');facultyToolbar('my-subjects','Search subjects',$term);
    $body=[];
    foreach(paginate(filterRows($subjects,[],false),$footer,'subjects') as $s){
        $params=['subject_id'=>$s['id'],'semester'=>$term[1],'academic_year'=>$term[3]];
        $body[]=[h($s['code']),h($s['title']),h($s['section']),linkTo('requirements',(string)($counts[$s['id']]??0),$params)];
    }
    tableView(['Subject Code','Subject Title','Section','Requirements'],$body,'No assigned subjects match this view.');echo $footer;
}
function facultyDirectoryView(array $u,string $page): void {
    $submissions=$page==='submissions';
    $records=assignmentRows($u,true);
    $term=directoryTermFilters(array_map(fn($r)=>str_replace('–','-',$r['academic_year']),$records),settings());
    $items=[];$subjects=[];
    foreach($records as $r){
        if($r['semester']!==$term[1] || str_replace('–','-',$r['academic_year'])!==$term[3])continue;
        if($submissions?!$r['submission_id']:!$r['assigned'])continue;
        $r['status']=$submissions?facultyApproval($r):match(true){
            $r['review_status']==='Needs Revision'=>'Returned',(bool)$r['submission_id']=>'Submitted',$r['timeliness']==='Overdue'=>'Overdue',default=>'Pending'
        };
        if($r['subject_id'])$subjects[$r['subject_id']]=facultySubjectLabel($r);
        $items[]=$r;
    }
    asort($subjects);
    if($submissions)usort($items,fn($a,$b)=>strcmp($b['submitted_at'],$a['submitted_at']) ?: $b['version_id']<=>$a['version_id']);
    pageHead($submissions?'My Submissions':'Requirements');
    facultyToolbar($page,$submissions?'Search submissions':'Search requirements',$term,$subjects,$submissions?['Approved','For Review','Returned']:['Pending','Overdue','Submitted','Returned']);
    $visible=paginate(filterRows($items,['subject_id'=>'Subject','status'=>'Status'],false),$footer,$page);
    $body=[];$dialogs=[];
    foreach($visible as $r){
        $name=h($r['requirement']);
        $upload=!$submissions && $r['status']!=='Submitted';
        if($upload){
            $label=$r['status']==='Returned'?'Resubmit':'Submit';
            $actions='<button type="button" class="btn btn-primary" data-open-review="upload-'.$r['id'].'">'.icon($label==='Resubmit'?'arrow-repeat':'upload').' '.$label.'</button>';
        }else{
            $actions='<button type="button" class="user-action" data-open-review="view-'.$r['id'].'" title="View submission" aria-label="View submission for '.$name.'">'.icon('eye').'</button>';
            if($submissions)$actions.='<a class="user-action" href="'.url('download',['id'=>$r['version_id']]).'" aria-label="Download '.$name.'" title="Download file">'.icon('download').'</a>';
        }
        $date=$submissions?date('M j, Y, g:i A',strtotime($r['submitted_at'])):deadlineLabel($r['deadline']);
        $body[]=[$name,h($r['subject_code']??'—'),h($date),badge($r['status']),'<div class="user-actions">'.$actions.'</div>'];
        $dialogs[]=[$r,$upload];
    }
    tableView(['Requirement','Subject',$submissions?'Submitted Date & Time':'Due Date','Status','Actions'],$body,'No '.$page.' match this view.');echo $footer;
    foreach($dialogs as [$r,$upload]){
        if($upload)facultyUploadDialog($r);
        else facultySubmissionDialog($r);
    }
}
function facultySubmissionDialog(array $r): void {
    reviewDialogStart('view-'.$r['id'],$r['requirement']);
    echo '<dl class="submission-details">';
    foreach(['Document'=>$r['title'].' · v'.$r['version_no'],'Subject'=>facultySubjectLabel($r),'Submitted'=>date('M j, Y, g:i A',strtotime($r['submitted_at'])),'Submission Status'=>$r['timeliness'],'Approval Status'=>facultyApproval($r),'Remarks'=>$r['author_remarks'],'Reviewer feedback'=>$r['review_remarks']] as $label=>$value){
        if($value!==null && $value!=='')echo '<dt>'.h($label).'</dt><dd>'.h($value).'</dd>';
    }
    echo '</dl>'.linkTo('download','Download '.$r['original_name'],['id'=>$r['version_id']],'btn btn-primary').' '.linkTo('detail','Submission history',['id'=>$r['id']]).'</div></dialog>';
}
function facultyUploadDialog(array $r): void {
    $revision=$r['status']==='Returned';
    $title=$revision?'Resubmit Document':'Submit Document';
    reviewDialogStart('upload-'.$r['id'],$title);
    echo '<div class="upload-summary">';
    $subject=$r['subject_id']?$r['subject_code'].' – '.$r['subject_section']:'—';
    foreach([['Requirement',$r['requirement'],'file-earmark-text'],['Subject',$subject,'book'],['Deadline',deadlineLabel($r['deadline']).' · 11:59 PM','calendar3'],['Allowed Formats',strtoupper(str_replace(',',', ',$r['allowed_formats'])),'layers']] as [$label,$value,$ic]){
        echo '<div>'.icon($ic).'<span>'.h($label).'<strong>'.h($value).'</strong></span></div>';
    }
    echo '</div>';
    if($r['description'] || $r['has_template']){
        echo '<div class="upload-instructions"><strong>Instructions</strong><p>'.nl2br(h($r['description'])).'</p>';
        if($r['has_template'])echo linkTo('template-download','Download template',['id'=>$r['type_id']]);
        echo '</div>';
    }
    if($revision)echo '<div class="upload-instructions"><strong>Reviewer feedback</strong><p>'.nl2br(h($r['review_remarks'])).'</p></div>';
    startForm('upload',0,'enctype="multipart/form-data" data-modal-form="1"');
    echo '<input type="hidden" name="modal_form" value="1"><input type="hidden" name="assignment_id" value="'.h($r['id']).'">';
    echo '<strong>Upload your document</strong><label class="faculty-drop">'.icon('cloud-arrow-up').'<span>Drag and drop your file here</span><small>or choose a file</small><small>Maximum file size: '.MAX_UPLOAD_MB.' MB</small><input type="file" name="file" aria-label="Upload your document" required accept=".'.h(str_replace(',',',.',$r['allowed_formats'])).'"></label>';
    textarea('remarks','Remarks','',true);
    echo '<div class="upload-actions"><button type="button" data-cancel-upload class="btn btn-outline-secondary">Cancel</button><button type="submit" class="btn btn-primary">'.icon('upload').' '.h($title).'</button></div></form></div></dialog>';
}
