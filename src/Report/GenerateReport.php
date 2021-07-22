<?php declare(strict_types=1);
/*
 * This file is part of csv-lookup.
 *
 * (c) Thor Juhasz <thor@juhasz.pro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CsvLookup\Report;

use CsvLookup\CsvQuery;
use CsvLookup\Result;
use function clearstatcache;
use function dirname;
use function file_exists;
use function mkdir;

/**
 * Class GenerateReport
 */
abstract class GenerateReport
{
    public const REPORT_FORMAT_TEXT = 'text';
    public const REPORT_FORMAT_XML = 'xml';

    /**
     * @psalm-readonly
     */
    protected string $searchPath;

    /**
     * @var CsvQuery[]
     * @psalm-readonly
     */
    protected array $conditions;

    /**
     * @var Result[]
     * @psalm-readonly
     */
    protected array $results;

    /**
     * GenerateReport constructor.
     *
     * @param string     $searchPath
     * @param CsvQuery[] $conditions
     * @param Result[]   $results
     */
    public function __construct(
        string $searchPath,
        array $conditions,
        array $results,
    ) {
        $this->searchPath = $searchPath;
        $this->conditions = $conditions;
        $this->results    = $results;
    }

    /**
     * @psalm-pure
     *
     * @return string[]
     */
    public static function supportedFormats(): array
    {
        return [
            GenerateReport::REPORT_FORMAT_TEXT,
            GenerateReport::REPORT_FORMAT_XML,
        ];
    }

    protected function createDir(string $path): void
    {
        if (file_exists($path) === false) {
            $this->createDir(dirname($path));
            mkdir($path, 0744, true);
            clearstatcache();
        }
    }

    public abstract function __invoke(string $output): void;
}
