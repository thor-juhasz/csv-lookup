<?php declare(strict_types=1);

namespace CsvLookup;

use CsvLookup\Exception\InvalidArgumentException;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use function sprintf;

class Result
{
    /** @var Line|null */
    private ?Line $headers = null;

    /**
     * @var Collection<int, Line>
     */
    private Collection $matches;

    public function __construct(
        /** @psalm-readonly */
        private string $filename,
    ) {
        $this->matches = new ArrayCollection();
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
}