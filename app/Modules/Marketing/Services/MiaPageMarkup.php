<?php
/**
 * MiaPageMarkup — the small, safe text format a generated page is written, edited and approved in.
 *
 *   ## Heading            -> <h2>
 *   ### Question          -> <h3>        (under a heading containing "questions" the pairs also become the page's FAQ)
 *   - item                -> <ul><li>    (consecutive lines)
 *   anything else         -> <p>         (paragraphs are separated by a blank line)
 *
 * Everything is escaped on the way to HTML and there is no way to write a tag, a link, an image or a script, so neither the model
 * nor an edit can put markup on the website. Pure: no database. PHP 7.4-compatible.
 */
declare(strict_types=1);

final class MiaPageMarkup
{
    public const MAX_CHARS = 12000;

    /** Plain text only: no control characters, no angle brackets. @return string[] problems */
    public static function problems(string $text): array
    {
        $p = [];
        $n = mb_strlen($text, 'UTF-8');
        if (trim($text) === '') { $p[] = 'The page is empty'; }
        if ($n > self::MAX_CHARS) { $p[] = 'The page is longer than ' . self::MAX_CHARS . ' characters'; }
        if (preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f<>]/u', $text)) { $p[] = 'The page contains characters that are not allowed (no HTML)'; }
        if (preg_match('#https?://|www\.#i', $text)) { $p[] = 'The page contains a web address (links are added by Mia, not typed)'; }
        return $p;
    }

    /** Tidy line endings and spacing so the same text always hashes the same. */
    public static function normalise(string $text): string
    {
        $t = str_replace(["\r\n", "\r"], "\n", $text);
        $t = preg_replace("/[ \t]+\n/", "\n", $t) ?? $t;
        $t = preg_replace("/\n{3,}/", "\n\n", $t) ?? $t;
        return trim($t);
    }

    /** @return array<int,array{type:string,text?:string,items?:string[]}> blocks */
    public static function blocks(string $text): array
    {
        $blocks = []; $para = []; $list = [];
        $flush = function () use (&$blocks, &$para, &$list): void {
            if ($para) { $blocks[] = ['type' => 'p', 'text' => trim(implode(' ', $para))]; $para = []; }
            if ($list) { $blocks[] = ['type' => 'ul', 'items' => $list]; $list = []; }
        };
        foreach (explode("\n", self::normalise($text)) as $line) {
            $l = rtrim($line);
            if ($l === '') { $flush(); continue; }
            if (preg_match('/^###\s+(.+)$/u', $l, $m)) { $flush(); $blocks[] = ['type' => 'h3', 'text' => trim($m[1])]; continue; }
            if (preg_match('/^##\s+(.+)$/u', $l, $m))  { $flush(); $blocks[] = ['type' => 'h2', 'text' => trim($m[1])]; continue; }
            if (preg_match('/^[-*]\s+(.+)$/u', $l, $m)) { if ($para) { $blocks[] = ['type' => 'p', 'text' => trim(implode(' ', $para))]; $para = []; } $list[] = trim($m[1]); continue; }
            if ($list) { $blocks[] = ['type' => 'ul', 'items' => $list]; $list = []; }
            $para[] = trim($l);
        }
        $flush();
        return $blocks;
    }

    /**
     * @param array $extraHtml already-escaped HTML sections appended by Mia (recent work, related services)
     * @return array{html:string, faq:array<int,array{question:string,answer:string}>}
     */
    public static function render(string $text, string $extraHtml = ''): array
    {
        $e = function (string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
        $html = ''; $faq = []; $inFaq = false; $q = null; $a = [];
        $closeQ = function () use (&$faq, &$q, &$a): void {
            if ($q !== null && $a) { $faq[] = ['question' => $q, 'answer' => implode(' ', $a)]; }
            $q = null; $a = [];
        };
        foreach (self::blocks($text) as $b) {
            switch ($b['type']) {
                case 'h2': $closeQ(); $inFaq = (bool)preg_match('/question/i', $b['text']); $html .= '<h2>' . $e($b['text']) . "</h2>\n"; break;
                case 'h3': $closeQ(); if ($inFaq) { $q = $b['text']; } $html .= '<h3>' . $e($b['text']) . "</h3>\n"; break;
                case 'ul': $html .= "<ul>\n"; foreach ($b['items'] as $i) { $html .= '<li>' . $e($i) . "</li>\n"; } $html .= "</ul>\n"; if ($inFaq && $q !== null) { $a[] = implode('; ', $b['items']); } break;
                default:   $html .= '<p>' . $e($b['text']) . "</p>\n"; if ($inFaq && $q !== null) { $a[] = $b['text']; }
            }
        }
        $closeQ();
        return ['html' => $html . $extraHtml, 'faq' => $faq];
    }

    /** Words in the page, headings included. */
    public static function wordCount(string $text): int
    {
        return count(preg_split('/\s+/u', trim(preg_replace('/^(#{2,3}|[-*])\s+/m', '', $text) ?? $text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
}
