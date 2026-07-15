<?php
declare(strict_types=1);

enum Environment: string
{
    case Production = 'production';
    case Staging = 'staging';
    case Local = 'local';

    public static function parse(string $value): self
    {
        return match (strtolower(trim($value))) {
            'production', 'prod' => self::Production,
            'staging', 'stage' => self::Staging,
            'local', 'development', 'dev' => self::Local,
            default => throw new RuntimeException('Unsupported Mini Games World environment.'),
        };
    }

    public function isPublic(): bool
    {
        return $this !== self::Local;
    }
}
