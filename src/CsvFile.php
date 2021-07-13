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
use NumberFormatter;
use SplFileObject;
use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_search;
use function count;
use function filter_var;
use function is_file;
use function is_numeric;
use function is_readable;
use function max;
use function sprintf;
use function str_getcsv;
use function strlen;
use function strpos;
use function strtotime;
use const FILTER_VALIDATE_BOOLEAN;
use const FILTER_VALIDATE_DOMAIN;
use const FILTER_VALIDATE_EMAIL;
use const FILTER_VALIDATE_INT;
use const FILTER_VALIDATE_IP;
use const FILTER_VALIDATE_MAC;
use const FILTER_VALIDATE_URL;

class CsvFile
{
    protected const AUTO_DETECT_DEPTH = 5;

    protected const AUTO_DETECT_DELIMITER_CHARACTERS = [
        ",",
        ";",
        "\t",
        ".",
        ":",
        "|",
    ];

    private string $delimiter;

    private string $enclosure;

    private string $escape;

    /** @var string[]|null */
    private ?array $headers;

    private ?SplFileObject $fileHandle = null;

    /**
     * CsvFile constructor.
     *
     * @param string    $file
     * @param bool|null $hasHeaders
     * @param string    $delimiter
     * @param string    $enclosure
     * @param string    $escape
     *
     * @throws InaccessibleException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RuntimeException
     */
    public function __construct(
        string $file,
        ?bool $hasHeaders = null,
        ?string $delimiter = null,
        string $enclosure = "\"",
        string $escape = "\\"
    ) {
        if (is_file($file) === false) {
            throw new InvalidArgumentException(sprintf('File "%s" not found.', $file));
        }

        if (is_readable($file) === false) {
            throw new InaccessibleException(sprintf('File "%s" can not be read.', $file));
        }

        $this->fileHandle = new SplFileObject($file, "r", false);

        $this->enclosure = $enclosure;
        $this->escape    = $escape;

        if ($delimiter !== null) {
            if (strlen($delimiter) > 1) {
                throw new InvalidArgumentException(
                    sprintf(
                        'The delimiter argument can not be more than one character! "%s" given.',
                        $delimiter
                    )
                );
            }

            $this->delimiter = $delimiter;
        }
        else {
            $this->delimiter = $this->detectDelimiter();
        }

        $this->headers = ($hasHeaders === null) ? $this->detectHeaders() : $this->getHeaders();

        if ($this->headers !== null) {
            $this->fileHandle->seek(1);
        }
    }

    public function __destruct()
    {
        if ($this->fileHandle !== null) {
            $this->fileHandle = null;
        }
    }

    /**
     * This method will attempt to detect the delimiter used in the CSV file.
     * To limit the complexity and execution time of the method, we will search
     * only for a limited number of common delimiters (defined in
     * {@see CsvFile::AUTO_DETECT_DELIMITER_CHARACTERS}).
     *
     * The method will look at the first N lines only. The more lines, the more
     * accurate the detection will be. By default, the number of lines scanned
     * is defined in {@see CsvFile::AUTO_DETECT_DEPTH}.
     *
     * **WARNING:** While this method might work for *most* CSV files, CSV files
     * come in many different formats and can make the auto-detection of
     * delimiters very difficult. It is **strongly** recommended to always
     * specify the delimiter rather than attempt to detect it.
     *
     * @throws RuntimeException
     *
     * @return string
     */
    private function detectDelimiter(): string
    {
        if ($this->fileHandle === null) {
            throw new RuntimeException('Can not detect CSV delimiter, no file given.');
        }

        $currentLine = $this->fileHandle->key();

        /** @var array<string, array<int, int>> $delimiters */
        $delimiters = [];

        $this->fileHandle->rewind();
        $lineNum = 1;
        while (($line = $this->fileHandle->fgets()) !== false && $lineNum <= CsvFile::AUTO_DETECT_DEPTH) {
            foreach (CsvFile::AUTO_DETECT_DELIMITER_CHARACTERS as $delimChar) {
                $columnsFound = count(str_getcsv($line, $delimChar, $this->enclosure, $this->escape));
                if ($columnsFound > 1) {
                    if (isset($delimiters[$delimChar][$lineNum]) === false) {
                        $delimiters[$delimChar][$lineNum] = $columnsFound;
                    }
                    else {
                        $delimiters[$delimChar][$lineNum]++;
                    }
                }
            }

            $lineNum++;
        }

        $this->fileHandle->seek($currentLine);

        /** @var array<string, int> $delimCharacters */
        $delimCharacters = [];
        foreach ($delimiters as $delimChar => $results) {
            if (array_key_exists($delimChar, $delimCharacters) === false) {
                $delimCharacters[$delimChar] = count($results);
            }
            else {
                $delimCharacters[$delimChar] += count($results);
            }
        }

        if (empty($delimCharacters) === false) {
            $charWithMostLines = max($delimCharacters);
            $character         = array_search($charWithMostLines, $delimCharacters);

            if ($character !== false) {
                return $character;
            }
        }

        $message = sprintf(
            'The CSV delimiter could not be found for file "%s"',
            $this->fileHandle->getFilename()
        );

        throw new RuntimeException($message);
    }

