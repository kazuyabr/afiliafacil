<?php

class Theme
{
    public const VALID = ['light', 'dark'];

    public static function current(): string
    {
        $cookie = $_COOKIE['theme'] ?? '';
        if (in_array($cookie, self::VALID, true)) return $cookie;

        if (session_status() === PHP_SESSION_ACTIVE) {
            $session = $_SESSION['theme'] ?? '';
            if (in_array($session, self::VALID, true)) return $session;
        }

        return 'light';
    }

    public static function isDark(): bool
    {
        return self::current() === 'dark';
    }

    public static function icon(): string
    {
        return self::isDark() ? 'sun' : 'moon';
    }

    public static function antiFlashScript(): string
    {
        return '<script>(function(){try{var s=localStorage.getItem("theme");'
            . 'var t=s||((window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches)?"dark":"light");'
            . 'document.documentElement.setAttribute("data-theme",t);}catch(e){}})();</script>';
    }

    public static function toggleButton(string $class = 'theme-toggle', string $style = ''): string
    {
        $styleAttr = $style !== '' ? ' style="' . htmlspecialchars($style, ENT_QUOTES) . '"' : '';
        return '<button type="button" class="' . htmlspecialchars($class, ENT_QUOTES) . '" onclick="toggleTheme()" title="Alternar tema claro/escuro"' . $styleAttr . '>'
            . '<i class="fas fa-' . self::icon() . '"></i></button>';
    }
}
