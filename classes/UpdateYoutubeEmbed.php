<?php

class UpdateYoutubeEmbed
{
    public static function Update(DOMDocument $doc, DOMXPath $xpath, DOMElement $basenode): void 
    {
        $query = "(//iframe[starts-with(@src, 'https://www.youtube.com/embed/')])";
        $entries = $xpath->query($query, $basenode);
        if($entries->length === 0){
            return;
        }

        $nodesToUpdate = [];
        foreach ($entries as $entry) {
            $nodesToUpdate[] = $entry;
        }

        foreach($nodesToUpdate as $entry)
        {
            $original_src = $entry->getAttribute('src');
            $html = UpdateYoutubeEmbed::CreateIframeHtml($original_src);

            $tempDoc = new DOMDocument();
            @$tempDoc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            $importedNode = $doc->importNode($tempDoc->documentElement, true);
            $entry->parentNode->replaceChild($importedNode, $entry);
        }
    }
    private static function CreateIframeHtml(string $url) : string
    {
        $id = UpdateYoutubeEmbed::ExtractionId($url);
        return "<iframe class=\"youtube-player\"
                type=\"text/html\" width=\"640\" height=\"385\"
                title='YouTube video player'
                src=\"https://www.youtube-nocookie.com/embed/$id\"
                referrerpolicy='strict-origin-when-cross-origin'
                allowfullscreen frameborder=\"0\"></iframe>";
        
    }
    private static function ExtractionId(string $url) : string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $id = basename($path);
        return $id;
    }
}
