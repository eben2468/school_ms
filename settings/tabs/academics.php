<!-- Academics Tab -->
<div class="space-y-6">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-2xl font-semibold text-gray-900 dark:text-white">Academic Settings</h2>
            <p class="text-gray-600 dark:text-gray-400 mt-1">Configure academic year, terms, grading systems, and curriculum settings</p>
        </div>
    </div>

    <!-- Current Academic Status -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        <!-- Current Academic Year -->
        <div class="bg-gradient-to-br from-blue-50 to-indigo-50 dark:from-blue-900/20 dark:to-indigo-900/20 rounded-xl p-6 border border-blue-200 dark:border-blue-800">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Current Academic Year</h3>
                <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                    <i class="fas fa-calendar-alt text-blue-600 dark:text-blue-400"></i>
                </div>
            </div>
            <?php if ($current_year): ?>
            <div class="space-y-2">
                <p class="text-2xl font-bold text-gray-900 dark:text-white"><?php echo htmlspecialchars($current_year['year_name']); ?></p>
                <p class="text-gray-600 dark:text-gray-400">
                    <?php echo date('M j, Y', strtotime($current_year['start_date'])); ?> -
                    <?php echo date('M j, Y', strtotime($current_year['end_date'])); ?>
                </p>
                <span class="inline-flex px-3 py-1 text-xs font-medium rounded-full
                    <?php echo $current_year['status'] === 'active' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200'; ?>">
                    <?php echo ucfirst($current_year['status']); ?>
                </span>
            </div>
            <?php else: ?>
            <p class="text-gray-500 dark:text-gray-400">No academic year set</p>
            <a href="../academic/settings/" class="inline-flex items-center mt-4 text-blue-600 hover:text-blue-700 dark:text-blue-400">
                <i class="fas fa-plus-circle mr-2"></i>
                Create Academic Year
            </a>
            <?php endif; ?>
        </div>

        <!-- Current Term -->
        <div class="bg-gradient-to-br from-green-50 to-emerald-50 dark:from-green-900/20 dark:to-emerald-900/20 rounded-xl p-6 border border-green-200 dark:border-green-800">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Current Term</h3>
                <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                    <i class="fas fa-clock text-green-600 dark:text-green-400"></i>
                </div>
            </div>
            <?php if ($current_term): ?>
            <div class="space-y-2">
                <p class="text-2xl font-bold text-gray-900 dark:text-white"><?php echo htmlspecialchars($current_term['term_name']); ?></p>
                <p class="text-gray-600 dark:text-gray-400">
                    <?php echo date('M j, Y', strtotime($current_term['start_date'])); ?> -
                    <?php echo date('M j, Y', strtotime($current_term['end_date'])); ?>
                </p>
                <span class="inline-flex px-3 py-1 text-xs font-medium rounded-full
                    <?php echo $current_term['status'] === 'active' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200'; ?>">
                    <?php echo ucfirst($current_term['status']); ?>
                </span>
            </div>
            <?php else: ?>
            <p class="text-gray-500 dark:text-gray-400">No term set</p>
            <?php endif; ?>
        </div>
    </div>

    <form method="POST" class="space-y-8">
        <input type="hidden" name="action" value="update_academics">

        <!-- Academic Year Configuration -->
        <div>
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Academic Year Configuration</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Academic Year Start -->
                <div>
                    <label for="academic_year_start" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Academic Year Start Date
                    </label>
                    <input type="date" id="academic_year_start" name="academic_year_start"
                        value="<?php echo htmlspecialchars($settings['academic_year_start']); ?>"
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                </div>

                <!-- Academic Year End -->
                <div>
                    <label for="academic_year_end" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Academic Year End Date
                    </label>
                    <input type="date" id="academic_year_end" name="academic_year_end"
                        value="<?php echo htmlspecialchars($settings['academic_year_end']); ?>"
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                </div>

                <!-- Terms per Year -->
                <div>
                    <label for="terms_per_year" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Terms per Academic Year
                    </label>
                    <select id="terms_per_year" name="terms_per_year"
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                        <option value="2" <?php echo ($settings['terms_per_year'] ?? '3') === '2' ? 'selected' : ''; ?>>2 Terms (Semester System)</option>
                        <option value="3" <?php echo ($settings['terms_per_year'] ?? '3') === '3' ? 'selected' : ''; ?>>3 Terms (Trimester System)</option>
                        <option value="4" <?php echo ($settings['terms_per_year'] ?? '3') === '4' ? 'selected' : ''; ?>>4 Terms (Quarter System)</option>
                    </select>
                </div>

                <!-- Grading System -->
                <div>
                    <label for="grading_system" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Grading System
                    </label>
                    <?php $current_grading_system = function_exists('getGradingSystem') ? getGradingSystem() : ($settings['grading_system'] ?? 'percentage'); ?>
                    <select id="grading_system" name="grading_system"
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                        <?php foreach ((function_exists('getGradingSystems') ? getGradingSystems() : [
                            'percentage' => 'Percentage (0-100%)',
                            'letter'     => 'Letter Grades (A-F)',
                            'gpa'        => 'GPA (4.0 Scale)',
                            'points'     => 'Points (1-10)',
                        ]) as $gs_value => $gs_label): ?>
                        <option value="<?php echo htmlspecialchars($gs_value); ?>" <?php echo $current_grading_system === $gs_value ? 'selected' : ''; ?>><?php echo htmlspecialchars($gs_label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Controls how grades are displayed across grades, report cards, transcripts and student/parent views. Mark ranges for letter grades and GPA are configured under <a href="../academic/reports/grading_key.php" class="text-indigo-600 dark:text-indigo-400 hover:underline">Grading Key</a>.</p>
                </div>
            </div>
        </div>

        <!-- Report Card Branding -->
        <div>
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Report Card & Transcript Branding</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- School Motto -->
                <div>
                    <label for="school_motto" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        School Motto
                    </label>
                    <input type="text" id="school_motto" name="school_motto"
                        value="<?php echo htmlspecialchars($academic_settings['school_motto'] ?? 'Excellence in Character and Knowledge'); ?>"
                        placeholder="Enter school motto"
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Displayed on report cards and official documents</p>
                </div>

                <!-- Postal Address -->
                <div>
                    <label for="school_postal" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Postal Address
                    </label>
                    <input type="text" id="school_postal" name="school_postal"
                        value="<?php echo htmlspecialchars($academic_settings['school_postal'] ?? 'P.O. Box GP 1234, Accra'); ?>"
                        placeholder="P.O. Box..."
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Postal address for official correspondence</p>
                </div>
            </div>
        </div>

        <!-- Quick Links -->
        <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-6 border border-blue-200 dark:border-blue-800">
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Advanced Academic Management</h3>
            <p class="text-gray-600 dark:text-gray-400 mb-4">For detailed academic year and term management, visit the Academic Settings page.</p>
            <a href="../academic/settings/" class="inline-flex items-center px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors duration-200">
                <i class="fas fa-external-link-alt mr-2"></i>
                Go to Academic Settings
            </a>
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
