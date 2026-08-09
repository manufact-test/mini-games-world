# Shield King — Component State Contract

## 1. Purpose

This document defines how reusable Shield King components visually respond to interaction and authoritative product state.

State ownership rule:

```text
visual component renders state
≠
visual component owns state
```

A pressed animation may be local visual feedback. Match readiness, timers, balances, game outcomes, network status and persisted settings must come from their functional owners.

---

# 2. Global interaction-state vocabulary

Use these names consistently:

```text
default
hover
pressed
focus
selected / active
disabled
loading
success
warning
error
```

Not every component supports every state.

### Precedence

When several conditions overlap, resolve visual priority in this order unless a specific component says otherwise:

```text
disabled
→ loading
→ error/warning/success semantic state
→ pressed
→ focus
→ selected/active
→ hover
→ default
```

Focus may remain visible together with selected state when keyboard navigation requires it.

---

# 3. Button states

## Primary

### Default

- `gradient.ctaPrimary`;
- white text;
- no glow required.

### Hover

Desktop/web only:

- slightly stronger highlight through overlay equivalent to `rgba(255,255,255,.06)`;
- no geometry shift.

### Pressed

- visual scale: `0.98` maximum;
- dark overlay: `rgba(4,5,9,.10)`;
- transition: `motion.fast`;
- never move surrounding layout.

### Focus

- keep default/selected fill;
- add focus ring `2px #A65FF7`, offset 2px.

### Disabled

- background: `color.state.disabledBg`;
- text/icon: `color.state.disabledFg`;
- no glow/gradient;
- pointer/touch affordance visually removed.

### Loading

- preserve button width/height;
- replace or accompany label with 18px loading indicator according to screen contract;
- prevent double-submit visually and functionally;
- do not change to success until authoritative action completion.

## Secondary

Hover: border becomes `rgba(166,95,247,.65)` and text brightens.

Pressed: `color.bg.cardSecondary` with `motion.fast`.

Focus: standard focus ring.

Disabled: disabled colors, no hover.

Loading: preserve size; spinner uses violet/silver.

## Tertiary

Hover: violet-tinted background at low opacity.

Pressed: stronger violet tint.

Focus: focus ring or clearly visible underline/container according to placement.

Disabled: muted text, no tint interaction.

## Premium

Hover: gold highlight may brighten slightly; no large glow.

Pressed: 0.98 scale + subtle dark overlay.

Disabled: normal disabled palette, not faded gold.

Loading: dark spinner/readable label over retained premium surface where contrast remains sufficient.

## Destructive

Hover/pressed may increase error tint, but the first destructive entry point should remain restrained.

Final destructive confirmation may use stronger error surface only after explicit user intent.

---

# 4. Icon-button states

### Default

- icon: secondary text/silver;
- container: transparent or dark card.

### Hover

- low violet tint background;
- icon becomes primary text.

### Pressed

- stronger tint;
- optional 0.96–0.98 scale on icon/container.

### Focus

- visible focus ring around 44px hit container.

### Selected

- icon: primary/violet;
- selected background: violet tint;
- do not substitute an unrelated active icon artwork.

### Disabled

- disabled foreground;
- no hover/pressed animation.

---

# 5. Chip states

### Default

Dark card + default border + secondary text.

### Hover

Border brightens to `rgba(166,95,247,.55)`.

### Pressed

Background deepens to cardSecondary.

### Active

Violet tint/cardSecondary + purple border + primary text.

### Active + focus

Active visuals remain; focus ring is additive outside.

### Disabled

Disabled surface/foreground, no active glow.

---

# 6. Tabs / segmented control states

## Tab

Default: muted label, no indicator.

Hover: secondary text.

Pressed: temporary violet tint or label brightening.

Active: primary text + 2px purple indicator.

Focus: visible focus ring/outline around the tab hit area while preserving active indicator.

Disabled: disabled foreground; no indicator unless the product intentionally exposes a disabled current view, which should be avoided.

