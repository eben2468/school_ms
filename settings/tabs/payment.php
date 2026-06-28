<!-- Payment Tab -->
<style>
    /* Self-contained toggle switch (works without Tailwind 3 peer/arbitrary classes) */
    .ui-switch {
        position: relative;
        display: inline-block;
        width: 46px;
        height: 26px;
        flex-shrink: 0;
    }
    .ui-switch input { position: absolute; opacity: 0; width: 0; height: 0; }
    .ui-switch .ui-switch-slider {
        position: absolute;
        inset: 0;
        cursor: pointer;
        background-color: #cbd5e1;
        border-radius: 9999px;
        transition: background-color .25s ease;
    }
    .ui-switch .ui-switch-slider::before {
        content: "";
        position: absolute;
        height: 20px;
        width: 20px;
        left: 3px;
        top: 3px;
        background-color: #ffffff;
        border-radius: 50%;
        box-shadow: 0 1px 3px rgba(0,0,0,.25);
        transition: transform .25s ease;
    }
    .ui-switch input:checked + .ui-switch-slider { background-color: #2563eb; }
    .ui-switch input:checked + .ui-switch-slider::before { transform: translateX(20px); }
    .ui-switch input:focus-visible + .ui-switch-slider { box-shadow: 0 0 0 3px rgba(37,99,235,.35); }
    .dark .ui-switch .ui-switch-slider { background-color: #4b5563; }
    .dark .ui-switch input:checked + .ui-switch-slider { background-color: #3b82f6; }
</style>
<div class="space-y-6">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-2xl font-semibold text-gray-900 dark:text-white">Payment Settings</h2>
            <p class="text-gray-600 dark:text-gray-400 mt-1">Configure currency, payment gateway, and fee structure settings</p>
        </div>
    </div>

    <form method="POST" class="space-y-8">
        <input type="hidden" name="action" value="update_payment">

        <!-- Currency Settings -->
        <div>
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Currency Configuration</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Currency -->
                <div>
                    <label for="currency" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Default Currency
                    </label>
                    <select id="currency" name="currency"
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                        <option value="GHS" <?php echo $settings['currency'] === 'GHS' ? 'selected' : ''; ?>>Ghana Cedi (₵)</option>
                        <option value="USD" <?php echo $settings['currency'] === 'USD' ? 'selected' : ''; ?>>US Dollar ($)</option>
                        <option value="EUR" <?php echo $settings['currency'] === 'EUR' ? 'selected' : ''; ?>>Euro (€)</option>
                        <option value="GBP" <?php echo $settings['currency'] === 'GBP' ? 'selected' : ''; ?>>British Pound (£)</option>
                        <option value="NGN" <?php echo $settings['currency'] === 'NGN' ? 'selected' : ''; ?>>Nigerian Naira (₦)</option>
                        <option value="KES" <?php echo $settings['currency'] === 'KES' ? 'selected' : ''; ?>>Kenyan Shilling (KSh)</option>
                        <option value="ZAR" <?php echo $settings['currency'] === 'ZAR' ? 'selected' : ''; ?>>South African Rand (R)</option>
                    </select>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Currency symbol will be automatically set</p>
                </div>

                <!-- Currency Symbol Display -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Currency Symbol
                    </label>
                    <div class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-white">
                        <?php 
                        $currency_symbols = [
                            'GHS' => '₵', 'USD' => '$', 'EUR' => '€', 'GBP' => '£',
                            'NGN' => '₦', 'KES' => 'KSh', 'ZAR' => 'R'
                        ];
                        echo $currency_symbols[$settings['currency']] ?? $settings['currency'];
                        ?>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Auto-generated based on currency</p>
                </div>
            </div>
        </div>

        <!-- Payment Gateway Configuration -->
        <div>
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Payment Gateway Integration</h3>
            <div class="space-y-6">
                <!-- Gateway Selection -->
                <div>
                    <label for="payment_gateway" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Payment Gateway
                    </label>
                    <select id="payment_gateway" name="payment_gateway"
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                        <option value="manual" <?php echo ($settings['payment_gateway'] ?? 'manual') === 'manual' ? 'selected' : ''; ?>>Manual Payment (Cash/Bank Transfer)</option>
                        <option value="paystack" <?php echo ($settings['payment_gateway'] ?? 'manual') === 'paystack' ? 'selected' : ''; ?>>Paystack</option>
                        <option value="flutterwave" <?php echo ($settings['payment_gateway'] ?? 'manual') === 'flutterwave' ? 'selected' : ''; ?>>Flutterwave</option>
                        <option value="stripe" <?php echo ($settings['payment_gateway'] ?? 'manual') === 'stripe' ? 'selected' : ''; ?>>Stripe</option>
                        <option value="paypal" <?php echo ($settings['payment_gateway'] ?? 'manual') === 'paypal' ? 'selected' : ''; ?>>PayPal</option>
                    </select>
                </div>

                <!-- API Credentials -->
                <div id="gateway-credentials" class="space-y-4">
                    <div>
                        <label for="payment_api_key" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            API Public Key
                        </label>
                        <input type="text" id="payment_api_key" name="payment_api_key"
                            value="<?php echo htmlspecialchars($settings['payment_api_key'] ?? ''); ?>"
                            placeholder="Enter your payment gateway public key"
                            class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                    </div>

                    <div>
                        <label for="payment_api_secret" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            API Secret Key
                        </label>
                        <input type="password" id="payment_api_secret" name="payment_api_secret"
                            value="<?php echo htmlspecialchars($settings['payment_api_secret'] ?? ''); ?>"
                            placeholder="Enter your payment gateway secret key"
                            class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Keep this secure and never share it publicly</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Payment Gateway Information -->
        <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-6 border border-blue-200 dark:border-blue-800">
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4 flex items-center">
                <i class="fas fa-info-circle text-blue-600 dark:text-blue-400 mr-2"></i>
                Payment Gateway Setup Guide
            </h3>
            <div class="space-y-3 text-sm text-gray-700 dark:text-gray-300">
                <div class="flex items-start">
                    <i class="fas fa-check-circle text-green-600 dark:text-green-400 mr-2 mt-1"></i>
                    <p><strong>Paystack:</strong> Get your API keys from <a href="https://dashboard.paystack.com/#/settings/developer" target="_blank" class="text-blue-600 hover:underline">Paystack Dashboard</a></p>
                </div>
                <div class="flex items-start">
                    <i class="fas fa-check-circle text-green-600 dark:text-green-400 mr-2 mt-1"></i>
                    <p><strong>Flutterwave:</strong> Get your API keys from <a href="https://dashboard.flutterwave.com/settings/apis" target="_blank" class="text-blue-600 hover:underline">Flutterwave Dashboard</a></p>
                </div>
                <div class="flex items-start">
                    <i class="fas fa-check-circle text-green-600 dark:text-green-400 mr-2 mt-1"></i>
                    <p><strong>Stripe:</strong> Get your API keys from <a href="https://dashboard.stripe.com/apikeys" target="_blank" class="text-blue-600 hover:underline">Stripe Dashboard</a></p>
                </div>
                <div class="flex items-start">
                    <i class="fas fa-exclamation-triangle text-yellow-600 dark:text-yellow-400 mr-2 mt-1"></i>
                    <p><strong>Security:</strong> Always use test keys during development and switch to live keys only in production</p>
                </div>
            </div>
        </div>

        <!-- Payment Methods -->
        <div>
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Accepted Payment Methods</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Disabled methods are hidden from the payment forms used by staff and parents.</p>
            <div class="space-y-3">
                <?php
                $payment_method_options = [
                    'cash'   => ['Cash Payment', 'Accept cash payments at school office', 'fas fa-money-bill-wave', 'text-green-600 dark:text-green-400'],
                    'bank'   => ['Bank Transfer', 'Direct bank transfers to school account', 'fas fa-university', 'text-blue-600 dark:text-blue-400'],
                    'card'   => ['Card Payment', 'Credit/Debit card payments via gateway', 'fas fa-credit-card', 'text-purple-600 dark:text-purple-400'],
                    'mobile' => ['Mobile Money', 'MTN, Vodafone, AirtelTigo mobile money', 'fas fa-mobile-alt', 'text-orange-600 dark:text-orange-400'],
                ];
                foreach ($payment_method_options as $pm_key => $pm):
                    $pm_enabled = ((string)($settings['pay_method_' . $pm_key] ?? '1') === '1');
                ?>
                <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-900 rounded-lg">
                    <div class="flex items-center">
                        <i class="<?php echo $pm[2]; ?> <?php echo $pm[3]; ?> mr-3 text-xl"></i>
                        <div>
                            <p class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($pm[0]); ?></p>
                            <p class="text-sm text-gray-600 dark:text-gray-400"><?php echo htmlspecialchars($pm[1]); ?></p>
                        </div>
                    </div>
                    <label class="ui-switch">
                        <!-- Hidden field guarantees a value is posted when the toggle is off -->
                        <input type="hidden" name="pay_method_<?php echo $pm_key; ?>" value="0">
                        <input type="checkbox" name="pay_method_<?php echo $pm_key; ?>" value="1" <?php echo $pm_enabled ? 'checked' : ''; ?>>
                        <span class="ui-switch-slider"></span>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Quick Links -->
        <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-6 border border-green-200 dark:border-green-800">
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Fee Management</h3>
            <p class="text-gray-600 dark:text-gray-400 mb-4">Manage fee structures, payments, and invoices</p>
            <div class="flex flex-wrap gap-3">
                <a href="../finance/fee_structures.php" class="inline-flex items-center px-4 py-2 bg-green-600 hover:bg-green-700 text-white font-medium rounded-lg transition-colors duration-200">
                    <i class="fas fa-money-check-alt mr-2"></i>
                    Fee Structures
                </a>
                <a href="../finance/payments.php" class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors duration-200">
                    <i class="fas fa-receipt mr-2"></i>
                    Payment Records
                </a>
            </div>
        </div>

        <!-- Submit Button -->
        <div class="flex justify-end pt-6 border-t border-gray-200 dark:border-gray-700">
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-3 px-8 rounded-lg transition-colors duration-200 flex items-center">
                <i class="fas fa-save mr-2"></i>
                Save Changes
            </button>
        </div>
    </form>
</div>

<script>
// Show/hide API credentials based on gateway selection
document.getElementById('payment_gateway').addEventListener('change', function() {
    const credentialsDiv = document.getElementById('gateway-credentials');
    if (this.value === 'manual') {
        credentialsDiv.style.display = 'none';
    } else {
        credentialsDiv.style.display = 'block';
    }
});

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    const gateway = document.getElementById('payment_gateway').value;
    const credentialsDiv = document.getElementById('gateway-credentials');
    if (gateway === 'manual') {
        credentialsDiv.style.display = 'none';
    }
});
</script>