    /**
     * ## Detect if CSV file contains headers
     *
     * This method will attempt to detect if the first line of the CSV file
     * contains headers, and return them if so.
     *
     * It does so by scanning the first N lines (after the first line), and see
     * if they
     * The method attempts to find if the first line of the CSV file has some
     * key differences from other lines.
     * To limit the complexity and execution time of the method
     * To limit the complexity and execution time of the method, the method will
     * look at the first N lines only. The more lines, the more accurate the
     * detection will be. By default, the number of lines scanned is defined in
     * {@see CsvFile::AUTO_DETECT_DELIMITER_DEPTH}.
     *
     * **WARNING:** While this method might work for *most* CSV files, CSV files
     * come in many different formats and can make the auto-detection of
     * delimiters very difficult. It is **strongly** recommended to always
     * specify if the file contains headers or not.
     *
     * @throws LogicException
     * @throws RuntimeException
     *
     * @return string[]|null
     */
    private function detectHeaders(): ?array
    {
        if ($this->fileHandle === null) {
            throw new RuntimeException('Can not detect CSV headers, no file given.');
        }

        $firstLine       = $this->getHeaders();
        $firstLineValues = $this->getLineValues($firstLine);

        $currentLine = $this->fileHandle->key();

        /** @var array<int, array<string, bool>> $lines */
        $lines = [];

        $this->fileHandle->seek(1);
        $lineNum = 1;
        while (($line = $this->getLine()) !== null && $lineNum <= CsvFile::AUTO_DETECT_DEPTH) {
            $lines[] = $this->getLineValues($line);

            $lineNum++;
        }

        $this->fileHandle->seek($currentLine);

        $allLinesContainOnlyStrings = array_filter($lines, fn(array $line) => $line['onlyStrings']) === $lines;
        $allLinesContainOnlyNumeric = array_filter($lines, fn(array $line) => $line['onlyNumeric']) === $lines;
        $allLinesContainBoolean     = array_filter($lines, fn(array $line) => $line['containsBoolean']) === $lines;
        $allLinesContainDomain      = array_filter($lines, fn(array $line) => $line['containsDomain']) === $lines;
        $allLinesContainEmail       = array_filter($lines, fn(array $line) => $line['containsEmail']) === $lines;
        $allLinesContainIp          = array_filter($lines, fn(array $line) => $line['containsIp']) === $lines;
        $allLinesContainMacAddress  = array_filter($lines, fn(array $line) => $line['containsMacAddress']) === $lines;
        $allLinesContainUrl         = array_filter($lines, fn(array $line) => $line['containsUrl']) === $lines;
        $allLinesContainDate        = array_filter($lines, fn(array $line) => $line['containsDate']) === $lines;

        if (
            $firstLineValues['onlyStrings'] !== $allLinesContainOnlyStrings ||
            $firstLineValues['onlyNumeric'] !== $allLinesContainOnlyNumeric ||
            $firstLineValues['containsBoolean'] !== $allLinesContainBoolean ||
            $firstLineValues['containsDomain'] !== $allLinesContainDomain ||
            $firstLineValues['containsEmail'] !== $allLinesContainEmail ||
            $firstLineValues['containsIp'] !== $allLinesContainIp ||
            $firstLineValues['containsMacAddress'] !== $allLinesContainMacAddress ||
            $firstLineValues['containsUrl'] !== $allLinesContainUrl ||
            $firstLineValues['containsDate'] !== $allLinesContainDate
        ) {
            // The first line has differences from all the others
            // While this may not be 100% accurate, it should be fairly consistent
            return $firstLine;
        }

        return null;
    }

