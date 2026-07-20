<?php

declare(strict_types=1);

/**
 * Minimal, dependency-free Markdown → HTML renderer used for folder intro cards.
 *
 * Supports: ATX headings, bold/italic, inline code, fenced code blocks, links,
 * images, unordered/ordered lists, blockquotes, horizontal rules and paragraphs.
 * Input is treated as untrusted: everything is HTML-escaped before markup is
 * reintroduced, and URLs are restricted to safe schemes (no javascript:/data:).
 */
if (!function_exists('renderMarkdown')) {
    function renderMarkdown(string $markdown): string
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);

        // 1. Pull fenced code blocks out first so their content is left verbatim.
        $codeBlocks = [];
        $markdown = preg_replace_callback('/```[ \t]*[a-zA-Z0-9_-]*\n(.*?)\n?```/s', static function (array $m) use (&$codeBlocks): string {
            $placeholder = "\x00CB" . count($codeBlocks) . "\x00";
            $codeBlocks[$placeholder] = '<pre class="md-code"><code>' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '</code></pre>';
            return $placeholder;
        }, $markdown) ?? $markdown;

        $lines = explode("\n", $markdown);
        $html = [];
        $listType = null;     // 'ul' | 'ol' | null
        $paragraph = [];

        $flushParagraph = static function () use (&$paragraph, &$html): void {
            if ($paragraph !== []) {
                $html[] = '<p>' . renderMarkdownInline(implode(' ', $paragraph)) . '</p>';
                $paragraph = [];
            }
        };
        $closeList = static function () use (&$listType, &$html): void {
            if ($listType !== null) {
                $html[] = '</' . $listType . '>';
                $listType = null;
            }
        };

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // A code-block placeholder sitting on its own line
            if (preg_match('/^\x00CB\d+\x00$/', $trimmed) === 1) {
                $flushParagraph();
                $closeList();
                $html[] = $trimmed;
                continue;
            }

            if ($trimmed === '') {
                $flushParagraph();
                $closeList();
                continue;
            }

            // Horizontal rule
            if (preg_match('/^(-{3,}|\*{3,}|_{3,})$/', $trimmed) === 1) {
                $flushParagraph();
                $closeList();
                $html[] = '<hr>';
                continue;
            }

            // ATX headings (# .. ######)
            if (preg_match('/^(#{1,6})\s+(.*)$/', $trimmed, $m) === 1) {
                $flushParagraph();
                $closeList();
                $level = strlen($m[1]);
                $html[] = '<h' . $level . '>' . renderMarkdownInline($m[2]) . '</h' . $level . '>';
                continue;
            }

            // Blockquote
            if (preg_match('/^>\s?(.*)$/', $trimmed, $m) === 1) {
                $flushParagraph();
                $closeList();
                $html[] = '<blockquote>' . renderMarkdownInline($m[1]) . '</blockquote>';
                continue;
            }

            // Unordered list item
            if (preg_match('/^[-*+]\s+(.*)$/', $trimmed, $m) === 1) {
                $flushParagraph();
                if ($listType !== 'ul') {
                    $closeList();
                    $html[] = '<ul>';
                    $listType = 'ul';
                }
                $html[] = '<li>' . renderMarkdownInline($m[1]) . '</li>';
                continue;
            }

            // Ordered list item
            if (preg_match('/^\d+\.\s+(.*)$/', $trimmed, $m) === 1) {
                $flushParagraph();
                if ($listType !== 'ol') {
                    $closeList();
                    $html[] = '<ol>';
                    $listType = 'ol';
                }
                $html[] = '<li>' . renderMarkdownInline($m[1]) . '</li>';
                continue;
            }

            // Plain text: accumulate into the current paragraph
            $closeList();
            $paragraph[] = $trimmed;
        }
        $flushParagraph();
        $closeList();

        $out = implode("\n", $html);
        if ($codeBlocks !== []) {
            $out = strtr($out, $codeBlocks); // restore fenced code blocks
        }
        return $out;
    }

    /** Inline span rendering: escape first, then code / images / links / bold / italic. */
    function renderMarkdownInline(string $text): string
    {
        // Inline code is extracted first so its content is not reformatted.
        $codes = [];
        $text = preg_replace_callback('/`([^`]+)`/', static function (array $m) use (&$codes): string {
            $ph = "\x00IC" . count($codes) . "\x00";
            $codes[$ph] = '<code>' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '</code>';
            return $ph;
        }, $text) ?? $text;

        // Everything else is escaped before we reintroduce safe markup.
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        // Images ![alt](src) — handled before links
        $text = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/', static function (array $m): string {
            $src = mdSafeUrl($m[2]);
            return $src === null
                ? htmlspecialchars($m[0], ENT_QUOTES, 'UTF-8')
                : '<img src="' . $src . '" alt="' . $m[1] . '" loading="lazy">';
        }, $text) ?? $text;

        // Links [text](url)
        $text = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', static function (array $m): string {
            $href = mdSafeUrl($m[2]);
            if ($href === null) {
                return htmlspecialchars($m[0], ENT_QUOTES, 'UTF-8');
            }
            $rel = preg_match('#^https?:#i', $m[2]) === 1 ? ' target="_blank" rel="noopener"' : '';
            return '<a href="' . $href . '"' . $rel . '>' . $m[1] . '</a>';
        }, $text) ?? $text;

        // Bold, then italic
        $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/__([^_]+)__/', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $text) ?? $text;
        $text = preg_replace('/(?<!_)_([^_]+)_(?!_)/', '<em>$1</em>', $text) ?? $text;

        if ($codes !== []) {
            $text = strtr($text, $codes);
        }
        return $text;
    }

    /**
     * Returns the URL (already HTML-escaped upstream) when it is safe, or null to
     * reject it. Allows http(s) and mailto absolute links, plus any relative or
     * root-relative path (e.g. "index.html", "../guide", "/skill/x", "#anchor").
     * Rejects javascript:/data:/vbscript: and any other explicit scheme.
     */
    function mdSafeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        $decoded = html_entity_decode($url);
        if (preg_match('#^\s*(javascript|data|vbscript):#i', $decoded) === 1) {
            return null;
        }
        if (preg_match('#^(https?://|mailto:)#i', $url) === 1) {
            return $url; // already escaped by the caller
        }
        // Any other explicit "scheme:" is rejected; everything else is relative.
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url) === 1) {
            return null;
        }
        return $url; // relative / root-relative / anchor link
    }
}
