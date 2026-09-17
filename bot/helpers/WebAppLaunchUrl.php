<?php
declare(strict_types=1);

final class WebAppLaunchUrl
{
    private const ENTRY_PATH = '/app/v110.php?v=1193&domino_stability=30&runtime_fix=2';

    public static function build(string $baseUrl): string
    {
        return rtrim($baseUrl, '/') . self::ENTRY_PATH;
    }
}
