<?php
declare(strict_types=1);

// Called by integration.php after its private test server and fixtures are ready.
/**
 * @var string $base
 * @var string $password
 * @var string $project
 * @var array<string, string> $env
 * @var PDO $db
 * @var int $teacher
 * @var int $headId
 * @var int $peerId
 * @var int $manager
 * @var string $directory
 * @var string $root
 * @var array<string, int> $assignment
 * @var array<string, int> $peerAssignment
 * @var array<string, int> $future
 */
$guest = new HttpClient($base);
$guest->get('dashboard');
check(str_contains($guest->body,'type="password"'),'unauthenticated dashboard requires sign-in');
$guest->get('login');
$guest->post('login',['employee_id'=>'','password'=>'']);
check(str_contains($guest->body,'Employee ID is required.') && str_contains($guest->body,'Password is required.'),
    'sign-in shows required Employee ID and password messages');
$guest->login('EMP-TEACHER','incorrect');
check(str_contains($guest->body,'Incorrect Employee ID or password.'),'incorrect credentials rejected with a clear message');
$guest->login('EMP-INACTIVE',$password);
check(str_contains($guest->body,'type="password"'),'inactive account rejected');
for ($attempt=0;$attempt<4;$attempt++) $guest->login('EMP-TEACHER','incorrect');
$guest->login('EMP-TEACHER','incorrect');
check(str_contains($guest->body,'Too many attempts. Try again in 5 minutes.'),'sign-in lockout shows the remaining wait');
$signIn=$guest->get('login');
check(!str_contains($signIn,'name="remember"'),'sign-in omits Remember me');
check(str_contains($signIn,'name="employee_id"') && str_contains($signIn,'Employee ID'),'sign-in uses Employee ID');
check(str_contains($signIn,'Need an account or forgot your password?')
    && str_contains($signIn,'Contact the System Administrator.')
    && !str_contains($signIn,'href="?p=register"'),'sign-in displays non-link administrator guidance');
$closedRegistration=$guest->get('register');
check(!str_contains($closedRegistration,'Sign up') && str_contains($closedRegistration,'Welcome to'),'public registration redirects to sign in');
$remembered = new HttpClient($base);
check(!str_contains($remembered->login('EMP-TEACHER',$password,true),'type="password"'),'Remember me signs in');
check((int)value('SELECT COUNT(*) FROM remember_tokens WHERE user_id=?',[$teacher])===1,'Remember me stores a persistent login token');
$remembered->post('logout');
check((int)value('SELECT COUNT(*) FROM remember_tokens WHERE user_id=?',[$teacher])===0,'sign-out removes persistent login tokens');
$specialEmployeeId='EMP-017';
$specialPassword='  Héllo #<>[]{} & =  ';
sql("INSERT INTO users(full_name,faculty_id,email,password_hash,role,department_id,is_teaching,active,registration_status)
    VALUES(?,?,?,?,?,?,?,?,?)",
    ['Special Login',$specialEmployeeId,'special@example.test',password_hash($specialPassword,PASSWORD_DEFAULT),'Faculty',1,0,1,'Approved']);
$specialLogin=new HttpClient($base);
check(!str_contains($specialLogin->login($specialEmployeeId,$specialPassword),'type="password"'),'sign-in accepts Employee ID and password');
$faculty = actor('teacher');
$head = actor('head');
$admin = actor('admin');
$peer = actor('peer');
$facultyDashboard=$faculty->get('dashboard');
check(str_contains($facultyDashboard,'name="semester"') && str_contains($facultyDashboard,'name="academic_year"'),
    'faculty dashboard separates semester and academic year filters');
$faculty->get('my-subjects');
check(str_contains($faculty->body,'Computer Programming') && str_contains($faculty->body,'BSIT 1B')
    && str_contains($faculty->body,'name="semester"') && str_contains($faculty->body,'name="academic_year"')
    && str_contains($faculty->body,'subject_id='),
    'faculty subjects page shows assigned subjects with separate term filters and requirement links');
$faculty->get('settings',['tab'=>'profile']);
check(str_contains($faculty->body,'Faculty Profile') && str_contains($faculty->body,'name="employee_id"')
    && str_contains($faculty->body,'name="full_name"'),
    'faculty settings provide editable profile fields');
$faculty->get('settings',['tab'=>'password']);
check(str_contains($faculty->body,'Change Password') && str_contains($faculty->body,'Current password')
    && str_contains($faculty->body,'At least 8 characters'),
    'faculty settings provide the shared password-change flow');
