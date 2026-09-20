<?php
/**
 * Safe, deliberately small renderer for M12 restricted Markdown.
 *
 * It owns presentation only. ContentPages remains the sole data source. Raw
 * HTML is always escaped and only the agreed block and inline elements can be
 * emitted.
 */
final class ContentRenderer
{
    /** Render supported Markdown and return its deterministic heading index. */
    public static function render(string $markdown): array
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown));
        $html = [];
        $headings = [];
        $usedAnchors = [];
        $count = count($lines);

        for ($index = 0; $index < $count;) {
            $line = $lines[$index];
            if (trim($line) === '') {
                $index++;
                continue;
            }

            if (preg_match('/^(##|###)\s+(.+?)\s*$/u', $line, $match)) {
                $level = strlen($match[1]);
                $plain = self::plainInline($match[2]);
                $anchor = self::uniqueAnchor($plain, $usedAnchors);
                $headings[] = ['level' => $level, 'text' => $plain, 'id' => $anchor];
                $class = $level === 2
                    ? 'mt-10 scroll-mt-24 font-editorial text-okv-h6 text-ink first:mt-0 md:text-okv-h5'
                    : 'mt-8 scroll-mt-24 text-xl font-bold text-ink';
                $html[] = '<h' . $level . ' id="' . self::escape($anchor) . '" class="' . $class . '">'
                    . self::inline($match[2]) . '</h' . $level . '>';
                $index++;
                continue;
            }

            if (preg_match('/^\s*([-*])\s+(.+)$/u', $line)) {
                $items = [];
                while ($index < $count && preg_match('/^\s*[-*]\s+(.+)$/u', $lines[$index], $match)) {
                    $items[] = '<li>' . self::inline($match[1]) . '</li>';
                    $index++;
                }
                $html[] = '<ul class="mt-5 list-disc space-y-2 pl-6 text-ink-60">' . implode('', $items) . '</ul>';
                continue;
            }

            if (preg_match('/^\s*\d+[.)]\s+(.+)$/u', $line)) {
                $items = [];
                while ($index < $count && preg_match('/^\s*\d+[.)]\s+(.+)$/u', $lines[$index], $match)) {
                    $items[] = '<li>' . self::inline($match[1]) . '</li>';
                    $index++;
                }
                $html[] = '<ol class="mt-5 list-decimal space-y-2 pl-6 text-ink-60">' . implode('', $items) . '</ol>';
                continue;
            }

            if (preg_match('/^>\s?(.*)$/u', $line)) {
                $quote = [];
                while ($index < $count && preg_match('/^>\s?(.*)$/u', $lines[$index], $match)) {
                    $quote[] = $match[1];
                    $index++;
                }
                $html[] = '<blockquote class="mt-6 border-l-4 border-gold pl-5 font-medium text-ink">'
                    . self::inline(trim(implode(' ', $quote))) . '</blockquote>';
                continue;
            }

            $paragraph = [];
            while ($index < $count && trim($lines[$index]) !== ''
                && !preg_match('/^(?:##|###)\s+/u', $lines[$index])
                && !preg_match('/^\s*(?:[-*]|\d+[.)])\s+/u', $lines[$index])
                && !preg_match('/^>\s?/u', $lines[$index])) {
                $paragraph[] = trim($lines[$index]);
                $index++;
            }
            $html[] = '<p class="mt-5 leading-7 text-ink-60">'
                . self::inline(trim(implode(' ', $paragraph))) . '</p>';
        }

        return ['html' => implode("\n", $html), 'headings' => $headings];
    }

    /** Plain fallback used for metadata and safe summaries. */
    public static function plainText(string $markdown, int $limit = 0): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $markdown);
        $text = preg_replace('/(?<!!)\[([^\]]+)\]\([^)]*\)/u', '$1', $text) ?? $text;
        $text = preg_replace('/^\s*(?:#{1,6}|>|[-*]|\d+[.)])\s*/mu', '', $text) ?? $text;
        $text = str_replace(['**', '__', '*', '_'], '', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        return $limit > 0 ? mb_substr($text, 0, $limit) : $text;
    }

    private static function inline(string $text): string
    {
        $pattern = '/\[([^\]\n]+)\]\(([^)\s]+)\)|\*\*([^*\n]+)\*\*|__([^_\n]+)__|\*([^*\n]+)\*|_([^_\n]+)_/u';
        $output = '';
        $offset = 0;
        while (preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $token = $match[0][0];
            $position = $match[0][1];
            $output .= self::escape(substr($text, $offset, $position - $offset));
            if (($match[1][1] ?? -1) >= 0) {
                $label = (string) $match[1][0];
                $destination = html_entity_decode((string) $match[2][0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (self::safeDestination($destination)) {
                    $external = preg_match('#^https?://#i', $destination) === 1;
                    $output .= '<a href="' . self::escape($destination) . '" class="font-semibold text-forest underline underline-offset-2"'
                        . ($external ? ' rel="external noopener noreferrer"' : '') . '>' . self::inline($label) . '</a>';
                } else {
                    $output .= self::escape($token);
                }
            } elseif (($match[3][1] ?? -1) >= 0 || ($match[4][1] ?? -1) >= 0) {
                $value = ($match[3][1] ?? -1) >= 0 ? $match[3][0] : $match[4][0];
                $output .= '<strong>' . self::escape((string) $value) . '</strong>';
            } else {
                $value = ($match[5][1] ?? -1) >= 0 ? $match[5][0] : $match[6][0];
                $output .= '<em>' . self::escape((string) $value) . '</em>';
            }
            $offset = $position + strlen($token);
        }
        return $output . self::escape(substr($text, $offset));
    }

    private static function safeDestination(string $destination): bool
    {
        if ($destination === '' || preg_match('/[\x00-\x20\x7f]/', $destination)) {
            return false;
        }
        if (str_starts_with($destination, '/') && !str_starts_with($destination, '//') && !str_contains($destination, '..')) {
            return true;
        }
        return in_array(strtolower((string) parse_url($destination, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    private static function plainInline(string $text): string
    {
        return self::plainText($text);
    }

    private static function uniqueAnchor(string $heading, array &$used): string
    {
        $base = mb_strtolower($heading);
        $base = trim((string) preg_replace('/[^\pL\pN]+/u', '-', $base), '-');
        if ($base === '') {
            $base = 'section';
        }
        $used[$base] = ($used[$base] ?? 0) + 1;
        return $used[$base] === 1 ? $base : $base . '-' . $used[$base];
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
