<?php
/**
 * Add Sidebar Transitions Script
 * This script adds transition classes to sidebar space divs
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

// Function to add transitions to sidebar space divs
function addSidebarTransitions($filePath) {
    $content = file_get_contents($filePath);
    $originalContent = $content;
    $updated = false;
    
    // Pattern to match sidebar space divs and add transition class
    $patterns = [
        // Add transition class to sidebar space divs that don't have it
        '/(<div class="w-72 flex-shrink-0 lg:block hidden)("(?![^"]*transition)[^"]*x-bind:class="\$store\.sidebar\?\.\w+ \? \'w-16\' : \'w-72\'"[^>]*>)/' => '$1 transition-all duration-300$2',
        
        // Handle variations without x-bind
        '/(<div class="w-72 flex-shrink-0 lg:block hidden)(" x-data[^>]*>)/' => '$1 transition-all duration-300$2',
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
echo "Starting sidebar transition update process...\n";

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
        strpos($relativePath, 'add_sidebar_transitions.php') === 0 ||
        strpos($relativePath, 'update_sidebar_layout.php') === 0) {
        echo "  ⏭️  Skipped (system file)\n";
        continue;
    }
    
    if (addSidebarTransitions($file)) {
        $updatedFiles[] = str_replace($rootDir . '/', '', $file);
        echo "  ✅ Updated!\n";
    } else {
        echo "  ⏭️  No changes needed\n";
    }
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "SIDEBAR TRANSITION UPDATE COMPLETE!\n";
echo str_repeat("=", 60) . "\n";
echo "Total files processed: $totalFiles\n";
echo "Files updated: " . count($updatedFiles) . "\n";

if (!empty($updatedFiles)) {
    echo "\nUpdated files:\n";
    foreach ($updatedFiles as $file) {
        echo "  - $file\n";
    }
}

echo "\nSidebar space divs now have smooth transitions!\n";
?>
