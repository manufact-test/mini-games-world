# Shield King — Loading and System States

## Status

`DS-5 SPEC READY — VISUAL ONLY / EXISTING LIFECYCLE PRESERVED`

This document defines how existing loading/system states may be visually skinned with Shield King without changing runtime ownership, timing, polling, readiness or navigation.

---

# 1. Global rule

System-state visuals must reflect authoritative runtime state.

They may not create a second state machine.

```text
runtime state
→ existing UI state owner
→ Shield King visual treatment
```

Never:

```text
visual animation
→ pretend backend work is complete
→ reveal gameplay early
```

---

# 2. Shared visual language

Base surfaces:

- deepest background: `#080B12`;
- elevated shell: `#0C0F14`;
- card: `#17121F`;
- secondary/deep-violet surface: `#231942`;
- border: `#342A43`;
- primary text: `#FFFFFF`;
- secondary text: `#C7C3D1`;
- muted text: `#918A9E`;
- primary accent: `#6A4CFF`;
- highlight violet: `#A65FF7`;
- premium/gold: `#FFD45C`;
- success: `#48D6A5`;
- warning: `#F2B84B`;
- error: `#FF617D`.

Motion is restrained. No casino/noisy neon effects.

---

# 3. App startup/loading

Preserve the current startup ownership and duration.

Shield King treatment may use:

- near-black background;
- compact centered brand mark or restrained crown/shield motif;
- small violet/gold ambient motion;
- short neutral copy already owned by the product.

Do not add a second launcher-style splash after Android launcher/startup branding.

Do not add fake percentage progress.

---

# 4. Generic page loading

Use a neutral dark card or skeleton/spinner appropriate to the existing surface.

Rules:

- never display invented progress;
- do not hide already-valid content behind a global loader;
- preserve existing loading owner and first-frame behavior;
- use violet for neutral activity, not green.

---

# 5. Retryable network error

Visual structure:

- error icon/status accent;
- clear user-facing message;
- existing Retry action;
- secondary navigation action only if already supported.

No technical stack/request copy.

Error color: `#FF617D` on dark base.

---

# 6. Offline

Offline is distinct from a generic server error.

Visual treatment:

- neutral/dim dark surface;
- offline status icon;
- concise connection message;
- existing retry/reconnect behavior only.

Do not claim synchronization has succeeded while offline.

---

# 7. Reconnect

Reconnect is a transient system state.

Visual treatment:

- restrained violet activity indicator;
- existing content remains visible where current runtime allows it;
- success appears only when authoritative connection state returns.

No local countdown or fake reconnect percentage.

---

# 8. Match preparation

Match preparation uses the existing Phase B lifecycle and presentation sequence.

Visual treatment:

- near-black/deep-violet global preparation layer;
- neutral Shield King motion;
- no player-specific-looking initials/text in the center;
- no `VS` center label;
- no technical synchronization copy.

The preparation layer stays until the authoritative reveal conditions are met.

---

# 9. Waiting for opponent readiness

This state is neutral waiting, not success and not failure.

Use:

- neutral/violet activity mark;
- muted supporting copy if current product exposes it;
- no green until readiness is actually authoritative;
- no invented device/player readiness percentages.

---

# 10. Shared countdown 3 → 2 → 1

The existing deterministic `3 → 2 → 1` presentation remains the source of truth.

Visual rules:

- one large centered numeral;
- tabular/consistent geometry so numbers do not jump;
- white/silver numeral with restrained violet/gold edge/accent;
- short scale/fade transition only;
- no `VS`;
- no `СТАРТ` text after `1`.

The visual animation must not become a clock owner.

---

# 11. Ready / reveal

After countdown completion and authoritative readiness:

- show the existing short success check/ready confirmation visual;
- semantic success green may be used here;
- immediately proceed to the authoritative gameplay reveal when runtime allows.

Do not insert an extra branded interstitial.

---

# 12. Turn handoff

Turn handoff remains gameplay-owned.

A shared turn container may use:

- neutral → active visual transition;
- violet emphasis for normal active state;
- semantic warning/error only when runtime state actually warrants it.

No fake local timer reset.

---

# 13. Victory

Use the existing result surface/flow.

Safe Shield King skin:

- dark result card;
- restrained gold/violet success emphasis;
- game icon/name if already part of the result UI;
- existing rematch/menu actions.

Do not turn victory into a separate casino celebration screen.

---

# 14. Loss

Use a dark neutral result surface with clear loss hierarchy.

Error red may be used sparingly; loss is not a technical application error.

Existing rematch/menu actions remain unchanged.

---

# 15. Draw

Draw uses neutral silver/violet treatment.

It must not look like a preparation timeout or technical failure.

---

# 16. Preparation timeout

Preparation timeout is a system/match-start failure state, not a draw.

Use:

- warning/error semantic accent;
- clear timeout/cancellation copy owned by the product;
- existing recovery/navigation actions.

Do not reuse draw artwork or result wording.

---

# 17. Generic match cancellation

Keep runtime reason/copy ownership.

Visual treatment:

- neutral/error depending on actual reason;
- current recovery action;
- no fake blame/technical diagnostics.

---

# 18. Session/auth boundary error

Use a dedicated application boundary error surface.

Rules:

- concise human copy;
- existing re-entry/reload action only;
- never expose tokens, raw auth data or backend terminology.

---

# 19. Motion and reduced motion

Allowed:

- opacity transition;
- short scale pulse;
- restrained ring/particle motion around a neutral brand motif;
- success-check draw animation.

Forbidden:

- indefinite bright strobing;
- fake progress loops that imply a percentage;
- animation that delays real UI readiness;
- multiple competing loader owners.

When `prefers-reduced-motion` is enabled, every state must remain readable with static icon/text changes.

---

# 20. Acceptance

```text
all required system states documented
existing lifecycle preserved
no fake progress
no second timing/readiness owner
no technical developer copy
Phase B preparation remains authoritative
```
