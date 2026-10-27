<?php

declare(strict_types=1);

use TradSystem\trade\TradeRequest;
use TradSystem\trade\TradeSession;
use TradSystem\trade\TradeState;
use TradSystem\trade\TradeJournalStore;
use TradSystem\trade\TradeQuarantineStore;
use TradSystem\util\ItemDisplayName;
use TradSystem\util\MessageFormatter;

require __DIR__ . '/stubs/Item.php';
require __DIR__ . '/../src/TradSystem/trade/TradeOffer.php';
require __DIR__ . '/../src/TradSystem/trade/TradeState.php';
require __DIR__ . '/../src/TradSystem/trade/TradeSession.php';
require __DIR__ . '/../src/TradSystem/trade/TradeRequest.php';
require __DIR__ . '/../src/TradSystem/trade/TradeQuarantineStore.php';
require __DIR__ . '/../src/TradSystem/trade/TradeJournalStore.php';
require __DIR__ . '/../src/TradSystem/util/MessageFormatter.php';
require __DIR__ . '/../src/TradSystem/util/ItemDisplayName.php';

$tests = 0;
$failures = [];
$assert = static function(bool $condition, string $description) use (&$tests, &$failures) : void{
    ++$tests;
    if(!$condition){
        $failures[] = $description;
    }
};

$assert(
    MessageFormatter::interpolate('Offer %item% x%count%', ['item' => '%count% §cSword', 'count' => 3]) === 'Offer %count% §cSword x3',
    'Template replacement values are inserted once and are not re-interpolated.'
);
$assert(
    ItemDisplayName::sanitize("§cDiamond\n§lSword") === 'Diamond Sword',
    'Item display names cannot inject formatting codes or extra form lines.'
);
$assert(
    ItemDisplayName::sanitize("§bAdmin\nSlot %count%\u{202E}") === 'Admin Slot %count%',
    'Item display labels neutralize line breaks and bidirectional override controls.'
);

$session = new TradeSession('session-1', 'uuid-a', 'uuid-b', 'Alice', 'Bob', time() + 300);
$assert($session->getState() === TradeState::OFFERING, 'A new session starts in OFFERING.');
$assert($session->getRevision() === 0, 'A new session begins at revision zero.');
$assert($session->confirm('stranger') === false, 'A non-participant cannot confirm.');
$assert($session->confirm('uuid-a') === true, 'The first participant can confirm.');
$assert($session->getState() === TradeState::CONFIRMING, 'A first confirmation moves the state to CONFIRMING.');
$assert($session->bothConfirmed() === false, 'One confirmation is not sufficient.');
$assert($session->beginCommit() === false, 'Commit is blocked until both players confirm.');
$assert($session->confirm('uuid-b') === true, 'The second participant can confirm.');
$assert($session->bothConfirmed() === true, 'Both confirmations are recorded.');
$assert($session->beginCommit() === true, 'Commit starts only after both confirmations.');
$assert($session->getState() === TradeState::COMMITTING, 'The state becomes COMMITTING before execution.');
$assert($session->beginCommit() === false, 'A second commit attempt is rejected.');
$assert($session->markCompleted() === true, 'A committing session can complete.');
$assert($session->getState() === TradeState::COMPLETED, 'The completed state is terminal.');
$assert($session->cancel() === false, 'A completed session cannot be cancelled again.');

$recoverable = new TradeSession('session-2', 'uuid-a', 'uuid-b', 'Alice', 'Bob', time() + 300);
$recoverable->confirm('uuid-a');
$recoverable->confirm('uuid-b');
$recoverable->resetAfterValidationFailure();
$assert($recoverable->getState() === TradeState::OFFERING, 'Validation failure resets the session to OFFERING.');
$assert($recoverable->getRevision() === 1, 'Validation failure invalidates previously rendered forms by advancing the revision.');
$assert($recoverable->isConfirmed('uuid-a') === false && $recoverable->isConfirmed('uuid-b') === false, 'Validation failure clears both confirmations.');
$assert($recoverable->confirm('uuid-a') === true, 'A session can be reviewed and confirmed again after validation failure.');
$assert($recoverable->cancel() === true, 'An active session can be cancelled.');
$assert($recoverable->getState() === TradeState::CANCELLED, 'Cancellation marks the state terminal.');

