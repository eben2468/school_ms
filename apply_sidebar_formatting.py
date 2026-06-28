import re

with open('c:\\xampp\\htdocs\\school_ms\\includes\\sidebar.php', 'r', encoding='utf-8') as f:
    content = f.read()

# 1. Fix Profile Picture sizing
# Original: <div class="w-14 h-14 rounded-2xl overflow-hidden shadow-xl backdrop-blur-sm border-2 border-white/20 group-hover:border-white/40 transition-all duration-300 ring-2 ring-white/10">
content = re.sub(
    r'<div class="w-14 h-14 (rounded-2xl overflow-hidden shadow-xl backdrop-blur-sm border-2 border-white/20 group-hover:border-white/40 transition-all duration-300 ring-2 ring-white/10)">',
    r'<div class="\1" :class="$store.sidebar.collapsed ? \'w-10 h-10\' : \'w-14 h-14\'">',
    content
)

# 2. Fix the padding for nav buttons inside the :class condition
# Before: :class="$store.sidebar.collapsed ? 'justify-center px-2 py-3' : 'space-x-3 px-4 py-3'"
# After: :class="$store.sidebar.collapsed ? 'justify-center px-0 py-3 mx-auto w-10' : 'space-x-3 px-4 py-3'"
content = content.replace(
    "? 'justify-center px-2 py-3' : 'space-x-3 px-4 py-3'\"",
    "? 'justify-center px-0 py-3' : 'space-x-3 px-4 py-3'\""
)

# 3. Submenus: Change ml-6 to bind ml-0 when collapsed
# Before: class="ml-6 space-y-1"
# After: :class="$store.sidebar.collapsed ? 'ml-0 items-center justify-center' : 'ml-6'" class="space-y-1"
content = re.sub(
    r'class="ml-6 space-y-1"',
    r'class="space-y-1" :class="$store.sidebar.collapsed ? \'ml-0 flex flex-col items-center\' : \'ml-6\'"',
    content
)

# 4. Submenu inner links padding and text hiding
# Find all a tags inside submenus
# original format: class="flex items-center space-x-3 px-4 py-2 rounded-lg ... "
content = re.sub(
    r'class="flex items-center space-x-3 px-4 py-2 rounded-lg (.*?)"',
    r'class="flex items-center rounded-lg \1" :class="$store.sidebar.collapsed ? \'justify-center px-0 py-2 w-10\' : \'space-x-3 px-4 py-2\'"',
    content
)

# Also make sure the span/text inside the submenu inner links are hidden when collapsed
# Before: <span class="text-white">All Students</span>
# After: <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>All Students</span>
content = re.sub(
    r'<span class="text-white">(.*?)</span>',
    r'<span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>\1</span>',
    content
)
content = content.replace(
    'x-show="!$store.sidebar.collapsed" x-transition x-show="!$store.sidebar.collapsed" x-transition',
    'x-show="!$store.sidebar.collapsed" x-transition'
)

# 5. Fix any bad "New" span that might be broken
# <span class="bg-green-500 text-white text-xs rounded-full px-2 py-0.5 ml-auto">New</span>
content = re.sub(
    r'<span class="(bg-[a-z]+-\d+ text-white text-xs rounded-full.*?)">(.*?)</span>',
    r'<span class="\1" x-show="!$store.sidebar.collapsed" x-transition>\2</span>',
    content
)


with open('c:\\xampp\\htdocs\\school_ms\\includes\\sidebar.php', 'w', encoding='utf-8') as f:
    f.write(content)

print("Formatting fixes applied successfully.")
