export const AVATAR_VISUAL_REGISTRY = {
  'starter-default-01': { rarity: 'free', asset: null },
  'starter-default-02': { rarity: 'free', asset: null },
  'starter-default-03': { rarity: 'free', asset: null },
  'store-avatar-01': { rarity: 'rare', asset: null },
  'store-avatar-02': { rarity: 'rare', asset: null },
  'store-avatar-03': { rarity: 'rare', asset: null },
  'store-avatar-04': { rarity: 'elite', asset: null },
  'store-avatar-05': { rarity: 'elite', asset: null },
  'store-avatar-06': { rarity: 'elite', asset: null },
  'store-avatar-07': { rarity: 'legendary', asset: null },
  'store-avatar-08': { rarity: 'legendary', asset: null },
  'store-avatar-09': { rarity: 'legendary', asset: null }
};

export function getAvatarVisualMeta(itemId){
  return AVATAR_VISUAL_REGISTRY[itemId] || null;
}
