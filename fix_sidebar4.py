import re

filepath = 'c:\\xampp\\htdocs\\school_ms\\includes\\sidebar.php'

with open(filepath, 'r', encoding='utf-8') as f:
    content = f.read()

content = content.replace(r"\'", "'")
content = content.replace(r'\"', '"')

with open(filepath, 'w', encoding='utf-8') as f:
    f.write(content)

print("Backslashes removed.")
