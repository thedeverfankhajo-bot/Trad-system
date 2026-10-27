<?php

declare(strict_types=1);

namespace TradeSystemIntegrationHarness;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\block\VanillaBlocks;
use pocketmine\inventory\PlayerInventory;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use Throwable;
use TradSystem\trade\TradeExecutor;
use TradSystem\trade\TradeJournalStore;
use TradSystem\trade\TradePlayerDataSaver;
use TradSystem\trade\TradeSession;

final class Main extends PluginBase{
    private TradePlayerDataSaver $dataSaver;

    protected function onEnable() : void{
        $this->dataSaver = new TradePlayerDataSaver();
        $this->getServer()->getPluginManager()->registerEvents($this->dataSaver, $this);
        $this->getLogger()->warning(
            "Integration harness enabled. Use only a disposable PMMP server and two disposable accounts; " .
            "both main inventories must be empty before each run."
        );
    }

    /** @param list<string> $args */
    public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
        if(strtolower($command->getName()) !== "tradeint"){
            return false;
        }
        if(!$sender->hasPermission("tradesystem.integration.run")){
            $sender->sendMessage("You do not have permission to run integration tests.");
            return true;
        }
        if(!$sender instanceof Player){
            $sender->sendMessage("Run this command in-game as the first disposable test player.");
            return true;
        }
        if(count($args) !== 2 || !in_array(strtolower($args[0]), ["success", "capacity", "stale-count", "stale-meta", "stale-type", "stale-slot"], true)){
            $sender->sendMessage("Usage: /tradeint <success|capacity|stale-count|stale-meta|stale-type|stale-slot> <other-player>");
            return true;
        }

        $scenario = strtolower($args[0]);
        $partner = $this->findOnlinePlayer($args[1]);
        if($partner === null || $partner->getUniqueId()->toString() === $sender->getUniqueId()->toString()){
            $sender->sendMessage("The other test player must be online and must be a different account.");
            return true;
        }
        if(!$this->isMainInventoryEmpty($sender->getInventory()) || !$this->isMainInventoryEmpty($partner->getInventory())){
            $sender->sendMessage("ABORTED: both players' main inventories must be empty. No inventory was changed.");
            return true;
        }

        $firstInventory = $sender->getInventory();
        $secondInventory = $partner->getInventory();
        $beforeFirst = $this->snapshot($firstInventory);
        $beforeSecond = $this->snapshot($secondInventory);
        $safeToRestore = true;
        $testPassed = false;
        $message = "";

