<?php

namespace Sunnysideup\PaymentCyberSource;

use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Manifest\ModuleResourceLoader;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\View\Requirements;
use Sunnysideup\Ecommerce\Model\Money\EcommercePayment;
use Sunnysideup\Ecommerce\Model\Order;
use Sunnysideup\Ecommerce\Money\Payment\PaymentResults\EcommercePaymentProcessing;
use SilverStripe\Core\Environment;
use SilverStripe\i18n\i18n;
use Sunnysideup\Ecommerce\Model\Address\BillingAddress;
use Sunnysideup\Ecommerce\Model\Address\ShippingAddress;
use Sunnysideup\PaymentCyberSource\Api\SignatureCheck;

class CyberSourcePayment extends EcommercePayment
{
    private static $table_name = 'CyberSourcePayment';

    private static $db = [
        'Decision' => 'Text',
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




    // --- URL/PARAMS ---
    protected function getParams($amount, $currency)
    {
        $order = $this->getOrderCached();
        $billingAddress = $order->CreateOrReturnExistingAddress(BillingAddress::class);
        if ($order->IsSeparateShippingAddress()) {
            $shippingAddress = $order->ShippingAddress();
            $shippingAddress->setFieldsToMatchBillingAddress();
        } else {
            //if shipping address is the same as billing address, use the billing address
            $shippingAddress = $billingAddress;
        }
        $shippingAddress = $order->CreateOrReturnExistingAddress(ShippingAddress::class);
        $initialParams = [
            'access_key' => Environment::getEnv('CYBERSOURCE_ACCESS_KEY'),
            'profile_id' => Environment::getEnv('CYBERSOURCE_PROFILE_ID'),
            'transaction_uuid' => $this->ID,
            'signed_field_names' => '',
            'unsigned_field_names' => '', // just to confirm
            'signed_date_time' => gmdate("Y-m-d\TH:i:s\Z"),
            'locale' => i18n::get_locale(),
            'transaction_type' => Environment::getEnv('CYBERSOURCE_TRANSACTION_TYPE'),
            'reference_number' => $order->ID,
            'amount' => $amount,
            'currency' => $currency,
            //allow_payment_token_update:
            // Indicates whether the customer can update the billing, shipping, and payment information on  the order review page.
            // 'allow_payment_token_update' => true,
            'bill_to_forename' => implode(' ', array_filter([$billingAddress->Prefix, $billingAddress->FirstName,])),
            'bill_to_surname' => $billingAddress->Surname,
            'bill_to_address_line1' => $billingAddress->Address,
            'bill_to_address_line2' => $billingAddress->Address2,
            'bill_to_address_city' => $billingAddress->City,
            'bill_to_address_postal_code' => $billingAddress->PostalCode,
            'bill_to_address_country' => $billingAddress->Country,
            'bill_to_phone' => $billingAddress->Phone,
            'bill_to_company_name' => $billingAddress->CompanyName,
            // 'bill_to_email' => $billingAddress->Email,

            'ship_to_forename' => implode(' ', array_filter([$shippingAddress->Prefix, $shippingAddress->FirstName,])),
            'ship_to_surname' => $shippingAddress->Surname,
            'ship_to_address_line1' => $shippingAddress->Address,
            'ship_to_address_line2' => $shippingAddress->Address2,
            'ship_to_address_city' => $shippingAddress->City,
            'ship_to_address_postal_code' => $shippingAddress->PostalCode,
            'ship_to_address_country' => $shippingAddress->Country,
            'ship_to_phone' => $shippingAddress->Phone,
        ];
        $initialParams['signed_field_names'] = implode(',', array_keys($initialParams));

        $initialParams['signature'] = SignatureCheck::sign($initialParams);

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
            '
<form>
    <input type="submit" id="submit" value="Confirm"/>
</form>
<script type="text/javascript">
    document.addEventListener("DOMContentLoaded", function() {
        document.getElementById("submit").click();
    });
</script>
<style>
    #submit {
        display: none;
    }
</style>


            ';

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
