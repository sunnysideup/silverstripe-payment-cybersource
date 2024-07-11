<?php

namespace Sunnysideup\PaymentCyberSource;

use SilverStripe\Control\Controller;
use Sunnysideup\Ecommerce\Model\Money\EcommercePayment;
use Sunnysideup\PaymentCyberSource\Api\SignatureCheck;

/**
 * Class \Sunnysideup\PaymentCyberSource\CyberSourcePaymentController
 *
 */
class CyberSourcePaymentController extends Controller
{
    private static $allowed_actions = [
        'returned',
    ];

    private static $url_segment = 'cybersourcepayment';

    public function returned()
    {
        $request = $this->getRequest();
        $id = (int) $request->requestVar('req_transaction_uuid');
        $reasonCode = $request->requestVar('reason_code');
        $authAmount = $request->requestVar('auth_amount');
        $currency = $request->requestVar('req_currency');
        $decision = $request->requestVar('decision');

        /** @var CyberSourcePayment $payment */
        $payment = CyberSourcePayment::get_by_id($id);

        /*if ($decision == 'CANCEL') {
            $order = $payment->getOrderCached();
            $order->Cancel(null, 'Cancelled during Cybersource Checkout');
            return $this->redirect('/');
        }*/

        if (SignatureCheck::signature_check($_REQUEST)) {
            if ($payment) {
                if (100 === intval($reasonCode)) {
                    $payment->Status = EcommercePayment::SUCCESS_STATUS;
                } else {
                    $payment->Status = EcommercePayment::FAILURE_STATUS;
                }

                $payment->SettlementAmount->Amount = $authAmount;
                $payment->SettlementAmount->Currency = $currency;
                $payment->Decision = $decision;
                $payment->write();
                $payment->redirectToOrder();
            } else {
                user_error('could not find payment with matching ID', E_USER_ERROR);
            }
        } else {
            user_error('Transaction signature is incorrect', E_USER_ERROR);
        }
    }


}
