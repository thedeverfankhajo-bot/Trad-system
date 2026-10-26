<?php

declare(strict_types=1);

namespace TradSystem\trade;

use pocketmine\item\Item;
use pocketmine\player\Player;
use pocketmine\world\sound\PopSound;
use Throwable;
use TradSystem\Main;
use TradSystem\config\TradeConfig;
use TradSystem\form\IncomingRequestsForm;
use TradSystem\form\MainForm;
use TradSystem\form\OfferAmountForm;
use TradSystem\form\PlayerSelectionForm;
use TradSystem\form\RemoveOfferForm;
use TradSystem\form\SlotSelectionForm;
use TradSystem\form\TradeConfirmationForm;
use TradSystem\form\TradeOfferForm;
use TradSystem\util\ItemDisplayName;
use TradSystem\util\Messages;

final class TradeManager{
    /** @var array<string, TradeRequest> */
    private array $requests = [];
    /** @var array<string, string> pair-key => request id */
    private array $requestByPair = [];
    /** @var array<string, TradeSession> */
    private array $sessions = [];
    /** @var array<string, string> player UUID => session id */
    private array $activeByPlayer = [];
    /** @var array<string, list<int>> player UUID => timestamps of requests in the rolling minute */
    private array $requestTimes = [];
    /** @var array<string, int> player UUID => last request time */
    private array $lastRequestAt = [];
    private TradeQuarantineStore $quarantineStore;

    public function __construct(
        private readonly Main $plugin,
        private readonly TradeConfig $config,
        private readonly Messages $messages,
        private readonly TradeExecutor $executor
    ){
        $this->quarantineStore = new TradeQuarantineStore(
            $this->plugin->getDataFolder() . 'trade-quarantine.json',
            function(string $message) : void{
                $this->plugin->getLogger()->critical($message);
                if(str_contains($message, 'Unable to persist safety marker')){
                    $this->plugin->getLogger()->critical('TradeSystem is stopping the server because the rollback failure could not be durably locked.');
                    $this->plugin->getServer()->shutdown();
                }
            }
        );
    }

    public function getMessages() : Messages{
        return $this->messages;
    }

    public function openMainForm(Player $player) : void{
        MainForm::send($this, $player);
    }

    public function openPlayerSelectionForm(Player $player) : void{
        $uuid = $player->getUniqueId()->toString();
        if($this->quarantineStore->isUnsafe()){
            $this->messages->send($player, 'trade-system-disabled');
            return;
        }
        if($this->quarantineStore->contains($uuid)){
            $this->messages->send($player, 'trade-quarantined');
            return;
        }
        PlayerSelectionForm::send($this, $player);
    }

    public function openIncomingRequestsForm(Player $player) : void{
        IncomingRequestsForm::send($this, $player);
    }

    public function openTradeOfferForm(Player $player, ?string $sessionId = null) : void{
        $sessionId ??= $this->activeByPlayer[$player->getUniqueId()->toString()] ?? null;
        if($sessionId === null){
            $this->messages->send($player, 'trade-no-active');
            return;
        }
        TradeOfferForm::send($this, $player, $sessionId);
    }

    public function openSlotSelectionForm(Player $player, string $sessionId) : void{
        SlotSelectionForm::send($this, $player, $sessionId);
    }

    public function openOfferAmountForm(Player $player, string $sessionId, int $slot, ?Item $expectedSnapshot = null) : void{
        $session = $this->getSessionFor($player, $sessionId);
        $inventory = $player->getInventory();
        if($session === null || !$inventory->slotExists($slot)){
            $this->messages->send($player, 'trade-offer-invalid');
            return;
        }
        $item = $inventory->getItem($slot);
        if($item->isNull() || ($expectedSnapshot !== null && !\TradSystem\util\ItemFingerprint::matches($item, $expectedSnapshot, true))){
            $this->messages->send($player, 'trade-offer-invalid');
            SlotSelectionForm::send($this, $player, $sessionId);
            return;
        }
        $uuid = $player->getUniqueId()->toString();
        $existingOffer = $session->getOffer($uuid, $slot);
        if($existingOffer === null && count($session->getOffersFor($uuid)) >= $this->config->maxOfferSlots){
            $this->messages->send($player, 'trade-offer-limit', ['limit' => $this->config->maxOfferSlots]);
            TradeOfferForm::send($this, $player, $sessionId);
            return;
        }
        $maximum = min($item->getCount(), $this->config->maxItemsPerOffer, $item->getMaxStackSize());
        OfferAmountForm::send($this, $player, $sessionId, $slot, $maximum, $item->getName(), clone $item, $session->getRevision());
    }

