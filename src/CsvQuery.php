<?php declare(strict_types=1);

namespace CsvLookup;

use CsvLookup\Exception\InvalidArgumentException;
use CsvLookup\Exception\LogicException;
use function array_filter;
use function count;
use function gettype;
use function in_array;
use function intval;
use function is_array;
use function is_float;
use function is_string;
use function join;
use function preg_match;
use function sprintf;
use function strpos;
use function strtolower;
use function strtotime;
use function trim;

final class CsvQuery
{
    public const QUERY_TYPE_MATCHES               = 'matches';
    public const QUERY_TYPE_MATCHES_LOOSE         = 'matches_loose';
    public const QUERY_TYPE_NOT_MATCHES           = 'not_matches';
    public const QUERY_TYPE_NOT_MATCHES_LOOSE     = 'not_matches';
    public const QUERY_TYPE_CONTAINS              = 'contains';
    public const QUERY_TYPE_CONTAINS_LOOSE        = 'contains_loose';
    public const QUERY_TYPE_NOT_CONTAINS          = 'not_contains';
    public const QUERY_TYPE_NOT_CONTAINS_LOOSE    = 'not_contains_loose';
    public const QUERY_TYPE_GREATER_THAN          = 'greater';
    public const QUERY_TYPE_GREATER_OR_EQUAL_THAN = 'greater_or_equal';
    public const QUERY_TYPE_LOWER_THAN            = 'lower';
    public const QUERY_TYPE_LOWER_OR_EQUAL_THAN   = 'lower_or_equal';
    public const QUERY_TYPE_BETWEEN               = 'between';
    public const QUERY_TYPE_BETWEEN_INCLUSIVE     = 'between_inclusive';

    private string $column;

    /** @psalm-var static::QUERY_TYPE_*  */
    private string $queryType;

    /** @var mixed  */
    private $value;

    /**
     * CsvQuery constructor.
     *
     * @param string $column
     * @param string $queryType
     * @param mixed  $value
     *
     * @psalm-param CsvQuery::QUERY_TYPE_* $queryType
     *
     * @throws InvalidArgumentException
     */
    public function __construct(string $column, string $queryType, $value)
    {
        $this->setColumn($column);
        $this->setQueryType($queryType);
        $this->setValue($value);
    }

    public function getColumn(): string
    {
        return $this->column;
    }

    public function setColumn(string $column): CsvQuery
    {
        $this->column = $column;

        return $this;
    }

    public function getQueryType(): string
    {
        return $this->queryType;
    }

