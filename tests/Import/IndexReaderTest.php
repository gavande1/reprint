<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match the existing importer test class style.

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

final class IndexReaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir()
            . '/index-reader-'
            . bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->root) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                unlink($this->root . '/' . $entry);
            }
        }
        rmdir($this->root);
    }

    public function testSequentialReadReturnsDecodedEntriesAndSkipsBlankLines(): void
    {
        $remoteIndexPath = $this->root . '/remote-index.jsonl';
        file_put_contents(
            $remoteIndexPath,
            $this->indexLine('/site/first.txt', 10, 5, 'file')
                . "\n\n"
                . $this->indexLine('/site/second', 20, 0, 'dir')
                . "\n"
        );

        $reader = new \IndexReader($remoteIndexPath);
        $reader->open();

        $this->assertSame(
            [
                'path' => '/site/first.txt',
                'ctime' => 10,
                'size' => 5,
                'type' => 'file',
            ],
            $reader->next_entry()
        );
        $this->assertSame(
            [
                'path' => '/site/second',
                'ctime' => 20,
                'size' => 0,
                'type' => 'dir',
            ],
            $reader->next_entry()
        );
        $this->assertNull($reader->next_entry());
        $reader->close();
    }

    public function testMissingFileIsAnEmptyReaderAtByteOffsetZero(): void
    {
        $reader = new \IndexReader($this->root . '/missing.jsonl');
        $reader->open();

        $this->assertNull($reader->next_entry());
        $this->assertSame(0, $reader->byte_offset());

        $reader->close();
        $reader->close();
    }

    public function testReadsLocalPathAndMappedRemotePath(): void
    {
        $indexPath = $this->root . '/mapped-local-index.jsonl';
        file_put_contents(
            $indexPath,
            json_encode([
                'path' => base64_encode('wp-content/index.php'),
                'remote_absolute_path' =>
                    base64_encode('/srv/site/wp-content/index.php'),
                'ctime' => 10,
                'size' => 5,
                'type' => 'file',
                'selected' => true,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
        );

        $reader = new \IndexReader($indexPath);
        $reader->open();
        $this->assertSame(
            [
                'path' => 'wp-content/index.php',
                'remote_absolute_path' => '/srv/site/wp-content/index.php',
                'ctime' => 10,
                'size' => 5,
                'type' => 'file',
                'selected' => true,
            ],
            $reader->next_entry()
        );
        $reader->close();
    }

    public function testByteOffsetResumeRepeatsAndSkipsNoEntries(): void
    {
        $remoteIndexPath = $this->root . '/remote-index.jsonl';
        file_put_contents(
            $remoteIndexPath,
            $this->indexLine('/site/first.txt', 10, 5, 'file') . "\n"
                . $this->indexLine('/site/second.txt', 20, 6, 'file') . "\n"
                . $this->indexLine('/site/third.txt', 30, 7, 'file') . "\n"
        );

        $firstReader = new \IndexReader($remoteIndexPath);
        $firstReader->open();
        $firstEntry = $firstReader->next_entry();
        $secondEntry = $firstReader->next_entry();
        $byteOffset = $firstReader->byte_offset();
        $firstReader->close();

        $resumedReader = new \IndexReader($remoteIndexPath);
        $resumedReader->open();
        $resumedReader->seek_to_byte_offset($byteOffset);
        $thirdEntry = $resumedReader->next_entry();
        $this->assertNull($resumedReader->next_entry());
        $resumedReader->close();

        $this->assertSame(
            ['/site/first.txt', '/site/second.txt', '/site/third.txt'],
            [
                $firstEntry['path'] ?? null,
                $secondEntry['path'] ?? null,
                $thirdEntry['path'] ?? null,
            ]
        );
    }

    public function testInvalidLineIsConsumedBeforeTheException(): void
    {
        $remoteIndexPath = $this->root . '/remote-index.jsonl';
        file_put_contents(
            $remoteIndexPath,
            "not-json\n"
                . $this->indexLine('/site/valid.txt', 10, 5, 'file')
                . "\n"
        );

        $reader = new \IndexReader($remoteIndexPath);
        $reader->open();
        try {
            $reader->next_entry();
            $this->fail('Expected the invalid index line to throw.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Invalid index line format', $exception->getMessage());
        }

        $this->assertSame(
            '/site/valid.txt',
            $reader->next_entry()['path'] ?? null
        );
        $reader->close();
    }

    private function indexLine(
        string $path,
        int $ctime,
        int $size,
        string $type
    ): string {
        return json_encode([
            'path' => base64_encode($path),
            'ctime' => $ctime,
            'size' => $size,
            'type' => $type,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
