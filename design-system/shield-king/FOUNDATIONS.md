# Shield King — Foundations

## 1. Purpose

This document defines the visual foundation for the shared Mini Games World Shield King design system. It translates the approved art direction into rules that future implementation must follow consistently across Telegram/Web and the Android WebView shell.

This document is visual only. It does not own product state, timers, readiness, progress, game rules, economy, authentication, networking, or navigation logic.

---

## 2. Design character

The approved character is:

- dark premium competitive game UI;
- near-black base rather than generic black;
- deep violet surfaces rather than bright flat purple;
- selective gold for premium/value/win emphasis;
- silver/light neutrals for readability and metallic identity;
- restrained violet glow;
- clean geometry with subtle retro-neon/game influence;
- no noisy casino styling;
- no glassmorphism as the default card language;
- no decorative effects that reduce information clarity.

### Primary-mark rule

The Shield King mark must remain optically centered.

Never place behind it:

- an offset white/silver backplate;
- a pale duplicate plate;
- a shifted duplicate shield;
- an asymmetric glow blob.

Centered ambient glow is allowed only when it does not create a second silhouette.

---

## 3. Color roles

Canonical approved brand colors are preserved exactly:

| Role | Token | Value | Use |
|---|---|---:|---|
| Startup/deepest app background | `color.bg.app` | `#080B12` | Primary full-screen background, startup continuity |
| Elevated shell background | `color.bg.elevated` | `#0C0F14` | App shell/top layers |
| Card background | `color.bg.card` | `#17121F` | Default cards and panels |
| Secondary/deep violet card | `color.bg.cardSecondary` | `#231942` | Selected/emphasized dark surfaces, never default for every card |
| Primary purple | `color.brand.primary` | `#6A4CFF` | Main CTA, active controls, selected state |
| Secondary violet | `color.brand.violet` | `#A65FF7` | Highlight/end of approved purple gradient |
| Deep violet | `color.brand.deepViolet` | `#231942` | Deep selected/background accent |
| Gold | `color.brand.gold` | `#FFD45C` | Premium/value/victory accent used selectively |
| Silver | `color.brand.silver` | `#E6E8EF` | Secondary light neutral, metallic/readability accent |
| Maximum-contrast text | `color.text.primary` | `#FFFFFF` | Primary headings/body where highest contrast is needed |

Supporting system colors:

| Role | Token | Value |
|---|---|---:|
| Border | `color.border.default` | `#342A43` |
| Separator | `color.border.separator` | `#282130` |
| Secondary text | `color.text.secondary` | `#C7C3D1` |
| Muted text | `color.text.muted` | `#918A9E` |
| Success | `color.state.success` | `#48D6A5` |
| Warning | `color.state.warning` | `#F2B84B` |
| Error | `color.state.error` | `#FF617D` |
| Informational | `color.state.info` | `#72A7FF` |
| Disabled foreground | `color.state.disabledFg` | `#6F6979` |
| Disabled surface | `color.state.disabledBg` | `#201C28` |
| Scrim/overlay | `color.overlay.scrim` | `rgba(4, 5, 9, 0.76)` |
| Soft violet tint | `color.overlay.violetTint` | `rgba(106, 76, 255, 0.12)` |
| Soft gold tint | `color.overlay.goldTint` | `rgba(255, 212, 92, 0.10)` |

### Color usage rules

- `#6A4CFF` is the default interactive brand color; do not replace it screen-by-screen with arbitrary purples.
- Gold is not a generic CTA color. Reserve it for premium/value/victory emphasis where semantics justify it.
- Error does not dominate loss-result screens. A loss is a game outcome, not necessarily a system error.
- Disabled states must read as unavailable without looking like loading.
- Muted text may not be used for critical gameplay information.
- Pure white backgrounds are not part of Shield King shared UI.

---

## 4. Gradients

Only these reusable gradients are approved in V1.

### `gradient.ctaPrimary`

- start: `#6A4CFF`
- end: `#A65FF7`
- direction: `135deg`
- use: primary CTA, strongly selected interactive surfaces when a solid purple is insufficient
- forbidden: large page backgrounds, all cards, decorative text fills

CSS reference:

`linear-gradient(135deg, #6A4CFF 0%, #A65FF7 100%)`

### `gradient.premiumGold`

- start: `#FFD45C`
- end: `#D69A32`
- direction: `135deg`
- use: premium purchase/value emphasis, controlled victory accent
- forbidden: normal navigation, ordinary buttons, generic backgrounds

CSS reference:

`linear-gradient(135deg, #FFD45C 0%, #D69A32 100%)`

### `gradient.activeGlow`

