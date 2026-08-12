<?php

use function Reprint\Importer\sort_index_file;
use function WordPress\Filesystem\wp_join_unix_paths;
use function WordPress\Reprint\Exporter\relative_path_under;

require_once __DIR__ . '/../index/class-file-sync-patch-planner.php';
require_once __DIR__ . '/../index/class-fresh-local-index-processor.php';
require_once __DIR__ . '/../index/class-index-reader.php';

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Local CLI paths, never HTML output.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Reprint processors use domain names.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match the existing processor classes.

/**
 * Prepares the local tree and fetch list for a mirror pull.
 *
 * The normal remote-index diff finds remote changes. It cannot find a local
 * edit. Before that diff runs, this processor finds local changes and undoes
 * them. A locally added path is removed. A changed or deleted local path is
 * added to the fetch list when it still exists in the current remote index.
 *
 * The existing processors do the work. FreshLocalIndexProcessor scans the
 * local tree. FileSyncPatchPlanner compares that scan with the retained local
 * index. IndexReader reads the current remote index. This class only keeps
 * their order, open handles, and cursor.
 *
 * The caller supplies the same path mapping, selection, fetch-list writer,
 * and safe remover used by the rest of files-pull. One call to next_step()
 * performs one bounded scan, map, sort, or patch step.
 *
 * @phpstan-type Cursor array<string,mixed>
 */
final class PullMirrorProcessor
{
    private string $work_directory;
    private string $filesystem_root;
    private string $retained_local_index_file;
    private string $next_remote_index_file;
    private string $fetch_list_file;
    private string $fresh_local_index_file;
    private string $next_local_index_file;
    private string $active_deletion_roots_file;

    /** @var list<string> */
    private array $included_local_index_roots;

    /** @var list<string> */
    private array $excluded_local_index_roots;

    /** @var callable(string):string */
    private $map_remote_path_to_local_absolute_path;

    /** @var callable(string):bool */
    private $remote_path_is_selected;

    /** @var callable(string):bool */
    private $remove_local_absolute_path;

    /** @var callable(string,resource):void */
    private $append_remote_path_to_fetch_list;

    /** @var Cursor */
    private array $cursor;

    private FreshLocalIndexProcessor $fresh_local_index;
    private FileSyncPatchPlanner $patch_planner;
    private IndexReader $next_remote_index_reader;
    private IndexReader $next_local_index_reader;

    /** @var resource|null */
    private $next_local_index_handle = null;

    /** @var resource|null */
    private $fetch_list_handle = null;

    /** @var array<string,mixed>|null */
    private ?array $next_local_index_entry = null;

    /** Byte offset before the retained mapped-index entry. */
    private int $next_local_index_byte_offset = 0;

    private bool $closed = false;