$commitLocked = new TradeSession('session-3', 'uuid-a', 'uuid-b', 'Alice', 'Bob', time() + 300);
$commitLocked->confirm('uuid-a');
$commitLocked->confirm('uuid-b');
$assert($commitLocked->beginCommit() === true, 'A dedicated session enters COMMITTING before executor resolution.');
$assert($commitLocked->cancel() === false, 'Ordinary cancellation cannot interrupt an in-flight commit.');
$assert($commitLocked->getState() === TradeState::COMMITTING, 'A rejected cancellation leaves the in-flight commit state unchanged.');
$assert($commitLocked->abortCommit() === true, 'A failed executor result can explicitly abort the commit after recovery.');
$assert($commitLocked->getState() === TradeState::CANCELLED, 'An explicitly aborted commit becomes terminal.');
$assert($commitLocked->abortCommit() === false, 'A resolved commit cannot be aborted a second time.');

$request = TradeRequest::create('uuid-a', 'uuid-b', 'Alice', 'Bob', 100);
$assert($request->senderUuid === 'uuid-a' && $request->receiverUuid === 'uuid-b', 'A request retains the correct UUID parties.');
$assert($request->isExpired(99) === false, 'A request is valid before its expiry timestamp.');
$assert($request->isExpired(100) === true, 'A request expires at its expiry timestamp.');
$assert(strlen($request->id) === 32, 'Request IDs use a 128-bit random hexadecimal token.');
$offerSession = new TradeSession('session-offers', 'uuid-a', 'uuid-b', 'Alice', 'Bob', time() + 300);
$originalItem = new \pocketmine\item\Item(5, 'Diamond');
$assert($offerSession->setOffer('uuid-a', 4, 3, $originalItem), 'A participant can add an offer for a source slot.');
$firstOffer = $offerSession->getOffer('uuid-a', 4);
$transferItem = $firstOffer?->getTransferItem();
$assert(
    $firstOffer?->getSourceCount() === 5 && $transferItem?->getCount() === 3 && $originalItem->getCount() === 5,
    'A transfer item copies the offered amount without changing the source item or saved source snapshot.'
);
$originalItem->setCount(7);
$assert(
    $firstOffer?->getSourceCount() === 5 && $firstOffer?->getTransferItem()->getCount() === 3,
    'Offer snapshots and transfer items are isolated clones rather than references to a mutable source item.'
);
$assert($offerSession->getRevision() === 1, 'Adding an offer advances the form revision.');
$assert(
    $offerSession->setOffer('uuid-a', 4, 2, new \pocketmine\item\Item(5, 'Diamond')),
    'Updating an existing source slot replaces its offer.'
);
$assert(
    count($offerSession->getOffersFor('uuid-a')) === 1 &&
    $offerSession->getOffer('uuid-a', 4)?->offeredCount === 2,
    'A source slot cannot be represented by multiple offer entries.'
);
$assert($offerSession->setOffer('uuid-a', 7, 1, new \pocketmine\item\Item(1, 'Iron')), 'A separate source slot can be offered.');
$assert(count($offerSession->getOffersFor('uuid-a')) === 2, 'Different source slots remain separate offer entries.');
$revisionBeforeInvalidOffers = $offerSession->getRevision();
$assert(!$offerSession->setOffer('stranger', 8, 1, new \pocketmine\item\Item()), 'A non-participant cannot add an offer.');
$assert(!$offerSession->setOffer('uuid-a', -1, 1, new \pocketmine\item\Item()), 'Negative source slots are rejected by the session state model.');
$assert(!$offerSession->setOffer('uuid-a', 8, 0, new \pocketmine\item\Item()), 'Zero-sized offers are rejected by the session state model.');
$assert($offerSession->getRevision() === $revisionBeforeInvalidOffers, 'Rejected offer edits do not advance the revision.');
$assert($offerSession->confirm('uuid-a') && $offerSession->confirm('uuid-b'), 'Both participants can confirm the current offer revision.');
$revisionBeforeEdit = $offerSession->getRevision();
$assert(
    $offerSession->setOffer('uuid-a', 4, 1, new \pocketmine\item\Item(5, 'Diamond')),
    'An offer can be changed after confirmation.'
);
$assert(
    $offerSession->getRevision() === $revisionBeforeEdit + 1 &&
    !$offerSession->isConfirmed('uuid-a') &&
    !$offerSession->isConfirmed('uuid-b') &&
    $offerSession->getState() === TradeState::OFFERING,
    'Changing an offer invalidates both confirmations and advances the revision.'
);
$revisionBeforeRemoval = $offerSession->getRevision();
$assert($offerSession->removeOffer('uuid-a', 4), 'A participant can remove their own offer.');
$assert(
    $offerSession->getOffer('uuid-a', 4) === null &&
    $offerSession->getRevision() === $revisionBeforeRemoval + 1,
    'Removing an offer clears its source slot entry and advances the revision.'
);
$assert(!$offerSession->removeOffer('stranger', 7), 'A non-participant cannot remove another player offer.');
$assert($offerSession->cancel(), 'The offer test session can be cancelled.');
$assert(!$offerSession->setOffer('uuid-a', 4, 1, new \pocketmine\item\Item()), 'A terminal session rejects further offer edits.');

$journalPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tradesystem-journal-' . bin2hex(random_bytes(8)) . '.json';
$journalErrors = [];
$onJournalError = static function(string $message) use (&$journalErrors) : void{
    $journalErrors[] = $message;
};
$beforeA = ['size' => 3, 'slots' => [0 => base64_encode('before-a')]];
$beforeB = ['size' => 3, 'slots' => [1 => base64_encode('before-b')]];
$afterA = ['size' => 3, 'slots' => [2 => base64_encode('after-a')]];
$afterB = ['size' => 3, 'slots' => [0 => base64_encode('after-b')]];
$journalStore = new TradeJournalStore($journalPath, $onJournalError);
$assert(!$journalStore->isUnsafe() && !$journalStore->hasPendingTransaction(), 'A missing trade journal starts with no pending transaction.');
$assert($journalStore->prepare('uuid-a', 'uuid-b', $beforeA, $beforeB, $afterA, $afterB), 'A valid transaction can be written durably as PREPARED.');
$assert(is_file($journalPath), 'Preparing a transaction creates its canonical journal file.');
$assert(($journalStore->getRecord()['state'] ?? null) === 'PREPARED', 'A prepared journal records that recovery must restore before-states.');
$assert(!$journalStore->prepare('uuid-c', 'uuid-d', $beforeA, $beforeB, $afterA, $afterB), 'The journal refuses a second transaction while the first is unresolved.');
$loadedPrepared = new TradeJournalStore($journalPath, $onJournalError);
$assert(!$loadedPrepared->isUnsafe() && ($loadedPrepared->getRecord()['transaction_id'] ?? null) === ($journalStore->getRecord()['transaction_id'] ?? null), 'A complete journal reloads with the same transaction ID.');
$assert($loadedPrepared->markCommitted(), 'A prepared journal can be transitioned to COMMITTED.');
$assert(($loadedPrepared->getRecord()['state'] ?? null) === 'COMMITTED', 'COMMITTED records the after-state decision.');
$loadedCommitted = new TradeJournalStore($journalPath, $onJournalError);
$assert(!$loadedCommitted->isUnsafe() && ($loadedCommitted->getRecord()['state'] ?? null) === 'COMMITTED', 'The committed decision survives journal reconstruction.');
$assert($loadedCommitted->markRecovered('uuid-a'), 'The first participant recovery acknowledgement can be persisted.');
$assert(!$loadedCommitted->bothParticipantsRecovered(), 'One recovered participant is not enough to clear the journal.');
$assert($loadedCommitted->markRecovered('uuid-b'), 'The second participant recovery acknowledgement can be persisted.');
$assert($loadedCommitted->bothParticipantsRecovered(), 'The journal identifies when both committed snapshots are recovered.');
$assert($loadedCommitted->clearIfBothRecovered(), 'The resolved committed journal can be removed after both recovery acknowledgements.');
$assert(!is_file($journalPath), 'Clearing a resolved journal removes the canonical record.');
$emptyJournal = new TradeJournalStore($journalPath, $onJournalError);
$assert(!$emptyJournal->isUnsafe() && !$emptyJournal->hasPendingTransaction(), 'A cleared journal returns to an empty safe state.');
$assert($emptyJournal->prepare('uuid-a', 'uuid-b', $beforeA, $beforeB, $afterA, $afterB), 'A new PREPARED transaction can start after prior cleanup.');
$assert($emptyJournal->clearAfterRollback(), 'A verified rollback can clear a PREPARED transaction.');
@unlink($journalPath);
@unlink($journalPath . '.tmp');
@unlink($journalPath . '.unsafe');

