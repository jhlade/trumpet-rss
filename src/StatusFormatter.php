<?php
declare(strict_types=1);

namespace Trumpet;

final class StatusFormatter
{
    public const MAX_LEN = 500;

    /**
     * @param array{guid:string, title:string, link:string, summary:string, pub_date:?string, timestamp:int} $item
     */
    public static function render(string $template, array $item): string
    {
        $vars = [
            '{title}'    => $item['title'],
            '{link}'     => $item['link'],
            '{summary}'  => $item['summary'],
            '{pub_date}' => $item['pub_date'] ?? '',
            '{guid}'     => $item['guid'],
        ];
        $text = strtr($template, $vars);
        return self::truncate($text, self::MAX_LEN);
    }

    private static function truncate(string $text, int $max): string
    {
        if (function_exists('mb_strlen')) {
            if (\mb_strlen($text) <= $max) return $text;
            return \mb_substr($text, 0, $max - 1) . '…';
        }
        // Fallback without mbstring: count UTF-8 code points by regex.
        $count = preg_match_all('//u', $text, $_) ?: strlen($text);
        if ($count <= $max) return $text;
        preg_match_all('/./us', $text, $chars);
        return implode('', array_slice($chars[0], 0, $max - 1)) . '…';
    }
}
