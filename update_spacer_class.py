import os
import re

directory = 'c:\\xampp\\htdocs\\school_ms'

# Regex to find the spacer div and replace with a class-based one
# The old pattern we were using:
# <div class="transition-all duration-300 lg:block hidden flex-shrink-0" :class="$store.sidebar.collapsed ? 'w-16' : 'w-72'"></div>

spacer_pattern = re.compile(
    r'<div class="transition-all duration-300 lg:block hidden flex-shrink-0" :class="\$store\.sidebar\.collapsed \? \'w-16\' : \'w-72\'"></div>',
    re.IGNORECASE
)

# New replacement using the fixed CSS class
spacer_replacement = '<div class="sidebar-spacer lg:block hidden" :class="{ \'collapsed\': $store.sidebar.collapsed }"></div>'

for root, dirs, files in os.walk(directory):
    for filename in files:
        if filename.endswith(".php"):
            filepath = os.path.join(root, filename)
            if 'assets' in filepath or 'vendor' in filepath:
                continue
                
            with open(filepath, 'r', encoding='utf-8', errors='ignore') as f:
                content = f.read()
            
            new_content = spacer_pattern.sub(spacer_replacement, content)
            
            if new_content != content:
                with open(filepath, 'w', encoding='utf-8') as f:
                    f.write(new_content)
                print(f"Updated spacer to use CSS class in: {filepath}")
