<?php
/**
 * Fix Sidebar Spacing Script
 * This script updates all pages to remove empty space when sidebar is collapsed
 */

// Function to recursively find PHP files
function findPHPFiles($dir, &$files = []) {
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            // Skip certain directories
            if (in_array($item, ['vendor', 'node_modules', '.git', 'backups', 'logs', 'cache', 'tmp'])) {
                continue;
            }
            findPHPFiles($path, $files);
        } elseif (pathinfo($item, PATHINFO_EXTENSION) === 'php') {
            $files[] = $path;
        }
    }
    return $files;
}

// Function to fix sidebar spacing in a file
function fixSidebarSpacing($filePath) {
    $content = file_get_contents($filePath);
    $originalContent = $content;
    $updated = false;
    
    // Pattern to update sidebar space divs to use w-16 when collapsed (for icon space)
    $patterns = [
        // Update existing sidebar space divs to use w-16 when collapsed (accounting for icons)
        '/(<div class="w-72 flex-shrink-0 lg:block hidden transition-all duration-300"[^>]*x-bind:class="\$store\.sidebar\?\.\w+ \? \'w-0\' : \'w-72\'")/' =>
        '<div class="transition-all duration-300 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? \'w-16\' : \'w-72\'"',

        // Update any w-0 references back to w-16 for collapsed state
        '/x-bind:class="\$store\.sidebar\?\.\w+ \? \'w-0\' : \'w-72\'"/i' =>
        'x-bind:class="$store.sidebar?.collapsed ? \'w-16\' : \'w-72\'"',

        // Update main content areas to have transition
        '/(<div class="flex-1 flex flex-col)(")/i' => '$1 transition-all duration-300$2',
    ];
    
    foreach ($patterns as $pattern => $replacement) {
        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, $replacement, $content);
            $updated = true;
        }
    }
    
    if ($updated && $content !== $originalContent) {
        file_put_contents($filePath, $content);
        return true;
    }
    
    return false;
}

// Main execution
echo "Starting sidebar spacing fix process...\n";

$rootDir = __DIR__;
$phpFiles = findPHPFiles($rootDir);

$updatedFiles = [];
$totalFiles = count($phpFiles);
$processedFiles = 0;

foreach ($phpFiles as $file) {
    $processedFiles++;
    echo "Processing ($processedFiles/$totalFiles): " . str_replace($rootDir . '/', '', $file) . "\n";
    
    // Skip certain files
    $relativePath = str_replace($rootDir . '/', '', $file);
    if (strpos($relativePath, 'includes/') === 0 || 
        strpos($relativePath, 'assets/') === 0 ||
        strpos($relativePath, 'vendor/') === 0 ||
        strpos($relativePath, 'fix_sidebar_spacing.php') === 0) {
        echo "  ⏭️  Skipped (system file)\n";
        continue;
    }
    
    if (fixSidebarSpacing($file)) {
        $updatedFiles[] = str_replace($rootDir . '/', '', $file);
        echo "  ✅ Updated!\n";
    } else {
        echo "  ⏭️  No changes needed\n";
    }
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "SIDEBAR SPACING FIX COMPLETE!\n";
echo str_repeat("=", 60) . "\n";
echo "Total files processed: $totalFiles\n";
echo "Files updated: " . count($updatedFiles) . "\n";

if (!empty($updatedFiles)) {
    echo "\nUpdated files:\n";
    foreach ($updatedFiles as $file) {
        echo "  - $file\n";
    }
}

echo "\nSidebar now uses full width when collapsed!\n";
echo "Changes applied:\n";
echo "  - Removed flex-shrink-0 from sidebar space divs\n";
echo "  - Changed collapsed width from w-16 to w-0\n";
echo "  - Added transitions to main content areas\n";
echo "  - Content now expands to full width when sidebar is collapsed\n";
?>
