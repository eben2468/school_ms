import os
import re

directory = 'c:\\xampp\\htdocs\\school_ms\\students'

# Replace standard older templates across the students modules
# Looking for variations of:
# <!-- Main Layout Container -->
# <div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 20px;">
#     <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
#     <div class="transition-all duration-300 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>
# 
#     <!-- Main Content Area -->
#     <div class="flex-1 flex flex-col">

broken_start = re.compile(
    r'(?:<!-- Main Layout Container -->\s*)?'
    r'<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 20px;">\s*'
    r'<!-- Sidebar Space.*?-->\s*'
    r'<div class="(?:w-72 flex-shrink-0 |transition-all duration-300 )?lg:block hidden" x-data x-bind:class="\$store\.sidebar\?\.collapsed \? \'w-16\' : \'w-72\'"></div>\s*'
    r'<!-- Main Content Area -->\s*'
    r'<div class="flex-1 flex flex-col">', 
    re.IGNORECASE | re.MULTILINE | re.DOTALL
)

replacement_start = """<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 20px;">
    <!-- Sidebar Space (Dynamic width based on sidebar state) -->
    <div class="transition-all duration-300 lg:block hidden" x-data :class="$store.sidebar.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300">"""

count = 0
for root, dirs, files in os.walk(directory):
    for filename in files:
        if filename.endswith(".php"):
            filepath = os.path.join(root, filename)
            with open(filepath, 'r', encoding='utf-8', errors='ignore') as f:
                content = f.read()
            
            new_content = broken_start.sub(replacement_start, content)
            if new_content != content:
                with open(filepath, 'w', encoding='utf-8') as f:
                    f.write(new_content)
                count += 1
                print(f"Fixed start layout in: {filepath}")

print(f"Total files fixed: {count}")
