<?php

namespace Modules\SignatureCleaner\Services;

class AgranaSignatureCleaner
{
    public function clean($body)
    {
        if (!is_string($body) || trim($body) === '') {
            return $body;
        }

        if ($this->looksLikeHtml($body)) {
            return $this->cleanHtml($body);
        }

        return $this->cleanPlainText($body);
    }

    private function looksLikeHtml($body)
    {
        return preg_match('/<\s*(html|body|div|table|p|br|span|a|img)\b/i', $body) === 1;
    }

    private function cleanHtml($html)
    {
        $original = $html;

        $previous = libxml_use_internal_errors(true);

        $dom = new \DOMDocument('1.0', 'UTF-8');

        $wrapped = '<?xml encoding="UTF-8">' . $html;

        if (!$dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            return $this->cleanHtmlFallback($original);
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($dom);

        $tables = $xpath->query('//table');

        $nodesToRemove = [];

        foreach ($tables as $table) {
            $text = $this->normalizeText($table->textContent);

            if ($this->isAgranaSignatureText($text)) {
                $nodesToRemove[] = $table;

                $next = $this->nextElementSibling($table);
                if ($next && $this->isBannerNode($next)) {
                    $nodesToRemove[] = $next;
                }

                $parent = $table->parentNode;
                if ($parent instanceof \DOMElement) {
                    $parentNext = $this->nextElementSibling($parent);
                    if ($parentNext && $this->isBannerNode($parentNext)) {
                        $nodesToRemove[] = $parentNext;
                    }
                }
            }
        }

        $imgs = $xpath->query('//img');

        foreach ($imgs as $img) {
            $alt = $img->getAttribute('alt');
            $src = $img->getAttribute('src');

            if ($this->contains($alt, 'Agrana Fruit in Fashion Banner') ||
                $this->contains($src, 'image006') ||
                $this->contains($src, 'agrana')) {
                $bannerContainer = $this->closestBlock($img);
                if ($bannerContainer) {
                    $nodesToRemove[] = $bannerContainer;
                }
            }
        }

        $this->removeNodes($nodesToRemove);

        $this->removeEmptyBlocks($dom, $xpath);

        $cleaned = $dom->saveHTML();

        $cleaned = preg_replace('/^<\?xml[^>]+>\s*/i', '', $cleaned);

        if (trim(strip_tags($cleaned)) === '' && trim(strip_tags($original)) !== '') {
            return $original;
        }

        return trim($cleaned);
    }

    private function cleanHtmlFallback($html)
    {
        $pattern = '/<table\b[^>]*>(?:(?!<\/table>).)*AGRANA Fruit Moscow region LLC(?:(?!<\/table>).)*<\/table>/isu';
        $html = preg_replace($pattern, '', $html);

        $bannerPattern = '/<p\b[^>]*>.*?Agrana Fruit in Fashion Banner.*?<\/p>/isu';
        $html = preg_replace($bannerPattern, '', $html);

        return trim($html);
    }

    private function cleanPlainText($text)
    {
        $normalized = str_replace("\xC2\xA0", ' ', $text);
        $lines = preg_split('/\R/u', $normalized);

        if (!$lines) {
            return $text;
        }

        $companyLineIndex = null;

        foreach ($lines as $i => $line) {
            if ($this->contains($line, 'AGRANA Fruit Moscow region LLC')) {
                $companyLineIndex = $i;
                break;
            }
        }

        if ($companyLineIndex === null) {
            return $text;
        }

        $tail = implode("\n", array_slice($lines, $companyLineIndex));

        if (!$this->isAgranaSignatureText($tail)) {
            return $text;
        }

        $start = $companyLineIndex;

        for ($i = $companyLineIndex - 1; $i >= max(0, $companyLineIndex - 4); $i--) {
            $line = trim($lines[$i]);

            if ($line === '') {
                $start = $i;
                continue;
            }

            if (preg_match('/^[A-ZА-ЯЁ][a-zа-яё]+ [A-ZА-ЯЁ]{2,}\s*\|/u', $line)) {
                $start = $i;
                break;
            }
        }

        $before = array_slice($lines, 0, $start);
        $cleaned = trim(implode("\n", $before));

        return $cleaned === '' ? $text : $cleaned;
    }

    private function isAgranaSignatureText($text)
    {
        $score = 0;

        $markers = [
            'AGRANA Fruit Moscow region LLC',
            'Festivalnaya street',
            'Serpukhov',
            'Phone:',
            'Mobile:',
            'E-Mail:',
            'agrana.com',
            'Privacy Principles',
        ];

        foreach ($markers as $marker) {
            if ($this->contains($text, $marker)) {
                $score++;
            }
        }

        return $score >= 4;
    }

    private function isBannerNode(\DOMNode $node)
    {
        if (!$node instanceof \DOMElement) {
            return false;
        }

        $html = $this->nodeHtml($node);
        $text = $this->normalizeText($node->textContent);

        return $this->contains($html, 'Agrana Fruit in Fashion Banner')
            || $this->contains($html, 'trendblog.agrana.com')
            || $this->contains($html, 'Fruit in Fashion')
            || $this->contains($text, 'Agrana Fruit in Fashion Banner');
    }

    private function nextElementSibling(\DOMNode $node)
    {
        $next = $node->nextSibling;

        while ($next) {
            if ($next instanceof \DOMElement) {
                return $next;
            }

            if ($next instanceof \DOMText && trim($next->textContent) !== '') {
                return null;
            }

            $next = $next->nextSibling;
        }

        return null;
    }

    private function closestBlock(\DOMNode $node)
    {
        $blockTags = ['p', 'div', 'table', 'td'];

        $current = $node;

        while ($current && $current->parentNode) {
            if ($current instanceof \DOMElement) {
                $tag = strtolower($current->tagName);

                if (in_array($tag, $blockTags, true)) {
                    return $current;
                }
            }

            $current = $current->parentNode;
        }

        return null;
    }

    private function removeNodes(array $nodes)
    {
        $removed = [];

        foreach ($nodes as $node) {
            if (!$node instanceof \DOMNode || !$node->parentNode) {
                continue;
            }

            $hash = spl_object_hash($node);
            if (isset($removed[$hash])) {
                continue;
            }

            $node->parentNode->removeChild($node);
            $removed[$hash] = true;
        }
    }

    private function removeEmptyBlocks(\DOMDocument $dom, \DOMXPath $xpath)
    {
        $changed = true;

        while ($changed) {
            $changed = false;

            $nodes = $xpath->query('//p|//div|//span');

            foreach ($nodes as $node) {
                if (!$node instanceof \DOMElement || !$node->parentNode) {
                    continue;
                }

                $text = trim($this->normalizeText($node->textContent));
                $hasImg = $node->getElementsByTagName('img')->length > 0;
                $hasTable = $node->getElementsByTagName('table')->length > 0;

                if ($text === '' && !$hasImg && !$hasTable) {
                    $node->parentNode->removeChild($node);
                    $changed = true;
                }
            }
        }
    }

    private function nodeHtml(\DOMNode $node)
    {
        $html = '';

        if (!$node->ownerDocument) {
            return $html;
        }

        $html .= $node->ownerDocument->saveHTML($node);

        return $html;
    }

    private function normalizeText($text)
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xC2\xA0", ' ', $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace('/\R+/u', "\n", $text);

        return trim($text);
    }

    private function contains($haystack, $needle)
    {
        return mb_stripos((string) $haystack, (string) $needle, 0, 'UTF-8') !== false;
    }
}