- center color: `rgba(166, 95, 247, 0.30)`
- outer color: `rgba(106, 76, 255, 0.00)`
- geometry: centered radial glow
- use: active game/turn/selected emphasis behind an owned component
- forbidden: offset behind the primary Shield King mark; never create a second silhouette

CSS reference:

`radial-gradient(circle at 50% 50%, rgba(166,95,247,.30) 0%, rgba(106,76,255,0) 70%)`

### `gradient.backgroundAccent`

- center color: `rgba(106, 76, 255, 0.14)`
- outer color: `rgba(106, 76, 255, 0.00)`
- geometry: broad centered/contained radial accent
- use: sparse hero/app-shell atmosphere
- forbidden: persistent high-contrast glow behind body copy or game boards

CSS reference:

`radial-gradient(circle at 50% 0%, rgba(106,76,255,.14) 0%, rgba(106,76,255,0) 62%)`

No additional gradient becomes reusable until explicitly added to the token source of truth.

---

## 5. Typography

### Font family

Primary family:

`Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`

Design intent uses **Inter**. Future implementation must either bundle/use Inter legitimately or fall back to the listed system stack. The design system does not authorize a network-only font dependency that can block rendering.

No second decorative font is required for V1. Timer/numeric styles use tabular numerals in the same family for consistency and loading reliability.

### Type scale

| Style | Weight | Size | Line height | Letter spacing | Notes |
|---|---:|---:|---:|---:|---|
| Display | 800 | 40px | 44px | -0.02em | Hero/result emphasis |
| H1 | 700 | 32px | 38px | -0.02em | Screen title |
| H2 | 700 | 24px | 30px | -0.01em | Major section |
| H3 | 600 | 20px | 26px | -0.01em | Card/section heading |
| Body | 400 | 16px | 24px | 0 | Primary body |
| Body secondary | 400 | 14px | 20px | 0 | Supporting copy |
| Label | 600 | 13px | 16px | 0.01em | Controls/status labels |
| Button | 700 | 15px | 20px | 0.01em | Buttons |
| Caption | 500 | 12px | 16px | 0.01em | Metadata/helper text |
| Numeric/timer | 700 | 28px | 32px | 0 | Use tabular numerals |

Numeric/timer implementation requirement:

`font-variant-numeric: tabular-nums;`

### Typography rules

- Do not use all-caps for paragraphs or long labels.
- Button labels should normally be sentence/title case, not forced uppercase.
- Critical timers/scores use the numeric style, not decorative outlined fonts.
- Responsive reduction must preserve hierarchy; do not independently shrink random elements to fit one device.
- On narrow screens, Display may step down to H1 sizing and H1 may step down to 28/34, while body remains 16/24 unless space is structurally constrained.

---

## 6. Spacing scale

All standard spacing must come from this scale:

| Token | Value |
|---|---:|
| `space.1` | 4px |
| `space.2` | 8px |
| `space.3` | 12px |
| `space.4` | 16px |
| `space.5` | 20px |
| `space.6` | 24px |
| `space.8` | 32px |
| `space.10` | 40px |
| `space.12` | 48px |
| `space.16` | 64px |

Default component rhythm:

- icon-to-label gap: 8px;
- compact control internal gap: 8px;
- standard card internal gap: 12–16px using tokens;
- section-to-section gap: 24–32px;
- major screen-region gap: 32–48px.

Do not create one-off `17px`, `19px`, `27px` spacing to fix individual screenshots unless a later component specification explicitly establishes a semantic exception.

---

## 7. Radius

| Token | Value | Use |
|---|---:|---|
| `radius.small` | 8px | Small chips/badges |
| `radius.control` | 12px | Buttons/inputs/tabs |
| `radius.card` | 16px | Standard cards |
| `radius.modal` | 20px | Modal/sheet surfaces |
| `radius.hero` | 24px | Hero/large feature containers |
| `radius.circular` | 9999px | Avatars/pills/circles |

Use consistent radii per component family. Avoid mixing 12/13/14/15px variants of the same component.

---

## 8. Borders

### Default border

- width: `1px`
- color: `#342A43`
- style: solid

### Separator

- width: `1px`
- color: `#282130`
- style: solid

### Focus ring

- outer width: `2px`
- color: `#A65FF7`
- offset: `2px`
- must remain visible against dark surfaces

Selected cards may use a `1px` primary-purple border plus controlled glow; they do not need a thick neon frame.

---

## 9. Shadows and glows

### `shadow.card`

`0 8px 24px rgba(0, 0, 0, 0.28)`

### `shadow.modal`

`0 20px 60px rgba(0, 0, 0, 0.46)`

