from pathlib import Path

service_path = Path('bot/services/StagingTestPlayerStateResetService.php')
text = service_path.read_text(encoding='utf-8')

marker = "invite_cleanup_parity_db_missing"
if marker in text:
    print('Classifier already applied.')
else:
    old_stages = """        'notification_cleanup',\n        'invite_cleanup',\n        'economy',\n"""
    new_stages = """        'notification_cleanup',\n        'invite_cleanup',\n        'invite_cleanup_parity_db_missing',\n        'invite_cleanup_parity_db_extra',\n        'invite_cleanup_parity_fingerprint',\n        'invite_cleanup_parity_unknown',\n        'economy',\n"""
    if text.count(old_stages) != 1:
        raise SystemExit('Expected reset stage list anchor exactly once.')
    text = text.replace(old_stages, new_stages, 1)

    old_catch = """        try {\n            $inviteCleanup = $this->cleanupRuntimeInviteRows($snapshot, $removedInvites);\n        } catch (Throwable $error) {\n            throw new StagingTestPlayerResetStageException('invite_cleanup', $error);\n        }\n"""
    new_catch = """        try {\n            $inviteCleanup = $this->cleanupRuntimeInviteRows($snapshot, $removedInvites);\n        } catch (StagingTestPlayerResetStageException $error) {\n            throw $error;\n        } catch (Throwable $error) {\n            throw new StagingTestPlayerResetStageException('invite_cleanup', $error);\n        }\n"""
    if text.count(old_catch) != 1:
        raise SystemExit('Expected invite cleanup catch anchor exactly once.')
    text = text.replace(old_catch, new_catch, 1)

    old_parity = """        if (($inviteAudit['ok'] ?? false) !== true) {\n            throw new RuntimeException('Staging test invite cleanup did not restore invite parity.');\n        }\n"""
    new_parity = """        if (($inviteAudit['ok'] ?? false) !== true) {\n            $sourceCount = (int)($inviteAudit['source_count'] ?? -1);\n            $databaseCount = (int)($inviteAudit['database_count'] ?? -1);\n            $sourceFingerprint = (string)($inviteAudit['source_fingerprint'] ?? '');\n            $databaseFingerprint = (string)($inviteAudit['database_fingerprint'] ?? '');\n\n            if ($sourceCount >= 0 && $databaseCount >= 0 && $sourceCount > $databaseCount) {\n                $stage = 'invite_cleanup_parity_db_missing';\n            } elseif ($sourceCount >= 0 && $databaseCount >= 0 && $databaseCount > $sourceCount) {\n                $stage = 'invite_cleanup_parity_db_extra';\n            } elseif ($sourceFingerprint !== ''\n                && $databaseFingerprint !== ''\n                && !hash_equals($sourceFingerprint, $databaseFingerprint)) {\n                $stage = 'invite_cleanup_parity_fingerprint';\n            } else {\n                $stage = 'invite_cleanup_parity_unknown';\n            }\n\n            throw new StagingTestPlayerResetStageException(\n                $stage,\n                new RuntimeException('Staging test invite cleanup did not restore invite parity.')\n            );\n        }\n"""
    if text.count(old_parity) != 1:
        raise SystemExit('Expected invite parity failure anchor exactly once.')
    text = text.replace(old_parity, new_parity, 1)
    service_path.write_text(text, encoding='utf-8')

contract = Path('bot/tests/StagingTestInviteCleanupParityClassifierContractTest.php')
contract.write_text("""<?php\ndeclare(strict_types=1);\n\n$root = dirname(__DIR__, 2);\n$service = file_get_contents($root . '/bot/services/StagingTestPlayerStateResetService.php');\nif (!is_string($service)) {\n    throw new RuntimeException('Cannot read staging test reset service.');\n}\n\n$assertions = 0;\n$assert = static function (bool $condition, string $message) use (&$assertions): void {\n    $assertions++;\n    if (!$condition) throw new RuntimeException($message);\n};\n\nforeach ([\n    'invite_cleanup_parity_db_missing',\n    'invite_cleanup_parity_db_extra',\n    'invite_cleanup_parity_fingerprint',\n    'invite_cleanup_parity_unknown',\n] as $stage) {\n    $assert(str_contains($service, \"'{$stage}'\"), \"Missing safe invite parity stage {$stage}.\");\n}\n$assert(str_contains($service, 'catch (StagingTestPlayerResetStageException $error)'),\n    'Invite cleanup must preserve the classified safe stage.');\n$assert(str_contains($service, '$sourceCount > $databaseCount')\n    && str_contains($service, '$databaseCount > $sourceCount'),\n    'Invite parity classifier must distinguish missing versus extra DB rows.');\n$assert(str_contains($service, '!hash_equals($sourceFingerprint, $databaseFingerprint)'),\n    'Equal-count fingerprint mismatch must have its own safe classification.');\n$assert(!str_contains($service, \"throw new StagingTestPlayerResetStageException(\\n                $stage,\\n                $error\"),\n    'Classifier must not expose or reuse a private exception message.');\n\nfwrite(STDOUT, \"StagingTestInviteCleanupParityClassifierContractTest: {$assertions} assertions passed\\n\");\n""", encoding='utf-8')

print('Classifier patch applied.')
