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

            return $this->cleanHtmlFallback($html, $signatureRemoved);
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($dom);

        $signatureNodes = $xpath->query('//*[@id="Signature" or @id="signature"]');

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

    private function isAgranaSignatureNode(\DOMElement $node)
    {
        $text = $this->normalizeText($node->textContent);
        $html = $this->nodeHtml($node);
        if (
            $this->contains($text, 'Disclaimer: This message contains confidential information')
            && $this->contains($text, 'AGRANA Fruit Moscow region')
        ) {
            return true;
        }

        if ($this->isAgranaSignatureText($text)) {
            return true;
        }

        if (
            $this->contains($html, 'C2_signature_')
            || $this->contains($html, 'signaturelogo_default')
            || $this->contains($html, 'af_banner_fruitinfashion')
            || $this->contains($html, 'Agrana Fruit in Fashion Banner')
            || $this->contains($html, 'trendblog.agrana.com')
        ) {
            return true;
        }

        return false;
    }

    public function filterSignatureAttachments($attachments)
    {
        if (!$attachments || !is_iterable($attachments)) {
            return $attachments;
        }

        $filtered = [];

        foreach ($attachments as $attachment) {
            if (!$this->isSignatureAttachment($attachment)) {
                $filtered[] = $attachment;
            }
        }

        return $filtered;
    }

    private function cleanHtmlFallback($html)
    {
        $pattern = '/<table\b[^>]*>(?:(?!<\/table>).)*AGRANA Fruit Moscow region LLC(?:(?!<\/table>).)*<\/table>/isu';
        $html = preg_replace($pattern, '', $html);

        $bannerPattern = '/<p\b[^>]*>.*?Agrana Fruit in Fashion Banner.*?<\/p>/isu';
        $html = preg_replace($bannerPattern, '', $html);

        return trim($html);
    }
    private function isAgranaSignatureImage(\DOMElement $img)
    {
        $alt = $img->getAttribute('alt');
        $src = $img->getAttribute('src');
        $id = $img->getAttribute('id');

        $haystack = $alt . ' ' . $src . ' ' . $id;

        return $this->contains($haystack, 'Agrana Fruit in Fashion Banner')
            || $this->contains($haystack, 'C2_signature_')
            || $this->contains($haystack, 'signaturelogo_default')
            || $this->contains($haystack, 'af_banner_fruitinfashion')
            || $this->contains($haystack, 'Fruit in Fashion');
    }

    private function isSignatureAttachment($attachment)
    {
        $name = '';

        if (is_object($attachment) && method_exists($attachment, 'getName')) {
            $name = (string) $attachment->getName();
        } elseif (is_object($attachment) && isset($attachment->name)) {
            $name = (string) $attachment->name;
        }

        $id = '';

        if (is_object($attachment) && isset($attachment->id)) {
            $id = (string) $attachment->id;
        }

        $contentType = '';

        if (is_object($attachment) && method_exists($attachment, 'getMimeType')) {
            $contentType = (string) $attachment->getMimeType();
        } elseif (is_object($attachment) && isset($attachment->content_type)) {
            $contentType = (string) $attachment->content_type;
        }

        $haystack = $name . ' ' . $id . ' ' . $contentType;

        return $this->contains($haystack, 'C2_signature_')
            || $this->contains($haystack, 'signaturelogo_default')
            || $this->contains($haystack, 'af_banner_fruitinfashion')
            || $this->contains($haystack, 'fruitinfashion')
            || $this->contains($haystack, 'signature_signaturelogo');
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

    public function cleanWithResult($body)
{
    $result = [
        'body' => $body,
        'signature_removed' => false,
    ];

    if (!is_string($body) || trim($body) === '') {
        return $result;
    }

    if (!$this->looksLikeHtml($body)) {
        $cleaned = $this->cleanPlainText($body);

        return [
            'body' => $cleaned,
            'signature_removed' => $cleaned !== $body,
        ];
    }

    $cleaned = $this->cleanHtml($body, $signatureRemoved);

    return [
        'body' => $cleaned,
        'signature_removed' => $signatureRemoved,
    ];
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