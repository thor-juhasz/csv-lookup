<?php declare(strict_types=1);

namespace CsvLookup;

use CsvLookup\Exception\InvalidArgumentException;
use CsvLookup\Exception\RuntimeException;
use Doctrine\Common\Collections\ArrayCollection;
use NumberFormatter;
use function array_filter;

/**
 * Class Line
 *
 * @psalm-template int
 * @psalm-template string
 * @extends ArrayCollection<int, string>
 */
class Line extends ArrayCollection
{
    /**
     * Line constructor.
     *
     * @param int            $lineNumber
     * @param string         $filename
     * @param string[]|array $line
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        private int $lineNumber,
        private string $filename,
        array $line,
    ) {
        /** @psalm-var array<int, string> $filtered */
        $filtered = array_filter($line, 'is_string');

        if ($filtered !== $line) {
            throw new InvalidArgumentException(
                'Line can only contain strings'
            );
        }

        parent::__construct($filtered);
    }

    /**
     * {@inheritDoc}
     *
     * @psalm-param array<int, string> $elements
     *
     * @psalm-suppress LessSpecificImplementedReturnType
     * {@link https://psalm.dev/docs/running_psalm/issues/LessSpecificImplementedReturnType/}
     *
     * @throws InvalidArgumentException
     */
    protected function createFrom(array $elements): Line
    {
        return new Line($this->getLineNumber(), $this->getFilename(), $elements);
    }

    public function getLineNumber(): int
    {
        return $this->lineNumber;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    /**
     * @throws RuntimeException
     */
    public function getColumn(int $column): string
    {
        $val = $this->get($column);

        if ($val === null) {
            $ordinalNumberFormatter = new NumberFormatter('en_US', NumberFormatter::ORDINAL);
            throw new RuntimeException(
                sprintf(
                    'Can not query the %s column, does not exist in line %s of file "%s".',
                    $ordinalNumberFormatter->format($column + 1),
                    $this->getLineNumber(),
                    $this->getFilename()
                )
            );
        }

        return $val;
    }
}
