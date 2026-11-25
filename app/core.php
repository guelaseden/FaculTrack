<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Manila');
const ROOT = __DIR__.'/..';
const ROLES = ['Faculty','Department Head','Administrator'];
const TERMS = ['First Semester','Second Semester','Summer'];
const MAX_UPLOAD_MB = 10;
function config(string $key, string $default=''): string {
    static $env;
    $env ??= is_file(ROOT.'/.env') ? parse_ini_file(ROOT.'/.env',false,INI_SCANNER_RAW) : [];
    $value = getenv('FACULTRACK_'.$key);
    return $value !== false ? $value : ($env['FACULTRACK_'.$key] ?? $default);
}
function db(): PDO {
    static $db;
    if (!$db) {
        $dsn='mysql:host='.config('DB_HOST','127.0.0.1').';port='.config('DB_PORT','3306').';dbname='.config('DB_NAME','facul_track').';charset=utf8mb4';
        $db=new PDO($dsn,config('DB_USER','facul_track_app'),config('DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
        $db->exec("SET time_zone='+08:00'");
    }
    return $db;
}
function query(string $sql,array $params=[]): PDOStatement {
    $stmt=db()->prepare($sql); $stmt->execute($params); return $stmt;
}
function rows(string $sql,array $params=[]): array {return query($sql,$params)->fetchAll();}
function one(string $sql,array $params=[]): array {return query($sql,$params)->fetch() ?: [];}
function audit(string $event,string $detail): void {
    query('INSERT INTO activity_logs(actor_id,action,detail) VALUES(?,?,?)',[$_SESSION['uid']??null,$event,$detail]);
}
class AccessDenied extends RuntimeException {}
function allow(bool $condition): void {if (!$condition) throw new AccessDenied('You do not have access to this record or action.');}
function valid(bool $condition,string $message): void {if (!$condition) throw new DomainException($message);}
function teaching(array $u): bool {return $u['role']==='Faculty' || (bool)$u['is_teaching'];}
function admin(array $u): bool {return $u['role']==='Administrator';}
function manager(array $u): bool {return $u['role']!=='Faculty';}
function input(string $key,int $max=180,bool $required=true): string {
    $value=$_POST[$key]??''; valid(is_string($value),'Invalid field value.');
    $value=trim($value); valid((!$required || $value!=='') && mb_strlen($value)<=$max,'Check the value for '.str_replace('_',' ',$key).'.'); return $value;
}
function choice(string $key,array $options): string {$v=input($key); valid(in_array($v,$options,true),'Select a valid '.str_replace('_',' ',$key).'.'); return $v;}
function emailInput(): string {
    $v=input('email');$at=strrpos($v,'@');
    valid((bool)filter_var($v,FILTER_VALIDATE_EMAIL) && $at!==false && str_contains(substr($v,$at+1),'.'),'Enter a valid email address.');
    return $v;
}
function employeeIdInput(string $field='employee_id'): string {
    $value=input($field,48);
    valid((bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{2,47}$/D',$value),'Use 3–48 letters, numbers or hyphens for your Employee ID.');
    return $value;
}
function loginPassword(): string {
    $password=$_POST['password']??'';
    valid(is_string($password),'Invalid password.');
    valid(mb_check_encoding($password,'UTF-8') && strlen($password)<=1024,'Enter a valid password.');
    valid(!str_contains($password,chr(0)),'Enter a valid password.');
    return $password;
}
function fullNameInput(): string {
    $name=input('full_name',140);
    valid(mb_check_encoding($name,'UTF-8'),'Enter a valid full name.');
    valid((bool)preg_match('/^\p{L}[\p{L}\p{M}]*(?:[ .-]+\p{L}[\p{L}\p{M}]*)*$/u',$name),'Use letters, spaces, periods or hyphens only in your full name.');
    $name=preg_replace_callback('/\p{L}[\p{L}\p{M}]*/u',static function(array $match): string {
        $word=mb_strtolower($match[0],'UTF-8');
        return mb_strtoupper(mb_substr($word,0,1,'UTF-8'),'UTF-8').mb_substr($word,1,null,'UTF-8');
    },$name);
    valid(is_string($name),'Enter a valid full name.');
    return $name;
}
function passwordHash(): string {
    $password=$_POST['password']??'';
    valid(is_string($password) && mb_check_encoding($password,'UTF-8') && mb_strlen($password,'UTF-8')>=8,'Use at least 8 characters.');
    valid((bool)preg_match('/[\p{N}\p{P}\p{S}]/u',$password),'Include at least one number or special character.');
    valid(strlen($password)<=72 && !str_contains($password,chr(0)),'Use a password within 72 bytes with no null characters.');
    return password_hash($password,PASSWORD_DEFAULT);
}
function settings(): array {return one('SELECT * FROM system_settings WHERE id=1');}
function storage(): string {return config('STORAGE',ROOT.'/storage/private');}
function appBasePath(): string {
    $dir=dirname($_SERVER['SCRIPT_NAME']??'/index.php');
    return $dir==='.'?'':rtrim(str_replace('\\','/',$dir),'/');
}
function rolePrefix(string $role): string {
    return match($role){'Administrator'=>'admin','Department Head'=>'department-head','Faculty'=>'faculty'};
}
function pageSlugs(string $prefix): array {
    if($prefix==='faculty')return ['my-subjects'=>'subjects'];
    return ['manage'=>'requirements','review'=>'submissions','types'=>'documents','requirements'=>'my-requirements','submissions'=>'my-submissions'];
}
function pageUrl(string $page,array $params=[]): string {
    unset($params['p']);
    $path=appBasePath().'/';
    if($page!=='login' && !empty($_SESSION['role'])){
        $prefix=rolePrefix($_SESSION['role']);
        $path.=$prefix.'/'.(pageSlugs($prefix)[$page]??$page);
    }
    $query=http_build_query($params);
    return $path.($query!==''?'?'.$query:'');
}
function redirect(string $page,array $params=[]): never {header('Location: '.pageUrl($page,$params),true,303);exit;}
function h(mixed $value): string {return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function dateLabel(?string $d): string {return $d ? date('M j, Y',strtotime($d)) : '—';}
function deadlineLabel(?string $d): string {return $d ? date('m-d-Y',strtotime($d)) : '—';}
function currentUser(): array {return one('SELECT u.*,d.name department FROM users u LEFT JOIN departments d ON d.id=u.department_id WHERE u.id=? AND u.active=1',[$_SESSION['uid']??0]);}
function initSession(): void {
    if (PHP_SAPI === 'cli-server' && ini_get('session.save_handler') === 'files') {
        $sessions = config('SESSION_DIR', ROOT.'/.local/sessions');
        if (!is_dir($sessions) && !@mkdir($sessions, 0700, true) && !is_dir($sessions)) {
            throw new RuntimeException('Cannot create the local session directory.');
        }
        session_save_path($sessions);
    }
    ini_set('session.use_strict_mode','1');
    $instance=implode('|',[config('DB_HOST','127.0.0.1'),config('DB_PORT','3306'),config('DB_NAME','facul_track')]);
    session_name('FACULTRACK'.substr(hash('sha256',$instance),0,12));session_set_cookie_params(['httponly'=>true,'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off','samesite'=>'Lax','path'=>'/']);session_start();
    if (isset($_SESSION['last']) && time()-$_SESSION['last']>1800) {$_SESSION=[];session_regenerate_id(true);}
    $_SESSION['last']=time(); $_SESSION['csrf']??=bin2hex(random_bytes(32));
    if (empty($_SESSION['uid'])) restoreRememberedLogin();
}

function rememberCookieName(): string {
    return session_name().'_remember';
}
function rememberBase64Url(string $value): string {
    return rtrim(strtr(base64_encode($value),'+/','-_'),'=');
}
function rememberBase64UrlDecode(string $value): string|false {
    $value=strtr($value,'-_','+/');
    $padding=(4-(strlen($value)%4))%4;
    return base64_decode($value.str_repeat('=', $padding),true);
}
function rememberCookieOptions(int $expires): array {
    return [
        'expires'=>$expires,
        'path'=>'/',
        'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off',
        'httponly'=>true,
        'samesite'=>'Lax',
    ];
}
function createRememberedLogin(int $userId): void {
    $selector=rememberBase64Url(random_bytes(18));
    $validator=rememberBase64Url(random_bytes(32));
    query('INSERT INTO remember_tokens(selector,user_id,validator_hash,expires_at) VALUES(?,?,?,DATE_ADD(NOW(), INTERVAL 30 DAY))',
        [$selector,$userId,hash('sha256',$validator)]);
    setcookie(rememberCookieName(),$selector.'.'.$validator,rememberCookieOptions(time()+30*86400));
}
function clearRememberedLoginCookie(): void {
    setcookie(rememberCookieName(),'',rememberCookieOptions(time()-3600));
}
function forgetRememberedLogin(int $userId): void {
    query('DELETE FROM remember_tokens WHERE user_id=?',[$userId]);
    clearRememberedLoginCookie();
}
function restoreRememberedLogin(): void {
    $cookie=$_COOKIE[rememberCookieName()]??null;
    if (!is_string($cookie) || substr_count($cookie,'.')!==1) return;
    [$selector,$validator]=explode('.',$cookie,2);
    if ($selector==='' || $validator==='') {clearRememberedLoginCookie();return;}
    try {
        $token=one('SELECT rt.*,u.active,u.registration_status FROM remember_tokens rt JOIN users u ON u.id=rt.user_id WHERE rt.selector=?',[$selector]);
    } catch (PDOException $error) {
        if ($error->getCode()==='42S02') return;
        throw $error;
    }
    if (!$token || strtotime((string)$token['expires_at'])<time() ||
        !hash_equals((string)$token['validator_hash'],hash('sha256',$validator)) ||
        !(bool)$token['active'] || $token['registration_status']!=='Approved') {
        if ($token) query('DELETE FROM remember_tokens WHERE selector=?',[$selector]);
        clearRememberedLoginCookie();
        return;
    }
    query('DELETE FROM remember_tokens WHERE selector=?',[$selector]);
    session_regenerate_id(true);
    $_SESSION=['uid'=>(int)$token['user_id'],'csrf'=>bin2hex(random_bytes(32)),'last'=>time()];
    createRememberedLogin((int)$token['user_id']);
}