$corruptJournalPath = $journalPath . '.corrupt';
file_put_contents($corruptJournalPath, '{"version":1,"state":"PREPARED"}');
$corruptJournal = new TradeJournalStore($corruptJournalPath, $onJournalError);
$assert($corruptJournal->isUnsafe(), 'Malformed transaction journal data disables journal-backed trading.');
$assert(is_file($corruptJournalPath . '.unsafe'), 'Malformed transaction journal data creates a persistent safety lock.');
$lockedJournal = new TradeJournalStore($corruptJournalPath, $onJournalError);
$assert($lockedJournal->isUnsafe(), 'The journal safety lock persists across store reconstruction.');
@unlink($corruptJournalPath);
@unlink($corruptJournalPath . '.tmp');
@unlink($corruptJournalPath . '.unsafe');

$tamperedJournalPath = $journalPath . '.tampered';
$tamperedJournal = new TradeJournalStore($tamperedJournalPath, $onJournalError);
$assert($tamperedJournal->prepare('uuid-a', 'uuid-b', $beforeA, $beforeB, $afterA, $afterB), 'A journal can be prepared for integrity-tampering detection.');
$tamperedContent = (string) file_get_contents($tamperedJournalPath);
$tamperedContent = str_replace('"state": "PREPARED"', '"state": "COMMITTED"', $tamperedContent);
file_put_contents($tamperedJournalPath, $tamperedContent);
$rejectedTampering = new TradeJournalStore($tamperedJournalPath, $onJournalError);
$assert($rejectedTampering->isUnsafe(), 'A journal whose decision is changed without recomputing its checksum is rejected.');
@unlink($tamperedJournalPath);
@unlink($tamperedJournalPath . '.tmp');
@unlink($tamperedJournalPath . '.unsafe');

$assert($journalErrors !== [], 'Journal storage issues are reported through the supplied logging callback.');

$quarantinePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tradesystem-quarantine-' . bin2hex(random_bytes(8)) . '.json';
$quarantineErrors = [];
$onQuarantineError = static function(string $message) use (&$quarantineErrors) : void{
    $quarantineErrors[] = $message;
};
$quarantineStore = new TradeQuarantineStore($quarantinePath, $onQuarantineError);
$assert(!$quarantineStore->isUnsafe(), 'A missing quarantine file is a safe empty starting state.');
$assert($quarantineStore->quarantine('uuid-a', 'inventory-rollback-failed') === true, 'A rollback failure is persisted to the quarantine file.');
$assert(!is_file($quarantinePath . '.unsafe'), 'A successful quarantine write does not leave the persistent safety-lock marker.');
$assert($quarantineStore->contains('uuid-a'), 'A quarantined UUID is blocked in the current process.');
$reloadedQuarantine = new TradeQuarantineStore($quarantinePath, $onQuarantineError);
$assert(!$reloadedQuarantine->isUnsafe() && $reloadedQuarantine->contains('uuid-a'), 'Quarantine survives reconstruction across plugin restarts.');
$assert(is_file($quarantinePath), 'The quarantine store is written to disk.');
@unlink($quarantinePath);

