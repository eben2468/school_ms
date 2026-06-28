import os
import re

directory = 'c:\\xampp\\htdocs\\school_ms'

# Pattern for the broken spacer div:
# <div class="transition-all duration-300 lg:block hidden" x-data :class="$store.sidebar.collapsed ? 'w-16' : 'w-72'"></div>
spacer_pattern = re.compile(
    r'<div class="transition-all duration-300 lg:block hidden" x-data :class="\$store\.sidebar\.collapsed \? \'w-16\' : \'w-72\'"></div>',
    re.IGNORECASE
)

# New spacer div with flex-shrink-0 and no redundant x-data
spacer_replacement = r'<div class="transition-all duration-300 lg:block hidden flex-shrink-0" :class="$store.sidebar.collapsed ? \'w-16\' : \'w-72\'"></div>'

# Pattern for the margin-top 20px:
margin_pattern = re.compile(r'style="margin-top: 20px;"', re.IGNORECASE)
margin_replacement = r'style="margin-top: 80px;"'

count_spacer = 0
count_margin = 0

for root, dirs, files in os.walk(directory):
    for filename in files:
        if filename.endswith(".php"):
            filepath = os.path.join(root, filename)
            # Skip assets/plugins if any
            if 'assets' in filepath or 'vendor' in filepath:
                continue
                
            with open(filepath, 'r', encoding='utf-8', errors='ignore') as f:
                content = f.read()
            
            new_content = content
            
            # Fix spacers
            if spacer_pattern.search(new_content):
                new_content = spacer_pattern.sub(spacer_replacement, new_content)
                count_spacer += 1
                
            # Fix margin-top
            if margin_pattern.search(new_content):
                new_content = margin_pattern.sub(margin_replacement, new_content)
                count_margin += 1
                
            if new_content != content:
                with open(filepath, 'w', encoding='utf-8') as f:
                    f.write(new_content)
                print(f"Fixed layout in: {filepath}")

# Also fix the header.php margin-top
header_path = r'c:\xampp\htdocs\school_ms\includes\header.php'
if os.path.exists(header_path):
    with open(header_path, 'r', encoding='utf-8') as f:
        header_content = f.read()
    
    new_header = header_content.replace('margin-top: 20px; /* Space for fixed header */', 'margin-top: 80px; /* Space for fixed header */')
    if new_header != header_content:
        with open(header_path, 'w', encoding='utf-8') as f:
            f.write(new_header)
        print("Fixed margin-top in header.php")

print(f"Total spacers fixed: {count_spacer}")
print(f"Total margins fixed: {count_margin}")
