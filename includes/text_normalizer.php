<?php
declare(strict_types=1);

if (!function_exists('app_text_mojibake_score')) {
    function app_text_mojibake_score(string $value): int
    {
        $score = 0;

        if (preg_match_all('/\x{00C3}[\x{0080}-\x{00BF}]/u', $value, $matches)) {
            $score += count($matches[0]) * 3;
        }

        if (preg_match_all('/\x{00C2}[\x{0080}-\x{00BF}]/u', $value, $matches)) {
            $score += count($matches[0]) * 2;
        }

        if (preg_match_all('/\x{00E2}\x{20AC}[\x{0099}\x{009C}\x{009D}\x{2013}\x{2014}\x{2022}\x{2026}]?/u', $value, $matches)) {
            $score += count($matches[0]) * 4;
        }

        $score += substr_count($value, "\u{FFFD}") * 5;

        return $score;
    }
}

if (!function_exists('app_text_fix_mojibake')) {
    function app_text_fix_mojibake(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $hasMarker = preg_match('/\x{00C3}[\x{0080}-\x{00BF}]|\x{00C2}[\x{0080}-\x{00BF}]|\x{00E2}\x{20AC}|\x{FFFD}/u', $value) === 1;
        if (!$hasMarker) {
            return $value;
        }

        $best = $value;
        $bestScore = app_text_mojibake_score($best);

        foreach (['Windows-1252', 'ISO-8859-1'] as $targetEncoding) {
            $candidate = @mb_convert_encoding($value, $targetEncoding, 'UTF-8');
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }

            if (!mb_check_encoding($candidate, 'UTF-8')) {
                continue;
            }

            $candidateScore = app_text_mojibake_score($candidate);
            if ($candidateScore < $bestScore) {
                $best = $candidate;
                $bestScore = $candidateScore;
            }
        }

        return str_replace("\u{00A0}", ' ', $best);
    }
}

if (!function_exists('app_text_fix_mojibake_deep')) {
    /**
     * @param mixed $value
     * @return mixed
     */
    function app_text_fix_mojibake_deep($value)
    {
        if (is_string($value)) {
            return (string) app_text_fix_mojibake($value);
        }

        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = app_text_fix_mojibake_deep($item);
        }

        return $value;
    }
}
