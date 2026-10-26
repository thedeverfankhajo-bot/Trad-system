<?php

declare(strict_types=1);

namespace TradSystem\form;

use pocketmine\player\Player;
use TradSystem\trade\TradeManager;

final class TradeConfirmationForm{
    public static function send(TradeManager $manager, Player $player, string $sessionId) : void{
        $session = $manager->getSessionFor($player, $sessionId);
        if($session === null){
            $manager->getMessages()->send($player, 'trade-expired');
            MainForm::send($manager, $player);
            return;
        }
        $messages = $manager->getMessages();
        $uuid = $player->getUniqueId()->toString();
        $partnerUuid = $session->getOtherUuid($uuid);
        if($partnerUuid === null){
            MainForm::send($manager, $player);
            return;
        }
        $offers = $session->partyOneName . ":\n" . $manager->formatOffer($session, $session->partyOneUuid) . "\n\n" .
            $session->partyTwoName . ":\n" . $manager->formatOffer($session, $session->partyTwoUuid);
        $content = $messages->get('ui-confirm-content', [
            'player_a' => $session->partyOneName,
            'player_b' => $session->partyTwoName,
            'offers' => $offers,
            'self_status' => $session->isConfirmed($uuid) ? $messages->get('ui-confirmed') : $messages->get('ui-not-confirmed'),
            'partner_status' => $session->isConfirmed($partnerUuid) ? $messages->get('ui-confirmed') : $messages->get('ui-not-confirmed')
        ]);
        $buttons = [
            $messages->get('ui-confirm-button'),
            $messages->get('ui-back-offer'),
            $messages->get('ui-cancel-button')
        ];
        $revision = $session->getRevision();
        $player->sendForm(new SimpleForm(
            $uuid,
            $messages->get('ui-confirm-title'),
            $content,
            $buttons,
            static function(Player $responsePlayer, int $index) use ($manager, $sessionId, $revision) : void{
                if($index === 0){
                    $manager->confirmTrade($responsePlayer, $sessionId, $revision);
                }elseif($index === 1){
                    TradeOfferForm::send($manager, $responsePlayer, $sessionId);
                }else{
                    $manager->cancelTrade($responsePlayer, $sessionId);
                }
            }
        ));
    }
}
