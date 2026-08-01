from pathlib import Path


def replace_once(path: str, old: str, new: str) -> None:
    file = Path(path)
    text = file.read_text()
    if old not in text:
        if new in text:
            return
        raise RuntimeError(f'Expected block not found in {path}: {old[:100]!r}')
    file.write_text(text.replace(old, new, 1))

replace_once(
    'bot/helpers/UserWelcomeGuard.php',
    "// Active canonical path: '/app/v110.php?v=1111'.",
    "// Active canonical path: '/app/v110.php?v=1113'.",
)

replace_once(
    'bot/tests/ProductionV110CanonicalShareNotificationRootContractTest.php',
    """$assert(
    str_contains($invites, \"String(errorCode || '') === 'USER_DECLINED'\")
        && str_contains($invites, 'void discardDraft(attempt.invite).finally')
        && !str_contains($invites, \"toast(sent === false\"),
    'Native cancellation must silently discard its draft without a technical toast or waiting surface.'
);""",
    """$assert(
    str_contains($invites, \"String(errorCode || '') === 'USER_DECLINED'\")
        && str_contains($invites, 'restoreWarmShareDraft(attempt);')
        && !str_contains($invites, 'void discardDraft(attempt.invite).finally')
        && !str_contains($invites, \"toast(sent === false\"),
    'Native cancellation must silently reuse its valid draft without a technical toast or waiting surface.'
);""",
)

for path in [
    'bot/tests/ProductionV110AcceptanceRootFixContractTest.php',
    'bot/tests/ProductionV110CanonicalInviteLaunchContractTest.php',
]:
    file = Path(path)
    text = file.read_text()
    file.write_text(text.replace('fresh R8 assets', 'fresh R9 assets'))
