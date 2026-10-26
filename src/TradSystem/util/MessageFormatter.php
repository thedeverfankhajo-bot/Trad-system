<?php

declare(strict_types=1);

namespace TradSystem\util;

final class MessageFormatter{
    /** @param array<string, int|float|string> $variables */
    public static function interpolate(string $template, array $variables) : string{
        $result = preg_replace_callback(
            '/%([A-Za-z0-9_-]+)%/',
            static function(array $matches) use ($variables) : string{
                $name = $matches[1];
                return array_key_exists($name, $variables) ? (string) $variables[$name] : $matches[0];
            },
            $template
        );

        // Keep the original template if PCRE cannot process it.
        return $result ?? $template;
    }
}
