<?php
/**
 * Paystack integration helper.
 * --------------------------------------------------------------------------
 * Reads the school's payment-gateway configuration (Settings > Payment) and
 * provides server-side transaction verification against the Paystack API.
 *
 * Config mapping (school_settings):
 *   payment_gateway   -> must be 'paystack' for the gateway to be active
 *   payment_api_key   -> Paystack PUBLIC key  (pk_test_… / pk_live_…) – used in the browser
 *   payment_api_secret-> Paystack SECRET key  (sk_test_… / sk_live_…) – used server-side only
 *
 * Amounts are handled in the major currency unit (e.g. GHS) in this app, but
 * Paystack works in the minor unit (pesewas/kobo), i.e. amount * 100.
 */

require_once __DIR__ . '/settings_helper.php';

if (!function_exists('getPaystackConfig')) {
    /**
     * @return array {
     *   enabled (bool), public_key (string), secret_key (string),
     *   currency (string), reason (string|null why disabled)
     * }
     */
    function getPaystackConfig() {
        $gateway   = (string)getSchoolSetting('payment_gateway', 'manual');
        $publicKey = trim((string)getSchoolSetting('payment_api_key', ''));
        $secretKey = trim((string)getSchoolSetting('payment_api_secret', ''));
        $currency  = strtoupper((string)getSchoolSetting('currency', 'GHS'));

        // Paystack only settles in these currencies; fall back to GHS otherwise.
        $supported = ['GHS', 'NGN', 'USD', 'ZAR', 'KES'];
        if (!in_array($currency, $supported, true)) {
            $currency = 'GHS';
        }

        $reason = null;
        if ($gateway !== 'paystack') {
            $reason = 'Gateway is not set to Paystack.';
        } elseif ($publicKey === '' || $secretKey === '') {
            $reason = 'Paystack API keys are not configured.';
        }

        return [
            'enabled'    => ($reason === null),
            'public_key' => $publicKey,
            'secret_key' => $secretKey,
            'currency'   => $currency,
            'reason'     => $reason,
        ];
    }
}

if (!function_exists('isPaystackEnabled')) {
    function isPaystackEnabled() {
        $c = getPaystackConfig();
        return !empty($c['enabled']);
    }
}

if (!function_exists('paystackVerifyTransaction')) {
    /**
     * Verify a transaction with Paystack using the secret key.
     *
     * @param string $reference The transaction reference from the checkout callback.
     * @return array { success (bool), message (string), data (array|null) }
     *               On success, data holds Paystack's transaction object
     *               (status, amount in minor units, currency, customer, …).
     */
    function paystackVerifyTransaction($reference) {
        $config = getPaystackConfig();
        if (empty($config['secret_key'])) {
            return ['success' => false, 'message' => 'Paystack is not configured.', 'data' => null];
        }
        // Catch the most common misconfiguration early with a clear message: the
        // Secret Key field must hold the sk_ key, not the pk_ (public) key.
        if (stripos($config['secret_key'], 'sk_') !== 0) {
            error_log('Paystack secret key misconfigured (does not start with sk_).');
            return [
                'success' => false,
                'message' => 'Your Paystack Secret Key is invalid. In Settings → Payment, the "API Secret Key" must be your secret key (it starts with "sk_"), not the public key.',
                'data'    => null,
            ];
        }
        $reference = trim((string)$reference);
        if ($reference === '') {
            return ['success' => false, 'message' => 'Missing transaction reference.', 'data' => null];
        }

        $url = 'https://api.paystack.co/transaction/verify/' . rawurlencode($reference);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $config['secret_key'],
                'Accept: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $result   = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            error_log('Paystack verify connection error: ' . $curlErr);
            return ['success' => false, 'message' => 'Could not reach Paystack. Please try again.', 'data' => null];
        }

        $response = json_decode($result, true);
        if ($httpCode !== 200 || !is_array($response)) {
            $msg = is_array($response) ? ($response['message'] ?? 'HTTP ' . $httpCode) : ('HTTP ' . $httpCode);
            error_log('Paystack verify failed: ' . $msg);
            return ['success' => false, 'message' => 'Verification failed: ' . $msg, 'data' => null];
        }

        $data = $response['data'] ?? null;
        if (empty($response['status']) || !is_array($data)) {
            return ['success' => false, 'message' => $response['message'] ?? 'Transaction not found.', 'data' => null];
        }

        if (($data['status'] ?? '') !== 'success') {
            return [
                'success' => false,
                'message' => 'Payment was not successful (status: ' . ($data['status'] ?? 'unknown') . ').',
                'data'    => $data,
            ];
        }

        return ['success' => true, 'message' => 'Verified.', 'data' => $data];
    }
}
