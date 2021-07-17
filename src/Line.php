<?php declare(strict_types=1);

namespace CsvLookup;

use CsvLookup\Exception\InvalidArgumentException;
use CsvLookup\Exception\RuntimeException;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use NumberFormatter;
use function array_filter;

class Line
{
    /** @var Collection<int, string>  */
    private Collection $line;

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
        $filtered = array_filter($line, 'is_string');

        if ($filtered !== $line) {
            throw new InvalidArgumentException(
                'Line can only contain strings'
            );
        }

        /** @var Collection<int, string> $collection */
        $collection = new ArrayCollection($line);
        $this->line = $collection;
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
     * @return Collection<int, string>
     */
    public function getLine(): Collection
    {
        return $this->line;
    }

    /**
     * @throws RuntimeException
     */
    public function getColumn(int $column): string
    {
        $val = $this->line->get($column);

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

    public function countColumns(): int
    {
        return $this->line->count();
    }
}
