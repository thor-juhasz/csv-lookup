<?php declare(strict_types=1);
/*
 * This file is part of csv-lookup.
 *
 * (c) Thor Juhasz <thor@juhasz.pro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CsvLookup\Report\Text;

use CsvLookup\Exception\InvalidArgumentException;
use CsvLookup\Exception\LogicException;
use CsvLookup\Line;
use CsvLookup\Report\GenerateReport;
use SplFileObject;
use SplTempFileObject;
use function count;
use function dirname;
use function is_array;
use function join;
use function sprintf;
use function str_repeat;
use function strlen;
use const PHP_EOL;

class TextReport extends GenerateReport
{
    /**
     * @throws InvalidArgumentException
     * @throws LogicException
     */
    public function __invoke(string $output): void {
        if ($output === "") {
            $file = new SplTempFileObject();
        } else {
            $dir = dirname($output);
            $this->createDir($dir);
            $file = new SplFileObject($output, 'w+');
        }

        $text = <<<EOT

            Search result report
            --------------------

            Search path: %s

            EOT;

        $text = sprintf($text, $this->searchPath);
        $file->fwrite($text, strlen($text));

        if (count($this->conditions)) {
            $text = sprintf("Queries:%s", PHP_EOL);
            $file->fwrite($text, strlen($text));

            $longestColumn = 0;
            $longestQueryType = 0;
            foreach ($this->conditions as $condition) {
                $columnText = (string) $condition->getColumn() ?: "";
                if (strlen($columnText) > $longestColumn) {
                    $longestColumn = strlen($columnText);
                }

                $queryTypeText = $condition->getQueryType();
                if (strlen($queryTypeText) > $longestQueryType) {
                    $longestQueryType = strlen($queryTypeText);
                }
            }

            foreach ($this->conditions as $condition) {
                $columnText = (string) $condition->getColumn() ?: "";
                $queryTypeText = $condition->getQueryType();

                $text = sprintf(
                    '    Column: "%s",%sType: "%s",%s',
                    $columnText,
                    str_repeat(" ", ($longestColumn - strlen($columnText)) + 1),
                    $queryTypeText,
                    str_repeat(" ", ($longestQueryType - strlen($queryTypeText)) + 1),
                );

                $value = match ($condition->getValueType()) {
                    "bool" => $condition->getValueAsBool(),
                    "null", "int", "float", "datetime", "string" => $condition->getValueAsString(),
                    "array" => $condition->getValueAsTuple(),
                    default => "",
                };

                if (is_array($value)) {
                    $padLength = strlen($text);
                    $text .= <<<EOT
                    Lower value: "%s"
                    %sUpper value: "%s"%s
                    EOT;

                    $text = sprintf(
                        $text,
                        (string) $value['lower'],
                        str_repeat(" ", $padLength),
                        (string) $value['upper'],
                        PHP_EOL
                    );
                } else {
                    $text .= sprintf('Value: "%s"%s', $value, PHP_EOL);
                }

                $file->fwrite($text, strlen($text));
            }
        }

        $text = <<<EOT

            Results
            --------------------

            EOT;

        $file->fwrite($text, strlen($text));

        $anyResults = false;
        foreach ($this->results as $result) {
            if ($result->getMatches()->count() === 0) {
                continue;
            }

            $text = <<<EOT

                File: %s
                    Delimiter: %s    Enclosure: %s    Escape: %s

                EOT;
            $text = sprintf(
                $text,
                $result->getFilename(),
                $result->getDelimiter(),
                $result->getEnclosureCharacter(),
                $result->getEscapeCharacter()
            );
            $file->fwrite($text, strlen($text));

            $headers = $result->getHeaders();
            if ($headers !== null) {
                $text = sprintf("    Headers: %s%s", join(", ", $headers->toArray()), PHP_EOL);
                $file->fwrite($text, strlen($text));
            }

            $matches = $result->getMatches()->count();
            $text = sprintf("    Total matches: %d%s", $matches, PHP_EOL);
            $file->fwrite($text, strlen($text));

            if ($matches > 0) {
                $text = "    Matches:" . PHP_EOL;
                $file->fwrite($text, strlen($text));

                /** @var Line[] $matches */
                $matches = $result->getMatches()->toArray();
                foreach ($matches as $line) {
                    $text = sprintf(
                        "        Line number: %d, %s%s",
                        $line->getLineNumber(),
                        join(
                            $result->getDelimiter(),
                            $line->map(
                                fn(string $column) => sprintf('%s%s%s', $result->getEnclosureCharacter(), $column, $result->getEnclosureCharacter())
                            )->toArray()
                        ),
                        PHP_EOL
                    );
                    $file->fwrite($text, strlen($text));
                }

                $anyResults = true;
            }
        }

        if ($anyResults === false) {
            $text = sprintf("No results found!%s", PHP_EOL);
            $file->fwrite($text, strlen($text));
        }

        $file->fwrite(PHP_EOL, strlen(PHP_EOL));

        if ($file instanceof SplTempFileObject) {
            $file->rewind();
            $file->fpassthru();
        }

        unset($file);
    }
}
