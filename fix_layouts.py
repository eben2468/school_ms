import os
import re

directory = 'c:\\xampp\\htdocs\\school_ms'

# Look for patterns like:
# <div class="flex">
#     <!-- Sidebar space -->
#     <div class="w-64 flex-shrink-0"></div>
#
#     <!-- Main content -->
#     <div class="flex-grow p-8 bg-gray-50 min-h-screen">

# and replace with:
# <div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 20px;">
#     <!-- Sidebar Space (Dynamic width based on sidebar state) -->
#     <div class="transition-all duration-300 lg:block hidden" x-data :class="$store.sidebar.collapsed ? 'w-16' : 'w-72'"></div>
# 
#     <!-- Main Content Area -->
#     <div class="flex-1 flex flex-col transition-all duration-300">
#         <!-- Content Wrapper -->
#         <main class="p-4 lg:p-8 flex-1">

broken_start = re.compile(r'<div class="flex">(?:\s*<!-- Sidebar space -->\s*)?\s*<div class="w-64 flex-shrink-0"></div>\s*(?:<!-- Main content -->\s*)?<div class="flex-grow p-8 bg-gray-50 min-h-screen">', re.IGNORECASE)

replacement_start = """<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 20px;">
    <!-- Sidebar Space (Dynamic width based on sidebar state) -->
    <div class="transition-all duration-300 lg:block hidden" x-data :class="$store.sidebar.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300">
        <!-- Content Wrapper -->
        <main class="p-4 lg:p-8 flex-1">"""

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
