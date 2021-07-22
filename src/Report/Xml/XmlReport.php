<?php declare(strict_types=1);

namespace CsvLookup\Report\Xml;

use CsvLookup\Exception\InvalidArgumentException;
use CsvLookup\Exception\LogicException;
use CsvLookup\Line;
use CsvLookup\Report\GenerateReport;
use DOMDocument;
use SplFileObject;
use function dirname;
use function is_array;
use function strlen;

class XmlReport extends GenerateReport
{
    /**
     * @param string $output
     *
     * @throws InvalidArgumentException
     * @throws LogicException
     */
    public function __invoke(string $output): void
    {
        $dir = dirname($output);
        $this->createDir($dir);

        $xmlDocument = new DOMDocument('1.0', 'UTF-8');
        $xmlDocument->formatOutput = true;

        $searchQueries = $xmlDocument->createElement('queries');

        foreach ($this->conditions as $condition) {
            $column = $condition->getColumn();
            $queryType = $condition->getQueryType();
            $value = match ($condition->getValueType()) {
                "bool" => $condition->getValueAsBool(),
                "null", "int", "float", "datetime", "string" => $condition->getValueAsString(),
                "array" => $condition->getValueAsTuple(),
                default => "",
            };

            $queryCondition = $xmlDocument->createElement('query');
            $queryCondition->setAttribute('column', (string) $column);
            $queryCondition->setAttribute('type', $queryType);
            if (is_array($value)) {
                $queryCondition->setAttribute('value-lower', (string) $value['lower']);
                $queryCondition->setAttribute('value-upper', (string) $value['upper']);
            } else {
                $queryCondition->setAttribute('value', $value);
            }

            $searchQueries->appendChild($queryCondition);
        }

        $searchPath = $xmlDocument->createElement('path', $this->searchPath);

        $search = $xmlDocument->createElement('search');
        $search->appendChild($searchPath);
        $search->appendChild($searchQueries);

        $results = $xmlDocument->createElement('results');

        foreach ($this->results as $result) {
            if ($result->getMatches()->count() === 0) {
                continue;
            }

            $resultFile = $xmlDocument->createElement('file');
            $resultFile->setAttribute('path', $result->getFilename());
            $resultFile->setAttribute('delimiter', $result->getDelimiter());
            $resultFile->setAttribute('enclosure', $result->getEnclosureCharacter());
            $resultFile->setAttribute('escape', $result->getEscapeCharacter());

            $headers = $result->getHeaders();
            if ($headers !== null) {
                $headers = join(", ", $headers->toArray());
                $resultFileHeaders = $xmlDocument->createElement('headers', $headers);

                $resultFile->appendChild($resultFileHeaders);
            }

            $resultFoundLines = $xmlDocument->createElement('found-lines');

            /** @var Line[] $matches */
            $matches = $result->getMatches()->toArray();
            foreach ($matches as $line) {
                $lineContents = join(
                    $result->getDelimiter(),
                    $line->map(
                        fn(string $column) => sprintf('%s%s%s', $result->getEnclosureCharacter(), $column, $result->getEnclosureCharacter())
                    )->toArray()
                );

                $resultLine = $xmlDocument->createElement('line', $lineContents);
                $resultLine->setAttribute('number', (string) $line->getLineNumber());
                $resultFoundLines->appendChild($resultLine);
            }

            $resultFile->appendChild($resultFoundLines);

            $results->appendChild($resultFile);
        }

        $csvLookup = $xmlDocument->createElementNS(
            'https://github.com/thor-juhasz/csv-lookup',
            'csv-lookup'
        );

        $csvLookup->setAttribute(
            'xmlns:xsi',
            'http://www.w3.org/2001/XMLSchema-instance'
        );

        $csvLookup->setAttribute(
            'xsi:schemaLocation',
            'https://github.com/thor-juhasz/csv-lookup https://raw.githubusercontent.com/thor-juhasz/csv-lookup/resources/Resources/report-schema.xsd'
        );

        $csvLookup->appendChild($search);
        $csvLookup->appendChild($results);

        $xmlDocument->appendChild($csvLookup);

        $contents = $xmlDocument->saveXML();

        $file = new SplFileObject($output, 'w+');
        $file->fwrite($contents, strlen($contents));
        $file = null;
    }
}