## Segmented control

Default/inactive: muted text.

Hover: low violet tint.

Pressed: cardSecondary.

Active: cardSecondary + purple border + primary text.

Disabled segment: disabled foreground; active selection must not silently jump merely because another segment becomes disabled.

---

# 7. Card states

Cards are not automatically interactive.

## Static card

No hover/pressed behavior.

## Clickable card

### Default

Standard shell.

### Hover

- border brightens slightly;
- optional `translateY(-1px)` desktop only;
- shadow may increase minimally.

### Pressed

- return to y=0;
- darken/tint surface;
- no strong scale on large cards.

### Focus

Visible focus ring around entire interactive card.

### Selected

- purple 1px border;
- contained active glow optional;
- explicit selected indicator where selection meaning matters.

### Disabled

- reduced text/icon emphasis;
- disabled surface;
- no hover lift/glow.

## Game card special state

`active match` and `selected in catalogue` are separate semantics and must not share one ambiguous badge. The card may show both only when the screen contract defines clear labels.

## Result card

Result semantic styling overrides hover/selection decoration because result is an outcome, not a selectable card state.

---

# 8. Input states

## Empty/default

- default border;
- placeholder muted.

## Filled

- default border;
- value primary text.

## Hover

Desktop: border slightly brighter.

## Focus

- violet border;
- focus ring;
- placeholder/value remains readable.

## Invalid/error

- error border;
- error icon/helper copy;
- preserve entered value unless product logic explicitly resets it.

## Valid/success

Only show success state when validation success is useful. Do not decorate every normal field green.

## Disabled

- disabled surface and foreground;
- value remains legible;
- no focus/hover.

## Loading/async validation

- optional small inline spinner;
- do not show success until authoritative validation completes.

---

# 9. Checkbox states

```text
unchecked
unchecked-hover
unchecked-focus
checked
checked-hover
checked-focus
indeterminate (only if product uses it)
disabled-unchecked
disabled-checked
error (validation context only)
```

Checked uses primary purple background and white check.

Indeterminate uses the same checked shell with a centered horizontal mark; never infer indeterminate from partial opacity.

Error validation must include text/helper context.

---

# 10. Toggle states

```text
off
on
pressed-off
pressed-on
focus-off
focus-on
disabled-off
disabled-on
pending-save
save-failed
```

### Pending-save

Preferred behavior:

- keep requested visual position only if product implementation uses optimistic state and can revert safely;
- show subtle pending indicator outside the thumb/track when persistence duration is perceptible.

If product uses pessimistic persistence, keep the authoritative state until save succeeds.

The design system does not choose the persistence model; screen/functional contract must specify it.

### Save-failed

- restore/retain authoritative state;
- pair error messaging with the setting row/toast;
- do not leave the switch visually lying about persisted state.

---

# 11. Avatar / identity states

## Image loaded

Normal avatar image.

## Image unavailable

Use neutral approved placeholder. Do not fabricate profile content.

## Online

Success indicator + accessible/status text where context requires it.

## Offline

Muted indicator only when meaningful.

## Current turn

Purple ring + explicit turn indicator/label where ambiguity is possible.

## Winner

Outcome indicator may add controlled gold after authoritative result.

Do not use the same crown/gold ring before result in a way that implies rank or winner.

## Opponent disconnected / connection issue

Use network/status treatment defined by DS-5 rather than converting avatar to a generic error state.

---

# 12. Badge states

Badges normally have semantic state rather than interaction state.

```text
info
selected
success / online
warning
premium
error
unread count
```

Rules:

- premium gold ≠ success;
- unread purple ≠ error;
- warning ≠ timeout until product state says timeout;
- zero-count notification badge is hidden unless zero itself has product meaning.

---

# 13. Progress states

## Determinate

- value 0–100% only from authoritative progress;
- visual fill tracks value;
- complete state may transition to success after authoritative completion.

