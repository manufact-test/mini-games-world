# Shield King — Screen State Matrix

## Status

`DS-3 STATE COVERAGE — SPEC READY / VISUAL ACCEPTANCE PENDING`

Legend:

- `R` — required visual state in V1 design system;
- `C` — conditional: only when authoritative product functionality exists;
- `—` — not applicable;
- `DS-5` — visually reserved here, finalized in Loading/System States.

| Surface | Default | Loading | Empty | Error | Disabled/locked | Offline/reconnect | Authoritative dependency |
|---|---:|---:|---:|---:|---:|---:|---|
| App shell | R | DS-5 | — | DS-5 | — | DS-5 | navigation/session |
| Home | R | R | C | R | C | DS-5 | profile/balance/rooms/games |
| Game catalogue | R | R | C | R | C | C | game availability |
| Profile | R | R | C | R | C | C | identity/stats |
| Notifications | R | R | R | R | — | C | notification feed |
| Friends/social | C | C | C | C | C | C | social contract |
| Settings | R | R | — | R | C | C | actual settings |
| Store | C | C | C | C | C | C | economy/store contract |
| History | C | C | R | C | — | C | history contract |
| Rules/game info | R | R | — | R | — | C | authoritative rules content |
| Invite flow | C | C | — | C | C | C | invite contract |
| Rematch | C | C | — | C | C | C | rematch contract |
| Search/matchmaking | R | R | — | R | C | DS-5 | matchmaking lifecycle |
| Match preparation | R | R | — | DS-5 | — | DS-5 | readiness lifecycle |
| Shared countdown | R | — | — | DS-5 | — | DS-5 | authoritative `starts_at` |
| Gameplay shell | R | C | — | C | R | DS-5 | game runtime |
| Result | R | C | — | R | C | C | settlement/result/rematch |
| Local error surface | R | — | — | R | — | C | retry contract |
| Empty state surface | R | — | R | — | C | — | zero-data condition |
| Offline | DS-5 | — | — | — | — | R | connectivity |
| Reconnect | DS-5 | — | — | — | — | R | connectivity/session |

---

# Result-state matrix

| Outcome | Icon/accent | Primary tone | Distinct rule |
|---|---|---|---|
| Victory | `win` / controlled gold | celebratory premium | no casino overload |
| Loss | `loss` / neutral or restrained error-adjacent | calm | not hostile/red-heavy |
| Draw | `draw` / silver-violet | neutral | never reused for timeout |
| Preparation timeout | DS-5 timeout status | warning/system | separate from draw/loss |
| Cancellation | neutral/system | informational | separate from gameplay result |
| Retryable failure | error + retry | error | preserve context when possible |

---

# Game-card matrix

All eight game cards share one component and one icon bounding box.

| State | Frame/icon | Surface | Label | Action |
|---|---|---|---|---|
| Default | accepted royal icon | standard card | primary | card/disclosure |
| Hover | same icon | subtle violet tint | primary | pointer feedback |
| Pressed | same icon | pressed surface | primary | scale/opacity component feedback only |
| Selected | same icon | violet border/tint | primary | optional selection check |
| Locked | same icon, muted | disabled/locked surface | muted | lock reason if authoritative |
| Loading | skeleton keeps icon/card geometry | skeleton | skeleton | none |
| Error | icon may remain if known | error supporting surface | primary | retry where valid |

Never resize one game's source artwork to compensate for perceived interior symbol width.

---

# Home room-state matrix

| State | Match | Gold | Rule |
|---|---|---|---|
| Inactive tab | neutral | neutral | no gold just because tab says Gold unless semantic accent is visible in content |
| Active Match | violet selected treatment | inactive | standard room semantics |
| Active Gold | inactive | violet selection + controlled semantic gold details | gold remains restrained |
| Loading room data | skeleton | skeleton | no fake values |
| Ineligible/disabled | runtime-defined | runtime-defined | reason must be authoritative |
| Balance insufficient | runtime-defined | runtime-defined | economy contract owns threshold |
| Error | local room error if retryable | local room error if retryable | no invented fallback behavior |

---

# Match lifecycle visual-state matrix

Design only; runtime remains owner.

| Lifecycle | User-visible surface | Progress/timer rule |
|---|---|---|
| Search started | Search/matchmaking | indefinite branded motion allowed; no fake % |
| Match confirmed | preparation enters | no gameplay reveal yet |
| Waiting readiness | preparation | no fake local readiness owner |
| Both ready / waiting start | preparation | authoritative transition only |
| Countdown 3 | shared countdown | runtime/server value rendered |
| Countdown 2 | shared countdown | runtime/server value rendered |
| Countdown 1 | shared countdown | runtime/server value rendered |
| Active reveal | gameplay shell | reveal only on authoritative transition |
| Connection degraded | DS-5 overlay/message | do not mutate game ownership |
| Preparation timeout | dedicated result/system state | never draw |
| Match cancelled | dedicated system result | neutral/informational |

---

# Responsive state rules

State meaning never changes by breakpoint.

Responsive changes may alter:

- card columns;
- modal → bottom sheet/full-height sheet;
- spacing;
- typography narrow variants;
- secondary metadata visibility only when non-critical.

Responsive changes must not hide:

- current game/room;
- primary CTA;
- critical error/warning;
- authoritative timer when it is required to play;
- outcome;
- accessibility label/semantic action.