### `glow.violet`

`0 0 24px rgba(106, 76, 255, 0.30)`

### `glow.gold`

`0 0 20px rgba(255, 212, 92, 0.20)`

Rules:

- glow is emphasis, not elevation by itself;
- ordinary cards should not all glow;
- the primary brand mark may only use centered/subtle ambient glow and never a visibly offset backplate/shadow silhouette;
- shadows must not create accidental silver/white edges.

---

## 10. Motion foundation

Motion should feel controlled and competitive, not arcade-chaotic.

| Token | Value | Use |
|---|---:|---|
| `motion.fast` | 120ms | Press/selection feedback |
| `motion.standard` | 200ms | Common UI transitions |
| `motion.emphasis` | 320ms | Modal/result/important state reveal |
| `motion.countdown` | 600ms | Visual countdown pulse segment; timing owner remains external |

Default easing:

- standard: `cubic-bezier(0.2, 0, 0, 1)`
- emphasis: `cubic-bezier(0.16, 1, 0.3, 1)`

Respect reduced-motion preferences. Reduced motion removes/shortens decoration, not state meaning.

---

## 11. Layout and responsive rules

The system is shared across desktop Telegram/Web, mobile Telegram and the Android WebView shell. It must adapt by available viewport, not by hard-coded device model.

### Breakpoint bands

| Band | Width |
|---|---|
| Narrow | `0–359px` |
| Mobile | `360–639px` |
| Tablet/compact desktop | `640–959px` |
| Desktop | `960px+` |

These bands are layout triggers, not device detection.

### Screen gutters

- Narrow: 12px minimum horizontal content gutter;
- Mobile: 16px;
- Tablet/compact desktop: 20px;
- Desktop: 24px, with large content regions allowed to use 32px internally.

### Content width

- Standard shared app content max width: `1200px`.
- Gameplay surfaces may use a dedicated centered max width defined by each game, while preserving surrounding shell gutters.
- Text-heavy modal/readable content should not stretch to full desktop width.

### Safe areas / system insets

Top/bottom/side safe areas must be additive to semantic layout spacing.

Web reference pattern:

- top: `max(basePadding, env(safe-area-inset-top))`
- right: `max(basePadding, env(safe-area-inset-right))`
- bottom: `max(basePadding, env(safe-area-inset-bottom))`
- left: `max(basePadding, env(safe-area-inset-left))`

Native/WebView integration must map equivalent system insets once at the shell boundary. Do not compensate individual screens for specific phones.

### Minimum interaction target

Interactive controls must expose at least a `44×44px` hit target, even when the visible icon is smaller.

### Narrow-screen priority

On narrow screens, preserve in this order:

1. gameplay/status meaning;
2. primary action availability;
3. readable labels;
4. player identity;
5. decorative art/effects.

Decoration is reduced before essential information is compressed below readable/usable sizes.

### Overflow

- No horizontal page scroll in standard app screens.
- Horizontal scrolling is allowed only for a component whose contract explicitly defines it (for example, a carousel), never as a patch for layout overflow.
- Bottom navigation must fit within the safe width; labels may use the compact style defined later, not be clipped.

---

## 12. Accessibility foundation

- Do not communicate win/loss/online/error only by color; pair color with iconography, text or shape/state treatment.
- Focus visibility is mandatory for keyboard-capable Web environments.
- Body text should remain readable against its assigned dark surface; muted text is for secondary information only.
- Touch targets: minimum 44×44px.
- Reduced motion must be supported.
- Essential status cannot depend on glow alone.

---

## 13. Do / Don't

### Do

- use the approved dark/violet/gold/silver palette;
- use a small number of semantic elevation levels;
- keep the Shield King mark centered;
- use token spacing/radius rather than screenshot-specific fixes;
- let game accents vary inside a shared product shell;
- treat responsive behavior as structural layout behavior.

### Don't

- resurrect the old bright-purple `MG` tile;
- use random neon colors as reusable brand colors;
- put gold on every premium-looking control;
- use giant blurred glow everywhere;
- add device-specific left/right/top pixel patches;
- create a separate Android visual system;
- fake server progress, timing, readiness or game state through visual rules.

---

## 14. DS-0 acceptance checklist

- Approved Shield King identity preserved: YES
- Exact canonical brand colors preserved: YES
- Required supporting colors explicit: YES
- Reusable gradients explicit: YES
- Typography explicit: YES
- Font dependency/fallback explicit: YES
- Spacing scale explicit: YES
- Radius explicit: YES
- Borders/shadows/glows explicit: YES
- Responsive and safe-area rules explicit: YES
- Runtime behavior ownership unchanged: YES
