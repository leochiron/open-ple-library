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
            $canonicalHomepage = $this->config['seo_canonical_homepage'] ?? null;
            $seoIndexable = $relativePath === ''
                && is_string($canonicalHomepage)
                && $canonicalHomepage !== '';
            if ($seoIndexable) {
                header('X-Robots-Tag: index, follow');
            }
            $entries = array_map(function (array $entry): array {
                $entry['display_size'] = $entry['is_dir']
                    ? '—'
                    : $this->fileSystem->getHumanFileSize($entry['size']);
                return $entry;
            }, $this->fileSystem->listDirectory($absolutePath));

            // Folder intro card: hide the special file from the listing and
            // render its content above the file list (see renderFolderIntro).
            $entries = array_values(array_filter($entries, static function (array $entry): bool {
                return !in_array(strtolower($entry['name']), ['_intro.html', '_intro.md'], true);
            }));

            render('library/folder', [
                'relativePath' => $relativePath,
                'entries' => $entries,
                'introHtml' => $this->renderFolderIntro($absolutePath),
                'breadcrumbs' => $this->buildBreadcrumbs($relativePath),
                'parentPath' => $this->getParentPath($relativePath),
                'title' => $this->i18n->t('folder.heading'),
                'seoIndexable' => $seoIndexable,
                'canonicalUrl' => $seoIndexable ? $canonicalHomepage . '/' : null,
            ], $this->i18n, $this->config);
            return;
        }

        $filename = basename($absolutePath);
        $mime = $this->mime->getMimeType($absolutePath);
        
        // Vérifier si c'est un fichier PHP contenant une redirection
        if (pathinfo($absolutePath, PATHINFO_EXTENSION) === 'php') {
            $redirectUrl = $this->extractRedirectUrl($absolutePath);
            if ($redirectUrl !== null) {
                // Afficher la page de redirection
                render('library/redirect', [
                    'redirectUrl' => $redirectUrl,
                    'filename' => $filename,
                    'breadcrumbs' => $this->buildBreadcrumbs($relativePath),
                ], $this->i18n, $this->config);
                return;
            }
        }
        
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
     * Folder intro card. If the directory contains an intro file, returns its
     * HTML to be displayed above the file listing — empty string otherwise.
     *   _intro.html : trusted HTML fragment, injected as-is (full design control)
     *   _intro.md   : Markdown, rendered to safe HTML
     * _intro.html wins when both are present.
     */
    private function renderFolderIntro(string $absoluteDir): string
    {
        $htmlFile = $absoluteDir . DIRECTORY_SEPARATOR . '_intro.html';
        if (is_file($htmlFile)) {
            $raw = (string)file_get_contents($htmlFile);
            return $this->ensureUtf8($raw);
        }

        $mdFile = $absoluteDir . DIRECTORY_SEPARATOR . '_intro.md';
        if (is_file($mdFile)) {
            $raw = $this->ensureUtf8((string)file_get_contents($mdFile));
            return renderMarkdown($raw);
        }

        return '';
    }

    /** Best-effort conversion to UTF-8 (intro files may be saved as Windows-1252). */
    private function ensureUtf8(string $text): string
    {
        $text = (string)preg_replace('/^\xEF\xBB\xBF/', '', $text); // strip BOM
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }
        return $text;
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

    /**
     * Extrait l'URL de redirection d'un fichier PHP s'il en contient une
     * Format attendu : $url = 'https://...';
     */
    private function extractRedirectUrl(string $filePath): ?string
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        // Rechercher le pattern : $url = '...';
        if (preg_match('/\$url\s*=\s*[\'"]([^\'"]+)[\'"];/', $content, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
