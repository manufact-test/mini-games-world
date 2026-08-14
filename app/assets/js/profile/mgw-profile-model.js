export function applyCanonicalMgwProfile(runtimeUser = {}, profile = null){
  if (!profile || typeof profile !== 'object') {
    throw new Error('Canonical MGW profile is unavailable.');
  }

  const mgwId = String(profile.mgw_id || '').trim();
  if (!mgwId) {
    throw new Error('Canonical MGW profile id is unavailable.');
  }

  const current = runtimeUser && typeof runtimeUser === 'object' ? runtimeUser : {};
  const displayName = String(profile.display_name || '').trim() || 'Игрок';
  const username = nullableString(profile.username);
  const avatarUrl = canonicalAvatarUrl(profile.avatar);
  const registeredAt = nullableString(profile.created_at);

  return {
    ...current,
    mgw_id: mgwId,
    display_name: displayName,
    first_name: displayName,
    username,
    photo_url: avatarUrl,
    registered_at: registeredAt,
    mgw_profile_loaded: true,
  };
}

export function canonicalAvatarUrl(avatar = null){
  if (!avatar || typeof avatar !== 'object') return '';

  const externalRef = String(avatar.external_ref || '').trim();
  if (/^https?:\/\//i.test(externalRef) || externalRef.startsWith('/')) {
    return externalRef;
  }

  return '';
}

function nullableString(value){
  const normalized = String(value ?? '').trim();
  return normalized || null;
}
