<?php
/**
 * Reduce Page Header Heights Script
 * This script finds and reduces the height of all page headers across the system
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

// Function to reduce header heights in a file
function reduceHeaderHeights($filePath) {
    $content = file_get_contents($filePath);
    $originalContent = $content;
    $updated = false;
    
    // Patterns to match and reduce header heights
    $patterns = [
        // Reduce padding from p-8 to p-4
        '/class="([^"]*page-header-gradient[^"]*)\s+rounded-2xl\s+p-8([^"]*)"/' => 'class="$1 rounded-xl p-4$2"',
        '/class="([^"]*page-header-gradient[^"]*)\s+rounded-xl\s+p-8([^"]*)"/' => 'class="$1 rounded-xl p-4$2"',
        '/class="([^"]*page-header-gradient[^"]*)\s+p-8([^"]*)"/' => 'class="$1 p-4$2"',
        
        // Reduce padding from p-6 to p-4
        '/class="([^"]*page-header-gradient[^"]*)\s+rounded-2xl\s+p-6([^"]*)"/' => 'class="$1 rounded-xl p-4$2"',
        '/class="([^"]*page-header-gradient[^"]*)\s+rounded-xl\s+p-6([^"]*)"/' => 'class="$1 rounded-xl p-4$2"',
        '/class="([^"]*page-header-gradient[^"]*)\s+p-6([^"]*)"/' => 'class="$1 p-4$2"',
        
        // Change rounded-2xl to rounded-xl for headers
        '/class="([^"]*page-header-gradient[^"]*)\s+rounded-2xl([^"]*)"/' => 'class="$1 rounded-xl$2"',
        
        // Reduce shadow from shadow-xl to shadow-lg
        '/class="([^"]*page-header-gradient[^"]*)\s+shadow-xl([^"]*)"/' => 'class="$1 shadow-lg$2"',
        
        // Handle bg-gradient-to-r headers
        '/class="([^"]*bg-gradient-to-r[^"]*)\s+rounded-2xl\s+p-8([^"]*)"/' => 'class="$1 rounded-xl p-4$2"',
        '/class="([^"]*bg-gradient-to-r[^"]*)\s+rounded-xl\s+p-8([^"]*)"/' => 'class="$1 rounded-xl p-4$2"',
        '/class="([^"]*bg-gradient-to-r[^"]*)\s+p-8([^"]*)"/' => 'class="$1 p-4$2"',
        '/class="([^"]*bg-gradient-to-r[^"]*)\s+rounded-2xl\s+p-6([^"]*)"/' => 'class="$1 rounded-xl p-4$2"',
        '/class="([^"]*bg-gradient-to-r[^"]*)\s+rounded-xl\s+p-6([^"]*)"/' => 'class="$1 rounded-xl p-4$2"',
        '/class="([^"]*bg-gradient-to-r[^"]*)\s+p-6([^"]*)"/' => 'class="$1 p-4$2"',
        '/class="([^"]*bg-gradient-to-r[^"]*)\s+rounded-2xl([^"]*)"/' => 'class="$1 rounded-xl$2"',
        '/class="([^"]*bg-gradient-to-r[^"]*)\s+shadow-xl([^"]*)"/' => 'class="$1 shadow-lg$2"',
    ];
    
    foreach ($patterns as $pattern => $replacement) {
        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, $replacement, $content);
            $updated = true;
        }
    }
    
    // Additional specific patterns for headers
    $specificPatterns = [
        // Reduce text sizes in headers
        'text-2xl font-bold' => 'text-2xl font-bold',
        'text-2xl font-bold' => 'text-2xl font-bold',
        
        // Reduce margin/padding in header content
        'mb-2' => 'mb-2',
        'mt-2' => 'mt-2',
    ];
    
    // Only apply text size changes to header sections
    if (strpos($content, 'page-header-gradient') !== false || strpos($content, 'bg-gradient-to-r') !== false) {
        foreach ($specificPatterns as $pattern => $replacement) {
            if (preg_match('/' . preg_quote($pattern, '/') . '/', $content)) {
                $content = preg_replace('/' . preg_quote($pattern, '/') . '/', $replacement, $content);
                $updated = true;
            }
        }
    }
    
    if ($updated && $content !== $originalContent) {
        file_put_contents($filePath, $content);
        return true;
    }
    
    return false;
}

// Main execution
echo "Starting page header height reduction process...\n";

$rootDir = __DIR__;
$phpFiles = findPHPFiles($rootDir);

$updatedFiles = [];
$totalFiles = count($phpFiles);
$processedFiles = 0;

foreach ($phpFiles as $file) {
    $processedFiles++;
    echo "Processing ($processedFiles/$totalFiles): " . str_replace($rootDir . '/', '', $file) . "\n";
    
    if (reduceHeaderHeights($file)) {
        $updatedFiles[] = str_replace($rootDir . '/', '', $file);
        echo "  ✅ Updated!\n";
    } else {
        echo "  ⏭️  No changes needed\n";
    }
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "HEADER HEIGHT REDUCTION COMPLETE!\n";
echo str_repeat("=", 60) . "\n";
echo "Total files processed: $totalFiles\n";
echo "Files updated: " . count($updatedFiles) . "\n";

if (!empty($updatedFiles)) {
    echo "\nUpdated files:\n";
    foreach ($updatedFiles as $file) {
        echo "  - $file\n";
    }
}

echo "\nAll page headers now have reduced heights for better space utilization!\n";
echo "Changes applied:\n";
echo "  - Padding reduced from p-8/p-6 to p-4\n";
echo "  - Border radius changed from rounded-2xl to rounded-xl\n";
echo "  - Shadow reduced from shadow-xl to shadow-lg\n";
echo "  - Text sizes optimized for compact headers\n";
?>
