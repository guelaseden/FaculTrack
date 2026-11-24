<?php
function headerHtml(string $title, bool $portal=false): void { ?><!DOCTYPE html><html lang="en"<?=$portal?' class="portal-page"':''?>><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>FaculTrack | <?=h($title)?></title><?php if($portal){?><link rel="preload" as="image" href="assets/images/vci-bg.png"><?php }?><link rel="icon" type="image/png" sizes="64x64" href="assets/images/favicon.png"><link rel="stylesheet" href="assets/vendor/bootstrap.min.css"><link rel="stylesheet" href="assets/vendor/bootstrap-icons.css"><link rel="stylesheet" href="assets/css/app.css?v=<?=filemtime(ROOT.'/public/assets/css/app.css')?>"><script defer src="assets/js/app.js?v=<?=filemtime(ROOT.'/public/assets/js/app.js')?>"></script></head><body><?php }
function brand(): void {echo '<div class="brand"><img class="brand-mark" src="assets/images/facultrack-logo.png" alt=""> FaculTrack</div>';}
function shellStart(array $u,string $page,string $title): void {
    $head=$u['role']==='Department Head';
    headerHtml($title);$cfg=settings();$activePage=$page==='profile'?'settings':$page;$showInstitution=strcasecmp(trim((string)$cfg['institution']),'FaculTrack')!==0;?><div class="app-shell<?=admin($u)?' admin-suite':($head?' admin-suite department-suite':' admin-suite faculty-suite')?>"><aside class="sidebar" id="sidebar"><?php brand();?>
    <nav aria-label="Main navigation"><?php foreach(navGroups($u) as $group=>$items){echo '<div class="nav-label">'.h($group).'</div>';foreach($items as $key=>[$label,$ic])echo '<a class="nav-link '.($key===$activePage?'active':'').'" href="'.url($key).'" title="'.h($label).'" '.($key===$activePage?'aria-current="page"':'').'>'.icon($ic).' '.h($label).'</a>';}?></nav>
    <div class="sidebar-footer"><?php startForm('logout');?><button class="nav-link" type="submit" title="Sign out"><?=icon('box-arrow-left')?> Sign out</button></form></div></aside>
    <div class="workspace"><header class="topbar"><div class="topbar-brand-group"><img class="topbar-brand-mark" src="assets/images/facultrack-logo.png" alt=""><button class="topbar-nav-toggle" type="button" data-menu-toggle aria-label="Minimize navigation" aria-controls="sidebar" aria-expanded="true"><?=icon('list')?></button><span class="topbar-brand">FaculTrack</span></div><?php if($showInstitution && admin($u)){?><span class="topbar-institution"><?=h($cfg['institution'])?></span><?php }?><div class="topbar-actions"><?php if(admin($u)){?><a class="topbar-notification" href="<?=url('logs')?>" aria-label="Activity logs"><?=icon('bell')?></a><?php }elseif($head){?><a class="topbar-notification" href="<?=url('review',['review_status'=>'Pending Review'])?>" aria-label="Submissions awaiting review"><?=icon('bell')?></a><?php }else{?><a class="topbar-notification" href="<?=url('requirements',['status'=>'Returned'])?>" aria-label="Returned requirements"><?=icon('bell')?></a><?php }?>
    <div class="account-menu">
        <button type="button" class="account-link" popovertarget="account-options" aria-label="<?=h($u['role'])?> account menu"><span class="avatar"><?=h(mb_substr($u['full_name'],0,1))?></span><span><?=h($u['role'])?></span><?=icon('chevron-down')?></button>
        <div popover id="account-options">
            <?php if($head){
                echo linkTo('settings','Department Head Profile',['tab'=>'profile']).linkTo('settings','Change Password',['tab'=>'password']).linkTo('deadlines','Department Deadlines');
                if(teaching($u)){
                    $routes=routeMap($u);
                    foreach(['requirements','upload','submissions','personal-deadlines','history'] as $key)echo linkTo($key,$routes[$key][0]);
                }
            }elseif(!manager($u)){
                echo linkTo('settings','Faculty Profile',['tab'=>'profile']).linkTo('settings','Change Password',['tab'=>'password']).linkTo('history','Submission History');
            }else{?>
            <?=linkTo('settings','Admin Profile',['tab'=>'profile'])?>
            <?=linkTo('settings','Change Password',['tab'=>'password'])?>
            <?php }?>
            <?php startForm('logout');?><button type="submit">Sign out</button></form>
        </div>
    </div>
    </div></header><main class="content"><?php }