    public function openRemoveOfferForm(Player $player, string $sessionId) : void{
        RemoveOfferForm::send($this, $player, $sessionId);
    }

    public function openConfirmationForm(Player $player, string $sessionId) : void{
        TradeConfirmationForm::send($this, $player, $sessionId);
    }

    /** @return list<TradeRequest> */
    public function getIncomingRequestsFor(Player $player) : array{
        $this->pruneExpiredRequests();
        $uuid = $player->getUniqueId()->toString();
        $incoming = [];
        foreach($this->requests as $request){
            if($request->receiverUuid === $uuid){
                $incoming[] = $request;
            }
        }
        usort($incoming, static fn(TradeRequest $a, TradeRequest $b) : int => $a->expiresAt <=> $b->expiresAt);
        return $incoming;
    }

    /** @return list<Player> */
    public function getEligiblePlayers(Player $player) : array{
        $sourceUuid = $player->getUniqueId()->toString();
        if($this->quarantineStore->isUnsafe() || $this->quarantineStore->contains($sourceUuid)){
            return [];
        }
        $eligible = [];
        foreach($this->plugin->getServer()->getOnlinePlayers() as $target){
            $targetUuid = $target->getUniqueId()->toString();
            if($this->quarantineStore->contains($targetUuid) || $targetUuid === $sourceUuid || isset($this->activeByPlayer[$targetUuid]) || isset($this->activeByPlayer[$sourceUuid])){
                continue;
            }
            if(isset($this->requestByPair[$this->pairKey($sourceUuid, $targetUuid)])){
                continue;
            }
            $eligible[] = $target;
        }
        usort($eligible, static fn(Player $a, Player $b) : int => strcasecmp($a->getName(), $b->getName()));
        return $eligible;
    }

    public function sendRequest(Player $sender, string $targetName) : void{
        $target = $this->findOnlinePlayer($targetName);
        if($target === null){
            $this->messages->send($sender, 'player-not-found', ['player' => $targetName]);
            return;
        }
        $this->sendRequestToTarget($sender, $target);
    }

    /**
     * Resolves a UI-selected player by the UUID captured when the form was built.
     * The subsequent request creation remains synchronous on PMMP's main thread.
     */
    public function sendRequestToUuid(Player $sender, string $targetUuid) : void{
        $target = $this->findOnlinePlayerByUuid($targetUuid);
        if($target === null){
            $this->messages->send($sender, 'player-not-found', ['player' => $targetUuid]);
            return;
        }
        $this->sendRequestToTarget($sender, $target);
    }

