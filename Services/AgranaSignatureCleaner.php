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

    private function cleanHtml($html, &$signatureRemoved = null)
    {
        $original = $html;
        $signatureRemoved = false;

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
        $nodesToRemove = [];

        $replyForwardHeaders = $xpath->query('//*[@id="divRplyFwdMsg"]');

        foreach ($replyForwardHeaders as $header) {
            if (!$header instanceof \DOMElement || !$this->isOutlookReplyForwardHeaderNode($header)) {
                continue;
            }

            $nodesToRemove[] = $header;

            $separator = $this->previousElementSibling($header);
            if ($separator instanceof \DOMElement && strtolower($separator->tagName) === 'hr') {
                $nodesToRemove[] = $separator;
            }
        }

        foreach ($signatureNodes as $table) {
            $text = $this->normalizeText($table->textContent);

            if ($this->isAgranaSignatureText($text)) {
                $nodesToRemove[] = $table;
                $this->appendAdjacentSignatureNodes($nodesToRemove, $table);

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

        $tables = $xpath->query('//table');

        foreach ($tables as $table) {
            if (!$table instanceof \DOMElement) {
                continue;
            }

            if ($this->isAgranaSignatureText($table->textContent)) {
                $nodesToRemove[] = $table;
                $this->appendAdjacentSignatureNodes($nodesToRemove, $table);
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

        $disclaimers = $xpath->query('//*[contains(translate(normalize-space(.), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "disclaimer: this message contains confidential information")]');

        foreach ($disclaimers as $disclaimer) {
            if (!$disclaimer instanceof \DOMElement) {
                continue;
            }

            if ($this->isDisclaimerNode($disclaimer)) {
                $block = $this->closestBlock($disclaimer);
                if ($block) {
                    $nodesToRemove[] = $block;
                }
            }
        }

        if (!empty($nodesToRemove)) {
            $signatureRemoved = true;
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

    private function cleanHtmlFallback($html, &$signatureRemoved = null)
    {
        $signatureRemoved = false;
        $originalHtml = $html;
        $pattern = '/<table\b[^>]*>(?:(?!<\/table>).)*AGRANA Fruit Moscow region LLC(?:(?!<\/table>).)*<\/table>/isu';
        $html = preg_replace($pattern, '', $html);

        $disclaimerPattern = '/<p\b[^>]*>.*?Disclaimer:\s*This message contains confidential information.*?AGRANA Fruit Moscow region.*?<\/p>/isu';
        $html = preg_replace($disclaimerPattern, '', $html);

        $bannerPattern = '/<p\b[^>]*>.*?Agrana Fruit in Fashion Banner.*?<\/p>/isu';
        $html = preg_replace($bannerPattern, '', $html);

        $replyForwardPattern = '/<hr\b[^>]*>\s*<div\b[^>]*\bid=["\']divRplyFwdMsg["\'][^>]*>.*?<\/div>\s*<\/div>/isu';
        $html = preg_replace($replyForwardPattern, '', $html);

        $replyForwardPattern = '/<div\b[^>]*\bid=["\']divRplyFwdMsg["\'][^>]*>.*?<\/div>\s*<\/div>/isu';
        $html = preg_replace($replyForwardPattern, '', $html);

        $signatureRemoved = $html !== $originalHtml;

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
        $withoutReplyForwardHeaders = $this->removeOutlookReplyForwardHeaderText($normalized);
        $replyForwardHeaderRemoved = $withoutReplyForwardHeaders !== $normalized;
        $lines = preg_split('/\R/u', $withoutReplyForwardHeaders);

        if (!$lines) {
            return $replyForwardHeaderRemoved ? $withoutReplyForwardHeaders : $text;
        }

        $companyLineIndex = null;

        foreach ($lines as $i => $line) {
            if ($this->contains($line, 'AGRANA Fruit Moscow region LLC')) {
                $companyLineIndex = $i;
                break;
            }
        }

        if ($companyLineIndex === null) {
            return $replyForwardHeaderRemoved ? $withoutReplyForwardHeaders : $text;
        }

        $tail = implode("\n", array_slice($lines, $companyLineIndex));

        if (!$this->isAgranaSignatureText($tail)) {
            return $replyForwardHeaderRemoved ? $withoutReplyForwardHeaders : $text;
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

    private function isDisclaimerNode(\DOMNode $node)
    {
        $text = $this->normalizeText($node->textContent);

        return preg_match('/^Disclaimer:\s*This message contains confidential information/iu', $text) === 1
            && $this->contains($text, 'AGRANA Fruit Moscow region');
    }

    private function isOutlookReplyForwardHeaderNode(\DOMElement $node)
    {
        $text = $this->normalizeText($node->textContent);

        return preg_match('/\bFrom:\s*/iu', $text) === 1
            && preg_match('/\bSubject:\s*/iu', $text) === 1
            && (
                preg_match('/\bSent:\s*/iu', $text) === 1
                || preg_match('/\bTo:\s*/iu', $text) === 1
            );
    }

    private function removeOutlookReplyForwardHeaderText($text)
    {
        $lines = preg_split('/\R/u', $text);

        if (!$lines) {
            return $text;
        }

        $remove = array_fill(0, count($lines), false);

        for ($i = 0, $count = count($lines); $i < $count; $i++) {
            if (!$this->isOutlookReplyForwardHeaderLine($lines[$i], 'From')) {
                continue;
            }

            $seen = ['From' => true];
            $end = null;

            for ($j = $i + 1; $j < $count && $j <= $i + 8; $j++) {
                $field = $this->outlookReplyForwardHeaderField($lines[$j]);

                if ($field === null) {
                    break;
                }

                $seen[$field] = true;

                if ($field === 'Subject') {
                    $end = $j;
                    break;
                }
            }

            if ($end === null || empty($seen['Subject']) || (empty($seen['Sent']) && empty($seen['To']))) {
                continue;
            }

            while ($end + 1 < $count && trim($lines[$end + 1]) === '') {
                $end++;
            }

            for ($j = $i; $j <= $end; $j++) {
                $remove[$j] = true;
            }

            $i = $end;
        }

        if (!in_array(true, $remove, true)) {
            return $text;
        }

        $kept = [];

        foreach ($lines as $i => $line) {
            if (!$remove[$i]) {
                $kept[] = $line;
            }
        }

        return implode("\n", $kept);
    }

    private function isOutlookReplyForwardHeaderLine($line, $field)
    {
        return preg_match('/^' . preg_quote($field, '/') . ':\s*\S/iu', trim($line)) === 1;
    }

    private function outlookReplyForwardHeaderField($line)
    {
        $line = trim($line);

        if (preg_match('/^(From|Sent|To|Cc|Subject):\s*\S/iu', $line, $matches) !== 1) {
            return null;
        }

        return ucfirst(strtolower($matches[1]));
    }

    private function appendAdjacentSignatureNodes(array &$nodesToRemove, \DOMNode $signatureNode)
    {
        $next = $this->nextElementSibling($signatureNode);

        if ($next && ($this->isDisclaimerNode($next) || $this->isBannerNode($next))) {
            $nodesToRemove[] = $next;
        }
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

    private function previousElementSibling(\DOMNode $node)
    {
        $previous = $node->previousSibling;

        while ($previous) {
            if ($previous instanceof \DOMElement) {
                return $previous;
            }

            if ($previous instanceof \DOMText && trim($previous->textContent) !== '') {
                return null;
            }

            $previous = $previous->previousSibling;
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

        $signatureRemoved = false;
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
        if (!function_exists('mb_stripos')) {
            return stripos((string) $haystack, (string) $needle) !== false;
        }

        return mb_stripos((string) $haystack, (string) $needle, 0, 'UTF-8') !== false;
    }
}
