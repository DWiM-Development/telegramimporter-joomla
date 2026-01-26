<?php

namespace PlgSystemTelegramimporter\Helper;

use Joomla\CMS\Http\HttpFactory;

class TelegramHelper
{
    private string $botToken;
    private int $channelId;

    public function __construct(string $botToken, int $channelId)
    {
        $this->botToken  = $botToken;
        $this->channelId = $channelId;
    }

    public function getLatestPosts(int $limit = 10, int $channelId = 0, int $lastUpdateId = 0): array
    {
        $params = [
            'allowed_updates' => json_encode(['channel_post']),
            'limit'           => $limit,
        ];
        if ($lastUpdateId > 0) {
            $params['offset'] = $lastUpdateId + 1;
        }

        $url      = 'https://api.telegram.org/bot' . $this->botToken . '/getUpdates?' . http_build_query($params);
        $response = HttpFactory::getHttp()->get($url);
        if ($response->code !== 200) {
            return ['posts' => [], 'last_update_id' => $lastUpdateId];
        }

        $data = json_decode($response->body, true);
        if (!($data['ok'] ?? false)) {
            return ['posts' => [], 'last_update_id' => $lastUpdateId];
        }

        $posts       = [];
        $maxUpdateId = $lastUpdateId;

        foreach ($data['result'] as $update) {
            $maxUpdateId = max($maxUpdateId, (int) ($update['update_id'] ?? 0));

            $message = $update['channel_post'] ?? null;
            if (!$message) {
                continue;
            }

            $chatId    = (int) ($message['chat']['id'] ?? 0);
            $messageId = (int) ($message['message_id'] ?? 0);
            if ($chatId !== $this->channelId) {
                continue;
            }

            $text     = (string) ($message['text'] ?? $message['caption'] ?? '');
            $entities = $message['entities'] ?? $message['caption_entities'] ?? [];

            // Title: first sentence without emoji/HTML
            $sentences = $this->splitSentences($text);
            $title     = $this->makeTitleFromFirstSentence($sentences, $messageId);

            // Sentence positions in the original text
            $ranges = $this->locateSentenceRanges($text, $sentences);

            // Remove the first sentence
            if (!empty($sentences)) {
                array_shift($sentences);
                array_shift($ranges);
            }

            // Build intro/body with correct HTML (line breaks only)
            [$introHtml, $bodyHtml] = $this->buildIntroAndBodyHtml($text, $entities, $sentences, $ranges);

            // Photo
            $imageUrl = null;
            if (!empty($message['photo']) && is_array($message['photo'])) {
                $last   = end($message['photo']);
                $fileId = $last['file_id'] ?? null;
                if ($fileId) {
                    $imageUrl = $this->getPhotoUrl($fileId);
                }
            }

            $posts[] = [
                'id'         => $messageId,
                'message_id' => $messageId,
                'channel_id' => $chatId,
                'title'      => $title,
                'intro'      => $introHtml,
                'body'       => $bodyHtml,
                'image'      => $imageUrl,
            ];
        }

        return ['posts' => $posts, 'last_update_id' => $maxUpdateId];
    }

    private function getPhotoUrl(string $fileId): ?string
    {
        $url      = 'https://api.telegram.org/bot' . $this->botToken . '/getFile?file_id=' . urlencode($fileId);
        $response = HttpFactory::getHttp()->get($url);
        $data     = json_decode($response->body, true);

        return isset($data['result']['file_path'])
            ? 'https://api.telegram.org/file/bot' . $this->botToken . '/' . $data['result']['file_path']
            : null;
    }

