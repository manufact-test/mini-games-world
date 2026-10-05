<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require __DIR__ . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/app/runtime/localization/LocalizationCatalog.php';

function mgw_friends_copy(string $key, string $emergencyFallback): string
{
    try {
        static $catalog = null;
        if (!$catalog instanceof LocalizationCatalog) {
            $catalog = new LocalizationCatalog(dirname(__DIR__) . '/app/locales');
        }
        return $catalog->translate($key);
    } catch (Throwable $error) {
        error_log('[MiniGamesWorld friends localization] ' . $error->getMessage());
        return $emergencyFallback;
    }
}
require_once __DIR__ . '/social/FriendGraphService.php';
require_once __DIR__ . '/social/SocialFriendNotificationService.php';
require_once __DIR__ . '/social/SocialPlayerProfileReader.php';
require_once __DIR__ . '/social/PlayerReportService.php';
require_once __DIR__ . '/moderation/ModerationService.php';
require_once __DIR__ . '/services/UserActionRateLimiter.php';

function mgw_friend_error_status(string $reason): int
{
    return match ($reason) {
        'self_relation', 'self_report', 'invalid_reason', 'invalid_match' => 422,
        'user_unavailable', 'report_not_found' => 404,
        'request_unavailable', 'incoming_request_exists', 'request_not_incoming', 'request_not_outgoing' => 409,
        default => 409,
    };
}

