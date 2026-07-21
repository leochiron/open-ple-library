<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Controllers\SyncController;
use App\Services\GoogleDriveService;
use App\Services\I18nService;

final class LocalSyncDrive extends GoogleDriveService
{
    /** @var array<int, array{id: string, name: string, mimeType: string, content?: string}> */
    private array $entries;
    /** @var string[] */
    public array $downloadedIds = [];

    /** @param array<int, array{id: string, name: string, mimeType: string, content?: string}> $entries */
    public function __construct(array $entries)
    {
        $this->entries = $entries;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getRootFolderId(): ?string
    {
        return 'root';
    }

    public function listFiles(?string $folderId = null): array
    {
        return $folderId === 'root' ? $this->entries : [];
    }

    public function downloadFile(string $fileId, ?string $destinationPath = null)
    {
        if ($destinationPath === null) {
            return null;
        }
        foreach ($this->entries as $entry) {
            if ($entry['id'] === $fileId && array_key_exists('content', $entry)) {
                $this->downloadedIds[] = $fileId;
                return file_put_contents($destinationPath, $entry['content']);
            }
        }
        return null;
    }
}

function removeSyncFixture(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $target = $path . DIRECTORY_SEPARATOR . $item;
        is_dir($target) ? removeSyncFixture($target) : unlink($target);
    }
    rmdir($path);
}

$root = dirname(__DIR__);
$fixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ple-sync-protection-' . bin2hex(random_bytes(5));
$contentPath = $fixture . DIRECTORY_SEPARATOR . 'content';
mkdir($contentPath . DIRECTORY_SEPARATOR . 'old-folder', 0700, true);
file_put_contents($contentPath . DIRECTORY_SEPARATOR . 'old.txt', 'obsolete');
file_put_contents($contentPath . DIRECTORY_SEPARATOR . 'old-folder' . DIRECTORY_SEPARATOR . 'nested.txt', 'obsolete');
copy($root . '/content/.htaccess', $contentPath . DIRECTORY_SEPARATOR . '.htaccess');

try {
    $config = [
        'content_path' => $contentPath,
        'branding' => ['sync_delete_existing' => true],
    ];
    $freshContent = 'fresh content';
    $drive = new LocalSyncDrive([
        ['id' => 'fresh', 'name' => 'fresh.txt', 'mimeType' => 'text/plain', 'content' => $freshContent],
        ['id' => 'rules', 'name' => '.HTACCESS. ', 'mimeType' => 'text/plain', 'content' => 'RewriteRule ^ - [L]'],
        ['id' => 'traversal', 'name' => '../escape.txt', 'mimeType' => 'text/plain', 'content' => 'escaped'],
        ['id' => 'backslash', 'name' => '..\\escape.txt', 'mimeType' => 'text/plain', 'content' => 'escaped'],
        ['id' => 'absolute', 'name' => '/absolute.txt', 'mimeType' => 'text/plain', 'content' => 'escaped'],
        ['id' => 'drive-letter', 'name' => 'C:escape.txt', 'mimeType' => 'text/plain', 'content' => 'escaped'],
        ['id' => 'nul', 'name' => "bad\0name", 'mimeType' => 'text/plain', 'content' => 'escaped'],
        ['id' => 'folder-traversal', 'name' => '..\\outside', 'mimeType' => 'application/vnd.google-apps.folder'],
    ]);
    $controller = new SyncController($drive, new I18nService([], []), $config);
    $sync = new ReflectionMethod($controller, 'syncFromGoogleDrive');
    $sync->setAccessible(true);
    $stats = $sync->invoke($controller);

    $canonicalRules = (string)file_get_contents($root . '/app/Config/content.htaccess');
    assertSameValue(false, is_file($contentPath . '/old.txt'), 'Destructive sync removes an old root file');
    assertSameValue(false, is_dir($contentPath . '/old-folder'), 'Destructive sync removes old nested content');
    assertSameValue($freshContent, file_get_contents($contentPath . '/fresh.txt'), 'Sync downloads fresh content');
    assertSameValue($canonicalRules, file_get_contents($contentPath . '/.htaccess'), 'Sync preserves canonical HTTP denial without downloading a remote variant');
    assertSameValue(false, is_file($fixture . '/escape.txt'), 'Traversal cannot write outside content');
    assertSameValue(false, is_dir($fixture . '/outside'), 'Traversal cannot create a folder outside content');
    assertSameValue(2, $stats['files_deleted'], 'Protection file is excluded from destructive deletion statistics');
    assertSameValue(1, $stats['files_downloaded'], 'Only the safe file reaches the download path');
    assertSameValue(strlen($freshContent), $stats['bytes_transferred'], 'Rejected names contribute no transferred bytes');
    assertSameValue(['fresh'], $drive->downloadedIds, 'Reserved and traversal names are rejected before download');

    $progressContent = $fixture . DIRECTORY_SEPARATOR . 'progress-content';
    mkdir($progressContent, 0700, true);
    $progressStats = ['folders_created' => 0, 'files_downloaded' => 0, 'files_deleted' => 0, 'bytes_transferred' => 0];
    $progressDownload = new ReflectionMethod($controller, 'downloadFolderWithProgress');
    $progressDownload->setAccessible(true);
    $sendEvent = static function (): void {
    };
    $progressArguments = ['root', $progressContent, &$progressStats, $sendEvent];
    $drive->downloadedIds = [];
    $progressDownload->invokeArgs($controller, $progressArguments);
    assertSameValue(['fresh'], $drive->downloadedIds, 'Progress sync applies the same pre-download validation');
    assertSameValue(1, $progressStats['files_downloaded'], 'Progress sync downloads only the safe file');

    echo "SyncContentProtectionTest: OK\n";
} finally {
    removeSyncFixture($fixture);
}
