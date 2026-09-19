# MGW CANONICAL DELTA — 2026-09-19 — MVP-20 PRESEASON / 500-USER ADMIN ACTIVATION GATE

## 1. Product decision

The rating/season system is built during MVP-20, but the official public competition does **not** have to start immediately at first launch.

Active competition-state model:

- `OFF` — rating/season competition is not running.
- `PRESEASON` — the technical foundation is running and can collect/test rating and matchmaking data, but there is no official season result, no official leaderboard award, no seasonal badge/frame/medal-part grant, and no Hall-of-Fame result.
- `ACTIVE` — official seasonal competition is live.

The launch target is `PRESEASON`, not automatic `ACTIVE`.

## 2. What may run before official activation

During `PRESEASON`:

- MVP-20.1 per-game visible rating foundation may operate;
- MVP-20.2 hidden skill/MMR may operate;
- leaderboard/season infrastructure may be exercised and validated;
- telemetry, anti-duplicate logic, anti-farming logic and load/recalculation tooling may collect evidence.

During `PRESEASON` the system must **not**:

- issue official seasonal places;
- issue MVP-20.5 seasonal badges/temporary frames;
- issue MVP-20.6 yearly medal parts;
- create official Hall-of-Fame/season archive achievements;
- retroactively turn preseason test results into official awards.

When official competition is activated, the first official season history starts from the activation boundary. Preseason data remains diagnostic/history only and must not generate retroactive awards.

## 3. 500-user readiness trigger

A durable readiness trigger must exist for the future production system:

- when the authoritative production user-count metric first reaches **500 users**, create a one-time durable admin alert;
- reaching 500 users must **not** auto-activate official seasons;
- exact inclusion/exclusion rules for the production user-count metric must use the authoritative admin analytics/user-count owner available at implementation time; do not create a parallel counter just for this feature;
- the alert remains visible until acknowledged or official competition is activated.

Required admin alert meaning:

> The product has reached the 500-user readiness threshold. Official rating seasons are still in PRESEASON. Review system health and press the official activation control when ready.

## 4. Admin activation control

Future Admin must contain one explicit control for this transition.

Target location:
- MVP-22.5 `System status и admin messages` / feature-flag surface.

Required control:
- current state visible: `OFF / PRESEASON / ACTIVE`;
- primary action in PRESEASON: **«Запустить официальные рейтинговые сезоны»**;
- the button must not be a silent toggle;
- before confirmation, show a full human-readable explanation of exactly what activation does.

The confirmation must state that activation:

1. changes competition state `PRESEASON → ACTIVE`;
2. opens official seasonal leaderboards/results;
3. enables future seasonal awards according to MVP-20.5;
4. enables yearly medal-part eligibility according to MVP-20.6;
5. starts official season history from the activation boundary, without retroactive preseason awards;
6. triggers the launch announcement to users;
7. is audit-recorded with admin, timestamp, before/after state and reason.

Activation must be idempotent. Repeated clicks must not start a second season or send duplicate global announcements.

## 5. User announcement on activation

A successful `PRESEASON → ACTIVE` transition must automatically create/send one global product notification through the canonical notification/admin-message owner.

Meaning of the message:

> Поздравляем! В MINI GAMES WORLD запущены официальные рейтинговые сезоны. Теперь результаты по играм участвуют в официальных таблицах, сезонных местах и наградах.

Exact final copy/localization belongs to the notification/localization implementation, but the automatic all-user announcement is required.

## 6. Ownership / implementation split

- MVP-20 owns competition-state semantics and must be designed so OFF/PRESEASON/ACTIVE is possible from the beginning.
- MVP-20.4 owns the official season boundary semantics.
- MVP-20.5/20.6 must respect PRESEASON and never grant official awards before ACTIVE.
- MVP-20.8 owns rehearsal/metrics needed to prove readiness.
- MVP-22.5 owns the final Admin feature-flag/status UI, 500-user admin alert presentation, activation control and admin messaging integration.
- Existing notification center/admin-message owners must be reused; do not create a second notification system.

## 7. Current roadmap point

This decision does not change NEXT.

**NEXT remains MVP-20.1 — Per-game visible rating.**

MVP-20 should be implemented with the future activation gate in mind, but official public competition stays PRESEASON until an administrator explicitly activates it.

`main`, production, Cron and live DB remain separate explicit gates.

## 8. Season calendar and admin preparation reminders

Clarification: official rating seasons are **quarterly**, not monthly.

Canonical calendar from MVP-20.4:
- Season 1: January–March;
- Season 2: April–June;
- Season 3: July–September;
- Season 4: October–December;
- Moscow boundary remains authoritative.

Once competition state is `ACTIVE`, every scheduled season end must create an operational preparation cycle for Admin.

Reminder schedule:
- **T-21 days** before season end: create a durable admin task/alert: **«Сезон заканчивается через 3 недели — подготовьте награды следующего сезона»**;
- if readiness is incomplete, repeat/escalate at **T-14 days**;
- if still incomplete, repeat/escalate at **T-7 days**;
- reminders stop only when the required next-season reward package is marked `READY` or the season boundary has passed.

The reminder must survive logout/restart and remain visible in Admin until completed/acknowledged according to the canonical admin task/message owner.

## 9. Next-season reward readiness checklist

The admin season-preparation task must show a clear checklist of what is required before the boundary.

At minimum:
- next season ID and exact start/end dates;
- seasonal award assets required by MVP-20.5;
- top-3 temporary frame assets/configuration if that season uses a new design;
- the correct yearly medal design/fragments from MVP-20.6 when a new year or new quarter asset requires preparation;
- localized names/descriptions/notification copy where applicable;
- preview/validation that all required assets resolve correctly;
- final explicit `READY` state.

Important distinction:
- the **yearly medal design is annual**;
- each eligible season unlocks the corresponding quarter/fragment;
- do not require an entirely new four-part medal design every quarter unless a later explicit product decision changes that contract.

## 10. Automatic season-boundary behavior

If competition state is `ACTIVE` and the required reward package is `READY`:
- MVP-20.4 closes/finalizes the ending season automatically;
- MVP-20.5 awards are issued automatically to eligible users;
- MVP-20.6 medal-part eligibility is processed automatically;
- the next official season starts according to the canonical calendar;
- eligible user-facing reward/profile surfaces update without a manual grant step;
- season-close and reward issuance remain idempotent.

If required reward assets/config are **not READY** at the boundary:
- standings/results must not be lost;
- do not issue broken, placeholder or incomplete awards;
- keep award finalization in a durable `FINALIZING / ASSETS_REQUIRED` state;
- raise a high-priority durable admin alert;
- once the approved package becomes READY, resume finalization idempotently without double grants.

## 11. Ownership split for reminders and season operations

- MVP-20.4 owns calendar boundaries, finalizing state and due-date semantics.
- MVP-20.5 owns seasonal award eligibility/grant semantics.
- MVP-20.6 owns yearly medal and quarter/fragment eligibility.
- MVP-20.8 must rehearse season close, missing-assets behavior and idempotent recovery.
- MVP-22.5 owns Admin alert/message presentation.
- MVP-22.7 owns the recurring operational task/checklist surface for season preparation.
- Existing notification/admin-message/task owners must be reused; do not create parallel reminder systems.

This reminder/readiness requirement becomes active only for official `ACTIVE` seasons. It does not create official awards during `OFF` or `PRESEASON`.