    private function sendRequestToTarget(Player $sender, Player $target) : void{
        $senderUuid = $sender->getUniqueId()->toString();
        $targetUuid = $target->getUniqueId()->toString();
        if($this->quarantineStore->isUnsafe()){
            $this->messages->send($sender, 'trade-system-disabled');
            return;
        }
        if($this->quarantineStore->contains($senderUuid)){
            $this->messages->send($sender, 'trade-quarantined');
            return;
        }
        if($this->quarantineStore->contains($targetUuid)){
            $this->messages->send($sender, 'player-quarantined', ['player' => $target->getName()]);
            return;
        }
        if($senderUuid === $targetUuid){
            $this->messages->send($sender, 'player-self-trade');
            return;
        }
        if(isset($this->activeByPlayer[$senderUuid])){
            $this->messages->send($sender, 'request-busy-self');
            return;
        }
        if(isset($this->activeByPlayer[$targetUuid])){
            $this->messages->send($sender, 'player-busy', ['player' => $target->getName()]);
            return;
        }

        $reverseKey = $this->pairKey($targetUuid, $senderUuid);
        $reverseId = $this->requestByPair[$reverseKey] ?? null;
        if($reverseId !== null && isset($this->requests[$reverseId])){
            $reverse = $this->requests[$reverseId];
            if(!$reverse->isExpired(time())){
                $this->removeRequestsForPlayer($senderUuid);
                $this->removeRequestsForPlayer($targetUuid);
                $this->startSession($sender, $target);
                return;
            }
            $this->removeRequest($reverse);
        }

        $forwardKey = $this->pairKey($senderUuid, $targetUuid);
        if(isset($this->requestByPair[$forwardKey])){
            $existingId = $this->requestByPair[$forwardKey];
            $existing = $this->requests[$existingId] ?? null;
            if($existing !== null && !$existing->isExpired(time())){
                $this->messages->send($sender, 'request-already-sent', ['player' => $target->getName()]);
                return;
            }
            if($existing !== null){
                $this->removeRequest($existing);
            }
        }
        $now = time();
        $last = $this->lastRequestAt[$senderUuid] ?? 0;
        if($this->config->cooldownSeconds > 0 && $now - $last < $this->config->cooldownSeconds){
            $this->messages->send($sender, 'request-cooldown', ['seconds' => $this->config->cooldownSeconds - ($now - $last)]);
            return;
        }
        if(!$this->consumeRateLimit($senderUuid)){
            $this->messages->send($sender, 'request-rate-limit');
            return;
        }

        $request = TradeRequest::create(
            $senderUuid,
            $targetUuid,
            $sender->getName(),
            $target->getName(),
            $now + $this->config->requestTimeoutSeconds
        );
        $this->requests[$request->id] = $request;
        $this->requestByPair[$forwardKey] = $request->id;
        $this->lastRequestAt[$senderUuid] = $now;
        $this->messages->send($sender, 'request-sent', ['player' => $target->getName()]);
        $this->messages->send($target, 'request-received', ['player' => $sender->getName()]);
        $this->playSound($sender);
        $this->playSound($target);
    }

    public function acceptRequestByName(Player $receiver, string $senderName) : void{
        foreach($this->getIncomingRequestsFor($receiver) as $request){
            if(strcasecmp($request->senderName, $senderName) === 0){
                $this->acceptRequestById($receiver, $request->id);
                return;
            }
        }
        $this->messages->send($receiver, 'request-none', ['player' => $senderName]);
    }

    public function denyRequestByName(Player $receiver, string $senderName) : void{
        foreach($this->getIncomingRequestsFor($receiver) as $request){
            if(strcasecmp($request->senderName, $senderName) === 0){
                $this->denyRequestById($receiver, $request->id);
                return;
            }
        }
        $this->messages->send($receiver, 'request-none', ['player' => $senderName]);
    }

    public function acceptRequestById(Player $receiver, string $requestId) : void{
        $request = $this->requests[$requestId] ?? null;
        $receiverUuid = $receiver->getUniqueId()->toString();
        if($this->quarantineStore->isUnsafe()){
            $this->cancelSessionsForSafetyMode();
            $this->messages->send($receiver, 'trade-system-disabled');
            return;
        }
        if($this->quarantineStore->contains($receiverUuid)){
            $this->messages->send($receiver, 'trade-quarantined');
            return;
        }
        if($request === null || $request->receiverUuid !== $receiverUuid){
            $this->messages->send($receiver, 'request-none', ['player' => $request?->senderName ?? 'unknown']);
            return;
        }
        if($this->quarantineStore->contains($request->senderUuid)){
            $this->removeRequest($request);
            $this->messages->send($receiver, 'player-quarantined', ['player' => $request->senderName]);
            return;
        }
        if($request->isExpired(time())){
            $this->removeRequest($request);
            $this->messages->send($receiver, 'request-expired');
            return;
        }
        $sender = $this->findOnlinePlayerByUuid($request->senderUuid);
        if($sender === null){
            $this->removeRequest($request);
            $this->messages->send($receiver, 'request-expired');
            return;
        }
        if(isset($this->activeByPlayer[$receiverUuid]) || isset($this->activeByPlayer[$request->senderUuid])){
            $this->removeRequest($request);
            $this->messages->send($receiver, 'request-busy-self');
            return;
        }
        $this->removeRequestsForPlayer($receiverUuid);
        $this->removeRequestsForPlayer($request->senderUuid);
        $this->startSession($receiver, $sender);
    }

