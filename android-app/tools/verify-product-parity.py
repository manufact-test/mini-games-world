#!/usr/bin/env python3
from __future__ import annotations

import json
import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parents[1]
REPO = ROOT.parent
APP = ROOT / "app"
JAVA = APP / "src/main/java/com/minigamesworld/app"
WEB = REPO / "app/assets/js"
BOT = REPO / "bot"
errors: list[str] = []

def require(condition: bool, message: str) -> None:
    if not condition:
        errors.append(message)

def read(path: pathlib.Path) -> str:
    require(path.is_file(), f"missing file: {path.relative_to(REPO)}")
    return path.read_text(encoding="utf-8") if path.is_file() else ""

build = read(APP / "build.gradle")
manifest = read(APP / "src/main/AndroidManifest.xml")
main = read(JAVA / "MainActivity.java")
navigation = read(JAVA / "NavigationPolicy.java")
nav_test = read(APP / "src/test/java/com/minigamesworld/app/NavigationPolicyTest.java")
telegram = read(WEB / "telegram/telegram-app.js")
invites = read(WEB / "games/game-invites-v110.js")
home = read(WEB / "screens/home-screen.js")
handoff = read(WEB / "main-v110-handoff-shell.js")
game_screen = read(WEB / "screens/game-screen-v102.js")
reconnect = read(WEB / "main-v110-reconnect-v174.js")
account_shortcuts = read(WEB / "components/account-shortcuts.js")
version_manifest = read(REPO / "app/runtime/client/version-manifest.php")
android_auth = read(BOT / "android-auth.php")
support_download = read(BOT / "support-attachment-download.php")
assetlinks_raw = read(REPO / ".well-known/assetlinks.json")

version = re.search(r"\bversionCode\s+(\d+)\b", build)
require(version is not None and int(version.group(1)) == 2613,
        "MVP-26.6 corrective candidate must use versionCode 2613")
require("versionName '0.26.6.2-android-product-parity-corrective'" in build,
        "MVP-26.6 versionName missing")
require("applicationId 'com.minigamesworld.app.acceptance'" in build,
        "accepted Android package changed")
require("mgw-acceptance-stable.keystore" in build,
        "accepted signing owner changed")

require('android.permission.VIBRATE' in manifest,
        "Android vibration permission missing")
require('android:autoVerify="true"' in manifest
        and 'android:pathPrefix="/invite/"' in manifest
        and 'android:host="${mgwInviteHost}"' in manifest,
        "verified /invite App Link filter missing")
require("manifestPlaceholders = [mgwInviteHost: configuredMgwInviteHost]" in build,
        "App Link host must derive from configured MGW HTTPS origin")

require("inviteTokenFromLanding" in navigation
        and 'Pattern.compile("^/invite/([a-f0-9]{24})/?$")' in navigation
        and "sameOrigin(baseUri, uri)" in navigation,
        "invite deep link must be exact and same-origin")
require("publicInviteLandingIsExactSameOriginDeepLink" in nav_test,
        "invite navigation unit contract missing")
require("beginAndroidAuthentication(inviteTokenFromIntent(intent))" in main,
        "cold invite must pass through Android auth")
require("beginAndroidAuthentication(inviteToken)" in main,
        "warm invite must pass through Android auth")
require("WebAppLaunchUrl::invitation" in android_auth
        and "$inviteToken" in android_auth,
        "server Android auth must preserve validated invite token")

require("installAndroidParityHooks" in main
        and "__mgwAndroidParityHooksV1" in main
        and "navigator.vibrate" in main
        and "event.isTrusted" in main,
        "standalone Android user-action haptic compatibility hook missing")
require("https://t.me/" in main
        and "__mgwAndroidOriginalOpen" in main
        and "window.location.assign(value)" in main,
        "standalone Android invite share handoff missing")
require("nativeTelegramShareDeepLink" in navigation
        and "tg://msg_url?" in navigation
        and "openTelegramShareDirect" in main,
        "standalone Android Telegram share must bypass the browser when Telegram is installed")
require("network_unavailable" in game_screen
        and "android-poll-network-silent-v1" in version_manifest,
        "background game polling must suppress transient transport toasts with a fresh active cache identity")

require("MgwWebChromeClient" in main
        and "onShowFileChooser" in main
        and "Intent.ACTION_OPEN_DOCUMENT" in main
        and "WEB_UPLOAD_MIME_TYPES" in main,
        "Android Support file chooser missing")
