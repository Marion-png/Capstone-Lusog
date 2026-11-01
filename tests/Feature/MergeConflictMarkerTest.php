<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * No file in the project carries an unresolved merge conflict.
 *
 * A stash popped over a pull and committed as it stood (d0ed9b7) put conflict
 * markers into 24 files, and a later merge carried them onto main: PHP refused
 * to parse a controller that `routes/web.php` loads while registering routes,
 * so every page in the application failed, and PHPUnit could not load the
 * suite that would have said so. This reads the source itself, so a marker
 * fails the build even in a file no other test happens to touch — a Blade
 * view, a stylesheet, a doc.
 *
 * Only the opening and closing markers are matched. They always come as a
 * pair, so one is enough to catch a conflict, and neither can be mistaken
 * for anything else; a bare "=======" can (a Markdown underline is one).
 */
class MergeConflictMarkerTest extends TestCase
{
    /** The project's own source and docs: never vendor/, node_modules/ or storage/. */
    private const DIRECTORIES = ['app', 'bootstrap', 'config', 'database', 'docs', 'public', 'resources', 'routes', 'scripts', 'tests'];

    private const ROOT_FILES = ['CLAUDE.md', 'README.md', 'Caddyfile', 'composer.json', 'package.json', 'phpunit.xml', 'vite.config.js', '.env.example'];

    /** Text files only: an image or a font is bytes, and bytes can match anything. */
    private const EXTENSIONS = ['php', 'js', 'mjs', 'ts', 'css', 'json', 'md', 'xml', 'yml', 'yaml', 'txt', 'html', 'svg', 'csv', 'sql', 'sh'];

    #[Test]
    public function no_file_carries_a_merge_conflict_marker(): void
    {
        $conflicted = [];

        foreach ($this->files() as $path) {
            if (preg_match_all('/^(?:<{7}|>{7})(?: |$)/m', (string) file_get_contents($path), $markers) > 0) {
                $conflicted[] = str_replace('\\', '/', substr($path, strlen(base_path()) + 1)).' ('.count($markers[0]).')';
            }
        }

        $this->assertSame([], $conflicted, 'Resolve the merge conflict in: '.implode(', ', $conflicted));
    }

    /** @return list<string> */
    private function files(): array
    {
        $files = array_values(array_filter(
            array_map(fn (string $name): string => base_path($name), self::ROOT_FILES),
            'is_file'
        ));

        foreach (self::DIRECTORIES as $directory) {
            if (! is_dir(base_path($directory))) {
                continue;
            }

            $entries = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(base_path($directory), RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($entries as $entry) {
                $path = $entry->getPathname();

                // Compiled framework caches, not source.
                if (str_contains(str_replace('\\', '/', $path), 'bootstrap/cache/')) {
                    continue;
                }

                if (in_array(strtolower($entry->getExtension()), self::EXTENSIONS, true)) {
                    $files[] = $path;
                }
            }
        }

        return $files;
    }
}