function flashMessage(bool $login=false): void {
    if(!isset($_SESSION['flash']))return;
    $flash=$_SESSION['flash'];unset($_SESSION['flash']);[$kind,$msg,$icon]=array_pad($flash,3,'');
    if($login && $kind!=='success')return;
    $role=$kind==='danger'?'alert':'status';
    echo '<div class="app-toast alert alert-'.h($kind).'" role="'.$role.'" data-toast>';
    if($icon&&!$login)echo '<svg class="login-error-icon toast-icon" aria-hidden="true"><use href="assets/images/account-icons.svg#'.h($icon).'"></use></svg>';
    echo '<span>'.h($msg).'</span></div>';
}
function editModal(): void {
    echo '<dialog class="edit-modal" data-edit-modal aria-labelledby="edit-modal-title"><div class="edit-modal-header"><h2 id="edit-modal-title" data-edit-modal-title>Edit</h2><button type="button" class="edit-modal-close" data-close-edit aria-label="Close edit dialog">'.icon('x-lg').'</button></div><div class="edit-modal-body" data-edit-modal-body></div></dialog>';
}
function confirmationDialog(): void {
    echo '<dialog class="confirmation-dialog" data-confirm-dialog aria-labelledby="confirm-dialog-title"><div class="confirmation-dialog-header"><h2 id="confirm-dialog-title">Confirm action</h2><button type="button" class="confirmation-dialog-close" data-confirm-cancel aria-label="Close confirmation dialog">'.icon('x-lg').'</button></div><p data-confirm-message></p><div class="confirmation-dialog-actions"><button type="button" class="btn btn-outline-secondary" data-confirm-cancel>Cancel</button><button type="button" class="btn btn-primary" data-confirm-accept>Confirm</button></div></dialog>';
}
function accountIcon(string $name): string {
    return '<svg class="account-icon" aria-hidden="true" focusable="false"><use href="assets/images/account-icons.svg#'.h($name).'"></use></svg>';
}
function portalInput(string $name, string $label, string $iconName, string $type, string $autocomplete, bool $invalid=false): void {
    $id = 'account-'.$name; ?>
    <div class="portal-field<?=$type==='password'?' password-wrap':''?>">
        <label class="visually-hidden" for="<?=h($id)?>"><?=h($label)?></label>
        <?=accountIcon($iconName)?>
        <input class="form-control<?=($invalid?' is-invalid':'')?>" id="<?=h($id)?>" name="<?=h($name)?>" type="<?=h($type)?>" autocomplete="<?=h($autocomplete)?>" <?=($invalid?'aria-invalid="true" ':'')?>data-lpignore="true" data-1p-ignore="true" <?=in_array($autocomplete,['off','new-password','current-password'],true)?'':'autocapitalize="none" spellcheck="false"'?> placeholder="<?=h($label)?>">
        <?php if($type==='password'){ ?><button type="button" data-password-toggle aria-controls="<?=h($id)?>" aria-label="Show password"><?=accountIcon('eye-slash')?></button><?php } ?>
    </div>
    <?php
}
function loginView(array $loginErrors=[],string $authMessage='',string $authIcon=''): void {
    headerHtml('Sign in', true); ?>
    <main class="login-shell">
    <section class="login-art">
        <?php brand(); ?>
        <p class="eyebrow">Academic services</p>
        <h1><span>Faculty Document</span> <span>Submission &amp;</span> <span>Tracking System</span></h1>
        <p class="portal-description">FaculTrack brings faculty document submission and departmental review into one place, making it easier to keep track of deadlines and see which requirements still need attention.</p>
        <ul class="portal-features">
            <li><i class="feature-icon" aria-hidden="true"></i>Document submission</li>
            <li><i class="feature-icon feature-track" aria-hidden="true"></i>Review tracking</li>
            <li><i class="feature-icon feature-records" aria-hidden="true"></i>Faculty records</li>
        </ul>
    </section>
    <section class="login-pane">
        <?php portalCard($loginErrors, $authMessage, $authIcon); ?>
    </section></main></body></html><?php
}
function portalCard(array $loginErrors=[],string $authMessage='',string $authIcon=''): void { ?>
    <div class="login-form" data-mode="login">
    <h2 tabindex="-1">Welcome to</h2>
    <?php brand(); if($authMessage){echo '<div role="alert" class="login-auth-error"><svg class="login-error-icon" aria-hidden="true"><use href="assets/images/account-icons.svg#'.h($authIcon ?: 'circle-alert').'"></use></svg><span>'.h($authMessage).'</span></div>'; }else{flashMessage(true);} startForm('login',0,'autocomplete="off"');
    portalInput('employee_id','Employee ID','person-badge','text','off',isset($loginErrors['employee_id']));
    echo '<p class="field-error" data-field-error="employee_id"'.(!empty($loginErrors['employee_id'])?'':' hidden').' role="alert"><svg class="login-error-icon" aria-hidden="true"><use href="assets/images/account-icons.svg#circle-alert"></use></svg><span>'.h($loginErrors['employee_id']??'Employee ID is required.').'</span></p>';
    portalInput('password','Password','lock','password','new-password',isset($loginErrors['password']));
    echo '<p class="field-error" data-field-error="password"'.(!empty($loginErrors['password'])?'':' hidden').' role="alert"><svg class="login-error-icon" aria-hidden="true"><use href="assets/images/account-icons.svg#circle-alert"></use></svg><span>'.h($loginErrors['password']??'Password is required.').'</span></p>';
    ?><button class="btn btn-primary" type="submit">Sign In</button></form>
    <p class="login-guidance"><span>Need an account or forgot your password?</span> <span class="login-guidance-contact">Contact the System Administrator.</span></p>
    </div><?php
}
