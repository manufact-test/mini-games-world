<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/services/NotificationVisibleSelection.php';

function check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function entry(string $id, bool $read): array {
    return ['item'=>['id'=>$id,'read'=>$read],'raw'=>[],'invite'=>null];
}

// All entries are already delivered/visible and ordered newest to oldest.
$source = [];
for ($i = 0; $i < 80; $i++) $source[] = entry('read-' . $i, true);
$source[] = entry('unread-older', false);
$selection = NotificationVisibleSelection::select($source, 30);
check(count($selection) === 30, 'Visible sheet must remain limited to 30.');
check($selection[0]['item']['id'] === 'unread-older', 'Older unread is never displaced by 80 newer read events.');
check($selection[1]['item']['id'] === 'read-0', 'Read entries preserve descending order.');
check($selection[29]['item']['id'] === 'read-28', 'Read list fills remaining slots without duplication.');

$selection = NotificationVisibleSelection::select([entry('u2',false),entry('r1',true),entry('u1',false)],2);
check(array_column(array_column($selection, 'item'), 'id') === ['u2','u1'], 'Newer unread entries remain ordered.');
check(NotificationVisibleSelection::select($source,0) === [], 'Zero limit must return empty list.');
check(NotificationVisibleSelection::select([],30) === [], 'Empty source must return empty list.');
$readOnly = NotificationVisibleSelection::select([entry('r2',true),entry('r1',true)],2);
check(array_column(array_column($readOnly,'item'),'id') === ['r2','r1'], 'Already-read history order is unchanged.');

echo "MGW_VISIBLE_NOTIFICATION_SELECTION=PASS\n";