    /**
     * Starts a mirror plan from the current local and remote trees.
     *
     * @param list<string>     $included_remote_roots Remote roots selected by --include.
     * @param list<string>     $excluded_remote_roots Remote roots omitted by --exclude.
     * @param callable(string):string $map_remote_path_to_local_absolute_path Pull path mapper.
     * @param callable(string):bool   $remote_path_is_selected              Pull selection check.
     * @param callable(string):bool   $remove_local_absolute_path           Safe local remover.
     * @param callable(string,resource):void $append_remote_path_to_fetch_list Fetch-list writer.
     */
    public static function create(
        string $work_directory,
        string $filesystem_root,
        string $retained_local_index_file,
        string $next_remote_index_file,
        string $fetch_list_file,
        string $state_directory,
        array $included_remote_roots,
        array $excluded_remote_roots,
        callable $map_remote_path_to_local_absolute_path,
        callable $remote_path_is_selected,
        callable $remove_local_absolute_path,
        callable $append_remote_path_to_fetch_list
    ): self {
        if (is_dir($work_directory)) {
            $files = scandir($work_directory);
            if ($files === false) {
                throw new RuntimeException("Failed to read the old pull mirror work directory.");
            }
            foreach ($files as $file) {
                if ($file === "." || $file === "..") {
                    continue;
                }
                $path = wp_join_unix_paths($work_directory, $file);
                if (!is_file($path) || !unlink($path)) {
                    throw new RuntimeException("Failed to remove an old pull mirror work file: {$path}.");
                }
            }
            if (!rmdir($work_directory)) {
                throw new RuntimeException("Failed to remove the old pull mirror work directory.");
            }
        }
        if (!mkdir($work_directory, 0755, true)) {
            throw new RuntimeException("Failed to create the pull mirror work directory.");
        }

        $included_local_index_roots = [];
        foreach ($included_remote_roots as $remote_root) {
            $included_local_index_roots[] = self::map_remote_root(
                $remote_root,
                $filesystem_root,
                $map_remote_path_to_local_absolute_path
            );
        }
        if ($included_local_index_roots === []) {
            $included_local_index_roots[] = "";
        }
        $excluded_local_index_roots = [];
        foreach ($excluded_remote_roots as $remote_root) {
            $excluded_local_index_roots[] = self::map_remote_root(
                $remote_root,
                $filesystem_root,
                $map_remote_path_to_local_absolute_path
            );
        }
        $state_directory_local_relative_path = relative_path_under(
            $state_directory,
            $filesystem_root
        );
        if ($state_directory_local_relative_path !== null) {
            $excluded_local_index_roots[] =
                $state_directory_local_relative_path;
        }

        $processor = new self(
            $work_directory,
            $filesystem_root,
            $retained_local_index_file,
            $next_remote_index_file,
            $fetch_list_file,
            $included_local_index_roots,
            $excluded_local_index_roots,
            $map_remote_path_to_local_absolute_path,
            $remote_path_is_selected,
            $remove_local_absolute_path,
            $append_remote_path_to_fetch_list
        );
        $processor->fresh_local_index = FreshLocalIndexProcessor::create(
            $processor->work_directory,
            $processor->filesystem_root,
            $processor->fresh_local_index_file
        );
        $processor->cursor = [
            "work_directory" => $processor->work_directory,
            "filesystem_root" => $processor->filesystem_root,
            "retained_local_index_file" => $processor->retained_local_index_file,
            "next_remote_index_file" => $processor->next_remote_index_file,
            "fetch_list_file" => $processor->fetch_list_file,
            "included_local_index_roots" => $processor->included_local_index_roots,
            "excluded_local_index_roots" => $processor->excluded_local_index_roots,
            "fetch_list_byte_offset" => 0,
            "position" => [
                "phase" => "indexing",
                "fresh_local_index_cursor" =>
                    $processor->fresh_local_index->get_cursor(),
            ],
        ];
        return $processor;
    }

    /**
     * Resumes from the cursor returned by get_cursor().
     *
     * @param callable(string):string $map_remote_path_to_local_absolute_path Pull path mapper.
     * @param callable(string):bool   $remote_path_is_selected              Pull selection check.
     * @param callable(string):bool   $remove_local_absolute_path           Safe local remover.
     * @param callable(string,resource):void $append_remote_path_to_fetch_list Fetch-list writer.
     * @phpstan-param Cursor $cursor
     */
    public static function resume(
        array $cursor,
        callable $map_remote_path_to_local_absolute_path,
        callable $remote_path_is_selected,
        callable $remove_local_absolute_path,
        callable $append_remote_path_to_fetch_list
    ): self {
        $processor = new self(
            $cursor["work_directory"],
            $cursor["filesystem_root"],
            $cursor["retained_local_index_file"],
            $cursor["next_remote_index_file"],
            $cursor["fetch_list_file"],
            $cursor["included_local_index_roots"],
            $cursor["excluded_local_index_roots"],
            $map_remote_path_to_local_absolute_path,
            $remote_path_is_selected,
            $remove_local_absolute_path,
            $append_remote_path_to_fetch_list
        );
        $processor->cursor = $cursor;
        $position = $cursor["position"];
        if ($position["phase"] === "indexing") {
            $processor->fresh_local_index = FreshLocalIndexProcessor::resume(
                $position["fresh_local_index_cursor"]
            );
        } elseif ($position["phase"] === "mapping_remote_index") {
            $processor->open_remote_index_mapping(
                $position["next_remote_index_byte_offset"],
                $position["next_local_index_byte_offset"]
            );
        } elseif ($position["phase"] === "patching") {
            $processor->open_patch(
                $position["patch_planner_cursor"],
                $position["next_local_index_byte_offset"],
                $position["fetch_list_byte_offset"]
            );
        }
        return $processor;
    }

