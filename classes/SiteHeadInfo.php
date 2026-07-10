<?php

require_once __DIR__ . '/DomUtils.php';
require_once __DIR__ . '/FeedModHttp.php';
require_once __DIR__ . '/UrlUtils.php';

class SiteHeadInfo
{
    private const DEFAULT_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36 Edg/146.0.0.0';
    private const MAX_FETCH_HOPS = 3;

    private string $url;
    private ?array $data = null;

    public function __construct(string $url)
    {
        $this->url = $this->normalizeUrl($url);
    }

    public function toArray(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        $result = ['url' => $this->url];

        $fetch_url = $this->resolveUrl($this->url);
        $fetched = $this->fetchHtml($fetch_url);
        if ($fetched === null) {
            $result['error'] = 'fetch_failed';
            $this->data = $result;
            return $result;
        }

        $result['final_url'] = $fetched['final_url'];

        // 最終的なドメインが x.com の場合は失敗とする
        $host = parse_url($fetched['final_url'], PHP_URL_HOST);
        if ($host !== null) {
            $host = strtolower($host);
            if ($host === 'x.com' || substr($host, -6) === '.x.com') {
                $result['error'] = 'fetch_failed';
                $this->data = $result;
                return $result;
            }
        }

        $parsed = $this->parseHead($fetched['html'], $fetched['final_url']);
        if (isset($parsed['error'])) {
            $result['error'] = $parsed['error'];
            $this->data = $result;
            return $result;
        }

        $this->data = array_merge($result, $parsed);
        return $this->data;
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if (strpos($url, '//') === 0) {
            return 'http:' . $url;
        }
        return $url;
    }

    private function userAgent(): string
    {
        return defined('USER_AGENT_FEEDMOD') ? USER_AGENT_FEEDMOD : self::DEFAULT_UA;
    }

    private function httpHeaders(): array
    {
        return ['Accept-Language: ' . FeedModHttp::ACCEPT_LANGUAGE];
    }

    private function resolveUrl(string $url): string
    {
        if (!UrlUtils::is_shortcur_url($url)) {
            return $url;
        }
        $original = UrlUtils::get_original_url($url);
        return $original !== '' && $original !== $url ? $original : $url;
    }

