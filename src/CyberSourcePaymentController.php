<?php

namespace Sunnysideup\PaymentCyberSource;

use SilverStripe\Control\Controller;
use Sunnysideup\Ecommerce\Model\Money\EcommercePayment;
use SilverStripe\Core\Environment;


/**
 * Class \Sunnysideup\PaymentCyberSource\CyberSourcePaymentController
 *
 */
class CyberSourcePaymentController extends Controller
{
    private static $allowed_actions = [
        'paid',
        'returned',
    ];

    private static $url_segment = 'cybersourcepayment';

    public function paid()
    {
        $id = $_REQUEST['transaction_id'];
        $reasonCode = $_REQUEST['reason_code'];
        $authAmount = $_REQUEST['auth_amount'];
        $currency = $_REQUEST['req_currency'];

        /** @var CyberSourcePayment $payment */
        $payment = CyberSourcePayment::get_by_id($id);

        if ($payment && $this->signatureCheck($_REQUEST)) {
            if (100 === intval($reasonCode)) {
                $payment->Status = EcommercePayment::SUCCESS_STATUS;
            } else {
                $payment->Status = EcommercePayment::FAILURE_STATUS;
            }

            $payment->SettlementAmount->Amount = $authAmount;
            $payment->SettlementAmount->Currency = $currency;
            $payment->write();
        }
    }

    public function returned()
    {
        $id = $_REQUEST['transaction_id'];

        /** @var CyberSourcePayment $payment */
        $payment = CyberSourcePayment::get_by_id($id);

        if (!$this->signatureCheck($_REQUEST)) {
            user_error('Transaction signature is incorrect', E_USER_WARNING);
        }
        else if ($payment) {
            $payment->redirectToOrder();
        } else {
            user_error('could not find payment with matching ID', E_USER_WARNING);
        }
    }

    protected function signatureCheck($params): bool
    {
        $signedFieldNames = explode(",",$params['signed_field_names']);
        foreach ($signedFieldNames as $field) {
            $dataToSign[] = $field . "=" . $params[$field];
        }
        $data = implode(",",$dataToSign);

        $signature =  base64_encode(hash_hmac('sha256', $data, Environment::getEnv('CYBERSOURCE_SECRET_KEY'), true));

        if ($params['signature'] === $signature) {
            return true;
        }
        return false;
    }
}
