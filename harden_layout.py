import os
import re

directory = 'c:\\xampp\\htdocs\\school_ms'

# Pattern for the layout container
container_pattern = re.compile(
    r'<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 80px;">',
    re.IGNORECASE
)
# Replacement adding w-full and overflow-x-hidden for safety
container_replacement = '<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">'

# Pattern for the main content area div
content_area_pattern = re.compile(
    r'<div class="flex-1 flex flex-col transition-all duration-300">',
    re.IGNORECASE
)
# Replacement adding min-w-0
content_area_replacement = '<div class="flex-1 flex flex-col transition-all duration-300 min-w-0">'

count = 0

for root, dirs, files in os.walk(directory):
    for filename in files:
        if filename.endswith(".php"):
            filepath = os.path.join(root, filename)
            if 'assets' in filepath or 'vendor' in filepath:
                continue
                
            with open(filepath, 'r', encoding='utf-8', errors='ignore') as f:
                content = f.read()
            
            new_content = container_pattern.sub(container_replacement, content)
            new_content = content_area_pattern.sub(content_area_replacement, new_content)
            
            if new_content != content:
                with open(filepath, 'w', encoding='utf-8') as f:
                    f.write(new_content)
                count += 1
                print(f"Hardened layout in: {filepath}")

print(f"Total files hardened: {count}")
