<?php

require_once __DIR__ . '/SiteHeadInfo.php';

class SiteHeadInfoHtml
{
    public function render(array $data, bool $includeCss = true): string
    {
        $normalized = $this->normalize($data);
        if ($this->isEmpty($normalized)) {
            return '';
        }
        $html = $this->buildMarkup($normalized);
        return $includeCss ? $this->getStylesheet() . $html : $html;
    }

    public function renderFromUrl(string $url, bool $includeCss = true): string
    {
        $info = new SiteHeadInfo($url);
        $result = $info->toArray();
        if($result['error'] == 'fetch_failed')
        {
            return '';
        }
        return $this->render($result, $includeCss);
    }

    public function getStylesheet(): string
    {
        return $this->getCss();
    }

    private function normalize(array $data): array
    {
        $link = $data['canonical'] ?? $data['final_url'] ?? $data['url'] ?? '';
        $og = $data['og'] ?? [];
        $twitter = $data['twitter'] ?? [];
        $base_url = $data['final_url'] ?? $data['url'] ?? $link;

        $host = $this->extractHost($base_url);

        $title = $data['title'] ?? $og['title'] ?? null;
        if ($title === null || $title === '') {
            $title = $host !== '' ? $host : null;
        }

        $description = $data['description'] ?? $og['description'] ?? $twitter['description'] ?? null;
        if ($description === '') {
            $description = null;
        }

        $image = $og['image'] ?? $twitter['image'] ?? $twitter['image:src'] ?? null;
        if ($image === '') {
            $image = null;
        }

        $favicon = $data['favicon'] ?? null;
        if ($favicon === '') {
            $favicon = null;
        }

        $domain = $this->extractHost($data['final_url'] ?? $data['url'] ?? $link);
        $lang = isset($data['lang']) && $data['lang'] !== '' ? $data['lang'] : null;
        $error = $data['error'] ?? null;

        if ($error !== null) {
            $title = 'ページ情報を取得できませんでした';
            if ($link === '' && isset($data['url'])) {
                $link = $data['url'];
            }
            if ($domain === '' && $link !== '') {
                $domain = $this->extractHost($link);
            }
        }

        return [
            'link' => $link,
            'title' => $title,
            'description' => $description,
            'image' => $image,
            'favicon' => $favicon,
            'domain' => $domain,
            'lang' => $lang,
            'error' => $error,
        ];
    }

    private function isEmpty(array $normalized): bool
    {
        if ($normalized['error'] !== null) {
            return ($normalized['link'] ?? '') === '';
        }

        $has_content = ($normalized['title'] ?? '') !== ''
            || ($normalized['description'] ?? '') !== ''
            || ($normalized['image'] ?? '') !== '';

        return !$has_content;
    }

    private function buildMarkup(array $n): string
    {
        $lang_attr = $n['lang'] !== null
            ? ' lang="' . $this->esc($n['lang']) . '"'
            : '';

        $parts = [];
        $parts[] = '<aside class="fm-site-head-info"' . $lang_attr . '>';
        $parts[] = '<a href="' . $this->esc($n['link']) . '" rel="nofollow noopener" target="_blank">';

        if ($n['favicon'] !== null) {
            $parts[] = '<img class="fm-site-head-info__favicon" src="'
                . $this->esc($n['favicon']) . '" alt="" width="16" height="16">';
        }

        $parts[] = '<div class="fm-site-head-info__body">';
        $parts[] = '<div class="fm-site-head-info__title">' . $this->esc($n['title']) . '</div>';

        if ($n['description'] !== null) {
            $parts[] = '<div class="fm-site-head-info__description">'
                . $this->esc($n['description']) . '</div>';
        }

        if ($n['domain'] !== '') {
            $parts[] = '<div class="fm-site-head-info__domain">' . $this->esc($n['domain']) . '</div>';
        }

        $parts[] = '</div>';

        if ($n['image'] !== null) {
            $parts[] = '<img class="fm-site-head-info__image" src="'
                . $this->esc($n['image']) . '" alt="">';
        }

        $parts[] = '</a>';
        $parts[] = '</aside>';

        return implode('', $parts);
    }