    public function downloadImage(string $url): ?string
    {
        try {
            $response = HttpFactory::getHttp()->get($url);
            if ($response->code !== 200) {
                return null;
            }

            $imageData = $response->body;
            $fileName  = 'images/tgimport/' . uniqid('tg_', true) . '.jpg';
            $filePath  = JPATH_ROOT . '/' . $fileName;

            $dir = dirname($filePath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            if (file_put_contents($filePath, $imageData) !== false) {
                return $fileName;
            }
        } catch (\Throwable $e) {
            // Ignore errors
        }

        return null;
    }

    // Minimal: normalize line breaks + <br>
    private function preserveHtmlAndBreaks(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        return nl2br($text, false);
    }

    // Line break is also a sentence boundary
    private function splitSentences(string $text): array
    {
        if ($text === '') {
            return [];
        }
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        preg_match_all('/[^\n]+?(?:[\.!\?\…](?=\s)|\n|$)/u', $text, $m);
        $sentences = array_map(static fn($s) => trim($s), $m[0] ?? []);
        return array_values(array_filter($sentences, static fn($s) => $s !== ''));
    }

    private function makeTitleFromFirstSentence(array $sentences, int $messageId): string
    {
        $first = $sentences[0] ?? '';
        if ($first !== '') {
            $title = $this->sanitizeTitle($first); // Strict: no emoji/pictograms
            if ($title !== '') {
                return $title;
            }
        }
        return 'Post #' . $messageId;
    }

    // Strict for title: remove HTML and all emoji/pictograms
    private function sanitizeTitle(string $s): string
    {
        $s = strip_tags($s);
        $patterns = [
            '/[\x{10000}-\x{10FFFF}]/u',                // non-BMP emoji
            '/[\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u',  // BMP emoji, pictograms
            '/[\x{200D}\x{FE0E}\x{FE0F}\x{20E3}]/u',    // combinators/variation selectors
        ];
        foreach ($patterns as $rx) {
            $s = @preg_replace($rx, '', $s);
        }
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim((string) $s);
    }

    private function locateSentenceRanges(string $text, array $sentences): array
    {
        $ranges = [];
        $cursor = 0;
        foreach ($sentences as $s) {
            $pos = mb_strpos($text, $s, $cursor, 'UTF-8');
            if ($pos === false) {
                $pos = mb_strpos($text, $s, 0, 'UTF-8');
                if ($pos === false) {
                    $ranges[] = [0, 0];
                    continue;
                }
            }
            $len      = mb_strlen($s, 'UTF-8');
            $ranges[] = [$pos, $len];
            $cursor   = $pos + $len;
        }
        return $ranges;
    }

    // How many sentences in the intro
    private function buildIntroAndBodyHtml(string $text, array $entities, array $sentences, array $ranges): array
    {
        if (empty($sentences)) {
            $segment = $this->renderHtmlSegmentByEntities($text, $entities, 0, mb_strlen($text, 'UTF-8'));
            $segment = $this->preserveHtmlAndBreaks(trim($segment));
            $segment = $this->ensureTrailingBr($segment); // Final <br> in the intro
            return [$segment, ''];
        }

        // Intro
        $introCount = min(2, count($sentences));
        $sum        = 0;
        for ($i = 0; $i < $introCount; $i++) {
            $sum += mb_strlen($sentences[$i], 'UTF-8');
        }
        $idx = $introCount;
        while ($sum < 80 && $idx < count($sentences)) {
            $sum += mb_strlen($sentences[$idx], 'UTF-8');
            $idx++;
        }
        $introEndIndex = $idx;

        // Intro
        [$iStart, $iLen] = $this->mergeRangesToSpan(array_slice($ranges, 0, $introEndIndex));
        $introSegment    = $this->renderHtmlSegmentByEntities($text, $entities, $iStart, $iLen);
        $introHtml       = $this->preserveHtmlAndBreaks(trim($introSegment));
        $introHtml       = $this->ensureTrailingBr($introHtml); // Final <br> in the intro

        // Body
        $bodyHtml = '';
        if ($introEndIndex < count($ranges)) {
            [$bStart, $bLen] = $this->mergeRangesToSpan(array_slice($ranges, $introEndIndex));
            $bodySegment     = $this->renderHtmlSegmentByEntities($text, $entities, $bStart, $bLen);
            $bodyHtml        = $this->preserveHtmlAndBreaks(trim($bodySegment));
        }

        return [$introHtml, $bodyHtml];
    }

    private function mergeRangesToSpan(array $ranges): array
    {
        $ranges = array_values(array_filter($ranges, fn($r) => ($r[1] ?? 0) > 0));
        if (empty($ranges)) {
            return [0, 0];
        }
        $start = $ranges[0][0];
        $end   = $ranges[0][0] + $ranges[0][1];
        foreach ($ranges as $r) {
            $s = $r[0];
            $e = $r[0] + $r[1];
            if ($s < $start) $start = $s;
            if ($e > $end)   $end   = $e;
        }
        return [$start, max(0, $end - $start)];
    }

    private function renderHtmlSegmentByEntities(string $text, array $entities, int $startChars, int $lenChars): string
    {
        if ($lenChars <= 0) {
            return '';
        }

        $prefixUtf16Len  = $this->utf16UnitsLength(mb_substr($text, 0, $startChars, 'UTF-8'));
        $segmentUtf8     = mb_substr($text, $startChars, $lenChars, 'UTF-8');
        $segmentUtf16Len = $this->utf16UnitsLength($segmentUtf8);

        $segStart16 = $prefixUtf16Len;
        $segEnd16   = $segStart16 + $segmentUtf16Len;

        $full16 = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');

        $opens  = [];
        $closes = [];

        foreach ($entities as $e) {
            if (!isset($e['type'], $e['offset'], $e['length'])) {
                continue;
            }
            $eStart = (int) $e['offset'];
            $eEnd   = $eStart + (int) $e['length'];

            $start = max($eStart, $segStart16);
            $end   = min($eEnd, $segEnd16);
            if ($end <= $start) {
                continue;
            }

            $relStart = $start - $segStart16;
            $relEnd   = $end - $segStart16;
            $len16    = $relEnd - $relStart;

            $item = [
                'type'     => $e['type'],
                'start'    => $relStart,
                'end'      => $relEnd,
                'length'   => $len16,
                'url'      => $e['url'] ?? null,
                'language' => $e['language'] ?? null,
            ];

            if ($item['type'] === 'url' && empty($item['url'])) {
                $byteStart = $start * 2;
                $byteLen   = ($end - $start) * 2;
                $piece16   = substr($full16, $byteStart, $byteLen);
                $pieceUtf8 = mb_convert_encoding($piece16, 'UTF-8', 'UTF-16LE');
                $item['url'] = $pieceUtf8;
            }

            $opens[$relStart]  ??= [];
            $closes[$relEnd]   ??= [];
            $opens[$relStart][]  = $item;
            $closes[$relEnd][]   = $item;
        }

        foreach ($opens as &$arr) {
            usort($arr, static fn($a, $b) => $b['length'] <=> $a['length']);
        }
        unset($arr);
        foreach ($closes as &$arr) {
            usort($arr, static fn($a, $b) => $a['length'] <=> $b['length']);
        }
        unset($arr);

        $seg16  = mb_convert_encoding($segmentUtf8, 'UTF-16LE', 'UTF-8');
        $units  = (int) (strlen($seg16) / 2);
        $out    = '';
        $stack  = [];
        $inCode = 0;

        for ($i = 0; $i <= $units; $i++) {
            if (!empty($opens[$i])) {
                foreach ($opens[$i] as $ent) {
                    [$openTag, $closeTag, $isCode] = $this->entityTags($ent);
                    $out   .= $openTag;
                    $stack[] = $closeTag;
                    if ($isCode) {
                        $inCode++;
                    }
                }
            }

            if ($i === $units) {
                if (!empty($closes[$i])) {
                    for ($j = count($closes[$i]) - 1; $j >= 0; $j--) {
                        if (!empty($stack)) {
                            $closeTag = array_pop($stack);
                            if (is_string($closeTag) && strpos($closeTag, '</code>') !== false) {
                                $inCode = max(0, $inCode - 1);
                            }
                            $out .= (string) $closeTag;
                        }
                    }
                }
                break;
            }

            $char16 = substr($seg16, $i * 2, 2);
            $ch     = mb_convert_encoding($char16, 'UTF-8', 'UTF-16LE');
            $out   .= $inCode > 0 ? htmlspecialchars($ch, ENT_QUOTES, 'UTF-8') : $ch;

            if (!empty($closes[$i + 1])) {
                for ($j = count($closes[$i + 1]) - 1; $j >= 0; $j--) {
                    if (!empty($stack)) {
                        $closeTag = array_pop($stack);
                        if (is_string($closeTag) && strpos($closeTag, '</code>') !== false) {
                            $inCode = max(0, $inCode - 1);
                        }
                        $out .= (string) $closeTag;
                    }
                }
            }
        }

        return $out;
    }

    private function entityTags(array $e): array
    {
        $type = $e['type'] ?? '';
        switch ($type) {
            case 'bold':
                return ['<strong>', '</strong>', false];
            case 'italic':
                return ['<em>', '</em>', false];
            case 'underline':
                return ['<u>', '</u>', false];
            case 'strikethrough':
                return ['<s>', '</s>', false];
            case 'spoiler':
                return ['<span class="tg-spoiler">', '</span>', false];
            case 'code':
                return ['<code>', '</code>', true];
            case 'pre':
                $lang = $e['language'] ? ' class="language-' . htmlspecialchars($e['language'], ENT_QUOTES, 'UTF-8') . '"' : '';
                return ['<pre><code' . $lang . '>', '</code></pre>', true];
            case 'text_link':
                $href = $this->sanitizeUrl($e['url'] ?? '');
                return ['<a href="' . $href . '" target="_blank" rel="nofollow noopener">', '</a>', false];
            case 'url':
                $href = $this->sanitizeUrl($e['url'] ?? '');
                return ['<a href="' . $href . '" target="_blank" rel="nofollow noopener">', '</a>', false];
            default:
                return ['', '', false];
        }
    }

    private function sanitizeUrl(string $url): string
    {
        $url = trim(strip_tags($url));
        $url = rtrim($url, " \t\n\r\0\x0B).,;:»\"");
        if ($url === '') {
            return '#';
        }
        if (!preg_match('~^[a-z][a-z0-9+\-.]*://~i', $url)) {
            $url = 'https://' . $url;
        }
        return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    }

    private function utf16UnitsLength(string $utf8): int
    {
        $utf16 = mb_convert_encoding($utf8, 'UTF-16LE', 'UTF-8');
        return (int) (strlen($utf16) / 2);
    }

    // Final <br> in the intro
    private function ensureTrailingBr(string $html): string
    {
        $trimmed = rtrim($html);
        if ($trimmed === '') {
            return '<br>';
        }
        if (preg_match('~<br\s*/?>\s*$~i', $trimmed)) {
            return preg_replace('~<br\s*/?>\s*$~i', '<br>', $trimmed);
        }
        return $trimmed . '<br>';
    }
}