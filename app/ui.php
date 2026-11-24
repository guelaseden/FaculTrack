<?php
function url(string $page,array $params=[]): string {return '?'.h(http_build_query(['p'=>$page]+$params));}
function linkTo(string $page,string $label,array $params=[],string $class=''): string {return '<a class="'.h($class).'" href="'.url($page,$params).'">'.h($label).'</a>';}
function icon(string $name): string {return '<i aria-hidden="true" class="bi bi-'.h($name).'"></i>';}
function startForm(string $page,int $id=0,string $extra=''): void {echo '<form method="post" action="'.url($page,$id?['id'=>$id]:[]).'" novalidate autocomplete="off" '.$extra.'><input type="hidden" name="csrf" value="'.h($_SESSION['csrf']).'">';}
function field(string $key,string $label,$value='',string $type='text',bool $required=true,string $placeholder=''): void {
    echo '<label class="form-label">'.($placeholder?'':h($label)).'<input class="form-control" name="'.h($key).'" type="'.h($type).'" value="'.h($value).'" aria-label="'.h($label).'"'.($placeholder?' placeholder="'.h($placeholder).'"':'').'></label>';
}
function passwordField(string $key,string $label,string $autocomplete,bool $required=false,string $idSuffix=''): void {
    $id='password-'.$key.($idSuffix?'-'.$idSuffix:'');
    echo '<label class="form-label">'.h($label).'<span class="password-field-wrap password-wrap"><input class="form-control" id="'.h($id).'" name="'.h($key).'" type="password" autocomplete="'.h($autocomplete).'" aria-label="'.h($label).'"'.($required?' required':'').'><button type="button" data-password-toggle aria-controls="'.h($id).'" aria-label="Show password" hidden>'.icon('eye-slash').'</button></span></label>';
}
function textarea(string $key,string $label,$value='',bool $optional=false): void {echo '<label class="form-label">'.h($label).($optional?' <span class="optional-label">(optional)</span>':'').'<textarea class="form-control" name="'.h($key).'" rows="3">'.h($value).'</textarea></label>';}
function selectBox(string $key,string $label,array $options,$value='',string $extra='',bool $placeholder=false): void {
    echo '<label class="form-label">'.($placeholder?'':h($label)).'<select class="form-select" name="'.h($key).'" aria-label="'.h($label).'" '.$extra.'>';
    echo '<button type="button" class="select-value"><selectedcontent></selectedcontent></button>';
    foreach ($options as $k=>$v) echo '<option value="'.h($k).'" '.((string)$value===(string)$k?'selected':'').'>'.h($v).'</option>';
    echo '</select></label>';
}
function checkBox(string $key,string $label,bool $checked,string $extra=''): void {
    echo '<label class="form-check form-switch"><input class="form-check-input" type="checkbox" name="'.h($key).'" value="1" '.($checked?'checked':'').' '.$extra.'> '.h($label).'</label>';
}
function saveButton(string $label='Save Changes'): void {echo '<button class="btn btn-primary" type="submit">'.h($label).'</button></form>';}
function badge(mixed $value): string {
    $class=match($value){'Approved','Active','Completed','Submitted','On Time'=>'success','Overdue','Inactive','Needs Revision','Returned','Not Submitted','Late'=>'danger','Pending','Pending Review','For Review','Upcoming'=>'warning',default=>'neutral'};
    return '<span class="badge badge-'.$class.' status-'.$class.'">'.h($value).'</span>';
}
function tableView(array $columns,array $items,string $empty='No records match this view.',string $class=''): void {
    echo '<div class="table-wrap"><table class="table'.($class?' '.h($class):'').'"><thead><tr>';foreach($columns as $col)echo '<th scope="col">'.h($col).'</th>';echo '</tr></thead><tbody>';
    foreach ($items as $cells){echo '<tr>';foreach($cells as $cell) echo '<td>'.$cell.'</td>';echo '</tr>';}
    if (!$items) echo '<tr><td colspan="'.count($columns).'" class="empty-state">'.h($empty).'</td></tr>';
    echo '</tbody></table></div>';
}
function filterOptions(array $items,string $key): array {
    $values=array_unique(array_filter(array_column($items,$key),static fn($v)=>$v!==null && $v!==''));
    sort($values);
    return array_combine($values,$values);
}
function filterRows(array $items,array $keys,bool $render=true,bool $inlinePrompts=false): array {
    $term=trim((string)($_GET['q']??''));
    if($render){
        echo '<form method="get" class="filters"><input type="hidden" name="p" value="'.h($_GET['p']??'dashboard').'">';
        foreach (['faculty_id','report'] as $key) if(isset($_GET[$key]))echo '<input type="hidden" name="'.$key.'" value="'.h($_GET[$key]).'">';
        echo '<label class="form-label">Search<input type="search" class="form-control" name="q" placeholder="Search records" value="'.h($term).'"></label>';
        foreach($keys as $key=>$label)selectBox($key,$label,[''=>$inlinePrompts?'All '.$label:'All']+filterOptions($items,$key),$_GET[$key]??'','',$inlinePrompts);
        echo '<button class="btn btn-outline-secondary">Apply filters</button></form>';
    }
    return array_values(array_filter($items,function($row)use($term,$keys){
        if ($term!=='' && stripos(implode(' ',array_map('strval',$row)),$term)===false)return false;
        foreach($keys as $k=>$label) if(($_GET[$k]??'')!=='' && (string)$_GET[$k] !== (string)($row[$k]??''))return false;
        return true;
    }));
}
function paginate(array $items,?string &$footer,string $noun='records'): array {
    $size=10;
    $total=count($items);
    $pages=max(1,(int)ceil($total/$size));
    $page=min($pages,max(1,(int)($_GET['pg']??1)));
    $offset=($page-1)*$size;
    $visible=array_slice($items,$offset,$size);
    $range=$total?number_format($offset+1).'–'.number_format($offset+count($visible)):'0';
    $footer='<div class="table-footer no-print"><span>Showing '.$range.' of '.number_format($total).'</span><nav aria-label="'.h(ucfirst($noun).' pagination').'">';
    $link=static function(int $target,string $text,string $label)use($pages): string {
        if($target<1 || $target>$pages)return '<span aria-disabled="true" aria-label="'.$label.'">'.$text.'</span>';
        $href='?'.h(http_build_query(array_merge($_GET,['pg'=>$target])));
        return '<a href="'.$href.'" aria-label="'.$label.'">'.$text.'</a>';
    };
    if($page>1)$footer.=$link(1,'«','First page');
    $footer.=$link($page-1,'‹','Previous page');
    $first=intdiv($page-1,10)*10+1;
    for($n=$first;$n<=min($first+9,$pages);$n++){
        $footer.=$n===$page?'<span aria-current="page" aria-label="Page '.$n.'">'.$n.'</span>':$link($n,(string)$n,'Page '.$n);
    }
    $footer.=$link($page+1,'›','Next page');
    if($page<$pages)$footer.=$link($pages,'»','Last page');
    $footer.='</nav></div>';
    return $visible;
}
function navGroups(array $u): array {
    if(admin($u))return ['Administrator'=>[
        'dashboard'=>['Dashboard','grid-1x2'],'users'=>['Users','people'],'departments'=>['Departments','buildings'],
        'academic-terms'=>['Academic Terms','calendar3'],'manage'=>['Requirements','journal-text'],
        'review'=>['Submissions','clipboard-check'],'types'=>['Documents','files'],'reports'=>['Reports','bar-chart'],
        'settings'=>['Settings','gear']
    ]];
    if($u['role']==='Department Head')return ['Department Head'=>[
        'dashboard'=>['Dashboard','grid-1x2'],'faculty'=>['Faculty','people'],'subjects'=>['Subjects','book'],
        'manage'=>['Requirements','journal-text'],'review'=>['Submissions','clipboard-check'],
        'reports'=>['Reports','bar-chart'],'settings'=>['Settings','gear']
    ]];
    return ['Faculty'=>[
        'dashboard'=>['Dashboard','grid-1x2'],'my-subjects'=>['My Subjects','book'],'requirements'=>['Requirements','clipboard-check'],
        'submissions'=>['My Submissions','file-earmark-text'],'settings'=>['Settings','gear']
    ]];
}
function routeMap(array $u): array {
    $routes=array_merge(...array_values(navGroups($u)));
    if(admin($u))$routes+=['logs'=>['Activity Logs','clock-history'],'profile'=>['Settings','gear']];
    if($u['role']==='Department Head')$routes+=['profile'=>['Settings','gear'],'deadlines'=>['Deadlines','calendar3']];
    $routes+=['profile'=>['Settings','gear']];
    if(teaching($u))$routes+=['requirements'=>['My Requirements','list-check'],'upload'=>['Upload Document','cloud-arrow-up'],'submissions'=>['My Submissions','file-earmark-text'],'personal-deadlines'=>['My Deadlines','calendar3'],'history'=>['Submission History','clock-history']];
    return $routes;
}
function pageHead(string $title,string $text='',string $action=''): void {
    echo '<div class="page-heading"><div><h1>'.h($title).'</h1>'.($text?'<p>'.h($text).'</p>':'').'</div>'.$action.'</div>';
}
function panel(string $title,string $action=''): void {echo '<section class="panel"><div class="panel-heading"><h2>'.h($title).'</h2>'.$action.'</div><div class="panel-body">';}
function endPanel(): void {echo '</div></section>';}
