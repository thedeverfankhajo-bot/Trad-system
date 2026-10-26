<?php

declare(strict_types=1);

namespace TradSystem\form;

use pocketmine\item\Item;
use pocketmine\player\Player;
use TradSystem\trade\TradeManager;
use TradSystem\util\ItemDisplayName;

final class OfferAmountForm{
    public static function send(TradeManager $manager, Player $player, string $sessionId, int $slot, int $maxAmount, string $itemName, Item $sourceSnapshot, int $expectedRevision) : void{
        $messages = $manager->getMessages();
        $options = [];
        for($amount = 1; $amount <= $maxAmount; ++$amount){
            $options[] = (string) $amount;
        }
        if($options === []){
            $messages->send($player, 'trade-offer-invalid');
            SlotSelectionForm::send($manager, $player, $sessionId);
            return;
        }
        $player->sendForm(new CustomForm(
            $player->getUniqueId()->toString(),
            $messages->get('ui-quantity-title'),
            [[
                'type' => 'dropdown',
                'text' => $messages->get('ui-quantity-content') . "\n" . ItemDisplayName::sanitize($itemName) . " — Slot $slot",
                'options' => $options,
                'default' => 0
            ]],
            static function(Player $responsePlayer, array $data) use ($manager, $sessionId, $slot, $maxAmount, $sourceSnapshot, $expectedRevision) : void{
                $index = $data[0] ?? null;
                if(!is_int($index) || $index < 0 || $index >= $maxAmount){
                    $manager->getMessages()->send($responsePlayer, 'trade-offer-invalid');
                    SlotSelectionForm::send($manager, $responsePlayer, $sessionId);
                    return;
                }
                $manager->setOffer($responsePlayer, $sessionId, $slot, $index + 1, $sourceSnapshot, $expectedRevision);
            }
        ));
    }
}
