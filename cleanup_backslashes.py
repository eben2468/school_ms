import os

directory = 'c:\\xampp\\htdocs\\school_ms'

for root, dirs, files in os.walk(directory):
    for filename in files:
        if filename.endswith(".php"):
            filepath = os.path.join(root, filename)
            if 'assets' in filepath or 'vendor' in filepath:
                continue
                
            with open(filepath, 'r', encoding='utf-8', errors='ignore') as f:
                content = f.read()
            
            # Remove the literal backslashes from :class attributes
            new_content = content.replace(":class=\"$store.sidebar.collapsed ? \\'w-16\\' : \\'w-72\\'\"", ":class=\"$store.sidebar.collapsed ? 'w-16' : 'w-72'\"")
            
            if new_content != content:
                with open(filepath, 'w', encoding='utf-8') as f:
                    f.write(new_content)
                print(f"Cleaned backslashes in: {filepath}")
