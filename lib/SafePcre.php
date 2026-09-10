<?php
class SafePcre
{
    public static function bootstrap(): void
    {
        @ini_set('pcre.backtrack_limit', '50000000');
        @ini_set('pcre.recursion_limit', '5000000');
        @ini_set('pcre.jit', '0');
    }

    public static function replaceCallback(string $pattern, callable $cb, string $subject): string
    {
        $result = @preg_replace_callback($pattern, $cb, $subject);
        return $result === null ? $subject : $result;
    }

    public static function replace(string $pattern, string $replacement, string $subject, int $limit = -1): string
    {
        $result = $limit >= 0
            ? @preg_replace($pattern, $replacement, $subject, $limit)
            : @preg_replace($pattern, $replacement, $subject);
        return $result === null ? $subject : $result;
    }
}
