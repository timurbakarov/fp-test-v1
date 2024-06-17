<?php

namespace FpDbTest;

use mysqli;

class Database implements DatabaseInterface
{
    const string PLACEHOLDER_SYMBOL = '?';

    const string SPEC_IDENTIFIER = '#';
    const string SPEC_INT = 'd';
    const string SPEC_FLOAT = 'f';
    const string SPEC_ARRAY = 'a';

    const string CONDITIONAL_OPEN_SYMBOL = '{';
    const string CONDITIONAL_CLOSE_SYMBOL = '}';

    const array VALID_SPECS = [
        self::SPEC_IDENTIFIER,
        self::SPEC_INT,
        self::SPEC_FLOAT,
        self::SPEC_ARRAY,
    ];

    private mysqli $mysqli;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    public function buildQuery(string $query, array $args = []): string
    {
        $query = $this->parseConditionals($query, $args);

        return $this->parsePlaceholders($query, $args);
    }

    private function parseConditionals(string $query, array $args): string
    {
        $openBracketExists = str_contains($query, self::CONDITIONAL_OPEN_SYMBOL);
        $closeBracketExists = str_contains($query, self::CONDITIONAL_CLOSE_SYMBOL);

        if (!$openBracketExists && !$closeBracketExists) {
            return $query;
        }

        $openBracketPosition = null;
        $closeBracketPosition = null;

        $placeholderPositions = [];
        $charIndex = 0;
        $argsIndex = 0;

        while($char = $this->nextChar($query, $charIndex)) {
            if ($char === self::CONDITIONAL_OPEN_SYMBOL) {
                if ($openBracketPosition !== null) {
                    throw new DatabaseException('nested conditionals are not supported');
                }

                $openBracketPosition = $charIndex;
                $charIndex++;
                continue;
            }

            if ($char === self::PLACEHOLDER_SYMBOL) {
                if ($openBracketPosition !== null) {
                    if (!array_key_exists($argsIndex, $args)) {
                        throw new DatabaseException('parameter data in conditional is missing');
                    }

                    $placeholderPositions[] = $argsIndex;
                }

                $argsIndex++;
                $charIndex++;
                continue;
            }

            if ($char === self::CONDITIONAL_CLOSE_SYMBOL) {
                if ($openBracketPosition === null) {
                    throw new DatabaseException('open bracket is missing');
                }

                if (count($placeholderPositions) === 0) {
                    throw new DatabaseException('parameter in conditional is missing');
                }

                $closeBracketPosition = $charIndex;

                $skips = array_filter($placeholderPositions, function (int $position) use ($args) {
                    return $args[$position] === Skip::class;
                });

                if (count($skips) > 0) {
                    $query = $this->substrReplace(
                        input: $query,
                        start: $openBracketPosition,
                        length: $closeBracketPosition - $openBracketPosition + 1,
                        replace: '',
                    );

                    $charIndex = $openBracketPosition - 1;
                } else {
                    $query = $this->substrReplace(
                        input: $query,
                        start: $openBracketPosition,
                        length: 1,
                        replace: '',
                    );

                    $query = $this->substrReplace(
                        input: $query,
                        start: $closeBracketPosition - 1,
                        length: 1,
                        replace: '',
                    );

                    $charIndex = $closeBracketPosition - 3;
                }

                $openBracketPosition = null;
                $closeBracketPosition = null;

                $charIndex++;
                continue;
            }

            $charIndex++;
        }

        if ($openBracketPosition !== null && $closeBracketPosition === null) {
            throw new DatabaseException('closing bracket is missing');
        }

        return $query;
    }

    private function substrReplace(string $input,  int $start, int $length, string $replace): string
    {
        return mb_substr($input, 0, $start) . $replace . mb_substr($input, $start + $length);
    }

    private function nextChar(string $value, int $index): string
    {
         return mb_substr($value, $index, 1);
    }

    private function parsePlaceholders(string $query, array $args): string
    {
        $argsIndex = 0;
        while ($placeholder = $this->findPlaceholder($query)) {
            $query = $this->replacePlaceholder($query, $placeholder, $args[$argsIndex]);
            $argsIndex++;
        }

        return $query;
    }

