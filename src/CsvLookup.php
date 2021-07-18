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
use FilesystemIterator;
use SplFileInfo;
use SplFileObject;
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

    /** @var Result[] */
    private array $results;

    /** @var array<int, array<string, string>> */
    private array $skippedFiles;

    public function __construct()
    {
        $this->results = [];
        $this->skippedFiles  = [];
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

    /**
     * @param CsvQuery[]  $conditions
     * @param string      $path
     * @param bool|null   $hasHeaders
     * @param string|null $delimiter
     * @param string|null $enclosure
     * @param string|null $escape
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
        ?string $delimiter = null,
        ?string $enclosure = null,
        ?string $escape = null
    ): array {
        if (is_dir($path)) {
            $this->dirSearch($conditions, $path, $hasHeaders, $delimiter, $enclosure, $escape);
        }

        if (is_file($path)) {
            $this->fileSearch($conditions, $path, $hasHeaders, $delimiter, $enclosure, $escape);
        }

        return $this->results;
    }

    /**
     * @param CsvQuery[]  $conditions
     * @param string      $dir
     * @param bool|null   $hasHeaders
     * @param string|null $delimiter
     * @param string|null $enclosure
     * @param string|null $escape
     *
     * @throws InaccessibleException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RuntimeException
     */
    private function dirSearch(
        array $conditions,
        string $dir,
        ?bool $hasHeaders = null,
        ?string $delimiter = null,
        ?string $enclosure = null,
        ?string $escape = null
    ): void {
        $dirIterator = $this->getDirIterator($dir);

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

            $this->fileSearch($conditions, $file->getRealPath(), $hasHeaders, $delimiter, $enclosure, $escape);
        }
    }

    /**
     * @param CsvQuery[]  $conditions
     * @param string      $filePath
     * @param bool|null   $hasHeaders
     * @param string|null $delimiter
     * @param string|null $enclosure
     * @param string|null $escape
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
        ?string $delimiter = null,
        ?string $enclosure = null,
        ?string $escape = null
    ): void {
        $file = new SplFileObject($filePath);

        $csvFile = new CsvFile(
            $file->getRealPath(), $hasHeaders, $delimiter, $enclosure, $escape
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
