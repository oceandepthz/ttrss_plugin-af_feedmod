<?php

interface TranslateJapaneseInterface
{
    /**
     * 翻訳対象であるか判定します。
     */
    public function isTranslate(): bool;

    /**
     * 文字列を翻訳します。
     */
    public function translateString(): string;
}
