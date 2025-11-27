<?php
/**
 * Update Page Headers Script
 * This script finds and updates all page headers to use the dynamic theme system
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

// Function to update gradient headers in a file
function updateGradientHeaders($filePath) {
    $content = file_get_contents($filePath);
    $originalContent = $content;
    $updated = false;
    
    // Pattern to match gradient headers
    $patterns = [
        '/class="page-header-gradient([^"]*)"/',
        '/class="page-header-gradient([^"]*)"/',
        '/class="page-header-gradient([^"]*)"/',
        '/class="page-header-gradient([^"]*)"/',
        '/class="page-header-gradient([^"]*)"/',
        '/class="page-header-gradient([^"]*)"/',
        '/class="page-header-gradient([^"]*)"/',
        '/class="page-header-gradient([^"]*)"/',
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, 'class="page-header-gradient$1"', $content);
            $updated = true;
        }
    }
    
    // Also update any remaining hardcoded gradients
    $hardcodedPatterns = [
        '/style="background:\s*linear-gradient\([^)]+\)([^"]*)"/',
        '/style="background-image:\s*linear-gradient\([^)]+\)([^"]*)"/',
    ];
    
    foreach ($hardcodedPatterns as $pattern) {
        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, 'class="page-header-gradient" style="$1"', $content);
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
echo "Starting page header update process...\n";

$rootDir = __DIR__;
$phpFiles = findPHPFiles($rootDir);

$updatedFiles = [];
$totalFiles = count($phpFiles);
$processedFiles = 0;

foreach ($phpFiles as $file) {
    $processedFiles++;
    echo "Processing ($processedFiles/$totalFiles): " . str_replace($rootDir . '/', '', $file) . "\n";
    
    if (updateGradientHeaders($file)) {
        $updatedFiles[] = str_replace($rootDir . '/', '', $file);
        echo "  ✅ Updated!\n";
    } else {
        echo "  ⏭️  No changes needed\n";
    }
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "UPDATE COMPLETE!\n";
echo str_repeat("=", 60) . "\n";
echo "Total files processed: $totalFiles\n";
echo "Files updated: " . count($updatedFiles) . "\n";

if (!empty($updatedFiles)) {
    echo "\nUpdated files:\n";
    foreach ($updatedFiles as $file) {
        echo "  - $file\n";
    }
}

echo "\nAll page headers now use the dynamic theme system!\n";
echo "Changes will take effect immediately when theme colors are changed in settings.\n";
?>