    private function __construct(
        string $work_directory,
        string $filesystem_root,
        string $retained_local_index_file,
        string $next_remote_index_file,
        string $fetch_list_file,
        array $included_local_index_roots,
        array $excluded_local_index_roots,
        callable $map_remote_path_to_local_absolute_path,
        callable $remote_path_is_selected,
        callable $remove_local_absolute_path,
        callable $append_remote_path_to_fetch_list
    ) {
        $this->work_directory = $work_directory;
        $this->filesystem_root = $filesystem_root;
        $this->retained_local_index_file = $retained_local_index_file;
        $this->next_remote_index_file = $next_remote_index_file;
        $this->fetch_list_file = $fetch_list_file;
        $this->fresh_local_index_file = wp_join_unix_paths(
            $work_directory,
            "fresh_local_index.jsonl"
        );
        $this->next_local_index_file = wp_join_unix_paths(
            $work_directory,
            "next_local_index.jsonl"
        );
        $this->active_deletion_roots_file = wp_join_unix_paths(
            $work_directory,
            "active_deletion_roots.jsonl"
        );
        $this->included_local_index_roots = $included_local_index_roots;
        $this->excluded_local_index_roots = $excluded_local_index_roots;
        $this->map_remote_path_to_local_absolute_path =
            $map_remote_path_to_local_absolute_path;
        $this->remote_path_is_selected = $remote_path_is_selected;
        $this->remove_local_absolute_path = $remove_local_absolute_path;
        $this->append_remote_path_to_fetch_list =
            $append_remote_path_to_fetch_list;
    }

