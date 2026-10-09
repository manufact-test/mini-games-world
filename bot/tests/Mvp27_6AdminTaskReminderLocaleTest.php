<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/notifications/NotificationCenterV2Policy.php';
require_once dirname(__DIR__) . '/services/NotificationService.php';

function mgwAssert(bool $condition, string $description): void {
    if (!$condition) throw new RuntimeException($description);
}

$base = [
    'id'=>'system-task-1',
    'user_id'=>'locale-test',
    'type'=>'system_message',
    'source_type'=>'system',
    'created_by'=>'system:admin-task-reminder',
    'title'=>'⏰ Срок задачи наступил',
    'message'=>'Наступил срок задачи «тестовая задача».',
    'tone'=>'info',
    'created_at'=>'2026-10-01T10:00:00+00:00',
    'delivered_at'=>'2026-10-01T10:00:00+00:00',
    'read_at'=>'2026-10-02T10:00:00+00:00',
];
$service = new NotificationService();
$_SERVER['HTTP_X_MGW_LOCALE'] = 'en';
$english = $service->userNotifications(['notifications'=>[$base]], 'locale-test');
mgwAssert(count($english)===1, 'Task event must remain in the feed');
mgwAssert($english[0]['title']==='⏰ Task deadline reached', 'EN title is system-owned');
mgwAssert($english[0]['message']==='Task “тестовая задача” is due.', 'EN template retains author title verbatim');
mgwAssert($english[0]['read']===true, 'Read authority must not change');
$_SERVER['HTTP_X_MGW_LOCALE'] = 'ru';
$russian = $service->userNotifications(['notifications'=>[$base]], 'locale-test');
mgwAssert($russian[0]['title']==='⏰ Срок задачи наступил', 'RU title parity');
mgwAssert($russian[0]['message']==='Наступил срок задачи «тестовая задача».', 'RU historical copy parity');

$_SERVER['HTTP_X_MGW_LOCALE'] = 'en';
$custom = [
  ...$base, 'id'=>'other-system',
  'created_by'=>'system:other',
  'title'=>'Авторская запись', 'message'=>'Текст автора',
];
$other = $service->userNotifications(['notifications'=>[$custom]], 'locale-test');
mgwAssert($other[0]['title']==='Авторская запись', 'Other system notifications are not auto-translated');
mgwAssert($other[0]['message']==='Текст автора', 'User/Admin notification message preserved');
$unknown = [...$base, 'id'=>'unknown-system', 'message'=>'Историческое сообщение другого формата'];
$legacy = $service->userNotifications(['notifications'=>[$unknown]], 'locale-test');
mgwAssert($legacy[0]['message']==='Историческое сообщение другого формата',
    'Unrecognized legacy message must stay unchanged');
echo "MVP-27.6 Admin task reminder typed RU/EN projection PASS\n";
