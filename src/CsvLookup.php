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

use CsvLookup\Exception\InaccessibleException;
use CsvLookup\Exception\InvalidArgumentException;
use CsvLookup\Exception\LogicException;
use CsvLookup\Exception\RuntimeException;
use CsvLookup\Report\GenerateReport;
use CsvLookup\Report\Html\HtmlReport;
use CsvLookup\Report\Text\TextReport;
use CsvLookup\Report\Xml\XmlReport;
use FilesystemIterator;
use SplFileInfo;
use SplFileObject;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use UnexpectedValueException;
use function is_dir;
use function is_file;
use function sprintf;
use function strtolower;

class CsvLookup
{
    public const SKIP_REASON_NOT_FILE     = 'not_a_file';
    public const SKIP_REASON_NOT_READABLE = 'not_readable';
    public const SKIP_REASON_NOT_CSV      = 'not_csv_file';

    /** @var CsvQuery[] */
    private array $conditions = [];

    private string $path;

    /** @var Result[] $results */
    private array $results = [];

    /** @var array<int, array<string, string>> $skippedFiles */
    private array $skippedFiles = [];

    private ?string $delimiter = null;

    private string $enclosureCharacter = CsvFile::DEFAULT_ENCLOSURE;

    private string $escapeCharacter = CsvFile::DEFAULT_ESCAPE;

    /**
     * @return CsvQuery[]
     */
    public function getConditions(): array
    {
        return $this->conditions;
    }

    private function getPath(): string
    {
        return $this->path;
    }

    /**
     * @return Result[]
     */
    public function getResults(): array
    {
        return $this->results;
    }

    public function getSkippedFiles(): array
    {
        return $this->skippedFiles;
    }

    public function getDelimiter(): ?string
    {
        return $this->delimiter;
    }

    public function setDelimiter(string $delimiter): CsvLookup
    {
        $this->delimiter = $delimiter;

        return $this;
    }

    public function getEnclosureCharacter(): string
    {
        return $this->enclosureCharacter;
    }

    public function setEnclosureCharacter(string $enclosureCharacter): CsvLookup
    {
        $this->enclosureCharacter = $enclosureCharacter;

        return $this;
    }

    public function getEscapeCharacter(): string
    {
        return $this->escapeCharacter;
    }

    public function setEscapeCharacter(string $escapeCharacter): CsvLookup
    {
        $this->escapeCharacter = $escapeCharacter;

        return $this;
    }

    /**
     * @param CsvQuery[]  $conditions
     * @param string      $path
     * @param bool|null   $hasHeaders
     *
     * @throws InaccessibleException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RuntimeException
     *
     * @return Result[]
     */
    public function search(
        array $conditions,
        string $path,
        ?bool $hasHeaders = null,
    ): array {
        $this->conditions = $conditions;
        $this->path       = $path;

        if (is_dir($path)) {
            $this->dirSearch($conditions, $hasHeaders);
        } elseif (is_file($path)) {
            $this->fileSearch($conditions, $path, $hasHeaders);
        }

        return $this->results;
    }

    /**
     * @psalm-param GenerateReport::REPORT_FORMAT_* $format
     *
     * @throws InvalidArgumentException
     * @throws LoaderError
     * @throws LogicException
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public function generateReport(
        string $format,
        string $output = ""
    ): void {
        switch ($format) {
            case GenerateReport::REPORT_FORMAT_TEXT:
                $generator = new TextReport($this->getPath(), $this->getConditions(), $this->getResults());
                $generator($output);
                break;
            case GenerateReport::REPORT_FORMAT_XML:
                $generator = new XmlReport($this->getPath(), $this->getConditions(), $this->getResults());
                $generator($output);
                break;
            case GenerateReport::REPORT_FORMAT_HTML:
                $generator = new HtmlReport($this->getPath(), $this->getConditions(), $this->getResults());
                $generator($output);
                break;
            default:
                throw new InvalidArgumentException(
                    sprintf(
                        'Can not generate report with format "%s", only the following are supported: %s',
                        $format,
                        join(", ", GenerateReport::supportedFormats())
                    )
                );
        }
    }

    /**
     * @param CsvQuery[]  $conditions
     * @param bool|null   $hasHeaders
     *
     * @throws InaccessibleException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RuntimeException
     */
    private function dirSearch(
        array $conditions,
        ?bool $hasHeaders = null,
    ): void {
        $dirIterator = $this->getDirIterator($this->getPath());

        /** @var SplFileInfo $file */
        foreach ($dirIterator as $file) {
            if ($file->isFile() === false) {
                $this->skippedFiles[] = [
                    'file' => $file->getRealPath(),
                    'reason' => CsvLookup::SKIP_REASON_NOT_FILE,
                ];

                continue;
            }

            if ($file->isReadable() === false) {
                $this->skippedFiles[] = [
                    'file' => $file->getRealPath(),
                    'reason' => CsvLookup::SKIP_REASON_NOT_READABLE,
                ];

                continue;
            }

            if (strtolower($file->getExtension()) !== 'csv') {
                $this->skippedFiles[] = [
                    'file' => $file->getRealPath(),
                    'reason' => CsvLookup::SKIP_REASON_NOT_CSV,
                ];

                continue;
            }

            $this->fileSearch($conditions, $file->getRealPath(), $hasHeaders);
        }
    }

    /**
     * @param CsvQuery[]  $conditions
     * @param string      $filePath
     * @param bool|null   $hasHeaders
     *
     * @throws InaccessibleException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RuntimeException
     */
    private function fileSearch(
        array $conditions,
        string $filePath,
        ?bool $hasHeaders = null,
    ): void {
        $file = new SplFileObject($filePath);

        $csvFile = new CsvFile(
            $file->getRealPath(),
            $hasHeaders,
            $this->getDelimiter(),
            $this->getEnclosureCharacter(),
            $this->getEscapeCharacter(),
        );

        $this->results[] = $csvFile->findBy($conditions);
    }

    /**
     * @param string $dir
     *
     * @throws RuntimeException
     *
     * @return FilesystemIterator
     */
    private function getDirIterator(string $dir): FilesystemIterator
    {
        try {
            $dirIterator = new FilesystemIterator(
                $dir, FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::SKIP_DOTS
            );
        } catch (UnexpectedValueException $e) {
            throw new RuntimeException(
                sprintf('Could not read dir %s.', $dir), 0, $e
            );
        }

        return $dirIterator;
    }
}
