<?php
/**
 * Update Sidebar Layout Script
 * This script updates pages to use dynamic sidebar layout
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

// Function to update sidebar layout in a file
function updateSidebarLayout($filePath) {
    $content = file_get_contents($filePath);
    $originalContent = $content;
    $updated = false;
    
    // Pattern to match old main tag with fixed margins
    $patterns = [
        // Update main tags with ml-0 lg:ml-72 to remove fixed margins
        '/(<main[^>]*class="[^"]*)\s*ml-0\s+lg:ml-72([^"]*"[^>]*>)/' => '$1$2',
        '/(<main[^>]*class="[^"]*)\s*lg:ml-72\s+ml-0([^"]*"[^>]*>)/' => '$1$2',
        '/(<main[^>]*class="[^"]*)\s*ml-72([^"]*"[^>]*>)/' => '$1$2',
        
        // Update main tags with style margin-top
        '/(<main[^>]*)\s*style="margin-top:\s*\d+px;?"([^>]*>)/' => '$1 style="margin-top: 80px;"$2',
        
        // Add dynamic sidebar space div if missing and page has main content
        '/(<div class="flex[^"]*"[^>]*>\s*)((?!.*<div class="w-72 flex-shrink-0).*<main)/' => '$1<!-- Sidebar Space -->\n    <div class="w-72 flex-shrink-0 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? \'w-16\' : \'w-72\'"></div>\n\n    <!-- Main Content Area -->\n    <div class="flex-1 flex flex-col">\n        $2',
        
        // Close the main content area div before footer
        '/(<\/main>\s*)((?:.*?<\/div>\s*)*<\/body>)/' => '$1    </div>\n$2',
    ];
    
    foreach ($patterns as $pattern => $replacement) {
        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, $replacement, $content);
            $updated = true;
        }
    }
    
    // Special handling for pages that need complete layout restructure
    if (strpos($content, 'includes/sidebar.php') !== false && 
        strpos($content, '<main') !== false && 
        strpos($content, 'w-72 flex-shrink-0') === false) {
        
        // Look for main tag and wrap it properly
        $mainPattern = '/(<main[^>]*>)(.*?)(<\/main>)/s';
        if (preg_match($mainPattern, $content, $matches)) {
            $mainTag = $matches[1];
            $mainContent = $matches[2];
            $mainClose = $matches[3];
            
            // Remove any existing margin classes from main tag
            $mainTag = preg_replace('/\s*ml-\d+\s*/', ' ', $mainTag);
            $mainTag = preg_replace('/\s*lg:ml-\d+\s*/', ' ', $mainTag);
            
            // Create new layout structure
            $newLayout = <<<HTML

<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen">
    <!-- Sidebar Space -->
    <div class="w-72 flex-shrink-0 lg:block hidden transition-all duration-300" x-data x-bind:class="\$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col">
        <!-- Content Wrapper -->
        $mainTag$mainContent$mainClose

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include 'includes/footer.php'; ?>
        </div>
    </div>
</div>
HTML;
            
            // Replace the main section
            $content = preg_replace($mainPattern, $newLayout, $content);
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
echo "Starting sidebar layout update process...\n";

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
        $relativePath === 'update_sidebar_layout.php') {
        echo "  ⏭️  Skipped (system file)\n";
        continue;
    }
    
    if (updateSidebarLayout($file)) {
        $updatedFiles[] = str_replace($rootDir . '/', '', $file);
        echo "  ✅ Updated!\n";
    } else {
        echo "  ⏭️  No changes needed\n";
    }
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "SIDEBAR LAYOUT UPDATE COMPLETE!\n";
echo str_repeat("=", 60) . "\n";
echo "Total files processed: $totalFiles\n";
echo "Files updated: " . count($updatedFiles) . "\n";

if (!empty($updatedFiles)) {
    echo "\nUpdated files:\n";
    foreach ($updatedFiles as $file) {
        echo "  - $file\n";
    }
}

echo "\nSidebar layout now supports dynamic toggling!\n";
echo "Changes applied:\n";
echo "  - Removed fixed margin classes from main elements\n";
echo "  - Added dynamic sidebar space divs\n";
echo "  - Implemented proper flex layout structure\n";
echo "  - Added transition animations\n";
?>
