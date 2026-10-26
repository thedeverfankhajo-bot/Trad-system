<?php

declare(strict_types=1);

use TradSystem\trade\TradeRequest;
use TradSystem\trade\TradeSession;
use TradSystem\trade\TradeState;

require __DIR__ . '/../src/TradSystem/trade/TradeState.php';
require __DIR__ . '/../src/TradSystem/trade/TradeSession.php';
require __DIR__ . '/../src/TradSystem/trade/TradeRequest.php';

$tests = 0;
$failures = [];
$assert = static function(bool $condition, string $description) use (&$tests, &$failures) : void{
    ++$tests;
    if(!$condition){
        $failures[] = $description;
    }
};

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
$manifest = (string) file_get_contents($root . '/plugin.yml');
$assert(str_contains($manifest, 'name: TradeSystem'), 'plugin.yml declares the plugin name.');
$assert(str_contains($manifest, 'main: TradSystem\\Main'), 'plugin.yml points to the Main class.');
$managerSource = (string) file_get_contents($root . '/src/TradSystem/trade/TradeManager.php');
$amountFormSource = (string) file_get_contents($root . '/src/TradSystem/form/OfferAmountForm.php');
$assert(str_contains($managerSource, 'int $expectedRevision') && str_contains($amountFormSource, '$expectedRevision'), 'Quantity-form callbacks are bound to the trade-offer revision.');
$executorSource = (string) file_get_contents($root . '/src/TradSystem/trade/TradeExecutor.php');
$assert(str_contains($executorSource, 'setMaxStackSize($source->getMaxStackSize())'), 'Capacity simulation mirrors the source inventory max stack size.');
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
