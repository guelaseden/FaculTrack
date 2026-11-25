<?php
function dashboardView(array $u): void {
    $defaults=settings();
    $legacyId=(int)($_GET['term_id']??0);
    if($legacyId)$defaults=one('SELECT academic_year,semester FROM academic_terms WHERE id=? AND active=1',[$legacyId]) ?: $defaults;
    [$semesters,$semester,$years,$year]=directoryTermFilters([],$defaults);
    $selectedTerm=['academic_year'=>$year,'semester'=>$semester];
    $items=array_filter(activeRows(assignmentRows($u)),fn($r)=>str_replace('–','-',$r['academic_year'])===$year && $r['semester']===$semester);
    $sent=array_values(array_filter($items,fn($r)=>$r['submission_id']));
    usort($sent,fn($a,$b)=>strcmp($b['submitted_at'],$a['submitted_at']));
    ob_start();
    echo '<form method="get" class="dashboard-term-filter" aria-labelledby="dashboard-term-label"><span class="form-label" id="dashboard-term-label">Academic Term:</span>';
    selectBox('semester','Semester',$semesters,$semester,'',true);
    selectBox('academic_year','Academic Year',$years,$year,'',true);
    echo '</form>';
    pageHead('Dashboard','',ob_get_clean());
    $total=count($items);
    $percent=fn(int $count): int=>$total?(int)round(100*$count/$total):0;
    if(admin($u)){
        $onTime=count(array_filter($sent,fn($r)=>$r['timeliness']==='On Time'));
        $late=count(array_filter($sent,fn($r)=>$r['timeliness']==='Late'));
        $missing=max(0,$total-$onTime-$late);
        $metrics=[
            ['Total Required',number_format($total),'file-earmark-text','neutral'],
            ['Submitted On Time',number_format($onTime).' ('.$percent($onTime).'%)','check-circle','success'],
            ['Submitted Late',number_format($late).' ('.$percent($late).'%)','clock','warning'],
            ['Missing / Overdue',number_format($missing).' ('.$percent($missing).'%)','exclamation-circle','danger']
        ];
    }elseif(!manager($u)){
        $overdue=count(array_filter($items,fn($r)=>$r['timeliness']==='Overdue'));
        $metrics=[['Total Required',$total,'file-earmark-text','neutral'],['Submitted',count($sent),'check-circle','success'],
            ['Pending',$total-count($sent)-$overdue,'clock','warning'],['Overdue',$overdue,'exclamation-circle','danger']];
    }else{
        $faculty=one("SELECT COUNT(*) n FROM users WHERE department_id=? AND active=1 AND (role='Faculty' OR is_teaching)",[$u['department_id']]);
        $review=count(array_filter($sent,fn($r)=>$r['review_status']==='Pending Review'));
        $overdue=count(array_filter($items,fn($r)=>$r['timeliness']==='Overdue'));
        $metrics=[
            ['Total Faculty',number_format($faculty['n']),'person','neutral'],
            ['Submitted',count($sent).' ('.$percent(count($sent)).'%)','check-circle','success'],
            ['For Review',$review.' ('.$percent($review).'%)','clock','warning'],
            ['Overdue',$overdue,'exclamation-circle','danger']
        ];
    }
    echo '<div class="stats-grid admin-stats">';foreach($metrics as [$label,$value,$ic,$status])echo '<article class="stat-card stat-'.$status.'"><span class="stat-icon">'.icon($ic).'</span><div class="stat-value">'.h($value).'</div><div class="stat-label">'.h($label).'</div></article>';echo '</div>';
    if(!manager($u)){facultyDashboardPanels($items,$sent,$selectedTerm);return;}
    if(!admin($u)){
        departmentDashboardPanels($sent,$total,$percent(count($sent)),$selectedTerm);
        return;
    }
    if(!$items)echo '<div class="dashboard-note">'.icon('info-circle').'<span>No active requirements are assigned for this academic term yet.</span>'.linkTo('manage','Create Requirement',[],'btn btn-sm btn-primary').'</div>';
    echo '<div class="dashboard-grid admin-dashboard-grid">';
    $groups=[];foreach($items as $item){$name=$item['department_code'].' - '.$item['department'];$groups[$name]??=['required'=>0,'submitted'=>0];$groups[$name]['required']++;$groups[$name]['submitted']+=(int)(bool)$item['submission_id'];}
    uasort($groups,fn($a,$b)=>($b['required']?100*$b['submitted']/$b['required']:0)<=>($a['required']?100*$a['submitted']/$a['required']:0));
    panel('Department Compliance');if(!$groups)echo '<div class="dashboard-empty">'.icon('bar-chart-line').'<strong>No compliance data yet</strong><span>Department progress will appear after requirements are assigned.</span></div>';else{foreach($groups as $name=>$group){$rate=$group['required']?round(100*$group['submitted']/$group['required']):0;echo '<div class="compliance-row"><span>'.h($name).'</span><div class="compliance-track" role="img" aria-label="'.h($rate).'% submitted"><span style="width:'.$rate.'%"></span></div><strong>'.h($rate).'%</strong></div>';}echo '<div class="compliance-scale"><span>0%</span><span>20%</span><span>40%</span><span>60%</span><span>80%</span><span>100%</span></div>';}endPanel();
    panel('Recent Submissions',linkTo('reports','View All',[],'dashboard-view-all'));$body=[];
    foreach(array_slice($sent,0,6) as $item)$body[]=[h($item['faculty']),h($item['department_code']),h($item['document_type']),h(date('M j, g:i A',strtotime($item['submitted_at']))),badge($item['timeliness'])];
    if(!$sent)echo '<div class="dashboard-empty">'.icon('file-earmark-arrow-up').'<strong>No submissions yet</strong><span>Recent faculty uploads will appear here.</span></div>';else tableView(['Faculty Name','Department','Document','Submitted','Status'],$body);endPanel();echo '</div>';
}
function departmentDashboardPanels(array $sent,int $total,int $rate,?array $term): void {
    $filters=$term?['academic_year'=>$term['academic_year'],'semester'=>$term['semester']]:[];
    echo '<div class="dashboard-grid admin-dashboard-grid department-panels">';
    panel('Submission Compliance');
    echo '<div class="department-compliance"><div class="progress-ring" style="--progress:'.$rate.'%"><div><strong>'.$rate.'%</strong><small>Completion</small></div></div>';
    echo '<dl><div><dt>Submitted</dt><dd>'.count($sent).'</dd></div><div><dt>Pending</dt><dd>'.($total-count($sent)).'</dd></div></dl></div>';
    if(!$total)echo '<p class="department-empty">No assigned requirements for this term.</p>';
    endPanel();
    panel('Recent Submissions',linkTo('review','View All',$filters,'dashboard-view-all'));
    if(!$sent)echo '<div class="dashboard-empty">'.icon('file-earmark-text').'<strong>No submissions yet</strong><span>Faculty uploads for this term will appear here.</span></div>';
    foreach(array_slice($sent,0,3) as $row){
        echo '<a class="department-submission" href="'.url('detail',['id'=>$row['id']]).'">'.icon('file-earmark-text').'<span><strong>'.h($row['title']).'</strong><small>Submitted by: '.h($row['faculty']).'</small></span><time>'.h(dateLabel($row['submitted_at'])).'</time></a>';
    }
    endPanel();
    echo '</div>';
}
