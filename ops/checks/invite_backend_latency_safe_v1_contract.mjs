import fs from 'node:fs';

const endpoint = fs.readFileSync('bot/invite-opponents.php','utf8');
const invites = fs.readFileSync('bot/invites.php','utf8');
const assert = (ok, message) => { if (!ok) throw new Error(message); };

assert(
  endpoint.includes("$isAndroidActor = $identityProvider === 'android_device';")
    && endpoint.includes('if ($isAndroidActor && MgwIdGenerator::isValid($actorMgwId))'),
  'Telegram picker must stay on the fast runtime snapshot while Android retains canonical friend merge.'
);

assert(
  invites.includes("$action !== 'create_link_draft'")
    && invites.includes('not yet shared product state'),
  'Unshared link draft must skip DB projection before native prepared sharing.'
);

assert(
  invites.includes("if ($action === 'create_link_draft' && is_array($result['invite'] ?? null))")
    && invites.includes('mgw_prepare_invite_message('),
  'Prepared Telegram message generation must remain intact.'
);

console.log('INVITE_BACKEND_LATENCY_SAFE_V1=PASS');
