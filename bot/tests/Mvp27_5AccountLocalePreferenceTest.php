<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require_once $root.'/bot/database/DatabaseConnectionInterface.php';
require_once $root.'/bot/database/PdoDatabaseConnection.php';
require_once $root.'/bot/accounts/MgwIdGenerator.php';
require_once $root.'/bot/accounts/MgwProfileService.php';
$check=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$check(MgwIdentityPolicy::normalizeLocale('EN')==='en','Normalize EN');
$check(MgwIdentityPolicy::normalizeLocale('ru')==='ru','Normalize RU');
foreach(['de','fr',''] as $bad){try{MgwIdentityPolicy::normalizeLocale($bad);throw new RuntimeException('Unsupported '.$bad);}catch(InvalidArgumentException $expected){}}
$db=new PdoDatabaseConnection(new PDO('sqlite::memory:'));
$db->execute('CREATE TABLE mgw_users (mgw_id TEXT PRIMARY KEY,status TEXT,nickname TEXT,display_name TEXT,equipped_avatar_item_id TEXT,preferred_locale TEXT,created_at_utc TEXT,updated_at_utc TEXT,last_seen_at_utc TEXT)');
$db->execute('CREATE TABLE mgw_identities (mgw_id TEXT,provider TEXT,linked_at_utc TEXT)');
$one=MgwIdGenerator::generate();$two=MgwIdGenerator::generate();
foreach([$one,$two] as $id){$db->execute('INSERT INTO mgw_users (mgw_id,status,nickname,display_name,equipped_avatar_item_id,preferred_locale,created_at_utc,updated_at_utc,last_seen_at_utc) VALUES (:id,\'active\',\'PlayerA\',\'PlayerA\',\'starter-default-01\',NULL,\'2026-10-08 12:00:00\',\'2026-10-08 12:00:00\',\'2026-10-08 12:00:00\')',['id'=>$id]);}
$service=new MgwProfileService($db);
$check($service->publicProfile($one)['preferred_locale']===null,'Initial is unset');
$check($service->updateProfile($one,['preferred_locale'=>'en'])['preferred_locale']==='en','English stored');
$check($service->publicProfile($one)['preferred_locale']==='en','English persists');
$check($service->publicProfile($two)['preferred_locale']===null,'Other account untouched');
$check($service->updateProfile($one,['preferred_locale'=>'ru'])['preferred_locale']==='ru','Russian stored');
try{$service->updateProfile($one,['preferred_locale'=>'fr']);throw new RuntimeException('Unsupported was saved');}catch(InvalidArgumentException $expected){}
$check($service->publicProfile($one)['preferred_locale']==='ru','Invalid write is atomic');
echo "MVP-27.5 canonical locale DB: PASS\n";