    public function denyRequestById(Player $receiver, string $requestId) : void{
        $request = $this->requests[$requestId] ?? null;
        $receiverUuid = $receiver->getUniqueId()->toString();
        if($request === null || $request->receiverUuid !== $receiverUuid){
            $this->messages->send($receiver, 'request-none', ['player' => $request?->senderName ?? 'unknown']);
            return;
        }
        $this->removeRequest($request);
        $this->messages->send($receiver, 'request-denied', ['player' => $request->senderName]);
        $sender = $this->findOnlinePlayerByUuid($request->senderUuid);
        if($sender !== null){
            $this->messages->send($sender, 'request-denied-sender', ['player' => $receiver->getName()]);
        }
    }

    public function setOffer(Player $player, string $sessionId, int $slot, int $amount, Item $formSnapshot, int $expectedRevision) : void{
        $session = $this->getSessionFor($player, $sessionId);
        $uuid = $player->getUniqueId()->toString();
        $inventory = $player->getInventory();
        if($session === null || $session->getRevision() !== $expectedRevision || !$inventory->slotExists($slot)){
            $this->messages->send($player, 'trade-offer-invalid');
            if($session !== null){
                TradeOfferForm::send($this, $player, $sessionId);
            }
            return;
        }
        $item = $inventory->getItem($slot);
        if($item->isNull() || !\TradSystem\util\ItemFingerprint::matches($item, $formSnapshot, true) || $amount < 1 || $amount > $item->getCount() || $amount > $this->config->maxItemsPerOffer || $amount > $item->getMaxStackSize()){
            $this->messages->send($player, 'trade-offer-invalid');
            return;
        }
        if($session->getOffer($uuid, $slot) === null && count($session->getOffersFor($uuid)) >= $this->config->maxOfferSlots){
            $this->messages->send($player, 'trade-offer-limit', ['limit' => $this->config->maxOfferSlots]);
            return;
        }
        if(!$session->setOffer($uuid, $slot, $amount, $item)){
            $this->messages->send($player, 'trade-expired');
            return;
        }
        $this->messages->send($player, 'trade-offer-added');
        $partnerUuid = $session->getOtherUuid($uuid);
        $partner = $partnerUuid !== null ? $this->findOnlinePlayerByUuid($partnerUuid) : null;
        if($partner !== null){
            $this->messages->send($partner, 'trade-offer-added');
        }
        $this->refreshTradeForms($session);
        $this->playSound($player);
    }

    public function removeOffer(Player $player, string $sessionId, int $slot, int $expectedRevision) : void{
        $session = $this->getSessionFor($player, $sessionId);
        if($session === null || $session->getRevision() !== $expectedRevision || !$session->removeOffer($player->getUniqueId()->toString(), $slot)){
            $this->messages->send($player, 'trade-offer-invalid');
            return;
        }
        $this->messages->send($player, 'trade-offer-removed');
        $partnerUuid = $session->getOtherUuid($player->getUniqueId()->toString());
        $partner = $partnerUuid !== null ? $this->findOnlinePlayerByUuid($partnerUuid) : null;
        if($partner !== null){
            $this->messages->send($partner, 'trade-offer-removed');
        }
        $this->refreshTradeForms($session);
    }

