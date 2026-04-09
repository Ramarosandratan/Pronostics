<?php

namespace App\Service;

final class ImportValueParser
{
    /**
     * @return array{0: ?int, 1: ?int}
     */
    public static function extractMeetingRaceFromCode(?string $value): array
    {
        $result = [null, null];

        if ($value === null) {
            return $result;
        }

        if (preg_match('/R\s*(\d+)\s*C\s*(\d+)|^(\d)(\d)$/i', trim($value), $matches) === 1) {
            $meeting = $matches[1] !== '' ? $matches[1] : $matches[3];
            $race = $matches[2] !== '' ? $matches[2] : $matches[4];
            $result = [(int) $meeting, (int) $race];
        }

        return $result;
    }
}
