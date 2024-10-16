<?php

namespace Sunnysideup\PaymentCyberSource;

use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use Sunnysideup\Ecommerce\Model\Money\EcommercePayment;
use Sunnysideup\PaymentCyberSource\Api\SignatureCheck;
use Exception;

/**
 * Class \Sunnysideup\PaymentCyberSource\CyberSourcePaymentController
 *
 */
class CyberSourcePaymentController extends Controller
{
    private static $allowed_actions = [
        'returned',
    ];

    protected $debug = false;

    private static $url_segment = 'cybersourcepayment';

    public function returned()
    {
        $request = $this->getRequest();
        $id = (int) $request->requestVar('req_transaction_uuid');
        $orderID = (int) $request->requestVar('req_reference_number');
        $reasonCode = (int) $request->requestVar('reason_code');
        $authAmount = $request->requestVar('auth_amount');
        $currency = $request->requestVar('req_currency');
        $decision = $request->requestVar('decision');
        if ($this->debug) {
            file_put_contents(
                Director::baseFolder() .'/cybersourcepayment.log',
                print_r(
                    $request->requestVars(),
                    true
                ),
                FILE_APPEND
            );
        }
        /** @var CyberSourcePayment $payment */
        $payment = CyberSourcePayment::get()->filter(['ID' => $id, 'OrderID' => $orderID])->first();

        if ($payment) {
            try {
                if ($decision === 'ACCEPT' && SignatureCheck::signature_check($_REQUEST)) {
                    $payment->Status = EcommercePayment::SUCCESS_STATUS;
                } else {
                    $payment->Status = EcommercePayment::FAILURE_STATUS;
                }

                $payment->SettlementAmount->Amount = $authAmount;
                $payment->SettlementAmount->Currency = $currency;
                $payment->Decision = $reasonCode . ' - ' .$decision;
                $payment->write();
                return $payment->redirectToOrder();

            } catch (Exception $e) {
                print_r($e->getMessage(), 1);
                // do nothing
                file_put_contents(
                    Director::baseFolder() .'/cybersourcepayment.error.log',
                    print_r($e->getMessage(), 1),
                    FILE_APPEND
                );
                file_put_contents(
                    Director::baseFolder() .'/cybersourcepayment.error.log',
                    print_r(
                        $request->requestVars(),
                        true
                    ),
                    FILE_APPEND
                );
            }

        } else {
            return $this->httpError(404, 'Payment not found');
        }
    }


}
