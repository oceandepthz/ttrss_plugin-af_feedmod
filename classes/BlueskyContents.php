<?php

/**
 * Bluesky投稿URLから公開API経由で投稿および返信ツリーを取得し、
 * RSSリーダー閲覧用に最適化したクリーンなHTMLに変換するクラス
 */
class BlueskyContents
{
    /** @var string 対象のURL */
    private string $url;

    /** @var int 返信ツリーの取得深度 */
    private int $depth;

    /** @var string ユーザーエージェント */
    private string $userAgent = 'Mozilla/5.0 (compatible; TTRSS-FeedMod/1.0)';

    /**
     * コンストラクタ
     *
     * @param string $url Blueskyの投稿URL
     * @param int $depth 返信ツリーの取得深度（デフォルト: 10）
     */
    public function __construct(string $url, int $depth = 10)
    {
        $this->url = trim($url);
        $this->depth = $depth;
    }

    /**
     * 指定されたURLがBlueskyの投稿URLであるかを判定する
     *
     * @param string $url 判定対象のURL
     * @return bool
     */
    public static function isBlueskyUrl(string $url): bool
    {
        return (bool)preg_match('~https?://(?:www\.)?bsky\.app/profile/[^/]+/post/[^/?#]+~i', $url);
    }

    /**
     * インスタンスのURLがBlueskyの投稿URLであるかを判定する
     *
     * @return bool
     */
    public function isBluesky(): bool
    {
        return self::isBlueskyUrl($this->url);
    }

    /**
     * Bluesky投稿のHTMLコンテンツを取得する
     *
     * @param bool $includeCss CSSスタイルを含めるかどうか（デフォルト: true）
     * @return string 生成されたHTML
     */
    public function getContents(bool $includeCss = true): string
    {
        return $this->getHtml($includeCss);
    }

    /**
     * Bluesky投稿のHTMLコンテンツを取得する
     *
     * @param bool $includeCss CSSスタイルを含めるかどうか（デフォルト: true）
     * @return string 生成されたHTML
     */
    public function getHtml(bool $includeCss = true): string
    {
        // 1. URLから handle (または DID) と rkey を抽出
        if (!preg_match('~/profile/([^/]+)/post/([^/?#]+)~', $this->url, $matches)) {
            return '';
        }

        $identifier = $matches[1];
        $rkey = $matches[2];
        $atUri = "at://{$identifier}/app.bsky.feed.post/{$rkey}";

        // 2. Bluesky公式公開APIを呼び出し
        $threadData = $this->fetchPostThread($atUri, $this->depth);
        if (!$threadData) {
            return '';
        }

        $thread = $threadData['thread'] ?? null;
        $post = $thread['post'] ?? null;

        if (!$post) {
            return '';
        }

        // 3. メイン投稿のHTMLパーツを生成
        $html = $this->renderPost($post, $rkey);

        // 4. 返信ツリーのHTMLパーツを再帰的に生成して連結
        if (!empty($thread['replies']) && is_array($thread['replies'])) {
            $html .= $this->renderRepliesTree($thread['replies']);
        }

        // 5. CSSスタイルを付加して返す
        if ($includeCss) {
            $html = $this->getStylesheet() . "\n" . $html;
        }

        return "<!DOCTYPE html><html><head><meta charset='utf-8'></head><body>{$html}</body></html>";
    }

    /**
     * Bluesky公開APIからスレッドデータを取得する
     *
     * @param string $atUri ATプロトコルURI
     * @param int $depth 取得深度
     * @return array|null デコードされたJSONデータ
     */
    protected function fetchPostThread(string $atUri, int $depth): ?array
    {
        $apiUrl = 'https://public.api.bsky.app/xrpc/app.bsky.feed.getPostThread?' . http_build_query([
            'uri'   => $atUri,
            'depth' => $depth,
        ]);

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false) {
            return null;
        }