        try{
            if($scenario !== "capacity"){
                $diamond = VanillaItems::DIAMOND();
                $diamond->setCount(3);
                $firstInventory->setItem(0, $diamond);

                $emerald = VanillaItems::EMERALD();
                $emerald->setCount(2);
                $secondInventory->setItem(0, $emerald);
            }else{
                $dirt = VanillaBlocks::DIRT()->asItem();
                $dirt->setCount(64);
                for($slot = 0; $slot < $firstInventory->getSize(); ++$slot){
                    $firstInventory->setItem($slot, clone $dirt);
                }
                $secondInventory->setItem(0, VanillaItems::DIAMOND_SWORD());
            }

            $preparedFirst = $this->snapshot($firstInventory);
            $preparedSecond = $this->snapshot($secondInventory);
            $uuidFirst = $sender->getUniqueId()->toString();
            $uuidSecond = $partner->getUniqueId()->toString();
            $session = new TradeSession(
                bin2hex(random_bytes(16)),
                $uuidFirst,
                $uuidSecond,
                $sender->getName(),
                $partner->getName(),
                time() + 120
            );

            $firstAmount = $scenario === "capacity" ? 1 : 2;
            if(!$session->setOffer($uuidFirst, 0, $firstAmount, $firstInventory->getItem(0))){
                throw new \RuntimeException("Could not add the first test offer.");
            }
            if(!$session->setOffer($uuidSecond, 0, 1, $secondInventory->getItem(0))){
                throw new \RuntimeException("Could not add the second test offer.");
            }
            if(!$session->confirm($uuidFirst) || !$session->confirm($uuidSecond) || !$session->beginCommit()){
                throw new \RuntimeException("Could not prepare the session for commit.");
            }

            // Mutate a source stack only after its original offer snapshot has been recorded.
            if($scenario === "stale-count"){
                $changed = clone $firstInventory->getItem(0);
                $changed->setCount(4);
                $firstInventory->setItem(0, $changed);
            }elseif($scenario === "stale-meta"){
                $changed = clone $firstInventory->getItem(0);
                $changed->setCustomName("Changed after offer");
                $firstInventory->setItem(0, $changed);
            }elseif($scenario === "stale-type"){
                $changed = VanillaItems::GOLD_INGOT();
                $changed->setCount(3);
                $firstInventory->setItem(0, $changed);
            }elseif($scenario === "stale-slot"){
                $changed = clone $firstInventory->getItem(0);
                $firstInventory->clear(0);
                $firstInventory->setItem(1, $changed);
            }
            $commitBaselineFirst = $this->snapshot($firstInventory);
            $commitBaselineSecond = $this->snapshot($secondInventory);

            // After entering the executor, an unexpected result must be preserved for diagnosis.
            // Only a verified expected outcome permits restoring the initially empty inventories.
            $safeToRestore = false;
            $journalPath = $this->getDataFolder() . 'trade-journal-' . bin2hex(random_bytes(8)) . '.json';
            $journalStore = new TradeJournalStore($journalPath, function(string $error) : void{
                $this->getLogger()->critical($error);
            });
            $result = (new TradeExecutor($journalStore, $this->dataSaver))->commit($session, $sender, $partner);
            if($scenario === "success"){
                $passed = $result->success
                    && $this->countType($firstInventory, VanillaItems::DIAMOND()->getTypeId()) === 1
                    && $this->countType($firstInventory, VanillaItems::EMERALD()->getTypeId()) === 1
                    && $this->countType($secondInventory, VanillaItems::DIAMOND()->getTypeId()) === 2
                    && $this->countType($secondInventory, VanillaItems::EMERALD()->getTypeId()) === 1;
                $expectation = "successful trade transfers exact item counts to both players";
            }elseif($scenario === "capacity"){
                $passed = !$result->success
                    && $result->reason === "no-space"
                    && $this->matchesSnapshot($firstInventory, $preparedFirst)
                    && $this->matchesSnapshot($secondInventory, $preparedSecond);
                $expectation = "full destination inventory rejects the trade before either inventory changes";
            }else{
                $passed = !$result->success
                    && $result->reason === "inventory-changed"
                    && $this->matchesSnapshot($firstInventory, $commitBaselineFirst)
                    && $this->matchesSnapshot($secondInventory, $commitBaselineSecond);
                $expectation = match($scenario){
                    "stale-count" => "source stack count changes after offering reject the trade without further inventory mutations",
                    "stale-meta" => "source metadata changes after offering reject the trade without further inventory mutations",
                    "stale-type" => "source item type changes after offering reject the trade without further inventory mutations",
                    default => "moving an offered stack to another slot after offering rejects the trade without further inventory mutations"
                };
            }

            if($passed){
                $safeToRestore = true;
                $testPassed = true;
                $message = "PASS: " . $expectation . ". Test inventories will be restored to empty.";
            }else{
                $message = "FAIL: " . $expectation . ". Result=" . $result->reason .
                    ". Test inventory contents were left in place for inspection; reconcile them manually.";
                $this->getLogger()->critical($message);
                $sender->sendMessage($message);
                $partner->sendMessage($message);
            }
        }catch(Throwable $exception){
            $this->getLogger()->critical(
                "Integration test threw " . get_class($exception) . ": " . $exception->getMessage() .
                ". If the executor had started, inspect both test inventories before cleanup."
            );
            $sender->sendMessage("Integration test failed with an exception. Check server logs and inspect both test inventories.");
            $partner->sendMessage("Integration test failed with an exception. Check server logs and inspect both test inventories.");
        }finally{
            if($safeToRestore){
                $restoredFirst = $this->restore($firstInventory, $beforeFirst);
                $restoredSecond = $this->restore($secondInventory, $beforeSecond);
                // Restoring the second inventory can trigger listeners that mutate the first.
                // Re-verify both original snapshots only after both restore operations finish.
                $finalFirstMatches = $this->matchesSnapshot($firstInventory, $beforeFirst);
                $finalSecondMatches = $this->matchesSnapshot($secondInventory, $beforeSecond);
                if(!$restoredFirst || !$restoredSecond || !$finalFirstMatches || !$finalSecondMatches){
                    $this->getLogger()->critical(
                        "Integration harness could not restore both initially empty test inventories. " .
                        "Stop testing and inspect both accounts before any further use."
                    );
                    $sender->sendMessage("CRITICAL: test cleanup could not be verified. Inspect both inventories before further use.");
                    $partner->sendMessage("CRITICAL: test cleanup could not be verified. Inspect both inventories before further use.");
                }
                elseif($testPassed){
                    $this->getLogger()->info($message);
                    $sender->sendMessage($message);
                    $partner->sendMessage($message);
                }
            }else{
                $this->getLogger()->critical(
                    "Integration test state preserved for diagnosis. Do not reuse either account until inventory contents are reviewed."
                );
            }
        }
        return true;
    }

    private function findOnlinePlayer(string $name) : ?Player{
        foreach($this->getServer()->getOnlinePlayers() as $player){
            if(strcasecmp($player->getName(), $name) === 0){
                return $player;
            }
        }
        return null;
    }

    private function isMainInventoryEmpty(PlayerInventory $inventory) : bool{
        for($slot = 0; $slot < $inventory->getSize(); ++$slot){
            if(!$inventory->getItem($slot)->isNull()){
                return false;
            }
        }
        return true;
    }

    /** @return array<int, Item> */
    private function snapshot(PlayerInventory $inventory) : array{
        $items = [];
        for($slot = 0; $slot < $inventory->getSize(); ++$slot){
            $items[$slot] = clone $inventory->getItem($slot);
        }
        return $items;
    }

    /** @param array<int, Item> $expected */
    private function matchesSnapshot(PlayerInventory $inventory, array $expected) : bool{
        try{
            for($slot = 0; $slot < $inventory->getSize(); ++$slot){
                if(!isset($expected[$slot]) || !$inventory->getItem($slot)->equalsExact($expected[$slot])){
                    return false;
                }
            }
            return count($expected) === $inventory->getSize();
        }catch(Throwable){
            return false;
        }
    }

    private function countType(PlayerInventory $inventory, int $typeId) : int{
        $count = 0;
        for($slot = 0; $slot < $inventory->getSize(); ++$slot){
            $item = $inventory->getItem($slot);
            if(!$item->isNull() && $item->getTypeId() === $typeId){
                $count += $item->getCount();
            }
        }
        return $count;
    }

    /** @param array<int, Item> $snapshot */
    private function restore(PlayerInventory $inventory, array $snapshot) : bool{
        try{
            $inventory->setContents($snapshot);
        }catch(Throwable $exception){
            $this->getLogger()->warning("Bulk test inventory restoration failed: " . $exception->getMessage());
        }
        if(!$this->matchesSnapshot($inventory, $snapshot)){
            foreach($snapshot as $slot => $item){
                try{
                    $inventory->setItem($slot, clone $item);
                }catch(Throwable $exception){
                    $this->getLogger()->warning("Could not restore integration test slot " . $slot . ": " . $exception->getMessage());
                }
            }
        }
        return $this->matchesSnapshot($inventory, $snapshot);
    }
}
