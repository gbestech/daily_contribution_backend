<?php
// api/config/charges.php

/**
 * Load charge settings from the `settings` table.
 * Falls back to defaults if not configured.
 */
function getChargeSettings(PDO $db = null): array
{
    $defaults = [
        'enabled'              => true,
        'threshold_amount'     => 200000,
        'below_threshold_rate' => 1.0,   // % applied when amount <= threshold
        'above_threshold_rate' => 0.4,   // % applied when amount > threshold
    ];

    if (!$db) {
        return $defaults;
    }

    try {
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'charges'");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return $defaults;
        }

        $saved = json_decode($row['setting_value'], true);
        if (!is_array($saved)) {
            return $defaults;
        }

        $merged = array_merge($defaults, $saved);

        $merged['threshold_amount']     = floatval($merged['threshold_amount']);
        $merged['below_threshold_rate'] = floatval($merged['below_threshold_rate']);
        $merged['above_threshold_rate'] = floatval($merged['above_threshold_rate']);
        $merged['enabled']              = (bool) $merged['enabled'];

        return $merged;
    } catch (Exception $e) {
        return $defaults;
    }
}

/**
 * Calculate DEPOSIT charge.
 *
 * Rule:
 *   - Amount >  200,000 → 0.4%
 *   - Amount <= 200,000 → 1.0%
 *
 * The threshold and both rates are configurable from the Settings page.
 *
 * @param float    $amount
 * @param PDO|null $db     Pass the DB handle to use saved settings
 * @return array
 */
function calculateCharge(float $amount, PDO $db = null): array
{
    if ($amount < 0) {
        $amount = 0;
    }

    $cfg = getChargeSettings($db);

    if (!$cfg['enabled']) {
        return [
            'amount'    => round($amount, 2),
            'rate'      => 0,
            'rate_pct'  => 0,
            'charge'    => 0.0,
            'net'       => round($amount, 2),
            'tier'      => 'disabled',
            'threshold' => $cfg['threshold_amount'],
        ];
    }

    // STRICTLY ABOVE gets the lower rate (0.4%);
    // Anything ≤ threshold gets the higher rate (1.0%).
    if ($amount > $cfg['threshold_amount']) {
        $ratePct = $cfg['above_threshold_rate'];   // 0.4%
        $tier    = 'above_threshold';
    } else {
        $ratePct = $cfg['below_threshold_rate'];   // 1.0%
        $tier    = 'below_threshold';
    }

    $rate   = $ratePct / 100;
    $charge = round($amount * $rate, 2);
    $net    = round($amount - $charge, 2);

    return [
        'amount'    => round($amount, 2),
        'rate'      => $rate,
        'rate_pct'  => $ratePct,
        'charge'    => $charge,
        'net'       => $net,
        'tier'      => $tier,
        'threshold' => $cfg['threshold_amount'],
    ];
}

function getCharge(float $amount, PDO $db = null): float
{
    return calculateCharge($amount, $db)['charge'];
}

function getNetAmount(float $amount, PDO $db = null): float
{
    return calculateCharge($amount, $db)['net'];
}