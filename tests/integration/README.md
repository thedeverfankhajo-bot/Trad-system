# TradeSystem API 5 inventory integration harness

This is a separate, test-only PocketMine-MP plugin. It calls the real `TradSystem\trade\TradeExecutor` against live `PlayerInventory` objects on the PMMP main thread. It is not included in the production plugin package and does not replace the full Bedrock UI/request end-to-end test plan in `../README.md`.

## Safety requirements

- Use a **disposable staging server** running a PocketMine-MP API 5 release compatible with the TradeSystem version under test.
- Install TradeSystem first, then install this harness as a separate plugin. Its manifest declares `depend: [TradeSystem]`.
- Use two disposable test accounts. Before running a case, the **main inventory of both accounts must be empty**. The harness refuses to run otherwise.
- Run one case at a time. Do not run this on a production server or accounts containing valuable items.
- On a verified expected outcome, the harness restores both initially empty main inventories and verifies that restoration.
- On an unexpected executor result or exception after execution begins, the harness deliberately leaves the test state in place for diagnosis. Inspect both inventories and server logs before reusing the accounts.

## Install

Copy this `integration/` directory into a temporary plugin folder outside the production TradeSystem source package, so the folder layout becomes:

```text
plugins/
  TradeSystem/                  # the plugin being tested
  TradeSystemIntegrationHarness/
    plugin.yml
    src/TradeSystemIntegrationHarness/Main.php
```

Start the test server and verify that both plugins load without errors. The command permission `tradesystem.integration.run` defaults to operators.

## Cases

1. With the first empty account in-game, run `/tradeint success <other-player>`. The harness installs 3 diamonds on the first account and 2 emeralds on the second, invokes the real executor after both session confirmations, checks the final counts (1 diamond + 1 emerald; 2 diamonds + 1 emerald), then restores both inventories to empty.
2. With the first empty account in-game, run `/tradeint capacity <other-player>`. The harness fills every storage slot in the first account with a full dirt stack and places a diamond sword in slot 0 of the second. It offers one dirt for the sword. Since removing one dirt leaves every slot occupied, preflight must return `no-space` and both prepared inventories must remain exactly unchanged before the harness restores them to empty.
3. Run `/tradeint stale-count <other-player>`. The harness records a 3-diamond source snapshot, then changes the offered stack count before executing the commit. The executor must return `inventory-changed` and leave both commit-baseline inventories unchanged.
4. Run `/tradeint stale-meta <other-player>`. The harness records the source snapshot, then changes the diamond's custom name before executing the commit. The executor must return `inventory-changed` and leave both commit-baseline inventories unchanged.
5. Run `/tradeint stale-type <other-player>`. The harness records the offered diamonds, replaces the source slot with three gold ingots before commit, and expects `inventory-changed` with both commit-baseline inventories unchanged.

After an expected result, the harness restores both initially empty inventories and now re-verifies both snapshots after both restoration operations. This last check catches inventory listeners that mutate the first player while the second inventory is restored.

The harness validates the executor and real storage inventories, not command request resolution, form callbacks, plugin interactions, rollback fault injection, or crash consistency. Those require additional scenarios. Record the PMMP build, PHP version, command output and server logs with the test report.