    public function confirmTrade(Player $player, string $sessionId, int $expectedRevision) : void{
        $session = $this->getSessionFor($player, $sessionId);
        $uuid = $player->getUniqueId()->toString();
        if($session === null || $session->getRevision() !== $expectedRevision){
            $this->messages->send($player, 'trade-offer-invalid');
            $this->openConfirmationForm($player, $sessionId);
            return;
        }
        if(!$session->confirm($uuid)){
            $this->messages->send($player, 'trade-expired');
            return;
        }
        if($session->getOffersFor($session->partyOneUuid) === [] && $session->getOffersFor($session->partyTwoUuid) === []){
            $session->resetAfterValidationFailure();
            $this->messages->send($player, 'trade-offer-empty');
            $this->refreshTradeForms($session);
            return;
        }
        $otherUuid = $session->getOtherUuid($uuid);
        $other = $otherUuid !== null ? $this->findOnlinePlayerByUuid($otherUuid) : null;
        if(!$session->bothConfirmed()){
            $this->messages->send($player, 'trade-confirmed');
            if($other !== null){
                $this->messages->send($other, 'trade-partner-confirmed', ['player' => $player->getName()]);
            }
            $this->refreshConfirmationForms($session);
            return;
        }
        if(!$session->beginCommit()){
            $this->messages->send($player, 'trade-expired');
            return;
        }
        $partyOne = $this->findOnlinePlayerByUuid($session->partyOneUuid);
        $partyTwo = $this->findOnlinePlayerByUuid($session->partyTwoUuid);
        if($partyOne === null || $partyTwo === null){
            $session->cancel();
            $this->finishSession($session);
            $this->messages->send($player, 'trade-partner-left', ['player' => $session->getName($otherUuid ?? '')]);
            return;
        }

        try{
            $result = $this->executor->commit($session, $partyOne, $partyTwo);
        }catch(Throwable $exception){
            $this->plugin->getLogger()->error('Unexpected trade executor exception; cancelling session ' . $sessionId . ': ' . $exception->getMessage());
            $session->cancel();
            $this->finishSession($session);
            $this->messages->send($partyOne, 'trade-commit-failed');
            $this->messages->send($partyTwo, 'trade-commit-failed');
            $this->audit('FAILED', $session, 'unexpected executor exception');
            return;
        }

        if($result->success){
            $session->markCompleted();
            $nameOne = $session->partyOneName;
            $nameTwo = $session->partyTwoName;
            $this->audit('COMPLETED', $session, '');
            $this->finishSession($session);
            $this->messages->send($partyOne, 'trade-complete', ['player' => $nameTwo]);
            $this->messages->send($partyTwo, 'trade-complete-partner', ['player' => $nameOne]);
            $this->playSound($partyOne);
            $this->playSound($partyTwo);
            return;
        }

        if($result->reason === 'inventory-changed' || $result->reason === 'no-space' || $result->reason === 'invalid-state' || $result->reason === 'invalid-participants'){
            $session->resetAfterValidationFailure();
            $key = $result->reason === 'no-space' ? 'trade-no-space' : 'trade-inventory-changed';
            $this->messages->send($partyOne, $key);
            $this->messages->send($partyTwo, $key);
            $this->audit('REVIEW_REQUIRED', $session, $result->reason);
            $this->refreshTradeForms($session);
            return;
        }

        $session->cancel();
        $this->finishSession($session);
        if(!$result->rollbackSuccessful || str_starts_with($result->reason, 'rollback-failed')){
            $this->quarantinePlayer($session->partyOneUuid);
            $this->quarantinePlayer($session->partyTwoUuid);
            $this->plugin->getLogger()->critical('Trade rollback failure for session ' . $sessionId . ': ' . $result->reason);
            $this->messages->send($partyOne, 'trade-rollback-critical');
            $this->messages->send($partyTwo, 'trade-rollback-critical');
            $this->audit('CRITICAL_ROLLBACK_FAILURE', $session, $result->reason);
        }else{
            $this->plugin->getLogger()->error('Trade transaction failed and was rolled back for session ' . $sessionId . ': ' . $result->reason);
            $this->messages->send($partyOne, 'trade-commit-failed');
            $this->messages->send($partyTwo, 'trade-commit-failed');
            $this->audit('FAILED_ROLLED_BACK', $session, $result->reason);
        }
    }

    public function cancelTrade(Player $player, string $sessionId) : void{
        $session = $this->getSessionFor($player, $sessionId);
        if($session === null){
            $this->messages->send($player, 'trade-no-active');
            return;
        }
        $uuid = $player->getUniqueId()->toString();
        $partnerUuid = $session->getOtherUuid($uuid);
        $partnerName = $partnerUuid !== null ? $session->getName($partnerUuid) : '';
        $session->cancel();
        $this->finishSession($session);
        $this->messages->send($player, 'trade-cancelled');
        $partner = $partnerUuid !== null ? $this->findOnlinePlayerByUuid($partnerUuid) : null;
        if($partner !== null){
            $this->messages->send($partner, 'trade-partner-cancelled', ['player' => $player->getName()]);
        }
        $this->audit('CANCELLED', $session, 'cancelled by ' . $uuid . ($partnerName !== '' ? '; partner ' . $partnerName : ''));
    }

    public function cancelActiveTrade(Player $player) : void{
        $id = $this->activeByPlayer[$player->getUniqueId()->toString()] ?? null;
        if($id === null){
            $this->messages->send($player, 'trade-no-active');
            return;
        }
        $this->cancelTrade($player, $id);
    }

