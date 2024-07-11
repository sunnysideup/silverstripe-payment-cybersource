<?php

namespace Sunnysideup\PaymentCyberSource\Api;

use SilverStripe\Core\Environment;

class SignatureCheck
{
    // --- SECURITY ---
    public static function sign(array $params): string
    {
        $signedFieldNames = explode(",", $params["signed_field_names"]);
        foreach ($signedFieldNames as $field) {
            $dataToSign[] = $field . "=" . $params[$field];
        }
        $data = implode(",", $dataToSign);

        return base64_encode(hash_hmac('sha256', $data, Environment::getEnv('CYBERSOURCE_SECRET_KEY'), true));
    }

    public static function signature_check(array $params): bool
    {
        return ! empty($params['signature']) && $params['signature'] === self::sign($params);
    }
}
