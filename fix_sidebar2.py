import re

with open('c:\\xampp\\htdocs\\school_ms\\includes\\sidebar.php', 'r', encoding='utf-8') as f:
    content = f.read()

# Fix the backslashes in :class attribute
content = content.replace(
    r":class=\"$store.sidebar.collapsed ? \'justify-center px-2 py-3\' : \'space-x-3 px-4 py-3\'\"",
    ":class=\"$store.sidebar.collapsed ? 'justify-center px-2 py-3' : 'space-x-3 px-4 py-3'\""
)

# And in chevron
content = re.sub(
    r":class=\"\{ \\'rotate-180\\': (.*?) \}\"",
    r":class=\"{ 'rotate-180': \1 }\"",
    content
)

# Fix h3 class
content = re.sub(
    r'<h3 class="px-4 text-xs font-semibold text-blue-200 uppercase tracking-wider mb-3">',
    r'<h3 class="px-4 text-xs font-semibold text-blue-200 uppercase tracking-wider mb-3" x-show="!$store.sidebar.collapsed" x-transition>',
    content
)


with open('c:\\xampp\\htdocs\\school_ms\\includes\\sidebar.php', 'w', encoding='utf-8') as f:
    f.write(content)

print("sidebar.php cleaned up.")