    private function replacePlaceholder(string $query, array $placeholder, mixed $parameter): string
    {
        if ($parameter === Skip::class) {
            return $query;
        }

        $value = match ($placeholder['spec']) {
            null => $this->formatParameter($parameter),
            self::SPEC_IDENTIFIER => $this->makeIdentifiedValue($parameter),
            self::SPEC_INT => $this->makeIntValue($parameter),
            self::SPEC_FLOAT => $this->makeFloatValue($parameter),
            self::SPEC_ARRAY => $this->makeArrayValue($parameter),
            default => throw new DatabaseException('Invalid spec'),
        };

        $hasSpec = $placeholder['spec'] !== null;

        return $this->insertPlaceholderValue($query, $placeholder, $value, $hasSpec);
    }

    private function insertPlaceholderValue(
        string $query,
        array $placeholder,
        mixed $value,
        bool $hasSpec,
    ): string {
        return $this->substrReplace(
            input: $query,
            start: $placeholder['position'],
            length: $hasSpec ? 2 : 1,
            replace: $value,
        );
    }

    private function makeIdentifiedValue(mixed $parameter): string
    {
        if (is_array($parameter)) {
            $params = array_map(
                fn(string $value) => $this->escapeIdentifier($value),
                $parameter,
            );

            return implode(', ', $params);
        }

        if (is_string($parameter)) {
            return $this->escapeIdentifier($parameter);
        }

        throw new DatabaseException("invalid value");
    }

    private function escapeIdentifier(string $value): string
    {
        return '`' . mysqli_real_escape_string($this->mysqli, $value) . '`';
    }

    private function makeArrayValue(mixed $parameter): string
    {
        if (!is_array($parameter)) {
            throw new DatabaseException("invalid value");
        }

        // list
        if (array_key_exists(0, $parameter)) {
            return implode(
                ', ',
                array_map(fn(mixed $val) => $this->formatParameter($val), $parameter),
            );
        }

        // associative array
        $value = [];
        foreach ($parameter as $name => $val) {
            $value[] = '`' . $name . '` = ' . $this->formatParameter($val);
        }

        return implode(', ', $value);
    }

    private function makeIntValue(mixed $parameter): int|string
    {
        if (is_int($parameter) || is_float($parameter)) {
            return  $parameter;
        }

        if (is_null($parameter)) {
            return 'NULL';
        }

        if (is_bool($parameter)) {
            return $parameter ? 1 : 0;
        }

        throw new DatabaseException("invalid value type " . gettype($parameter));
    }

    private function makeFloatValue(mixed $parameter): float|string
    {
        if (is_int($parameter) || is_float($parameter)) {
            return $parameter;
        }

        if (is_null($parameter)) {
            return 'NULL';
        }

        throw new DatabaseException("invalid value");
    }

    private function formatParameter(mixed $parameter): mixed
    {
        if (is_string($parameter)) {
            return "'" . mysqli_real_escape_string($this->mysqli, $parameter) . "'";
        }

        if (is_int($parameter) || is_float($parameter)) {
            return $parameter;
        }

        if (is_bool($parameter)) {
            return $parameter ? 1 : 0;
        }

        if (is_null($parameter)) {
            return 'NULL';
        }

        throw new DatabaseException("invalid parameter value");
    }

    private function findPlaceholder(string $query): array|false
    {
        $index = 0;
        while ($char = mb_substr($query, $index, 1)) {
            if ($char !== self::PLACEHOLDER_SYMBOL) {
                $index++;
                continue;
            }

            $spec = mb_substr($query, $index + 1, 1);

            if ($spec === ' ' || $spec === '') {
                return [
                    'position' => $index,
                    'spec' => null,
                ];
            }

            if (!$this->isValidSpec($spec)) {
                throw new DatabaseException("invalid spec " . $spec);
            }

            return [
                'position' => $index,
                'spec' => $spec,
            ];
        }

        return false;
    }

    private function isValidSpec(string $spec): bool
    {
        return in_array($spec, self::VALID_SPECS);
    }

    public function skip(): string
    {
        return Skip::class;
    }
}
