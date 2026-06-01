<?php

declare(strict_types=1);

namespace Indoctrinate\Service\Impact;

use Symfony\Component\Finder\Finder;

final class CodeReferenceScanner
{
    /**
     * Scan PHP files under $sourceDir for references to each changed column.
     * Searches for the snake_case column name and its camelCase equivalent.
     *
     * @param list<array{type: string, table: string, column: string, newColumn: string|null, dataType: string|null, severity: string, sql: string}> $changes
     * @return array{filesScanned: int, findings: list<array{change: array<string, mixed>, references: list<array{file: string, line: int, content: string}>}>}
     */
    public function scan(string $sourceDir, array $changes): array
    {
        $phpFiles = $this->collectPhpFiles($sourceDir);

        if ($changes === []) {
            return [
                'filesScanned' => count($phpFiles),
                'findings' => [],
            ];
        }

        $findings = [];
        foreach ($changes as $change) {
            $terms = $this->searchTerms($change);
            $findings[] = [
                'change' => $change,
                'references' => $this->findReferences($phpFiles, $terms),
            ];
        }

        return [
            'filesScanned' => count($phpFiles),
            'findings' => $findings,
        ];
    }

    /**
     * @return array<string, string> filepath => full file content
     */
    private function collectPhpFiles(string $sourceDir): array
    {
        if (! is_dir($sourceDir)) {
            return [];
        }

        $finder = (new Finder())->files()->name('*.php')->in($sourceDir);

        $files = [];
        foreach ($finder as $file) {
            $files[$file->getRealPath()] = $file->getContents();
        }

        return $files;
    }

    /**
     * Build the set of strings to search for from a change descriptor.
     * For renames we search for the old name; for drops/modifies, the existing name.
     *
     * @param array<string, mixed> $change
     * @return list<string>
     */
    private function searchTerms(array $change): array
    {
        $col = (string) $change['column'];
        $terms = [$col];

        $camel = $this->toCamelCase($col);
        if ($camel !== $col) {
            $terms[] = $camel;
        }

        // PascalCase catches getter/setter names: getCreatedAt, setCreatedAt, etc.
        $pascal = ucfirst($camel);
        if ($pascal !== $camel && $pascal !== $col) {
            $terms[] = $pascal;
        }

        return $terms;
    }

    /**
     * @param array<string, string> $files
     * @param list<string> $terms
     * @return list<array{file: string, line: int, content: string}>
     */
    private function findReferences(array $files, array $terms): array
    {
        $refs = [];

        foreach ($files as $path => $content) {
            $lines = explode("\n", $content);

            foreach ($lines as $i => $line) {
                $stripped = ltrim($line);

                // Skip truly blank lines and single-line // comments only.
                // Docblock annotation lines (starting with *) are kept so that
                // @ORM\Column(name="...") and #[ORM\Column] references are found.
                if ($stripped === '' || strncmp($stripped, '//', 2) === 0) {
                    continue;
                }

                foreach ($terms as $term) {
                    if (strpos($line, $term) !== false) {
                        $refs[] = [
                            'file' => $path,
                            'line' => $i + 1,
                            'content' => trim($line),
                        ];
                        break;
                    }
                }
            }
        }

        return $refs;
    }

    private function toCamelCase(string $input): string
    {
        return lcfirst(str_replace('_', '', ucwords($input, '_')));
    }
}
