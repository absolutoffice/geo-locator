<?php

declare(strict_types=1);

namespace GeoLocator\Config;

use GeoLocator\Exception\ConfigurationException;

/**
 * Reads a small, deterministic subset of dotenv syntax into an array.
 *
 * Loading does not mutate getenv(), $_ENV or $_SERVER. Variable interpolation is
 * not supported, and repeated keys are resolved in favour of the last entry.
 */
final class EnvLoader
{
    /**
     * Parse assignments, optional export prefixes, quoted values and comments.
     *
     * @param string $path Environment file path selected by the consuming application.
     * @return array<string, string> Parsed values, or an empty array if the path is not a regular file.
     *
     * @throws ConfigurationException If reading fails or an assignment, key or quoted value is malformed.
     */
    public static function load(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new ConfigurationException('Unable to read GeoLocator environment file.');
        }

        $values = [];

        foreach ($lines as $lineNumber => $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            $separator = strpos($line, '=');
            if ($separator === false) {
                throw new ConfigurationException(sprintf(
                    'Invalid GeoLocator .env entry on line %d.',
                    $lineNumber + 1,
                ));
            }

            $key = trim(substr($line, 0, $separator));
            $value = trim(substr($line, $separator + 1));

            if (preg_match('/^[A-Z_][A-Z0-9_]*$/i', $key) !== 1) {
                throw new ConfigurationException(sprintf(
                    'Invalid GeoLocator .env key on line %d.',
                    $lineNumber + 1,
                ));
            }

            $values[$key] = self::parseValue($value, $lineNumber + 1);
        }

        return $values;
    }

    /**
     * Decode one dotenv value using its quote style and supported escape sequences.
     *
     * Unquoted values lose inline comments introduced by a space followed by #.
     * Single-quoted values are literal; double-quoted values decode newline, carriage
     * return, tab, quote and backslash escapes.
     *
     * @param string $value Trimmed text to the right of the assignment separator.
     * @param int $lineNumber One-based source line used in syntax-error messages.
     * @return string Decoded value, including an empty string when the assignment is empty.
     *
     * @throws ConfigurationException If a quoted value lacks its matching final quote.
     */
    private static function parseValue(string $value, int $lineNumber): string
    {
        if ($value === '') {
            return '';
        }

        $first = $value[0];
        if ($first !== '"' && $first !== "'") {
            $comment = strpos($value, ' #');
            if ($comment !== false) {
                $value = substr($value, 0, $comment);
            }

            return trim($value);
        }

        if (strlen($value) < 2 || !str_ends_with($value, $first)) {
            throw new ConfigurationException(sprintf(
                'Unclosed quoted value in GeoLocator .env on line %d.',
                $lineNumber,
            ));
        }

        $value = substr($value, 1, -1);

        if ($first === '"') {
            return str_replace(
                ['\\n', '\\r', '\\t', '\\"', '\\\\'],
                ["\n", "\r", "\t", '"', '\\'],
                $value,
            );
        }

        return $value;
    }
}
