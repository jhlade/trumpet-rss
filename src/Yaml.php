<?php
declare(strict_types=1);

namespace Trumpet;

final class Yaml
{
    public static function parseFile(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException("YAML file not found: $path");
        }
        return self::parse((string)file_get_contents($path));
    }

    public static function parse(string $content): array
    {
        $lines = self::preprocess($content);
        [$result, $consumed] = self::parseBlock($lines, 0, 0);
        if ($consumed !== count($lines)) {
            throw new \RuntimeException("YAML parser stopped at line " . ($consumed + 1));
        }
        return is_array($result) ? $result : [];
    }

    private static function preprocess(string $content): array
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $raw = explode("\n", $content);
        $out = [];
        foreach ($raw as $i => $line) {
            $stripped = self::stripComment($line);
            if (trim($stripped) === '') {
                continue;
            }
            $indent = strspn($stripped, ' ');
            if (str_contains(substr($stripped, 0, $indent), "\t")) {
                throw new \RuntimeException("Tabs not allowed for indentation on line " . ($i + 1));
            }
            $out[] = ['indent' => $indent, 'text' => substr($stripped, $indent), 'lineno' => $i + 1];
        }
        return $out;
    }

    private static function stripComment(string $line): string
    {
        $len = strlen($line);
        $inSingle = false;
        $inDouble = false;
        for ($i = 0; $i < $len; $i++) {
            $c = $line[$i];
            if ($c === "'" && !$inDouble) {
                $inSingle = !$inSingle;
            } elseif ($c === '"' && !$inSingle) {
                if ($inDouble && $i > 0 && $line[$i - 1] === '\\') {
                    continue;
                }
                $inDouble = !$inDouble;
            } elseif ($c === '#' && !$inSingle && !$inDouble) {
                if ($i === 0 || $line[$i - 1] === ' ' || $line[$i - 1] === "\t") {
                    return rtrim(substr($line, 0, $i));
                }
            }
        }
        return rtrim($line);
    }

    /**
     * @return array{0: mixed, 1: int}  parsed value, index of next unconsumed line
     */
    private static function parseBlock(array $lines, int $i, int $indent): array
    {
        if ($i >= count($lines)) {
            return [null, $i];
        }
        $first = $lines[$i];
        if ($first['indent'] < $indent) {
            return [null, $i];
        }
        $baseIndent = $first['indent'];

        if (str_starts_with($first['text'], '- ') || $first['text'] === '-') {
            return self::parseSequence($lines, $i, $baseIndent);
        }
        return self::parseMapping($lines, $i, $baseIndent);
    }

    private static function parseSequence(array $lines, int $i, int $indent): array
    {
        $out = [];
        $n = count($lines);
        while ($i < $n) {
            $line = $lines[$i];
            if ($line['indent'] < $indent) {
                break;
            }
            if ($line['indent'] > $indent) {
                throw new \RuntimeException("Unexpected indent at line {$line['lineno']}");
            }
            if (!str_starts_with($line['text'], '-')) {
                break;
            }
            $rest = ltrim(substr($line['text'], 1));
            if ($rest === '') {
                $i++;
                [$child, $i] = self::parseBlock($lines, $i, $indent + 1);
                $out[] = $child;
                continue;
            }
            // "- key: value"  → first key of a mapping that may continue on next lines
            if (self::looksLikeKey($rest)) {
                // Build a virtual mapping starting at indent + 2 (past "- ")
                $virtualIndent = $indent + 2;
                $lines[$i] = ['indent' => $virtualIndent, 'text' => $rest, 'lineno' => $line['lineno']];
                [$map, $i] = self::parseMapping($lines, $i, $virtualIndent);
                $out[] = $map;
                continue;
            }
            // "- scalar"
            $out[] = self::parseScalar($rest);
            $i++;
        }
        return [$out, $i];
    }

    private static function parseMapping(array $lines, int $i, int $indent): array
    {
        $out = [];
        $n = count($lines);
        while ($i < $n) {
            $line = $lines[$i];
            if ($line['indent'] < $indent) {
                break;
            }
            if ($line['indent'] > $indent) {
                throw new \RuntimeException("Unexpected indent at line {$line['lineno']}");
            }
            if (str_starts_with($line['text'], '-')) {
                break;
            }
            $text = $line['text'];
            $colonPos = self::findKeyColon($text);
            if ($colonPos === -1) {
                throw new \RuntimeException("Expected 'key:' on line {$line['lineno']}: $text");
            }
            $key = trim(substr($text, 0, $colonPos));
            if ($key !== '' && ($key[0] === '"' || $key[0] === "'")) {
                $key = self::parseQuoted($key);
            }
            $rest = trim(substr($text, $colonPos + 1));
            $i++;
            if ($rest === '') {
                if ($i < $n && $lines[$i]['indent'] > $indent) {
                    [$child, $i] = self::parseBlock($lines, $i, $lines[$i]['indent']);
                    $out[$key] = $child;
                } else {
                    $out[$key] = null;
                }
            } else {
                $out[$key] = self::parseScalar($rest);
            }
        }
        return [$out, $i];
    }

    private static function findKeyColon(string $text): int
    {
        $len = strlen($text);
        $inSingle = false;
        $inDouble = false;
        for ($i = 0; $i < $len; $i++) {
            $c = $text[$i];
            if ($c === "'" && !$inDouble) {
                $inSingle = !$inSingle;
            } elseif ($c === '"' && !$inSingle) {
                if ($i > 0 && $text[$i - 1] === '\\') continue;
                $inDouble = !$inDouble;
            } elseif ($c === ':' && !$inSingle && !$inDouble) {
                if ($i === $len - 1 || $text[$i + 1] === ' ' || $text[$i + 1] === "\t") {
                    return $i;
                }
            }
        }
        return -1;
    }

    private static function looksLikeKey(string $text): bool
    {
        return self::findKeyColon($text) !== -1;
    }

    private static function parseScalar(string $text): mixed
    {
        $text = trim($text);
        if ($text === '' || $text === '~' || strtolower($text) === 'null') {
            return null;
        }
        if ($text[0] === '"' || $text[0] === "'") {
            return self::parseQuoted($text);
        }
        if ($text[0] === '[') {
            return self::parseFlowSequence($text);
        }
        if ($text[0] === '{') {
            return self::parseFlowMapping($text);
        }
        $lower = strtolower($text);
        if ($lower === 'true' || $lower === 'yes' || $lower === 'on') return true;
        if ($lower === 'false' || $lower === 'no' || $lower === 'off') return false;
        if (preg_match('/^-?\d+$/', $text)) return (int)$text;
        if (preg_match('/^-?\d+\.\d+$/', $text)) return (float)$text;
        return $text;
    }

    private static function parseQuoted(string $text): string
    {
        $q = $text[0];
        $end = strlen($text) - 1;
        if ($text[$end] !== $q) {
            throw new \RuntimeException("Unterminated quoted string: $text");
        }
        $inner = substr($text, 1, $end - 1);
        if ($q === "'") {
            return str_replace("''", "'", $inner);
        }
        return self::unescapeDouble($inner);
    }

    private static function unescapeDouble(string $s): string
    {
        $out = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($c === '\\' && $i + 1 < $len) {
                $next = $s[$i + 1];
                $out .= match ($next) {
                    'n' => "\n",
                    't' => "\t",
                    'r' => "\r",
                    '"' => '"',
                    '\\' => '\\',
                    '0' => "\0",
                    default => $next,
                };
                $i++;
            } else {
                $out .= $c;
            }
        }
        return $out;
    }

    private static function parseFlowSequence(string $text): array
    {
        if (!str_ends_with($text, ']')) {
            throw new \RuntimeException("Unterminated flow sequence: $text");
        }
        $inner = trim(substr($text, 1, -1));
        if ($inner === '') return [];
        $parts = self::splitFlow($inner);
        return array_map(fn($p) => self::parseScalar(trim($p)), $parts);
    }

    private static function parseFlowMapping(string $text): array
    {
        if (!str_ends_with($text, '}')) {
            throw new \RuntimeException("Unterminated flow mapping: $text");
        }
        $inner = trim(substr($text, 1, -1));
        if ($inner === '') return [];
        $out = [];
        foreach (self::splitFlow($inner) as $pair) {
            $cp = self::findKeyColon($pair);
            if ($cp === -1) {
                throw new \RuntimeException("Bad flow mapping entry: $pair");
            }
            $k = trim(substr($pair, 0, $cp));
            if ($k !== '' && ($k[0] === '"' || $k[0] === "'")) {
                $k = self::parseQuoted($k);
            }
            $out[$k] = self::parseScalar(trim(substr($pair, $cp + 1)));
        }
        return $out;
    }

    private static function splitFlow(string $s): array
    {
        $out = [];
        $buf = '';
        $depth = 0;
        $inSingle = false;
        $inDouble = false;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($c === "'" && !$inDouble) $inSingle = !$inSingle;
            elseif ($c === '"' && !$inSingle) $inDouble = !$inDouble;
            elseif (!$inSingle && !$inDouble) {
                if ($c === '[' || $c === '{') $depth++;
                elseif ($c === ']' || $c === '}') $depth--;
                elseif ($c === ',' && $depth === 0) {
                    $out[] = $buf;
                    $buf = '';
                    continue;
                }
            }
            $buf .= $c;
        }
        if ($buf !== '') $out[] = $buf;
        return $out;
    }
}