    public function getSessionFor(Player $player, string $sessionId) : ?TradeSession{
        $uuid = $player->getUniqueId()->toString();
        if($this->quarantineStore->isUnsafe()){
            $this->cancelSessionsForSafetyMode();
            return null;
        }
        if($this->quarantineStore->contains($uuid)){
            return null;
        }
        if(($this->activeByPlayer[$uuid] ?? null) !== $sessionId){
            return null;
        }
        $session = $this->sessions[$sessionId] ?? null;
        if($session === null || $session->getState()->isTerminal() || $session->getState() === TradeState::COMMITTING || !$session->isParticipant($uuid)){
            return null;
        }
        if($session->isExpired(time())){
            $this->expireSession($session);
            return null;
        }
        return $session;
    }

    public function formatOffer(TradeSession $session, string $uuid) : string{
        $offers = $session->getOffersFor($uuid);
        if($offers === []){
            return $this->messages->get('ui-empty-offer');
        }
        $lines = [];
        foreach($offers as $slot => $offer){
            $lines[] = $this->messages->get('ui-offer-line', [
                'slot' => $slot,
                'item' => ItemDisplayName::sanitize($offer->getItemName()),
                'count' => $offer->offeredCount
            ]);
        }
        return implode("\n", $lines);
    }

    public function onQuit(Player $player) : void{
        $uuid = $player->getUniqueId()->toString();
        $sessionId = $this->activeByPlayer[$uuid] ?? null;
        if($sessionId !== null && isset($this->sessions[$sessionId])){
            $session = $this->sessions[$sessionId];
            $partnerUuid = $session->getOtherUuid($uuid);
            $session->cancel();
            $this->finishSession($session);
            $partner = $partnerUuid !== null ? $this->findOnlinePlayerByUuid($partnerUuid) : null;
            if($partner !== null){
                $this->messages->send($partner, 'trade-partner-left', ['player' => $player->getName()]);
            }
            $this->audit('CANCELLED_PLAYER_LEFT', $session, 'player ' . $uuid . ' disconnected');
        }

        foreach(array_values($this->requests) as $request){
            if($request->senderUuid === $uuid || $request->receiverUuid === $uuid){
                $otherUuid = $request->senderUuid === $uuid ? $request->receiverUuid : $request->senderUuid;
                $other = $this->findOnlinePlayerByUuid($otherUuid);
                if($other !== null){
                    $this->messages->send($other, 'request-expired');
                }
                $this->removeRequest($request);
            }
        }
    }

    public function tick() : void{
        if($this->quarantineStore->isUnsafe()){
            $this->cancelSessionsForSafetyMode();
            return;
        }
        $this->pruneExpiredRequests();
        $this->pruneRateLimits();
        $now = time();
        foreach(array_values($this->sessions) as $session){
            if($session->isExpired($now)){
                $this->expireSession($session);
                continue;
            }
            if($this->findOnlinePlayerByUuid($session->partyOneUuid) === null || $this->findOnlinePlayerByUuid($session->partyTwoUuid) === null){
                $session->cancel();
                $this->finishSession($session);
            }
        }
    }

    public function shutdown() : void{
        foreach(array_values($this->sessions) as $session){
            $session->cancel();
            $this->finishSession($session);
        }
        $this->requests = [];
        $this->requestByPair = [];
        $this->requestTimes = [];
        $this->lastRequestAt = [];
        $this->activeByPlayer = [];
        $this->sessions = [];
    }

