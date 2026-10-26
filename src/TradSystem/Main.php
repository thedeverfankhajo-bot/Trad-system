<?php

declare(strict_types=1);

namespace TradSystem;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\Config;
use TradSystem\command\TradeCommand;
use TradSystem\config\TradeConfig;
use TradSystem\listener\PlayerListener;
use TradSystem\trade\TradeExecutor;
use TradSystem\trade\TradeManager;
use TradSystem\util\Messages;

final class Main extends PluginBase{
    private ?TradeManager $tradeManager = null;
    private ?TradeCommand $tradeCommand = null;

    protected function onEnable() : void{
        $this->saveDefaultConfig();
        $this->saveResource('messages.yml');
        $validatedConfig = new TradeConfig($this->getConfig()->getAll(), $this->getLogger());
        $this->tradeManager = new TradeManager(
            $this,
            $validatedConfig,
            new Messages(new Config($this->getDataFolder() . 'messages.yml', Config::YAML), $validatedConfig->messagesPrefix),
            new TradeExecutor()
        );
        $this->tradeCommand = new TradeCommand($this->tradeManager, $this->tradeManager->getMessages());
        $this->getServer()->getPluginManager()->registerEvents(new PlayerListener($this->tradeManager), $this);
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() : void{
            $this->tradeManager?->tick();
        }), 20);
        $this->getLogger()->info('TradeSystem enabled. Trade items are kept in player inventories until final commit.');
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
        if(strtolower($command->getName()) !== 'trade' || $this->tradeCommand === null){
            return false;
        }
        return $this->tradeCommand->execute($sender, array_values(array_map(static fn($arg) : string => (string) $arg, $args)));
    }

    protected function onDisable() : void{
        $this->tradeManager?->shutdown();
        $this->tradeManager = null;
        $this->tradeCommand = null;
    }
}
