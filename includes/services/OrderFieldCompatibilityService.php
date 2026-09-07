<?php

final class OrderFieldCompatibilityService
{
    public static function legacyStatus(string $orderStatus): string
    {
        return match (strtolower(trim($orderStatus))) {
            'pending', 'confirmed', 'shipped', 'delivered', 'cancelled' => strtolower(trim($orderStatus)),
            'refunded' => 'cancelled',
            'packed', 'returned' => 'processing',
            default => throw new InvalidArgumentException('Unsupported canonical order status.'),
        };
    }

    /**
     * Bind the note four times. The legacy assignment intentionally runs first
     * so both columns derive from the same pre-update canonical value in MySQL.
     */
    public static function appendNoteAssignments(): string
    {
        return "notes = CASE WHEN order_notes IS NULL OR order_notes = '' THEN ? ELSE CONCAT(order_notes, '\n', ?) END,
                order_notes = CASE WHEN order_notes IS NULL OR order_notes = '' THEN ? ELSE CONCAT(order_notes, '\n', ?) END";
    }
}
