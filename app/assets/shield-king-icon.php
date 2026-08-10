<?php
declare(strict_types=1);

/**
 * Read-only delivery endpoint for the frozen Shield King accepted metallic icon bundle.
 * It owns no product state and extracts only whitelisted WebP members from the exact bundle.
 */
const MGW_SK_ICON_EXPORT_SHA = 'bcb098b72333e5efa3247de82506550091710757';
const MGW_SK_ICON_BUNDLE = __DIR__ . '/icons/shield-king/accepted/MGW_SHIELD_KING_ACCEPTED_METALLIC_ICON_EXPORT_V1.zip';

$allowed = [
    'games/battleship.webp','games/checkers.webp','games/chess.webp','games/domino.webp',
    'games/four-in-a-row.webp','games/go.webp','games/reversi.webp','games/tic-tac-toe.webp',
    'ui/actions/back.webp','ui/actions/check.webp','ui/actions/close.webp','ui/actions/edit.webp',
    'ui/actions/invite.webp','ui/actions/more.webp','ui/actions/refresh-retry.webp','ui/actions/rematch.webp',
    'ui/actions/replay.webp','ui/actions/retry.webp','ui/actions/rules.webp',
    'ui/navigation/achievements.webp','ui/navigation/friends.webp','ui/navigation/games.webp',
    'ui/navigation/history.webp','ui/navigation/home.webp','ui/navigation/notifications.webp',
    'ui/navigation/profile.webp','ui/navigation/ranking.webp','ui/navigation/rules.webp',
    'ui/navigation/search.webp','ui/navigation/settings.webp','ui/navigation/store.webp',
    'ui/status/draw.webp','ui/status/error.webp','ui/status/info.webp','ui/status/locked.webp',
    'ui/status/loss.webp','ui/status/offline.webp','ui/status/online.webp','ui/status/success.webp',
    'ui/status/unlocked.webp','ui/status/warning.webp','ui/status/win.webp',
    'ui/economy/coins.webp','ui/economy/premium-currency.webp',
];

$asset = isset($_GET['asset']) ? (string) $_GET['asset'] : '';
if ($asset === '' || !in_array($asset, $allowed, true)) {
    http_response_code(404);
    exit;
}

try {
    $bytes = readZipMember(MGW_SK_ICON_BUNDLE, $asset);
} catch (Throwable $error) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Shield King icon asset is unavailable.';
    exit;
}

$etag = '"sk-icons-' . substr(MGW_SK_ICON_EXPORT_SHA, 0, 12) . '-' . sha1($asset) . '"';
if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Type: image/webp');
header('Content-Length: ' . strlen($bytes));
header('Cache-Control: public, max-age=31536000, immutable');
header('ETag: ' . $etag);
header('X-MGW-Shield-King-Icon-Export: ' . MGW_SK_ICON_EXPORT_SHA);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
    echo $bytes;
}

function readZipMember(string $zipPath, string $target): string
{
    $zip = file_get_contents($zipPath);
    if (!is_string($zip) || strlen($zip) < 22) {
        throw new RuntimeException('Bundle missing.');
    }

    $eocd = findEndOfCentralDirectory($zip);
    $entryCount = u16($zip, $eocd + 10);
    $centralOffset = u32($zip, $eocd + 16);
    $cursor = $centralOffset;

    for ($index = 0; $index < $entryCount; $index++) {
        if (substr($zip, $cursor, 4) !== "PK\x01\x02") {
            throw new RuntimeException('Invalid central directory.');
        }

        $method = u16($zip, $cursor + 10);
        $compressedSize = u32($zip, $cursor + 20);
        $uncompressedSize = u32($zip, $cursor + 24);
        $nameLength = u16($zip, $cursor + 28);
        $extraLength = u16($zip, $cursor + 30);
        $commentLength = u16($zip, $cursor + 32);
        $localOffset = u32($zip, $cursor + 42);
        $name = substr($zip, $cursor + 46, $nameLength);

        if ($name === $target) {
            if (substr($zip, $localOffset, 4) !== "PK\x03\x04") {
                throw new RuntimeException('Invalid local header.');
            }
            $localNameLength = u16($zip, $localOffset + 26);
            $localExtraLength = u16($zip, $localOffset + 28);
            $dataOffset = $localOffset + 30 + $localNameLength + $localExtraLength;
            $compressed = substr($zip, $dataOffset, $compressedSize);

            if ($method === 0) {
                $data = $compressed;
            } elseif ($method === 8) {
                $inflated = gzinflate($compressed);
                if (!is_string($inflated)) {
                    throw new RuntimeException('Deflate failed.');
                }
                $data = $inflated;
            } else {
                throw new RuntimeException('Unsupported compression method.');
            }

            if (strlen($data) !== $uncompressedSize) {
                throw new RuntimeException('Unexpected member size.');
            }
            return $data;
        }

        $cursor += 46 + $nameLength + $extraLength + $commentLength;
    }

    throw new RuntimeException('Member missing.');
}

function findEndOfCentralDirectory(string $zip): int
{
    $start = max(0, strlen($zip) - 65557);
    $position = strrpos(substr($zip, $start), "PK\x05\x06");
    if ($position === false) {
        throw new RuntimeException('EOCD missing.');
    }
    return $start + $position;
}

function u16(string $bytes, int $offset): int
{
    $value = unpack('vvalue', substr($bytes, $offset, 2));
    return (int)($value['value'] ?? 0);
}

function u32(string $bytes, int $offset): int
{
    $value = unpack('Vvalue', substr($bytes, $offset, 4));
    return (int)($value['value'] ?? 0);
}
