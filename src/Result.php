<?php declare(strict_types=1);
/*
 * This file is part of csv-lookup.
 *
 * (c) Thor Juhasz <thor@juhasz.pro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CsvLookup;

use CsvLookup\Exception\InvalidArgumentException;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use function sprintf;

class Result
{
    private int $totalLines = 0;

    /**
     * @var Collection<int, Line>
     */
    private Collection $matches;

    public function __construct(
        /** @psalm-readonly */
        private string $filename,
        private string $delimiter,
        private string $enclosureCharacter,
        private string $escapeCharacter,
        private ?Line $headers = null,
    ) {
        $this->matches = new ArrayCollection();
    }

    public function getDelimiter(): string
    {
        return $this->delimiter;
    }

    public function getEnclosureCharacter(): string
    {
        return $this->enclosureCharacter;
    }

    public function getEscapeCharacter(): string
    {
        return $this->escapeCharacter;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getHeaders(): ?Line
    {
        return $this->headers;
    }

    /**
     * @param Line $headers
     */
    public function setHeaders(Line $headers): void
    {
        $this->headers = $headers;
    }

    /** @return Collection<int, Line> */
    public function getMatches(): Collection
    {
        return $this->matches;
    }

    /**
     * @param Collection<int, Line> $matches
     *
     * @throws InvalidArgumentException
     */
    public function setMatches(Collection $matches): void
    {
        $filtered = $matches->filter(fn($match) => $match instanceof Line);
        if ($filtered !== $matches) {
            throw new InvalidArgumentException(
                sprintf(
                    'Matches can only contain instances of %s',
                    Line::class
                )
            );
        }

        $this->matches = $matches;
    }

    public function addMatch(Line $line): void
    {
        $this->matches->add($line);
    }

    public function removeMatch(Line $line): void
    {
        if ($this->matches->contains($line)) {
            $this->matches->removeElement($line);
        }
    }

    public function getTotalLines(): int
    {
        return $this->totalLines;
    }

    public function setTotalLines(int $totalLines): void
    {
        $this->totalLines = $totalLines;
    }


}
