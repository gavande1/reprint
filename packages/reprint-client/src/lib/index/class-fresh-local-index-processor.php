<?php

use function Reprint\Importer\sort_index_file;
use function WordPress\Reprint\Exporter\relative_path_under;
use function WordPress\Reprint\Exporter\trim_right_slash;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Index paths are CLI values, never HTML output.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Reprint processors use domain names.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match the existing processor classes.

/**
 * Builds one sorted index of the current local filesystem tree.
 *
 * `FileIndexProcessor` walks the filesystem. This processor owns the work
 * needed around that walk: it writes the JSONL index, keeps its durable byte
 * offset, resumes the traversal, and sorts the completed index by decoded path
 * bytes.
 *
 * Push and pull use the same local scan:
 *
 *     $index = FreshLocalIndexProcessor::create(
 *         $work_directory,
 *         $filesystem_root,
 *         $fresh_local_index_file
 *     );
 *     while ($index->next_step()) {
 *         $index->flush_pending_output();
 *         save_cursor($index->get_cursor());
 *     }
 *     save_cursor($index->get_cursor());
 *     $index->close();
 *
 * Each non-terminal step advances one `FileIndexProcessor` traversal event.
 * Finishing the traversal and sorting are separate steps. The caller stores a
 * `sorting` cursor before sorting changes byte order. If sorting is interrupted,
 * `resume()` sorts the same complete file again instead of treating its bytes
 * as traversal output. During traversal, `resume()` truncates bytes written
 * after the stored boundary before continuing.
 *
 * @phpstan-type FileIndexCursor array{stack:list<array{dir:string,after:string|null}>}
 * @phpstan-type IndexingPosition array{phase:'indexing',file_index_cursor:FileIndexCursor,fresh_local_index_byte_offset:int}
 * @phpstan-type SortingPosition array{phase:'sorting'}
 * @phpstan-type CompletePosition array{phase:'complete'}
 * @phpstan-type Cursor array{work_directory:string,filesystem_root:string,fresh_local_index_file:string,position:IndexingPosition|SortingPosition|CompletePosition}
 */
final class FreshLocalIndexProcessor
{
    /** Resolved filesystem root represented by the index. */
    private string $filesystem_root;

    /** Directory used by FileIndexProcessor while it walks the tree. */
    private string $work_directory;

    /** Completed path-sorted local index. */
    private string $fresh_local_index_file;

    /** @var Cursor Current durable continuation point. */
    private array $cursor;

    /** Filesystem traversal retained between steps. */
    private FileIndexProcessor $file_index_processor;

    /** @var resource|null Open fresh local index during traversal. */
    private $fresh_local_index_handle = null;

    /** Whether close() has made this processor terminal. */
    private bool $closed = false;

    /**
     * Starts a new local scan and truncates the output index.
     *
     * @param string $work_directory         Existing directory for traversal work.
     * @param string $filesystem_root        Local filesystem root to scan.
     * @param string $fresh_local_index_file JSONL index to create.
     */
    public static function create(
        string $work_directory,
        string $filesystem_root,
        string $fresh_local_index_file
    ): self {
        $processor = new self(
            $work_directory,
            $filesystem_root,
            $fresh_local_index_file
        );
        $processor->fresh_local_index_handle = fopen(
            $processor->fresh_local_index_file,
            "w+b"
        );
        if (!is_resource($processor->fresh_local_index_handle)) {
            throw new RuntimeException(
                "Failed to open the fresh local index: {$processor->fresh_local_index_file}"
            );
        }
        try {
            $processor->file_index_processor = FileIndexProcessor::start(
                [$processor->filesystem_root],
                $processor->filesystem_root,
                false,
                false,
                $processor->work_directory
            );
        } catch (Throwable $exception) {
            $processor->close();
            throw $exception;
        }
        $processor->cursor = [
            "work_directory" => $processor->work_directory,
            "filesystem_root" => $processor->filesystem_root,
            "fresh_local_index_file" => $processor->fresh_local_index_file,
            "position" => [
                "phase" => "indexing",
                "file_index_cursor" => $processor->file_index_processor->get_cursor(),
                "fresh_local_index_byte_offset" => 0,
            ],
        ];
        return $processor;
    }

