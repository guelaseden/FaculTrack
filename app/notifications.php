<?php
// Receipt keys use the source record ID so a new version or review is independently unread.
function ensureNotificationReceipts(): void {
    static $ready=false;
    if($ready)return;
    try {
        query('SELECT 1 FROM notification_receipts LIMIT 0');
    } catch(PDOException $e) {
        if((int)($e->errorInfo[1]??0)!==1146)throw $e;
        db()->exec("CREATE TABLE IF NOT EXISTS notification_receipts (
            user_id INT NOT NULL, event_key VARCHAR(80) NOT NULL, hidden BOOLEAN NOT NULL DEFAULT 0,
            PRIMARY KEY(user_id,event_key), FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
    }
    $ready=true;
}

function notificationSource(array $u): array {
    $parts=[];$args=[];
    $assignment=" FROM requirement_assignments a JOIN requirements r ON r.id=a.requirement_id
        JOIN users f ON f.id=a.user_id ";
    $versions=$assignment." JOIN submissions s ON s.assignment_id=a.id AND s.retired=0
        JOIN submission_versions v ON v.submission_id=s.id ";
    if(teaching($u)){
        $active=" WHERE a.user_id=? AND a.active=1 AND r.active=1 AND (r.department_id IS NULL OR r.department_id=f.department_id)";
        $parts[]="SELECT CONCAT('assignment-',a.id) event_key, 'Requirement assigned' title,r.title message,
            NULL occurred,r.deadline due,a.id assignment_id ".$assignment.$active;
        $args[]=$u['id'];
        $parts[]="SELECT CONCAT('deadline-',a.id,'-',r.deadline,'-',IF(r.deadline<CURDATE(),'late','soon')),
            IF(r.deadline<CURDATE(),'Requirement overdue','Requirement due soon'),r.title,
            IF(r.deadline<CURDATE(),r.deadline+INTERVAL 1 DAY,r.deadline-INTERVAL 3 DAY),r.deadline,a.id
            ".$assignment.$active." AND r.deadline<=CURDATE()+INTERVAL 3 DAY
            AND NOT EXISTS (SELECT 1 FROM submissions s WHERE s.assignment_id=a.id AND s.retired=0)";
        $args[]=$u['id'];
        $parts[]="SELECT CONCAT('review-',rv.id),IF(rv.decision='Approved','Submission approved','Submission returned'),
            CONCAT(r.title,IF(rv.remarks='', '', CONCAT(' · ',rv.remarks))),rv.reviewed_at,NULL,a.id
            ".$versions." JOIN submission_reviews rv ON rv.version_id=v.id WHERE a.user_id=?";
        $args[]=$u['id'];
    }
    if(manager($u)){
        $scope=" WHERE a.user_id<>?";
        $args[]=$u['id'];
        if(!admin($u)){
            $scope.=" AND f.department_id=? AND (r.department_id IS NULL OR r.department_id=f.department_id)";
            $args[]=$u['department_id'];
        }
        $parts[]="SELECT CONCAT('submission-',v.id) event_key,IF(v.version_no=1,'Document submitted','Document resubmitted') title,
            CONCAT(f.full_name,' · ',r.title) message,v.submitted_at occurred,NULL due,a.id assignment_id ".$versions.$scope;
    }
    return [implode(' UNION ALL ',$parts),$args];
}

function inboxQuery(array $u,string $columns,string $suffix=''): PDOStatement {
    ensureNotificationReceipts();
    [$events,$args]=notificationSource($u);
    return query("SELECT $columns FROM ($events) e LEFT JOIN notification_receipts n
        ON n.user_id=? AND n.event_key=e.event_key WHERE COALESCE(n.hidden,0)=0 $suffix",[...$args,$u['id']]);
}

function notificationUnread(array $u): int {
    return (int)inboxQuery($u,'COUNT(*)','AND n.event_key IS NULL')->fetchColumn();
}

function updateNotifications(array $u): string {
    $action=choice('action',['read','delete']);
    $keys=$_POST['events']??[];
    valid(is_array($keys) && count($keys)>0 && count($keys)<=10,'Select at least one notification on this page.');
    foreach($keys as $key)valid(is_string($key) && strlen($key)<=80,'Invalid notification.');
    $marks=implode(',',array_fill(0,count($keys),'?'));
    [$source,$args]=notificationSource($u);
    query("INSERT INTO notification_receipts(user_id,event_key,hidden)
        SELECT ?,e.event_key,? FROM ($source) e WHERE e.event_key IN ($marks)
        ON DUPLICATE KEY UPDATE hidden=GREATEST(hidden,VALUES(hidden))",
        [$u['id'],(int)($action==='delete'),...$args,...$keys]);
    return $action==='delete'?'Selected notifications deleted.':'Selected notifications marked as read.';
}