$corruptQuarantinePath = $quarantinePath . '.corrupt';
file_put_contents($corruptQuarantinePath, '{ invalid JSON');
$corruptQuarantine = new TradeQuarantineStore($corruptQuarantinePath, $onQuarantineError);
$assert($corruptQuarantine->isUnsafe(), 'Corrupt quarantine data activates fail-closed mode.');
$assert(is_file($corruptQuarantinePath . '.unsafe'), 'Corrupt quarantine data creates a persistent safety-lock marker.');
$lockedCorrupt = new TradeQuarantineStore($corruptQuarantinePath, $onQuarantineError);
$assert($lockedCorrupt->isUnsafe(), 'The persisted lock keeps trading disabled after a new store instance is created.');
$assert($corruptQuarantine->quarantine('uuid-b', 'inventory-rollback-failed') === false, 'An unsafe quarantine store refuses to claim that a new quarantine is persisted.');
$assert($quarantineErrors !== [], 'Quarantine persistence failures are reported through the supplied logger callback.');
@unlink($corruptQuarantinePath);
@unlink($corruptQuarantinePath . '.tmp');
@unlink($corruptQuarantinePath . '.unsafe');

$listQuarantinePath = $quarantinePath . '.list';
file_put_contents($listQuarantinePath, '[]');
$listQuarantine = new TradeQuarantineStore($listQuarantinePath, $onQuarantineError);
$assert($listQuarantine->isUnsafe(), 'A JSON list is rejected as a malformed quarantine root rather than treated as an empty store.');
@unlink($listQuarantinePath);
@unlink($listQuarantinePath . '.unsafe');

$pendingMarkerPath = $quarantinePath . '.pending';
file_put_contents($pendingMarkerPath . '.tmp', '{"uuid-a":');
file_put_contents($pendingMarkerPath, '{}');
$pendingMarker = new TradeQuarantineStore($pendingMarkerPath, $onQuarantineError);
$assert($pendingMarker->isUnsafe(), 'A surviving temporary quarantine write prevents re-enabling trades after restart.');
@unlink($pendingMarkerPath);
@unlink($pendingMarkerPath . '.tmp');
@unlink($pendingMarkerPath . '.unsafe');

$missingDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tradesystem-missing-' . bin2hex(random_bytes(8));
$unwritableQuarantinePath = $missingDirectory . DIRECTORY_SEPARATOR . 'trade-quarantine.json';
$unwritableStore = new TradeQuarantineStore($unwritableQuarantinePath, $onQuarantineError);
$assert($unwritableStore->quarantine('uuid-c', 'inventory-rollback-failed') === false, 'An unavailable quarantine directory must not claim persistence success.');
$assert($unwritableStore->isUnsafe(), 'A quarantine write failure permanently marks the current store unsafe.');
$assert(
    count(array_filter($quarantineErrors, static fn(string $message) : bool => str_contains($message, 'Unable to persist safety marker'))) > 0,
    'Failure to persist the independent safety lock is reported for emergency server shutdown.'
);

$root = dirname(__DIR__);
$requiredFiles = [
    'plugin.yml', 'composer.json', 'README.md', 'resources/config.yml', 'resources/messages.yml',
    'src/TradSystem/Main.php', 'src/TradSystem/trade/TradeManager.php',
    'src/TradSystem/trade/TradeExecutor.php', 'src/TradSystem/trade/TradeJournalStore.php',
    'src/TradSystem/trade/TradePlayerDataSaver.php', 'src/TradSystem/util/InventorySnapshotCodec.php',
    'src/TradSystem/form/SimpleForm.php', 'tools/build-phar.php',
    'tests/integration/plugin.yml', 'tests/integration/README.md',
    'tests/integration/src/TradeSystemIntegrationHarness/Main.php',
    '.github/workflows/tests.yml'
];
foreach($requiredFiles as $file){
    $assert(is_file($root . '/' . $file), 'Required project file exists: ' . $file);
}
$composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
$assert(is_array($composer) && ($composer['name'] ?? null) === 'thedeverfankhajo-bot/trade-system', 'composer.json is valid JSON and has the expected package name.');
$assert(($composer['license'] ?? null) === 'proprietary', 'composer.json identifies the project as proprietary instead of claiming an open-source SPDX license.');
$manifest = (string) file_get_contents($root . '/plugin.yml');
$assert(str_contains($manifest, 'name: TradeSystem'), 'plugin.yml declares the plugin name.');
$assert(str_contains($manifest, 'main: TradSystem\\Main'), 'plugin.yml points to the Main class.');
$managerSource = (string) file_get_contents($root . '/src/TradSystem/trade/TradeManager.php');