    private function startSession(Player $partyOne, Player $partyTwo) : void{
        $uuidOne = $partyOne->getUniqueId()->toString();
        $uuidTwo = $partyTwo->getUniqueId()->toString();
        if($this->quarantineStore->isUnsafe()){
            $this->messages->send($partyOne, 'trade-system-disabled');
            return;
        }
        if($this->quarantineStore->contains($uuidOne)){
            $this->messages->send($partyOne, 'trade-quarantined');
            return;
        }
        if($this->quarantineStore->contains($uuidTwo)){
            $this->messages->send($partyOne, 'player-quarantined', ['player' => $partyTwo->getName()]);
            return;
        }
        if($uuidOne === $uuidTwo || isset($this->activeByPlayer[$uuidOne]) || isset($this->activeByPlayer[$uuidTwo])){
            $this->messages->send($partyOne, 'player-busy', ['player' => $partyTwo->getName()]);
            return;
        }
        $session = new TradeSession(
            bin2hex(random_bytes(16)),
            $uuidOne,
            $uuidTwo,
            $partyOne->getName(),
            $partyTwo->getName(),
            time() + $this->config->tradeTimeoutSeconds
        );
        $this->sessions[$session->id] = $session;
        $this->activeByPlayer[$uuidOne] = $session->id;
        $this->activeByPlayer[$uuidTwo] = $session->id;
        $this->messages->send($partyOne, 'trade-started', ['player' => $partyTwo->getName()]);
        $this->messages->send($partyTwo, 'trade-started', ['player' => $partyOne->getName()]);
        $this->playSound($partyOne);
        $this->playSound($partyTwo);
        TradeOfferForm::send($this, $partyOne, $session->id);
        TradeOfferForm::send($this, $partyTwo, $session->id);
        $this->audit('STARTED', $session, '');
    }

    private function refreshTradeForms(TradeSession $session) : void{
        foreach([$session->partyOneUuid, $session->partyTwoUuid] as $uuid){
            $player = $this->findOnlinePlayerByUuid($uuid);
            if($player !== null){
                TradeOfferForm::send($this, $player, $session->id);
            }
        }
    }

    private function refreshConfirmationForms(TradeSession $session) : void{
        foreach([$session->partyOneUuid, $session->partyTwoUuid] as $uuid){
            $player = $this->findOnlinePlayerByUuid($uuid);
            if($player !== null){
                TradeConfirmationForm::send($this, $player, $session->id);
            }
        }
    }

    private function expireSession(TradeSession $session) : void{
        if(!$session->cancel()){
            return;
        }
        $this->finishSession($session);
        foreach([$session->partyOneUuid, $session->partyTwoUuid] as $uuid){
            $player = $this->findOnlinePlayerByUuid($uuid);
            if($player !== null){
                $this->messages->send($player, 'trade-timeout');
            }
        }
        $this->audit('EXPIRED', $session, 'trade session timeout');
    }

    private function finishSession(TradeSession $session) : void{
        unset($this->sessions[$session->id]);
        if(($this->activeByPlayer[$session->partyOneUuid] ?? null) === $session->id){
            unset($this->activeByPlayer[$session->partyOneUuid]);
        }
        if(($this->activeByPlayer[$session->partyTwoUuid] ?? null) === $session->id){
            unset($this->activeByPlayer[$session->partyTwoUuid]);
        }
    }

    private function quarantinePlayer(string $uuid) : void{
        if(!$this->quarantineStore->quarantine($uuid, 'inventory-rollback-failed')){
            $this->plugin->getLogger()->critical('Trade quarantine could not be persisted; all trading is now fail-closed.');
            if($this->quarantineStore->isUnsafe()){
                // If the data directory cannot persist either the quarantine or
                // its independent lock marker, continuing the server is unsafe.
                $this->plugin->getServer()->shutdown();
            }
        }
        foreach(array_values($this->requests) as $request){
            if($request->senderUuid !== $uuid && $request->receiverUuid !== $uuid){
                continue;
            }
            $otherUuid = $request->senderUuid === $uuid ? $request->receiverUuid : $request->senderUuid;
            $other = $this->findOnlinePlayerByUuid($otherUuid);
            if($other !== null){
                $this->messages->send($other, 'request-expired');
            }
            $this->removeRequest($request);
        }
    }

    private function cancelSessionsForSafetyMode() : void{
        foreach(array_values($this->sessions) as $session){
            if(!$session->cancel()){
                continue;
            }
            $this->finishSession($session);
            foreach([$session->partyOneUuid, $session->partyTwoUuid] as $uuid){
                $player = $this->findOnlinePlayerByUuid($uuid);
                if($player !== null){
                    $this->messages->send($player, 'trade-system-disabled');
                }
            }
            $this->audit('CANCELLED_SAFETY_MODE', $session, 'quarantine store unsafe');
        }
        $this->requests = [];
        $this->requestByPair = [];
    }

