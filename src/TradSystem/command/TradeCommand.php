<?php

declare(strict_types=1);

namespace TradSystem\command;

use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use TradSystem\trade\TradeManager;
use TradSystem\util\Messages;

final class TradeCommand{
    public function __construct(
        private readonly TradeManager $manager,
        private readonly Messages $messages
    ){}

    /** @param list<string> $args */
    public function execute(CommandSender $sender, array $args) : bool{
        if(!$sender->hasPermission('tradesystem.command.trade')){
            $this->messages->send($sender, 'command-no-permission');
            return true;
        }
        if($args === []){
            if(!$sender instanceof Player){
                $this->messages->send($sender, 'command-player-only');
                return true;
            }
            $this->manager->openMainForm($sender);
            return true;
        }
        $subcommand = strtolower($args[0]);
        if($subcommand === 'help'){
            $this->messages->send($sender, 'trade-help');
            return true;
        }
        if($subcommand === 'cancel'){
            if(!$sender instanceof Player){
                $this->messages->send($sender, 'command-player-only');
                return true;
            }
            $this->manager->cancelActiveTrade($sender);
            return true;
        }
        if($subcommand === 'accept' || $subcommand === 'deny'){
            if(!$sender instanceof Player){
                $this->messages->send($sender, 'command-player-only');
                return true;
            }
            if(count($args) !== 2){
                $this->messages->send($sender, 'command-usage');
                return true;
            }
            if($subcommand === 'accept'){
                $this->manager->acceptRequestByName($sender, $args[1]);
            }else{
                $this->manager->denyRequestByName($sender, $args[1]);
            }
            return true;
        }
        if(count($args) !== 1){
            $this->messages->send($sender, 'command-usage');
            return true;
        }
        if(!$sender instanceof Player){
            $this->messages->send($sender, 'command-player-only');
            return true;
        }
        $this->manager->sendRequest($sender, $args[0]);
        return true;
    }
}