$journalStoreSource = (string) file_get_contents($root . '/src/TradSystem/trade/TradeJournalStore.php');
$executorJournalSource = (string) file_get_contents($root . '/src/TradSystem/trade/TradeExecutor.php');
$mainSource = (string) file_get_contents($root . '/src/TradSystem/Main.php');
$playerListenerSource = (string) file_get_contents($root . '/src/TradSystem/listener/PlayerListener.php');
$assert(
    str_contains($executorJournalSource, 'InventorySnapshotCodec::capture($inventoryOne)') &&
    strpos($executorJournalSource, '$this->journal->prepare(') < strpos($executorJournalSource, '$this->apply($inventoryOne, $planOne)'),
    'The executor persists before/after inventory snapshots in PREPARED before applying either inventory plan.'
);
$assert(
    str_contains($journalStoreSource, 'hash_equals($checksum, hash("sha256", $canonical))') &&
    str_contains($journalStoreSource, 'fflush($handle)') &&
    str_contains($journalStoreSource, 'fsync($handle)') &&
    str_contains($journalStoreSource, '@rename($temporaryPath, $this->path)'),
    'The journal validates its checksum and uses a complete write, fsync, and atomic replacement.'
);
$assert(
    str_contains($executorJournalSource, '$this->journal->markCommitted()') &&
    str_contains($executorJournalSource, '$this->rollbackPreparedTransaction(') &&
    str_contains($executorJournalSource, '$this->journal->clearAfterCommittedSave()'),
    'The executor records COMMITTED only after verifying both after-states and retains the journal through saves.'
);
$assert(
    str_contains($managerSource, 'recoverJournalFor(Player $player)') &&
    str_contains($managerSource, 'isJournalBlocked()') &&
    str_contains($playerListenerSource, 'onPlayerJoin(PlayerJoinEvent $event)') &&
    str_contains($mainSource, 'enforceRecoveryBarrier()'),
    'An unresolved journal gates new trades and is recovered at player join before normal gameplay continues.'
);


$lookupStart = strpos($managerSource, 'private function findOnlinePlayerByUuid(string $uuid) : ?Player{');
$lookupEnd = $lookupStart !== false ? strpos($managerSource, "\n    private function playSound", $lookupStart) : false;
$uuidLookupBody = ($lookupStart !== false && $lookupEnd !== false) ? substr($managerSource, $lookupStart, $lookupEnd - $lookupStart) : '';
$assert(
    str_contains($managerSource, 'return $this->plugin->getServer()->getPlayerExact($name);') &&
    str_contains($uuidLookupBody, '->getPlayerByUUID($parsedUuid)') &&
    !str_contains($uuidLookupBody, 'getOnlinePlayers()'),
    'Player name and UUID resolution use PMMP indexed lookup APIs rather than repeatedly scanning every online player.'
);

