# Shield King — Component Library

## 1. Scope

This document defines reusable visual building blocks for Mini Games World. Components consume `TOKENS.md`; they do not invent local colors, radii, typography or spacing.

A component may visualize product state, but it never becomes the authority for that state. Timers, loading, matchmaking, economy, readiness and game rules remain owned by their functional contracts.

---

# 2. Buttons

## 2.1 Shared button anatomy

```text
[ optional leading icon ] [ label ] [ optional trailing affordance ]
```

Rules:

- minimum hit target: 44px high;
- default control radius: 12px;
- horizontal alignment: centered unless the component is explicitly a row/action button;
- icon and label gap: 8px;
- one primary action per local decision group whenever practical;
- icon-only buttons still expose a 44×44px hit target and require an accessible label.

### Standard sizes

| Size | Visible height | Horizontal padding | Label | Icon |
|---|---:|---:|---|---:|
| Small | 36px | 12px | `type.label` | 16px |
| Medium | 44px | 16px | `type.button` | 18px |
| Large | 52px | 20px | `type.button` | 20px |

Small visible buttons require a 44px interaction wrapper where touch input is possible.

## 2.2 Primary button

Purpose: highest-priority action in a surface.

Default:

- background: `gradient.ctaPrimary`;
- text: `color.text.primary`;
- border: none;
- shadow: none by default;
- radius: `radius.control`.

Use for: start/play/confirm/continue and equivalent main actions.

Do not use two equal primary buttons beside each other unless both choices are genuinely equal and the screen specification explicitly permits it.

## 2.3 Secondary button

Purpose: important but non-primary action.

- background: `color.bg.card`;
- text: `color.text.secondary`;
- border: `border.default`;
- radius: `radius.control`.

## 2.4 Tertiary / text button

Purpose: low-emphasis action.

- background: transparent;
- text: `color.brand.violet` or `color.text.secondary` according to emphasis;
- no persistent border;
- minimum 44px hit area.

## 2.5 Premium button

Purpose: premium/value-specific action only.

- background: `gradient.premiumGold`;
- text: `#17121F`;
- radius: `radius.control`;
- optional leading premium/currency icon: 18–20px.

Forbidden for ordinary navigation and ordinary gameplay CTAs.

## 2.6 Destructive button

Purpose: irreversible or materially destructive action such as confirmed surrender/account destructive action when such functionality exists.

Default contained variant:

- background: `color.overlay.scrim` over `color.state.error` tint or a dark card surface;
- text: `color.state.error`;
- border: `1px solid rgba(255,97,125,.46)`.

A fully error-colored background is reserved for the final destructive confirmation action, not the first entry point.

---

# 3. Icon buttons

Sizes:

| Variant | Visual icon | Hit target | Container |
|---|---:|---:|---:|
| Compact | 18px | 44px | 36px visible |
| Standard | 20px | 44px | 44px visible |
| Emphasis | 24px | 48px | 48px visible |

Default container:

- background: transparent or `color.bg.card` depending on context;
- radius: 12px;
- border: optional `border.default` when a visible control boundary is required.

Do not use an icon-only action where the semantic meaning is ambiguous without a label/tool-tip/accessibility name.

---

# 4. Chips

Purpose: compact filters/status selectors, not primary navigation.

Standard anatomy:

```text
[optional 16px icon] label [optional count]
```

- height: 36px;
- horizontal padding: 12px;
- gap: 6–8px;
- radius: `radius.circular`;
- type: `type.label`.

Default:

- background: `color.bg.card`;
- text: `color.text.secondary`;
- border: `border.default`.

Active:

- background: `color.overlay.violetTint` over `color.bg.cardSecondary`;
- text: `color.text.primary`;
- border: `1px solid #6A4CFF`;
- optional subtle `glow.violet` at reduced opacity.

Do not use gold for ordinary active filters.

---

# 5. Tabs

Purpose: switch peer views inside one product area.

## 5.1 Underline/rail tabs

Use when more than 3 peers may exist.

- min height: 44px;
- label: `type.label` or `type.button` for major tabs;
- inactive text: `color.text.muted`;
- active text: `color.text.primary`;
- active indicator: 2px `color.brand.primary`;
- tab gap: 20–24px.

