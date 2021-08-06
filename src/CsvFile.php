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
use SplFileObject;
use function array_filter;
use function array_key_exists;
use function array_search;
use function count;
use function filter_var;
use function is_file;
use function is_int;
use function is_numeric;
use function is_readable;
use function max;
use function sprintf;
use function str_getcsv;
use function strlen;
use function strtotime;
use const FILTER_VALIDATE_BOOLEAN;
use const FILTER_VALIDATE_DOMAIN;
use const FILTER_VALIDATE_EMAIL;
use const FILTER_VALIDATE_IP;
use const FILTER_VALIDATE_MAC;
use const FILTER_VALIDATE_URL;

class CsvFile
{
    public const DEFAULT_ENCLOSURE = "\"";

    public const DEFAULT_ESCAPE = "\\";

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

    private string $enclosureCharacter;

    private string $escapeCharacter;

    /** @var Line|null */
    private ?Line $headers;

    private ?SplFileObject $fileHandle = null;

    /**
     * CsvFile constructor.
     *
     * @param string      $file
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
    public function __construct(
        string $file,
        ?bool $hasHeaders = null,
        ?string $delimiter = null,
        ?string $enclosure = null,
        ?string $escape = null
    ) {
        if (is_file($file) === false) {
            throw new InvalidArgumentException(sprintf('File "%s" not found.', $file));
        }

        if (is_readable($file) === false) {
            throw new InaccessibleException(sprintf('File "%s" can not be read.', $file));
        }

        $this->fileHandle = new SplFileObject($file, "r", false);

        $this->enclosureCharacter = $enclosure ?? CsvFile::DEFAULT_ENCLOSURE;
        $this->escapeCharacter    = $escape ?? CsvFile::DEFAULT_ESCAPE;

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
                $columnsFound = count(
                    str_getcsv(
                        $line,
                        $delimChar,
                        $this->getEnclosureCharacter(),
                        $this->getEscapeCharacter()
                    )
                );
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
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RuntimeException
     *
     * @return Line|null
     */
    private function detectHeaders(): ?Line
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
     * @param Line $line
     *
     * @return array<string, bool>
     */
    private function getLineValues(Line $line): array
    {
        /** @var array<string, bool> $values */
        $values = [
            'onlyStrings'        => $line->filter(fn(string $column) => is_numeric($column) === false) === $line,
            'onlyNumeric'        => $line->filter(fn(string $column) => is_numeric($column)) === $line,
            'containsBoolean'    => false,
            'containsDomain'     => false,
            'containsEmail'      => false,
            'containsIp'         => false,
            'containsMacAddress' => false,
            'containsUrl'        => false,
            'containsDate'       => false,
        ];

        foreach ($line as $column) {
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
     * @throws InvalidArgumentException
     * @throws RuntimeException
     *
     * @return Line|null
     */
    private function getLine(): ?Line
    {
        if ($this->fileHandle === null) {
            throw new RuntimeException('Can not get line from CSV file, no file given.');
        }

        /** @var string[]|false $line */
        $line = $this->fileHandle->fgetcsv(
            $this->delimiter,
            $this->getEnclosureCharacter(),
            $this->getEscapeCharacter()
        );

        if ($line !== false && array_filter($line) !== []) {
            return new Line($this->fileHandle->key(), $this->fileHandle->getRealPath(), $line);
        }

        return null;
    }

    /**
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RuntimeException
     *
     * @return Line
     */
    public function getHeaders(): Line
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
     * @param CsvQuery[] $conditions
     *
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RuntimeException
     *
     * @return Result
     */
    public function findBy(
        array $conditions
    ): Result {
        if ($this->fileHandle === null) {
            throw new RuntimeException('Can not search in CSV file, no file given.');
        }

        $results = new Result(
            $this->fileHandle->getRealPath(),
            $this->getDelimiter(),
            $this->getEnclosureCharacter(),
            $this->getEscapeCharacter(),
            $this->headers,
        );

        $totalLines = 0;
        while (($line = $this->getLine()) !== null) {
            $totalLines++;
            if ($this->findInLine($conditions, $line)) {
                $results->addMatch($line);
            }
        }

        $results->setTotalLines($totalLines);

        return $results;
    }

    /**
     * @param CsvQuery[] $conditions
     * @param Line       $line
     *
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RuntimeException
     *
     * @return bool
     */
    private function findInLine(
        array $conditions,
        Line $line
    ): bool {
        if ($this->fileHandle === null) {
            throw new RuntimeException(
                'Can not search in CSV file, no file given.'
            );
        }

        if (count($conditions) === 0) {
            throw new LogicException(
                'No conditions set for search.'
            );
        }

        foreach ($conditions as $condition) {
            $column = $condition->getColumn();
            if ($column === null) {
                $conditionMatch = false;
                foreach ($line->getKeys() as $column) {
                    if ($this->findInColumn($column, $condition, $line)) {
                        $conditionMatch = true;
                    }
                }
            } else {
                $conditionMatch = $this->findInColumn($column, $condition, $line);
            }

            if ($conditionMatch === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string|int $column
     * @param CsvQuery   $condition
     * @param Line       $line
     *
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RuntimeException
     *
     * @return bool
     */
    private function findInColumn(string|int $column, CsvQuery $condition, Line $line): bool
    {
        if (is_int($column)) {
            $columnValue = $line->getColumn($column);

            return $condition->matchValue($columnValue);
        }

        if ($this->headers === null) {
            throw new RuntimeException(
                sprintf('Can not query column "%s", does not exist in CSV headers.', $column)
            );
        }

        if ($this->headers->contains($column) === false) {
            throw new RuntimeException(
                sprintf('Can not query column "%s", does not exist in CSV headers.', $column)
            );
        }

        $columnIndex = $this->headers->indexOf($column);

        if ($columnIndex === false) {
            throw new RuntimeException(
                sprintf('Can not query column "%s", does not exist in CSV headers.', $column)
            );
        }

        $columnValue = $line->getColumn($columnIndex);

        return $condition->matchValue($columnValue);
    }
}
