<?php
declare(strict_types=1);

final class AccountDataZipWriter
{
    /** @param array<string,string> $entries */
    public static function write(string $path, array $entries): array
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Не удалось подготовить каталог экспорта.');
        }

        $local = '';
        $central = '';
        $offset = 0;
        $count = 0;
        [$dosTime, $dosDate] = self::dosTimestamp();

        foreach ($entries as $name => $data) {
            $name = self::normalizeName($name);
            if ($name === '') continue;
            $data = (string)$data;
            $crc = (int)hexdec(hash('crc32b', $data));
            $size = strlen($data);
            $nameLength = strlen($name);

            $header = pack(
                'VvvvvvVVVvv',
                0x04034b50,
                20,
                0,
                0,
                $dosTime,
                $dosDate,
                $crc,
                $size,
                $size,
                $nameLength,
                0
            );
            $local .= $header . $name . $data;

            $central .= pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014b50,
                20,
                20,
                0,
                0,
                $dosTime,
                $dosDate,
                $crc,
                $size,
                $size,
                $nameLength,
                0,
                0,
                0,
                0,
                0,
                $offset
            ) . $name;

            $offset += strlen($header) + $nameLength + $size;
            $count++;
        }

        $centralOffset = strlen($local);
        $eocd = pack(
            'VvvvvVVv',
            0x06054b50,
            0,
            0,
            $count,
            $count,
            strlen($central),
            $centralOffset,
            0
        );
        $payload = $local . $central . $eocd;
        if (file_put_contents($path, $payload, LOCK_EX) === false) {
            throw new RuntimeException('Не удалось записать ZIP-экспорт.');
        }
        @chmod($path, 0600);

        return [
            'path'=>$path,
            'size'=>strlen($payload),
            'sha256'=>hash('sha256', $payload),
            'entries'=>$count,
        ];
    }

    private static function normalizeName(string $name): string
    {
        $name = str_replace('\\', '/', trim($name));
        $name = ltrim($name, '/');
        if ($name === '' || str_contains($name, '../') || str_contains($name, "\0")) {
            throw new InvalidArgumentException('Некорректное имя файла в ZIP.');
        }
        return $name;
    }

    /** @return array{0:int,1:int} */
    private static function dosTimestamp(): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $year = max(1980, (int)$now->format('Y'));
        $time = ((int)$now->format('H') << 11)
            | ((int)$now->format('i') << 5)
            | intdiv((int)$now->format('s'), 2);
        $date = (($year - 1980) << 9)
            | ((int)$now->format('n') << 5)
            | (int)$now->format('j');
        return [$time, $date];
    }
}
