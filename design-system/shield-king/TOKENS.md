# Shield King — Design Tokens

## Status

`DS-0 TOKEN SOURCE OF TRUTH`

This file is the human-readable semantic token contract. `tokens.json` mirrors these values for future tooling/implementation. If the two ever differ, fix the mismatch before implementation; do not silently choose one.

---

## Color tokens

```text
color.bg.app                 #080B12
color.bg.elevated            #0C0F14
color.bg.card                #17121F
color.bg.cardSecondary       #231942

color.border.default         #342A43
color.border.separator       #282130

color.brand.primary          #6A4CFF
color.brand.violet           #A65FF7
color.brand.deepViolet       #231942
color.brand.gold             #FFD45C
color.brand.goldDeep         #D69A32
color.brand.silver           #E6E8EF

color.text.primary           #FFFFFF
color.text.secondary         #C7C3D1
color.text.muted             #918A9E

color.state.success          #48D6A5
color.state.warning          #F2B84B
color.state.error            #FF617D
color.state.info             #72A7FF
color.state.disabledFg       #6F6979
color.state.disabledBg       #201C28

color.overlay.scrim          rgba(4, 5, 9, 0.76)
color.overlay.violetTint     rgba(106, 76, 255, 0.12)
color.overlay.goldTint       rgba(255, 212, 92, 0.10)
```

---

## Gradient tokens

```text
gradient.ctaPrimary
linear-gradient(135deg, #6A4CFF 0%, #A65FF7 100%)

gradient.premiumGold
linear-gradient(135deg, #FFD45C 0%, #D69A32 100%)

gradient.activeGlow
radial-gradient(circle at 50% 50%, rgba(166,95,247,.30) 0%, rgba(106,76,255,0) 70%)

gradient.backgroundAccent
radial-gradient(circle at 50% 0%, rgba(106,76,255,.14) 0%, rgba(106,76,255,0) 62%)
```

No other gradient is a reusable token in V1.

---

## Typography tokens

Primary font stack:

```text
font.family.primary
Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif
```

Style tokens:

```text
type.display      800 / 40px / 44px / -0.02em
type.h1           700 / 32px / 38px / -0.02em
type.h2           700 / 24px / 30px / -0.01em
type.h3           600 / 20px / 26px / -0.01em
type.body         400 / 16px / 24px / 0
type.bodySmall    400 / 14px / 20px / 0
type.label        600 / 13px / 16px / 0.01em
type.button       700 / 15px / 20px / 0.01em
type.caption      500 / 12px / 16px / 0.01em
type.numeric      700 / 28px / 32px / 0 + tabular numerals
```

Narrow-screen responsive overrides:

```text
type.display.narrow  700 / 32px / 38px / -0.02em
type.h1.narrow       700 / 28px / 34px / -0.02em
```

Body typography does not shrink globally below 16/24 merely to fit a narrow screen.

---

## Spacing tokens

```text
space.1     4px
space.2     8px
space.3    12px
space.4    16px
space.5    20px
space.6    24px
space.8    32px
space.10   40px
space.12   48px
space.16   64px
```

---

## Radius tokens

```text
radius.small       8px
radius.control    12px
radius.card       16px
radius.modal      20px
radius.hero       24px
radius.circular 9999px
```

---

## Border tokens

```text
border.default     1px solid #342A43
border.separator   1px solid #282130
border.focus       2px solid #A65FF7
focus.offset       2px
```

---

## Shadow / glow tokens

```text
shadow.card       0 8px 24px rgba(0, 0, 0, 0.28)
shadow.modal      0 20px 60px rgba(0, 0, 0, 0.46)
glow.violet       0 0 24px rgba(106, 76, 255, 0.30)
glow.gold         0 0 20px rgba(255, 212, 92, 0.20)
```

The mark itself must never gain an offset light backplate or shifted shield-like shadow from these tokens.

---

## Motion tokens

```text
motion.fast         120ms
motion.standard     200ms
motion.emphasis     320ms
motion.countdown    600ms

easing.standard    cubic-bezier(0.2, 0, 0, 1)
easing.emphasis    cubic-bezier(0.16, 1, 0.3, 1)
```

`motion.countdown` defines visual pulse duration only. It is not a clock/timer owner.

---

## Layout tokens

```text
breakpoint.narrowMax      359px
breakpoint.mobileMax      639px
breakpoint.compactMax     959px
breakpoint.desktopMin     960px

layout.gutter.narrow       12px
layout.gutter.mobile       16px
layout.gutter.compact      20px
layout.gutter.desktop      24px
layout.gutter.desktopInner 32px
layout.contentMax        1200px

interaction.targetMin      44px
```

Safe-area rule:

```text
resolved edge padding = max(semantic gutter/padding, platform safe-area inset)
```

No device-model-specific inset token is allowed.

---

## Semantic usage guardrails

```text
PRIMARY CTA:
solid color.brand.primary OR gradient.ctaPrimary

SECONDARY CTA:
card/elevated dark surface + border.default + primary/secondary text

PREMIUM:
color.brand.gold / gradient.premiumGold only when semantics are premium/value/victory

ERROR:
color.state.error only for actual error/destructive semantics

LOSS:
not automatically color.state.error

ACTIVE/SELECTED:
color.brand.primary, optional gradient.activeGlow, explicit state indicator

DISABLED:
color.state.disabledBg + color.state.disabledFg

OVERLAY:
color.overlay.scrim
```

---

## Implementation rule

Future code should map platform variables/resources to these semantic names rather than copying raw hex values screen-by-screen. Implementation may use CSS variables, native resource aliases or another equivalent token layer, but it must preserve semantic ownership and one source of truth.