    /**
     * Resumes a local scan at its last stored output and traversal positions.
     *
     * @param array $cursor {
     *     Cursor returned by get_cursor().
     *
     *     @type string $work_directory         Directory used by the filesystem traversal.
     *     @type string $filesystem_root        Local filesystem root being scanned.
     *     @type string $fresh_local_index_file JSONL index being written.
     *     @type array  $position               Current indexing or complete position.
     * }
     * @phpstan-param Cursor $cursor
     */
    public static function resume(array $cursor): self
    {
        $processor = new self(
            $cursor["work_directory"],
            $cursor["filesystem_root"],
            $cursor["fresh_local_index_file"]
        );
        $processor->cursor = $cursor;
        if ($cursor["position"]["phase"] !== "indexing") {
            return $processor;
        }

        $processor->fresh_local_index_handle = fopen(
            $processor->fresh_local_index_file,
            "r+b"
        );
        if (!is_resource($processor->fresh_local_index_handle)) {
            throw new RuntimeException(
                "Failed to reopen the fresh local index: {$processor->fresh_local_index_file}"
            );
        }
        $fresh_local_index_byte_offset =
            $cursor["position"]["fresh_local_index_byte_offset"];
        if (
            !ftruncate(
                $processor->fresh_local_index_handle,
                $fresh_local_index_byte_offset
            )
            || fseek(
                $processor->fresh_local_index_handle,
                $fresh_local_index_byte_offset
            ) !== 0
        ) {
            $processor->close();
            throw new RuntimeException(
                "Failed to restore the fresh local index byte offset."
            );
        }
        try {
            $processor->file_index_processor = FileIndexProcessor::resume(
                [$processor->filesystem_root],
                json_encode(
                    $cursor["position"]["file_index_cursor"],
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ),
                false,
                false,
                $processor->work_directory
            );
        } catch (Throwable $exception) {
            $processor->close();
            throw $exception;
        }
        return $processor;
    }

    private function __construct(
        string $work_directory,
        string $filesystem_root,
        string $fresh_local_index_file
    ) {
        $work_directory = trim_right_slash($work_directory);
        if (!is_dir($work_directory)) {
            throw new LogicException(
                "Cannot build a fresh local index without its work directory: {$work_directory}"
            );
        }
        clearstatcache(true, $filesystem_root);
        $resolved_filesystem_root = realpath($filesystem_root);
        if (
            $resolved_filesystem_root === false
            || !is_dir($resolved_filesystem_root)
            || is_link($filesystem_root)
        ) {
            throw new InvalidArgumentException(
                "FreshLocalIndexProcessor requires the filesystem root to be a real directory."
            );
        }
        $this->work_directory = $work_directory;
        $this->filesystem_root = trim_right_slash($resolved_filesystem_root);
        $this->fresh_local_index_file = $fresh_local_index_file;
    }