    /** Performs one bounded scan, map, sort, or patch step. */
    public function next_step(): bool
    {
        if ($this->closed) {
            throw new LogicException("Cannot take a pull mirror step after close().");
        }
        $position = $this->cursor["position"];
        if ($position["phase"] === "complete") {
            return false;
        }
        if ($position["phase"] === "indexing") {
            $has_next_step = $this->fresh_local_index->next_step();
            $fresh_local_index_cursor = $this->fresh_local_index->get_cursor();
            if (!$has_next_step) {
                $this->fresh_local_index->close();
                $this->open_remote_index_mapping(0, 0);
                $this->cursor["position"] = [
                    "phase" => "mapping_remote_index",
                    "next_remote_index_byte_offset" => 0,
                    "next_local_index_byte_offset" => 0,
                ];
            } else {
                $this->cursor["position"] = [
                    "phase" => "indexing",
                    "fresh_local_index_cursor" => $fresh_local_index_cursor,
                ];
            }
            return true;
        }
        if ($position["phase"] === "mapping_remote_index") {
            $entry = $this->next_remote_index_reader->next_entry();
            if ($entry === null) {
                $this->close_remote_index_mapping();
                $this->cursor["position"] = ["phase" => "sorting_remote_index"];
                return true;
            }
            if (( $this->remote_path_is_selected )($entry["path"])) {
                $remote_absolute_path = $entry["path"];
                $local_absolute_path =
                    ( $this->map_remote_path_to_local_absolute_path )(
                        $remote_absolute_path
                    );
                $local_relative_path = relative_path_under(
                    $local_absolute_path,
                    $this->filesystem_root
                );
                if ($local_relative_path === null || $local_relative_path === "") {
                    throw new RuntimeException(
                        "Cannot map the selected remote path beneath the filesystem root: "
                            . $entry["path"] . "."
                    );
                }
                $entry["path"] = base64_encode($local_relative_path);
                $entry["remote_absolute_path"] = base64_encode(
                    $remote_absolute_path
                );
                $line = json_encode(
                    $entry,
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ) . "\n";
                if (fwrite($this->next_local_index_handle, $line) !== strlen($line)) {
                    throw new RuntimeException("Failed to write the mapped remote index.");
                }
            }
            $next_local_index_byte_offset = ftell($this->next_local_index_handle);
            if (!is_int($next_local_index_byte_offset)) {
                throw new RuntimeException("Failed to read the mapped remote index byte offset.");
            }
            $this->cursor["position"] = [
                "phase" => "mapping_remote_index",
                "next_remote_index_byte_offset" =>
                    $this->next_remote_index_reader->byte_offset(),
                "next_local_index_byte_offset" => $next_local_index_byte_offset,
            ];
            return true;
        }
        if ($position["phase"] === "sorting_remote_index") {
            if (!sort_index_file($this->next_local_index_file)) {
                throw new RuntimeException("Failed to sort the mapped remote index.");
            }
            $this->open_patch(null, 0, 0);
            $this->cursor["position"] = [
                "phase" => "patching",
                "patch_planner_cursor" => $this->patch_planner->get_cursor(),
                "next_local_index_byte_offset" => 0,
                "fetch_list_byte_offset" => 0,
            ];
            return true;
        }

        if (!$this->patch_planner->next_path()) {
            $fetch_list_byte_offset = ftell($this->fetch_list_handle);
            if (!is_int($fetch_list_byte_offset)) {
                throw new RuntimeException("Failed to read the mirror fetch-list byte offset.");
            }
            $this->cursor["fetch_list_byte_offset"] =
                $fetch_list_byte_offset;
            $this->cursor["position"] = [
                "phase" => "complete",
            ];
            return false;
        }
        $operation = $this->patch_planner->get_operation();
        if ($operation !== null) {
            $local_absolute_path = wp_join_unix_paths(
                $this->filesystem_root,
                $operation["path"]
            );
            if (
                ( file_exists($local_absolute_path) || is_link($local_absolute_path) )
                && !( $this->remove_local_absolute_path )($local_absolute_path)
            ) {
                throw new RuntimeException(
                    "Failed to remove the local path before mirroring it: {$local_absolute_path}."
                );
            }
            if ($operation["action"] !== "delete") {
                while (
                    $this->next_local_index_entry !== null
                    && strcmp(
                        $this->next_local_index_entry["path"],
                        $operation["path"]
                    ) < 0
                ) {
                    $this->next_local_index_byte_offset =
                        $this->next_local_index_reader->byte_offset();
                    $this->next_local_index_entry =
                        $this->next_local_index_reader->next_entry();
                }
                if (
                    $this->next_local_index_entry !== null
                    && $this->next_local_index_entry["path"] === $operation["path"]
                ) {
                    ( $this->append_remote_path_to_fetch_list )(
                        $this->next_local_index_entry["remote_absolute_path"],
                        $this->fetch_list_handle
                    );
                    $this->next_local_index_byte_offset =
                        $this->next_local_index_reader->byte_offset();
                    $this->next_local_index_entry =
                        $this->next_local_index_reader->next_entry();
                }
            }
        }
        $fetch_list_byte_offset = ftell($this->fetch_list_handle);
        if (!is_int($fetch_list_byte_offset)) {
            throw new RuntimeException("Failed to read the mirror fetch-list byte offset.");
        }
        $this->cursor["fetch_list_byte_offset"] = $fetch_list_byte_offset;
        $this->cursor["position"] = [
            "phase" => "patching",
            "patch_planner_cursor" => $this->patch_planner->get_cursor(),
            "next_local_index_byte_offset" =>
                $this->next_local_index_byte_offset,
            "fetch_list_byte_offset" => $fetch_list_byte_offset,
        ];
        return true;
    }

    /** Flushes output bytes before the caller stores get_cursor(). */
    public function flush_pending_outputs(): void
    {
        if (isset($this->fresh_local_index)) {
            $this->fresh_local_index->flush_pending_output();
        }
        if (
            ( is_resource($this->next_local_index_handle)
                && !fflush($this->next_local_index_handle) )
            || ( is_resource($this->fetch_list_handle)
                && !fflush($this->fetch_list_handle) )
        ) {
            throw new RuntimeException("Failed to flush a pull mirror output.");
        }
        if (isset($this->patch_planner)) {
            $this->patch_planner->flush_pending_outputs();
        }
    }

