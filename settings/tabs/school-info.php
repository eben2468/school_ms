<!-- School Profile Tab -->
<div class="space-y-6">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-2xl font-semibold text-gray-900 dark:text-white">School Profile</h2>
            <p class="text-gray-600 dark:text-gray-400 mt-1">Update your school's basic information and contact details</p>
        </div>
    </div>

    <form method="POST" enctype="multipart/form-data" class="space-y-8">
        <input type="hidden" name="action" value="update_school_info">

        <!-- School Logo -->
        <div class="bg-gray-50 dark:bg-gray-900 rounded-lg p-6">
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">School Logo</h3>
            <div class="flex items-center space-x-6">
                <?php if (!empty($settings['school_logo']) && file_exists('../uploads/logos/' . $settings['school_logo'])): ?>
                    <div class="flex flex-col items-center">
                        <span class="text-xs text-gray-500 dark:text-gray-400 mb-2">Current Logo</span>
                        <img src="../uploads/logos/<?php echo htmlspecialchars($settings['school_logo']); ?>" 
                             alt="School Logo" 
                             class="w-32 h-32 object-contain rounded-lg border-2 border-gray-200 dark:border-gray-700 bg-white p-2">
                    </div>
                <?php else: ?>
                    <div class="w-32 h-32 bg-gray-200 dark:bg-gray-700 rounded-lg flex items-center justify-center">
                        <i class="fas fa-school text-4xl text-gray-400"></i>
                    </div>
                <?php endif; ?>
                
                <div class="flex-1">
                    <label for="school_logo" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Upload New Logo
                    </label>
                    <input type="file" id="school_logo" name="school_logo" accept="image/*"
                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">
                        Accepted formats: PNG, JPG, JPEG, GIF, SVG. Maximum size: 2MB
                    </p>
                </div>
            </div>
        </div>

        <!-- Basic Information -->
        <div>
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Basic Information</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- School Name -->
                <div class="md:col-span-2">
                    <label for="school_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        School Name <span class="text-red-500">*</span>
                    </label>
                    <input type="text" id="school_name" name="school_name" required
                        value="<?php echo htmlspecialchars($settings['school_name']); ?>"
                        placeholder="Enter school name"
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                </div>

                <!-- School Phone -->
                <div>
                    <label for="school_phone" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Phone Number <span class="text-red-500">*</span>
                    </label>
                    <input type="tel" id="school_phone" name="school_phone" required
                        value="<?php echo htmlspecialchars($settings['school_phone']); ?>"
                        placeholder="+233 XX XXX XXXX"
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                </div>

                <!-- School Email -->
                <div>
                    <label for="school_email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Email Address <span class="text-red-500">*</span>
                    </label>
                    <input type="email" id="school_email" name="school_email" required
                        value="<?php echo htmlspecialchars($settings['school_email']); ?>"
                        placeholder="school@example.com"
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                </div>

                <!-- School Website -->
                <div>
                    <label for="school_website" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Website URL
                    </label>
                    <input type="url" id="school_website" name="school_website"
                        value="<?php echo htmlspecialchars($settings['school_website']); ?>"
                        placeholder="https://www.school.com"
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                </div>

                <!-- Principal Name -->
                <div>
                    <label for="principal_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Headmaster/Headmistress Name
                    </label>
                    <input type="text" id="principal_name" name="principal_name"
                        value="<?php echo htmlspecialchars($settings['principal_name']); ?>"
                        placeholder="Enter headmaster/headmistress's name"
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                </div>
            </div>

            <!-- School Address -->
            <div class="mt-6">
                <label for="school_address" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    School Address <span class="text-red-500">*</span>
                </label>
                <textarea id="school_address" name="school_address" rows="3" required
                    placeholder="Enter complete school address"
                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white"><?php echo htmlspecialchars($settings['school_address']); ?></textarea>
            </div>

            <!-- Footer Branding -->
            <div class="mt-8 pt-6 border-t border-gray-200 dark:border-gray-700">
                <div class="flex items-center mb-1">
                    <i class="fas fa-shoe-prints text-indigo-500 mr-2"></i>
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-white">Footer Branding</h3>
                </div>
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-5">The tagline and description shown beside your school name in the website footer.</p>

                <div class="space-y-4">
                    <div>
                        <label for="footer_tagline" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Tagline</label>
                        <input type="text" id="footer_tagline" name="footer_tagline" maxlength="150"
                            value="<?php echo htmlspecialchars($settings['footer_tagline'] ?? ''); ?>"
                            placeholder="e.g. Excellence in Education"
                            class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                    </div>
                    <div>
                        <label for="footer_description" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Footer Description</label>
                        <textarea id="footer_description" name="footer_description" rows="3" maxlength="500"
                            placeholder="A short sentence describing your school"
                            class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white"><?php echo htmlspecialchars($settings['footer_description'] ?? ''); ?></textarea>
                    </div>
                    <div>
                        <label for="office_hours" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            <i class="fas fa-clock text-indigo-500 mr-1"></i> Office Hours
                        </label>
                        <input type="text" id="office_hours" name="office_hours" maxlength="255"
                            value="<?php echo htmlspecialchars($settings['office_hours'] ?? ''); ?>"
                            placeholder="e.g. Mon - Fri: 8:00 AM - 5:00 PM"
                            class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Shown beside the clock icon in the website footer's contact section.</p>
                    </div>
                </div>
            </div>

            <!-- Social Media Links -->
            <div class="mt-8 pt-6 border-t border-gray-200 dark:border-gray-700">
                <div class="flex items-center mb-1">
                    <i class="fas fa-share-nodes text-indigo-500 mr-2"></i>
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-white">Social Media Links</h3>
                </div>
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-5">These links appear as icons in the website footer. Leave a field blank to hide that icon.</p>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php
                    $social_fields = [
                        'social_facebook'  => ['Facebook',  'fab fa-facebook-f', 'text-blue-600',    'https://facebook.com/yourschool'],
                        'social_twitter'   => ['Twitter / X','fab fa-x-twitter', 'text-gray-900 dark:text-white', 'https://x.com/yourschool'],
                        'social_linkedin'  => ['LinkedIn',  'fab fa-linkedin-in','text-blue-700',    'https://linkedin.com/company/yourschool'],
                        'social_instagram' => ['Instagram', 'fab fa-instagram',  'text-pink-600',    'https://instagram.com/yourschool'],
                        'social_youtube'   => ['YouTube',   'fab fa-youtube',    'text-red-600',     'https://youtube.com/@yourschool'],
                        'social_tiktok'    => ['TikTok',    'fab fa-tiktok',     'text-gray-900 dark:text-white', 'https://tiktok.com/@yourschool'],
                        'social_whatsapp'  => ['WhatsApp',  'fab fa-whatsapp',   'text-green-600',   'https://wa.me/233241234567'],
                        'social_telegram'  => ['Telegram',  'fab fa-telegram',   'text-sky-500',     'https://t.me/yourschool'],
                    ];
                    foreach ($social_fields as $key => $meta):
                        list($label, $icon, $color, $placeholder) = $meta;
                    ?>
                    <div>
                        <label for="<?php echo $key; ?>" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            <i class="<?php echo $icon; ?> <?php echo $color; ?> mr-1 w-4 text-center"></i> <?php echo $label; ?>
                        </label>
                        <input type="url" id="<?php echo $key; ?>" name="<?php echo $key; ?>"
                            value="<?php echo htmlspecialchars($settings[$key] ?? ''); ?>"
                            placeholder="<?php echo $placeholder; ?>"
                            class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                    </div>
                    <?php endforeach; ?>
                </div>
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
