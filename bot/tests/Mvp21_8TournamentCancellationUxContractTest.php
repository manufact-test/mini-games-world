<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$files=[
 'migration'=>file_get_contents($root.'/bot/database/migrations/20260922_0059_add_tournament_cancellation_emergency.php'),
 'service'=>file_get_contents($root.'/bot/tournaments/TournamentCancellationService.php'),
 'registration'=>file_get_contents($root.'/bot/tournaments/TournamentRegistrationService.php'),
 'settlement'=>file_get_contents($root.'/bot/tournaments/TournamentSettlementService.php'),
 'progression'=>file_get_contents($root.'/bot/tournaments/TournamentRoundProgressionService.php'),
 'bootstrap'=>file_get_contents($root.'/bot/core/bootstrap.php'),
 'admin_endpoint'=>file_get_contents($root.'/bot/admin-tournaments.php'),
 'admin_ui'=>file_get_contents($root.'/app/admin.php'),
 'admin_js'=>file_get_contents($root.'/app/assets/js/admin-tournaments.js'),
 'admin_css'=>file_get_contents($root.'/app/assets/css/admin-shell.css'),
 'status'=>file_get_contents($root.'/bot/tournament-status.php'),
 'player_ui'=>file_get_contents($root.'/app/assets/js/screens/tournaments-screen-v1.js'),
 'manifest'=>file_get_contents($root.'/app/runtime/client/version-manifest.php'),
];
foreach($files as $name=>$source){
 if(!is_string($source)) throw new RuntimeException('Missing MVP-21.8 source: '.$name);
}

$assertions=0;
$assert=static function(bool $value,string $message)use(&$assertions):void{
 $assertions++;
 if(!$value) throw new RuntimeException($message);
};

$assert(str_contains($files['migration'],'mgw_tournament_cancellation_events'),'Cancellation audit table must be durable.');
$assert(str_contains($files['migration'],'cancellation_kind'),'Tournament must store cancellation kind.');
$assert(str_contains($files['migration'],'cancelled_by_ref'),'Tournament must store cancellation actor.');
$assert(str_contains($files['migration'],'annulled_at_utc'),'Match evidence must support durable annulment.');
$assert(str_contains($files['migration'],'annulled_technical_count'),'Cancellation audit must count annulled technical evidence.');

$assert(str_contains($files['registration'],"STATE_CANCELLED = 'cancelled'"),'Normal cancellation state must be canonical.');
$assert(str_contains($files['registration'],"STATE_EMERGENCY_STOPPED = 'emergency_stopped'"),'Emergency state must be canonical.');
$assert(str_contains($files['registration'],"REGISTRATION_CANCELLED = 'cancelled'"),'Cancelled registration state must be canonical.');

$assert(str_contains($files['service'],'CONFIRMATION_MODE = \'double_confirm\''),'Backend must own double-confirm semantics.');
$assert(str_contains($files['service'],'Для аварийной остановки обязательно укажите причину.'),'Emergency reason must be mandatory.');
$assert(str_contains($files['service'],'releaseReservation'),'Active entry refund must reuse canonical reservation release.');
$assert(str_contains($files['service'],'tournament_cancellation_refund'),'Consumed-but-unrewarded entry must have a canonical ledger refund path.');
$assert(str_contains($files['service'],"category=:category"),'Cancellation must detect reward ledger writes before unsafe clawback.');
$assert(str_contains($files['service'],'Automatic cancellation refuses unsafe prize clawback.'),'Cancellation must fail closed once prize payout exists.');
$assert(str_contains($files['service'],'annulled_match_count'),'Cancellation must audit bracket annulment.');
$assert(str_contains($files['service'],'annulled_attempt_count'),'Cancellation must audit match-attempt annulment.');
$assert(str_contains($files['service'],'annulled_technical_count'),'Cancellation must audit technical-outcome annulment.');
$assert(str_contains($files['service'],'technical_cancel_required_count'),'21.7 technical escalation must be visible to 21.8.');
$assert(str_contains($files['service'],"$technicalRequired === 0"),'Technical escalation must disable normal cancellation.');
$assert(str_contains($files['service'],'emergency_stop_available'),'Technical escalation must retain emergency stop.');
$assert(!str_contains(strtolower($files['service']),'reschedule'),'Reschedule must remain outside MVP-21.8.');
$assert(!str_contains(strtolower($files['service']),'delay tournament'),'Delay must remain outside MVP-21.8.');

$assert(str_contains($files['settlement'],'STATE_CANCELLED'),'Settlement must refuse cancelled tournament state.');
$assert(str_contains($files['settlement'],'STATE_EMERGENCY_STOPPED'),'Settlement must refuse emergency-stopped tournament state.');
$assert(str_contains($files['settlement'],'FOR UPDATE') || str_contains($files['settlement'],'forUpdate'),'Settlement/cancellation race must serialize on DB locks.');
$assert(str_contains($files['progression'],"'reason'=>'tournament_cancelled'"),'Late runtime result must not revive cancelled progression.');
$assert(str_contains($files['progression'],'STATE_EMERGENCY_STOPPED'),'Progression must ignore emergency-stopped tournament.');