function mgw_friend_error_message(string $reason): string
{
    return match ($reason) {
        'self_relation' => mgw_friends_copy('server.friends.self_relation', 'You cannot perform this action with your own profile.'),
        'self_report' => mgw_friends_copy('server.friends.self_report', 'You cannot report your own profile.'),
        'invalid_reason' => mgw_friends_copy('server.friends.invalid_reason', 'Choose a report reason.'),
        'invalid_match' => mgw_friends_copy('server.friends.invalid_match', 'The related match is unavailable for this report.'),
        'user_unavailable' => mgw_friends_copy('server.friends.user_unavailable', 'MGW player was not found.'),
        'incoming_request_exists' => mgw_friends_copy('server.friends.incoming_request_exists', 'You already have an incoming request from this player.'),
        'request_not_incoming' => mgw_friends_copy('server.friends.request_not_incoming', 'The incoming request is no longer available.'),
        'request_not_outgoing' => mgw_friends_copy('server.friends.request_not_outgoing', 'The outgoing request is no longer available.'),
        default => mgw_friends_copy('server.friends.action_unavailable', 'This action is currently unavailable.'),
    };
}

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }

    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) json_response(['ok' => false, 'error' => mgw_friends_copy('server.friends.invalid_request', 'Invalid request.')], 400);

    $configRef = $config;
    $authenticatedUser = (new AuthService($configRef))->getUserFromRequest($payload);
    $actorMgwId = trim((string)($authenticatedUser['mgw_id'] ?? ''));
    if (!MgwIdGenerator::isValid($actorMgwId)) {
        json_response(['ok' => false, 'error' => mgw_friends_copy('server.friends.profile_unavailable', 'The MGW profile is unavailable for this session.')], 401);
    }

    $databaseConfig = DatabaseConfig::fromApplicationConfig($configRef);
    $router = new RuntimeStorageRouter($configRef);
    if (!$databaseConfig->enabled()
        || ($router->enabled() && $router->routeFor('accounts') !== RuntimeStorageRouter::DRIVER_DATABASE)) {
        json_response(['ok' => false, 'error' => mgw_friends_copy('server.friends.unavailable', 'MGW Friends are temporarily unavailable.')], 503);
    }

    $database = PdoConnectionFactory::create($databaseConfig);
    $service = new FriendGraphService($database);
    $profileReader = new SocialPlayerProfileReader($database);
    $reports = new PlayerReportService($database);
    $moderation = new ModerationService($database);
    $rateLimiter = new UserActionRateLimiter($database, $configRef);
    $action = strtolower(trim((string)($payload['action'] ?? 'snapshot')));
    $target = trim((string)($payload['target_mgw_id'] ?? ''));

    try {
        // Reads, decline/cancel, block/unblock and reporting remain available
        // for safety. Social restrictions stop new relationship creation.
        if (in_array($action, ['request','accept'], true)) {
            $moderation->assertAllowed($actorMgwId, 'social');
        }
        if ($action === 'report') {
            $rateLimiter->assertAllowed('player_report', $actorMgwId);
        }
        $result = match ($action) {
            'snapshot' => $service->snapshot($actorMgwId),
            'lookup' => [
                'players' => $service->searchPlayers($actorMgwId, (string)($payload['query'] ?? '')),
                'limit' => FriendGraphService::SEARCH_LIMIT,
            ],
            'report_lookup' => (function () use ($service, $actorMgwId, $payload): array {
                $query = trim((string)($payload['query'] ?? ''));
                $exact = $service->lookupExact($actorMgwId, $query);
                if (is_array($exact) && (string)($exact['mgw_id'] ?? '') === $actorMgwId) {
                    throw new PlayerReportException('self_report', mgw_friends_copy('server.friends.self_report', 'You cannot report your own profile.'));
                }
                return [
                    'players' => $exact !== null ? [$exact] : $service->searchPlayers($actorMgwId, $query),
                    'limit' => FriendGraphService::SEARCH_LIMIT,
                ];
            })(),
            'report_history' => [
                'reports' => $reports->reporterHistory($actorMgwId, 12),
            ],
            'player_profile' => (function () use ($service, $profileReader, $actorMgwId, $target): array {
                if ($service->lookupExact($actorMgwId, $target) === null) {
                    throw new FriendGraphException('user_unavailable', 'MGW account is unavailable.');
                }
                return $profileReader->read($target);
            })(),
            'request' => $service->requestFriend($actorMgwId, $target),
            'accept' => $service->acceptFriendRequest($actorMgwId, $target),
            'decline' => $service->declineFriendRequest($actorMgwId, $target),
            'cancel' => $service->cancelFriendRequest($actorMgwId, $target),
            'remove' => $service->removeFriend($actorMgwId, $target),
            'block' => $service->block($actorMgwId, $target),
            'unblock' => $service->unblock($actorMgwId, $target),
            'report' => $reports->submit(
                $actorMgwId,
                $target,
                (string)($payload['reason'] ?? ''),
                (string)($payload['details'] ?? ''),
                (string)($payload['related_match_id'] ?? '')
            ),
            default => throw new InvalidArgumentException('unknown_action'),
        };
    } catch (UserActionRateLimitException $error) {
        header('Retry-After: ' . $error->retryAfterSec);
        json_response([
            'ok'=>false,
            'code'=>'rate_limited',
            'error'=>mgw_friends_copy('server.friends.rate_limited', 'Too many actions. Try again a little later.'),
        ], 429);
    } catch (ModerationException $error) {
        json_response([
            'ok'=>false,
            'code'=>$error->reason,
            'error'=>mgw_public_api_error($error->getMessage()),
        ], 403);
    } catch (FriendGraphException|PlayerReportException $error) {
        json_response([
            'ok' => false,
            'code' => $error->reason,
            'error' => mgw_friend_error_message($error->reason),
        ], mgw_friend_error_status($error->reason));
    } catch (InvalidArgumentException $error) {
        json_response(['ok' => false, 'code' => 'invalid_request', 'error' => mgw_friends_copy('server.friends.invalid_request', 'Invalid request.')], 422);
    }

    if (in_array($action, ['request', 'accept', 'decline', 'cancel', 'block'], true) && is_array($result) && !empty($result['changed'])) {
        try {
            $notificationStorage = StorageFactory::createJson((string)($configRef['data_dir'] ?? (__DIR__ . '/data')));
            (new SocialFriendNotificationService($database, $notificationStorage))
                ->publish($action, $actorMgwId, $target, $result);
        } catch (Throwable $notificationError) {
            error_log('[MiniGamesWorld Friends notification] ' . $notificationError->getMessage());
        }
    }

    json_response(['ok' => true, 'action' => $action, 'result' => $result]);
} catch (Throwable $error) {
    error_log('[MiniGamesWorld Friends] ' . $error->getMessage());
    json_response(['ok' => false, 'error' => mgw_friends_copy('server.friends.failed', 'The MGW Friends action could not be completed.')], 500);
}
