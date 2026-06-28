import os
import re

files_to_fix = [
    r"c:\xampp\htdocs\school_ms\bulk_import.php",
    r"c:\xampp\htdocs\school_ms\academic\exams\edit.php",
    r"c:\xampp\htdocs\school_ms\academic\exams\results.php",
    r"c:\xampp\htdocs\school_ms\academic\exams\view.php",
    r"c:\xampp\htdocs\school_ms\academic\subjects\view.php",
    r"c:\xampp\htdocs\school_ms\finance\fees\index.php",
    r"c:\xampp\htdocs\school_ms\finance\fee_structures\index.php",
    r"c:\xampp\htdocs\school_ms\finance\payments\index.php",
    r"c:\xampp\htdocs\school_ms\health\counseling\index.php",
    r"c:\xampp\htdocs\school_ms\health\medical_records\index.php",
    r"c:\xampp\htdocs\school_ms\hostel\rooms\index.php",
    r"c:\xampp\htdocs\school_ms\library\reports.php",
    r"c:\xampp\htdocs\school_ms\library\books\create.php",
    r"c:\xampp\htdocs\school_ms\library\borrowing\index.php",
    r"c:\xampp\htdocs\school_ms\students\enrollment\index.php",
    r"c:\xampp\htdocs\school_ms\students\profiles\index.php"
]

# We need to look for typical badly formatted endings like:
#         </div>
#     </div>
# </div>
# <?php include '...footer.php'; ?>
#
# And replace it with the standard layout bottom:
#         </main>
#         <!-- Footer with proper margin for sidebar -->
#         <div class="lg:ml-0">
#             <?php include '...footer.php'; ?>
#         </div>
#     </div>
# </div>

footer_regex = re.compile(
    r'(?:</div>\s*)*'                  # some number of closing divs
    r'</div>\s*</div>\s*</div>\s*'     # the 3 outer closing divs from the bad layout
    r'(<\?php\s+include\s+[\'"].*?/?includes/footer\.php[\'"];\s*\?>)',
    re.IGNORECASE
)

for filepath in files_to_fix:
    if os.path.exists(filepath):
        with open(filepath, 'r', encoding='utf-8') as f:
            content = f.read()

        # Let's see if it has the bad footer structure.
        match = footer_regex.search(content)
        if match:
            # How many divs were closed right before the 3 wrappers?
            # We will just replace from the first </main> or </div> before the final sequence.
            # A safer way: we know we opened <main> and <div class="w-full"> or similar.
            # Let's just find the included footer, remove it, and rebuild the end.
            footer_line = match.group(1)
            
            # Remove the matched area entirely, we'll append the correct one
            # Actually we just need to replace the last occurance of those closing divs + footer
            correct_ending = f"""        </div>
        </main>

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            {footer_line}
        </div>
    </div>
</div>
"""
            new_content = content[:match.start()] + correct_ending + content[match.end():]
            if new_content != content:
                with open(filepath, 'w', encoding='utf-8') as f:
                    f.write(new_content)
                print(f"Fixed end layout in: {filepath}")
        else:
            print(f"Pattern not found in: {filepath}")
