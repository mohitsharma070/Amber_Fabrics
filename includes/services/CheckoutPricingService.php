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
        $remainingDiscount = $discountAmount;
        $itemCount = count($orderItems);
        foreach ($orderItems as $index => &$item) {
            $lineTotal = (float) ($item['total'] ?? 0.0);
            if ($itemCount === 1 || $index === ($itemCount - 1)) {
                $itemDiscount = round($remainingDiscount, 2);
            } else {
                $itemDiscount = ($subtotal > 0 && $discountAmount > 0)
                    ? round(($lineTotal / $subtotal) * $discountAmount, 2)
                    : 0.0;
                $itemDiscount = min($itemDiscount, $remainingDiscount);
            }
            $itemDiscount = min($itemDiscount, $lineTotal);
            $remainingDiscount = round(max(0.0, $remainingDiscount - $itemDiscount), 2);

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
}
