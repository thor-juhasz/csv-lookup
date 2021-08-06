<?php declare(strict_types=1);

namespace CsvLookup\Report\Html;

use CsvLookup\Config;
use CsvLookup\Line;
use CsvLookup\Report\GenerateReport;
use CsvLookup\Result;
use FilesystemIterator;
use SplFileInfo;
use SplFileObject;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Twig\Loader\FilesystemLoader;
use function basename;
use function copy;
use function is_dir;
use function is_file;
use function mkdir;
use function sprintf;
use function strlen;
use function unlink;

class HtmlReport extends GenerateReport
{
    private Environment $twig;

    /**
     * @param string $output
     *
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public function __invoke(string $output): void
    {
        $this->loadTwig();
        $this->createDir($output . '/files');
        $timestamp = date("r");

        foreach ($this->results as $result) {
            $breadcrumbs = $this->getBreadcrumbs($result);
            $this->parseFileResults($output, $result, $breadcrumbs, $timestamp);
        }

        $breadcrumbs = $this->getBreadcrumbs();
        $this->parseDashboard($output, $breadcrumbs, $timestamp);

        $this->copyAssets($output);
    }

    private function loadTwig(): void
    {
        $loader = new FilesystemLoader(__DIR__ . '/Template');

        $options = [
            'strict_variables' => true,
        ];

        $this->twig = new Environment($loader, $options);
    }

    private function getBreadcrumbs(Result $file = null): array
    {
        if ($file === null) {
            return [
                new Breadcrumb(true, "Dashboard", "dashboard.html"),
            ];
        }

        return [
            new Breadcrumb(false, "Dashboard", "../dashboard.html"),
            new Breadcrumb(true, basename($file->getFilename()), basename($file->getFilename())),
        ];
    }

    /**
     * @param string $path
     * @param array  $breadcrumbs
     * @param string $timestamp
     *
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    private function parseDashboard(string $path, array $breadcrumbs, string $timestamp): void
    {
        $totalMatches = $totalLines = 0;
        $files = [];
        foreach ($this->results as $result) {
            $headers = $result->getHeaders();
            if ($headers !== null) {
                $columns = $headers->count();
            } else {
                $firstLine = $result->getMatches()->first();
                if ($firstLine === false) {
                    $columns = 0;
                } else {
                    $columns = $firstLine->count();
                }
            }

            $files[] = [
                'filename'   => basename($result->getFilename()),
                'columns'    => $columns,
                'matches'    => $result->getMatches()->count(),
                'totalLines' => $result->getTotalLines(),
            ];

            $totalMatches += $result->getMatches()->count();
            $totalLines   += $result->getTotalLines();
        }

        $config = new Config();

        $dashboardContents = $this->twig->render(
            'dashboard.html.twig',
            [
                'breadcrumbs'  => $breadcrumbs,
                'conditions'   => $this->conditions,
                'dashboard'    => true,

                'files'        => $files,
                'totalMatches' => $totalMatches,
                'totalLines'   => $totalLines,
                'version'      => $config->version,
                'timestamp'    => $timestamp,
            ]
        );

        $file = new SplFileObject($path . '/dashboard.html', 'w');
        $file->fwrite($dashboardContents, strlen($dashboardContents));

        unset($file);
    }

    /**
     * @param string $path
     * @param Result $file
     * @param array  $breadcrumbs
     * @param string $timestamp
     *
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    private function parseFileResults(string $path, Result $file, array $breadcrumbs, string $timestamp): void
    {
        $config = new Config();

        $headers = $file->getHeaders();
        if ($headers === null) {
            /** @var Line|false $firstLine */
            $firstLine = $file->getMatches()->first();
            if ($firstLine === false) {
                $headers = [];
            } else {
                $headers = $firstLine->getKeys();
            }
        }

        $fileContents = $this->twig->render(
            'file.html.twig',
            [
                'breadcrumbs' => $breadcrumbs,
                'conditions'  => $this->conditions,
                'dashboard'   => false,

                'delimiter'          => $file->getDelimiter(),
                'enclosureCharacter' => $file->getEnclosureCharacter(),
                'escapeCharacter'    => $file->getEscapeCharacter(),

                'headers'      => $headers,
                'matches'      => $file->getMatches(),

                'totalMatches' => $file->getMatches()->count(),
                'totalLines'   => $file->getTotalLines(),

                'version'      => $config->version,
                'timestamp'    => $timestamp,
            ]
        );

        $filename = sprintf("%s/files/%s.html", $path, basename($file->getFilename()));
        $file = new SplFileObject($filename, 'w');
        $file->fwrite($fileContents, strlen($fileContents));

        unset($file);
    }

    private function copyAssets(string $output): void
    {
        $cssDir = $output . '/_css';
        $jsDir  = $output . '/_js';

        if (is_dir($cssDir) === false) {
            mkdir($cssDir);
        }

        if (is_dir($jsDir) === false) {
            mkdir($jsDir);
        }

        $cssDirIterator = new FilesystemIterator(
            __DIR__ . '/Template/_css', FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::SKIP_DOTS
        );

        $jsDirIterator = new FilesystemIterator(
            __DIR__ . '/Template/_js', FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::SKIP_DOTS
        );

        /** @var SplFileInfo $file */
        foreach ($cssDirIterator as $file) {
            $destFile = sprintf("%s/%s", $cssDir, $file->getFilename());
            if (is_file($destFile)) {
                unlink($destFile);
            }

            copy($file->getRealPath(), $destFile);
        }

        /** @var SplFileInfo $file */
        foreach ($jsDirIterator as $file) {
            $destFile = sprintf("%s/%s", $jsDir, $file->getFilename());
            if (is_file($destFile)) {
                unlink($destFile);
            }

            copy($file->getRealPath(), $destFile);
        }
    }
}