    /**
     * @param array<string|null> $line
     *
     * @return array<string, bool>
     */
    private function getLineValues(array $line): array
    {
        /** @var array<string, bool> $values */
        $values = [
            'onlyStrings'        => array_filter($line, fn($column) => is_numeric($column) === false) === $line,
            'onlyNumeric'        => array_filter($line, 'is_numeric') === $line,
            'containsBoolean'    => false,
            'containsDomain'     => false,
            'containsEmail'      => false,
            'containsIp'         => false,
            'containsMacAddress' => false,
            'containsUrl'        => false,
            'containsDate'       => false,
        ];

        foreach ($line as $column) {
            if ($column === null) {
                continue;
            }

            $containsBoolean    = filter_var($column, FILTER_VALIDATE_BOOLEAN) !== false;
            $containsDomain     = filter_var($column, FILTER_VALIDATE_DOMAIN) !== false;
            $containsEmail      = filter_var($column, FILTER_VALIDATE_EMAIL) !== false;
            $containsIp         = filter_var($column, FILTER_VALIDATE_IP) !== false;
            $containsMacAddress = filter_var($column, FILTER_VALIDATE_MAC) !== false;
            $containsUrl        = filter_var($column, FILTER_VALIDATE_URL) !== false;
            $containsDate       = strtotime($column) !== false;

            $values['containsBoolean']    = $values['containsBoolean'] || $containsBoolean;
            $values['containsDomain']     = $values['containsDomain'] || $containsDomain;
            $values['containsEmail']      = $values['containsEmail'] || $containsEmail;
            $values['containsIp']         = $values['containsIp'] || $containsIp;
            $values['containsMacAddress'] = $values['containsMacAddress'] || $containsMacAddress;
            $values['containsUrl']        = $values['containsUrl'] || $containsUrl;
            $values['containsDate']       = $values['containsDate'] || $containsDate;
        }

        return $values;
    }

    /**
     * @throws RuntimeException
     *
     * @return string[]|null
     */
    private function getLine(): ?array
    {
        if ($this->fileHandle === null) {
            throw new RuntimeException('Can not get line from CSV file, no file given.');
        }

        /** @var string[]|false $line */
        $line = $this->fileHandle->fgetcsv($this->delimiter, $this->enclosure, $this->escape);

        if ($line !== false && array_filter($line) !== []) {
            return $line;
        }

        return null;
    }

    /**
     * @throws LogicException
     * @throws RuntimeException
     *
     * @return string[]
     */
    private function getHeaders(): array
    {
        if ($this->fileHandle === null) {
            throw new RuntimeException('Can not get CSV headers, no file given.');
        }

        $currentLine = $this->fileHandle->key();
        $this->fileHandle->seek(0);

        $line = $this->getLine();

        $this->fileHandle->seek($currentLine);

        if ($line === null) {
            throw new LogicException(
                'Can not get CSV headers, first line could not be properly read. Bad format?',
            );
        }

        return $line;
    }

