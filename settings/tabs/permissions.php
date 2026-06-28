<!-- Permissions Tab -->
<div class="space-y-6">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-2xl font-semibold text-gray-900 dark:text-white">Permissions & Access Control</h2>
            <p class="text-gray-600 dark:text-gray-400 mt-1">Manage role-based access controls and portal restrictions</p>
        </div>
    </div>

    <form method="POST" class="space-y-8">
        <input type="hidden" name="action" value="update_permissions">

        <!-- Portal Access Control -->
        <div>
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Portal Access Settings</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Parent Portal -->
                <div>
                    <label for="parent_portal" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Parent Portal Access
                    </label>
                    <select id="parent_portal" name="parent_portal"
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                        <option value="enabled" <?php echo $settings['parent_portal'] === 'enabled' ? 'selected' : ''; ?>>Enabled</option>
                        <option value="disabled" <?php echo $settings['parent_portal'] === 'disabled' ? 'selected' : ''; ?>>Disabled</option>
                        <option value="restricted" <?php echo $settings['parent_portal'] === 'restricted' ? 'selected' : ''; ?>>Restricted (Approval Required)</option>
                    </select>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Control parent access to student information and reports</p>
                </div>

                <!-- Student Portal -->
                <div>
                    <label for="student_portal" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Student Portal Access
                    </label>
                    <select id="student_portal" name="student_portal"
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                        <option value="enabled" <?php echo $settings['student_portal'] === 'enabled' ? 'selected' : ''; ?>>Enabled</option>
                        <option value="disabled" <?php echo $settings['student_portal'] === 'disabled' ? 'selected' : ''; ?>>Disabled</option>
                        <option value="restricted" <?php echo $settings['student_portal'] === 'restricted' ? 'selected' : ''; ?>>Restricted (Limited Access)</option>
                    </select>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Control student access to grades, assignments, and resources</p>
                </div>
            </div>
        </div>

        <!-- User Roles Overview -->
        <div>
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">System Roles & Permissions</h3>
            <div class="space-y-3">

                <!-- School Admin -->
                <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4 border border-blue-200 dark:border-blue-800">
                    <div class="flex items-center justify-between mb-2">
                        <h4 class="font-semibold text-gray-900 dark:text-white flex items-center">
                            <i class="fas fa-user-shield text-blue-600 dark:text-blue-400 mr-2"></i>
                            School Admin
                        </h4>
                        <span class="px-3 py-1 text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200 rounded-full">
                            Administrative
                        </span>
                    </div>
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        Manage school operations, users, academic settings, and system configuration
                    </p>
                </div>

                <!-- Principal -->
                <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-4 border border-green-200 dark:border-green-800">
                    <div class="flex items-center justify-between mb-2">
                        <h4 class="font-semibold text-gray-900 dark:text-white flex items-center">
                            <i class="fas fa-user-tie text-green-600 dark:text-green-400 mr-2"></i>
                            Headmaster/Headmistress
                        </h4>
                        <span class="px-3 py-1 text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200 rounded-full">
                            Management
                        </span>
                    </div>
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        View reports, approve activities, manage academic calendar, and oversee school operations
                    </p>
                </div>

                <!-- Teacher -->
                <div class="bg-yellow-50 dark:bg-yellow-900/20 rounded-lg p-4 border border-yellow-200 dark:border-yellow-800">
                    <div class="flex items-center justify-between mb-2">
                        <h4 class="font-semibold text-gray-900 dark:text-white flex items-center">
                            <i class="fas fa-chalkboard-teacher text-yellow-600 dark:text-yellow-400 mr-2"></i>
                            Teacher
                        </h4>
                        <span class="px-3 py-1 text-xs font-medium bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200 rounded-full">
                            Academic
                        </span>
                    </div>
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        Mark attendance, upload grades, create assignments, manage classes, and communicate with students/parents
                    </p>
                </div>

                <!-- Student -->
                <div class="bg-indigo-50 dark:bg-indigo-900/20 rounded-lg p-4 border border-indigo-200 dark:border-indigo-800">
                    <div class="flex items-center justify-between mb-2">
                        <h4 class="font-semibold text-gray-900 dark:text-white flex items-center">
                            <i class="fas fa-user-graduate text-indigo-600 dark:text-indigo-400 mr-2"></i>
                            Student
                        </h4>
                        <span class="px-3 py-1 text-xs font-medium bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200 rounded-full">
                            Limited
                        </span>
                    </div>
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        View grades, submit assignments, access learning materials, and check attendance records
                    </p>
                </div>

                <!-- Parent -->
                <div class="bg-pink-50 dark:bg-pink-900/20 rounded-lg p-4 border border-pink-200 dark:border-pink-800">
                    <div class="flex items-center justify-between mb-2">
                        <h4 class="font-semibold text-gray-900 dark:text-white flex items-center">
                            <i class="fas fa-users text-pink-600 dark:text-pink-400 mr-2"></i>
                            Parent
                        </h4>
                        <span class="px-3 py-1 text-xs font-medium bg-pink-100 text-pink-800 dark:bg-pink-900 dark:text-pink-200 rounded-full">
                            View Only
                        </span>
                    </div>
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        Monitor child's academic progress, attendance, grades, and communicate with teachers
                    </p>
                </div>
            </div>
        </div>

        <!-- Module Access Control (For Super Admin) -->
        <?php if ($user_role === 'super_admin'): ?>
        <div>
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Module Access Control</h3>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                Control which modules are accessible to schools based on their subscription plan
            </p>
            <div class="bg-orange-50 dark:bg-orange-900/20 rounded-lg p-6 border border-orange-200 dark:border-orange-800">
                <div class="flex items-start">
                    <i class="fas fa-info-circle text-orange-600 dark:text-orange-400 mr-3 mt-1"></i>
                    <div>
                        <h4 class="font-semibold text-gray-900 dark:text-white mb-2">Advanced Feature</h4>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">
                            Module-level permissions are managed through subscription plans in the Super Admin panel.
                        </p>
                        <a href="super_admin.php?tab=plans" class="inline-flex items-center px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white font-medium rounded-lg transition-colors duration-200">
                            <i class="fas fa-external-link-alt mr-2"></i>
                            Manage Subscription Plans
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Quick Links -->
        <div class="bg-gray-50 dark:bg-gray-900 rounded-lg p-6 border border-gray-200 dark:border-gray-700">
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">User Management</h3>
            <p class="text-gray-600 dark:text-gray-400 mb-4">Manage users and assign roles</p>
            <a href="../users/" class="inline-flex items-center px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white font-medium rounded-lg transition-colors duration-200">
                <i class="fas fa-users-cog mr-2"></i>
                Manage Users
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
