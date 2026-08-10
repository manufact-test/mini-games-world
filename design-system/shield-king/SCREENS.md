# Shield King — Shared Screen Specifications

## Status

`DS-3 SCREEN SPECIFICATIONS — IMPLEMENTATION SPEC READY / VISUAL ACCEPTANCE PENDING`

This document defines the visual hierarchy of the shared Mini Games World product. It does not implement UI and does not create backend behavior.

The source product scope comes from the Shield King child roadmap and the approved full-app redesign handoff. Business/game/economy rules remain authoritative elsewhere.

---

# 1. Global app shell

## Purpose

Provide one shared visual shell for Telegram/Web and Android WebView inheritance.

## Structure

```text
safe-area / top inset
→ top bar
→ page content
→ optional persistent bottom navigation on primary destinations
→ overlays / sheets / system layers above content
```

## Top bar

- minimum visual height: 56px plus safe-area inset;
- compact brand mark only where useful, never giant repeated launcher art;
- page title uses H2/H3 depending density;
- back action only when navigation stack requires it;
- optional contextual action on trailing side;
- no decorative shield around every small toolbar icon.

## Bottom navigation

Core destinations may include Home, Games, Profile and other authoritative destinations established by the product/navigation contract.

Visual rules:

- ordinary DS-2 navigation glyphs without royal shield frames;
- 24px default icon;
- label 12–13px;
- inactive muted;
- active purple + primary label;
- compact active indicator/tint, not giant glow;
- unread badge is separate layer;
- safe-area bottom padding respected.

Do not freeze a destination set in design if the runtime product contract differs later; map existing authoritative destinations to this visual shell at integration time.

---

# 2. Home

## Purpose

Primary product overview and fastest path into a game room/game selection.

## Information order

1. compact user identity/header;
2. balances/status summary;
3. room selection: Match / Gold;
4. selected room summary and primary CTA;
5. game discovery / eight game cards;
6. relevant activity/status surfaces.

## Header

Compact profile identity:

- avatar 36–40px;
- display name if authoritative;
- optional online/presence indicator only when authoritative;
- notification entry on trailing side;
- no fake rank/level.

## Balances

Use Balance/Economy component.

- standard coin balance and gold/premium balance remain separate semantics;
- values are runtime-owned;
- gold accent only for actual gold/premium balance;
- no invented amount in design specification.

## Room selector

Segmented control:

```text
Match | Gold
```

Selected state uses primary violet indicator/tint.

Room business rules and texts are not changed here.

### Match room surface

- standard premium-dark card;
- room name + short authoritative explanation;
- stake/value row only if supplied by runtime contract;
- primary CTA to enter/select game/search according to current product flow;
- no fake opponent count.

### Gold room surface

- controlled premium gold accent around semantic currency/value;
- do not make entire screen gold;
- eligibility/balance/entry conditions shown only when authoritative.

## Game discovery

Eight game cards use the accepted equal-width royal DS-2 game icon family:

1. Tic Tac Toe
2. Four in a Row
3. Battleship
4. Checkers
5. Reversi
6. Chess
7. Go
8. Domino

Card anatomy:

```text
royal game icon
localized game name
optional short status/availability
optional disclosure/CTA
```

Do not resize Chess/Go/Domino independently: all icon source boxes are identical.

## Mobile layout

- 16px page gutter;
- room selector full width;
- balances can use 2-column compact row;
- game grid: 2 columns on normal mobile, 1 column only if narrow/accessibility layout requires;
- card content must not clip at 320px-class widths.

## Desktop/Telegram Web layout

- centered content max-width from Foundations;
- game grid may expand to 4 columns;
- no stretched ultra-wide cards;
- content hierarchy stays the same.

## States

Loading: skeleton/placeholder geometry only, no fake percentage.

Empty: only for authoritative no-data surfaces such as no activity.

Error: local retry surface if the page can retry safely; app/session failures use system-state contract later.

---

# 3. Game catalogue

## Purpose

Browse/select one of the eight supported games.

## Structure

- top bar: Games;
- optional search/filter only if product contract actually needs it;
- room context chip if selection is room-dependent;
- 2-column mobile / 4-column desktop card grid;
- same DS-2 royal icon size/bounding box across cards.