    /**
     * Searches a CSV file for matches to the search string in the first
     * argument. The return is an two-dimensional array as shown below:
     *
     * Given the following CSV file:
     * ```csv
     * name,stock,sold
     * Volvo,22,22
     * BMW,15,13
     * Ford,17,22
     * Land Rover,17,15
     * ```
     * If we search for "22" (with column as null), we might get results that
     * look like the following:
     * ```php
     * $result = array(
     *     2 => array(1, 2),
     *     4 => array(2),
     * )
     * ```
     * The keys of the first array are the line numbers with matches. The inner
     * arrays contain the column indexes where matches were found.
     *
     * @param string      $searchString The phrase to search for
     * @param string|null $column       If specified, search is performed only
     *                                  on the specified column.
     *                                  If the value validates as an integer,
     *                                  it will search in the column with that
     *                                  index. Otherwise, the column index of
     *                                  the header that matches the string will
     *                                  be used. Default null
     * @param bool        $partial      If false, we check if the search string
     *                                  is found within the columns.
     *                                  If true, the column value must match the
     *                                  search string exactly. Default true
     * @param int|null    $offsetLine   If given, the search starts from the
     *                                  given line number. Default null
     *
     * @throws LogicException
     * @throws RuntimeException
     *
     * @return array<int, int[]>
     */
    public function find(
        string $searchString,
        ?string $column = null,
        bool $partial = true,
        ?int $offsetLine = null
    ): array {
        if ($this->fileHandle === null) {
            throw new RuntimeException('Can not search in CSV file, no file given.');
        }

        $currentLine = $this->fileHandle->key();

        if ($offsetLine !== null) {
            try {
                $this->fileHandle->seek($offsetLine);
            } catch (\LogicException $e) {
                $this->fileHandle->seek($currentLine);

                $message = sprintf(
                    'Can not set offset to "%d" in file "%s".',
                    $offsetLine,
                    $this->fileHandle->getFilename()
                );

                throw new LogicException($message, 0, $e);
            }
        }

        /** @var array<int, int[]> $lineResults */
        $lineResults = [];
        while (($line = $this->getLine()) !== null) {
            if (filter_var($column, FILTER_VALIDATE_INT) !== false) {
                if ($this->columnSearch($searchString, $line, (int) $column, $partial)) {
                    $lineResults[$this->fileHandle->key()][] = (int) $column;
                }
            } elseif ($column !== null) {
                if ($this->headers === null) {
                    throw new RuntimeException(
                        sprintf(
                            'Can not search in column "%s". No headers are defined in CSV file.',
                            $column
                        )
                    );
                }

                /** @var int|false $columnIndex */
                $columnIndex = array_search($column, $this->headers, true);
                if ($columnIndex === false) {
                    throw new RuntimeException(
                        sprintf(
                            'Can not search in column "%s". Column not found in CSV file headers.',
                            $column
                        )
                    );
                }

                if ($this->columnSearch($searchString, $line, $columnIndex, $partial)) {
                    $lineResults[$this->fileHandle->key()][] = (int) $column;
                }
            } else {
                /** @var int $columnIndex */
                foreach (array_keys($line) as $columnIndex) {
                    if ($this->columnSearch($searchString, $line, $columnIndex, $partial)) {
                        $lineResults[$this->fileHandle->key()][] = $columnIndex;
                    }
                }
            }
        }

        $this->fileHandle->seek($currentLine);

        return $lineResults;
    }

    /**
     * @param string   $searchString
     * @param string[] $line
     * @param int      $column
     * @param bool     $partial
     *
     * @throws RuntimeException
     *
     * @return bool
     */
    private function columnSearch(string $searchString, array $line, int $column, bool $partial = true): bool
    {
        if ($this->fileHandle === null) {
            throw new RuntimeException('Can not search in CSV file, no file given.');
        }

        if (array_key_exists($column, $line) === false) {
            $ordinalNumberFormatter = new NumberFormatter('en_US', NumberFormatter::ORDINAL);
            throw new RuntimeException(
                sprintf(
                    'Can not search in the %s header as it does not exist on line %d',
                    $ordinalNumberFormatter->format($column),
                    $this->fileHandle->key()
                )
            );
        }

        $columnValue = $line[$column];

        if ($partial && strpos($columnValue, $searchString) !== false) {
            return true;
        }

        if ($partial === false && $columnValue === $searchString) {
            return true;
        }

        return false;
    }

    /**
     * @param CsvQuery[] $conditions
     *
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RuntimeException
     */
    public function findBy(
        array $conditions
    ): array {
        if ($this->fileHandle === null) {
            throw new RuntimeException('Can not search in CSV file, no file given.');
        }

        /** @var array<int, bool> $results */
        $results = [];
        while (($line = $this->getLine()) !== null) {
            if ($this->findInLine($conditions, $line)) {
                $results[$this->fileHandle->key()] = $this->fileHandle->current();
            }
        }

        return $results;
    }

    /**
     * @param CsvQuery[] $conditions
     * @param string[]   $line
     *
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RuntimeException
     *
     * @return bool
     */
    private function findInLine(
        array $conditions,
        array $line
    ): bool {
        if ($this->fileHandle === null) {
            throw new RuntimeException(
                'Can not search in CSV file, no file given.'
            );
        }

        if ($this->headers === null) {
            throw new RuntimeException(
                'Can not query CSV file without headers.'
            );
        }

        foreach ($conditions as $condition) {
            $column      = $condition->getColumn();
            $columnIndex = array_search($column, $this->headers, true);
            if ($columnIndex === false) {
                throw new RuntimeException(
                    sprintf('Can not query column "%s", does not exist in CVS headers.', $column)
                );
            }

            if (array_key_exists($columnIndex, $line) === false) {
                return false;
            }

            $columnValue = $line[$columnIndex];

            if ($condition->matchValue($columnValue) === false) {
                return false;
            }
        }

        return true;
    }
}
