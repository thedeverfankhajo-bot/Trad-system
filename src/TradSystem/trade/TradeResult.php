<?php

declare(strict_types=1);

namespace TradSystem\trade;

final class TradeResult{
    private function __construct(
        public readonly bool $success,
        public readonly string $reason,
        public readonly bool $rollbackSuccessful = true
    ){}

    public static function success() : self{
        return new self(true, 'success');
    }

    public static function failure(string $reason) : self{
        return new self(false, $reason, true);
    }

    public static function criticalFailure(string $reason) : self{
        return new self(false, $reason, false);
    }
}
