# Test plan

## Automated checks in this repository

- `php tests/run.php`: smoke tests for request expiration, participant authorization, state transitions, two-party confirmation, duplicate commit prevention, confirmation reset, safe one-pass message interpolation, item display-name sanitization, persistent rollback quarantine, corrupt/list-root fail-closed behavior, incomplete-write safety markers, failed-storage handling, and required project files/configuration.
- `composer lint`: PHP syntax validation for every source and test file.
- GitHub Actions: Composer manifest validation, PHP syntax checks, smoke tests, and YAML parsing of the manifest and resources on PHP 8.1–8.4. A separate PHPStan job analyzes the source against the PocketMine-MP API 5.44.3 reference.

These tests do not emulate PMMP's real inventory transaction/event pipeline and are not evidence that a live server inventory transfer was tested. The display-name and message interpolation checks are pure utility tests; executor integration remains a server-level test.

## Required PocketMine-MP integration test plan

Run the following against a disposable API 5 server with two test accounts and record server version, PHP version, logs, and before/after inventory snapshots:

1. Send and accept a valid request.
2. Deny a request and confirm both players' request state is cleared.
3. Reject a self-request.
4. Reject a request to an offline or ambiguous/non-exact player name.
5. Send reciprocal requests at nearly the same time and verify only one session exists.
6. Reject a second session for either participant.
7. Offer multiple stacks from separate slots and complete a trade.
8. Reject amount zero, negative, above stack count, above configured maximum, and malformed custom-form data.
9. Ensure a slot can only have one offer entry at a time.
10. Change the stack count after offering and verify final commit is rejected without inventory changes.
11. Change item type, damage, enchantments, custom name/lore, or compound NBT after offering and verify rejection without inventory changes.
12. Fill the target inventory; verify preflight capacity simulation rejects the trade before mutation.
13. Cancel from either side at each UI stage.
14. Disconnect either player before confirmation and before commit.
15. Let a request and a session time out.
16. Change an offer after a player confirms and verify both confirmations are reset.
17. Attempt a second confirmation callback/commit and verify no duplicate transfer.
18. Submit a form response retained from a cancelled/expired session and verify it is ignored.
19. Inject a test-only failure into the apply path and verify both snapshots are restored; separately test rollback failure handling in a disposable environment.
20. Verify completed/cancelled/expired sessions release both participant mappings and request references.
21. Verify `/trade`, subcommands, permission checks, and exact-name resolution.

## Deployment safety note

The executor validates both inventories, simulates capacity after outgoing removals, applies the plan synchronously, and restores snapshots if application throws or post-application verification fails. If rollback cannot be verified, both player UUIDs are persisted in `plugin_data/TradeSystem/trade-quarantine.json`; malformed/unreadable/unwritable quarantine data fail-closes the whole trading system. Recovery requires stopping the server, reconciling inventories from trusted state, removing only resolved UUID entries, and restarting. No user-space plugin can make updates to two distinct player inventories durable and crash-atomic across an abrupt process termination without an additional persistent transaction journal and recovery protocol. Test this plugin on a staging server before production use and keep regular server backups.