## Indeterminate

- animated loop;
- no fake numeric percent;
- no implied duration.

## Paused/waiting

If product state distinguishes waiting from loading, use static/low-motion waiting treatment and explicit copy/status rather than a perpetual fake progress animation.

## Error

Stop/replace progress with error state only when the operation has actually failed.

---

# 14. Timer states

```text
inactive
active
warning
expired
paused/waiting (only if authoritative contract has this state)
```

## Active

- full primary text;
- violet active indicator;
- tabular numerals.

## Inactive

- secondary text;
- dark neutral shell;
- remains readable.

## Warning

- warning color plus icon/ring/pulse;
- pulse must respect reduced motion;
- threshold is supplied by product/game contract.

## Expired

- show expired/zero only when clock owner reports it;
- visual animation stops;
- no client-side transition to result by design alone.

---

# 15. Modal / sheet states

## Opening

- backdrop fade: standard/emphasis motion;
- surface enter: emphasis easing;
- reduced motion: short fade only.

## Open

- background content non-interactive according to platform contract;
- focus trapped on keyboard-capable web where appropriate.

## Submitting

- primary action enters loading;
- prevent repeated action;
- surface remains stable.

## Error

- keep user context/data where safe;
- show error copy/surface;
- allow retry/correction.

## Closing

No state mutation should be implied solely from closing animation.

---

# 16. Toast states

Semantic variants:

- info;
- success;
- warning;
- error.

Interaction:

- may include one concise action when needed;
- dismiss button uses icon-button rules;
- timeout duration is implementation/accessibility-owned, not defined here.

Critical error or match result must not rely on toast alone.

---

# 17. Empty / loading / error relationship

These three are mutually distinct screen/content states:

```text
loading = data/state not resolved yet
empty   = successfully resolved, nothing to show
error   = resolution/action failed
```

Never use an empty state to mask an error or loading state.

---

# 18. Navigation-item states

Exact icons are DS-2 assets.

```text
inactive
hover (desktop)
pressed
active
focus
disabled (rare)
active + unread badge
inactive + unread badge
```

Active:

- purple/primary icon treatment;
- primary label;
- optional contained active indicator;
- never rely solely on a large glow.

Unread badge is additive and does not change the semantic destination icon.

---

# 19. Reduced motion

When `prefers-reduced-motion` or equivalent platform preference is active:

- remove continuous decorative glow/pulse movement;
- replace scale-heavy transitions with short opacity transitions;
- keep state transitions immediate and understandable;
- countdown still displays authoritative numbers/states; only decoration changes;
- loading remains visually distinguishable without requiring spinning motion alone where practical.

---

# 20. State anti-patterns

Do not:

- show loading after success has already been confirmed;
- show success because a button was merely pressed;
- turn disabled controls into visually active controls on hover;
- use red for ordinary loss outcomes;
- show gold to imply premium/winner/rank without authoritative semantics;
- start/finish timers locally from component animation;
- use fake 0–100 progress to cover backend waiting;
- use visual selection to mutate product state without the functional owner;
- add different active icon artwork that changes the underlying icon meaning;
- hide errors as empty states.

---

# 21. DS-1 state coverage checklist

- Buttons: default/hover/pressed/focus/disabled/loading — YES
- Chips/tabs/segmented: default/active/pressed/focus/disabled — YES
- Cards: static/interactive/selected/disabled/result — YES
- Inputs: default/filled/focus/error/disabled/loading — YES
- Checkbox/toggle: complete interaction/state map — YES
- Avatar/player identity: online/offline/current-turn/result — YES
- Badges: semantic variants — YES
- Progress: determinate/indeterminate/waiting/error — YES
- Timers: inactive/active/warning/expired — YES
- Modal/sheet/toast: open/loading/error/dismiss behavior — YES
- Navigation items: inactive/active/focus/badged — YES
- Reduced motion — YES
- Runtime ownership preserved — YES