## 5.2 Segmented control

Use for 2–3 mutually exclusive local modes such as room mode when product contracts support them.

- container height: 44px;
- container background: `color.bg.card`;
- border: `border.default`;
- container radius: 12px;
- internal padding: 4px;
- segment radius: 8px;
- active background: `color.bg.cardSecondary`;
- active border: `1px solid rgba(106,76,255,.70)`;
- active text: `color.text.primary`;
- inactive text: `color.text.muted`.

No horizontal overflow for a 2–3 segment contract; labels must be designed to fit the available width.

---

# 6. Cards

## 6.1 Shared card shell

- background: `color.bg.card`;
- border: `border.default`;
- radius: `radius.card`;
- shadow: `shadow.card` only when elevation is semantically useful; flat lists may omit shadow;
- default padding: 16px mobile, 20px compact/desktop where space allows;
- internal vertical rhythm: 12–16px.

Cards must not each invent custom purple backgrounds. Use semantic variants below.

## 6.2 Standard card

Use: grouped information/actions.

- background: `color.bg.card`;
- border: `border.default`.

## 6.3 Game card

Use: game catalogue/discovery.

Anatomy:

```text
[game icon/art region]
[game name]
[short metadata/status if authoritative]
[optional action/status affordance]
```

- min card width in grids: screen-spec dependent;
- icon/art region: 48–72px depending density;
- selected/active game may use `gradient.activeGlow` contained within the card;
- primary game identity accent must not override shared text hierarchy.

## 6.4 Room card

Use: Match/Gold room presentation.

Anatomy:

```text
room name + semantic badge
short product description
stake/value summary if authoritative
primary action
optional rules/help entry
```

- default room: standard card language;
- Gold room: may use restrained gold tint/border, never full gold background by default;
- room business rules are supplied by product contracts, never inferred from card styling.

## 6.5 Balance / economy card

Use only for authoritative balances.

- amount: `type.h2` or `type.numeric` depending context;
- currency icon: 20–24px;
- label: `type.caption`/`type.label`;
- gold treatment only for premium/gold currency semantics;
- free/standard currency must not look premium merely because it has value.

## 6.6 Information card

Use: rules, tips, non-blocking information.

- leading info icon: 18–20px;
- background: `color.bg.card` plus `color.overlay.violetTint` where emphasis is needed;
- border remains restrained.

## 6.7 Notification card

Use: notification item/surface.

- unread: 3–4px violet marker or contained unread badge plus slightly stronger surface;
- read: standard card/list surface;
- notification meaning comes from text/icon, not unread glow alone.

## 6.8 Result card

Use: victory/loss/draw/timeout presentation inside common result system.

- victory: controlled gold accent allowed;
- loss: neutral dark treatment with outcome icon/text; not a red error card;
- draw: silver/neutral treatment;
- timeout: warning/system treatment distinct from draw.

---

# 7. List rows

Purpose: settings, profile actions, rules/history entries.

- minimum height: 52px;
- horizontal padding: 12–16px;
- leading icon: 20px;
- title: `type.body` or `type.label` depending density;
- secondary copy: `type.bodySmall`/`type.caption`;
- trailing chevron/action: 18px;
- separators: `color.border.separator` when rows share one parent surface.

Do not wrap every settings row in its own glowing card.

---

# 8. Inputs

Inputs are specified visually only where product screens actually use them.

## 8.1 Text/search input

- min height: 48px;
- padding: 12px 14px;
- radius: `radius.control`;
- background: `color.bg.elevated` or `color.bg.card` depending parent;
- border: `border.default`;
- value text: `type.body` + `color.text.primary`;
- placeholder: `type.body` + `color.text.muted`;
- leading search icon where applicable: 20px;
- clear action: 18px icon inside 44px hit area.

Focus:

- border: `#A65FF7`;
- focus ring: `border.focus` with 2px offset when environment supports it.

Error:

- border: `color.state.error`;
- helper/error copy: `type.caption`, `color.state.error`;
- error must be text/icon-supported, never red border only.

## 8.2 Numeric input

