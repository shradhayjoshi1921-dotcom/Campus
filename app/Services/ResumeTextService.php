<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Extracts readable text from a PDF resume without shelling out and without a
 * third-party library (the project ships no Composer dependencies).
 *
 * What it does: walks the file's stream objects, inflates FlateDecode streams,
 * then reads the text-showing operators (Tj, TJ, ', ") including hex strings and
 * standard escapes.
 *
 * What it does not do: decode embedded subset fonts with custom encodings,
 * OCR a scanned page, or read text inside object streams / encrypted files.
 * Those cases return ok = false with a reason, and the UI says the text could
 * not be read rather than pretending the resume is empty.
 */
final class ResumeTextService
{
    private const MAX_BYTES = 8 * 1024 * 1024;

    /** @return array{ok:bool,text:string,pages:int,reason:string} */
    public function extract(string $path): array
    {
        if (!is_file($path)) {
            return ['ok' => false, 'text' => '', 'pages' => 0, 'reason' => 'The file could not be found.'];
        }
        if ((int) filesize($path) > self::MAX_BYTES) {
            return ['ok' => false, 'text' => '', 'pages' => 0, 'reason' => 'The file is too large to analyse.'];
        }
        $raw = (string) file_get_contents($path);
        if (!str_starts_with($raw, '%PDF-')) {
            return ['ok' => false, 'text' => '', 'pages' => 0, 'reason' => 'That file is not a PDF.'];
        }
        if (str_contains($raw, '/Encrypt')) {
            return ['ok' => false, 'text' => '', 'pages' => 0, 'reason' => 'The PDF is encrypted, so its text cannot be read.'];
        }

        $pages = preg_match_all('~/Type\s*/Page[^s]~', $raw);
        $chunks = $this->streams($raw);
        $text = '';
        foreach ($chunks as $chunk) {
            $text .= "\n" . $this->textFromContent($chunk);
        }
        $text = $this->clean($text);
        if (mb_strlen($text) < 40) {
            return [
                'ok' => false,
                'text' => '',
                'pages' => (int) $pages,
                'reason' => 'No readable text was found. The resume may be a scanned image or use an embedded font encoding.',
            ];
        }
        return ['ok' => true, 'text' => $text, 'pages' => max(1, (int) $pages), 'reason' => ''];
    }

    /** @return array<int,string> decoded stream bodies */
    private function streams(string $raw): array
    {
        $out = [];
        if (preg_match_all('~<<(.{0,600}?)>>\s*stream\r?\n~s', $raw, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === false) {
            return $out;
        }
        foreach ($matches as $match) {
            $dictionary = $match[1][0];
            $start = $match[0][1] + strlen($match[0][0]);
            $end = strpos($raw, 'endstream', $start);
            if ($end === false) {
                continue;
            }
            $body = substr($raw, $start, $end - $start);
            if (str_contains($dictionary, 'FlateDecode')) {
                $decoded = @gzuncompress($body);
                if ($decoded === false) {
                    $decoded = @gzinflate($body);
                }
                if ($decoded === false) {
                    continue;
                }
                $body = $decoded;
            } elseif (preg_match('~/(DCTDecode|JPXDecode|CCITTFaxDecode|JBIG2Decode)~', $dictionary) === 1) {
                continue; // image data, not text
            }
            $out[] = $body;
        }
        return $out;
    }

    private function textFromContent(string $content): string
    {
        $text = '';
        // Parenthesised strings followed by a show operator.
        if (preg_match_all('~\(((?:\\\\.|[^\\\\()])*)\)\s*(?:Tj|\'|")~s', $content, $matches) !== false) {
            foreach ($matches[1] as $value) {
                $text .= $this->unescape($value) . ' ';
            }
        }
        // Array form: [(Hello) -120 (world)] TJ
        if (preg_match_all('~\[((?:[^\[\]]|\\\\.)*)\]\s*TJ~s', $content, $arrays) !== false) {
            foreach ($arrays[1] as $array) {
                if (preg_match_all('~\(((?:\\\\.|[^\\\\()])*)\)~s', $array, $parts) !== false) {
                    $text .= $this->unescape(implode('', $parts[1])) . ' ';
                }
            }
        }
        // Hex strings: <48656C6C6F> Tj
        if (preg_match_all('~<([0-9A-Fa-f\s]{4,})>\s*Tj~', $content, $hex) !== false) {
            foreach ($hex[1] as $value) {
                $clean = preg_replace('/\s+/', '', $value) ?? '';
                if (strlen($clean) % 2 === 0 && ctype_xdigit($clean)) {
                    $text .= hex2bin($clean) . ' ';
                }
            }
        }
        return $text;
    }

    private function unescape(string $value): string
    {
        return preg_replace_callback('/\\\\([nrtbf()\\\\]|[0-7]{1,3})/', static function (array $match): string {
            $token = $match[1];
            return match ($token) {
                'n' => "\n", 'r' => "\n", 't' => "\t", 'b' => '', 'f' => '',
                '(' => '(', ')' => ')', '\\' => '\\',
                default => ctype_digit($token) ? chr(octdec($token) % 256) : $token,
            };
        }, $value) ?? '';
    }

    private function clean(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // Keep letters, digits, common punctuation and newlines; drop glyph noise.
        $text = preg_replace('/[^\P{C}\n]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/[ \t]{2,}/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        return trim($text);
    }
}