$admin->get('dashboard');
$hadFullNamePost=array_key_exists('full_name',$_POST);$previousFullNamePost=$_POST['full_name']??null;
$_POST['full_name']='mARIA ñuñez-smITH';
check(fullNameInput()==='Maria Ñuñez-Smith','full names are normalized to title case with enye preserved');
if($hadFullNamePost)$_POST['full_name']=$previousFullNamePost;else unset($_POST['full_name']);
$hadEmailPost=array_key_exists('email',$_POST);$previousEmailPost=$_POST['email']??null;
$_POST['email']='maria@school';$emailFormatRejected=false;
try {emailInput();} catch(DomainException) {$emailFormatRejected=true;}
check($emailFormatRejected,'email addresses require an at sign and domain dot');
if($hadEmailPost)$_POST['email']=$previousEmailPost;else unset($_POST['email']);
preg_match('/<nav aria-label="Main navigation">(.*?)<\/nav>/s',$admin->body,$navMatch);
preg_match_all('/href="\?p=([^"&]+)"/',$navMatch[1]??'',$navRoutes);
check(($navRoutes[1]??[])===['dashboard','users','departments','academic-terms','manage','review','types','reports','settings'],
    'administrator navigation includes requirements and review without a system settings page');
check(str_contains($admin->body,'dashboard-term-filter') && str_contains($admin->body,'Submitted On Time')
    && !str_contains($admin->body,'>Apply</button>')
    && str_contains($admin->body,'Department Compliance') && str_contains($admin->body,'compliance-scale')
    && str_contains($admin->body,'Recent Submissions'),'administrator dashboard shows term-filtered KPIs, compliance, and recent submissions');
$admin->get('manage');
check(str_contains($admin->body,'Requirements') && str_contains($admin->body,'Add Requirement'),
    'administrator can reach requirement creation from the main navigation');
$admin->get('review');
check($admin->status===200 && str_contains($admin->body,'Submissions'),
    'administrator can reach independent submission review from the main navigation');
$admin->get('deadlines');
check($admin->status===403,'administrator deadline route is removed in favor of requirement management');
$admin->get('system-settings');
check($admin->status===403,'obsolete System Settings route is unavailable');
$admin->get('dashboard');
$requirementCount=(int)value('SELECT COUNT(*) FROM requirements');
$admin->post('manage',['title'=>'Invalid-term requirement','type_id'=>1,'department_id'=>1,
    'academic_year'=>'2099-2100','semester'=>'First Semester','deadline'=>date('Y-m-d'),
    'assignees[0]'=>$teacher,'active'=>1]);
check((int)value('SELECT COUNT(*) FROM requirements')===$requirementCount,
    'requirement creation rejects terms not present in the active academic-term catalog');
$admin->get('subjects');
check($admin->status===403,'administrator cannot access the removed Subjects page');
$admin->get('academic-terms');
check(str_contains($admin->body,'Term Name') && str_contains($admin->body,'Start Date')
    && str_contains($admin->body,'End Date') && str_contains($admin->body,'Add Term'),
    'academic terms page lists terms and offers creation');
$admin->get('academic-terms',['new'=>1]);
check(str_contains($admin->body,'<select class="form-select" name="academic_year"')
    && substr_count($admin->body,'type="date"')>=2,
    'academic term form uses an academic-year picker and native date pickers');
$termStart=date('Y-m-d',strtotime('+30 days'));$termEnd=date('Y-m-d',strtotime('+150 days'));
$termStartYear=(int)date('Y',strtotime($termStart));$termYear=$termStartYear.'-'.($termStartYear+1);
$admin->post('academic-terms',['academic_year'=>$termYear,'semester'=>'Second Semester','start_date'=>$termStart,'end_date'=>$termEnd,'active'=>1]);
$newTermId=(int)value('SELECT id FROM academic_terms WHERE academic_year=? AND semester=?',[$termYear,'Second Semester']);
check($newTermId>0 && value('SELECT start_date FROM academic_terms WHERE id=?',[$newTermId])===$termStart,
    'administrator creates an academic term with start and end dates');
$admin->post('academic-terms',['academic_year'=>str_replace('–','-','2026–2027'),'semester'=>'First Semester',
    'start_date'=>$termStart,'end_date'=>$termEnd,'active'=>1]);
check((int)value("SELECT COUNT(*) FROM academic_terms WHERE REPLACE(academic_year,'–','-')='2026-2027' AND semester='First Semester'")===1,
    'academic-term creation treats hyphen variants as the same term');
$admin->get('academic-terms');
check(str_contains($admin->body,'2nd Semester '.$termYear) && str_contains($admin->body,'Upcoming'),
    'academic terms list formats term names and upcoming status');
$updatedTermStart=date('Y-m-d',strtotime('+45 days'));$updatedTermEnd=date('Y-m-d',strtotime('+190 days'));
$admin->post('academic-terms',['academic_year'=>$termYear,'semester'=>'Second Semester','start_date'=>$updatedTermStart,'end_date'=>$updatedTermEnd,'active'=>1],['id'=>$newTermId]);
check(value('SELECT start_date FROM academic_terms WHERE id=?',[$newTermId])===$updatedTermStart,
    'administrator edits academic term dates');
$admin->post('academic-terms',['action'=>'archive'],['id'=>$newTermId]);
check((int)value('SELECT active FROM academic_terms WHERE id=?',[$newTermId])===0,
    'administrator archives academic terms without deleting linked records');