    /**
     * @psalm-param CsvQuery::QUERY_TYPE_* $queryType
     *
     * @throws InvalidArgumentException
     */
    public function setQueryType(string $queryType): CsvQuery
    {
        if (in_array($queryType, CsvQuery::allowedQueryTypes()) === false) {
            throw new InvalidArgumentException(
                sprintf(
                    'Can not use given query type (%s). Use one of: %s',
                    $queryType,
                    join(", ", CsvQuery::allowedQueryTypes())
                )
            );
        }

        $this->queryType = $queryType;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * @param mixed $value
     *
     * @throws InvalidArgumentException
     */
    public function setValue($value): CsvQuery
    {
        // With these types, the $value has to be an array tuple
        $arrayQueryTypes = [
            CsvQuery::QUERY_TYPE_BETWEEN,
            CsvQuery::QUERY_TYPE_BETWEEN_INCLUSIVE
        ];

        if (
            in_array($this->queryType, $arrayQueryTypes) === false ||
            (is_array($value) && count($value) === 2)
        ) {
            $this->value = $value;

            return $this;
        }

        throw new InvalidArgumentException(
            'When query type is %s or %s, value must be an array with 2 elements'
        );
    }

    private function getValueAsBool(): string
    {
        if (in_array($this->getValue(), [true, "true"], true)) {
            return "true";
        }

        return "false";
    }

    /**
     * @throws LogicException
     */
    private function getValueAsString(): string
    {
        if (is_array($this->value)) {
            throw new LogicException(
                'Can not fetch CsvQuery::$value as string, value is of type array'
            );
        }

        return (string) $this->value;
    }

    /**
     * @psalm-return array{lower: mixed, upper: mixed}
     *
     * @throws InvalidArgumentException
     */
    private function getValueAsTuple(): array
    {
        if (is_array($this->value) === false || count($this->value) !== 2) {
            throw new InvalidArgumentException(
                sprintf(
                    'When query type is "%s" or "%s", value must be an array with 2 elements',
                    CsvQuery::QUERY_TYPE_BETWEEN,
                    CsvQuery::QUERY_TYPE_BETWEEN_INCLUSIVE
                )
            );
        }

        return [
            'lower' => $this->value[0],
            'upper' => $this->value[1],
        ];
    }

    public static function allowedQueryTypes(): array
    {
        return [
            CsvQuery::QUERY_TYPE_MATCHES,
            CsvQuery::QUERY_TYPE_MATCHES_LOOSE,
            CsvQuery::QUERY_TYPE_NOT_MATCHES,
            CsvQuery::QUERY_TYPE_NOT_MATCHES_LOOSE,
            CsvQuery::QUERY_TYPE_CONTAINS,
            CsvQuery::QUERY_TYPE_CONTAINS_LOOSE,
            CsvQuery::QUERY_TYPE_NOT_CONTAINS,
            CsvQuery::QUERY_TYPE_NOT_CONTAINS_LOOSE,
            CsvQuery::QUERY_TYPE_GREATER_THAN,
            CsvQuery::QUERY_TYPE_GREATER_OR_EQUAL_THAN,
            CsvQuery::QUERY_TYPE_LOWER_THAN,
            CsvQuery::QUERY_TYPE_LOWER_OR_EQUAL_THAN,
            CsvQuery::QUERY_TYPE_BETWEEN,
            CsvQuery::QUERY_TYPE_BETWEEN_INCLUSIVE,
        ];
    }

    /**
     * @throws LogicException
     */
    public function getValueType(): string
    {
        if ($this->value === null) {
            throw new LogicException(
                'Can not get type of value, no value has been set.'
            );
        }

        $booleanValues = [true, false, "true", "false",];

        if (in_array($this->value, $booleanValues, true)) {
            return "bool";
        }

        if (
            is_int($this->value) ||
            (
                is_string($this->value) &&
                intval(preg_match('/^[0-9]+$/', $this->value)) > 0
            )
        ) {
            return "int";
        }

        if (
            is_float($this->value) ||
            (
                is_string($this->value) &&
                intval(preg_match('/^([+\-])?([0-9]*[.])?[0-9]+$/', $this->value)) > 0
            )
        ) {
            return "float";
        }

        if (is_string($this->value) && strtotime($this->value) !== false) {
            return "datetime";
        }

        if (is_string($this->value)) {
            return "string";
        }

        if (
            is_array($this->value) &&
            array_filter($this->value, 'is_string') === $this->value
        ) {
            return "array";
        }

        throw new LogicException('Can not get type of value.');
    }

    private function findByMatches(string $columnValue, string $value): bool
    {
        return $this->getQueryType() === CsvQuery::QUERY_TYPE_MATCHES &&
               $columnValue === $value;
    }

    private function findByMatchesLoose(string $columnValue, string $value): bool
    {
        return $this->getQueryType() === CsvQuery::QUERY_TYPE_MATCHES_LOOSE &&
               trim($columnValue) == trim($value);
    }

    private function findByNotMatches(string $columnValue, string $value): bool
    {
        return $this->getQueryType() === CsvQuery::QUERY_TYPE_NOT_MATCHES &&
               $columnValue !== $value;
    }

    private function findByNotMatchesLoose(string $columnValue, string $value): bool
    {
        return $this->getQueryType() === CsvQuery::QUERY_TYPE_NOT_MATCHES_LOOSE &&
               trim($columnValue) != trim($value);
    }

    private function findByContains(string $columnValue, string $value): bool
    {
        return $this->getQueryType() === CsvQuery::QUERY_TYPE_CONTAINS &&
               strpos($columnValue, $value) !== false;
    }

    private function findByContainsLoose(string $columnValue, string $value): bool
    {
        return $this->getQueryType() === CsvQuery::QUERY_TYPE_CONTAINS_LOOSE &&
               strpos(strtolower($columnValue), strtolower($value)) !== false;
    }

    private function findByNotContains(string $columnValue, string $value): bool
    {
        return $this->getQueryType() === CsvQuery::QUERY_TYPE_NOT_CONTAINS &&
               strpos($columnValue, $value) === false;
    }

    private function findByNotContainsLoose(string $columnValue, string $value): bool
    {
        return $this->getQueryType() === CsvQuery::QUERY_TYPE_NOT_CONTAINS_LOOSE &&
               strpos(strtolower($columnValue), strtolower($value)) === false;
    }

    private function findByGreaterThan(string $columnValue, string $value): bool
    {
        return $this->getQueryType() === CsvQuery::QUERY_TYPE_GREATER_THAN &&
               $columnValue > $value;
    }

    private function findByGreaterOrEqualThan(string $columnValue, string $value): bool
    {
        return $this->getQueryType() === CsvQuery::QUERY_TYPE_GREATER_OR_EQUAL_THAN &&
               $columnValue >= $value;
    }

    private function findByLowerThan(string $columnValue, string $value): bool
    {
        return $this->getQueryType() === CsvQuery::QUERY_TYPE_LOWER_THAN &&
               $columnValue < $value;
    }

    private function findByLowerOrEqualThan(string $columnValue, string $value): bool
    {
        return $this->getQueryType() === CsvQuery::QUERY_TYPE_LOWER_OR_EQUAL_THAN &&
               $columnValue <= $value;
    }

    /**
     * @param string $columnValue
     * @param array  $value
     *
     * @psalm-param array{lower: mixed, upper: mixed} $value
     *
     * @return bool
     */
    private function findByBetween(string $columnValue, array $value): bool
    {
        return $this->getQueryType() === CsvQuery::QUERY_TYPE_BETWEEN &&
               $columnValue > $value['lower'] && $columnValue < $value['upper'];
    }

    /**
     * @param string $columnValue
     * @param array  $value
     *
     * @psalm-param array{lower: mixed, upper: mixed} $value
     *
     * @return bool
     */
    private function findByBetweenInclusive(string $columnValue, array $value): bool
    {
        return $this->getQueryType() === CsvQuery::QUERY_TYPE_BETWEEN_INCLUSIVE &&
               $columnValue >= $value['lower'] && $columnValue <= $value['upper'];
    }

    public function findByTypeBool(string $columnValue, string $value): bool
    {
        return (
            $this->findByMatches($columnValue, $value) ||
            $this->findByMatchesLoose($columnValue, $value) ||
            $this->findByNotMatches($columnValue, $value) ||
            $this->findByNotMatchesLoose($columnValue, $value)
        );
    }

    public function findByTypeString(string $columnValue, string $value): bool
    {
        return (
            $this->findByMatches($columnValue, $value) ||
            $this->findByMatchesLoose($columnValue, $value) ||
            $this->findByNotMatches($columnValue, $value) ||
            $this->findByNotMatchesLoose($columnValue, $value) ||
            $this->findByContains($columnValue, $value) ||
            $this->findByContainsLoose($columnValue, $value) ||
            $this->findByNotContains($columnValue, $value) ||
            $this->findByNotContainsLoose($columnValue, $value) ||
            $this->findByGreaterThan($columnValue, $value) ||
            $this->findByGreaterOrEqualThan($columnValue, $value) ||
            $this->findByLowerThan($columnValue, $value) ||
            $this->findByLowerOrEqualThan($columnValue, $value)
        );
    }

    /**
     * @psalm-param array{lower: mixed, upper: mixed} $value
     */
    public function findByTypeArray(string $columnValue, array $value): bool
    {
        return (
            $this->findByBetween($columnValue, $value) ||
            $this->findByBetweenInclusive($columnValue, $value)
        );
    }

    /**
     * @param string $columnValue
     *
     * @throws InvalidArgumentException
     * @throws LogicException
     *
     * @return bool
     */
    public function matchValue(string $columnValue): bool
    {
        $type = $this->getValueType();

        switch ($type) {
            case "bool":
                $value = $this->getValueAsBool();
                if ($this->findByTypeBool($columnValue, $value)) {
                    return true;
                }
                break;
            case "float":
            case "int":
            case "datetime":
            case "string":
                $value = $this->getValueAsString();
                if ($this->findByTypeString($columnValue, $value)) {
                    return true;
                }
                break;
            case "array":
                $value = $this->getValueAsTuple();
                if ($this->findByTypeArray($columnValue, $value)) {
                    return true;
                }
                break;
            default:
                throw new LogicException(
                    sprintf(
                        'Can not do query on unsupported type: %s',
                        gettype($this->getValue())
                    )
                );
        }

        return false;
    }
}