Use only when product contract calls for manual numeric entry.

- same shell as text input;
- numeric value: `type.body` or numeric style for large values;
- min/max/step validation belongs to functional implementation.

---

# 9. Checkbox

- visual box: 20×20px;
- hit target: 44×44px minimum;
- radius: 6px;
- border: `border.default`;
- unchecked background: transparent/dark;
- checked background: `color.brand.primary`;
- check icon: 14px, `color.text.primary`;
- label gap: 10px;
- label: `type.bodySmall` or `type.body`.

Disabled state must remain legible and distinguish checked-disabled from unchecked-disabled.

---

# 10. Toggle / switch

- track: 44×24px;
- thumb: 20px;
- hit target: 44×44px;
- off track: `color.state.disabledBg` with border;
- off thumb: `color.text.muted`;
- on track: `color.brand.primary`;
- on thumb: `color.text.primary`;
- animation: `motion.standard`.

The switch visual state reflects authoritative setting state; it does not optimistically fake completion if persistence fails.

---

# 11. Avatars / player identity

## 11.1 Avatar sizes

| Size | Avatar | Typical use |
|---|---:|---|
| Small | 32px | compact list/nav identity |
| Medium | 44px | cards, player rows |
| Large | 72px | profile/player emphasis |
| Hero | 96px | profile hero only |

- radius: circular;
- fallback: neutral Shield King-compatible placeholder, not fake user initials unless product supplies them;
- image crop: centered cover.

## 11.2 Online state

- indicator: 8px small avatar, 10px medium+, with 2px dark surface separation ring;
- online color: `color.state.success`;
- offline: neutral/muted marker only when offline semantics are actually relevant.

## 11.3 Current-turn highlight

- avatar/container ring: 2px `color.brand.primary`;
- optional subtle centered violet glow;
- pair with explicit turn label/indicator when needed;
- never imply rank, premium, winner or readiness from the same ring.

## 11.4 Opponent/current player

Visual ordering may identify sides, but labels/states must come from game/match data. Do not use fake rank crowns or status badges.

---

# 12. Badges

## 12.1 Status badge

- height: 24px;
- horizontal padding: 8px;
- radius: circular;
- type: `type.caption`;
- optional icon: 14px.

Semantic variants:

- info/selected: violet tint + violet text/highlight;
- success/online: success tint + success icon/text;
- warning: warning tint;
- premium: gold tint + dark/gold-readable text;
- error: error tint only for actual errors.

## 12.2 Notification count badge

- minimum: 18×18px;
- padding for 2+ digits: 5px horizontal;
- type: 11–12px semibold equivalent to caption family;
- background: `color.brand.primary` by default;
- text: white;
- `99+` is preferred visual cap unless product semantics require another explicit cap.

Do not place badges where they obscure the base icon silhouette.

---

# 13. Progress

## 13.1 Determinate progress bar

Use only when product/backend exposes meaningful progress.

- track height: 6px;
- radius: circular;
- track: `color.state.disabledBg`;
- fill: `color.brand.primary` or `gradient.ctaPrimary`;
- minimum visible progression should reflect authoritative value exactly enough for the product purpose.

Do not manufacture fake 0–100 match preparation progress.

## 13.2 Indeterminate loading indicator

- visual diameter: 24px inline, 32px standard, 48px large surface;
- stroke: 2–3px;
- active color: `color.brand.primary` / violet highlight;
- no percentage label unless a percentage actually exists.

## 13.3 XP / level progress

Visual pattern may reuse determinate progress **only if** XP/level is an authoritative product feature. Until then, no XP component should appear in real screens as if functional.

---

# 14. Timers / countdown

Timer component is visual only.

## 14.1 Compact timer

- min container height: 36px;
- numeric style: `type.numeric` scaled to 20–24px where compact;
- tabular numerals;
- icon: optional 16–18px;
- background: dark neutral card/pill;
- active border/accent: violet.

## 14.2 Gameplay timer

- numeric style: `type.numeric` 28/32;
- min width sized to prevent digit-width layout shift;
- active player timer gets explicit active treatment;
- inactive/waiting timer remains readable but lower emphasis;
- warning threshold uses warning color plus icon/shape/pulse, never color only;
- expired state is visually frozen/complete only when authoritative timing state says expired.