        $data = json_decode($response, true);
        return is_array($data) ? $data : null;
    }

    /**
     * メイン投稿のHTMLパーツを生成する
     *
     * @param array $post 投稿データ配列
     * @param string $rkey 投稿キー
     * @return string
     */
    public function renderPost(array $post, string $rkey): string
    {
        $author = $post['author'] ?? [];
        $record = $post['record'] ?? [];
        $embed  = $post['embed'] ?? null;

        $handle      = htmlspecialchars($author['handle'] ?? '', ENT_QUOTES, 'UTF-8');
        $rawName     = !empty($author['displayName']) ? $author['displayName'] : ($author['handle'] ?? '');
        $displayName = htmlspecialchars($rawName, ENT_QUOTES, 'UTF-8');
        $avatarUrl   = htmlspecialchars($author['avatar'] ?? '', ENT_QUOTES, 'UTF-8');
        $profileUrl  = "https://bsky.app/profile/{$handle}";

        // 本文テキスト
        $text = htmlspecialchars($record['text'] ?? '', ENT_QUOTES, 'UTF-8');
        $text = nl2br($text);

        // 投稿日時をJSTでフォーマット (YYYY/MM/DD HH:mm)
        $createdAt = $record['createdAt'] ?? '';
        $formattedDate = '';
        if (!empty($createdAt)) {
            try {
                $dt = new DateTime($createdAt);
                $dt->setTimezone(new DateTimeZone('Asia/Tokyo'));
                $formattedDate = $dt->format('Y/m/d H:i');
            } catch (Exception $e) {
                $formattedDate = htmlspecialchars($createdAt, ENT_QUOTES, 'UTF-8');
            }
        }

        // 各種カウント数
        $replyCount    = (int)($post['replyCount'] ?? 0);
        $repostCount   = (int)($post['repostCount'] ?? 0);
        $quoteCount    = (int)($post['quoteCount'] ?? 0);
        $likeCount     = (int)($post['likeCount'] ?? 0);
        $bookmarkCount = (int)($post['bookmarkCount'] ?? 0);

        // 認証バッジの有無
        $hasVerification = !empty($author['verification']['verifications']) || !empty($author['verifiedStatus']);
        $verificationHtml = '';
        if ($hasVerification) {
            $verificationHtml = <<<HTML
        <span class="bsky-post__badge" title="認証バッジ">
          <svg class="bsky-svg-icon" viewBox="0 0 24 24"><path d="M8.792 1.615a4.154 4.154 0 0 1 6.416 0 4.154 4.154 0 0 0 3.146 1.515 4.154 4.154 0 0 1 4 5.017 4.154 4.154 0 0 0 .777 3.404 4.154 4.154 0 0 1-1.427 6.255 4.153 4.153 0 0 0-2.177 2.73 4.154 4.154 0 0 1-5.781 2.784 4.154 4.154 0 0 0-3.492 0 4.154 4.154 0 0 1-5.78-2.784 4.154 4.154 0 0 0-2.178-2.73A4.154 4.154 0 0 1 .87 11.551a4.154 4.154 0 0 0 .776-3.404A4.154 4.154 0 0 1 5.646 3.13a4.154 4.154 0 0 0 3.146-1.515Z"></path><path d="M17.861 8.26a1.438 1.438 0 0 1 0 2.033l-6.571 6.571a1.437 1.437 0 0 1-2.033 0L5.97 13.58a1.438 1.438 0 0 1 2.033-2.033l2.27 2.269 5.554-5.555a1.437 1.437 0 0 1 2.033 0Z"></path></svg>
        </span>
HTML;
        }

        // 埋め込みカード・メディアのHTML生成
        $embedHtml = $this->renderEmbed($embed);

        $avatarImgTag = !empty($avatarUrl)
            ? "<img src=\"{$avatarUrl}\" alt=\"{$displayName}\" class=\"bsky-post__avatar-img\">"
            : "<div class=\"bsky-post__avatar-placeholder\"></div>";

        return <<<HTML
<div class="bsky-thread">
  <div class="bsky-post bsky-post--main">
    <!-- 投稿者ヘッダー -->
    <div class="bsky-post__header">
      <div class="bsky-post__avatar-wrapper">
        <a href="{$profileUrl}" target="_blank" rel="noopener noreferrer" class="bsky-post__avatar-link">
          {$avatarImgTag}
        </a>
      </div>
      <div class="bsky-post__user-info">
        <div class="bsky-post__display-name-row">
          <a href="{$profileUrl}" target="_blank" rel="noopener noreferrer" class="bsky-post__display-name">{$displayName}</a>
          {$verificationHtml}
        </div>
        <a href="{$profileUrl}" target="_blank" rel="noopener noreferrer" class="bsky-post__handle">@{$handle}</a>
      </div>
    </div>

    <!-- 本文・埋め込みカード -->
    <div class="bsky-post__body">
      <div class="bsky-post__text">{$text}</div>
      {$embedHtml}
    </div>

    <!-- 投稿日時 -->
    <div class="bsky-post__meta">
      <span class="bsky-post__date">{$formattedDate}</span>
    </div>

    <!-- エンゲージメント集計 -->
    <div class="bsky-post__stats">
      <span class="bsky-post__stat-item"><strong class="bsky-post__stat-count">{$repostCount}</strong> リポスト</span>
      <span class="bsky-post__stat-item"><strong class="bsky-post__stat-count">{$quoteCount}</strong> 引用</span>
      <span class="bsky-post__stat-item"><strong class="bsky-post__stat-count">{$likeCount}</strong> いいね</span>
      <span class="bsky-post__stat-item"><strong class="bsky-post__stat-count">{$bookmarkCount}</strong> 保存</span>
    </div>
  </div>
</div>
HTML;
    }

    /**
     * 返信ツリーの配列を再帰的に走査してHTML文字列を生成する
     *
     * @param array $replies 返信配列
     * @return string
     */
    public function renderRepliesTree(array $replies): string
    {
        $output = '';
        foreach ($replies as $reply) {
            if (($reply['$type'] ?? '') !== 'app.bsky.feed.defs#threadViewPost' || empty($reply['post'])) {
                continue;
            }

            $replyPost = $reply['post'];
            $replyUri  = $replyPost['uri'] ?? '';
            $replyRkey = '';
            if (preg_match('~/app\.bsky\.feed\.post/([^/?#]+)~', $replyUri, $m)) {
                $replyRkey = $m[1];
            }

            // 返信ポスト単体のHTML
            $output .= $this->renderReply($replyPost, $replyRkey);

            // さらにネストされた返信（子返信）がある場合は再帰処理
            if (!empty($reply['replies']) && is_array($reply['replies'])) {
                $output .= $this->renderRepliesTree($reply['replies']);
            }
        }
        return $output;
    }

    /**
     * 返信ポスト単体のHTMLパーツを生成する
     *
     * @param array $post 返信投稿データ
     * @param string $rkey 投稿キー
     * @return string
     */
    public function renderReply(array $post, string $rkey): string
    {
        $author = $post['author'] ?? [];
        $record = $post['record'] ?? [];
        $embed  = $post['embed'] ?? null;

        $handle      = htmlspecialchars($author['handle'] ?? '', ENT_QUOTES, 'UTF-8');
        $rawName     = !empty($author['displayName']) ? $author['displayName'] : ($author['handle'] ?? '');
        $displayName = htmlspecialchars($rawName, ENT_QUOTES, 'UTF-8');
        $avatarUrl   = htmlspecialchars($author['avatar'] ?? '', ENT_QUOTES, 'UTF-8');
        $profileUrl  = "https://bsky.app/profile/{$handle}";
        $postUrl     = "https://bsky.app/profile/{$handle}/post/{$rkey}";

        // 本文テキスト
        $text = htmlspecialchars($record['text'] ?? '', ENT_QUOTES, 'UTF-8');
        $text = nl2br($text);

        // 相対経過時間の計算
        $createdAt = $record['createdAt'] ?? '';
        $timeAgo   = $this->formatTimeAgo($createdAt);

        // 返信内の埋め込みメディア
        $embedHtml = $this->renderEmbed($embed);

        $avatarImgTag = !empty($avatarUrl)
            ? "<img src=\"{$avatarUrl}\" alt=\"{$displayName}\" class=\"bsky-reply-item__avatar-img\">"
            : "<div class=\"bsky-reply-item__avatar-placeholder\"></div>";

        return <<<HTML
<div class="bsky-reply-wrapper">
  <div class="bsky-reply-item">
    <!-- アバター + スレッド接続線 -->
    <div class="bsky-reply-item__avatar-column">
      <a href="{$profileUrl}" target="_blank" rel="noopener noreferrer" class="bsky-reply-item__avatar-link">
        {$avatarImgTag}
      </a>
      <div class="bsky-reply-item__thread-line"></div>
    </div>
    <!-- 返信内容 -->
    <div class="bsky-reply-item__body-column">
      <div class="bsky-reply-item__header">
        <a href="{$profileUrl}" target="_blank" rel="noopener noreferrer" class="bsky-reply-item__display-name">{$displayName}</a>
        <a href="{$profileUrl}" target="_blank" rel="noopener noreferrer" class="bsky-reply-item__handle">@{$handle}</a>
        <a href="{$postUrl}" target="_blank" rel="noopener noreferrer" class="bsky-reply-item__time-link">· {$timeAgo}</a>
      </div>
      <div class="bsky-reply-item__text">{$text}</div>
      {$embedHtml}
    </div>
  </div>
</div>
HTML;
    }

    /**
     * 埋め込みカード・画像ギャラリー等のHTMLを生成する
     *
     * @param array|null $embed 埋め込みデータ
     * @return string
     */
    protected function renderEmbed(?array $embed): string
    {
        if (!$embed) {
            return '';
        }

        $embedType = $embed['$type'] ?? '';

        // 1. 外部リンクカード
        if (strpos($embedType, 'app.bsky.embed.external') !== false && !empty($embed['external'])) {
            $ext = $embed['external'];
            $extUri   = htmlspecialchars($ext['uri'] ?? '', ENT_QUOTES, 'UTF-8');
            $extTitle = htmlspecialchars($ext['title'] ?? '', ENT_QUOTES, 'UTF-8');
            $extDesc  = htmlspecialchars($ext['description'] ?? '', ENT_QUOTES, 'UTF-8');
            $extThumb = htmlspecialchars($ext['thumb'] ?? '', ENT_QUOTES, 'UTF-8');
            $extHost  = htmlspecialchars(parse_url($ext['uri'] ?? '', PHP_URL_HOST) ?? '', ENT_QUOTES, 'UTF-8');

            $thumbHtml = '';
            if (!empty($extThumb)) {
                $thumbHtml = "<img src=\"{$extThumb}\" alt=\"\" class=\"bsky-embed-card__thumb\">";
            }

            return <<<HTML
      <div class="bsky-embed">
        <a href="{$extUri}" rel="noopener noreferrer" target="_blank" class="bsky-embed-card">
          {$thumbHtml}
          <div class="bsky-embed-card__body">
            <div class="bsky-embed-card__title">{$extTitle}</div>
            <div class="bsky-embed-card__description">{$extDesc}</div>
            <div class="bsky-embed-card__domain">
              <svg class="bsky-svg-icon" viewBox="0 0 24 24"><path d="M4.4 9.493C4.14 10.28 4 11.124 4 12a8 8 0 1 0 10.899-7.459l-.953 3.81a1 1 0 0 1-.726.727l-3.444.866-.772 1.533a1 1 0 0 1-1.493.35L4.4 9.493Zm.883-1.84L7.756 9.51l.44-.874a1 1 0 0 1 .649-.52l3.306-.832.807-3.227a7.993 7.993 0 0 0-7.676 3.597ZM2 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10S2 17.523 2 12Zm8.43.162a1 1 0 0 1 .77-.29l1.89.121a1 1 0 0 1 .494.168l2.869 1.928a1 1 0 0 1 .336 1.277l-.973 1.946a1 1 0 0 1-.894.553h-2.92a1 1 0 0 1-.831-.445L9.225 14.5a1 1 0 0 1 .126-1.262l1.08-1.076Zm.915 1.913.177-.177 1.171.074 1.914 1.286-.303.607h-1.766l-1.194-1.79Z"></path></svg>
              <span>{$extHost}</span>
            </div>
          </div>
        </a>
      </div>
HTML;
        }

        // 2. 画像ギャラリー
        if (strpos($embedType, 'app.bsky.embed.images') !== false && !empty($embed['images'])) {
            $imagesHtml = '';
            foreach ($embed['images'] as $img) {
                $thumbUrl = htmlspecialchars($img['thumb'] ?? $img['fullsize'] ?? '', ENT_QUOTES, 'UTF-8');
                $fullUrl  = htmlspecialchars($img['fullsize'] ?? $img['thumb'] ?? '', ENT_QUOTES, 'UTF-8');
                $alt      = htmlspecialchars($img['alt'] ?? '', ENT_QUOTES, 'UTF-8');
                $imagesHtml .= "<div class=\"bsky-embed-gallery__item\"><a href=\"{$fullUrl}\" target=\"_blank\" rel=\"noopener noreferrer\"><img src=\"{$thumbUrl}\" alt=\"{$alt}\" class=\"bsky-embed-gallery__img\"></a></div>";
            }
            return "<div class=\"bsky-embed bsky-embed--gallery\"><div class=\"bsky-embed-gallery\">{$imagesHtml}</div></div>";
        }

        // 3. 引用ポスト (record または recordWithMedia)
        if (strpos($embedType, 'app.bsky.embed.record') !== false) {
            $mediaHtml = '';
            if (!empty($embed['media'])) {
                $mediaHtml = $this->renderEmbed($embed['media']);
            }
            $recordObj = $embed['record'] ?? null;
            if ($recordObj && ($recordObj['$type'] ?? '') === 'app.bsky.embed.record#viewRecord') {
                $qAuthor      = $recordObj['author'] ?? [];
                $qValue       = $recordObj['value'] ?? [];
                $qHandle      = htmlspecialchars($qAuthor['handle'] ?? '', ENT_QUOTES, 'UTF-8');
                $qDisplayName = htmlspecialchars(!empty($qAuthor['displayName']) ? $qAuthor['displayName'] : ($qAuthor['handle'] ?? ''), ENT_QUOTES, 'UTF-8');
                $qAvatar      = htmlspecialchars($qAuthor['avatar'] ?? '', ENT_QUOTES, 'UTF-8');
                $qText        = nl2br(htmlspecialchars($qValue['text'] ?? '', ENT_QUOTES, 'UTF-8'));
                $qUri         = $recordObj['uri'] ?? '';
                $qPostUrl     = "https://bsky.app/profile/{$qHandle}";
                if (preg_match('~/app\.bsky\.feed\.post/([^/?#]+)~', $qUri, $qm)) {
                    $qPostUrl = "https://bsky.app/profile/{$qHandle}/post/{$qm[1]}";
                }

                $qEmbedHtml = '';
                if (!empty($recordObj['embeds']) && is_array($recordObj['embeds'])) {
                    foreach ($recordObj['embeds'] as $innerEmbed) {
                        $qEmbedHtml .= $this->renderEmbed($innerEmbed);
                    }
                }

                $avatarImg = !empty($qAvatar) ? "<img src=\"{$qAvatar}\" alt=\"\" class=\"bsky-quote__avatar-img\">" : "";

                return <<<HTML
      <div class="bsky-embed bsky-embed--quote">
        <a href="{$qPostUrl}" target="_blank" rel="noopener noreferrer" class="bsky-quote-card">
          <div class="bsky-quote-card__header">
            {$avatarImg}
            <span class="bsky-quote-card__display-name">{$qDisplayName}</span>
            <span class="bsky-quote-card__handle">@{$qHandle}</span>
          </div>
          <div class="bsky-quote-card__text">{$qText}</div>
          {$qEmbedHtml}
        </a>
      </div>
      {$mediaHtml}
HTML;
            }
        }

        return '';
    }

    /**
     * ISO日時文字列から相対経過時間を生成する
     *
     * @param string $isoDate
     * @return string
     */
    public function formatTimeAgo(string $isoDate): string
    {
        if (empty($isoDate)) {
            return '';
        }
        try {
            $now     = new DateTime('now', new DateTimeZone('UTC'));
            $created = new DateTime($isoDate);
            $diff    = $now->diff($created);

            if ($diff->y > 0) return $diff->y . '年前';
            if ($diff->m > 0) return $diff->m . 'ヶ月前';
            if ($diff->d > 0) return $diff->d . '日前';
            if ($diff->h > 0) return $diff->h . '時間前';
            if ($diff->i > 0) return $diff->i . '分前';
            return 'たった今';
        } catch (Exception $e) {
            return '';
        }
    }

    /**
     * Bluesky表示用のスタイルシートを返す
     *
     * @return string
     */
    public function getStylesheet(): string
    {
        $css = <<<'CSS'
.bsky-thread {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
  max-width: 650px;
  margin: 16px 0;
  padding: 16px;
  background: #ffffff;
  border: 1px solid #e1e8ed;
  border-radius: 12px;
  color: #0f1419;
  box-sizing: border-box;
}

.bsky-post {
  display: flex;
  flex-direction: column;
}

.bsky-post__header {
  display: flex;
  align-items: center;
  margin-bottom: 12px;
}

.bsky-post__avatar-wrapper {
  margin-right: 12px;
  flex-shrink: 0;
}

.bsky-post__avatar-img {
  width: 48px!important;
  height: 48px!important;
  border-radius: 50%;
  object-fit: cover;
  display: block;
}

.bsky-post__avatar-placeholder {
  width: 48px!important;
  height: 48px!important;
  border-radius: 50%;
  background-color: #cbd5e1;
}

.bsky-post__user-info {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.bsky-post__display-name-row {
  display: flex;
  align-items: center;
  gap: 4px;
}

.bsky-post__display-name {
  font-weight: 700;
  font-size: 16px;
  color: #0f1419;
  text-decoration: none;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.bsky-post__display-name:hover {
  text-decoration: underline;
}

.bsky-post__badge {
  display: inline-flex;
  align-items: center;
  color: #208bfe;
  flex-shrink: 0;
}

.bsky-post__handle {
  font-size: 14px;
  color: #536471;
  text-decoration: none;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.bsky-post__handle:hover {
  text-decoration: underline;
}

.bsky-post__body {
  margin-bottom: 12px;
}

.bsky-post__text {
  font-size: 16px;
  line-height: 1.5;
  word-break: break-word;
  color: #0f1419;
}

.bsky-svg-icon {
  width: 18px;
  height: 18px;
  fill: currentColor;
  vertical-align: middle;
}

.bsky-embed {
  margin-top: 12px;
}

.bsky-embed-card {
  display: flex;
  flex-direction: column;
  border: 1px solid #cfd9de;
  border-radius: 12px;
  overflow: hidden;
  text-decoration: none;
  color: inherit;
  background: #ffffff;
  transition: background-color 0.2s;
}
.bsky-embed-card:hover {
  background-color: #f7f9f9;
}

.bsky-embed-card__thumb {
  width: 100%;
  max-height: 250px;
  object-fit: cover;
  display: block;
}

.bsky-embed-card__body {
  padding: 10px 14px;
}

.bsky-embed-card__title {
  font-weight: 600;
  font-size: 15px;
  line-height: 1.3;
  margin-bottom: 4px;
  color: #0f1419;
}

.bsky-embed-card__description {
  font-size: 13px;
  color: #536471;
  line-height: 1.4;
  margin-bottom: 6px;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.bsky-embed-card__domain {
  font-size: 12px;
  color: #536471;
  display: flex;
  align-items: center;
  gap: 4px;
}

.bsky-embed-gallery {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  border-radius: 12px;
  overflow: hidden;
}

.bsky-embed-gallery__item {
  flex: 1 1 calc(50% - 6px);
  min-width: 120px;
  max-height: 280px;
  overflow: hidden;
}

.bsky-embed-gallery__img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
  border-radius: 6px;
}

.bsky-quote-card {
  display: block;
  border: 1px solid #cfd9de;
  border-radius: 10px;
  padding: 10px 12px;
  text-decoration: none;
  color: inherit;
  background: #f8fafc;
  margin-top: 8px;
}
.bsky-quote-card:hover {
  background: #f1f5f9;
}

.bsky-quote-card__header {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 4px;
}

.bsky-quote__avatar-img {
  width: 20px;
  height: 20px;
  border-radius: 50%;
  object-fit: cover;
}

.bsky-quote-card__display-name {
  font-weight: 600;
  font-size: 13px;
  color: #0f1419;
}

.bsky-quote-card__handle {
  font-size: 12px;
  color: #536471;
}

.bsky-quote-card__text {
  font-size: 13px;
  line-height: 1.4;
  color: #0f1419;
  word-break: break-word;
}

.bsky-post__meta {
  font-size: 14px;
  color: #536471;
  padding-bottom: 12px;
  border-bottom: 1px solid #eff3f4;
  margin-bottom: 12px;
}

.bsky-post__stats {
  display: flex;
  gap: 16px;
  flex-wrap: wrap;
  font-size: 14px;
  color: #536471;
}

.bsky-post__stat-count {
  color: #0f1419;
}

.bsky-reply-wrapper {
  margin-top: 10px;
  padding-left: 12px;
  border-left: 2px solid #e1e8ed;
}

.bsky-reply-item {
  display: flex;
  margin-bottom: 12px;
}

.bsky-reply-item__avatar-column {
  margin-right: 10px;
  display: flex;
  flex-direction: column;
  align-items: center;
  flex-shrink: 0;
}

.bsky-reply-item__avatar-img {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  object-fit: cover;
  display: block;
}

.bsky-reply-item__avatar-placeholder {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background-color: #cbd5e1;
}

.bsky-reply-item__thread-line {
  flex: 1;
  width: 2px;
  background-color: transparent;
  min-height: 8px;
}

.bsky-reply-item__body-column {
  flex: 1;
  min-width: 0;
}

.bsky-reply-item__header {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 2px;
  flex-wrap: wrap;
}

.bsky-reply-item__display-name {
  font-weight: 700;
  font-size: 14px;
  color: #0f1419;
  text-decoration: none;
}
.bsky-reply-item__display-name:hover {
  text-decoration: underline;
}

.bsky-reply-item__handle {
  font-size: 13px;
  color: #536471;
  text-decoration: none;
}
.bsky-reply-item__handle:hover {
  text-decoration: underline;
}

.bsky-reply-item__time-link {
  font-size: 13px;
  color: #536471;
  text-decoration: none;
}
.bsky-reply-item__time-link:hover {
  text-decoration: underline;
}

.bsky-reply-item__text {
  font-size: 14px;
  line-height: 1.4;
  word-break: break-word;
  color: #0f1419;
}
CSS;

        return "<style type=\"text/css\">\n" . trim($css) . "\n</style>";
    }
}