    /**
     * @return array{html: string, final_url: string}|null
     */
    private function fetchHtml(string $url, int $hops_left = self::MAX_FETCH_HOPS): ?array
    {
        if ($hops_left <= 0 || preg_match('/\.pdf(\?.*)?$/i', $url)) {
            return null;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, self::MAX_FETCH_HOPS);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent());
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->httpHeaders());

        $html = curl_exec($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $content_type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $content_length = (int) curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        $final_url = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $redirect_url = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);

        if ($html === false || $http_code >= 400) {
            return null;
        }
        if (stripos($content_type, 'application/pdf') !== false || $content_length > 10 * 1024 * 1024) {
            return null;
        }

        if ($final_url === '') {
            $final_url = $url;
        }

        $next_url = $this->findNextUrl($html, $url, $final_url, $http_code, $redirect_url);
        if ($next_url !== null && $hops_left > 1) {
            $refetched = $this->fetchHtml($next_url, $hops_left - 1);
            if ($refetched !== null) {
                return $refetched;
            }
        }

        return ['html' => $html, 'final_url' => $final_url];
    }

    private function findNextUrl(string $html, string $request_url, string $final_url, int $http_code, string $redirect_url): ?string
    {
        if ($redirect_url !== '' && $redirect_url !== $final_url) {
            return $redirect_url;
        }
        if ($http_code >= 300 && $http_code < 400 && $redirect_url !== '') {
            return $redirect_url;
        }

        $from_html = $this->extractRedirectFromHtml($html, $final_url);
        if ($from_html !== null && $from_html !== $final_url && $from_html !== $request_url) {
            return $from_html;
        }

        return null;
    }

    private function extractRedirectFromHtml(string $html, string $base_url): ?string
    {
        if (preg_match('/<meta\s+http-equiv=["\']?refresh["\']?\s+content=["\'][^"\']*url=([^"\';\s>]+)/i', $html, $matches)) {
            return $this->normalizeRedirectTarget($matches[1], $base_url);
        }
        if (preg_match('/location\.(?:replace|href)\s*=\s*["\']([^"\']+)["\']/i', $html, $matches)) {
            return $this->normalizeRedirectTarget($matches[1], $base_url);
        }
        if (preg_match('/<title>\s*(https?:\/\/[^\s<]+)\s*<\/title>/i', $html, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private function normalizeRedirectTarget(string $target, string $base_url): string
    {
        $target = stripcslashes(trim($target));
        if (strpos($target, '//') === 0) {
            return 'http:' . $target;
        }
        if (!preg_match('#^https?://#i', $target)) {
            return DomUtils::update_absolute_url($base_url, $target);
        }
        return $target;
    }

    private function parseHead(string $html, string $base_url): array
    {
        if (trim($html) === '') {
            return ['error' => 'parse_failed'];
        }

        $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'ASCII, JIS, UTF-8, EUC-JP, SJIS');
        if ($html === false) {
            return ['error' => 'parse_failed'];
        }

        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $loaded = @$doc->loadHTML($html);
        libxml_clear_errors();

        if (!$loaded) {
            return ['error' => 'parse_failed'];
        }

        $xpath = new DOMXPath($doc);
        $result = [];

        $title = $this->getTitle($xpath);
        if ($title !== null) {
            $result['title'] = $title;
        }

        $description = $this->metaContent($xpath, 'name', 'description');
        if ($description !== null) {
            $result['description'] = $description;
        }

        $canonical = $this->linkHref($xpath, 'canonical', $base_url);
        if ($canonical !== null) {
            $result['canonical'] = $canonical;
        }

        $favicon = $this->getFavicon($xpath, $base_url);
        if ($favicon !== null) {
            $result['favicon'] = $favicon;
        }

        $lang = $this->getLang($xpath);
        if ($lang !== null) {
            $result['lang'] = $lang;
        }

        $og = $this->collectMetaPrefix($xpath, 'property', 'og:');
        if ($og !== []) {
            $result['og'] = $og;
        }

        $twitter = $this->collectMetaPrefix($xpath, 'name', 'twitter:');
        if ($twitter !== []) {
            $result['twitter'] = $twitter;
        }

        return $result;
    }

    private function getTitle(DOMXPath $xpath): ?string
    {
        $entries = $xpath->query('//html/head/title');
        if (!$entries || $entries->length === 0) {
            return null;
        }
        $title = trim($entries->item(0)->textContent ?? '');
        return $title !== '' ? $title : null;
    }

    private function metaContent(DOMXPath $xpath, string $attr, string $value): ?string
    {
        $entries = $xpath->query("//html/head//meta[@{$attr}='{$value}']");
        if (!$entries || $entries->length === 0) {
            return null;
        }
        $content = trim($entries->item(0)->getAttribute('content'));
        return $content !== '' ? $content : null;
    }

    private function linkHref(DOMXPath $xpath, string $rel, string $base_url): ?string
    {
        $entries = $xpath->query("//html/head//link[@rel='{$rel}']");
        if (!$entries || $entries->length === 0) {
            return null;
        }
        $href = trim($entries->item(0)->getAttribute('href'));
        if ($href === '') {
            return null;
        }
        return DomUtils::update_absolute_url($base_url, $href);
    }

    private function getFavicon(DOMXPath $xpath, string $base_url): ?string
    {
        $entries = $xpath->query("//html/head//link[@rel='icon' or @rel='shortcut icon']");
        if (!$entries || $entries->length === 0) {
            return null;
        }
        $href = trim($entries->item(0)->getAttribute('href'));
        if ($href === '') {
            return null;
        }
        return DomUtils::update_absolute_url($base_url, $href);
    }

    private function getLang(DOMXPath $xpath): ?string
    {
        $entries = $xpath->query('/html/@lang');
        if (!$entries || $entries->length === 0) {
            return null;
        }
        $lang = trim($entries->item(0)->nodeValue ?? '');
        return $lang !== '' ? $lang : null;
    }

    private function collectMetaPrefix(DOMXPath $xpath, string $attr, string $prefix): array
    {
        $entries = $xpath->query("//html/head//meta[starts-with(@{$attr}, '{$prefix}')]");
        if (!$entries || $entries->length === 0) {
            return [];
        }

        $result = [];
        $prefix_len = strlen($prefix);
        foreach ($entries as $entry) {
            $key = $entry->getAttribute($attr);
            if ($key === '' || strlen($key) <= $prefix_len) {
                continue;
            }
            $short_key = substr($key, $prefix_len);
            $content = trim($entry->getAttribute('content'));
            if ($content !== '') {
                $result[$short_key] = $content;
            }
        }
        return $result;
    }
}
