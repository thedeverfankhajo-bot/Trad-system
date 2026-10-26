<?php

declare(strict_types=1);

use TradSystem\trade\TradeRequest;
use TradSystem\trade\TradeSession;
use TradSystem\trade\TradeState;
use TradSystem\trade\TradeQuarantineStore;
use TradSystem\util\ItemDisplayName;
use TradSystem\util\MessageFormatter;

require __DIR__ . '/../src/TradSystem/trade/TradeState.php';
require __DIR__ . '/../src/TradSystem/trade/TradeSession.php';
require __DIR__ . '/../src/TradSystem/trade/TradeRequest.php';
require __DIR__ . '/../src/TradSystem/trade/TradeQuarantineStore.php';
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

$request = TradeRequest::create('uuid-a', 'uuid-b', 'Alice', 'Bob', 100);
$assert($request->senderUuid === 'uuid-a' && $request->receiverUuid === 'uuid-b', 'A request retains the correct UUID parties.');
$assert($request->isExpired(99) === false, 'A request is valid before its expiry timestamp.');
$assert($request->isExpired(100) === true, 'A request expires at its expiry timestamp.');
$assert(strlen($request->id) === 32, 'Request IDs use a 128-bit random hexadecimal token.');

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
    'src/TradSystem/trade/TradeExecutor.php', 'src/TradSystem/form/SimpleForm.php',
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
$assert(str_contains($executorSource, '$inventory->setContents($contents);'), 'Inventory plans are applied as a complete contents snapshot.');
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
