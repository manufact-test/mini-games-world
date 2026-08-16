import { applyAccountLocalePreference } from '@mgw/i18n';

const DEFAULT_AVATAR_ITEM_ID = 'starter-default-01';
const STARTER_AVATAR_ITEM_IDS = new Set(['starter-default-01','starter-default-02','starter-default-03']);
const INTERNAL_MGW_ID_PATTERN = /^MGW-([0-9A-HJKMNP-TV-Z]{16})$/;
const PUBLIC_MGW_ID_PATTERN = /^MGW-ID-([0-9A-HJKMNP-TV-Z]{16})$/;

export function applyCanonicalMgwProfile(runtimeUser = {}, profile = null){
  if (!profile || typeof profile !== 'object') throw new Error('Canonical MGW profile is unavailable.');
  const mgwId = String(profile.mgw_id || '').trim();
  if (!mgwId) throw new Error('Canonical MGW profile id is unavailable.');
  const current = runtimeUser && typeof runtimeUser === 'object' ? runtimeUser : {};
  const nickname = String(profile.nickname || profile.display_name || '').trim() || 'Игрок';
  const avatarItemId = canonicalAvatarItemId(profile.avatar);
  applyAccountLocalePreference(profile.preferred_locale || null);
  return {
    ...current,
    mgw_id: mgwId,
    public_mgw_id: publicMgwId(profile.public_mgw_id || mgwId),
    display_name: nickname,
    first_name: nickname,
    username: null,
    photo_url: '',
    avatar_item_id: avatarItemId,
    avatar_label: 'MG',
    registered_at: nullableString(profile.created_at),
    mgw_profile_loaded: true,
  };
}

export function mergeCanonicalMgwUser(currentUser = {}, runtimeUser = {}, profile = null){
  return applyCanonicalMgwProfile({
    ...(currentUser && typeof currentUser === 'object' ? currentUser : {}),
    ...(runtimeUser && typeof runtimeUser === 'object' ? runtimeUser : {}),
  }, profile);
}

export function canonicalAvatarItemId(avatar = null){
  const itemId = String(avatar?.item_id || '').trim();
  return STARTER_AVATAR_ITEM_IDS.has(itemId) ? itemId : DEFAULT_AVATAR_ITEM_ID;
}

export function publicMgwId(value){
  const normalized = String(value || '').trim().toUpperCase();
  const publicMatch = normalized.match(PUBLIC_MGW_ID_PATTERN);
  if (publicMatch) return `MGW-ID-${publicMatch[1]}`;
  const internalMatch = normalized.match(INTERNAL_MGW_ID_PATTERN);
  return internalMatch ? `MGW-ID-${internalMatch[1]}` : '';
}

export function internalMgwId(value){
  const normalized = String(value || '').trim().toUpperCase();
  const internalMatch = normalized.match(INTERNAL_MGW_ID_PATTERN);
  if (internalMatch) return `MGW-${internalMatch[1]}`;
  const publicMatch = normalized.match(PUBLIC_MGW_ID_PATTERN);
  return publicMatch ? `MGW-${publicMatch[1]}` : '';
}

export function canonicalAvatarUrl(){ return ''; }

function nullableString(value){
  const normalized = String(value ?? '').trim();
  return normalized || null;
}
