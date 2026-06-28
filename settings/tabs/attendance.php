<!-- Attendance Tab -->
<div class="space-y-6">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-2xl font-semibold text-gray-900 dark:text-white">Attendance Settings</h2>
            <p class="text-gray-600 dark:text-gray-400 mt-1">Configure attendance tracking, grace periods, and reporting settings</p>
        </div>
    </div>

    <form method="POST" class="space-y-8">
        <input type="hidden" name="action" value="update_attendance">

        <!-- Attendance Tracking -->
        <div>
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Attendance Tracking Configuration</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Grace Period -->
                <div>
                    <label for="attendance_grace_period" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Late Arrival Grace Period (minutes)
                    </label>
                    <input type="number" id="attendance_grace_period" name="attendance_grace_period" min="0" max="60"
                        value="<?php echo htmlspecialchars($settings['attendance_grace_period'] ?? '15'); ?>"
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Students arriving within this period are marked as "Late" instead of "Absent"</p>
                </div>

                <!-- Auto Mark Absent -->
                <div>
                    <label for="attendance_auto_absent" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Auto-Mark Absent
                    </label>
                    <select id="attendance_auto_absent" name="attendance_auto_absent"
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                        <option value="enabled" <?php echo ($settings['attendance_auto_absent'] ?? 'enabled') === 'enabled' ? 'selected' : ''; ?>>Enabled</option>
                        <option value="disabled" <?php echo ($settings['attendance_auto_absent'] ?? 'enabled') === 'disabled' ? 'selected' : ''; ?>>Disabled</option>
                    </select>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Automatically mark students as absent if not marked by end of day</p>
                </div>
            </div>
        </div>

        <!-- Attendance Policies -->
        <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-6 border border-blue-200 dark:border-blue-800">
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4 flex items-center">
                <i class="fas fa-info-circle text-blue-600 dark:text-blue-400 mr-2"></i>
                Attendance Policies
            </h3>
            <div class="space-y-3 text-sm text-gray-700 dark:text-gray-300">
                <div class="flex items-start">
                    <i class="fas fa-check-circle text-green-600 dark:text-green-400 mr-2 mt-1"></i>
                    <p><strong>Present:</strong> Student arrived on time and attended all classes</p>
                </div>
                <div class="flex items-start">
                    <i class="fas fa-clock text-yellow-600 dark:text-yellow-400 mr-2 mt-1"></i>
                    <p><strong>Late:</strong> Student arrived within the grace period</p>
                </div>
                <div class="flex items-start">
                    <i class="fas fa-times-circle text-red-600 dark:text-red-400 mr-2 mt-1"></i>
                    <p><strong>Absent:</strong> Student did not attend or arrived after grace period</p>
                </div>
                <div class="flex items-start">
                    <i class="fas fa-file-medical text-blue-600 dark:text-blue-400 mr-2 mt-1"></i>
                    <p><strong>Excused:</strong> Absence with valid reason (medical, family emergency, etc.)</p>
                </div>
            </div>
        </div>

        <!-- Reporting Settings -->
        <div>
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Reporting & Notifications</h3>
            <div class="space-y-4">
                <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-900 rounded-lg">
                    <div>
                        <p class="font-medium text-gray-900 dark:text-white">Daily Attendance Reports</p>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Generate and send daily attendance summaries to administrators</p>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="attendance_daily_reports" value="enabled" class="sr-only peer" <?php echo ($settings['attendance_daily_reports'] ?? 'enabled') === 'enabled' ? 'checked' : ''; ?>>
                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 dark:peer-focus:ring-blue-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-blue-600"></div>
                    </label>
                </div>

                <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-900 rounded-lg">
                    <div>
                        <p class="font-medium text-gray-900 dark:text-white">Parent Notifications</p>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Notify parents when their child is marked absent</p>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="attendance_parent_notifications" value="enabled" class="sr-only peer" <?php echo ($settings['attendance_parent_notifications'] ?? 'enabled') === 'enabled' ? 'checked' : ''; ?>>
                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 dark:peer-focus:ring-blue-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-blue-600"></div>
                    </label>
                </div>

                <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-900 rounded-lg">
                    <div>
                        <p class="font-medium text-gray-900 dark:text-white">Weekly Attendance Summary</p>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Send weekly attendance reports to class teachers</p>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="attendance_weekly_summary" value="enabled" class="sr-only peer" <?php echo ($settings['attendance_weekly_summary'] ?? 'disabled') === 'enabled' ? 'checked' : ''; ?>>
                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 dark:peer-focus:ring-blue-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-blue-600"></div>
                    </label>
                </div>
            </div>
        </div>

        <!-- Quick Links -->
        <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-6 border border-green-200 dark:border-green-800">
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Attendance Management</h3>
            <p class="text-gray-600 dark:text-gray-400 mb-4">Access attendance tracking and reporting tools</p>
            <div class="flex flex-wrap gap-3">
                <a href="../attendance/take.php" class="inline-flex items-center px-4 py-2 bg-green-600 hover:bg-green-700 text-white font-medium rounded-lg transition-colors duration-200">
                    <i class="fas fa-clipboard-check mr-2"></i>
                    Take Attendance
                </a>
                <a href="../attendance/reports.php" class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors duration-200">
                    <i class="fas fa-chart-bar mr-2"></i>
                    View Reports
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
