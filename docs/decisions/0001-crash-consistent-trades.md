# ADR 0001: Crash-consistent trade commits

- **Status:** Accepted as a design constraint; implementation deferred.
- **Date:** 2026-10-27
- **Scope:** The item-transfer commit path in `TradeExecutor` and recovery behavior coordinated by `TradeManager`.

## Context

The current executor validates both source offers, simulates both target inventories, captures in-memory inventory snapshots, applies each inventory plan synchronously, and verifies the result. If an exception or mismatch is detected, it attempts to restore both snapshots. If rollback cannot be verified, the participants are quarantined and the trading system fails closed when quarantine state cannot be trusted.

This is meaningful protection against detected in-process failures, but it is not a durable transaction across two player inventories. A process termination, host crash, power loss, or storage failure between inventory updates can happen before the plugin observes an exception or completes rollback. The persistent quarantine store does not contain the full before/after inventory state and therefore cannot by itself repair such a crash.

## Decision

Do not describe the current implementation as crash-atomic. Do not add a best-effort journal that claims to solve this problem without proving recovery ordering against the target PocketMine-MP 5 release.

The current snapshot rollback and fail-closed quarantine remain the implemented behavior for in-process failures. A durable journal is a separate security milestone and must be implemented only with a versioned item encoding and a verified recovery barrier.

## Required design for a future journal

1. **Serialize transactions.** Permit at most one unresolved journal transaction per participant. Disable new trades globally while journal state is corrupt, unsupported, or unresolved in a way that could overlap a new transaction.
2. **Use a versioned record.** Include a random transaction ID, schema version, both player UUIDs, state, and complete before/after inventory snapshots for both participants. Persist item type and all relevant metadata using supported public APIs for the pinned PMMP version. Do not use PHP object serialization or guess at internal item fields.
3. **Persist before mutation.** Write and verify the complete `PREPARED` record before changing either inventory. A partial, unreadable, incompatible, or checksum-invalid record must fail closed.
4. **Apply synchronously and verify.** Revalidate the live source offers and destination capacity, apply the planned inventories on the main thread, then compare both resulting inventories with the recorded after-state. Inventory setters may trigger third-party listeners; a method call is not an atomic durable commit.
5. **Persist the decision.** Write `COMMITTED` only after both after-states have been verified. Journal writes need an explicitly reviewed durability strategy (including complete-write checks, atomic replacement where supported, and flush/sync behavior supported by the target runtime and filesystem).
6. **Recover idempotently.** A `PREPARED` transaction should recover to both recorded before-states; a `COMMITTED` transaction should recover to both after-states. Keep the record until both participants have been reconciled and the recovery result is durably recorded. Replaying the same recovery more than once must not create or remove items.
7. **Establish a recovery barrier.** Before enabling ordinary inventory interaction or allowing player data to be saved in a way that could conflict with recovery, establish how the chosen PMMP version loads, exposes, and saves player inventories. If the public plugin lifecycle cannot reliably provide this ordering, do not claim the journal solves crash recovery; reassess the integration boundary.
8. **Fail closed on uncertainty.** If recovery cannot read the journal, decode a snapshot, reach a participant, or verify the resulting inventory, keep trading disabled and emit actionable critical logs. Never discard the unresolved record automatically.

## Required verification before release

The implementation must be tested on a disposable server using the exact supported PMMP/API and PHP versions. Inject termination or simulated storage failure at each boundary: before the prepared record is durable; after it is durable but before mutation; after mutating player A; after mutating player B; after inventory verification but before writing `COMMITTED`; during committed-record replacement; and during journal cleanup.

For every boundary, restart the server and test both participants reconnecting in either order, one participant remaining offline, repeated recovery, malformed/unsupported journal versions, short writes, failed rename/flush, changed item NBT, full inventories, and third-party inventory listeners that cancel or mutate slot changes. Verify the complete before/after inventory contents and item counts from trusted snapshots. Unit tests and mocked inventories are useful but cannot replace these server-level fault-injection tests.

## Consequences

- The present implementation remains explicitly **not crash-atomic**.
- This ADR does not claim that a journal has been implemented or that crash recovery has been tested.
- A future implementation must be a coherent change including journal code, recovery gating, fault-injection tests, documentation, and CI checks. If those prerequisites cannot be validated, retain the limitation rather than weakening the safety model.
