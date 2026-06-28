import os
import re

directory = 'c:\\xampp\\htdocs\\school_ms'

# Find any spacer div that matches the sidebar pattern
# It might have x-data, x-bind:class, or :class, or different whitespace
spacer_pattern = re.compile(
    r'<div class="transition-all duration-300 lg:block hidden[^"]*"[^>]*:class="\$store\.sidebar\?\.collapsed \? \'w-16\' : \'w-72\'"[^>]*></div>',
    re.IGNORECASE
)

# And another variation I created:
val_spacer_pattern = re.compile(
    r'<div class="transition-all duration-300 lg:block hidden[^"]*"[^>]*:class="\$store\.sidebar\.collapsed \? \'w-16\' : \'w-72\'"[^>]*></div>',
    re.IGNORECASE
)

replacement = '<div class="transition-all duration-300 lg:block hidden flex-shrink-0" :class="$store.sidebar.collapsed ? \'w-16\' : \'w-72\'"></div>'

for root, dirs, files in os.walk(directory):
    for filename in files:
        if filename.endswith(".php"):
            filepath = os.path.join(root, filename)
            if 'assets' in filepath or 'vendor' in filepath:
                continue
                
            with open(filepath, 'r', encoding='utf-8', errors='ignore') as f:
                content = f.read()
            
            new_content = val_spacer_pattern.sub(replacement, content)
            new_content = spacer_pattern.sub(replacement, new_content)
            
            if new_content != content:
                with open(filepath, 'w', encoding='utf-8') as f:
                    f.write(new_content)
                print(f"Standardized spacer in: {filepath}")

# Fix header.php - remove the main margin-top
header_path = r'c:\xampp\htdocs\school_ms\includes\header.php'
if os.path.exists(header_path):
    with open(header_path, 'r', encoding='utf-8') as f:
        header_content = f.read()
    
    # Remove any main { margin-top: ... }
    new_header = re.sub(r'main\s*{\s*margin-top:\s*[^;]+;\s*(?:/\*.*?\*/)?\s*flex:\s*1;\s*}', 'main { flex: 1; }', header_content, flags=re.IGNORECASE)
    if new_header != header_content:
        with open(header_path, 'w', encoding='utf-8') as f:
            f.write(new_header)
        print("Removed main margin in header.php")
