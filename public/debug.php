<?php

declare(strict_types=1);

// Simple debug page - remove after diagnosis
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Load configuration
$config = require __DIR__ . '/../app/Config/config.php';

// Simple PSR-4 autoloader for SecurityService
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/../app/';

    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use App\Services\SecurityService;
use App\Services\FileSystemService;

$security = new SecurityService($config['content_path']);
$fileSystem = new FileSystemService($config['content_path'], $security);

$contentPath = $config['content_path'];
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$requestPath = $_GET['path'] ?? 'PLE-MANUEL';

// Remove /debug and query string
$requestPath = trim($requestPath, '/');

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>PLE Debug Page</title>
    <style>
        body { font-family: monospace; margin: 20px; background: #f5f5f5; }
        .section { background: white; padding: 15px; margin: 10px 0; border: 1px solid #ddd; border-radius: 4px; }
        h2 { color: #333; border-bottom: 2px solid #007bff; padding-bottom: 10px; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .warning { color: orange; font-weight: bold; }
        pre { background: #f0f0f0; padding: 10px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 8px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #007bff; color: white; }
        input[type="text"] { width: 100%; padding: 5px; margin: 10px 0; }
    </style>
</head>
<body>

<h1>🔍 PLE Debug Page</h1>

<div class="section">
    <h2>Test Path</h2>
    <form method="GET">
        <input type="hidden" name="debug" value="1">
        <input type="text" name="path" value="<?php echo htmlspecialchars($requestPath); ?>" placeholder="e.g., PLE-MANUEL">
        <button type="submit">Debug Path</button>
    </form>
</div>

<div class="section">
    <h2>📁 Configuration</h2>
    <table>
        <tr>
            <th>Setting</th>
            <th>Value</th>
        </tr>
        <tr>
            <td>Content Path</td>
            <td><code><?php echo htmlspecialchars($contentPath); ?></code></td>
        </tr>
        <tr>
            <td>Content Path Exists</td>
            <td><?php echo is_dir($contentPath) ? '<span class="success">✓ YES</span>' : '<span class="error">✗ NO</span>'; ?></td>
        </tr>
        <tr>
            <td>Content Path Readable</td>
            <td><?php echo is_readable($contentPath) ? '<span class="success">✓ YES</span>' : '<span class="error">✗ NO</span>'; ?></td>
        </tr>
        <tr>
            <td>Content Path Writable</td>
            <td><?php echo is_writable($contentPath) ? '<span class="success">✓ YES</span>' : '<span class="error">✗ NO</span>'; ?></td>
        </tr>
        <tr>
            <td>PHP Version</td>
            <td><?php echo phpversion(); ?></td>
        </tr>
        <tr>
            <td>Server OS</td>
            <td><?php echo php_uname(); ?></td>
        </tr>
    </table>
</div>

<div class="section">
    <h2>📂 Directory Listing: /content/</h2>
    <?php
    if (is_dir($contentPath)) {
        $items = @scandir($contentPath);
        if ($items === false) {
            echo '<p class="error">Cannot read directory</p>';
        } else {
            $items = array_filter($items, function($item) {
                return $item !== '.' && $item !== '..';
            });
            
            if (empty($items)) {
                echo '<p class="warning">Directory is empty</p>';
            } else {
                echo '<table>';
                echo '<tr><th>Name</th><th>Hex Bytes</th><th>Type</th><th>Size</th><th>Permissions</th><th>Readable</th><th>file_exists()</th></tr>';
                foreach (array_sort($items) as $item) {
                    $fullPath = $contentPath . DIRECTORY_SEPARATOR . $item;
                    $isDir = is_dir($fullPath);
                    $size = $isDir ? '—' : filesize($fullPath);
                    $perms = substr(sprintf('%o', fileperms($fullPath)), -4);
                    $readable = is_readable($fullPath) ? '✓' : '✗';
                    $type = $isDir ? 'DIR' : 'FILE';
                    $hexBytes = bin2hex($item);
                    $fileExists = file_exists($fullPath) ? '✓' : '✗';
                    echo '<tr>';
                    echo '<td><code>' . htmlspecialchars($item) . '</code></td>';
                    echo '<td><code style="font-size:11px">' . $hexBytes . '</code></td>';
                    echo '<td>' . $type . '</td>';
                    echo '<td>' . $size . '</td>';
                    echo '<td>' . $perms . '</td>';
                    echo '<td>' . $readable . '</td>';
                    echo '<td>' . $fileExists . '</td>';
                    echo '</tr>';
                }
                echo '</table>';
            }
        }
    } else {
        echo '<p class="error">Content path does not exist!</p>';
    }
    ?>
</div>

<div class="section">
    <h2>🔍 Path Resolution Test</h2>
    <?php
    try {
        $validated = $security->validateRelativePath($requestPath);
        $resolved = $fileSystem->resolvePath($validated);
        
        echo '<table>';
        echo '<tr><th>Property</th><th>Value</th></tr>';
        echo '<tr><td>Requested Path</td><td><code>' . htmlspecialchars($requestPath) . '</code><br><small>Hex: ' . bin2hex($requestPath) . '</small></td></tr>';
        echo '<tr><td>Validated Path</td><td><code>' . htmlspecialchars($validated) . '</code><br><small>Hex: ' . bin2hex($validated) . '</small></td></tr>';
        echo '<tr><td>Resolved Path</td><td><code>' . htmlspecialchars($resolved) . '</code><br><small>Hex: ' . bin2hex($resolved) . '</small></td></tr>';
        echo '<tr><td>Path Exists</td><td>';
        if (file_exists($resolved)) {
            echo '<span class="success">✓ YES</span>';
        } else {
            echo '<span class="error">✗ NO</span>';
        }
        echo '</td></tr>';
        
        // Try to find similar names in scandir
        $items = @scandir($contentPath);
        if ($items && !in_array($requestPath, $items, true)) {
            echo '<tr><td colspan="2" style="background: #fff3cd;">';
            echo '<strong>⚠️ Name not found in scandir()!</strong><br>';
            echo 'Looking for similar names...<br>';
            $similar = [];
            foreach ($items as $item) {
                if ($item !== '.' && $item !== '..' && strcasecmp($item, $requestPath) === 0) {
                    $similar[] = $item;
                }
            }
            if (!empty($similar)) {
                echo 'Found case-insensitive match(es):<br>';
                foreach ($similar as $match) {
                    echo '&nbsp;&nbsp;→ <code>' . htmlspecialchars($match) . '</code> (Hex: ' . bin2hex($match) . ')<br>';
                }
            } else {
                echo 'No case-insensitive matches found either.';
            }
            echo '</td></tr>';
        }
        
        if (file_exists($resolved)) {
            echo '<tr><td>Is Directory</td><td>' . (is_dir($resolved) ? 'YES' : 'NO') . '</td></tr>';
            echo '<tr><td>Is File</td><td>' . (is_file($resolved) ? 'YES' : 'NO') . '</td></tr>';
            echo '<tr><td>Is Readable</td><td>' . (is_readable($resolved) ? 'YES' : 'NO') . '</td></tr>';
            echo '<tr><td>Permissions</td><td>' . substr(sprintf('%o', fileperms($resolved)), -4) . '</td></tr>';
            echo '<tr><td>Size</td><td>' . filesize($resolved) . ' bytes</td></tr>';
            
            // List directory contents if it's a directory
            if (is_dir($resolved)) {
                echo '<tr><td colspan="2">';
                $subItems = @scandir($resolved);
                if ($subItems !== false) {
                    echo '<strong>Directory contents:</strong><br>';
                    foreach (array_slice($subItems, 0, 20) as $item) {
                        if ($item !== '.' && $item !== '..') {
                            $subPath = $resolved . DIRECTORY_SEPARATOR . $item;
                            $subType = is_dir($subPath) ? '[DIR]' : '[FILE]';
                            echo htmlspecialchars($item) . ' ' . $subType . '<br>';
                        }
                    }
                    if (count($subItems) > 22) {
                        echo '... and ' . (count($subItems) - 22) . ' more items';
                    }
                } else {
                    echo '<span class="error">Cannot read directory contents</span>';
                }
                echo '</td></tr>';
            }
        }
        echo '</table>';
    } catch (Exception $e) {
        echo '<p class="error">Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
        echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
    }
    ?>
</div>

<div class="section">
    <h2>💾 Try Loading Content</h2>
    <?php
    try {
        $validated = $security->validateRelativePath($requestPath);
        $resolved = $fileSystem->resolvePath($validated);
        
        if (file_exists($resolved)) {
            if (is_dir($resolved)) {
                $entries = $fileSystem->listDirectory($resolved);
                echo '<p><strong>Directory entries:</strong></p>';
                echo '<table>';
                echo '<tr><th>Name</th><th>Type</th><th>Size</th><th>Modified</th></tr>';
                foreach ($entries as $entry) {
                    $type = $entry['is_dir'] ? 'DIR' : 'FILE';
                    $size = $entry['is_dir'] ? '—' : $entry['size'];
                    $modified = date('Y-m-d H:i:s', $entry['modified']);
                    echo '<tr>';
                    echo '<td>' . htmlspecialchars($entry['name']) . '</td>';
                    echo '<td>' . $type . '</td>';
                    echo '<td>' . $size . '</td>';
                    echo '<td>' . $modified . '</td>';
                    echo '</tr>';
                }
                echo '</table>';
            } else {
                echo '<p class="success">File found! Size: ' . filesize($resolved) . ' bytes</p>';
            }
        } else {
            echo '<p class="error">Path not found (404)</p>';
        }
    } catch (Exception $e) {
        echo '<p class="error">Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
    }
    ?>
</div>

<div class="section">
    <h2>🗑️ Actions</h2>
    <p><a href="/?debug=clear">Clear Debug Cookie</a> | <a href="/">Back to Home</a></p>
</div>

</body>
</html>

<?php

function array_sort(array $array): array {
    sort($array);
    return $array;
}
