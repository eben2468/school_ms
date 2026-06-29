<?php
/**
 * Reusable Paystack inline-checkout component.
 * --------------------------------------------------------------------------
 * Include this once on any page that needs to take a Paystack payment. It emits
 * nothing when Paystack is not the active gateway, so it is always safe to add.
 *
 * Exposes two global JS helpers:
 *
 *   openPaystackPayment({ invoiceId, amount, label, email, onSuccess })
 *       Opens a small modal so the payer can confirm/edit the AMOUNT before
 *       paying (used by students & parents). `amount` pre-fills the field.
 *
 *   payWithPaystack({ invoiceId, amount, label, email, onSuccess })
 *       Charges immediately for the given amount, no modal (used where the
 *       amount has already been entered, e.g. the staff collect-payment form).
 *
 * Both verify server-side via finance/paystack/verify.php (which records the
 * payment). The CSRF token is attached automatically by footer.php's fetch
 * wrapper.
 */

require_once __DIR__ . '/paystack_helper.php';
$__ps = getPaystackConfig();
if (empty($__ps['enabled'])) {
    return; // not configured / not the active gateway — render nothing
}
$__psEmail = $_SESSION['email'] ?? '';
?>
<script src="https://js.paystack.co/v1/inline.js"></script>

<!-- Amount confirmation modal -->
<div id="paystackAmountModal" class="fixed inset-0 z-50 hidden" style="background: rgba(0,0,0,0.5);">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-md">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex items-center">
                <i class="fas fa-credit-card text-green-600 dark:text-green-400 mr-2"></i>
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Pay with Paystack</h3>
            </div>
            <div class="p-6 space-y-4">
                <p id="paystackModalLabel" class="text-sm text-gray-600 dark:text-gray-400"></p>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Amount to pay (<?php echo htmlspecialchars($__ps['currency']); ?>)</label>
                    <input type="number" id="paystackAmountInput" step="0.01" min="0.01"
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white text-lg font-semibold">
                    <p id="paystackBalanceHint" class="text-xs text-gray-500 dark:text-gray-400 mt-1"></p>
                </div>
            </div>
            <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 flex justify-end space-x-3">
                <button type="button" onclick="closePaystackModal()" class="px-4 py-2 text-gray-600 dark:text-gray-300 hover:text-gray-800 dark:hover:text-white">Cancel</button>
                <button type="button" onclick="paystackProceed()" class="px-5 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg font-semibold inline-flex items-center">
                    <i class="fas fa-lock mr-2"></i> Proceed to Pay
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var DEFAULT_EMAIL = <?php echo json_encode($__psEmail); ?>;
    var PUBLIC_KEY = <?php echo json_encode($__ps['public_key']); ?>;
    var CURRENCY = <?php echo json_encode($__ps['currency']); ?>;
    var pending = null; // opts captured while the amount modal is open

    function charge(opts) {
        var email = (opts.email && String(opts.email).trim()) ? String(opts.email).trim() : DEFAULT_EMAIL;
        if (!email) {
            email = window.prompt('Enter your email address (your receipt will be sent here):');
            if (!email) { return; }
        }
        var amount = Math.round(parseFloat(opts.amount) * 100); // major -> minor unit
        if (!amount || amount <= 0) { alert('Please enter a valid amount.'); return; }

        var handler = PaystackPop.setup({
            key: PUBLIC_KEY,
            email: email,
            amount: amount,
            currency: CURRENCY,
            metadata: {
                invoice_id: opts.invoiceId,
                custom_fields: [
                    { display_name: 'Invoice', variable_name: 'invoice_id', value: String(opts.invoiceId || '') },
                    { display_name: 'For', variable_name: 'description', value: String(opts.label || 'School payment') }
                ]
            },
            callback: function (response) {
                fetch('/school_ms/finance/paystack/verify.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ reference: response.reference, invoice_id: opts.invoiceId })
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.success) {
                        if (typeof opts.onSuccess === 'function') { opts.onSuccess(data); }
                        else {
                            alert('Payment successful!' + (data.receipt_number ? ('\nReceipt: ' + data.receipt_number) : ''));
                            window.location.reload();
                        }
                    } else {
                        alert('We could not confirm your payment: ' + ((data && data.message) || 'Please contact the school.') +
                              '\nReference: ' + response.reference);
                    }
                })
                .catch(function () {
                    alert('Your payment went through but we could not confirm it automatically.\n' +
                          'Please contact the school with reference: ' + response.reference);
                });
            },
            onClose: function () { /* user closed the popup */ }
        });
        handler.openIframe();
    }

    // Immediate charge (amount already known).
    window.payWithPaystack = function (opts) { charge(opts || {}); };

    // Open the amount modal first, then charge on confirm.
    window.openPaystackPayment = function (opts) {
        opts = opts || {};
        pending = opts;
        var input = document.getElementById('paystackAmountInput');
        var label = document.getElementById('paystackModalLabel');
        var hint = document.getElementById('paystackBalanceHint');
        var amt = parseFloat(opts.amount);
        input.value = (amt && amt > 0) ? amt.toFixed(2) : '';
        label.textContent = opts.label ? ('Payment for: ' + opts.label) : 'Enter the amount you want to pay.';
        hint.textContent = (amt && amt > 0) ? ('Outstanding balance: ' + CURRENCY + ' ' + amt.toFixed(2) + '. You can pay part or all of it.') : '';
        document.getElementById('paystackAmountModal').classList.remove('hidden');
        setTimeout(function () { input.focus(); input.select(); }, 50);
    };

    window.closePaystackModal = function () {
        document.getElementById('paystackAmountModal').classList.add('hidden');
        pending = null;
    };

    window.paystackProceed = function () {
        if (!pending) { return; }
        var val = parseFloat(document.getElementById('paystackAmountInput').value);
        if (!val || val <= 0) { alert('Please enter a valid amount greater than 0.'); return; }
        var opts = pending;
        opts.amount = val;
        document.getElementById('paystackAmountModal').classList.add('hidden');
        pending = null;
        charge(opts);
    };
})();
</script>