    private function getCss(): string
    {
        $css = <<<'CSS'
aside.fm-site-head-info {
  display: block !important;
  margin: 1em 0 !important;
  max-width: 720px !important;
  box-sizing: border-box !important;
}
aside.fm-site-head-info > a {
  display: flex !important;
  flex-direction: row !important;
  align-items: stretch !important;
  border: 1px solid #e1e4e8 !important;
  border-radius: 6px !important;
  text-decoration: none !important;
  background-color: #fff !important;
  overflow: hidden !important;
  color: #333 !important;
  transition: background-color 0.2s !important;
  box-sizing: border-box !important;
  width: auto !important;
  max-width: 100% !important;
}
aside.fm-site-head-info > a:hover {
  background-color: #f9f9f9 !important;
  text-decoration: none !important;
}
aside.fm-site-head-info img.fm-site-head-info__favicon {
  flex: 0 0 auto !important;
  width: 16px !important;
  height: 16px !important;
  min-width: 16px !important;
  max-width: 16px !important;
  margin: 14px 0 0 12px !important;
  align-self: flex-start !important;
  display: block !important;
  border: none !important;
  padding: 0 !important;
  float: none !important;
}
aside.fm-site-head-info .fm-site-head-info__body {
  flex: 1 1 auto !important;
  min-width: 0 !important;
  padding: 12px 16px !important;
  display: flex !important;
  flex-direction: column !important;
  justify-content: center !important;
  box-sizing: border-box !important;
  width: auto !important;
  margin: 0 !important;
  border: none !important;
  background: transparent !important;
}
aside.fm-site-head-info .fm-site-head-info__title {
  font-size: 15px !important;
  font-weight: 600 !important;
  color: #333 !important;
  line-height: 1.3 !important;
  margin: 0 0 4px 0 !important;
  padding: 0 !important;
  overflow: hidden !important;
  text-overflow: ellipsis !important;
  display: -webkit-box !important;
  -webkit-line-clamp: 2 !important;
  -webkit-box-orient: vertical !important;
  border: none !important;
  background: transparent !important;
}
aside.fm-site-head-info .fm-site-head-info__description {
  font-size: 13px !important;
  color: #586069 !important;
  line-height: 1.4 !important;
  margin: 0 0 4px 0 !important;
  padding: 0 !important;
  overflow: hidden !important;
  text-overflow: ellipsis !important;
  display: -webkit-box !important;
  -webkit-line-clamp: 2 !important;
  -webkit-box-orient: vertical !important;
  border: none !important;
  background: transparent !important;
}
aside.fm-site-head-info .fm-site-head-info__domain {
  font-size: 12px !important;
  color: #6a737d !important;
  white-space: nowrap !important;
  overflow: hidden !important;
  text-overflow: ellipsis !important;
  margin: 0 !important;
  padding: 0 !important;
  border: none !important;
  background: transparent !important;
}
aside.fm-site-head-info img.fm-site-head-info__image {
  flex: 0 0 auto !important;
  width: 160px !important;
  height: 90px !important;
  min-width: 160px !important;
  max-width: 160px !important;
  min-height: 90px !important;
  max-height: 90px !important;
  object-fit: cover !important;
  margin: 0 !important;
  padding: 0 !important;
  display: block !important;
  border: none !important;
  float: none !important;
}
@media (max-width: 600px) {
  aside.fm-site-head-info img.fm-site-head-info__image {
    width: 100px !important;
    height: 70px !important;
    min-width: 100px !important;
    max-width: 100px !important;
    min-height: 70px !important;
    max-height: 70px !important;
  }
  aside.fm-site-head-info .fm-site-head-info__body {
    padding: 8px 12px !important;
  }
  aside.fm-site-head-info .fm-site-head-info__title {
    font-size: 13px !important;
  }
}
CSS;

        return "<style type=\"text/css\">\n" . trim($css) . "\n</style>";
    }

    private function extractHost(string $url): string
    {
        if ($url === '') {
            return '';
        }
        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) ? $host : '';
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
