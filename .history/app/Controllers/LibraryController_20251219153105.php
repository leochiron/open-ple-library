<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\FileSystemService;
use App\Services\I18nService;
use App\Services\MimeService;
use App\Services\SecurityService;
use InvalidArgumentException;
use RuntimeException;

class LibraryController
{
    private FileSystemService $fileSystem;
    private MimeService $mime;
    private SecurityService $security;
    private I18nService $i18n;
    private array $config;

    public function __construct(
        FileSystemService $fileSystem,
        MimeService $mime,
        SecurityService $security,
        I18nService $i18n,
        array $config
    ) {
        $this->fileSystem = $fileSystem;
        $this->mime = $mime;
        $this->security = $security;
        $this->i18n = $i18n;
        $this->config = $config;
    }

    public function browse(string $relativePath): void
    {
        $absolutePath = $this->fileSystem->resolvePath($relativePath);

        if (!file_exists($absolutePath)) {
            throw new RuntimeException('Path not found');
        }

        if (is_dir($absolutePath)) {
            $entries = array_map(function (array $entry): array {
                $entry['display_size'] = $entry['is_dir']
                    ? '—'
                    : $this->fileSystem->getHumanFileSize($entry['size']);
                return $entry;
            }, $this->fileSystem->listDirectory($absolutePath));
            render('library/folder', [
                'relativePath' => $relativePath,
                'entries' => $entries,
                'breadcrumbs' => $this->buildBreadcrumbs($relativePath),
                'parentPath' => $this->getParentPath($relativePath),
                'title' => $this->i18n->t('folder.heading'),
            ], $this->i18n, $this->config);
            return;
        }

        $filename = basename($absolutePath);
        $mime = $this->mime->getMimeType($absolutePath);
        $previewable = $this->mime->isPreviewable($mime);

        render('library/file', [
            'relativePath' => $relativePath,
            'filename' => $filename,
            'mime' => $mime,
            'isPreviewable' => $previewable,
            'size' => $this->fileSystem->getHumanFileSize((int) filesize($absolutePath)),
            'modified' => (int) filemtime($absolutePath),
            'breadcrumbs' => $this->buildBreadcrumbs($relativePath),
        ], $this->i18n, $this->config);
    }

    /**
     * @return array<int, array{label:string,path:string|null}>
     */
    private function buildBreadcrumbs(string $relativePath): array
    {
        $breadcrumbs = [];
        $breadcrumbs[] = [
            'label' => $this->i18n->t('nav.root'),
            'path' => '',
        ];

        if ($relativePath === '') {
            return $breadcrumbs;
        }

        $segments = explode('/', $relativePath);
        $current = '';
        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }
            $current = $current === '' ? $segment : $current . '/' . $segment;
            $breadcrumbs[] = [
                'label' => $segment,
                'path' => $current,
            ];
        }

        return $breadcrumbs;
    }

    private function getParentPath(string $relativePath): ?string
    {
        if ($relativePath === '') {
            return null;
        }

        $parent = dirname($relativePath);
        return $parent === '.' ? '' : $parent;
    }
}
