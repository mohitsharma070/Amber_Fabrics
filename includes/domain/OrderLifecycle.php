<?php

final class OrderLifecycle
{
    private const TRANSITIONS = [
        'pending' => ['pending', 'confirmed', 'packed', 'cancelled'],
        'confirmed' => ['confirmed', 'packed', 'shipped', 'cancelled'],
        'packed' => ['packed', 'shipped', 'cancelled'],
        'shipped' => ['shipped', 'delivered', 'returned'],
        'delivered' => ['delivered', 'returned'],
        'cancelled' => ['cancelled', 'refunded'],
        'returned' => ['returned', 'refunded'],
        'refunded' => ['refunded'],
    ];

    public static function canTransition(string $currentStatus, string $nextStatus): bool
    {
        $current = self::normalize($currentStatus);
        $next = self::normalize($nextStatus);
        $allowed = self::TRANSITIONS[$current] ?? [$current];

        return in_array($next, $allowed, true);
    }

    public static function allowedNext(string $currentStatus): array
    {
        $current = self::normalize($currentStatus);
        return self::TRANSITIONS[$current] ?? [$current];
    }

    private static function normalize(string $status): string
    {
        return strtolower(trim($status));
    }
}
