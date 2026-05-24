<?php
declare(strict_types=1);

namespace Trumpet;

final class FeedFetcher
{
    public function __construct(private Logger $log) {}

    /**
     * @return list<array{guid:string, title:string, link:string, summary:string, pub_date:?string, timestamp:int}>
     */
    public function fetch(string $url): array
    {
        require_once __DIR__ . '/../vendor/rss-php/src/Feed.php';
        try {
            $feed = \Feed::load($url);
        } catch (\Throwable $e) {
            $this->log->warn("Failed to load feed $url: " . $e->getMessage());
            return [];
        }

        $items = [];
        // RSS: $feed->item; Atom: $feed->entry. Feed::load normalizes both with url + timestamp.
        // Note: \Feed uses __get magic so isset() always returns false. We probe via count().
        $rssNodes = $feed->item;
        $nodes = ($rssNodes !== null && count($rssNodes) > 0) ? $rssNodes : $feed->entry;
        if ($nodes === null || count($nodes) === 0) {
            return [];
        }

        foreach ($nodes as $node) {
            $title = trim((string)$node->title);
            $link = (string)($node->url ?? '');
            if ($link === '') {
                $link = (string)$node->link;
            }
            $guidStr = (string)$node->guid;
            if ($guidStr === '') $guidStr = (string)$node->id;
            $guid = $guidStr !== '' ? $guidStr : ($link !== '' ? $link : $title);
            if ($guid === '') {
                continue;
            }
            $summary = (string)$node->description;
            if ($summary === '') $summary = (string)$node->summary;
            if ($summary === '') $summary = (string)$node->content;

            $ts = (int)$node->timestamp;
            $pub = $ts > 0 ? date(DATE_RFC3339, $ts) : null;

            $items[] = [
                'guid' => $guid,
                'title' => $title,
                'link' => $link,
                'summary' => trim(strip_tags($summary)),
                'pub_date' => $pub,
                'timestamp' => $ts,
            ];
        }
        return $items;
    }
}
