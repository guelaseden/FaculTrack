<?php
function notificationsView(array $u): void {
    $items=inboxQuery($u,'e.*, n.event_key IS NULL unread',
        'ORDER BY e.occurred DESC,e.assignment_id DESC,e.event_key DESC')->fetchAll();
    pageHead('Notifications');
    if(!$items){echo '<div class="panel inbox-empty">'.icon('bell').' No notifications yet.</div>';return;}
    $visible=paginate($items,$footer,'notifications');
    startForm('notifications',0,'class="inbox"');
    ?>
    <div class="inbox-tools">
        <label><input type="checkbox" data-inbox-all aria-label="Select all notifications on this page">
            <?=count($items)?> notification<?=count($items)===1?'':'s'?> · <?=array_sum(array_column($items,'unread'))?> unread</label>
        <div><button class="btn btn-primary" name="action" value="read"><?=icon('envelope-open')?> Mark as Read</button>
        <button class="btn btn-outline-danger" name="action" value="delete"><?=icon('trash')?> Delete</button></div>
    </div>
    <?php foreach($visible as $item){ ?>
    <div class="inbox-row">
        <input type="checkbox" name="events[]" value="<?=h($item['event_key'])?>" aria-label="Select <?=h($item['title'].' — '.$item['message'])?>">
        <article class="inbox-item<?=$item['unread']?' unread':''?>">
            <span class="inbox-symbol"><?=icon($item['unread']?'envelope':'envelope-open')?></span>
            <div><a href="<?=url('detail',['id'=>$item['assignment_id']])?>"><?=h($item['title'])?></a>
            <p><?=h($item['message'])?></p></div>
            <small><?=h($item['occurred']?date('M j, Y · g:i A',strtotime($item['occurred'])):'Due '.dateLabel($item['due']))?></small>
        </article>
    </div>
    <?php } echo '</form>'.$footer;
}
