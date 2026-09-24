import fs from 'node:fs';

const profile = fs.readFileSync('app/assets/js/screens/profile-screen-v110.js', 'utf8');
const profileCss = fs.readFileSync('app/assets/css/screens/profile-corrective.css', 'utf8');
const mainCss = fs.readFileSync('app/assets/css/main.css', 'utf8');
const mobileProfileCss = fs.readFileSync('app/assets/css/production-v108-profile-entry-preview-live-owner-checkers-fit.css', 'utf8');
const cleanEntryWrapper = fs.readFileSync('app/assets/js/production-clean-entry-v110-mvp19-3-final-polish-mobile-nav-v2.js', 'utf8');
const mobileAnimationGuard = fs.readFileSync('app/assets/js/profile/mgw-mobile-profile-animation-guard-v2.js', 'utf8');
const manifest = fs.readFileSync('app/runtime/client/version-manifest.php', 'utf8');
const inventory = fs.readFileSync('bot/catalog/ProductInventoryService.php', 'utf8');
const storeService = fs.readFileSync('bot/catalog/CosmeticStoreService.php', 'utf8');
const endpoint = fs.readFileSync('bot/cosmetic-store.php', 'utf8');

function expect(condition, message) {
  if (!condition) throw new Error(message);
}

expect(profile.includes("{ layer:'theme', title:'Поля' }"), 'Profile must group owned game fields');
expect(profile.includes("{ layer:'elements', title:'Знаки' }"), 'Profile must group owned game marks');
expect(profile.includes("{ layer:'effect', title:'Эффекты' }"), 'Profile must group owned game effects');
expect(profile.includes("item.owned === true && item.item_type === 'game'"), 'Profile collection must display owned game items only');
expect(profile.includes('function ownedGameCosmeticGames()'), 'Profile must group owned cosmetics by game before rendering');
expect(profile.includes("const explicit = String(metadata.game_type || '').trim();"), 'Profile game grouping must prefer canonical metadata game_type');
expect(profile.includes("return family.startsWith('game_') ? family.slice(5) : '';"), 'Profile game grouping must retain a family fallback for rollout compatibility');
expect(profile.includes('data-profile-game-tab'), 'Profile must expose horizontal per-game collection tabs');
expect(profile.includes("activeCollectionGame = 'tictactoe'"), 'Profile must keep a deterministic first game selection');
expect(profile.includes('state.profileInventory.equipped'), 'selected state must come from the canonical inventory snapshot');
expect(profile.includes('api.cosmeticStoreEquip(itemId)'), 'Profile must reuse the existing canonical game-cosmetic equip transport');
expect(profile.includes('api.cosmeticStoreUnequip(slot)'), 'Profile must reuse the existing canonical game-cosmetic unequip transport');
expect(profile.includes('applyProfileResponse(await api.profileV2())'), 'Profile mutation must converge back to authoritative Profile inventory');
expect(profile.includes('state.profileInventory = previousInventory'), 'failed optimistic mutation must restore the previous inventory snapshot');
expect(!profile.includes('cosmeticStorePurchase('), 'Profile collection must not become a purchase owner');
expect(profile.includes('store-v2-game-preview'), 'Profile must reuse the accepted Store/game cosmetic preview artwork classes');
expect(profile.includes('ttt-effect-mark ttt-fx-${safeVariant}'), 'Profile effect preview must reuse canonical effect class identities');
expect(profile.includes("'winning-line':'sparks'"), 'Profile preview must tolerate rollout-era Sparks metadata');
expect(profile.includes("'move-pulse':'wave'"), 'Profile preview must tolerate rollout-era Wave metadata');
expect(!profile.includes('Игровая косметика'), 'unclear game-cosmetics wording must not be visible in Profile');

// Game collection tabs are a bounded panel switch, never a full Profile remount.
// Replacing #profileV2Root destroys the focused tab and lets mobile WebView move
// the document to recover focus. Keep the surrounding Profile DOM/scroll owner
// stable and replace only the game panel contents.
const gameTabBranchStart = profile.indexOf("const gameTab = event.target.closest('[data-profile-game-tab]');");
const gameTabBranchEnd = profile.indexOf("const gameCosmeticCard = event.target.closest('[data-profile-game-cosmetic]');", gameTabBranchStart);
const gameTabBranch = gameTabBranchStart >= 0 && gameTabBranchEnd > gameTabBranchStart
  ? profile.slice(gameTabBranchStart, gameTabBranchEnd)
  : '';
