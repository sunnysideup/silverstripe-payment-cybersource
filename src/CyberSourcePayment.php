<?php

namespace Sunnysideup\PaymentCyberSource;

use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Manifest\ModuleResourceLoader;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\FieldType\DBMoney;
use SilverStripe\View\Requirements;
use Sunnysideup\Ecommerce\Forms\OrderForm;
use Sunnysideup\Ecommerce\Model\Money\EcommercePayment;
use Sunnysideup\Ecommerce\Model\Order;
use Sunnysideup\Ecommerce\Money\Payment\PaymentResults\EcommercePaymentFailure;
use Sunnysideup\Ecommerce\Money\Payment\PaymentResults\EcommercePaymentProcessing;
use SilverStripe\Core\Environment;
use SilverStripe\i18n\i18n;

class CyberSourcePayment extends EcommercePayment
{
    private static $table_name = 'CyberSourcePayment';

    private static $db = [
    ];


    /**
     * standard SS variable.
     *
     * @var string
     */
    private static $singular_name = 'CyberSource Secure Acceptance Payment';

    /**
     * standard SS variable.
     *
     * @var string
     */
    private static $plural_name = 'CyberSource Secure Acceptance Payments';

    // AliPay Information

    /**
     * Default Status for Payment.
     *
     * @var string
     */
    private static $default_status = EcommercePayment::PENDING_STATUS;

    private static $email_debug = false;

    private static $logo = 'sunnysideup/payment-cybersource: client/images/cybersourcelogo.png';


    // --- SECURITY ---
    protected function sign($params) {
        $signedFieldNames = explode(",",$params["signed_field_names"]);
        foreach ($signedFieldNames as $field) {
            $dataToSign[] = $field . "=" . $params[$field];
        }
        $data = implode(",",$dataToSign);

        return base64_encode(hash_hmac('sha256', $data, Environment::getEnv('CYBERSOURCE_SECRET_KEY'), true));
    }

    // --- URL/PARAMS ---
    protected function getParams($amount, $currency) {
        $initialParams = [
            'access_key' => Environment::getEnv('CYBERSOURCE_ACCESS_KEY'),
            'profile_id' => Environment::getEnv('CYBERSOURCE_PROFILE_ID'),
            'transaction_uuid' => $this->ID,
            'unsigned_field_names' => '',
            'signed_date_time' => gmdate("Y-m-d\TH:i:s\Z"),
            'locale' => i18n::get_locale(),

            'transaction_type' => 'authorization',
            'reference_number' => $this->ID,
            'amount' => $amount,
            'currency' => $currency,

            'signed_field_names' => 'access_key,profile_id,transaction_uuid,signed_field_names,unsigned_field_names,signed_date_time,locale,transaction_type,reference_number,amount,currency'
        ];

        $signature = $this->sign($initialParams);
        $initialParams['signature'] = $signature;

        return $initialParams;
    }

    // --- FORMS ---
    public function getPaymentFormFields($amount = 0, ?Order $order = null): FieldList
    {
        $logo = $this->config()->get('logo');
        $src = ModuleResourceLoader::singleton()->resolveURL($logo);

        return new FieldList(
            new LiteralField('CybersourceLogo', DBField::create_field(
                'HTMLText',
                '<img src="' . $src . '" alt="Credit card payments powered by Cybersource"/>'
            ))
        );
    }

    public function getPaymentFormRequirements(): array
    {
        return [];
    }

    public function CyberSourceForm($url, $params)
    {
        $formHTML = '<form id="payment_confirmation" method="post" action="' . $url . '">';

        foreach($params as $param => $value) {
            $formHTML .= '<input type="hidden" id="'.$param.'" name="'.$param.'" value="'.$value.'" />';
        }

        $formHTML .= 
            '<input type="submit" id="submit" value="Confirm"/>
            </form>
            <script type="text/javascript">
                document.addEventListener("DOMContentLoaded", function() {
                    document.getElementById("submit").click();
                });
            </script>
            <style>#submit { display: none; }</style>';

        return DBField::create_field(
            'HTMLText',
            $formHTML
        );
    }


    // --- PROCESSING ---
    /**
     * Process the payment method.
     *
     * @param mixed $data
     */
    public function processPayment($data, Form $form)
    {
        $order = $this->getOrderCached();
        //if currency has been pre-set use this
        $currency = $this->Amount->Currency;
        //if amout has been pre-set, use this
        $amount = $this->Amount->Amount;
        if ($order && $order->exists()) {
            //amount may need to be adjusted to total outstanding
            //or amount may not have been set yet
            $amount = $order->TotalOutstanding();
            //get currency from Order
            //this is better than the pre-set currency one
            //which may have been set to the default
            $currencyObject = $order->CurrencyUsed();
            if ($currencyObject) {
                $currency = $currencyObject->Code;
            }
        }
        if (! $amount && ! empty($data['Amount'])) {
            $amount = (float) $data['Amount'];
        }
        if (! $currency && ! empty($data['Currency'])) {
            $currency = (string) $data['Currency'];
        }
        //final backup for currency
        if (! $currency) {
            $currency = EcommercePayment::site_currency();
        }

        if ($this->hasRandomDeduction()) {
            $randomDeduction = $this->setAndReturnRandomDeduction();
            if ($randomDeduction) {
                $amount -= $randomDeduction;
            }
        }

        $url = $this->getURL();
        $params = $this->getParams($amount, $currency);
        $csform = $this->CyberSourceForm($url, $params);

        $page = new SiteTree();
        $page->Title = 'Redirection to Cybersource...';
        // $page->Logo = $this->getLogoResource();
        $page->Form = $csform;
        $controller = new ContentController($page);
        Requirements::clear();
        Requirements::javascript('https://ajax.googleapis.com/ajax/libs/jquery/3.6.1/jquery.min.js');

        return EcommercePaymentProcessing::create($controller->RenderWith('Sunnysideup\Ecommerce\PaymentProcessingPage'));
    }

    protected function getURL()
    {
        //return 'https://testsecureacceptance.cybersource.com/pay';
        return Environment::getEnv('CYBERSOURCE_URL');
    }

    protected function hasRandomDeduction(): bool
    {
        return false;
    }

    protected function setAndReturnRandomDeduction(): float
    {
        return 0;
    }
}
