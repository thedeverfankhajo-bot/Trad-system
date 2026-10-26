<?php

declare(strict_types=1);

namespace TradSystem\config;

final class TradeConfig{
    public readonly int $requestTimeoutSeconds;
    public readonly int $tradeTimeoutSeconds;
    public readonly int $maxOfferSlots;
    public readonly int $maxItemsPerOffer;
    public readonly int $maxTradesPerMinute;
    public readonly int $cooldownSeconds;
    public readonly bool $soundEffects;
    public readonly bool $enableAuditLog;
    public readonly string $messagesPrefix;

    /** @param array<string, mixed> $values */
    public function __construct(array $values, \AttachableLogger $logger){
        $this->requestTimeoutSeconds = self::boundedInt($values, 'request-timeout-seconds', 60, 10, 300, $logger);
        $this->tradeTimeoutSeconds = self::boundedInt($values, 'trade-timeout-seconds', 300, 30, 900, $logger);
        $this->maxOfferSlots = self::boundedInt($values, 'max-offer-slots', 8, 1, 36, $logger);
        $this->maxItemsPerOffer = self::boundedInt($values, 'max-items-per-offer', 64, 1, 4096, $logger);
        $this->maxTradesPerMinute = self::boundedInt($values, 'max-trades-per-minute', 8, 1, 60, $logger);
        $this->cooldownSeconds = self::boundedInt($values, 'cooldown-seconds', 3, 0, 60, $logger);

        $allowSelfTrade = $values['allow-self-trade'] ?? false;
        if(!is_bool($allowSelfTrade)){
            $logger->warning("Invalid 'allow-self-trade'; expected true or false. Self-trading remains disabled.");
        }elseif($allowSelfTrade){
            $logger->warning("'allow-self-trade' cannot be enabled; self-trading is hard-disabled for safety.");
        }
        $this->soundEffects = self::boolValue($values, 'sound-effects', false, $logger);
        $this->enableAuditLog = self::boolValue($values, 'enable-audit-log', true, $logger);
        $prefix = $values['messages-prefix'] ?? "§8[§bTradeSystem§8] §r";
        if(!is_string($prefix) || strlen($prefix) > 64){
            $logger->warning("Invalid 'messages-prefix'; using the default prefix.");
            $prefix = "§8[§bTradeSystem§8] §r";
        }
        $this->messagesPrefix = $prefix;
    }

    /** @param array<string, mixed> $values */
    private static function boundedInt(array $values, string $key, int $default, int $minimum, int $maximum, Logger $logger) : int{
        $value = $values[$key] ?? $default;
        if(!is_int($value) || $value < $minimum || $value > $maximum){
            $logger->warning("Invalid '$key'; expected an integer from $minimum to $maximum. Using $default.");
            return $default;
        }
        return $value;
    }

    /** @param array<string, mixed> $values */
    private static function boolValue(array $values, string $key, bool $default, Logger $logger) : bool{
        $value = $values[$key] ?? $default;
        if(!is_bool($value)){
            $logger->warning("Invalid '$key'; expected true or false. Using " . ($default ? 'true' : 'false') . '.');
            return $default;
        }
        return $value;
    }
}
