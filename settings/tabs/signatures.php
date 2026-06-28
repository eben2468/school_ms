<?php
/**
 * Settings tab: Digital Signatures (institutional / school-level signers).
 * Per-staff signatures (e.g. class teachers) are uploaded on the staff profile.
 * Rendered inside settings/school.php; $settings holds the current row.
 */
$sig_slots = [
    'headmaster' => ['label' => 'Headmaster / Headmistress', 'hint' => 'Report cards, transcripts, certificates', 'title_default' => 'Headmaster/Headmistress'],
    'accountant' => ['label' => 'Accountant / Cashier',      'hint' => 'Receipts, invoices, fee statements',     'title_default' => 'Accountant'],
    'hr'         => ['label' => 'HR / Authorizing Officer',  'hint' => 'Payslips',                               'title_default' => 'Human Resource'],
    'registrar'  => ['label' => 'Registrar',                 'hint' => 'Transcripts, certificates',              'title_default' => 'Registrar'],
];
$sig_enabled = (string)($settings['signatures_enabled'] ?? '0') === '1';
?>
<div class="space-y-6">
    <div class="flex items-center justify-between mb-2">
        <div>
            <h2 class="text-2xl font-semibold text-gray-900 dark:text-white">Digital Signatures</h2>
            <p class="text-gray-600 dark:text-gray-400 mt-1">Upload signatures that are auto-embedded on printed documents (report cards, payslips, receipts, transcripts, reports).</p>
        </div>
    </div>

    <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg p-4 text-sm text-amber-800 dark:text-amber-300">
        <i class="fas fa-exclamation-triangle mr-2"></i>
        When enabled, these signatures appear on <strong>every</strong> downloaded copy of the relevant documents. Use a transparent PNG for the cleanest result. Class-teacher signatures are uploaded per staff member on their staff profile.
    </div>

    <form method="POST" enctype="multipart/form-data" class="space-y-8">
        <input type="hidden" name="action" value="update_signatures">

        <!-- Master toggle -->
        <div class="bg-gray-50 dark:bg-gray-900 rounded-lg p-6">
            <label class="flex items-center justify-between cursor-pointer">
                <span>
                    <span class="block text-lg font-medium text-gray-900 dark:text-white">Embed signatures on documents</span>
                    <span class="block text-sm text-gray-500 dark:text-gray-400">Master switch. Turn off to print blank signature lines for wet signing.</span>
                </span>
                <span class="relative inline-flex items-center">
                    <input type="checkbox" name="signatures_enabled" value="1" class="sr-only peer" <?php echo $sig_enabled ? 'checked' : ''; ?>>
                    <span class="w-12 h-7 bg-gray-300 dark:bg-gray-600 rounded-full peer peer-checked:bg-indigo-600 transition-colors"></span>
                    <span class="absolute left-1 top-1 w-5 h-5 bg-white rounded-full transition-transform peer-checked:translate-x-5"></span>
                </span>
            </label>
        </div>

        <!-- Signature slots -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <?php foreach ($sig_slots as $slot => $meta):
                $file = $settings['signature_' . $slot] ?? '';
                $has  = $file && file_exists('../uploads/signatures/' . $file);
            ?>
            <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-5">
                <div class="flex items-start justify-between mb-3">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white"><?php echo $meta['label']; ?></h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo $meta['hint']; ?></p>
                    </div>
                </div>

                <div class="flex items-center gap-4 mb-4">
                    <div class="w-40 h-20 bg-white dark:bg-gray-700 border border-dashed border-gray-300 dark:border-gray-600 rounded-lg flex items-center justify-center overflow-hidden">
                        <?php if ($has): ?>
                            <img src="../serve_image.php?path=signatures/<?php echo rawurlencode($file); ?>" alt="Signature" class="max-h-full max-w-full object-contain">
                        <?php else: ?>
                            <span class="text-xs text-gray-400"><i class="fas fa-signature mr-1"></i>No signature</span>
                        <?php endif; ?>
                    </div>
                    <div class="flex-1">
                        <input type="file" name="signature_<?php echo $slot; ?>" accept="image/png,image/jpeg,image/gif"
                            class="w-full text-sm text-gray-600 dark:text-gray-300 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
                        <p class="text-xs text-gray-400 mt-1">PNG/JPG/GIF, max 1 MB. Transparent PNG recommended.</p>
                        <?php if ($has): ?>
                        <label class="inline-flex items-center mt-2 text-xs text-red-600 cursor-pointer">
                            <input type="checkbox" name="remove_<?php echo $slot; ?>" value="1" class="mr-1.5">Remove current signature
                        </label>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Printed Name</label>
                        <input type="text" name="signature_<?php echo $slot; ?>_name" value="<?php echo htmlspecialchars($settings['signature_' . $slot . '_name'] ?? ''); ?>"
                            placeholder="e.g. Dr. Jane Mensah"
                            class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Title / Caption</label>
                        <input type="text" name="signature_<?php echo $slot; ?>_title" value="<?php echo htmlspecialchars($settings['signature_' . $slot . '_title'] ?? $meta['title_default']); ?>"
                            placeholder="<?php echo htmlspecialchars($meta['title_default']); ?>"
                            class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-3 rounded-lg font-medium shadow-sm transition-colors">
                <i class="fas fa-save mr-2"></i>Save Signature Settings
            </button>
        </div>
    </form>
</div>
