import re

with open('c:\\xampp\\htdocs\\school_ms\\includes\\sidebar.php', 'r', encoding='utf-8') as f:
    content = f.read()

# Fix the backslashes inside attributes
content = content.replace("? \\'justify-center px-2 py-3\\' : \\'space-x-3 px-4 py-3\\'", "? 'justify-center px-2 py-3' : 'space-x-3 px-4 py-3'")
content = content.replace(":class=\"\\{ \\'rotate-180\\':", ":class=\"{ 'rotate-180':")
content = content.replace("}\\\"", "}\"")
content = content.replace(":class=\\\"{", ":class=\"{")

with open('c:\\xampp\\htdocs\\school_ms\\includes\\sidebar.php', 'w', encoding='utf-8') as f:
    f.write(content)

print("Final cleanup pass done.")
