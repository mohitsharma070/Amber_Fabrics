<?php

final class CommercePresenter
{
    private const ORDER_STATUS = [
        'pending' => ['label' => 'Pending', 'class' => 'warning'],
        'confirmed' => ['label' => 'Confirmed', 'class' => 'info'],
        'processing' => ['label' => 'Processing', 'class' => 'primary'],
        'packed' => ['label' => 'Packed', 'class' => 'primary'],
        'shipped' => ['label' => 'Shipped', 'class' => 'primary'],
        'delivered' => ['label' => 'Delivered', 'class' => 'success'],
        'cancelled' => ['label' => 'Cancelled', 'class' => 'danger'],
        'returned' => ['label' => 'Returned', 'class' => 'secondary'],
        'refunded' => ['label' => 'Refunded', 'class' => 'dark'],
    ];

    private const PAYMENT_STATUS = [
        'pending' => ['label' => 'Pending', 'class' => 'secondary'],
        'paid' => ['label' => 'Paid', 'class' => 'success'],
        'failed' => ['label' => 'Failed', 'class' => 'danger'],
        'refunded' => ['label' => 'Refunded', 'class' => 'dark'],
    ];

    public static function orderStatus(string $status): array
    {
        $status = strtolower(trim($status));
        return self::ORDER_STATUS[$status] ?? ['label' => ucfirst($status), 'class' => 'secondary'];
    }

    public static function paymentStatus(string $status): array
    {
        $status = strtolower(trim($status));
        return self::PAYMENT_STATUS[$status] ?? ['label' => ucfirst($status), 'class' => 'secondary'];
    }

    public static function quantityUnitSuffix(string $unitType): string
    {
        if ($unitType === 'piece') {
            return ' pc';
        }
        if ($unitType === 'set') {
            return ' set';
        }
        return 'm';
    }
}