    /**
     * Performs one filesystem traversal step.
     *
     * The traversal-to-sorting transition returns true so the caller can
     * store that boundary before sorting changes byte order. False means the
     * completed index has been sorted and remains false on later calls.
     */
    public function next_step(): bool
    {
        if ($this->closed) {
            throw new LogicException(
                "Cannot take a fresh local index step after close()."
            );
        }
        if ($this->cursor["position"]["phase"] === "complete") {
            return false;
        }
        if ($this->cursor["position"]["phase"] === "sorting") {
            if (!sort_index_file($this->fresh_local_index_file)) {
                throw new RuntimeException(
                    "Failed to sort the fresh local index: {$this->fresh_local_index_file}"
                );
            }
            $this->cursor["position"] = ["phase" => "complete"];
            return false;
        }
        if (!$this->file_index_processor->next_index_step()) {
            if (!fflush($this->fresh_local_index_handle)) {
                throw new RuntimeException("Failed to flush the fresh local index.");
            }
            $this->file_index_processor->close();
            $this->close_fresh_local_index_handle();
            $this->cursor["position"] = ["phase" => "sorting"];
            return true;
        }

        switch ($this->file_index_processor->get_step_status()) {
            case FileIndexProcessor::STATUS_INDEXED:
                foreach ($this->file_index_processor->get_index_entries() as $entry) {
                    $this->append_index_entry($entry);
                }
                break;

            case FileIndexProcessor::STATUS_DIRECTORY_ERROR:
                $directory_error =
                    $this->file_index_processor->get_directory_error();
                throw new RuntimeException(
                    $directory_error["message"] . ": "
                        . base64_encode($directory_error["path"]) . "."
                );

            case FileIndexProcessor::STATUS_SKIPPED:
            case FileIndexProcessor::STATUS_PATH_UNAVAILABLE:
            case FileIndexProcessor::STATUS_DIRECTORY_COMPLETE:
                break;
        }

        $fresh_local_index_byte_offset = ftell(
            $this->fresh_local_index_handle
        );
        if (!is_int($fresh_local_index_byte_offset)) {
            throw new RuntimeException(
                "Failed to determine the fresh local index byte offset."
            );
        }
        $this->cursor["position"] = [
            "phase" => "indexing",
            "file_index_cursor" => $this->file_index_processor->get_cursor(),
            "fresh_local_index_byte_offset" => $fresh_local_index_byte_offset,
        ];
        return true;
    }

    /** Flushes index bytes before the caller stores the current cursor. */
    public function flush_pending_output(): void
    {
        if (
            is_resource($this->fresh_local_index_handle)
            && !fflush($this->fresh_local_index_handle)
        ) {
            throw new RuntimeException("Failed to flush the fresh local index.");
        }
    }

    /** @phpstan-return Cursor Cursor after the latest completed step. */
    public function get_cursor(): array
    {
        return $this->cursor;
    }

    /** Closes the traversal and index handle. Repeated calls do nothing. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        if (isset($this->file_index_processor)) {
            $this->file_index_processor->close();
        }
        $this->close_fresh_local_index_handle();
        $this->closed = true;
    }

    /**
     * Writes one filesystem entry in the local-index JSONL format.
     *
     * @param array<string,mixed> $entry Entry returned by FileIndexProcessor.
     */
    private function append_index_entry(array $entry): void
    {
        if ($entry["type"] === "other") {
            throw new RuntimeException(
                "Cannot index the unsupported local path: "
                    . base64_encode($entry["path"]) . "."
            );
        }
        if (
            $entry["type"] === "dir"
            && !array_key_exists("empty", $entry)
        ) {
            throw new RuntimeException(
                "Could not inspect the local directory: "
                    . base64_encode($entry["path"]) . "."
            );
        }

        $local_relative_path = relative_path_under(
            $entry["path"],
            $this->filesystem_root
        );
        if ($local_relative_path === null) {
            throw new LogicException(
                "File index path is outside the filesystem root."
            );
        }
        $fresh_local_index_entry = [
            "path" => base64_encode($local_relative_path),
            "ctime" => $entry["ctime"],
            "size" => $entry["size"],
            "type" => $entry["type"],
        ];
        if ($entry["type"] === "dir") {
            $fresh_local_index_entry["empty"] = $entry["empty"];
        }
        $line = json_encode(
            $fresh_local_index_entry,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . "\n";
        if (
            fwrite($this->fresh_local_index_handle, $line)
            !== strlen($line)
        ) {
            throw new RuntimeException(
                "Failed to write a fresh local index entry."
            );
        }
    }

    /** Closes the fresh local index handle when it is open. */
    private function close_fresh_local_index_handle(): void
    {
        if (is_resource($this->fresh_local_index_handle)) {
            fclose($this->fresh_local_index_handle);
        }
        $this->fresh_local_index_handle = null;
    }
}
