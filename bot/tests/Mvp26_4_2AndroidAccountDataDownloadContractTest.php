<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;

$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/../' . ltrim($path, '/'));
    if (!is_string($content)) {
        throw new RuntimeException('Unable to read ' . $path);
    }
    return $content;
};

$accountData = $read('app/assets/js/screens/account-data-sheet-v1.js');
$client = $read('app/assets/js/api/client.js');
$accountEndpoint = $read('bot/account-data.php');
$downloadEndpoint = $read('bot/account-data-download.php');
$activity = $read('android-app/app/src/main/java/com/minigamesworld/app/MainActivity.java');
$policy = $read('android-app/app/src/main/java/com/minigamesworld/app/NavigationPolicy.java');
$shellConfig = $read('android-app/app/src/main/java/com/minigamesworld/app/ShellConfig.java');
$manifest = $read('android-app/app/src/main/AndroidManifest.xml');
$styles = $read('app/assets/css/account-data-v1.css');
$build = $read('android-app/app/build.gradle');
$fingerprint = $read('bot/helpers/staging-e2e-runtime-files.txt');

$assert(str_contains($accountData, 'const pendingActions = new Set();'), 'Account Data must use per-action pending state.');
$assert(!str_contains($accountData, 'let actionPending'), 'Legacy global actionPending must be removed.');
$assert(str_contains($accountData, "ACTION_DOWNLOAD_EXPORT = 'download_export'"), 'Download must own an explicit pending action.');
$assert(str_contains($accountData, "ACTION_SCHEDULE_DELETE = 'schedule_delete'"), 'Delete scheduling must own a separate pending action.');
$assert(str_contains($accountData, 'pendingButtonContent(ACTION_DOWNLOAD_EXPORT'), 'Download button must render its own pending spinner.');
$assert(str_contains($accountData, 'if (isAndroidShell())'), 'Android download must split from browser blob delivery.');
$assert(str_contains($accountData, 'api.accountDataAuthorizeDownload(requestId)'), 'Android must authorize the archive before native handoff.');
$assert(str_contains($accountData, 'mgw://android-account-download?request='), 'Android WebView must hand off only the public request id.');
$assert(str_contains($accountData, "mgw:android-download-enqueued"), 'Android native enqueue success event must exist.');
$assert(str_contains($accountData, 'URL.createObjectURL(result.blob)'), 'Browser/Telegram blob download fallback must remain intact.');
$assert(
    strpos($accountData, 'if (isAndroidShell())') < strpos($accountData, 'URL.createObjectURL(result.blob)'),
    'Android native handoff must be evaluated before browser blob download.'
);

$assert(str_contains($client, 'accountDataAuthorizeDownload:'), 'API client must expose native download authorization.');
$assert(str_contains($accountEndpoint, "'authorize_download'"), 'Account Data endpoint must expose authorize_download.');
$assert(str_contains($accountEndpoint, "'native_url'=>'mgw://android-account-download?request='"), 'Authorization must return only the native request route.');
$assert(str_contains($downloadEndpoint, "REQUEST_METHOD") && str_contains($downloadEndpoint, "'GET'"), 'Native file owner must be GET-only.');
$assert(str_contains($downloadEndpoint, 'AccountReauthGuard::authorize($config, [])'), 'Native file GET must require recent Android reauth/session.');
$assert(str_contains($downloadEndpoint, 'exportPathForUser($requestId, $mgwId)'), 'Native file GET must verify archive ownership.');
$assert(str_contains($downloadEndpoint, "Content-Disposition: attachment"), 'Native file GET must remain a real ZIP attachment.');

$assert(str_contains($activity, 'DownloadManager'), 'Android shell must own the system download.');
$assert(str_contains($activity, 'MiniGamesWorldAndroid/'), 'Android shell must expose a non-secret UA capability marker.');
$assert(str_contains($activity, 'CookieManager.getInstance().getCookie(endpoint)'), 'DownloadManager must receive the existing HttpOnly Android cookie natively.');
$assert(str_contains($activity, 'setDestinationInExternalPublicDir'), 'Android download must land in public Downloads.');
$assert(str_contains($activity, 'mgw:android-download-enqueued'), 'Native shell must notify WebView only with a fixed enqueue event.');
$assert(!str_contains($activity, 'addJavascriptInterface'), 'No privileged JavaScript bridge may be introduced.');

$assert(str_contains($policy, 'nativeAccountDownloadRequest'), 'Navigation policy must own the exact native download route.');
$assert(str_contains($policy, '^adr_[a-f0-9]{32}$'), 'Native download route must accept only canonical Account Data request ids.');
$assert(str_contains($shellConfig, '/bot/account-data-download.php'), 'Native shell must resolve only the internal download endpoint.');

$assert(str_contains($manifest, 'android.permission.WRITE_EXTERNAL_STORAGE'), 'API 26-28 public Downloads compatibility permission must be declared.');
$assert(str_contains($manifest, 'android:maxSdkVersion="28"'), 'Legacy storage permission must not apply on modern Android.');

$assert(str_contains($styles, '.account-data-v1-spinner'), 'Account Data must render an inline spinner.');
$assert(str_contains($styles, '.account-data-v1-action:disabled'), 'Primary pending action must preserve explicit styling.');
$assert(str_contains($styles, '.account-data-v1-danger:disabled'), 'Danger pending action must preserve explicit styling.');
$assert(str_contains($build, 'versionCode 2609'), 'Corrective APK must advance monotonically to versionCode 2609.');
$assert(str_contains($fingerprint, 'bot/account-data-download.php'), 'Exact staging fingerprint must include the native download owner.');

fwrite(STDOUT, "Mvp26_4_2AndroidAccountDataDownloadContractTest: {$assertions} assertions passed\n");
