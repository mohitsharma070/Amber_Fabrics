<?php

final class CheckoutPricingService
{
    public static function taxContext(array $siteSettings, string $buyerState, string $country): array
    {
        $defaultGstRate = max(0.0, (float) ($siteSettings['gst_rate'] ?? 18));
        $defaultHsnCode = trim((string) ($siteSettings['hsn_code'] ?? '5208'));
        $companyState = strtolower(trim((string) ($siteSettings['company_state'] ?? '')));
        $buyerState = strtolower(trim($buyerState));
        $isIndiaOrder = strcasecmp(trim($country), 'india') === 0;

        if (!$isIndiaOrder) {
            $taxType = 'none';
        } elseif ($companyState !== '' && $buyerState !== '' && $companyState !== $buyerState) {
            $taxType = 'igst';
        } else {
            $taxType = 'cgst_sgst';
        }
        return [
            'default_gst_rate' => $defaultGstRate,
            'default_hsn_code' => $defaultHsnCode,
            'tax_type' => $taxType,
        ];
    }

    public static function allocateIncludedTax(
        array $orderItems,
        float $subtotal,
        float $discountAmount,
        string $taxType,
        float $defaultGstRate,
        string $defaultHsnCode
    ): array {
        $itemDiscounts = self::allocateDiscount($orderItems, $subtotal, $discountAmount);
        foreach ($orderItems as $index => &$item) {
            $lineTotal = (float) ($item['total'] ?? 0.0);
            $itemDiscount = (float) ($itemDiscounts[$index] ?? 0.0);

            $taxableAmount = round(max(0.0, $lineTotal - $itemDiscount), 2);
            $itemGstRate = $taxType === 'none'
                ? 0.0
                : max(0.0, (float) ($item['effective_gst_rate'] ?? $defaultGstRate));
            $gstAmount = ($itemGstRate > 0 && $taxableAmount > 0)
                ? round($taxableAmount * $itemGstRate / (100 + $itemGstRate), 2)
                : 0.0;
            $cgstAmount = 0.0;
            $sgstAmount = 0.0;
            $igstAmount = 0.0;
            if ($taxType === 'cgst_sgst' && $gstAmount > 0) {
                $cgstAmount = round($gstAmount / 2, 2);
                $sgstAmount = round($gstAmount - $cgstAmount, 2);
            } elseif ($taxType === 'igst' && $gstAmount > 0) {
                $igstAmount = $gstAmount;
            }

            $item['discount_amount'] = $itemDiscount;
            $item['taxable_amount'] = $taxableAmount;
            $item['gst_rate_snapshot'] = round($itemGstRate, 3);
            $item['gst_amount'] = $gstAmount;
            $item['cgst_amount'] = $cgstAmount;
            $item['sgst_amount'] = $sgstAmount;
            $item['igst_amount'] = $igstAmount;
            $item['tax_type'] = $taxType;
            $item['hsn_code_snapshot'] = (string) ($item['effective_hsn_code'] ?? $defaultHsnCode);
        }
        unset($item);
        return $orderItems;
    }

    /**
     * Split an order-level discount across lines so the parts always sum back to
     * the whole: sum(item discount) === $discountAmount.
     *
     * Proportional share first, capped per line so no line is discounted below
     * zero, then the residual left by that capping and by 2dp rounding is pushed
     * into whichever lines still have headroom (largest first). The previous
     * implementation dumped the whole residual on the last line and then silently
     * dropped whatever that line could not absorb, which left the per-item
     * discounts short of orders.discount_amount while the order total still used
     * the order-level figure - so the invoice did not foot to the amount charged
     * and the per-item GST, back-computed below from an under-discounted taxable
     * amount, overstated tax on the customer-facing document.
     *
     * place-order.php clamps the discount to the subtotal before calling in, so
     * the residual is normally paise-scale rounding drift rather than a large
     * shortfall. This keeps the invariant explicit here instead of resting on a
     * caller precondition two functions away.
     *
     * @return array<int|string,float> discount keyed by $orderItems key
     */
    private static function allocateDiscount(array $orderItems, float $subtotal, float $discountAmount): array
    {
        $discounts = [];
        $lineTotals = [];
        foreach ($orderItems as $index => $item) {
            $discounts[$index] = 0.0;
            $lineTotals[$index] = round(max(0.0, (float) ($item['total'] ?? 0.0)), 2);
        }

        $discountAmount = round(max(0.0, $discountAmount), 2);
        if ($discountAmount < 0.005 || $discounts === []) {
            return $discounts;
        }

        $remaining = $discountAmount;
        if ($subtotal > 0) {
            foreach ($lineTotals as $index => $lineTotal) {
                $share = max(0.0, min(
                    round(($lineTotal / $subtotal) * $discountAmount, 2),
                    $lineTotal,
                    $remaining
                ));
                $discounts[$index] = $share;
                $remaining = round($remaining - $share, 2);
            }
        }

        if ($remaining >= 0.005) {
            $headroom = [];
            foreach ($lineTotals as $index => $lineTotal) {
                $free = round($lineTotal - $discounts[$index], 2);
                if ($free >= 0.005) {
                    $headroom[$index] = $free;
                }
            }
            arsort($headroom);
            foreach ($headroom as $index => $free) {
                if ($remaining < 0.005) {
                    break;
                }
                $take = min($free, $remaining);
                $discounts[$index] = round($discounts[$index] + $take, 2);
                $remaining = round($remaining - $take, 2);
            }
        }

        if ($remaining >= 0.005) {
            // Only reachable if the discount exceeds the sum of the line totals,
            // which the caller's clamp to subtotal is supposed to prevent. The
            // goods cannot absorb it, so it stays undistributed - but loudly.
            error_log(sprintf(
                '[app] discount allocation shortfall: %.2f of %.2f could not be applied to lines totalling %.2f',
                $remaining,
                $discountAmount,
                array_sum($lineTotals)
            ));
        }

        return $discounts;
    }
}
