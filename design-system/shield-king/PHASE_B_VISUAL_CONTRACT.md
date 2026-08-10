# Shield King — Phase B Visual Contract

## Status

`DS-5 PHASE B VISUAL CONTRACT — READY`

This file is visual-only. It must never become a lifecycle, readiness, polling or clock owner.

---

# 1. Authoritative lifecycle

The future Shield King skin must preserve the product sequence:

```text
server confirms match
→ global preparation/loading layer
→ authoritative readiness
→ both ready
→ shared starts_at
→ deterministic shared presentation 3 → 2 → 1
→ authoritative active state
→ gameplay reveal
```

If server readiness takes longer than the local presentation sequence, the UI stays in a neutral preparation/synchronizing visual state until authoritative readiness is available.

The visual layer never skips ahead.

---

# 2. Already accepted presentation behavior to preserve

The current accepted preparation presentation must not regress:

- shared generic preparation layer works across all supported games;
- center does not show user-specific-looking initials;
- no `VS` label;
- presentation order is monotonic `3 → 2 → 1`;
- server snapshot timing cannot visually jump backwards/forwards through the sequence;
- after `1`, use the existing short animated success check rather than `СТАРТ` text;
- if backend readiness is slower, show a neutral waiting/preparation mark until authoritative ready;
- gameplay stays hidden until the preparation presentation and authoritative reveal conditions are both satisfied.

Shield King changes the material/color language only.

---

# 3. Preparation layer

Safe Shield King styling:

- background: `#080B12` with optional `#231942` low-opacity centered depth;
- central neutral preparation mark: compact, centered, non-player-specific;
- accent: restrained `#6A4CFF` / `#A65FF7`;
- optional tiny gold highlight: `#FFD45C`;
- copy: existing human-facing preparation wording only.

Do not use a giant launcher logo.

Do not add a second startup splash.

---

# 4. Countdown numerals

`3`, `2`, `1` share one exact visual box.

Recommended visual contract:

```text
alignment: centered
font: existing numeric/timer family
weight: strong/bold
foreground: #FFFFFF or #E6E8EF
accent edge/glow: restrained #6A4CFF / #A65FF7
transition: short opacity + scale
```

The animation may reflect presentation state but cannot determine it.

---

# 5. Neutral wait after 1

If countdown presentation is complete but authoritative readiness/reveal is not yet available:

- numeral disappears;
- neutral compact preparation indicator remains;
- no fake `0`;
- no repeated countdown;
- no fake success;
- no technical copy such as `sync`, `handshake`, device counts or polling status.

---

# 6. Success check

When the accepted lifecycle reaches ready/reveal transition:

- show a short check-draw or equivalent existing success animation;
- semantic success green `#48D6A5` is allowed;
- do not show `СТАРТ`;
- do not hold the success check longer than the existing presentation owner requires.

---

# 7. Gameplay reveal

Gameplay reveal is authoritative.

Rules:

- no board visible underneath in an interactable state before reveal;
- no early timer ownership in the visual layer;
- no second fade animation that delays the actual game unnecessarily;
- reveal current accepted gameplay exactly, with only safe shared-shell skin changes from DS-4.

---

# 8. Failure states

## Preparation timeout

Must look different from draw.

Use warning/error visual language and existing timeout recovery actions.

## Cancellation

Use runtime reason and current recovery flow.

## Network/offline during preparation

Use current authoritative reconnect/error owner.

Do not restart a local countdown unless the product runtime explicitly starts a new authoritative preparation sequence.

---

# 9. Ownership prohibitions

The visual implementation must not add:

- readiness booleans owned by frontend animation;
- local `starts_at` calculation;
- local game clock source;
- duplicate polling;
- retry loops that mask deterministic backend failure;
- fake progress percentages;
- hardcoded countdown timing that conflicts with current preparation presenter;
- game-specific second preparation owner.

---

# 10. Integration procedure

At future runtime integration:

```text
inspect exact then-current Phase B presenter
→ preserve event/state inputs and ordering
→ replace only presentation tokens/materials/icons
→ keep deterministic 3→2→1 contract
→ keep neutral slow-server wait
→ keep success check
→ run all-game Phase B smoke
→ manual two-device Telegram acceptance
```

If visual skinning requires changes to lifecycle logic, stop and treat it as a separate main-roadmap task.

---

# 11. Acceptance

```text
Phase B lifecycle remains authoritative
3→2→1 remains deterministic and monotonic
no VS
no START text
neutral wait supported
success check supported
gameplay reveal not early
no fake timing/readiness/polling
```
