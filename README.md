# TradeSystem

A dependency-free player-to-player **item trading** plugin for PocketMine-MP API 5 / Minecraft Bedrock. It uses the public `pocketmine\form\Form` interface and `Player::sendForm()` rather than requiring a separate FormAPI plugin.

## Features

- `/trade` opens the Bedrock UI; `/trade <player>` sends a request.
- `/trade accept <player>`, `/trade deny <player>`, `/trade cancel`, and `/trade help` are supported.
- UUID-based request/session tracking, exact online-name resolution, request expiry, rolling per-minute rate limits, and cooldowns.
- Offer multiple source inventory slots and choose amounts without moving items into a temporary inventory.
- Two-party confirmation; any offer change clears both confirmations.
- Source stack count, item identity, damage, and compound metadata are revalidated just before commit.
- Item names shown in trade forms are sanitized against formatting, line-break, and bidirectional-text spoofing; message placeholders are interpolated in one pass.
- Both target inventories are simulated after outgoing items are removed, so those newly freed slots are considered before capacity rejection.
- Synchronous main-thread commit, pre-commit inventory snapshots, post-commit verification, rollback attempts, stale-form/session validation, and idempotent cleanup.
- Persistent UUID quarantine after a failed inventory rollback; unreadable, malformed, or unwritable quarantine storage disables all trading rather than failing open.
- Configurable messages and optional sound effects; concise audit records omit NBT.
- GitHub Actions checks project syntax, smoke tests, Composer metadata, and YAML parseability.

## Requirements

- PocketMine-MP API 5.0.0 or compatible 5.x server. The plugin manifest declares `api: [5.0.0]` as the minimum API branch; runtime behavior must still be tested against the target PMMP release.
- PHP 8.1 or newer, consistent with the language features used by this code.
- No mandatory third-party plugin or runtime Composer package.
- To load the source folder directly, install DevTools on a test server. For distribution, package the plugin as a PHAR using the standard PMMP/DevTools workflow.

## Installation

1. Download or clone this repository.
2. For a development server with DevTools enabled, place the project folder in `plugins/` so that `plugins/Trad-system/plugin.yml` is present.
3. Start or restart PocketMine-MP.
4. Review `plugin_data/TradeSystem/config.yml` and `plugin_data/TradeSystem/messages.yml`.
5. Test trades on a staging server before allowing real player trading.

For a PHAR release, package the contents of this project with a PMMP-compatible packaging tool and put `TradeSystem.phar` in the server's `plugins/` directory.

## Commands

| Command | Behavior |
| --- | --- |
| `/trade` | Opens the main form. |
| `/trade <player>` | Sends an item-trade request to an exact online player name. |
| `/trade accept <player>` | Accepts an incoming request from that player. |
| `/trade deny <player>` | Denies an incoming request from that player. |
| `/trade cancel` | Cancels the current trade session. |
| `/trade help` | Shows command help. |

### Permission

- `tradesystem.command.trade` — allows use of the command and UI; default is `true`.

No administrator override is provided. Operator status never skips transaction validation.

## Configuration

`resources/config.yml` is copied to the plugin data directory on first load.

| Key | Default | Meaning |
| --- | ---: | --- |
| `request-timeout-seconds` | `60` | Pending-request expiry; accepted range 10–300. |
| `trade-timeout-seconds` | `300` | Active-session expiry; accepted range 30–900. |
| `max-offer-slots` | `8` | Maximum distinct source slots per participant; accepted range 1–36. |
| `max-items-per-offer` | `64` | Upper bound on item count in any one offer; accepted range 1–4096 and further capped by the stack's real count and item max stack size. |
| `max-trades-per-minute` | `8` | Maximum new request attempts accepted per rolling 60-second window. |
| `cooldown-seconds` | `3` | Minimum interval between a player's new requests; accepted range 0–60. |
| `allow-self-trade` | `false` | Self-trading is hard-disabled. If set to `true`, the plugin warns and still refuses self-trading. |
| `messages-prefix` | `§8[§bTradeSystem§8] §r` | Chat message prefix; max length 64 characters. |
| `sound-effects` | `false` | Plays a small public-API sound for trade interactions. |
| `enable-audit-log` | `true` | Appends compact results and UUIDs to `plugin_data/TradeSystem/trade-audit.log`. No full NBT is logged. |

Invalid configuration values fall back to safe defaults and emit a warning in the server log. Combat gating is not configured because this project does not assume a third-party combat plugin.