    private function pruneRateLimits() : void{
        $now = time();
        foreach($this->requestTimes as $uuid => $timestamps){
            $recent = array_values(array_filter($timestamps, static fn(int $timestamp) : bool => $timestamp > $now - 60));
            if($recent === [] && $now - ($this->lastRequestAt[$uuid] ?? 0) > max(60, $this->config->cooldownSeconds)){
                unset($this->requestTimes[$uuid], $this->lastRequestAt[$uuid]);
            }else{
                $this->requestTimes[$uuid] = $recent;
            }
        }
    }

    private function consumeRateLimit(string $uuid) : bool{
        $now = time();
        $recent = array_values(array_filter($this->requestTimes[$uuid] ?? [], static fn(int $timestamp) : bool => $timestamp > $now - 60));
        if(count($recent) >= $this->config->maxTradesPerMinute){
            $this->requestTimes[$uuid] = $recent;
            return false;
        }
        $recent[] = $now;
        $this->requestTimes[$uuid] = $recent;
        return true;
    }

    private function pruneExpiredRequests() : void{
        $now = time();
        foreach(array_values($this->requests) as $request){
            if($request->isExpired($now)){
                $this->removeRequest($request);
                $sender = $this->findOnlinePlayerByUuid($request->senderUuid);
                $receiver = $this->findOnlinePlayerByUuid($request->receiverUuid);
                if($sender !== null){
                    $this->messages->send($sender, 'request-expired');
                }
                if($receiver !== null){
                    $this->messages->send($receiver, 'request-expired');
                }
            }
        }
    }

    private function removeRequestsForPlayer(string $uuid) : void{
        foreach(array_values($this->requests) as $request){
            if($request->senderUuid === $uuid || $request->receiverUuid === $uuid){
                $this->removeRequest($request);
            }
        }
    }

    private function removeRequest(TradeRequest $request) : void{
        unset($this->requests[$request->id]);
        $key = $this->pairKey($request->senderUuid, $request->receiverUuid);
        if(($this->requestByPair[$key] ?? null) === $request->id){
            unset($this->requestByPair[$key]);
        }
    }

    private function pairKey(string $senderUuid, string $receiverUuid) : string{
        return $senderUuid . '>' . $receiverUuid;
    }

    private function findOnlinePlayer(string $name) : ?Player{
        foreach($this->plugin->getServer()->getOnlinePlayers() as $player){
            if(strcasecmp($player->getName(), $name) === 0){
                return $player;
            }
        }
        return null;
    }

    private function findOnlinePlayerByUuid(string $uuid) : ?Player{
        foreach($this->plugin->getServer()->getOnlinePlayers() as $player){
            if($player->getUniqueId()->toString() === $uuid){
                return $player;
            }
        }
        return null;
    }

    private function playSound(Player $player) : void{
        if(!$this->config->soundEffects){
            return;
        }
        try{
            $player->getWorld()->addSound($player->getPosition(), new PopSound());
        }catch(Throwable $exception){
            $this->plugin->getLogger()->debug('Trade sound could not be played: ' . $exception->getMessage());
        }
    }

    private function audit(string $result, TradeSession $session, string $details) : void{
        if(!$this->config->enableAuditLog){
            return;
        }
        $summaryOne = $this->auditOfferSummary($session->getOffersFor($session->partyOneUuid));
        $summaryTwo = $this->auditOfferSummary($session->getOffersFor($session->partyTwoUuid));
        $safeDetails = str_replace(["\r", "\n", "\t"], ' ', $details);
        $line = sprintf(
            "[%s] %s session=%s player1=%s player2=%s offer1={%s} offer2={%s} details=%s%s",
            gmdate(DATE_ATOM),
            $result,
            $session->id,
            $session->partyOneUuid,
            $session->partyTwoUuid,
            $summaryOne,
            $summaryTwo,
            $safeDetails,
            PHP_EOL
        );
        $path = $this->plugin->getDataFolder() . 'trade-audit.log';
        if(file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === false){
            $this->plugin->getLogger()->warning('Unable to write TradeSystem audit log.');
        }
    }

    /** @param array<int, TradeOffer> $offers */
    private function auditOfferSummary(array $offers) : string{
        $parts = [];
        foreach($offers as $slot => $offer){
            $name = str_replace(['{', '}', '|', ',', ':'], '_', ItemDisplayName::sanitize($offer->getItemName()));
            $parts[] = $slot . ':' . $name . 'x' . $offer->offeredCount;
        }
        return implode(',', $parts);
    }
}