$admin->get('settings',['tab'=>'system']);
check(str_contains($admin->body,'Admin Profile') && str_contains($admin->body,'Change Password')
    && !str_contains($admin->body,'Institution Settings'),
    'personal settings only shows profile and password options');
$admin->get('types');
check(!str_contains($admin->body,'<option value="">All Status</option>')
    && !str_contains($admin->body,'<label class="form-label">Status'),
    'documents list omits the status filter');
$admin->get('reports');
check(str_contains($admin->body,'aria-label="Academic Term"')
    && str_contains($admin->body,'aria-label="Document Type"')
    && str_contains($admin->body,'aria-label="Submission Status"')
    && str_contains($admin->body,'Submitted Date')
    && str_contains($admin->body,'Export Excel') && str_contains($admin->body,'Export PDF')
    && str_contains($admin->body,'Faculty Submission Summary')
    && str_contains($admin->body,'Missing / Overdue'),
    'reports page shows filters, summary export layout, and PDF and Excel controls');
$defaultTermId=(int)value("SELECT id FROM academic_terms WHERE REPLACE(academic_year,'–','-')='2026-2027' AND semester='First Semester'");
$reportWorkbook=$admin->get('reports',['term_id'=>$defaultTermId,'export'=>'xlsx']);
$reportWorkbookPath=$directory.'/faculty-report.xlsx';
file_put_contents($reportWorkbookPath,$reportWorkbook);
$reportArchive=new ZipArchive();
check($reportArchive->open($reportWorkbookPath)===true,'administrator can export the styled faculty report workbook');
$reportSheet=$reportArchive->getFromName('xl/worksheets/sheet1.xml');
check($reportArchive->locateName('xl/styles.xml')!==false
    && str_contains($reportSheet,'FACULTY SUBMISSION REPORT')
    && str_contains($reportSheet,'Compliance Rate'),
    'Excel export includes the report layout and formatted faculty summary');
$reportArchive->close();
unlink($reportWorkbookPath);
$admin->get('reports',['term_id'=>$defaultTermId,'department'=>'Information Technology','status'=>'Not Submitted']);
preg_match('/<tbody>(.*?)<\/tbody>/s',$admin->body,$reportBody);
check(substr_count($reportBody[1]??'','<tr>')===3,
    'administrator report term filters match years stored with either hyphen character');
$admin->get('settings',['tab'=>'profile']);
check(str_contains($admin->body,'Administrator Profile') && str_contains($admin->body,'name="employee_id"')
    && !str_contains($admin->body,'name="employee_id" readonly'),'settings opens an editable administrator profile');
$admin->get('settings',['tab'=>'password']);
check(str_contains($admin->body,'Change Password') && str_contains($admin->body,'Current password')
    && str_contains($admin->body,'aria-label="Password requirements"')
    && str_contains($admin->body,'At least 8 characters')
    && str_contains($admin->body,'Include at least one number or special character'),
    'password requirements appear as a checklist below the new-password field');
check(str_contains($facultyDashboard,'My Subjects') && !str_contains($facultyDashboard,'type="password"'),'faculty login');
$faculty->get('users');
check($faculty->status === 403,'faculty cannot open user management');
$faculty->get('academic-terms');
check($faculty->status===403,'faculty cannot open academic term management');
$faculty->get('subjects');
check($faculty->status===403,'faculty cannot open subject management');
$faculty->get('requirements');
check(str_contains($faculty->body,'Next syllabus') && str_contains($faculty->body,'Submit Document')
    && str_contains($faculty->body,'name="semester"') && str_contains($faculty->body,'name="academic_year"')
    && str_contains($faculty->body,'name="status"') && str_contains($faculty->body,'Maximum file size: 10 MB'),
    'requirements page shows upload dialogs and separate searchable term and status filters');
check(str_contains($faculty->body,'Overdue'),'past-due missing requirement marked overdue');
check(str_contains($faculty->body,'&lt;spring&gt;') && !str_contains($faculty->body,'<spring>'),'requirement title safely escaped');
check(!str_contains($faculty->body,'Education annual record'),'unassigned requirement excluded');
$faculty->get('requirements',['subject_id'=>(string)value('SELECT id FROM subjects WHERE code=?',['IT 102'])]);
check(str_contains($faculty->body,'Next syllabus') && !str_contains($faculty->body,'Teaching plan &lt;spring&gt;'),
    'faculty subject filter narrows requirements to the selected subject');