## How a trade works

1. One player sends a request; the recipient accepts or denies it.
2. Both players independently offer stacks from their normal player inventories. Item data and amount are read by the server; form data only selects from server-provided choices.
3. Both players review both offers and confirm.
4. On the final confirmation, the plugin verifies the session state and both participants, compares each current source slot with the recorded item snapshot, and checks the exact original stack count.
5. It simulates both post-trade inventories after outgoing items are removed, then verifies incoming capacity.
6. Only after preflight succeeds does it synchronously apply both inventory plans. It verifies the resulting contents; if application throws or verification fails, it tries to restore both captured inventories.
7. Requests/sessions are expired or cleaned on timeout, quit, cancellation, successful completion, or failure. Stale forms carry a session ID and cannot act on a different session.

Before final commit, offered items remain where they were and are not escrowed or dropped into the world. This means ordinary cancellation and disconnects do not require item refunds.

## Security model and known limits

- All transaction decisions and inventory changes execute synchronously on the server thread.
- UUIDs, not display names, are the identity keys for requests and sessions.
- Items are compared using PMMP's public item equality with damage and compound-tag checks plus an explicit count check.
- A source stack must still match its exact snapshot at commit time. If it has changed, the trade is blocked and players must review/edit the offer.
- No offer can be double-booked through multiple entries for the same source slot.
- A temporary `SimpleInventory` is used only for capacity simulation; it never holds players' trade items.
- Snapshot rollback is best-effort protection for an in-process failure. Updates across two separate inventories are not crash-atomic if the PHP process or host terminates abruptly between updates. A durable write-ahead journal and recovery protocol would be needed to cover that stronger failure model.
- If rollback verification fails, both participants are persisted in `plugin_data/TradeSystem/trade-quarantine.json` and cannot trade again until an administrator has reconciled their inventories. An additional `.unsafe` lock file persists unsafe storage states across restarts. If neither quarantine nor safety lock can be written, the server is asked to stop and a critical log entry is emitted. If any exception escapes the executor's own recovery boundary, the outcome is treated as unknown: the session is terminated and both participants are quarantined instead of claiming that inventories were restored.
- **Recovery procedure:** stop the server, inspect the critical log and both players' saved inventories, manually reconcile any discrepancy from trusted backups, then remove only the repaired players' UUID entries from `trade-quarantine.json` and remove `trade-quarantine.json.unsafe` only after the full quarantine has been reviewed. Delete stale `.tmp` files only after reconciliation. Restart and verify the logs before enabling player trading. Do not clear quarantine merely to restore access without checking item conservation.
- This repository has automated domain/state smoke tests but does not claim to have run a live PMMP server integration test. Follow `tests/README.md` before production rollout.

## Build, lint, static analysis, and tests

```bash
php -l src/TradSystem/Main.php
find src tests -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/run.php
composer validate --no-check-publish
```

For API-aware static analysis, use a PHP runtime configured for PocketMine-MP API 5 and run:

```bash
cd phpstan
composer update --prefer-dist
vendor/bin/phpstan analyse --no-progress
```

The separate `phpstan/` development environment pins the PMMP API reference to 5.44.4 and PHPStan to 2.1.46; these are analysis-only dependencies, not runtime plugin dependencies. GitHub Actions runs this analysis separately from the PHP 8.1–8.4 syntax/smoke-test matrix. The first dependency resolution generates a local `phpstan/composer.lock`; retain that lock in local workflows to make subsequent analyses reproducible. A staging PocketMine-MP API 5 server is still required to prove actual Bedrock form behavior and inventory integration. The full 21-scenario integration plan is in [`tests/README.md`](tests/README.md).

## Project structure

```text
plugin.yml
composer.json
resources/
  config.yml
  messages.yml
src/TradSystem/
  Main.php
  command/TradeCommand.php
  config/TradeConfig.php
  form/                         # Dependency-free Simple/Custom form implementations and screens
  listener/PlayerListener.php
  trade/                        # Requests, state machine, manager, transaction executor, quarantine store
  util/ItemFingerprint.php
  util/Messages.php
tests/
  run.php
  README.md
.github/workflows/tests.yml
```

## License

No open-source license was provided for this repository. The Composer metadata uses `proprietary` to avoid falsely declaring an SPDX open-source license; this does not grant open-source redistribution rights. Obtain permission from the repository owner before redistributing or reusing the code beyond rights provided by applicable law.
