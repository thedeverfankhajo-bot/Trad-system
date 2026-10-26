<?php

declare(strict_types=1);

namespace TradSystem\util;

use pocketmine\command\CommandSender;
use pocketmine\utils\Config;

final class Messages{
    public function __construct(
        private readonly Config $messages,
        private readonly string $prefix
    ){}

    /** @param array<string, int|float|string> $variables */
    public function get(string $key, array $variables = []) : string{
        $value = $this->messages->get($key);
        if(!is_string($value)){
            $value = $key;
        }
        return MessageFormatter::interpolate($value, $variables);
    }

    /** @param array<string, int|float|string> $variables */
    public function send(CommandSender $sender, string $key, array $variables = [], bool $withPrefix = true) : void{
        $message = $this->get($key, $variables);
        $sender->sendMessage(($withPrefix ? $this->prefix : '') . $message);
    }
}
