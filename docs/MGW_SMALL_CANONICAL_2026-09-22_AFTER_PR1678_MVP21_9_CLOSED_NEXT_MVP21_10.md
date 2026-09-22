# MGW SMALL CANONICAL — AFTER PR #1678 / MVP-21.9 CLOSED

**Date:** 2026-09-22  
**Staging SHA:** `7dd268ae71005819021cde2678061a9ff4997f5b`  
**Runtime PR:** #1678  
**Staging E2E:** #35772333623 SUCCESS  
**MVP-21.9:** CLOSED  
**Next:** MVP-21.10

## Frozen 21.9 ownership

- `TournamentSettlementService` is the only tournament reward/ledger writer.
- `TournamentRewardProjectionService` is read-only.
- Do not create a second payout, entitlement or Golden Ticket owner.
- Synthetic staging fixtures remain reward-ineligible.
- Temporary styles expire from their durable `valid_until_at_utc`; expired styles are not active on Profile.
- Golden Ticket stays non-saleable/non-transferable and keeps repeat `championship_count`.
- Tournament archive/Hall/Profile read the durable settlement tables; they do not synthesize competitive results.

## Accepted reward contract

- 1st: 200 000 total = 50 000 return + 150 000 prize + Golden Ticket + 30d crown + permanent winner badge + champion-set achievement + Hall of Fame + gold cup/result.
- 2nd: 80 000 total = 50 000 return + 30 000 prize + 30d silver frame + permanent finalist result + silver cup.
- 3rd: 50 000 return + 30d bronze mark + permanent third-place result + bronze cup.
- Others: no refund except explicit MVP-21.8 cancellation/emergency paths.

## Next implementation boundary

MVP-21.10 must integrate with the existing 21.9 settlement owner.

Required:

- review only top3 paths and explicitly flagged matches;
- no hold without a serious signal;
- serious signal → provisional reward hold;
- admin release/disqualify;
- deterministic placement shift;
- durable audit;
- retry/idempotency safety;
- release/shift must not duplicate ledger payouts, entitlements or Golden Ticket ownership.

Do not modify frozen game engines.
Do not start final manual Telegram acceptance yet.
