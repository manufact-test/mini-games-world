const AVATAR_SPRITE_ASSET = './assets/media/avatars/mgw-avatar-characters-v1.svg?v=2';

export const AVATAR_VISUAL_REGISTRY = Object.freeze({
  'starter-default-01': Object.freeze({ name:'Nova', rarity:'free', asset:AVATAR_SPRITE_ASSET, spriteIndex:0 }),
  'starter-default-02': Object.freeze({ name:'Moss', rarity:'free', asset:AVATAR_SPRITE_ASSET, spriteIndex:1 }),
  'starter-default-03': Object.freeze({ name:'Blaze', rarity:'free', asset:AVATAR_SPRITE_ASSET, spriteIndex:2 }),
  'store-avatar-01': Object.freeze({ name:'Pulse', rarity:'rare', asset:AVATAR_SPRITE_ASSET, spriteIndex:3 }),
  'store-avatar-02': Object.freeze({ name:'Tide', rarity:'rare', asset:AVATAR_SPRITE_ASSET, spriteIndex:4 }),
  'store-avatar-03': Object.freeze({ name:'Hex', rarity:'rare', asset:AVATAR_SPRITE_ASSET, spriteIndex:5 }),
  'store-avatar-04': Object.freeze({ name:'Volt', rarity:'elite', asset:AVATAR_SPRITE_ASSET, spriteIndex:6 }),
  'store-avatar-05': Object.freeze({ name:'Aurex', rarity:'elite', asset:AVATAR_SPRITE_ASSET, spriteIndex:7 }),
  'store-avatar-06': Object.freeze({ name:'Frost', rarity:'elite', asset:AVATAR_SPRITE_ASSET, spriteIndex:8 }),
  'store-avatar-07': Object.freeze({ name:'Void', rarity:'legendary', asset:AVATAR_SPRITE_ASSET, spriteIndex:9 }),
  'store-avatar-08': Object.freeze({ name:'Solaris', rarity:'legendary', asset:AVATAR_SPRITE_ASSET, spriteIndex:10 }),
  'store-avatar-09': Object.freeze({ name:'Drax', rarity:'legendary', asset:AVATAR_SPRITE_ASSET, spriteIndex:11 }),
});

export function getAvatarVisualMeta(itemId){
  const normalized = String(itemId || '').trim().toLowerCase();
  return AVATAR_VISUAL_REGISTRY[normalized] || null;
}

export function avatarDisplayName(itemId, fallback = 'Аватарка'){
  return getAvatarVisualMeta(itemId)?.name || fallback;
}

export function avatarSpritePosition(itemId){
  const meta = getAvatarVisualMeta(itemId);
  if (!meta) return '0% 50%';
  const x = meta.spriteIndex <= 0 ? 0 : (meta.spriteIndex / 11) * 100;
  return `${x}% 50%`;
}