## 14.3 Shared countdown number

Detailed system-state choreography belongs to DS-5. Component foundation:

- Display typography at screen center;
- white/silver number with restrained violet/gold accent;
- centered scale/fade pulse;
- no local timing ownership.

---

# 15. Modal

- desktop max width: 480px standard, 640px information-heavy;
- mobile: viewport width minus 32px standard gutters;
- radius: `radius.modal`;
- background: `color.bg.card`;
- border: `border.default`;
- shadow: `shadow.modal`;
- scrim: `color.overlay.scrim`;
- padding: 20px mobile, 24px desktop;
- title: H3/H2 according to importance;
- close icon: 20px inside 44×44px hit target.

Action layout:

- mobile: primary action normally full width, secondary beneath/above according to flow;
- desktop: actions may align to end horizontally when labels fit.

Modals are not default navigation containers; use them for contained decisions/information.

---

# 16. Bottom sheet

Use primarily on mobile/Telegram/Android viewport where sheet interaction is appropriate.

- top radius: 20px;
- background: `color.bg.card`;
- border-top: `border.default` where needed;
- padding: 20px plus bottom safe-area inset;
- drag handle: 36×4px, muted neutral, centered;
- max height: screen/state dependent, content scrolls inside sheet rather than body behind it.

Desktop should generally map the same semantic surface to modal/popover when appropriate rather than stretching a bottom sheet across a wide screen.

---

# 17. Toast / transient message

- max width: min(420px, viewport gutters);
- min height: 44px;
- padding: 12px 16px;
- radius: 12px;
- background: elevated/card dark;
- border: semantic or default;
- icon: 18px;
- label/body: 14–16px;
- no essential one-time information may exist only in a rapidly disappearing toast.

Semantic styles: success, info, warning, error. Game loss is not an error toast.

---

# 18. Empty state

Anatomy:

```text
[optional restrained icon/illustration]
title
supporting copy
[optional primary/secondary action]
```

- container aligned to screen context;
- icon: 40–64px;
- title: H3;
- copy: body secondary;
- max copy width: 420px;
- decoration must not imply nonexistent data or achievements.

---

# 19. Error surface

## Inline error

- icon: 18px;
- title/body: body small/label;
- color: error + readable neutral text;
- retry action if the functional contract supports retry.

## Full-surface retryable error

- centered/contained error state;
- icon: 48px;
- title: H2/H3;
- copy: body;
- primary retry button;
- optional secondary navigation action.

Do not expose developer/network implementation details in user copy by default.

---

# 20. Navigation item foundation

Exact icon assets and active icon behavior are finalized in DS-2.

Visual contract:

- minimum hit area: 48×48px;
- icon: 22–24px;
- label: `type.caption`/`type.label` depending shell;
- inactive: `color.text.muted`;
- active: `color.text.primary` + `color.brand.primary` icon/accent;
- badge positioned outside core icon silhouette;
- active state must be readable without glow alone.

Bottom navigation placement/layout is specified at the screen/app-shell level later.

---

# 21. Component composition rules

- Components must not duplicate the same state indicator redundantly unless accessibility/clarity requires it.
- One card may contain several components, but nested bordered cards should be rare.
- Avoid card-inside-card-inside-card stacking.
- Primary actions remain visually stronger than secondary actions.
- Gold semantics remain scarce.
- Game-specific accent styling is contained inside game components and never fragments the shared shell.
- Product behavior that does not exist may be visually specified but must be marked as a dependency before appearing in implementation.

---

# 22. DS-1 component coverage

Covered reusable families:

- primary/secondary/tertiary/premium/destructive buttons;
- icon buttons;
- chips;
- tabs/segmented controls;
- standard/game/room/balance/info/notification/result cards;
- list rows;
- text/search/numeric inputs;
- checkbox;
- toggle;
- avatars/player identity;
- badges;
- determinate/indeterminate progress;
- timers/countdown foundation;
- modal;
- bottom sheet;
- toast;
- empty state;
- error surface;
- navigation-item foundation.
