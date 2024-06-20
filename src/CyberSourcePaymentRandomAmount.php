<?php

namespace Sunnysideup\PaymentCyberSource;

/**
 * Class \Sunnysideup\PaymentCyberSource\CyberSourcePaymentRandomAmount
 *
 * @property float $RandomDeduction
 */
class CyberSourcePaymentRandomAmount extends CyberSourcePayment
{
    private static $max_random_deduction = 1;

    private static $db = [
        'RandomDeduction' => 'Currency',
    ];

    private static $table_name = 'CyberSourcePaymentRandomAmount';

    protected function hasRandomDeduction(): bool
    {
        return true;
    }

    protected function setAndReturnRandomDeduction(): float
    {
        $max = $this->Config()->get('max_random_deduction');
        $amount = round($max * (mt_rand() / mt_getrandmax()), 2);
        $this->RandomDeduction = $amount;
        $this->write();

        return floatval($this->RandomDeduction);
    }
}