$pdf = $directory.'/teaching.pdf';
$objects = [
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Count 1 /Kids [3 0 R] >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] >>'
];
$content = "%PDF-1.4\n";
$offsets = [];
foreach ($objects as $number => $object) {
    $offsets[] = strlen($content);
    $content .= ($number+1)." 0 obj\n$object\nendobj\n";
}
$start = strlen($content);
$content .= "xref\n0 4\n0000000000 65535 f \n";
foreach ($offsets as $offset) $content .= sprintf("%010d 00000 n \n",$offset);
$content .= "trailer\n<< /Size 4 /Root 1 0 R >>\nstartxref\n$start\n%%EOF\n";
file_put_contents($pdf,$content);
$invalid = $directory.'/teaching.txt';
file_put_contents($invalid,'Plain text test document');
upload($faculty,$assignment['teacher'],$invalid);
check(version($assignment['teacher'])===0,'unsupported extension rejected');
$invalidPdf = $directory.'/mislabeled.pdf';
copy($invalid,$invalidPdf);
upload($faculty,$assignment['teacher'],$invalidPdf);
check(version($assignment['teacher'])===0,'mislabeled file rejected');
$large = $directory.'/large.pdf';
file_put_contents($large,$content.str_repeat(' ',10*1048576));
upload($faculty,$assignment['teacher'],$large);
check(version($assignment['teacher'])===0,'10 MB upload size enforced');
$faculty->get('upload',['assignment_id'=>$assignment['teacher']]);
check(str_contains($faculty->body,'Maximum file size: 10 MB'),'upload form states the 10 MB maximum');
upload($faculty,$assignment['teacher'],$pdf);
$first = version($assignment['teacher']);
check($first>0,'valid PDF recorded');
$faculty->get('submissions');
check(str_contains($faculty->body,'Late') && str_contains($faculty->body,'For Review')
    && str_contains($faculty->body,'title="View submission"')
    && str_contains($faculty->body,'bi bi-eye')
    && !str_contains($faculty->body,'> View</button>'),
    'submission list shows approval status while the detail dialog preserves timeliness');
check($faculty->get('download',['id'=>$first])===$content,'owner download matches uploaded bytes');
$peer->get('download',['id'=>$first]);
check($peer->status===403,'other faculty cannot download document');
$key = value('SELECT storage_key FROM submission_versions WHERE id=?',[$first]);
check(is_file($directory.'/files/'.$key) && !file_exists($root.'/'.$key),'stored file is outside public directory');
$head->get('review');
check(str_contains($head->body,'Teaching plan') && !str_contains($head->body,'Education annual record'),'department review list scoped correctly');
$head->get('detail',['id'=>$peerAssignment['peer']]);
check($head->status===403,'department head cannot open another department assignment');
decision($head,$assignment['teacher'],'Needs Revision','');
check(review($assignment['teacher'])==='','return requires remarks');
decision($head,$assignment['teacher'],'Needs Revision','Please include learning objectives.');
check(review($assignment['teacher'])==='Needs Revision','department head can return with remarks');
$faculty->get('requirements');
check(str_contains($faculty->body,'Resubmit Document') && str_contains($faculty->body,'Please include learning objectives.'),
    'returned requirements offer resubmission with reviewer feedback');
upload($faculty,$assignment['teacher'],$pdf,'Revised teaching plan');
check(version($assignment['teacher'])>$first && review($assignment['teacher'])==='','replacement creates a pending version');
decision($head,$assignment['teacher'],'Approved','Objectives included.');
check(review($assignment['teacher'])==='Approved','department head can approve revised document');
$faculty->get('detail',['id'=>$assignment['teacher']]);
check(str_contains($faculty->body,'Please include learning objectives.') && str_contains($faculty->body,'Objectives included.'),'review history retained');
upload($faculty,$assignment['teacher'],$pdf,'Unneeded duplicate');
check((int)value('SELECT COUNT(*) FROM submission_versions')===2,'approved file cannot be replaced');
upload($head,$assignment['head'],$pdf);
check(version($assignment['head'])>0,'teaching department head may submit');
decision($head,$assignment['head'],'Approved','');
check(review($assignment['head'])==='','department head cannot approve own document');
decision($admin,$assignment['head'],'Approved','Reviewed independently.');
check(review($assignment['head'])==='Approved','independent administrator can review head submission');

