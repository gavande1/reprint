<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match the existing importer test class style.

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

final class FreshLocalIndexProcessorTest extends TestCase
{
    private string $root;
    private string $filesystemRoot;
    private string $workDirectory;
    private string $indexFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir()
            . '/fresh-local-index-processor-'
            . bin2hex(random_bytes(6));
        $this->filesystemRoot = $this->root . '/files';
        $this->workDirectory = $this->root . '/work';
        $this->indexFile = $this->workDirectory . '/fresh-local-index.jsonl';
        mkdir($this->filesystemRoot, 0700, true);
        mkdir($this->workDirectory, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testBuildsAPathSortedLocalIndex(): void
    {
        file_put_contents($this->filesystemRoot . '/z.txt', 'z');
        file_put_contents($this->filesystemRoot . '/a.txt', 'a');
        mkdir($this->filesystemRoot . '/empty');

        $processor = \FreshLocalIndexProcessor::create(
            $this->workDirectory,
            $this->filesystemRoot,
            $this->indexFile
        );
        try {
            while ($processor->next_step()) {
                continue;
            }
        } finally {
            $processor->close();
        }

        $entries = $this->readIndex();
        $this->assertSame(
            ['a.txt', 'empty', 'z.txt'],
            array_column($entries, 'path')
        );
        $this->assertTrue($entries[1]['empty']);
    }

    public function testResumeDiscardsBytesAfterTheStoredCursor(): void
    {
        file_put_contents($this->filesystemRoot . '/one.txt', 'one');
        file_put_contents($this->filesystemRoot . '/two.txt', 'two');

        $processor = \FreshLocalIndexProcessor::create(
            $this->workDirectory,
            $this->filesystemRoot,
            $this->indexFile
        );
        $processor->next_step();
        $processor->flush_pending_output();
        $cursor = $processor->get_cursor();
        $processor->close();

        file_put_contents(
            $this->indexFile,
            json_encode([
                'path' => base64_encode('not-confirmed.txt'),
                'ctime' => 0,
                'size' => 0,
                'type' => 'file',
            ], JSON_THROW_ON_ERROR) . "\n",
            FILE_APPEND
        );

        $processor = \FreshLocalIndexProcessor::resume($cursor);
        try {
            while ($processor->next_step()) {
                continue;
            }
        } finally {
            $processor->close();
        }

        $this->assertSame(
            ['one.txt', 'two.txt'],
            array_column($this->readIndex(), 'path')
        );
    }

    public function testResumeSortsAfterTheTraversalBoundary(): void
    {
        file_put_contents($this->filesystemRoot . '/z.txt', 'z');
        file_put_contents($this->filesystemRoot . '/a.txt', 'a');

        $processor = \FreshLocalIndexProcessor::create(
            $this->workDirectory,
            $this->filesystemRoot,
            $this->indexFile
        );
        while ($processor->get_cursor()['position']['phase'] !== 'sorting') {
            $this->assertTrue($processor->next_step());
        }
        $cursor = $processor->get_cursor();
        $processor->close();

        $processor = \FreshLocalIndexProcessor::resume($cursor);
        $this->assertFalse($processor->next_step());
        $processor->close();

        $this->assertSame(
            ['a.txt', 'z.txt'],
            array_column($this->readIndex(), 'path')
        );
    }

    /** @return list<array<string,mixed>> */
    private function readIndex(): array
    {
        $entries = [];
        foreach (file($this->indexFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $entry['path'] = base64_decode($entry['path'], true);
            $entries[] = $entry;
        }
        return $entries;
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