expect(gameTabBranch.includes('switchProfileGameCollection(nextGame)'), 'Profile game-tab click must delegate to the bounded panel switch owner');
expect(!gameTabBranch.includes('renderProfileV2()'), 'Profile game-tab click must not rebuild the full Profile DOM');
expect(profile.includes('function switchProfileGameCollection(nextGame)'), 'Profile must own a bounded game-panel switch helper');
expect(profile.includes("const panel = collection?.querySelector('.profile-v2-game-panel');"), 'bounded game switch must target the existing game panel');
expect(profile.includes("button.setAttribute('aria-selected', active ? 'true' : 'false');"), 'bounded game switch must keep tab accessibility state in sync');
expect(profile.includes('panel.innerHTML = renderGameCosmeticGroups(activeGame);'), 'bounded game switch must replace only the active game panel contents');
expect(!profile.includes('active_collection_game'), 'game-tab selection must not participate in the full Profile render signature');
expect(!profile.includes('data-open-leaderboard') && !profile.includes('function openLeaderboardSheet('), 'Arena corrective must keep the full global leaderboard out of Profile without replacing the accepted game-cosmetics collection owner');
expect(profile.includes('renderYearlyMedalSection(yearlyMedals)'), 'MVP-20.6 may compose the compact yearly medal after personal rating without replacing the game-cosmetics collection owner');
expect(profile.includes('function renderYearlyMedalSection('), 'MVP-20.6 yearly medal must remain a bounded Profile section renderer');
expect(profile.includes('renderTournamentPrestigeSummary(tournamentRewards)'), 'MVP-21 prestige may compose a compact status near identity without replacing the accepted collection owner');
expect(profile.includes('data-open-tournament-showcase'), 'Tournament prestige detail must open outside the collection instead of nesting a second inventory owner');
expect(profile.includes("TOURNAMENT_HIDDEN_REWARD_CODES = new Set(['champion_cosmetics'])"), 'Undefined champion cosmetics must not masquerade as an owned game-cosmetic SKU');
expect(profile.includes('data-tournament-showcase-scroll'), 'Tournament prestige corrective must keep long reward detail inside its own bounded sheet scroller');
expect(!profile.includes('champion_cosmetics') || profile.includes("TOURNAMENT_HIDDEN_REWARD_CODES = new Set(['champion_cosmetics'])"), 'Tournament prestige corrective must not turn the deferred champion entitlement into a Profile inventory item');
expect(!profile.includes('ProductInventoryService') && !profile.includes('CosmeticStoreService'), 'yearly medal Profile composition must not create a second cosmetics inventory/store owner');

const openProfileStart = profile.indexOf('export function openProfile()');
const visibleProfile = profile.indexOf('showProfileImmediately();', openProfileStart);
const scheduledRefresh = profile.indexOf('scheduleProfileRefreshAfterEntry();', visibleProfile);
const refreshHelper = profile.indexOf('function scheduleProfileRefreshAfterEntry()');
const backgroundHydration = profile.indexOf('api.profileV2()', refreshHelper);
expect(openProfileStart >= 0 && visibleProfile > openProfileStart && scheduledRefresh > visibleProfile && refreshHelper >= 0 && backgroundHydration > refreshHelper, 'Profile must paint shared state immediately and schedule authoritative hydration after the route first frame');
expect(profile.includes('const event = arguments[0] || null;') && profile.includes("if (event?.type === 'mgw:open-profile') event.stopImmediatePropagation?.();"), 'topbar Profile intent must stay single-owner until the deferred first-paint route lifecycle');
expect(profile.includes('function warmProfileSnapshot()') && profile.includes('globalThis.setTimeout(warm, 0)'), 'Profile must start its read-only authoritative warm immediately after the boot task without blocking bootstrap');
expect(profile.includes('lastProfileRenderSignature') && profile.includes('renderSignature === lastProfileRenderSignature'), 'Profile must skip redundant full DOM rebuilds when authoritative state is unchanged');
expect(profile.includes('deferWhileHidden:true') && profile.includes('scheduleProfileRenderIdle()'), 'Background Profile hydration must converge through the bounded idle render owner');
expect(profile.includes('requestIdleCallback(flushScheduledProfileRender, { timeout:1800 })'), 'Long Profile refresh work must be scheduled as idle work instead of a route-transition task');
expect(profile.includes("document.documentElement.classList.contains('mgw-profile-route-settling')"), 'Idle Profile convergence must yield while the canonical first-route settle guard is active');
expect(profile.includes('navigator.scheduling.isInputPending({ includeContinuous:true })'), 'Idle Profile convergence must yield to pending user input when the runtime exposes the scheduling signal');
expect(!profile.includes('flushHiddenProfileRenderOnEntry') && !profile.includes('PROFILE_ROUTE_TRANSITION_MS + 40'), 'First Profile entry must never be the trigger for the deferred full-DOM rebuild');
expect(profile.includes('Date.now() - lastFullProfileSnapshotAt < 5000'), 'Fresh background Profile state must suppress an immediate duplicate hydration request');