$assert(
    str_contains($managerSource, 'CRITICAL_EXECUTOR_EXCEPTION') &&
    str_contains($managerSource, 'inventory outcome is unknown') &&
    str_contains($managerSource, '$this->quarantinePlayer($session->partyOneUuid)') &&
    str_contains($managerSource, '$this->quarantinePlayer($session->partyTwoUuid)'),
    'An exception escaping executor recovery must quarantine both participants because inventory state is unknown.'
);
$amountFormSource = (string) file_get_contents($root . '/src/TradSystem/form/OfferAmountForm.php');
$assert(str_contains($managerSource, 'int $expectedRevision') && str_contains($amountFormSource, '$expectedRevision'), 'Quantity-form callbacks are bound to the trade-offer revision.');
$selectionSource = (string) file_get_contents($root . '/src/TradSystem/form/PlayerSelectionForm.php');
$assert(str_contains($managerSource, 'function sendRequestToUuid') && str_contains($selectionSource, '$targetUuids[$index]'), 'Player selection callbacks resolve the captured UUID, not just a display name.');
$executorSource = (string) file_get_contents($root . '/src/TradSystem/trade/TradeExecutor.php');
$assert(str_contains($executorSource, 'setMaxStackSize($source->getMaxStackSize())'), 'Capacity simulation mirrors the source inventory max stack size.');
$fingerprintSource = (string) file_get_contents($root . '/src/TradSystem/util/ItemFingerprint.php');
$assert(
    str_contains($fingerprintSource, '$current->equalsExact($snapshot)') &&
    !str_contains($fingerprintSource, 'equals($snapshot, true, true)'),
    'Item snapshot matching uses exact public comparison without deprecated equality arguments.'
);
$assert(str_contains($managerSource, 'ItemDisplayName::sanitize($offer->getItemName())'), 'Offer summaries sanitize item-controlled display names.');
$assert(str_contains($managerSource, 'private bool $shuttingDown = false;') && str_contains($managerSource, '$this->shuttingDown = true;'), 'Shutdown sets a persistent in-memory guard against stale callbacks.');
$assert(str_contains($managerSource, 'public function isShuttingDown() : bool{'), 'The UI can query whether callback work must be ignored.');
$assert(substr_count($managerSource, 'public function confirmTrade(') === 1 && substr_count($managerSource, 'public function cancelTrade(') === 1, 'Confirmation and cancellation entrypoints remain distinct and unique.');
$mainFormSource = (string) file_get_contents($root . '/src/TradSystem/form/MainForm.php');
foreach([
    'src/TradSystem/form/MainForm.php',
    'src/TradSystem/form/IncomingRequestsForm.php',
    'src/TradSystem/form/OfferAmountForm.php',
    'src/TradSystem/form/PlayerSelectionForm.php',
    'src/TradSystem/form/RemoveOfferForm.php',
    'src/TradSystem/form/SlotSelectionForm.php',
    'src/TradSystem/form/TradeConfirmationForm.php',
    'src/TradSystem/form/TradeOfferForm.php'
] as $formPath){
    $formSource = (string) file_get_contents($root . '/' . $formPath);
    $assert(str_contains($formSource, 'if($manager->isShuttingDown()){'), 'Form rendering is blocked during shutdown: ' . $formPath);
}
$shutdownStart = strpos($managerSource, 'public function shutdown() : void{');
$shutdownEnd = $shutdownStart !== false ? strpos($managerSource, 'private function startSession', $shutdownStart) : false;
$shutdownBody = ($shutdownStart !== false && $shutdownEnd !== false) ? substr($managerSource, $shutdownStart, $shutdownEnd - $shutdownStart) : '';
$assert(
    $shutdownBody !== '' &&
    str_contains($shutdownBody, 'TradeState::COMMITTING') &&
    !str_contains($shutdownBody, '$this->activeByPlayer = [];') &&
    !str_contains($shutdownBody, '$this->sessions = [];'),
    'Shutdown preserves the owner/session references for an in-flight commit until the executor result resolves.'
);
$assert(str_contains($managerSource, "if(\$this->shuttingDown){\n                \$session->abortCommit();") && str_contains($managerSource, "ABORTED_DURING_SHUTDOWN"), 'A failed preflight during shutdown terminates without reopening a stale form.');
$assert(str_contains($executorSource, '$inventory->setContents($contents);'), 'Inventory plans are applied as a complete contents snapshot.');
$restoreBothStart = strpos($executorSource, 'private function restoreBoth(');
$restoreBothEnd = $restoreBothStart !== false ? strpos($executorSource, 'private function restore(', $restoreBothStart) : false;
$restoreBothBody = ($restoreBothStart !== false && $restoreBothEnd !== false) ? substr($executorSource, $restoreBothStart, $restoreBothEnd - $restoreBothStart) : '';
$secondRestorePosition = strpos($restoreBothBody, '$restoredTwo = $this->restore($inventoryTwo, $beforeTwo);');
$verifyOnePosition = strpos($restoreBothBody, '$this->contentsMatchSafely($inventoryOne, $beforeOne)');
$verifyTwoPosition = strpos($restoreBothBody, '$this->contentsMatchSafely($inventoryTwo, $beforeTwo)');
$assert(
    $secondRestorePosition !== false &&
    $verifyOnePosition !== false &&
    $verifyTwoPosition !== false &&
    $verifyOnePosition > $secondRestorePosition &&
    $verifyTwoPosition > $secondRestorePosition,
    'Rollback revalidates both inventories after both restoration operations so cross-inventory listener effects cannot pass unnoticed.'
);
$integrationHarnessSource = (string) file_get_contents($root . '/tests/integration/src/TradeSystemIntegrationHarness/Main.php');
$integrationManifest = (string) file_get_contents($root . '/tests/integration/plugin.yml');
$assert(
    str_contains($integrationManifest, 'usage: "/tradeint <success|capacity|stale-count|stale-meta|stale-type|stale-slot> <other-player>"'),
    'Integration harness command usage lists all supported inventory scenarios.'
);
$assert(
    str_contains($integrationHarnessSource, '["success", "capacity", "stale-count", "stale-meta", "stale-type", "stale-slot"]') &&
    str_contains($integrationHarnessSource, '$changed->setCount(4)') &&
    str_contains($integrationHarnessSource, '$changed->setCustomName("Changed after offer")') &&
    str_contains($integrationHarnessSource, '$changed = VanillaItems::GOLD_INGOT();') &&
    str_contains($integrationHarnessSource, '$firstInventory->clear(0);') &&
    str_contains($integrationHarnessSource, '$firstInventory->setItem(1, $changed);') &&
    str_contains($integrationHarnessSource, '$result->reason === "inventory-changed"'),
    'Integration harness covers stale count, metadata, item type, and source-slot movement in live inventory scenarios.'
);
$integrationRestoreSecond = strpos($integrationHarnessSource, '$restoredSecond = $this->restore($secondInventory, $beforeSecond);');
$integrationFinalFirst = strpos($integrationHarnessSource, '$finalFirstMatches = $this->matchesSnapshot($firstInventory, $beforeFirst);');
$integrationFinalSecond = strpos($integrationHarnessSource, '$finalSecondMatches = $this->matchesSnapshot($secondInventory, $beforeSecond);');
$assert(
    $integrationRestoreSecond !== false &&
    $integrationFinalFirst !== false &&
    $integrationFinalSecond !== false &&
    $integrationFinalFirst > $integrationRestoreSecond &&
    $integrationFinalSecond > $integrationRestoreSecond &&
    str_contains($integrationHarnessSource, '!$finalFirstMatches || !$finalSecondMatches'),
    'Integration harness verifies both original inventories after both cleanup restores finish.'
);

$config = (string) file_get_contents($root . '/resources/config.yml');
foreach(['request-timeout-seconds', 'trade-timeout-seconds', 'max-offer-slots', 'max-items-per-offer', 'max-trades-per-minute', 'cooldown-seconds', 'allow-self-trade', 'messages-prefix', 'sound-effects', 'enable-audit-log'] as $key){
    $assert(str_contains($config, $key . ':'), 'config.yml contains required setting ' . $key . '.');
}

if($failures !== []){
    fwrite(STDERR, 'FAILED: ' . count($failures) . ' of ' . $tests . " assertions failed.\n");
    foreach($failures as $failure){
        fwrite(STDERR, ' - ' . $failure . "\n");
    }
    exit(1);
}
printf("PASS: %d assertions succeeded. (Domain-state and project-structure smoke tests; not a live PMMP inventory test.)\n", $tests);
