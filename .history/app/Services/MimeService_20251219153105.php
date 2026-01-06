<?php

declare(strict_types=1);

namespace App\Services;

class MimeService
{
    public function getMimeType(string $absolutePath): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $absolutePath) : null;
        if ($finfo) {
            finfo_close($finfo);
        }

        if ($mime && $mime !== 'application/octet-stream') {
            return $mime;
        }

        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        $map = [
            'pdf' => 'application/pdf',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'mp4' => 'video/mp4',
            'm4v' => 'video/mp4',
        ];

        return $map[$extension] ?? 'application/octet-stream';
    }

    public function isPreviewable(string $mime): bool
    {
        return in_array($mime, ['application/pdf', 'audio/mpeg', 'audio/mp3', 'audio/wav', 'video/mp4'], true);
    }
}