// Mobile route performance: never key a universal descendant selector directly
// off #screen-profile.active/not(.active). That makes every Profile route flip
// invalidate style across the complete long collection. Modern clients use the
// bounded Web Animations lifecycle guard; only unsupported WebViews get CSS fallback.
expect(!mobileProfileCss.includes('\n  #screen-profile.screen:not(.active) *,'), 'mobile Profile route CSS must not invalidate the whole descendant tree on normal active flips');
expect(mobileProfileCss.includes('html.mgw-profile-animation-css-fallback #screen-profile.screen:not(.active) *'), 'legacy WebViews must retain a CSS-only hidden-animation fallback');
expect(cleanEntryWrapper.includes("import './profile/mgw-mobile-profile-animation-guard-v2.js?v=1';"), 'accepted clean-entry wrapper must retain the canonical Profile animation guard specifier');
expect(manifest.includes("'./assets/js/profile/mgw-mobile-profile-animation-guard-v2.js?v=1' => './assets/js/profile/mgw-mobile-profile-animation-guard-v2.js?v=2&profile_input=known-animation-set-v1'"), 'import-map owner must cache-bust the first-input Profile animation guard without changing clean-entry bytes');
expect(mobileAnimationGuard.includes("root.getAnimations({ subtree:true })"), 'mobile Profile animation guard may enumerate animations only during off-input discovery');
expect(mobileAnimationGuard.includes('const knownProfileAnimations = new Set();') && mobileAnimationGuard.includes('function pauseKnownAnimations()'), 'route input must operate on already-known Profile animation objects');
const routeIntentStart = mobileAnimationGuard.indexOf('function handleRouteIntent(event){');
const routeIntentEnd = mobileAnimationGuard.indexOf('function handleScreenChanged(event){', routeIntentStart);
const routeIntentBody = routeIntentStart >= 0 && routeIntentEnd > routeIntentStart ? mobileAnimationGuard.slice(routeIntentStart, routeIntentEnd) : '';
expect(routeIntentBody.includes('pauseKnownAnimations()') && !routeIntentBody.includes('pauseAnimations();'), 'pointer input must never force a full Profile subtree animation scan');
expect(mobileAnimationGuard.includes("document.addEventListener('mgw:app-ready'") && mobileAnimationGuard.includes("requestIdleCallback(prime, { timeout:700 })"), 'late Profile animations must be discovered off the first-tap path');
expect(mobileAnimationGuard.includes("profileObserver.observe(screen, { childList:true, subtree:true })"), 'new hidden Profile animations must be paused without observing route class changes on the Profile subtree');
expect(manifest.includes('profile_route_guard=animation-runtime-v2') && manifest.includes('profile_input=known-animation-set-v1'), 'active clean-entry identity must preserve the accepted guard identity while publishing the no-full-scan first-input cache key');
expect(manifest.includes('profile_mobile=animation-runtime-guard-v2'), 'active consistency CSS identity must preserve the non-universal mobile Profile animation guard');

expect(profileCss.includes('.profile-v2-game-collection'), 'Profile game collection layout must exist');
expect(profileCss.includes('.profile-v2-game-tabs{display:flex'), 'Profile must keep games in one horizontal selector row');
expect(profileCss.includes('overflow-x:auto'), 'future game tabs must scroll horizontally instead of stacking vertically');
expect(profileCss.includes('.profile-v2-game-tab.active'), 'active game tab must have an explicit selected state');
expect(profileCss.includes('.profile-v2-game-card.active'), 'equipped game item must have an explicit selected state');
expect(profileCss.includes('.profile-v2-game-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))'), 'mobile Profile game items must remain compact');
expect(profileCss.includes('.store-v2-game-preview'), 'Profile layout may size but must not duplicate Store cosmetic artwork');
expect(mainCss.includes('profile-corrective.css?v=7&mvp19=profile-collection&mvp19_3=game-tabs-fresh&fresh-selection&ttt-mark=css'), 'active CSS graph must publish compact game tabs and stable Tic Tac Toe mark geometry');
expect(manifest.includes('mvp19_3_3=game-tabs-panel-only-v1'), 'active Profile runtime identity must publish bounded game-panel switching');
expect(manifest.includes('perf=stable-render-cache'), 'active Profile runtime must publish stable render-cache behavior while preserving immediate open/background refresh');
expect(manifest.includes('mvp19_3=profile-game-tabs-fresh'), 'active main CSS identity must publish Profile game-tab polish');

expect(inventory.includes('public function equip(string $mgwId, string $itemId): array'), 'ProductInventoryService must remain the equip owner');
expect(inventory.includes('public function unequip(string $mgwId, string $equipSlot): array'), 'ProductInventoryService must remain the equip owner');
expect(endpoint.includes('$store->equipGameItem($mgwId'), 'existing game cosmetic endpoint must retain its bounded game-item validation path');
expect(storeService.includes('return $this->inventory->equip($mgwId, $itemId);'), 'game-item validation path must delegate equip to ProductInventoryService');
expect(endpoint.includes('$inventory->unequip($mgwId, $equipSlot)'), 'existing game cosmetic endpoint must delegate unequip to ProductInventoryService');

console.log('MVP-19.3 Profile-owned game cosmetics polish contract: OK');