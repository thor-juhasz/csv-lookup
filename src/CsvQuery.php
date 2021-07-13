<?php declare(strict_types=1);

namespace CsvLookup;

use CsvLookup\Exception\InvalidArgumentException;
use CsvLookup\Exception\LogicException;
use function array_filter;
use function count;
use function filter_var;
use function gettype;
use function in_array;
use function is_array;
use function is_string;
use function join;
use function sprintf;
use function strpos;
use function strtolower;
use function strtotime;
use const FILTER_VALIDATE_FLOAT;
use const FILTER_VALIDATE_INT;

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

    /**
     * @psalm-return array{lower: mixed, upper: mixed}
     *
     * @throws InvalidArgumentException
     */
    private function getTupleValue(): array
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

        if (filter_var($this->value, FILTER_VALIDATE_FLOAT) !== false) {
            return "float";
        }

        if (filter_var($this->value, FILTER_VALIDATE_INT) !== false) {
            return "int";
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

    /**
     * @param mixed $columnValue
     */
    private function findByMatches($columnValue): bool
    {
        return $this->getQueryType() === CsvQuery::QUERY_TYPE_MATCHES &&
               $columnValue === $this->getValue();
    }

    /**
     * @param mixed $columnValue
     */
    private function findByMatchesLoose($columnValue): bool
    {
        return $this->getQueryType() === CsvQuery::QUERY_TYPE_MATCHES_LOOSE &&
               $columnValue == $this->getValue();
    }

    /**
     * @param mixed $columnValue
     */
    private function findByNotMatches($columnValue): bool
    {
        return $this->getQueryType() === CsvQuery::QUERY_TYPE_NOT_MATCHES &&
               $columnValue !== $this->getValue();
    }

    /**
     * @param mixed $columnValue
     */
    private function findByNotMatchesLoose($columnValue): bool
    {
        return $this->getQueryType() === CsvQuery::QUERY_TYPE_NOT_MATCHES_LOOSE &&
               $columnValue != $this->getValue();
    }

    private function findByContains(string $columnValue): bool
    {
        return $this->getQueryType() === CsvQuery::QUERY_TYPE_CONTAINS &&
               strpos($columnValue, $this->getValue()) !== false;
    }

    private function findByContainsLoose(string $columnValue): bool
    {
        return $this->getQueryType() === CsvQuery::QUERY_TYPE_CONTAINS_LOOSE &&
               strpos(strtolower($columnValue), strtolower((string) $this->getValue())) !== false;
    }

    private function findByNotContains(string $columnValue): bool
    {
        return $this->getQueryType() === CsvQuery::QUERY_TYPE_NOT_CONTAINS &&
               strpos($columnValue, $this->getValue()) === false;
    }

    private function findByNotContainsLoose(string $columnValue): bool
    {
        return $this->getQueryType() === CsvQuery::QUERY_TYPE_NOT_CONTAINS_LOOSE &&
               strpos(strtolower($columnValue), strtolower((string) $this->getValue())) === false;
    }

    /**
     * @param mixed $columnValue
     */
    private function findByGreaterThan($columnValue): bool
    {
        return $this->getQueryType() === CsvQuery::QUERY_TYPE_GREATER_THAN &&
               $columnValue > $this->getValue();
    }

    /**
     * @param mixed $columnValue
     */
    private function findByGreaterOrEqualThan($columnValue): bool
    {
        return $this->getQueryType() === CsvQuery::QUERY_TYPE_GREATER_OR_EQUAL_THAN &&
               $columnValue >= $this->getValue();
    }

    /**
     * @param mixed $columnValue
     */
    private function findByLowerThan($columnValue): bool
    {
        return $this->getQueryType() === CsvQuery::QUERY_TYPE_LOWER_THAN &&
               $columnValue < $this->getValue();
    }

    /**
     * @param mixed $columnValue
     */
    private function findByLowerOrEqualThan($columnValue): bool
    {
        return $this->getQueryType() === CsvQuery::QUERY_TYPE_LOWER_OR_EQUAL_THAN &&
               $columnValue <= $this->getValue();
    }

    /**
     * @param mixed $columnValue
     *
     * @throws InvalidArgumentException
     */
    private function findByBetween($columnValue): bool
    {
        $value = $this->getTupleValue();
        return $this->getQueryType() === CsvQuery::QUERY_TYPE_BETWEEN &&
               $columnValue > $value['lower'] && $columnValue < $value['upper'];
    }

    /**
     * @param mixed $columnValue
     *
     * @throws InvalidArgumentException
     */
    private function findByBetweenInclusive($columnValue): bool
    {
        $value = $this->getTupleValue();
        return $this->getQueryType() === CsvQuery::QUERY_TYPE_BETWEEN_INCLUSIVE &&
               $columnValue >= $value['lower'] && $columnValue <= $value['upper'];
    }

    public function findByTypeBool(bool $columnValue): bool
    {
        return (
            $this->findByMatches($columnValue) ||
            $this->findByMatchesLoose($columnValue) ||
            $this->findByNotMatches($columnValue) ||
            $this->findByNotMatchesLoose($columnValue)
        );
    }

    /**
     * @param int|float $columnValue
     */
    public function findByTypeNumber($columnValue): bool
    {
        return (
            $this->findByMatches($columnValue) ||
            $this->findByMatchesLoose($columnValue) ||
            $this->findByNotMatches($columnValue) ||
            $this->findByNotMatchesLoose($columnValue) ||
            $this->findByContains((string) $columnValue) ||
            $this->findByContainsLoose((string) $columnValue) ||
            $this->findByNotContains((string) $columnValue) ||
            $this->findByNotContainsLoose((string) $columnValue) ||
            $this->findByGreaterThan($columnValue) ||
            $this->findByGreaterOrEqualThan($columnValue) ||
            $this->findByLowerThan($columnValue) ||
            $this->findByLowerOrEqualThan($columnValue)
        );
    }

    public function findByTypeString(string $columnValue): bool
    {
        return (
            $this->findByMatches($columnValue) ||
            $this->findByMatchesLoose($columnValue) ||
            $this->findByNotMatches($columnValue) ||
            $this->findByNotMatchesLoose($columnValue) ||
            $this->findByContains($columnValue) ||
            $this->findByContainsLoose($columnValue) ||
            $this->findByNotContains($columnValue) ||
            $this->findByNotContainsLoose($columnValue) ||
            $this->findByGreaterThan($columnValue) ||
            $this->findByGreaterOrEqualThan($columnValue) ||
            $this->findByLowerThan($columnValue) ||
            $this->findByLowerOrEqualThan($columnValue)
        );
    }

    /**
     * @throws InvalidArgumentException
     */
    public function findByTypeArray(string $columnValue): bool
    {
        return (
            $this->findByBetween($columnValue) ||
            $this->findByBetweenInclusive($columnValue)
        );
    }

    /**
     * @param mixed $value
     *
     * @throws InvalidArgumentException
     * @throws LogicException
     *
     * @return bool
     */
    public function matchValue($value): bool
    {
        $type = $this->getValueType();

        switch ($type) {
            case "bool":
                if ($this->findByTypeBool((bool) $value)) {
                    return true;
                }
                break;
            case "float":
                if ($this->findByTypeNumber((float) $value)) {
                    return true;
                }
                break;
            case "int":
                if ($this->findByTypeNumber((int) $value)) {
                    return true;
                }
                break;
            case "datetime":
            case "string":
                if ($this->findByTypeString((string) $value)) {
                    return true;
                }
                break;
            case "array":
                if ($this->findByTypeArray((string) $value)) {
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
