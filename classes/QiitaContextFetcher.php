<?php
class QiitaContextFetcher 
{
    private string $url;
    function __construct($url)
    {
        $this->url = $url;
    }
    public static function IsQiitaContext($url) : bool
    {
        if(strpos($url, '//qiita.com/') !== false)
        {
            return true;
        }
        return false;
    }
    public function Fetch() : string
    {
        $qiitaHtml = $this->getQiitaContext();
        if(!$qiitaHtml)
        {
            return "";
        }

        $html = $this->replaceIframesWithLinkCards($qiitaHtml);
        return $this->appendCustomStyles($html);
    }

    private function appendCustomStyles(string $html) : string
    {
        $doc = new DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new DOMXPath($doc);
        $sections = $xpath->query("//section[@class='it-MdContent']");

        if ($sections->length > 0) {
            $section = $sections->item(0);
            $style = $doc->createElement('style');
            $css = "
.custom-link-card {
  height: 112px;
  width: 100%;
  max-width: 720px;
  margin: 1.5em 0;
}

.custom-link-card a {
  display: flex;
  height: 100%;
  border: 1px solid #e1e4e8;
  border-radius: 6px;
  text-decoration: none;
  background-color: #fff;
  overflow: hidden;
  transition: background-color 0.2s;
}

.custom-link-card a:hover {
  background-color: #f9f9f9;
}

.custom-link-card a div {
  padding: 12px 16px;
  display: flex;
  flex-direction: column;
  justify-content: center;
  flex: 1;
  min-width: 0;
}

.custom-link-card a div div {
  font-size: 15px;
  font-weight: 600;
  color: #333;
  line-height: 1.3;
  margin-bottom: 4px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.custom-link-card a div span {
  font-size: 12px;
  color: #6a737d;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.custom-link-card a img {
  width: 224px;
  height: 112px;
  object-fit: cover;
  margin: 0;
  display: block;
}

@media (max-width: 600px) {
  .custom-link-card {
    height: 90px;
  }
  .custom-link-card a img {
    width: 120px;
    height: 90px;
  }
  .custom-link-card a div {
    padding: 8px 12px;
  }
  .custom-link-card a div div {
    font-size: 13px;
  }
}
";
            $style->appendChild($doc->createTextNode($css));
            if ($section->firstChild) {
                $section->insertBefore($style, $section->firstChild);
            } else {
                $section->appendChild($style);
            }
        }

        return $doc->saveHTML();
    }

    private function replaceIframesWithLinkCards(string $qiitaHtml) : string
    {
        $doc = new DOMDocument();
        @$doc->loadHTML($qiitaHtml);
        $xpath = new DOMXPath($doc);
        $iframes = $xpath->query("//iframe[starts-with(@id,'qiita-embed-content__')]");

        foreach($iframes as $iframe) {
            $dataContent = $iframe->getAttribute('data-content');
            if (!$dataContent) continue;

            $apiUrl = "https://qiita.com/api/ogp?url=" . $dataContent;
            $jsonResponse = $this->getContent($apiUrl);
            
            if ($jsonResponse) {
                $ogpData = json_decode($jsonResponse, true);
                if ($ogpData) {
                    $image = $ogpData['image'] ?? '';
                    $title = $ogpData['title'] ?? '';
                    $displayUrl = $ogpData['url'] ?? '';

                    $newHtml = sprintf(
                        '<div class="custom-link-card">' .
                        '<a href="%s" rel="nofollow noopener" target="_blank">' .
                        '<div>' .
                        '<div>%s</div>' .
                        '<span>%s</span>' .
                        '</div>' .
                        '<img alt="" src="%s" height="110" width="220">' .
                        '</a>' .
                        '</div>',
                        htmlspecialchars(urldecode($dataContent)),
                        htmlspecialchars($title),
                        htmlspecialchars($displayUrl),
                        htmlspecialchars($image)
                    );
                    
                    $newDoc = new DOMDocument();
                    @$newDoc->loadHTML('<?xml encoding="utf-8" ?>' . $newHtml);
                    $newNode = $doc->importNode($newDoc->getElementsByTagName('div')->item(0), true);
                    $iframe->parentNode->replaceChild($newNode, $iframe);
                }
            }
        }

        return $doc->saveHTML();
    }

    private function getQiitaContext() : string
    {
        $contents = $this->getContent($this->url);
        if(!$contents){
            return "";
        }
        $html = mb_convert_encoding($contents, 'HTML-ENTITIES', 'ASCII, JIS, UTF-8, EUC-JP, SJIS');
        $doc = new DOMDocument();
        @$doc->loadHTML($html);
        $xpath = new DOMXPath($doc);
        $query_selector = "(//section[@class='it-MdContent'])";
        $nodeList = $xpath->query($query_selector);
        if ($nodeList->length === 0) {
            return "";
        }
        $fetchHtml = $doc->saveHTML($nodeList->item(0));
        return "<!DOCTYPE html><html><head><meta charset='utf-8'></head><body>${fetchHtml}</body></html>";
    }

    protected function getContent($url) {
        $context = stream_context_create([
            'http' => [
                'timeout' => 60,
                'follow_location' => true,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);
        $contents = @file_get_contents($url, false, $context);
        return $contents;
    }

}