function userFields(int $id): array {
    $row=sql('SELECT id,full_name,faculty_id employee_id,email,role,department_id,active,is_teaching FROM users WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);
    foreach (['active','is_teaching'] as $field) if (!$row[$field]) unset($row[$field]);
    return $row;
}
$admin->get('users');
$checkRoles=$admin->body;
check(str_contains($checkRoles,'<option value="Faculty">Faculty</option>')
    && str_contains($checkRoles,'<option value="Department Head">Department Head</option>')
    && str_contains($checkRoles,'<option value="Administrator">Administrator</option>')
    && str_contains($checkRoles,'<option value="">All roles</option>')
    && str_contains($checkRoles,'<option value="">All departments</option>')
    && str_contains($checkRoles,'<option value="">All statuses</option>')
    && !str_contains($checkRoles,'<label class="form-label">Role'),
    'user filters show All prompts inside dropdowns without outside labels');
check(str_contains($checkRoles,'<td>—</td>')
    && (int)value('SELECT department_id FROM users WHERE id=?',[$manager])===1,
    'users table shows an em dash for Administrator even when a legacy department remains stored');
$admin->get('users',['new'=>1]);
check(str_contains($admin->body,'<option value="Faculty">Faculty</option>')
    && str_contains($admin->body,'<option value="Department Head">Department Head</option>')
    && !str_contains($admin->body,'<option value="Administrator">Administrator</option>'),
    'user creation hides Administrator role when one already exists');
$admin->post('users',['full_name'=>'SECOND ADMIN','employee_id'=>'EMP-SECOND-ADMIN','email'=>'second-admin@example.test',
    'password'=>$password,'role'=>'Administrator','department_id'=>1,'active'=>1]);
check((int)value("SELECT COUNT(*) FROM users WHERE role='Administrator'")===1
    && !(bool)value("SELECT id FROM users WHERE email='second-admin@example.test'"),
    'account save rejects a second Administrator role');
$admin->get('users',['id'=>$manager]);
check(str_contains($admin->body,'<option value="Administrator" selected>Administrator</option>')
    && str_contains($admin->body,'<div data-department-field hidden>')
    && preg_match('/<select class="form-select" name="department_id"[^>]*disabled/',$admin->body)===1
    && (int)value('SELECT department_id FROM users WHERE id=?',[$manager])===1,
    'Administrator edit hides and disables department selection');
$admin->post('users',['full_name'=>'Test Admin','employee_id'=>'EMP-ADMIN','email'=>'admin@example.test',
    'role'=>'Administrator','active'=>1],['id'=>$manager]);
check(value('SELECT department_id FROM users WHERE id=?',[$manager])===null
    && (int)value('SELECT is_teaching FROM users WHERE id=?',[$manager])===0,
    'saving an Administrator removes department and teaching access');
$admin->post('users',['full_name'=>'tEST cOORDINATOR','employee_id'=>'EMP-COORD','email'=>'coordinator@example.test','password'=>$password,'role'=>'Faculty','department_id'=>1,'active'=>1]);
$coordinator=(int)value("SELECT id FROM users WHERE email='coordinator@example.test'");
check($coordinator>0 && value('SELECT faculty_id FROM users WHERE id=?',[$coordinator])==='EMP-COORD'
    && value('SELECT full_name FROM users WHERE id=?',[$coordinator])==='Test Coordinator'
    && value('SELECT role FROM users WHERE id=?',[$coordinator])==='Faculty',
    'administrator creates account with a title-cased full name and Employee ID');
$admin->get('users');
check(str_contains($admin->body,'aria-label="Edit Test Coordinator"')
    && str_contains($admin->body,'aria-label="Reset password for Test Coordinator"')
    && str_contains($admin->body,'data-reset-user-name')
    && str_contains($admin->body,'data-reset-employee-id')
    && str_contains($admin->body,'data-password-requirements')
    && str_contains($admin->body,'data-password-toggle')
    && str_contains($admin->body,'Include at least one number or special character')
    && str_contains($admin->body,'<td>IT</td>')
    && str_contains($admin->body,'bi-key') && str_contains($admin->body,'user-action-switch-on'),
    'user table shows department codes and edit, reset-password, and status-toggle actions');
$resetPassword=bin2hex(random_bytes(12));
$admin->post('users',['action'=>'reset_password','password'=>$resetPassword,'id'=>$coordinator]);
check(password_verify($resetPassword,value('SELECT password_hash FROM users WHERE id=?',[$coordinator])),
    'administrator resets a user password through the reset action');
$admin->get('users');
check(str_contains($admin->body,'aria-label="Deactivate Test Coordinator"')
    && str_contains($admin->body,'user-action-switch-on'),'active user toggle offers deactivation');
$admin->post('users',['action'=>'toggle_status'],['id'=>$coordinator]);
check((int)value('SELECT active FROM users WHERE id=?',[$coordinator])===0,'user toggle deactivates active account');
$admin->get('users');
check(str_contains($admin->body,'aria-label="Activate Test Coordinator"') && str_contains($admin->body,'user-action-switch-off'),
    'inactive user toggle offers activation');
$admin->post('users',['action'=>'toggle_status'],['id'=>$coordinator]);
check((int)value('SELECT active FROM users WHERE id=?',[$coordinator])===1,'user toggle reactivates inactive account');
$fields=userFields($headId);
unset($fields['is_teaching']);
$admin->post('users',$fields,['id'=>$headId]);
check((int)value('SELECT is_teaching FROM users WHERE id=?',[$headId])===0,'administrator disables teaching');
$head->get('upload');
check($head->status===403,'disabled head loses personal upload access in existing session');
$headVersions=(int)value('SELECT COUNT(*) FROM submission_versions');
upload($head,$assignment['head'],$pdf);
check((int)value('SELECT COUNT(*) FROM submission_versions')===$headVersions && review($assignment['head'])==='Approved','disabled teaching preserves versions and reviews');
$coordinatorClient=actor('coordinator');
$coordinatorClient->get('upload');
check($coordinatorClient->status===403,'new administrator has no default upload privilege');
$admin->post('users',userFields($coordinator)+['is_teaching'=>1],['id'=>$coordinator]);
check((int)value('SELECT is_teaching FROM users WHERE id=?',[$coordinator])===1,'administrator enables another administrator to teach');
$coordinatorAssignment=addRequirement('Coordinator syllabus',2,1,$manager,['coordinator'=>$coordinator]);
upload($coordinatorClient,$coordinatorAssignment['coordinator'],$pdf,'Coordinator syllabus copy');
check(version($coordinatorAssignment['coordinator'])>0,'teaching administrator may submit');
$admin->post('users',userFields($manager)+['is_teaching'=>1],['id'=>$manager]);
check((int)value('SELECT is_teaching FROM users WHERE id=?',[$manager])===0,'administrator cannot change own teaching privilege');
$admin->get('logs');
check(str_contains($admin->body,'coordinator@example.test'),'administrator changes audited');
$admin->post('users',['action'=>'toggle_status'],['id'=>$coordinator]);
$coordinatorClient->get('dashboard');
check(str_contains($coordinatorClient->body,'type="password"'),'deactivation ends authenticated access');

$admin->get('departments');
check(str_contains($admin->body,'Total Faculty') && str_contains($admin->body,'Department Head')
    && !str_contains($admin->body,'<th scope="col">Status</th>')
    && !str_contains($admin->body,'name="status"')
    && str_contains($admin->body,'aria-label="Delete Information Technology"')
    && str_contains($admin->body,'bi-pencil') && str_contains($admin->body,'bi-trash3'),
    'department list shows requested columns and icon actions without a status column');
$admin->post('departments',['name'=>'Applied! Teaching','code'=>'ATX','description'=>'','active'=>'1']);
check((int)value('SELECT COUNT(*) FROM departments WHERE code=?',['ATX'])===0,'department name rejects non-alphanumeric characters');
$admin->post('departments',['name'=>'','code'=>'AT0','description'=>'','active'=>'1']);
check(str_contains($admin->body,'Department name is required.'),'empty department name shows a specific required-field error');
$admin->post('departments',['name'=>'Applied Teaching','code'=>'','description'=>'','active'=>'1']);
check(str_contains($admin->body,'Department code is required.'),'empty department code shows a specific required-field error');
$admin->post('departments',['name'=>'Applied Teaching','code'=>'AT#X','description'=>'','active'=>'1']);
check((int)value('SELECT COUNT(*) FROM departments WHERE code=?',['AT#X'])===0,'department code rejects characters other than letters, numbers, and hyphens');
$catalog=['name'=>'Applied Teaching','code'=>'AT','description'=>'Test department','active'=>'1'];
$admin->post('departments',$catalog);
$catalogId=(int)value('SELECT id FROM departments WHERE code=?',['AT']);
check($catalogId>0,'department created');
$catalog['id']=$catalogId;
$catalog['description']='Updated department';
$admin->post('departments',$catalog,['id'=>$catalogId]);
check((string)value('SELECT description FROM departments WHERE id=? AND active=1',[$catalogId])==='Updated department','department edit action saves changes');
$admin->post('departments',['action'=>'archive'],['id'=>$catalogId]);
check((int)value('SELECT active FROM departments WHERE id=?',[$catalogId])===0,'department delete action safely archives the department');
$admin->get('types');
check(str_contains($admin->body,'Document Name') && str_contains($admin->body,'Instructions') &&
    str_contains($admin->body,'Allowed Formats') && !str_contains($admin->body,'<th>Status</th>') &&
    !str_contains($admin->body,'Document workflows'),'documents page shows the streamlined document catalog');
$admin->post('types',['name'=>'Practicum plan','description'=>'Practicum materials','active'=>1,
    'formats[0]'=>'pdf','formats[1]'=>'docx','formats[2]'=>'xlsx',
    'template'=>new CURLFile($pdf,'application/pdf','Practicum_Template.pdf')]);
$type=(int)value('SELECT id FROM document_types WHERE name=?',['Practicum plan']);
check($type>0 && value('SELECT formats FROM document_types WHERE id=?',[$type])==='pdf,docx,xlsx','document type stores its allowed formats');
$templateKey=(string)value('SELECT template_key FROM document_types WHERE id=?',[$type]);
check(strlen($templateKey)===64 && is_file($directory.'/files/'.$templateKey),'document template is stored privately');
$admin->get('types');
check(str_contains($admin->body,'Practicum_Template.pdf') && str_contains($admin->body,'document-type-action'),
    'documents list offers a template download and icon actions');
check($admin->get('template-download',['id'=>$type])===file_get_contents($pdf) &&
    $faculty->get('template-download',['id'=>$type])===file_get_contents($pdf),'authenticated users can download the stored template');
$admin->get('types',['id'=>$type]);
$admin->post('types',['name'=>'Practicum plan','description'=>'Practicum materials','active'=>1,
    'formats[0]'=>'pdf','formats[1]'=>'docx','formats[2]'=>'xlsx','remove_template'=>1],['id'=>$type]);
$removedTemplateKey=value('SELECT template_key FROM document_types WHERE id=?',[$type]);
$removedTemplateExists=is_file($directory.'/files/'.$templateKey);
check($removedTemplateKey===null && !$removedTemplateExists,'removing a template clears its record and deletes the private file after commit');
$head->get('manage');
$managed=['title'=>'Department practicum','type_id'=>$type,'department_id'=>1,'academic_year'=>'2026–2027','semester'=>'First Semester',
    'deadline'=>date('Y-m-d',strtotime('+14 days')),'description'=>'Practice plan','active'=>1,'assignees[0]'=>$teacher];
$head->post('manage',$managed);
$managedId=(int)value('SELECT id FROM requirements WHERE title=?',[$managed['title']]);
check($managedId>0 && (int)value('SELECT user_id FROM requirement_assignments WHERE requirement_id=?',[$managedId])===$teacher,'department requirement created and assigned');
$head->get('manage',['new'=>1]);
check(str_contains($head->body,'Deadline (Manila time, MM-DD-YYYY)')
    && str_contains($head->body,'name="deadline" type="text"')
    && str_contains($head->body,'placeholder="MM-DD-YYYY"'),
    'requirement deadline entry specifies the MM-DD-YYYY format');
$head->get('manage',['id'=>$managedId]);
check(str_contains($head->body,'value="'.date('m-d-Y',strtotime($managed['deadline'])).'"'),
    'editing a requirement shows its deadline in MM-DD-YYYY format');
$head->get('manage');
check(str_contains($head->body,date('m-d-Y',strtotime($managed['deadline'])))
    && str_contains($head->body,'title="Edit requirement"')
    && str_contains($head->body,'bi bi-pencil'),
    'requirement listings show deadlines in MM-DD-YYYY format');
$subjectId=(int)value('SELECT id FROM subjects WHERE code=? AND section=?',['IT 102','BSIT 1B']);
$head->get('manage',['new'=>1,'subject_id'=>$subjectId]);
check(str_contains($head->body,'data-requirement-subject')
    && preg_match('/name="assignees\[\]"[^>]*value="'.$teacher.'"[^>]*checked/',$head->body)===1
    && str_contains($head->body,'data-subject-assignee-warning'),
    'new subject-linked requirement preselects its assigned faculty and renders the assignment warning');
$subjectRequirementId=(int)value('SELECT requirement_id FROM requirement_assignments WHERE id=?',[$future['teacher']]);
sql('UPDATE requirement_assignments SET active=0 WHERE id=?',[$future['teacher']]);
$head->get('manage',['id'=>$subjectRequirementId]);
check(str_contains($head->body,'The faculty member assigned to this subject is not selected.')
    && preg_match('/name="assignees\[\]"[^>]*value="'.$teacher.'"[^>]*checked/',$head->body)!==1,
    'editing a subject-linked requirement warns when its subject faculty is excluded');
sql('UPDATE requirement_assignments SET active=1 WHERE id=?',[$future['teacher']]);
$managed['id']=$managedId;
$updatedDeadline=date('Y-m-d',strtotime('+21 days'));
$managed['deadline']=date('m-d-Y',strtotime('+21 days'));
$head->post('manage',$managed,['id'=>$managedId]);
check(value('SELECT deadline FROM requirements WHERE id=?',[$managedId])===$updatedDeadline &&
    (int)value("SELECT COUNT(*) FROM activity_logs WHERE action='Deadline changed'")>0,'deadline edited and change audited');
$managed['assignees[0]']=$peerId;
$head->post('manage',$managed,['id'=>$managedId]);
check((int)value('SELECT user_id FROM requirement_assignments WHERE requirement_id=? AND active=1',[$managedId])===$teacher,'invalid department assignment rolls back safely');
$managed['assignees[0]']=$teacher;
unset($managed['active']);
$head->post('manage',$managed,['id'=>$managedId]);
check((int)value('SELECT active FROM requirements WHERE id=?',[$managedId])===0,'department requirement archived');
$faculty->get('profile');
$faculty->post('profile',['full_name'=>"Test O'Neil <spring>",'email'=>'teacher@example.test','current_password'=>$password,'password'=>'']);
check(value('SELECT full_name FROM users WHERE id=?',[$teacher])==="Test O'Neil <spring>",'apostrophes and angle brackets persist as data');
$faculty->get('profile');
check(str_contains($faculty->body,'&lt;spring&gt;') && !str_contains($faculty->body,'<spring>'),'saved profile displays escaped text');
$docx=$directory.'/next.docx';
$zip=new ZipArchive();
$zip->open($docx,ZipArchive::CREATE);
$zip->addFromString('[Content_Types].xml','<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
$zip->addFromString('_rels/.rels','<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
$zip->addFromString('word/document.xml','<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Test syllabus</w:t></w:r></w:p></w:body></w:document>');
$zip->close();
upload($faculty,$future['teacher'],$docx,'Next syllabus DOCX');
$docVersion=version($future['teacher']);
check($docVersion>0 && $faculty->get('download',['id'=>$docVersion])===file_get_contents($docx),'valid DOCX uploaded and downloaded');
$admin->get('detail',['id'=>$future['teacher']]);
$admin->post('detail',['action'=>'retire','version_id'=>$docVersion],['id'=>$future['teacher']]);
check((int)value('SELECT retired FROM submissions WHERE assignment_id=?',[$future['teacher']])===1 &&
    version($future['teacher'])===$docVersion,'administrator retires incorrect submission and preserves history');
$faculty->get('requirements');
check(str_contains($faculty->body,'Pending') && str_contains($faculty->body,'Submit Document'),
    'retired document returns assignment to the pending upload state');
$reports=['report'=>'Late Submissions','department'=>'Information Technology','academic_year'=>'2026–2027','semester'=>'First Semester','review_status'=>'Approved'];
$admin->get('reports',$reports);
check(str_contains($admin->body,'data-print') && str_contains($admin->body,'&lt;spring&gt;'),'filtered printable HTML report');
$csv=$admin->get('reports',$reports+['csv'=>1]);
$lines=array_map('str_getcsv',preg_split('/\r?\n/',trim($csv)));
check(count($lines)===2 && $lines[1][1]==='Information Technology' && $lines[1][7]==='Late' && $lines[1][8]==='Approved','CSV applies report filters and keeps one row per matching assignment');
$head->get('reports',['csv'=>1,'department'=>'Education']);
check(count(preg_split('/\r?\n/',trim($head->body)))===1,'department report excludes records outside department');
$admin->get('reports',['csv'=>1,'report'=>'Department Compliance','department'=>'Information Technology']);
$counts=str_getcsv(preg_split('/\r?\n/',trim($admin->body))[1]);
check($counts===['Information Technology','2','1','1','50%'],'compliance calculated from assigned requirements');
sql('UPDATE requirements SET active=1 WHERE id=?',[$managedId]);
sql("UPDATE document_types SET formats='xlsx' WHERE id=?",[$type]);
$managedAssignment=(int)value('SELECT id FROM requirement_assignments WHERE requirement_id=? AND user_id=?',[$managedId,$teacher]);
upload($faculty,$managedAssignment,$pdf,'PDF disallowed for this type');
check(version($managedAssignment)===0,'submission rejects formats excluded by the assigned document type');
$invalidXlsx=$directory.'/invalid.xlsx';
file_put_contents($invalidXlsx,'Not an XLSX workbook');
upload($faculty,$managedAssignment,$invalidXlsx,'Invalid workbook');
check(version($managedAssignment)===0,'submission rejects invalid XLSX contents');
$xlsx=$directory.'/workbook.xlsx';
$zip=new ZipArchive();
$zip->open($xlsx,ZipArchive::CREATE|ZipArchive::OVERWRITE);
$zip->addFromString('[Content_Types].xml','<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>');
$zip->addFromString('xl/workbook.xml','<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheets/></workbook>');
$zip->close();
upload($faculty,$managedAssignment,$xlsx,'Spreadsheet submission');
$xlsxVersion=version($managedAssignment);
check($xlsxVersion>0 && value('SELECT format FROM submission_versions WHERE id=?',[$xlsxVersion])==='xlsx' &&
    $faculty->get('download',['id'=>$xlsxVersion])===file_get_contents($xlsx),'valid XLSX submitted and downloaded');

// Optional responsive checks use the same generated fixture and temporary password.
if (getenv('FACULTRACK_BROWSER_TEST')) {
    foreach (['faculty'=>'teacher','head'=>'head','admin'=>'admin'] as $role=>$account) {
        $browser = proc_open(['node',__DIR__.'/browser.mjs'],[0=>STDIN,1=>STDOUT,2=>STDERR],$unused,$project,
            array_merge($env,['FACULTRACK_TEST_ROLE'=>$role,'FACULTRACK_TEST_URL'=>$base,'FACULTRACK_TEST_EMPLOYEE_ID'=>'EMP-'.strtoupper($account),'FACULTRACK_TEST_PASSWORD'=>$password]));
        check(proc_close($browser)===0,"desktop, tablet and mobile browser checks for $role");
    }
}
$admin->get('settings',['tab'=>'profile']);
$adminId=(int)value('SELECT id FROM users WHERE faculty_id=?',['EMP-ADMIN']);
$admin->post('profile',['employee_id'=>'EMP-ADMIN-EDITED',
    'full_name'=>value('SELECT full_name FROM users WHERE id=?',[$adminId]),
    'email'=>value('SELECT email FROM users WHERE id=?',[$adminId]),'settings_tab'=>'profile']);
check(value('SELECT faculty_id FROM users WHERE id=?',[$adminId])==='EMP-ADMIN-EDITED',
    'administrator can update their Employee ID in profile settings');
$newPassword=bin2hex(random_bytes(12));
$faculty->post('profile',['full_name'=>'Test Teacher','email'=>'teacher@example.test','password'=>$newPassword,'current_password'=>$password]);
check(password_verify($newPassword,value('SELECT password_hash FROM users WHERE id=?',[$teacher])),'change password stores new hash');
$faculty->post('logout');
$faculty->get('dashboard');
check(str_contains($faculty->body,'type="password"'),'logout clears active session');