## Game card states

- available;
- pressed;
- selected when the flow explicitly selects before CTA;
- disabled/locked only when product contract supplies reason;
- loading if availability is being fetched;
- no fake “popular”, “new”, player counts, difficulty or ranking.

---

# 4. Profile

## Purpose

Present authoritative identity and statistics without inventing profile systems.

## Hierarchy

1. avatar / display identity;
2. balances shortcut if appropriate;
3. existing authoritative stats;
4. History entry;
5. Achievements entry only when authoritative;
6. profile/settings actions.

## Rules

- rank/level/XP visuals are `VISUAL SPEC READY / FUNCTIONAL CONTRACT PENDING` unless those fields exist;
- never display placeholder competitive status as user truth;
- empty stats use neutral empty-state copy.

---

# 5. Notifications

## Purpose

Central list of authoritative notifications.

## Row anatomy

- semantic icon/avatar;
- title;
- supporting message;
- timestamp if supplied;
- unread indicator;
- optional destination affordance.

## States

- loading skeleton;
- empty “no notifications” surface;
- unread/read;
- retryable error.

Unread state must not rely only on text weight; use a small violet indicator/tint.

---

# 6. Friends / social entry

`VISUAL SPEC READY / FUNCTIONAL CONTRACT DEPENDENT`

## Purpose

Provide a coherent social surface when current/future product contracts expose friends/invite functionality.

Possible authoritative sections:

- friends list;
- incoming/outgoing invite state;
- invite entry;
- online state where authoritative.

Do not fabricate friend suggestions, online counts or social graph behavior.

---

# 7. Settings

## Structure

Settings list/card groups using ordinary lightweight UI icons:

- account/profile;
- notifications;
- language;
- help/rules/legal;
- other sections only where authoritative.

Destructive actions use destructive component styling, not premium gold.

No setting appears merely because a concept mockup looks complete.

---

# 8. Store

`VISUAL SPEC READY / FUNCTIONAL CONTRACT PENDING WHERE ECONOMY IS NOT READY`

## Hierarchy

- balances header;
- category/navigation if needed;
- catalogue cards;
- item preview/details;
- purchase CTA;
- insufficient-balance state;
- success/failure result.

## Visual treatment

- premium gold is concentrated on actual premium value/purchase accents;
- catalogue surface remains dark/violet;
- product/cosmetic artwork may be more expressive but cannot redefine core components.

No fake products/prices are part of this design-system contract.

---

# 9. History

## Purpose

Show authoritative past records when available.

Row/card may include:

- game icon;
- outcome;
- opponent identity if supplied;
- room/stake if supplied;
- timestamp;
- destination/details if supported.

Victory/Loss/Draw are separate statuses.

Preparation timeout/cancellation is not represented as Draw.

---

# 10. Rules / game information

## Structure

- game identity header;
- rules content;
- scrollable sections;
- optional authoritative room/economy notes;
- back action.

Long copy prioritizes readability over decorative effects.

Game icon can appear smaller than Home hero usage but keeps its same aspect ratio/frame.

---

# 11. Invite flow surfaces

`VISUAL SPEC READY / FUNCTIONAL CONTRACT DEPENDENT`

Possible states:

- invite entry;
- invite sent;
- incoming invite;
- accepted;
- declined/expired;
- error.

Design never decides invite expiry or acceptance behavior.

---

# 12. Rematch surfaces

`VISUAL SPEC READY / FUNCTIONAL CONTRACT DEPENDENT`

Rematch CTA belongs primarily to Result when authoritative.

States may include:

- available;
- requested;
- waiting;
- accepted;
- declined/unavailable.

Do not use local visual waiting state as matchmaking owner.

---

# 13. Search / matchmaking

## Purpose

Present the authoritative search lifecycle without fake counts/progress.

## Structure

- selected room;
- selected game;
- optional stake/conditions supplied by runtime;
- branded waiting visual;
- Cancel action where product contract allows it.

## Copy

Use user-facing language such as searching/waiting/preparing.

Do not show technical synchronization/server/device terminology.

