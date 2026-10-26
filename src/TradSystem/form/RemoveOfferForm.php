<?php

declare(strict_types=1);

namespace TradSystem\form;

use pocketmine\player\Player;
use TradSystem\trade\TradeManager;

final class RemoveOfferForm{
    public static function send(TradeManager $manager, Player $player, string $sessionId) : void{
        $session = $manager->getSessionFor($player, $sessionId);
        if($session === null){
            $manager->getMessages()->send($player, 'trade-expired');
            MainForm::send($manager, $player);
            return;
        }
        $uuid = $player->getUniqueId()->toString();
        $offers = $session->getOffersFor($uuid);
        if($offers === []){
            $manager->getMessages()->send($player, 'trade-offer-empty');
            TradeOfferForm::send($manager, $player, $sessionId);
            return;
        }
        $slots = [];
        $buttons = [];
        foreach($offers as $slot => $offer){
            $slots[] = $slot;
            $buttons[] = $manager->getMessages()->get('ui-remove-line', ['slot' => $slot, 'item' => $offer->getItemName(), 'count' => $offer->offeredCount]);
        }
        $buttons[] = $manager->getMessages()->get('ui-back-offer');
        $slots[] = -1;
        $revision = $session->getRevision();
        $player->sendForm(new SimpleForm(
            $uuid,
            $manager->getMessages()->get('ui-remove-title'),
            $manager->getMessages()->get('ui-remove-content'),
            $buttons,
            static function(Player $responsePlayer, int $index) use ($manager, $sessionId, $slots, $revision) : void{
                if($slots[$index] < 0){
                    TradeOfferForm::send($manager, $responsePlayer, $sessionId);
                    return;
                }
                $manager->removeOffer($responsePlayer, $sessionId, $slots[$index], $revision);
            }
        ));
    }
}