    /** @phpstan-return Cursor */
    public function get_cursor(): array
    {
        return $this->cursor;
    }

    /** Closes every retained handle. */
    public function close(): void
    {
        if (isset($this->fresh_local_index)) {
            $this->fresh_local_index->close();
        }
        if (isset($this->patch_planner)) {
            $this->patch_planner->close();
        }
        $this->close_remote_index_mapping();
        if (isset($this->next_local_index_reader)) {
            $this->next_local_index_reader->close();
        }
        if (is_resource($this->fetch_list_handle)) {
            fclose($this->fetch_list_handle);
        }
        $this->fetch_list_handle = null;
        $this->closed = true;
    }

    private static function map_remote_root(
        string $remote_root,
        string $filesystem_root,
        callable $map_remote_path_to_local_absolute_path
    ): string {
        $local_relative_path = relative_path_under(
            $map_remote_path_to_local_absolute_path($remote_root),
            $filesystem_root
        );
        if ($local_relative_path === null) {
            throw new LogicException(
                "A selected remote path maps outside the filesystem root."
            );
        }
        return $local_relative_path;
    }

    private function open_remote_index_mapping(
        int $next_remote_index_byte_offset,
        int $next_local_index_byte_offset
    ): void {
        $this->next_remote_index_reader = new IndexReader(
            $this->next_remote_index_file
        );
        $this->next_remote_index_reader->open();
        $this->next_remote_index_reader->seek_to_byte_offset(
            $next_remote_index_byte_offset
        );
        $this->next_local_index_handle = fopen(
            $this->next_local_index_file,
            $next_local_index_byte_offset === 0 ? "w+b" : "r+b"
        );
        if (!is_resource($this->next_local_index_handle)) {
            $this->next_remote_index_reader->close();
            throw new RuntimeException("Failed to open the mapped remote index.");
        }
        if (
            !ftruncate(
                $this->next_local_index_handle,
                $next_local_index_byte_offset
            )
            || fseek(
                $this->next_local_index_handle,
                $next_local_index_byte_offset
            ) !== 0
        ) {
            $this->close_remote_index_mapping();
            throw new RuntimeException("Failed to restore the mapped remote index.");
        }
    }

    private function close_remote_index_mapping(): void
    {
        if (isset($this->next_remote_index_reader)) {
            $this->next_remote_index_reader->close();
        }
        if (is_resource($this->next_local_index_handle)) {
            fclose($this->next_local_index_handle);
        }
        $this->next_local_index_handle = null;
    }

    /** @param array<string,mixed>|null $patch_planner_cursor */
    private function open_patch(
        ?array $patch_planner_cursor,
        int $next_local_index_byte_offset,
        int $fetch_list_byte_offset
    ): void {
        $this->patch_planner = $patch_planner_cursor === null
            ? FileSyncPatchPlanner::create(
                $this->fresh_local_index_file,
                $this->retained_local_index_file,
                $this->active_deletion_roots_file,
                $this->included_local_index_roots,
                $this->excluded_local_index_roots
            )
            : FileSyncPatchPlanner::resume($patch_planner_cursor);
        $this->next_local_index_reader = new IndexReader(
            $this->next_local_index_file
        );
        $this->next_local_index_reader->open();
        $this->next_local_index_reader->seek_to_byte_offset(
            $next_local_index_byte_offset
        );
        $this->next_local_index_byte_offset =
            $next_local_index_byte_offset;
        $this->next_local_index_entry =
            $this->next_local_index_reader->next_entry();
        $this->fetch_list_handle = fopen($this->fetch_list_file, "c+b");
        if (!is_resource($this->fetch_list_handle)) {
            $this->patch_planner->close();
            $this->next_local_index_reader->close();
            throw new RuntimeException("Failed to open the files-pull fetch list.");
        }
        if (
            !ftruncate($this->fetch_list_handle, $fetch_list_byte_offset)
            || fseek($this->fetch_list_handle, $fetch_list_byte_offset) !== 0
        ) {
            $this->close();
            throw new RuntimeException("Failed to restore the mirror fetch list.");
        }
    }
}
