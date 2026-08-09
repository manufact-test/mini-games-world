# Shield King — Exact Icon System

## 1. Purpose

This document defines the implementable icon contract for the shared Mini Games World UI.

Core UI icons use a single vector language. They must not be replaced screen-by-screen with unrelated icon packs.

---

## 2. Core geometry

### UI icon grid

- source viewBox: `0 0 24 24`;
- default stroke: `1.75`;
- line cap: `round`;
- line join: `round`;
- optical safe area: normally keep essential strokes inside x/y `2–22`;
- default fill: `none` unless a semantic dot/pip/solid indicator is required;
- source color: `currentColor` so the design-system state controls active/inactive color without alternate artwork.

### Default rendered sizes

```text
16px — compact metadata/status
18px — small actions/inputs
20px — standard actions/list rows
22px — compact navigation
24px — primary navigation / emphasized actions
```

Hit target is owned by the component and remains at least 44×44px for touch controls.

---

## 3. Visual character

Core icons are:

- geometric;
- clean;
- slightly rounded;
- medium-weight rather than hairline;
- readable on near-black/dark-violet surfaces;
- not skeuomorphic;
- not filled neon glyphs by default.

The premium/game identity comes from controlled purple/gold/silver state treatment and the eight game icons, not by making every UI icon ornate.

---

## 4. Active / inactive behavior

The same semantic icon artwork is reused across states.

### Inactive

- color: `color.text.muted`;
- no glow.

### Hover

- color: `color.text.secondary` or `color.text.primary`;
- optional low violet-tint component background.

### Active / selected

- color: `color.brand.primary` or `color.text.primary` with an explicit purple component indicator;
- optional contained violet tint/glow supplied by the component;
- icon geometry does not change into unrelated artwork.

### Disabled

- color: `color.state.disabledFg`;
- no glow/hover.

### Premium semantic icon

- may use `color.brand.gold` only when the semantic item itself is premium/gold currency/value;
- gold is not a generic selected color.

---

## 5. Sprite contract

Core UI assets are stored as semantic SVG symbol sprites:

```text
icons/navigation/navigation-icons.svg
icons/actions/action-icons.svg
icons/status/status-icons.svg
icons/economy/economy-icons.svg
```

Each `<symbol>` has its own `id` and `viewBox="0 0 24 24"`.

Future implementation may:

- extract individual SVGs during build;
- consume the symbols directly where supported;
- map them to native vector resources during a later integration gate.

It must not redraw or substitute them without updating this source-of-truth design system.

---

## 6. Bottom navigation contract

Bottom-navigation destinations must use exact semantic icons from the navigation sprite.

Default icon size: `24px`.

Compact/narrow size: `22px` only when the navigation shell specification requires it.

Label:

- inactive: muted;
- active: primary text;
- active icon/accent: primary purple;
- unread badge: component-level notification badge, positioned outside the core icon silhouette.

The active state does not use a different drawing.

Exact bottom-navigation destination set is owned by the later app-shell screen/navigation specification; this file supplies the approved icon choices for all required destinations.

---

## 7. Eight-game icon family

Game icons are separate exact SVG assets at `64×64` viewBox because they carry more identity than generic UI glyphs.

Shared construction:

- viewBox: `0 0 64 64`;
- outer tile: rounded dark card surface;
- outer border: Shield King border/violet treatment;
- primary game glyph: silver/light neutral;
- controlled purple and gold accents;
- no text required for recognition;
- no external font dependency;
- no excessive glow inside the asset;
- readable at `40–64px` rendered size in cards.

Files:

1. `game-tic-tac-toe.svg`
2. `game-four-in-a-row.svg`
3. `game-battleship.svg`
4. `game-checkers.svg`
5. `game-reversi.svg`
6. `game-chess.svg`
7. `game-go.svg`
8. `game-domino.svg`

Game-specific accent does not change the shared card/button/navigation language.

---

## 8. Accessibility / semantic rules

- Icon-only controls require an accessible semantic label.
- Do not rely on icon color alone for critical states such as error, warning, online status, result or current turn.
- Decorative icons should be hidden from accessibility APIs where appropriate.
- A status icon never fabricates a product state; it only renders authoritative state.
- `win`, `loss`, `draw` and `error` are separate semantics.
- `locked` and `disabled` are separate semantics.
- `notifications` and `unread badge` are separate layers.

---

## 9. Forbidden patterns

Do not:

- mix multiple unrelated stroke widths/styles;
- use emoji as production UI icons;
- use random filled Material/SF glyphs alongside this set without mapping/redesign;
- create separate active artwork that changes semantic shape;
- add individual drop shadows to small UI glyphs;
- use gold for every selected icon;
- recreate the primary Shield King logo as a generic navigation icon;
- place unread badges over the meaningful center of an icon.

---

## 10. DS-2 visual acceptance gate

DS-2 is manually accepted as a family, not icon-by-icon.

Review should judge:

- consistent geometry;
- readability at small size;
- active/inactive behavior;
- bottom-navigation coherence;
- eight game icons as one family;
- whether the overall character still matches premium dark Shield King rather than generic mobile UI.

If rejected, update the underlying family rule first, then update dependent assets consistently.