for mime in ["image/jpeg","image/png","image/webp","image/gif","application/pdf","text/plain"]:
    require(mime in main, f"Android file chooser must retain Support MIME: {mime}")

require("setDownloadListener(this::beginWebDownload)" in main
        and "DownloadManager.Request" in main
        and "navigationPolicy.isInternal(url)" in main,
        "authenticated same-origin Android download owner missing")
require("data-support-attachment" in main
        and "support-thread-attachment-name" in main
        and "support-attachment-download.php" in main,
        "Android Support document download compatibility hook missing")
require("attachmentForUser" in support_download
        and "Content-Disposition: attachment" in support_download
        and "getUserFromRequest([])" in support_download,
        "Support attachment download must be authenticated and ownership-scoped")


try:
    assetlinks = json.loads(assetlinks_raw)
except Exception as exc:
    assetlinks = []
    errors.append(f"invalid assetlinks.json: {exc}")
require(isinstance(assetlinks, list) and len(assetlinks) == 1,
        "assetlinks.json must contain one bounded association")
if isinstance(assetlinks, list) and assetlinks:
    target = assetlinks[0].get("target", {}) if isinstance(assetlinks[0], dict) else {}
    require(target.get("package_name") == "com.minigamesworld.app.acceptance",
            "assetlinks package must match accepted Android package")
    fingerprints = target.get("sha256_cert_fingerprints", [])
    require("EF:DA:9B:86:B0:B0:ED:FA:A2:20:64:86:4A:AD:DC:02:5F:15:BD:C0:63:BF:53:CF:B5:8D:56:9F:50:4F:54:84" in fingerprints,
            "assetlinks must use accepted signing certificate")

renderers = {
    "tic_tac_toe": WEB / "games/tictactoe/renderer.js",
    "checkers": WEB / "games/checkers/renderer.js",
    "chess": WEB / "games/chess/renderer.js",
    "reversi": WEB / "games/reversi/renderer.js",
    "go": WEB / "games/go/renderer.js",
    "four_in_a_row": WEB / "games/four-in-a-row/renderer.js",
    "domino": WEB / "games/domino/renderer.js",
    "battleship": WEB / "games/battleship/renderer.js",
}
for game, path in renderers.items():
    require(path.is_file(), f"accepted game renderer missing: {game}")

for marker in [
    "games/tictactoe/renderer.js",
    "games/checkers/renderer.js",
    "games/chess/renderer.js",
    "games/reversi/renderer.js",
    "games/go/renderer.js",
    "games/four-in-a-row/renderer.js",
    "games/domino/renderer.js",
    "games/battleship/renderer.js",
]:
    require(marker in version_manifest, f"active version manifest lost game owner: {marker}")

require("production-v110-reconnect-v174.js" in reconnect,
        "accepted reconnect owner missing")
for marker in [
    "initSearchScreen",
    "initGameInvites",
    "initUnifiedGameLauncher",
    "initTournamentsScreen",
    "initNotificationsScreen",
    "initProfileScreen",
    "initAccountShortcuts",
    "screens/store-screen.js",
]:
    require(marker in handoff, f"shared Android product surface missing from active shell: {marker}")
require("friends-screen-v110.js" in account_shortcuts,
        "Friends surface missing from account shortcuts")
require("supportCreate" in home and "supportTickets" in home,
        "Support surface missing from shared Home owner")

require("setMediaPlaybackRequiresUserGesture(true)" in main,
        "Android must preserve user-gesture media policy instead of muting shared media")
require("PlatformAdapters.disabled()" in main,
        "provider-neutral adapters must remain disabled during product parity")

provider_markers = [
    "com.android.billingclient",
    "com.google.firebase",
    "firebase-messaging",
    "com.google.android.gms.ads",
    "play-services-ads",
    "com.google.android.play:integrity",
    "play-integrity",
]
for marker in provider_markers:
    require(marker.lower() not in build.lower(),
            f"MVP-26.6 must remain provider-independent: {marker}")

if errors:
    print("MVP-26.6 Android product parity verification FAILED", file=sys.stderr)
    for error in errors:
        print(f"- {error}", file=sys.stderr)
    raise SystemExit(1)

print("MVP-26.6 Android product parity verification PASS")
print("checks: 8 games + shared surfaces + vibration + invites + Support/account native seams + provider independence")
