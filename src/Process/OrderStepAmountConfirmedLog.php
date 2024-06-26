<?php

namespace Sunnysideup\PaymentCyberSource\Process;

use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\DB;
use Sunnysideup\Ecommerce\Model\Order;
use Sunnysideup\Ecommerce\Model\Process\OrderStatusLog;

/**
 * Class \Sunnysideup\PaymentDps\Model\Process\OrderStepAmountConfirmedLog
 *
 * @property bool $IsValid
 */
class OrderStepAmountConfirmedLog extends OrderStatusLog
{
    private static $table_name = 'OrderStepAmountConfirmedLogCS';

    private static $db = [
        'IsValid' => 'Boolean',
    ];

    /**
     * @var int
     */
    private static $maximum_payment_attempts_per_day = 3;

    public function i18n_singular_name()
    {
        return 'Amount Validated Log';
    }

    public function i18n_plural_name()
    {
        return 'Amount Validated Logs';
    }

    public static function has_been_confirmed(Order $order): bool
    {
        return  OrderStepAmountConfirmedLog::get()->filter(
            [
                'OrderID' => $order->ID,
                'IsValid' => true,
            ]
        )->exists();
    }

    public static function is_locked_out(Order $order): bool
    {
        $count = OrderStepAmountConfirmedLog::get()->filter(
            [
                'OrderID' => $order->ID,
                'Created:GreaterThan' => date('Y-m-d h:i', strtotime('1 day')),
            ]
        )->count();
        $max = Config::inst()->get(OrderStepAmountConfirmedLog::class, 'maximum_payment_attempts_per_day');

        return $count > $max;
    }

    public static function test_answer(Order $order, float $answer): bool
    {
        $orderStep = $order->Status();
        if (self::is_right_step($orderStep)) {
            if ($orderStep->hasAmountConfirmed($order)) {
                return true;
            }
            $isValid = false;

            $log = OrderStepAmountConfirmedLog::create(
                ['OrderID' => $order->ID, 'Answer' => floatval($answer)]
            );
            $payment = $orderStep->relevantPayments($order)->last();
            if ($payment) {
                $amount = (float) $payment->Amount->Amount;
                $deduction = (float) $payment->RandomDeduction;
                $expectedAnswer = (float) $amount - $deduction;
                if ($expectedAnswer === (float) $answer) {
                    $isValid = true;
                }
            }
            $log->IsValid = $isValid;
            $log->write();

            return $isValid;
        }

        return true;
    }

    protected static function is_right_step($orderStep): bool
    {
        return $orderStep instanceof OrderStepAmountConfirmed;
    }
}