$assert(str_contains($files['bootstrap'],'TournamentCancellationService.php'),'Runtime bootstrap must load the cancellation owner.');
$assert(str_contains($files['bootstrap'],'GameNoContestSettlementService.php'),'Runtime cancellation must reuse no-contest game owner.');

$assert(str_contains($files['admin_endpoint'],"'cancel_tournament'"),'Admin endpoint must expose normal cancellation.');
$assert(str_contains($files['admin_endpoint'],"'emergency_stop'"),'Admin endpoint must expose emergency stop.');
$assert(str_contains($files['admin_endpoint'],'mgw_apply_tournament_cancellation_runtime'),'Admin cancellation must synchronize runtime state.');
$assert(str_contains($files['admin_endpoint'],'tournament_result_annulled'),'Runtime games must be explicitly marked annulled.');
$assert(str_contains($files['admin_endpoint'],'hidden_tournament_notifications'),'Pending tournament reminders must be hidden after cancellation.');

$assert(str_contains($files['admin_ui'],'data-tournament-cancel-reason'),'Admin must expose a reason field.');
$assert(str_contains($files['admin_ui'],'data-tournament-cancel'),'Admin must expose normal cancel action.');
$assert(str_contains($files['admin_ui'],'data-tournament-emergency'),'Admin must expose emergency action.');
$assert(str_contains($files['admin_ui'],'Перенос даты здесь не выполняется.'),'Admin copy must state the no-reschedule boundary.');
$assert(str_contains($files['admin_ui'],'class="mgw-admin__tournament-reason"')
        && str_contains($files['admin_ui'],'placeholder="Опишите причину"')
        && str_contains($files['admin_ui'],'class="mgw-admin__field-help"'),
    'Cancellation reason must render as an intentional dark Admin field with helper copy outside the control.');
$assert(str_contains($files['admin_css'],'.mgw-admin__tournament-reason')
        && str_contains($files['admin_css'],'min-height:88px!important')
        && str_contains($files['admin_css'],'.mgw-admin__field-help'),
    'Cancellation reason styling must override the generic large textarea and remain readable in the dark Admin shell.');
$assert(str_contains($files['admin_ui'],'data-tournament-start-date type="date"')
        && str_contains($files['admin_ui'],'data-tournament-start-time type="time"')
        && !str_contains($files['admin_ui'],'type="datetime-local"'),
    'Tournament start control must split date and time so Telegram WebView does not render ambiguous extra datetime placeholder characters.');
$assert(str_contains($files['admin_js'],"const scheduleDate = card.querySelector('[data-tournament-start-date]')")
        && str_contains($files['admin_js'],"const scheduleTime = card.querySelector('[data-tournament-start-time]')")
        && str_contains($files['admin_js'],'localDate')
        && str_contains($files['admin_js'],'localTime')
        && str_contains($files['admin_js'],'toISOString()'),
    'Admin schedule JS must combine the explicit local date and time fields before converting to UTC.');
$assert(str_contains($files['admin_css'],'.mgw-admin__tournament-date-time')
        && str_contains($files['admin_css'],'@media(max-width:520px)'),
    'Split date/time controls must stay responsive in Telegram mobile WebView.');

$assert(str_contains($files['admin_js'],'armCancellationConfirmation'),'Admin must require a second deliberate confirmation.');
$assert(str_contains($files['admin_js'],"kind === 'emergency' && !reason"),'Admin must block emergency without reason.');
$assert(str_contains($files['admin_js'],"mode:'double_confirm'"),'Admin must send backend proof of double confirmation.');
$assert(str_contains($files['admin_js'],'Результаты аннулированы'),'Admin success copy must state result annulment.');
$assert(str_contains($files['admin_js'],'полный возврат'),'Admin warning must state full refund semantics.');

$assert(str_contains($files['status'],"snapshot['last_cancellation']"),'Participant status endpoint must expose latest cancellation.');
$assert(str_contains($files['player_ui'],'Взнос возвращён полностью. Результаты турнира аннулированы.'),'Player UI must state full refund and annulment.');
$assert(str_contains($files['player_ui'],'Аварийная остановка'),'Player UI must distinguish emergency stop.');
$assert(str_contains($files['player_ui'],'Причина:'),'Player UI must expose cancellation reason.');
$assert(str_contains($files['manifest'],'mvp21_8=cancellation-emergency-v1'),'Client manifest must publish MVP-21.8 tournament UI.');

$assert($assertions>=50,'MVP-21.8 UX/integration contract is too shallow: '.$assertions);
fwrite(STDOUT,"Mvp21_8TournamentCancellationUxContractTest: {$assertions} assertions passed\n");