## State rule

No fake percentage. No local timer unless the runtime exposes an authoritative time value intended for display.

---

# 14. Match preparation

This surface visually bridges server-confirmed match to shared countdown.

Sequence is not owned here.

```text
match confirmed
→ preparation layer
→ authoritative readiness
→ both ready
→ authoritative starts_at
→ 3 / 2 / 1
→ gameplay reveal
```

Visual anatomy:

- selected game royal icon;
- player A / player B identity;
- short preparation message;
- restrained Shield King motion;
- connection/retry messaging only when authoritative.

No fake progress bar.

Detailed Phase B visual state rules are finalized in DS-5.

---

# 15. Shared countdown

Full-screen/system-layer style rather than an arbitrary card.

- large numeric countdown typography;
- centered composition;
- subtle violet pulse / controlled gold final accent allowed;
- same background family as preparation;
- no locally-owned timing;
- game surface remains unrevealed until authoritative transition.

Detailed animation timing is DS-5 visual-only specification.

---

# 16. Gameplay shell

## Shared structure

```text
safe-area
player A identity/status
match context / optional timer region
player B identity/status
turn indicator
main game surface
secondary actions
system/connection message layer
```

Actual game-board visuals are DS-4.

## Player identity

- avatar/name where authoritative;
- current-turn highlight uses controlled violet outline/glow;
- opponent/current user are visually distinguishable by layout, not invented rank badges.

## Timer

- numeric style from Foundations;
- active state, warning state, expired presentation supported;
- server/runtime remains clock owner.

## Actions

Possible surface actions such as surrender/rules depend on existing product contract.

Destructive action is visually clear but not overbearing.

---

# 17. Result

## Common hierarchy

1. outcome icon/title;
2. game/opponent context;
3. authoritative result/reward/stake information if supplied;
4. primary next action;
5. rematch secondary action when available;
6. back/home action.

## Outcome language

Victory:

- controlled gold celebratory accent;
- no casino confetti overload.

Loss:

- restrained neutral/rose treatment;
- not hostile red-heavy.

Draw:

- neutral silver/violet.

Preparation timeout:

- separate system result, never Draw.

Cancellation:

- separate neutral/system result.

---

# 18. Errors

## Local error

Used when one screen/request can retry independently.

Anatomy:

- error icon;
- plain-language title/message;
- Retry primary or secondary based on severity;
- no developer/server stack wording.

## Boundary/system error

Auth/session/offline/reconnect flows belong to DS-5 full-screen/system-state contract.

---

# 19. Empty states

Use only for meaningful zero-data conditions.

Anatomy:

- lightweight status/illustrative icon;
- concise title;
- one useful explanation;
- CTA only when there is a valid next action.

Do not use royal game shield as a generic empty-state decoration everywhere.

---

# 20. Offline / reconnect

Shared visual contract placeholder for DS-5:

- offline state clearly distinct from loading;
- reconnect state may show restrained indefinite motion;
- user retains understandable context where safe;
- no fake synchronized progress.

---

# 21. Responsive hierarchy

## Narrow mobile `≤359px`

- 12px page gutter;
- avoid 3+ column layouts;
- two game cards only if minimum readable card width is preserved, otherwise one column;
- controls retain 44px hit targets;
- long labels wrap, never clip horizontally.

## Mobile `360–639px`

- 16px gutter;
- default 2-column game grid;
- bottom navigation respects safe-area inset;
- modals that would overflow become bottom sheets/full-height sheets.

## Compact/tablet `640–959px`

- 20px gutter;
- 3–4 game columns based on available width;
- centered readable content, not edge-to-edge stretch.

## Desktop `≥960px`

- 24–32px inner gutters;
- content max-width from Foundations;
- 4-column game catalogue is default visual target;
- hover states enabled where pointer input exists.

---

# 22. Design-system rule

A future implementation must migrate screens by mapping existing product content/state to these components.

Do not:

- implement the mockup as one-off HTML/CSS per screen;
- invent backend fields to fill visual empty space;
- create Android-only screen variants;
- change room/game/economy rules to fit layout;
- let loading/countdown visuals become runtime state owners.
