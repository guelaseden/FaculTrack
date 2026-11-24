<?php
declare(strict_types=1);
require __DIR__.'/../app/core.php';
require ROOT.'/app/records.php';
require ROOT.'/app/manage.php';
require ROOT.'/app/ui.php';
require ROOT.'/views/layout.php';
require ROOT.'/views/workspace.php';
require ROOT.'/views/management.php';
require ROOT.'/views/dashboard.php';
require ROOT.'/views/faculty.php';
initSession();
header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
$page=(string)($_GET['p']??'dashboard');$id=(int)($_GET['id']??$_POST['id']??0);$post=$_SERVER['REQUEST_METHOD']==='POST';
$modalRequest=$post && ($_SERVER['HTTP_X_MODAL_REQUEST']??null)==='1' && ($_POST['modal_form']??null)==='1';
function modalErrorField(string $page,string $message): ?string {
    $text=mb_strtolower($message,'UTF-8');
    if($page==='subjects' && str_contains($text,'units'))return 'units';
    if($page==='subjects' && str_contains($text,'faculty member'))return 'faculty_user_id';
    if($page==='subjects' && str_contains($text,'academic term'))return 'term_id';
    if($page==='manage' && str_contains($text,'subject'))return 'subject_id';
    if(str_contains($text,'full name'))return 'full_name';
    if(str_contains($text,'employee id'))return 'employee_id';
    if(str_contains($text,'email'))return 'email';
    if(str_contains($text,'department name'))return 'name';
    if(str_contains($text,'department code'))return 'code';
    if(str_contains($text,'password'))return 'password';
    if(str_contains($text,'department'))return 'department_id';
    if(str_contains($text,'role'))return 'role';
    if(str_contains($text,'academic year'))return 'academic_year';
    if(str_contains($text,'start date'))return 'start_date';
    if(str_contains($text,'end date'))return 'end_date';
    if(str_contains($text,'semester') || str_contains($text,'academic term'))return 'semester';
    if(str_contains($text,'document type'))return $page==='manage'?'type_id':'name';
    if(str_contains($text,'requirement title'))return 'title';
    if(str_contains($text,'deadline'))return 'deadline';
    if(str_contains($text,'file format'))return 'formats';
    if(preg_match('/check the value for (.+?)\./i',$message,$match))return str_replace(' ','_',strtolower($match[1]));
    if(preg_match('/select a valid (.+?)\./i',$message,$match))return str_replace(' ','_',strtolower($match[1]));
    return null;
}
ob_start();
try {
    if ($page==='register') redirect(!empty($_SESSION['uid'])?'dashboard':'login');
    if ($post) {
        if ($page==='login') {
            valid(isset($_SESSION['csrf']) && is_string($_SESSION['csrf']) && is_string($_POST['csrf']??null) && hash_equals($_SESSION['csrf'],$_POST['csrf']),'Your session expired. Refresh the page and try again.');
        } else {
            allow(is_string($_POST['csrf']??null) && hash_equals($_SESSION['csrf'],$_POST['csrf']));
        }
    }
    if ($page==='login'){
        if ($post && !empty($_SESSION['uid'])) redirect('dashboard');
        if ($post){
            $fieldErrors=[];
            if (!is_string($_POST['employee_id']??null) || trim((string)$_POST['employee_id'])==='') $fieldErrors['employee_id']='Employee ID is required.';
            if (!is_string($_POST['password']??null) || $_POST['password']==='') $fieldErrors['password']='Password is required.';
            if ($fieldErrors) {loginView($fieldErrors);exit;}
            $employeeId=employeeIdInput();
            $password=loginPassword();
            $now=time();
            $_SESSION['login_attempts']??=['count'=>0,'until'=>0];
            $lockoutRemaining=(int)($_SESSION['login_attempts']['until']??0)-$now;
            if ($lockoutRemaining>0) {
                $minutes=max(1,(int)ceil($lockoutRemaining/60));
                $unit=$minutes===1?'minute':'minutes';
                valid(false,"Too many attempts. Try again in {$minutes} {$unit}.|lock-keyhole");
            }
            $candidate=one('SELECT * FROM users WHERE faculty_id=?',[$employeeId]);
            $dummy='$2y$10$usesomesillystringfore7hnbRJHxXV.owhnk/pqz1YjZ5v8fC9G0';
            $hash=$candidate['password_hash']??$dummy;
            $passwordValid=(bool)$candidate && password_verify($password,$hash);
            if(!$passwordValid){
                $_SESSION['login_attempts']['count']=($_SESSION['login_attempts']['count']??0)+1;
                if($_SESSION['login_attempts']['count']>=5){
                    $_SESSION['login_attempts']['until']=$now+5*60;
                    $_SESSION['login_attempts']['count']=0;
                }
                throw new DomainException('Incorrect Employee ID or password.|circle-x');
            }
            valid($candidate['registration_status']!=='Pending','Your registration is awaiting administrator approval.');
            valid($candidate['registration_status']!=='Declined','Your registration was declined. Contact your administrator.');
            valid((bool)$candidate['active'],'Your account is inactive. Please contact the administrator.|user-round-x');
            $_SESSION['login_attempts']=['count'=>0,'until'=>0];
            session_regenerate_id(true);$_SESSION=['uid'=>$candidate['id'],'csrf'=>bin2hex(random_bytes(32)),'last'=>time()];
            if (($_POST['remember']??null)==='1') createRememberedLogin((int)$candidate['id']);
            else clearRememberedLoginCookie();
            redirect('dashboard');
        }
        loginView();exit;
    }
    if(empty($_SESSION['uid']))redirect('login');
    $u=currentUser();if(!$u){$_SESSION=[];redirect('login');}
    if ($page==='logout'){
        allow($post);
        forgetRememberedLogin((int)$u['id']);
        setcookie(session_name(),'',[
            'expires'=>time()-3600,'path'=>'/',
            'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off','httponly'=>true,'samesite'=>'Lax'
        ]);
        $_SESSION=[];session_destroy();redirect('login');
    }
    $routes=routeMap($u);
    allow(isset($routes[$page]) || in_array($page,['detail','download','template-download'],true));
    if ($page==='download')downloadFile($u,$id);
    if ($page==='template-download')downloadTemplate($u,$id);
    if ($post) {
        db()->beginTransaction();
        // Serialize account permission edits with actions by the affected user.
        $u=one('SELECT * FROM users WHERE id=? AND active=1 FOR UPDATE',[$_SESSION['uid']]);
        allow((bool)$u);
        $allowedPages=routeMap($u);
        allow(isset($allowedPages[$page]) || $page==='detail');
        $message=match($page){
            'users'=>saveUser($u,$id),'faculty'=>saveUser($u,$id,true),'departments','types'=>saveCatalog($u,$page,$id),'subjects'=>saveSubject($u,$id),'academic-terms'=>saveAcademicTerm($u,$id),'manage'=>saveRequirement($u,$id),
            'upload'=>submitFile($u),'detail','review'=>reviewFile($u,$id),'profile'=>saveProfile($u),
            default=>throw new AccessDenied('This action is unavailable.')
        };
        $pendingDeleteFiles=$GLOBALS['pending_delete_files']??[];
        db()->commit();unset($GLOBALS['pending_file'],$GLOBALS['pending_delete_files']);
        foreach($pendingDeleteFiles as $oldKey){
            $oldFile=storage().DIRECTORY_SEPARATOR.$oldKey;
            if(is_file($oldFile) && !unlink($oldFile))error_log('Unable to remove replaced private template: '.$oldFile);
        }
        $_SESSION['flash']=['success',$message];
        if($modalRequest){
            header('Content-Type: application/json; charset=utf-8');
            $target=['p'=>$page];
            if($page==='upload'){
                $uploaded=record($u,(int)$_POST['assignment_id']);
                $target=['p'=>'submissions','semester'=>$uploaded['semester'],'academic_year'=>$uploaded['academic_year']];
            }
            echo json_encode(['ok'=>true,'redirect'=>'?'.http_build_query($target)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            exit;
        }
        if($page==='review')redirect('review',array_intersect_key($_GET,array_flip(['semester','academic_year','subject_id','faculty_id','review_status','pg'])));
        if (in_array($page,['profile','settings'],true)) {
            $tab=is_string($_POST['settings_tab']??null)?$_POST['settings_tab']:'profile';
            redirect('settings',['tab'=>$tab]);
        }
        redirect($page==='upload'?'submissions':$page,$page==='detail'?['id'=>$id]:[]);
    }
    if ($page==='profile') redirect('settings',['tab'=>($_GET['tab']??'')==='password'?'password':'profile']);
    if($page==='reports' && isset($_GET['csv']))reportView($u,true);
    if($page==='reports' && $u['role']==='Department Head' && ($_GET['export']??'')==='xlsx')departmentReportView($u,true);
    if($page==='reports' && admin($u) && ($_GET['export']??'')==='xlsx')reportView($u,false,true);
    $title=$routes[$page][0]??'Submission details';shellStart($u,$page,$title);editModal();confirmationDialog();flashMessage();
    match($page){
        'dashboard'=>dashboardView($u),'my-subjects'=>facultySubjectsView($u),'requirements','submissions','history','review','deadlines','personal-deadlines'=>listView($u,$page,$title),
        'detail'=>detailView($u,$id),'upload'=>uploadView($u),'reports'=>reportView($u),
        'users','faculty','departments','types','manage'=>managementView($u,$page,$title,$id),'subjects'=>subjectsView($u,$id),'academic-terms'=>academicTermsView($u,$id),'profile','settings'=>settingsView($u,$page),
        'logs'=>logsView()
    };
    echo '</main></div></div></body></html>';
} catch(Throwable $e) {
    if ($post && $page!=='login' && isset($u) && db()->inTransaction()) db()->rollBack();
    if(isset($GLOBALS['pending_file']) && is_file($GLOBALS['pending_file']) && !unlink($GLOBALS['pending_file']))error_log('Unable to remove uncommitted private upload: '.$GLOBALS['pending_file']);
    ob_clean();
    if ($post && $page==='login' && $e instanceof PDOException) {
        error_log('Sign-in database error: '.$e->getMessage());
        http_response_code(503);
        loginView([],'Sign-in is unavailable because of a database problem. Please contact the system administrator.','circle-alert');exit;
    }
    if($e instanceof DomainException || ($e instanceof PDOException && $e->getCode()==='23000')){
        if ($post && $page==='login') {
            $message=$e instanceof DomainException?$e->getMessage():'';
            [$text,$icon]=array_pad(explode('|',$message,2),2,'');
            loginView([], $text ?: 'Unable to sign in. Please try again later.', $icon ?: 'circle-alert');exit;
        }
        $message=$e instanceof DomainException?$e->getMessage():'This value is already used or is linked to another record.';
        if($modalRequest){
            http_response_code(422);header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok'=>false,'message'=>$message,'field'=>modalErrorField($page,$message)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            exit;
        }
        $_SESSION['flash']=['danger',$message];
        if (!$post) redirect('dashboard');
        if (isset($u) && in_array($page,['profile','settings'],true)) {
            $tab=is_string($_POST['settings_tab']??null)?$_POST['settings_tab']:'profile';
            redirect('settings',['tab'=>$tab]);
        }
        $passwordReset=$post && in_array($page,['users','faculty'],true) && ($_POST['action']??'')==='reset_password';
        $modalForm=$post && ($_POST['modal_form']??null)==='1';
        $redirectParams=$passwordReset || $modalForm?[]:($id?['id'=>$id]:[]);
        if(isset($_GET['new']) || ($post && in_array($page,['users','departments','types','manage'],true) && !$passwordReset))$redirectParams=['new'=>1];
        if($post && $page==='academic-terms' && !$id)$redirectParams=['new'=>1];
        if($page==='faculty' && !$id && !$passwordReset)$redirectParams=['new'=>1];
        if($modalForm)$redirectParams=[];
        redirect($page,$redirectParams);
    }
    if ($post && $page==='login') {
        error_log((string)$e);
        http_response_code(500);
        loginView([],'The server could not complete sign-in. Please try again later.','circle-alert');exit;
    }
    $denied=$e instanceof AccessDenied;if(!$denied)error_log((string)$e);
    if($modalRequest){
        http_response_code($denied?403:500);header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'message'=>$denied?$e->getMessage():'The form could not be saved. Please try again.','field'=>null],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        exit;
    }
    http_response_code($denied?403:500);headerHtml($denied?'Access restricted':'Service unavailable');
    echo '<main class="content"><h1>'.($denied?'Access restricted':'Service unavailable').'</h1><p>'.($denied?h($e->getMessage()):'Check the database configuration and application log.').'</p>'.linkTo('dashboard','Return to dashboard').'</main></body></html>';
}

