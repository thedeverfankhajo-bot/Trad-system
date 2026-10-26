<?php

declare(strict_types=1);

namespace TradSystem\form;

use pocketmine\player\Player;
use TradSystem\trade\TradeManager;

final class SlotSelectionForm{
    public static function send(TradeManager $manager, Player $player, string $sessionId) : void{
        $session = $manager->getSessionFor($player, $sessionId);
        if($session === null){
            $manager->getMessages()->send($player, 'trade-expired');
            MainForm::send($manager, $player);
            return;
        }
        $inventory = $player->getInventory();
        $slots = [];
        $snapshots = [];
        $buttons = [];
        for($slot = 0; $slot < $inventory->getSize(); ++$slot){
            $item = $inventory->getItem($slot);
            if($item->isNull()){
                continue;
            }
            $slots[] = $slot;
            $snapshots[$slot] = clone $item;
            $buttons[] = $manager->getMessages()->get('ui-slot-line', ['slot' => $slot, 'item' => $item->getName(), 'count' => $item->getCount()]);
        }
        if($buttons === []){
            $manager->getMessages()->send($player, 'ui-no-items');
            TradeOfferForm::send($manager, $player, $sessionId);
            return;
        }
        $buttons[] = $manager->getMessages()->get('ui-back-offer');
        $slots[] = -1;
        $player->sendForm(new SimpleForm(
            $player->getUniqueId()->toString(),
            $manager->getMessages()->get('ui-slots-title'),
            $manager->getMessages()->get('ui-slots-content'),
            $buttons,
            static function(Player $responsePlayer, int $index) use ($manager, $sessionId, $slots, $snapshots) : void{
                $slot = $slots[$index];
                if($slot < 0){
                    TradeOfferForm::send($manager, $responsePlayer, $sessionId);
                    return;
                }
                $manager->openOfferAmountForm($responsePlayer, $sessionId, $slot, $snapshots[$slot]);
            }
        ));
    }
}
