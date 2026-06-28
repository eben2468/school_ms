import re

with open('c:\\xampp\\htdocs\\school_ms\\includes\\sidebar.php', 'r', encoding='utf-8') as f:
    content = f.read()

# Replace button classes
# Before: class="w-full flex items-center space-x-3 px-4 py-3 rounded-xl ... group"
# After: class="w-full flex items-center rounded-xl ... group" :class="$store.sidebar.collapsed ? 'justify-center px-2 py-3' : 'space-x-3 px-4 py-3'"
content = re.sub(
    r'class="w-full flex items-center space-x-3 px-4 py-3 rounded-xl (.*?)"',
    r'class="w-full flex items-center rounded-xl \1" :class="$store.sidebar.collapsed ? \'justify-center px-2 py-3\' : \'space-x-3 px-4 py-3\'"',
    content
)

# Same for a tags
content = re.sub(
    r'class="flex items-center space-x-3 px-4 py-3 rounded-xl (.*?)"',
    r'class="flex items-center rounded-xl \1" :class="$store.sidebar.collapsed ? \'justify-center px-2 py-3\' : \'space-x-3 px-4 py-3\'"',
    content
)

# Hide text container
content = re.sub(
    r'<div class="flex-1 text-left">',
    r'<div class="flex-1 text-left" x-show="!$store.sidebar.collapsed" x-transition>',
    content
)

# Hide the standard flex-1 as well if some exist
content = re.sub(
    r'<div class="flex-1">',
    r'<div class="flex-1" x-show="!$store.sidebar.collapsed" x-transition>',
    content
)
# We might have doubled x-show for dashboard. Let's fix that if it happened.
content = content.replace('x-show="!$store.sidebar.collapsed" x-transition x-show="!$store.sidebar.collapsed" x-transition', 'x-show="!$store.sidebar.collapsed" x-transition')

# Prevent chevron from showing
content = re.sub(
    r'<i class="fas fa-chevron-down text-sm transition-transform duration-200 text-white" :class="\{ \'rotate-180\': (.*?) \}"></i>',
    r'<i class="fas fa-chevron-down text-sm transition-transform duration-200 text-white" :class="{ \'rotate-180\': \1 }" x-show="!$store.sidebar.collapsed" x-transition></i>',
    content
)

with open('c:\\xampp\\htdocs\\school_ms\\includes\\sidebar.php', 'w', encoding='utf-8') as f:
    f.write(content)

print('Updated sidebar.php layout rules')
