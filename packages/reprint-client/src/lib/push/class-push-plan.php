<?php

use function WordPress\Filesystem\wp_join_unix_paths;
use function Reprint\Importer\sort_index_file;
use function WordPress\Reprint\Exporter\relative_path_under;
use function WordPress\Reprint\Exporter\trim_right_slash;

require_once __DIR__ . '/../index/class-fresh-local-index-processor.php';
require_once __DIR__ . '/../index/class-file-sync-patch-planner.php';
require_once __DIR__ . '/../index/class-index-reader.php';
require_once __DIR__ . '/../sort-index-file.php';

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Journal failures are CLI/API values, never HTML output.

/**
 * Internal bounded local-index and file-sync planner.
 *
 * PushPlan builds a sorted fresh local index, then feeds two indexes to
 * FileSyncPatchPlanner. A push compares the retained local index with the
 * fresh scan. A mirror pull reverses that comparison: the fresh scan is the
 * tree to change, and the retained local index is the last completed sync.
 * The caller applies each mirror operation and later downloads the remote
 * paths written by the plan.
 *
 * PushFilesSender or the files-diff command owns the caller-visible lifecycle,
 * lock, top-level phase, result, and terminal behavior. PushPlan owns
 * FreshLocalIndexProcessor, FileSyncPatchPlanner, the meaning of its cursor,
 * and the two completed path lists. A caller which resumes across processes
 * stores the cursor returned by get_cursor().
 *
 * ## Durable boundary
 *
 * The PushPlan cursor contains `indexing`, `sorting_fresh_local_index`,
 * `starting_diff`, `diffing`, or `complete`. The separate sorting phase gives
 * the caller a durable boundary before the index changes byte order. A false
 * next_step() result means both indexes reached EOF; the caller stores the
 * returned cursor and closes the plan before changing its phase. The completed
 * files remain in the caller-owned plan directory until the caller no longer
 * needs them.
 *
 * ## Change detection
 *
 * ctime is machine-local, so the local index must describe the same filesystem
 * root on the same local machine. The caller supplies the local index for its
 * remote Reprint API URL. File and symlink changes are determined by type,
 * ctime, and size. Directory changes use the indexer's empty-directory marker;
 * non-empty directories are represented by their descendants.
 *
 * With no local index, every file, symlink, and empty directory is
 * selected, and no deletion can be detected. Excluded paths are omitted from
 * both path lists but remain in the fresh local index.
 *
 * The index reader trusts the entry values produced by the indexer. It retains
 * failure handling for reading lines, decoding JSON, and decoding base64 paths.
 *
 * ## Durability and memory
 *
 * Each indexing step advances one FreshLocalIndexProcessor step. A separate
 * step starts the index diff. Each diff step compares at most one path
 * represented by either index and updates its next cursor.
 * The owner flushes pending output before storing a cursor. `resume()` discards
 * bytes beyond saved offsets, so an interrupted step cannot leave duplicate
 * durable entries.
 *
 * FileSyncPatchPlanner retains the next entry from each index and writes the
 * active directory-deletion roots needed to suppress redundant descendant
 * deletions. PushPlan stores its cursor without unpacking it. Neither class
 * loads an index, path list, or the active deletion roots file in full.
 *
 * @phpstan-type FileIndexCursor array{stack:list<array{dir:string,after:string|null}>}
 * @phpstan-type FreshLocalIndexPosition array{phase:'indexing',file_index_cursor:FileIndexCursor,fresh_local_index_byte_offset:int}|array{phase:'sorting'}|array{phase:'complete'}
 * @phpstan-type FreshLocalIndexCursor array{work_directory:string,filesystem_root:string,fresh_local_index_file:string,position:FreshLocalIndexPosition}
 * @phpstan-type IndexingCursor array{phase:'indexing',fresh_local_index_cursor:FreshLocalIndexCursor}
 * @phpstan-type SortingFreshLocalIndexCursor array{phase:'sorting_fresh_local_index',fresh_local_index_cursor:FreshLocalIndexCursor}
 * @phpstan-type StartingDiffCursor array{phase:'starting_diff'}
 * @phpstan-type FileSyncPlannerIndexDiffCursor array{old_index_byte_offset:int,new_index_byte_offset:int,preceding_new_index_entry_path_b64:string|null}
 * @phpstan-type FileSyncPlannerCursor array{patch_base_index_file:string,patch_result_index_file:string,active_deletion_roots_file:string,included_index_path_roots:list<string>,excluded_index_path_roots:list<string>,index_diff_cursor:FileSyncPlannerIndexDiffCursor,active_deletion_root_byte_offset:int|null}
 * @phpstan-type MappingRemoteIndexCursor array{phase:'mapping_remote_index',next_remote_index_byte_offset:int,mapped_remote_index_byte_offset:int}
 * @phpstan-type SortingMappedRemoteIndexCursor array{phase:'sorting_mapped_remote_index'}
 * @phpstan-type IndexDiffCursor array{phase:'diffing',file_sync_planner_cursor:FileSyncPlannerCursor,byte_offset_in_local_paths_to_push:int,byte_offset_in_local_paths_to_delete:int,fetch_list_byte_offset?:int,mapped_remote_index_byte_offset?:int,local_paths_to_push_count:int|null,local_file_bytes_to_push:int|null}
 * @phpstan-type CompleteCursor array{phase:'complete',local_paths_to_push_count:int|null,local_file_bytes_to_push:int|null}
 * @phpstan-type PushPlanPosition MappingRemoteIndexCursor|SortingMappedRemoteIndexCursor|IndexingCursor|SortingFreshLocalIndexCursor|StartingDiffCursor|IndexDiffCursor|CompleteCursor
 * @phpstan-type PushPlanCursor array{plan_directory:string,filesystem_root:string,local_index_file:string,document_root_local_relative_path:string,mapped_remote_index_file?:string,next_remote_index_file?:string,fetch_list_file?:string,included_index_path_roots?:list<string>,excluded_index_path_roots?:list<string>,position:PushPlanPosition}
 */
class PushPlan
{
    /** @var string Resolved filesystem root inspected while building the fresh local index. */
    private string $filesystem_root;

    /** @var string Document root relative to the local filesystem root. */
    private string $document_root_local_relative_path;

    /** @var string Caller-owned active plan directory. */
    private string $plan_directory;

    /** @var string Local index supplied by the caller. */
    private string $local_index_file;

    /** @var string JSONL file of local paths to push. */
    private string $local_paths_to_push;

    /** @var string Raw NUL-delimited local paths to delete. */
    private string $local_paths_to_delete;

    /** @var string JSONL file of remote paths needed by a mirror pull. */
    private string $fetch_list_file;

    /** @var string|null Current remote index mapped to local-index paths. */
    private ?string $mapped_remote_index_file = null;

    /** @var list<string> Local-index roots changed by a mirror pull. */
    private array $included_index_path_roots = [];

    /** @var list<string> Local-index roots left alone by a mirror pull. */
    private array $excluded_index_path_roots = [];

    /** @var string Plan-owned fresh local index file. */
    private string $fresh_local_index_file;

    /** @var string Plan path containing receiver-owned exclusions for the active push. */
    private string $excluded_paths_file;

    /** @var string State for directory deletions which cover paths not processed yet. */
    private string $active_deletion_roots_file;

    /** @var list<string> Receiver-owned paths that the plan must not push or delete. */
    private array $excluded_paths = [];

    /** @var PushPlanCursor Current cursor returned to the caller. */
    private array $cursor;

    /** @var bool Whether close() has closed this plan's file handles. */
    private bool $closed = false;

    /** Fresh local index builder retained during indexing. */
    private FreshLocalIndexProcessor $fresh_local_index_processor;

    /** File-sync patch planner retained during the diff phase. */
    private FileSyncPatchPlanner $patch_planner;

    /** @var resource|null */
    private $local_paths_to_push_handle = null;
    /** @var resource|null */
    private $local_paths_to_delete_handle = null;
    /** @var resource|null */
    private $fetch_list_handle = null;
    private IndexReader $mapped_remote_index_reader;
    /** @var array<string,mixed>|null */
    private ?array $mapped_remote_index_entry = null;
    private int $mapped_remote_index_byte_offset = 0;
    private string $next_remote_index_file;
    /** @var callable(string):string|null */
    private $map_remote_path = null;
    /** @var callable(string):bool|null */
    private $remote_path_is_selected = null;
    private IndexReader $next_remote_index_reader;
    /** @var resource|null */
    private $mapped_remote_index_handle = null;
    /** @var array<string,mixed>|null Last mirror operation returned by get_operation(). */
    private ?array $operation = null;
    /** @var int|null Combined size of the two retained indexes during the index diff. */
    private ?int $index_bytes_total = null;

    /**
     * Starts a push plan by opening a fresh local index traversal.
     *
     * Copies the target exclusions into the plan directory before opening the
     * fresh local index traversal. Until the caller stores the returned cursor,
     * an interrupted start is repeated and overwrites these initial plan files.
     *
     * @param string $plan_directory      Caller-owned active plan directory.
     * @param string $filesystem_root     Resolved filesystem root.
     * @param string $local_index_file    Local index file this plan diffs against.
     * @param string $excluded_paths_path Caller-owned target exclusions file.
     * @param string $document_root_local_relative_path Document root relative to the local filesystem root.
     * @return self Open plan positioned at the initial indexing cursor.
     */
    public static function start(
        string $plan_directory,
        string $filesystem_root,
        string $local_index_file,
        string $excluded_paths_path,
        string $document_root_local_relative_path = ""
    ): self {
        $plan = new self(
            $plan_directory,
            $filesystem_root,
            $local_index_file,
            $document_root_local_relative_path
        );
        if (!@copy($excluded_paths_path, $plan->excluded_paths_file)) {
            throw new RuntimeException("Failed to copy excluded paths into the push plan: {$excluded_paths_path}");
        }
        $plan->excluded_paths = $plan->load_excluded_paths();
        $plan->fresh_local_index_processor = FreshLocalIndexProcessor::create(
            $plan->plan_directory,
            $plan->filesystem_root,
            $plan->fresh_local_index_file
        );
        $plan->cursor = [
            "plan_directory" => $plan->plan_directory,
            "filesystem_root" => $plan->filesystem_root,
            "local_index_file" => $plan->local_index_file,
            "document_root_local_relative_path" => $plan->document_root_local_relative_path,
            "position" => [
                "phase" => "indexing",
                "fresh_local_index_cursor" =>
                    $plan->fresh_local_index_processor->get_cursor(),
            ],
        ];
        return $plan;
    }

    /**
     * Starts the local plan for a mirror pull.
     *
     * The fresh scan is the tree which must change. The retained local index
     * is the tree from the last completed sync. The mapped remote index tells
     * the plan which remote path supplies each copy operation.
     *
     * @param list<string> $included_remote_roots Remote roots to mirror.
     * @param list<string> $excluded_remote_roots Remote roots to leave alone.
     * @param callable(string):string $map_remote_path Pull path mapper.
     * @param callable(string):bool $remote_path_is_selected Pull selection check.
     */
    public static function start_mirror(
        string $plan_directory,
        string $filesystem_root,
        string $local_index_file,
        string $next_remote_index_file,
        string $fetch_list_file,
        string $state_directory,
        array $included_remote_roots,
        array $excluded_remote_roots,
        callable $map_remote_path,
        callable $remote_path_is_selected
    ): self {
        $plan = new self($plan_directory, $filesystem_root, $local_index_file, "");
        $plan->mapped_remote_index_file = wp_join_unix_paths($plan_directory, "mapped_remote_index.jsonl");
        $plan->next_remote_index_file = $next_remote_index_file;
        $plan->fetch_list_file = $fetch_list_file;
        $plan->map_remote_path = $map_remote_path;
        $plan->remote_path_is_selected = $remote_path_is_selected;
        foreach ($included_remote_roots as $remote_root) {
            $local_root = relative_path_under($map_remote_path($remote_root), $plan->filesystem_root);
            if ($local_root === null) {
                throw new RuntimeException("Cannot map included remote root beneath the filesystem root: {$remote_root}.");
            }
            $plan->included_index_path_roots[] = $local_root;
        }
        if ($plan->included_index_path_roots === []) {
            $plan->included_index_path_roots[] = "";
        }
        foreach ($excluded_remote_roots as $remote_root) {
            $local_root = relative_path_under($map_remote_path($remote_root), $plan->filesystem_root);
            if ($local_root === null) {
                throw new RuntimeException("Cannot map excluded remote root beneath the filesystem root: {$remote_root}.");
            }
            $plan->excluded_index_path_roots[] = $local_root;
        }
        $state_root = relative_path_under($state_directory, $plan->filesystem_root);
        if ($state_root !== null) {
            $plan->excluded_index_path_roots[] = $state_root;
        }
        $plan->next_remote_index_reader = new IndexReader($next_remote_index_file);
        $plan->next_remote_index_reader->open();
        $plan->mapped_remote_index_handle = fopen($plan->mapped_remote_index_file, "wb");
        if (!is_resource($plan->mapped_remote_index_handle)) {
            $plan->next_remote_index_reader->close();
            throw new RuntimeException("Failed to open the mapped remote index.");
        }
        $plan->cursor = [
            "plan_directory" => $plan->plan_directory,
            "filesystem_root" => $plan->filesystem_root,
            "local_index_file" => $plan->local_index_file,
            "document_root_local_relative_path" => "",
            "mapped_remote_index_file" => $plan->mapped_remote_index_file,
            "next_remote_index_file" => $next_remote_index_file,
            "fetch_list_file" => $fetch_list_file,
            "included_index_path_roots" => $plan->included_index_path_roots,
            "excluded_index_path_roots" => $plan->excluded_index_path_roots,
            "position" => [
                "phase" => "mapping_remote_index",
                "next_remote_index_byte_offset" => 0,
                "mapped_remote_index_byte_offset" => 0,
            ],
        ];
        return $plan;
    }

    /**
     * Resumes the unfinished push plan retained in local push state.
     *
     * Reopens only the processor and files required by the cursor's current
     * internal phase.
     *
     * @phpstan-param PushPlanCursor $cursor Cursor previously returned by get_cursor().
     * @return self Open plan positioned at its last durable cursor.
     */
    public static function resume(
        array $cursor,
        ?callable $map_remote_path = null,
        ?callable $remote_path_is_selected = null
    ): self
    {
        if (!array_key_exists("document_root_local_relative_path", $cursor)) {
            // Older cursors had no document-root mapping and used local relative paths unchanged.
            $cursor["document_root_local_relative_path"] = "";
        }
        $position = $cursor["position"];
        if (
            ($position["phase"] === "diffing" || $position["phase"] === "complete")
            && !array_key_exists("local_paths_to_push_count", $position)
        ) {
            // Keep the plan single-pass when an older cursor has no path count.
            $position["local_paths_to_push_count"] = null;
            $cursor["position"] = $position;
        }
        if (
            ( $position["phase"] === "diffing" || $position["phase"] === "complete" )
            && !array_key_exists("local_file_bytes_to_push", $position)
        ) {
            // Keep the plan single-pass when an older cursor has no file byte total.
            $position["local_file_bytes_to_push"] = null;
            $cursor["position"] = $position;
        }

        $plan = new self(
            $cursor["plan_directory"],
            $cursor["filesystem_root"],
            $cursor["local_index_file"],
            $cursor["document_root_local_relative_path"]
        );
        if (array_key_exists("mapped_remote_index_file", $cursor)) {
            $plan->mapped_remote_index_file = $cursor["mapped_remote_index_file"];
            $plan->next_remote_index_file = $cursor["next_remote_index_file"];
            $plan->fetch_list_file = $cursor["fetch_list_file"];
            $plan->included_index_path_roots = $cursor["included_index_path_roots"];
            $plan->excluded_index_path_roots = $cursor["excluded_index_path_roots"];
            $plan->map_remote_path = $map_remote_path;
            $plan->remote_path_is_selected = $remote_path_is_selected;
        }
        $plan->cursor = $cursor;
        $position = $plan->cursor["position"];
        if ($position["phase"] !== "complete" && $plan->mapped_remote_index_file === null) {
            $plan->excluded_paths = $plan->load_excluded_paths();
        }
        if ($position["phase"] === "mapping_remote_index") {
            if ($map_remote_path === null || $remote_path_is_selected === null) {
                throw new InvalidArgumentException("Mirror mapping requires its path mapper and selection check.");
            }
            $plan->next_remote_index_reader = new IndexReader($plan->next_remote_index_file);
            $plan->next_remote_index_reader->open();
            $plan->next_remote_index_reader->seek_to_byte_offset($position["next_remote_index_byte_offset"]);
            $plan->mapped_remote_index_handle = $plan->open_push_plan_output_file_at_byte_offset(
                $plan->mapped_remote_index_file,
                $position["mapped_remote_index_byte_offset"]
            );
        } elseif (
            $position["phase"] === "indexing"
            || $position["phase"] === "sorting_fresh_local_index"
        ) {
            $plan->fresh_local_index_processor =
                FreshLocalIndexProcessor::resume(
                    $position["fresh_local_index_cursor"]
                );
        } elseif ($position["phase"] === "diffing") {
            $plan->open_plan_output_files(
                $position["byte_offset_in_local_paths_to_push"],
                $position["byte_offset_in_local_paths_to_delete"],
                $plan->mapped_remote_index_file === null
                    ? 0
                    : $position["fetch_list_byte_offset"],
                $plan->mapped_remote_index_file === null
                    ? 0
                    : $position["mapped_remote_index_byte_offset"]
            );
            $file_sync_planner_cursor =
                $position["file_sync_planner_cursor"];
            $plan->patch_planner = FileSyncPatchPlanner::resume(
                $file_sync_planner_cursor
            );
            $plan->set_index_bytes_total();
        }
        return $plan;
    }

    /**
     * Returns the cursor required to resume this plan.
     *
     * @phpstan-return PushPlanCursor Current cursor after the latest completed step.
     */
    public function get_cursor(): array
    {
        return $this->cursor;
    }

    /**
     * Returns the JSONL local paths to push list.
     */
    public function get_local_paths_to_push_path(): string
    {
        return $this->local_paths_to_push;
    }

    /**
     * Returns progress through the open plan.
     *
     * Durable byte offsets across both indexes describe diff progress. An
     * exact path count would require another pass over the local index.
     *
     * @return array {
     *     Current planning progress.
     *
     *     @type string $phase             Current PushPlan phase.
     *     @type int    $index_bytes_done  Index bytes consumed. Present while diffing.
     *     @type int    $index_bytes_total Combined size of both indexes. Present while diffing.
     * }
     * @phpstan-return array{phase:string,index_bytes_done?:int,index_bytes_total?:int}
     */
    public function get_progress(): array
    {
        $position = $this->cursor["position"];
        $progress = ["phase" => $position["phase"]];
        if ($position["phase"] !== "diffing" || $this->index_bytes_total === null) {
            return $progress;
        }

        $index_diff_cursor =
            $position["file_sync_planner_cursor"]["index_diff_cursor"];
        $index_bytes_done = $index_diff_cursor["new_index_byte_offset"]
            + $index_diff_cursor["old_index_byte_offset"];
        $progress["index_bytes_done"] = min($index_bytes_done, $this->index_bytes_total);
        $progress["index_bytes_total"] = $this->index_bytes_total;
        return $progress;
    }

    /**
     * Returns the raw NUL-delimited path list produced for local deletions.
     */
    public function get_local_paths_to_delete_path(): string
    {
        return $this->local_paths_to_delete;
    }

    /**
     * Returns the mirror operation produced by the latest step.
     *
     * The caller removes this local path. A `copy` or `replace` operation also
     * has a matching path in the caller's fetch list.
     *
     * @return array|null {
     *     Mirror operation, or null when the latest step changed no local path.
     *
     *     @type string $action `copy`, `replace`, or `delete`.
     *     @type string $path   Path relative to the local filesystem root.
     * }
     */
    public function get_operation(): ?array
    {
        return $this->operation;
    }

    /**
     * Returns the plan-owned fresh local index path.
     */
    public function get_fresh_local_index_path(): string
    {
        return $this->fresh_local_index_file;
    }

    /**
     * Flushes plan files before the owner persists the current cursor.
     *
     * A later process truncates bytes beyond that cursor before appending.
     */
    public function flush_pending_outputs(): void
    {
        if (isset($this->fresh_local_index_processor)) {
            $this->fresh_local_index_processor->flush_pending_output();
        }
        if (
            ( is_resource($this->local_paths_to_push_handle) && !fflush($this->local_paths_to_push_handle) )
            || ( is_resource($this->local_paths_to_delete_handle) && !fflush($this->local_paths_to_delete_handle) )
            || ( is_resource($this->fetch_list_handle) && !fflush($this->fetch_list_handle) )
            || ( is_resource($this->mapped_remote_index_handle) && !fflush($this->mapped_remote_index_handle) )
        ) {
            throw new RuntimeException("Failed to flush a push-plan output.");
        }
        if (isset($this->patch_planner)) {
            $this->patch_planner->flush_pending_outputs();
        }
    }

    /**
     * Initializes paths in the caller-owned active plan directory.
     *
     * @param string $plan_directory   Caller-owned active plan directory.
     * @param string $filesystem_root  Resolved filesystem root.
     * @param string $local_index_file Local index file this plan diffs against.
     * @param string $document_root_local_relative_path Document root relative to the local filesystem root.
     */
    private function __construct(
        string $plan_directory,
        string $filesystem_root,
        string $local_index_file,
        string $document_root_local_relative_path
    ) {
        $plan_directory = trim_right_slash($plan_directory);
        if (!is_dir($plan_directory)) {
            throw new LogicException("Cannot open a push plan without its directory: {$plan_directory}");
        }
        $this->plan_directory = $plan_directory;
        $this->set_filesystem_root($filesystem_root);
        $this->local_index_file = $local_index_file;
        $this->document_root_local_relative_path =
            rtrim($document_root_local_relative_path, "/");
        $this->local_paths_to_push = wp_join_unix_paths($plan_directory, "local_paths_to_push.jsonl");
        $this->local_paths_to_delete = wp_join_unix_paths($plan_directory, "local_paths_to_delete");
        $this->fresh_local_index_file = wp_join_unix_paths($plan_directory, "fresh_local_index.jsonl");
        $this->excluded_paths_file = wp_join_unix_paths($plan_directory, "excluded_paths.json");
        $this->active_deletion_roots_file = wp_join_unix_paths($plan_directory, "deleted_directories_stack.jsonl");
    }

    /**
     * Stores the resolved filesystem root represented by this plan.
     *
     * @param string $filesystem_root Filesystem root selected by the caller.
     */
    private function set_filesystem_root(string $filesystem_root): void
    {
        clearstatcache(true, $filesystem_root);
        $resolved_local_filesystem_root = realpath($filesystem_root);
        if ($resolved_local_filesystem_root === false || !is_dir($resolved_local_filesystem_root) || is_link($filesystem_root)) {
            throw new InvalidArgumentException("PushPlan requires the filesystem root to be a real directory.");
        }
        $this->filesystem_root = trim_right_slash($resolved_local_filesystem_root);
    }

    /**
     * Performs one step for the current internal phase.
     *
     * A false return means planning is complete and remains false on later
     * calls. The owning caller closes the plan before using its path lists.
     *
     * @return bool Whether another planning step may be performed.
     */
    public function next_step(): bool
    {
        $this->operation = null;
        $position = $this->cursor["position"];
        if ($position["phase"] === "complete") {
            return false;
        }
        if ($this->closed) {
            throw new LogicException("Cannot take a push plan step after close().");
        }

        switch ($position["phase"]) {
            case "mapping_remote_index":
                $entry = $this->next_remote_index_reader->next_entry();
                if ($entry === null) {
                    fclose($this->mapped_remote_index_handle);
                    $this->mapped_remote_index_handle = null;
                    $this->next_remote_index_reader->close();
                    $this->cursor["position"] = ["phase" => "sorting_mapped_remote_index"];
                    return true;
                }
                if (( $this->remote_path_is_selected )($entry["path"])) {
                    $remote_path = $entry["path"];
                    $local_path = relative_path_under(
                        ( $this->map_remote_path )($remote_path),
                        $this->filesystem_root
                    );
                    if ($local_path === null || $local_path === "") {
                        throw new RuntimeException("Cannot map selected remote path beneath the filesystem root: {$remote_path}.");
                    }
                    $entry["path"] = base64_encode($local_path);
                    $entry["remote_absolute_path"] = base64_encode($remote_path);
                    $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
                    if (fwrite($this->mapped_remote_index_handle, $line) !== strlen($line)) {
                        throw new RuntimeException("Short write on the mapped remote index, is the disk full?");
                    }
                }
                $mapped_remote_index_byte_offset = ftell($this->mapped_remote_index_handle);
                if (!is_int($mapped_remote_index_byte_offset)) {
                    throw new RuntimeException("Failed to read the mapped remote index byte offset.");
                }
                $this->cursor["position"] = [
                    "phase" => "mapping_remote_index",
                    "next_remote_index_byte_offset" => $this->next_remote_index_reader->byte_offset(),
                    "mapped_remote_index_byte_offset" => $mapped_remote_index_byte_offset,
                ];
                return true;
            case "sorting_mapped_remote_index":
                if (!sort_index_file($this->mapped_remote_index_file)) {
                    throw new RuntimeException("Failed to sort the mapped remote index.");
                }
                $this->fresh_local_index_processor = FreshLocalIndexProcessor::create(
                    $this->plan_directory,
                    $this->filesystem_root,
                    $this->fresh_local_index_file
                );
                $this->cursor["position"] = [
                    "phase" => "indexing",
                    "fresh_local_index_cursor" => $this->fresh_local_index_processor->get_cursor(),
                ];
                return true;
            case "indexing":
                $this->next_file_index_step();
                return true;
            case "sorting_fresh_local_index":
                if ($this->fresh_local_index_processor->next_step()) {
                    throw new LogicException(
                        "The fresh local index did not finish sorting."
                    );
                }
                $this->cursor["position"] = ["phase" => "starting_diff"];
                return true;
            case "starting_diff":
                $this->start_index_diff();
                return true;
            case "diffing":
                return $this->next_index_diff_step();
            default:
                throw new LogicException("Unknown PushPlan phase: {$position["phase"]}.");
        }
    }

    /**
     * Performs one fresh-local-index step and stores its cursor unchanged.
     */
    private function next_file_index_step(): void
    {
        $has_next_step = $this->fresh_local_index_processor->next_step();
        $fresh_local_index_cursor =
            $this->fresh_local_index_processor->get_cursor();
        if (
            $fresh_local_index_cursor["position"]["phase"] === "sorting"
        ) {
            $this->cursor["position"] = [
                "phase" => "sorting_fresh_local_index",
                "fresh_local_index_cursor" => $fresh_local_index_cursor,
            ];
            return;
        }
        if (!$has_next_step) {
            $this->cursor["position"] = ["phase" => "starting_diff"];
            return;
        }
        $this->cursor["position"] = [
            "phase" => "indexing",
            "fresh_local_index_cursor" => $fresh_local_index_cursor,
        ];
    }

    /** Starts the index diff after FreshLocalIndexProcessor sorted its output. */
    private function start_index_diff(): void
    {
        $this->open_plan_output_files(0, 0, 0, 0);
        $this->patch_planner = FileSyncPatchPlanner::create(
            $this->mapped_remote_index_file === null
                ? $this->local_index_file
                : $this->fresh_local_index_file,
            $this->mapped_remote_index_file === null
                ? $this->fresh_local_index_file
                : $this->local_index_file,
            $this->active_deletion_roots_file,
            $this->mapped_remote_index_file === null
                ? [$this->document_root_local_relative_path]
                : $this->included_index_path_roots,
            $this->mapped_remote_index_file === null
                ? $this->get_excluded_index_path_roots()
                : $this->excluded_index_path_roots
        );
        $position = [
            "phase" => "diffing",
            "file_sync_planner_cursor" => $this->patch_planner->get_cursor(),
            "byte_offset_in_local_paths_to_push" => 0,
            "byte_offset_in_local_paths_to_delete" => 0,
            "local_paths_to_push_count" => 0,
            "local_file_bytes_to_push" => 0,
        ];
        if ($this->mapped_remote_index_file !== null) {
            $position["fetch_list_byte_offset"] = 0;
            $position["mapped_remote_index_byte_offset"] = 0;
        }
        $this->cursor["position"] = $position;
        $this->set_index_bytes_total();
    }

    /** Opens both patch-plan outputs at their durable byte offsets. */
    private function open_plan_output_files(
        int $byte_offset_in_local_paths_to_push,
        int $byte_offset_in_local_paths_to_delete,
        int $fetch_list_byte_offset,
        int $mapped_remote_index_byte_offset
    ): void {
        if ($this->mapped_remote_index_file === null) {
            $this->local_paths_to_push_handle =
                $this->open_push_plan_output_file_at_byte_offset(
                    $this->local_paths_to_push,
                    $byte_offset_in_local_paths_to_push
                );
            $this->local_paths_to_delete_handle =
                $this->open_push_plan_output_file_at_byte_offset(
                    $this->local_paths_to_delete,
                    $byte_offset_in_local_paths_to_delete
                );
        } else {
            $this->fetch_list_handle =
                $this->open_push_plan_output_file_at_byte_offset(
                    $this->fetch_list_file,
                    $fetch_list_byte_offset
                );
            $this->mapped_remote_index_reader = new IndexReader(
                $this->mapped_remote_index_file
            );
            $this->mapped_remote_index_reader->open();
            $this->mapped_remote_index_reader->seek_to_byte_offset(
                $mapped_remote_index_byte_offset
            );
            $this->mapped_remote_index_byte_offset =
                $mapped_remote_index_byte_offset;
            $this->mapped_remote_index_entry =
                $this->mapped_remote_index_reader->next_entry();
        }
    }

    /** Returns target exclusions in local-index coordinates. */
    private function get_excluded_index_path_roots(): array
    {
        $excluded_index_path_roots = [];
        foreach ($this->excluded_paths as $excluded_path) {
            $excluded_index_path_roots[] =
                $this->document_root_local_relative_path === ""
                    ? $excluded_path
                    : wp_join_unix_paths(
                        $this->document_root_local_relative_path,
                        $excluded_path
                    );
        }
        return $excluded_index_path_roots;
    }

    /** Stores the byte total used to report index-diff progress. */
    private function set_index_bytes_total(): void
    {
        $fresh_local_index_bytes = filesize($this->fresh_local_index_file);
        $local_index_bytes = is_file($this->local_index_file)
            ? filesize($this->local_index_file)
            : 0;
        if (is_int($fresh_local_index_bytes) && is_int($local_index_bytes)) {
            $this->index_bytes_total = $fresh_local_index_bytes
                + $local_index_bytes;
        }
    }

    /**
     * Compares at most one path and updates the resulting push plan cursor.
     *
     * Exclusions suppress planned changes, not entries in the retained fresh
     * local index.
     *
     * @return bool Whether another index diff step may be performed.
     */
    private function next_index_diff_step(): bool
    {
        /** @var IndexDiffCursor $cursor */
        $cursor = $this->cursor["position"];
        $local_paths_to_push_count = $cursor["local_paths_to_push_count"];
        $local_file_bytes_to_push = $cursor["local_file_bytes_to_push"];

        if (!$this->patch_planner->next_path()) {
            if (
                ( is_resource($this->local_paths_to_push_handle) && !fflush($this->local_paths_to_push_handle) )
                || ( is_resource($this->local_paths_to_delete_handle) && !fflush($this->local_paths_to_delete_handle) )
                || ( is_resource($this->fetch_list_handle) && !fflush($this->fetch_list_handle) )
            ) {
                throw new RuntimeException("Failed to flush a push-plan output.");
            }
            $this->patch_planner->flush_pending_outputs();
            $this->cursor["position"] = [
                "phase" => "complete",
                "local_paths_to_push_count" => $local_paths_to_push_count,
                "local_file_bytes_to_push" => $local_file_bytes_to_push,
            ];
            return false;
        }

        $operation = $this->patch_planner->get_operation();
        $this->operation = $this->mapped_remote_index_file === null
            ? null
            : $operation;
        if ($operation !== null) {
            if ($this->mapped_remote_index_file !== null) {
                if ($operation["action"] !== "delete") {
                    while (
                        $this->mapped_remote_index_entry !== null
                        && strcmp($this->mapped_remote_index_entry["path"], $operation["path"]) < 0
                    ) {
                        $this->mapped_remote_index_byte_offset =
                            $this->mapped_remote_index_reader->byte_offset();
                        $this->mapped_remote_index_entry =
                            $this->mapped_remote_index_reader->next_entry();
                    }
                    if (
                        $this->mapped_remote_index_entry !== null
                        && $this->mapped_remote_index_entry["path"] === $operation["path"]
                    ) {
                        $line = json_encode(
                            ["path" => base64_encode($this->mapped_remote_index_entry["remote_absolute_path"])],
                            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                        ) . "\n";
                        if (fwrite($this->fetch_list_handle, $line) !== strlen($line)) {
                            throw new RuntimeException("Short write on mirror fetch list {$this->fetch_list_file}, is the disk full?");
                        }
                        $this->mapped_remote_index_byte_offset =
                            $this->mapped_remote_index_reader->byte_offset();
                        $this->mapped_remote_index_entry =
                            $this->mapped_remote_index_reader->next_entry();
                    }
                }
            } elseif ($operation["action"] !== "copy") {
                $this->append_local_path_to_delete($operation["path"]);
            }
            if ($this->mapped_remote_index_file === null && $operation["action"] !== "delete") {
                $this->append_local_path_to_push($operation);
                if ($local_paths_to_push_count !== null) {
                    ++$local_paths_to_push_count;
                }
                if (
                    $local_file_bytes_to_push !== null
                    && $operation["expected_source"]["type"] === "file"
                ) {
                    $local_file_bytes_to_push +=
                        $operation["expected_source"]["size"];
                }
            }
        }

        $complete = $this->patch_planner->is_complete();
        if ($complete) {
            if (
                ( is_resource($this->local_paths_to_push_handle) && !fflush($this->local_paths_to_push_handle) )
                || ( is_resource($this->local_paths_to_delete_handle) && !fflush($this->local_paths_to_delete_handle) )
                || ( is_resource($this->fetch_list_handle) && !fflush($this->fetch_list_handle) )
            ) {
                throw new RuntimeException("Failed to flush a push-plan output.");
            }
            $this->patch_planner->flush_pending_outputs();
        }
        if ($complete) {
            $this->cursor["position"] = [
                "phase" => "complete",
                "local_paths_to_push_count" => $local_paths_to_push_count,
                "local_file_bytes_to_push" => $local_file_bytes_to_push,
            ];
        } else {
            $position = [
                "phase" => "diffing",
                "file_sync_planner_cursor" =>
                    $this->patch_planner->get_cursor(),
                "byte_offset_in_local_paths_to_push" =>
                    is_resource($this->local_paths_to_push_handle) ? ftell($this->local_paths_to_push_handle) : 0,
                "byte_offset_in_local_paths_to_delete" =>
                    is_resource($this->local_paths_to_delete_handle) ? ftell($this->local_paths_to_delete_handle) : 0,
                "local_paths_to_push_count" => $local_paths_to_push_count,
                "local_file_bytes_to_push" => $local_file_bytes_to_push,
            ];
            if ($this->mapped_remote_index_file !== null) {
                $position["fetch_list_byte_offset"] =
                    ftell($this->fetch_list_handle);
                $position["mapped_remote_index_byte_offset"] =
                    $this->mapped_remote_index_byte_offset;
            }
            $this->cursor["position"] = $position;
        }
        return !$complete;
    }

    /**
     * Closes every plan file handle and prevents further plan steps.
     *
     * The cursor returned to the caller and the plan-owned files remain
     * available to resume the plan or save the completed fresh local index
     * after a successful push.
     */
    public function close(): void
    {
        if (isset($this->fresh_local_index_processor)) {
            $this->fresh_local_index_processor->close();
        }
        if (isset($this->patch_planner)) {
            $this->patch_planner->close();
        }
        if (is_resource($this->local_paths_to_push_handle)) {
            fclose($this->local_paths_to_push_handle);
        }
        if (is_resource($this->local_paths_to_delete_handle)) {
            fclose($this->local_paths_to_delete_handle);
        }
        if (is_resource($this->fetch_list_handle)) {
            fclose($this->fetch_list_handle);
        }
        if (isset($this->mapped_remote_index_reader)) {
            $this->mapped_remote_index_reader->close();
        }
        if (isset($this->next_remote_index_reader)) {
            $this->next_remote_index_reader->close();
        }
        if (is_resource($this->mapped_remote_index_handle)) {
            fclose($this->mapped_remote_index_handle);
        }
        $this->local_paths_to_push_handle = null;
        $this->local_paths_to_delete_handle = null;
        $this->fetch_list_handle = null;
        $this->mapped_remote_index_handle = null;
        $this->closed = true;
    }

    /**
     * Opens one output at its durable cursor offset and discards later bytes.
     *
     * Plan output is flushed before its cursor is stored, so a valid cursor
     * cannot exceed the output length. A process may stop after writing output
     * but before storing its next cursor. Truncating to the saved offset
     * removes only that uncommitted tail before the plan continues.
     *
     * @param string $push_plan_output_file Path to the push-plan output file.
     * @param int    $byte_offset           Durable byte offset at which writing resumes.
     * @return resource Writable output handle positioned at the durable offset.
     */
    private function open_push_plan_output_file_at_byte_offset(
        string $push_plan_output_file,
        int $byte_offset
    )
    {
        $push_plan_output_file_handle = fopen($push_plan_output_file, "c+b");
        if (!$push_plan_output_file_handle) {
            throw new RuntimeException("Failed to open push plan output for writing: {$push_plan_output_file}");
        }
        if (
            !ftruncate($push_plan_output_file_handle, $byte_offset)
            || fseek($push_plan_output_file_handle, $byte_offset) !== 0
        ) {
            fclose($push_plan_output_file_handle);
            throw new RuntimeException(
                "Failed to truncate and seek push plan output "
                . "{$push_plan_output_file} to byte {$byte_offset}."
            );
        }
        return $push_plan_output_file_handle;
    }

    /**
     * Appends one copy or replace operation to the JSONL push list.
     *
     * Base64 keeps arbitrary filesystem path bytes representable in JSON.
     *
     * @param array $operation {
     *     Copy or replace operation selected by FileSyncPatchPlanner.
     *
     *     @type string $action          `copy` or `replace`.
     *     @type string $path            Local relative path selected for push.
     *     @type array  $expected_source {
     *         Source state required when the path is pushed.
     *
     *         @type string $type  Expected `file`, `link`, or `dir` type.
     *         @type int    $size  Expected size.
     *         @type int    $ctime Expected inode change time.
     *     }
     * }
     * @phpstan-param array{action:'copy'|'replace',path:string,expected_source:array{type:string,size:int,ctime:int}} $operation
     */
    private function append_local_path_to_push(array $operation): void {
        $local_path_to_push_json_line = json_encode(
            [
                "path" => base64_encode($operation["path"]),
                "type" => $operation["expected_source"]["type"] === "link"
                    ? "symlink"
                    : ( $operation["expected_source"]["type"] === "dir"
                        ? "directory"
                        : "file" ),
                "size" => $operation["expected_source"]["size"],
                "ctime" => $operation["expected_source"]["ctime"],
            ],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . "\n";
        if (
            fwrite($this->local_paths_to_push_handle, $local_path_to_push_json_line)
            !== strlen($local_path_to_push_json_line)
        ) {
            throw new RuntimeException("Short write on local push path list {$this->local_paths_to_push}, is the disk full?");
        }
    }

    /**
     * Appends one path to the NUL-delimited list of local paths to delete.
     *
     * @param string $path Raw filesystem path selected for deletion.
     */
    private function append_local_path_to_delete(string $path): void
    {
        $document_root_relative_path = relative_path_under(
            $path,
            $this->document_root_local_relative_path
        );
        if ($document_root_relative_path === null) {
            return;
        }
        $document_root_relative_path_with_nul = $document_root_relative_path . "\0";
        if (fwrite($this->local_paths_to_delete_handle, $document_root_relative_path_with_nul) !== strlen($document_root_relative_path_with_nul)) {
            throw new RuntimeException("Short write on local paths to delete {$this->local_paths_to_delete}, is the disk full?");
        }
    }

    /**
     * Loads the caller-owned exclusions used throughout one planning run.
     *
     * @return list<string> Decoded document-root-relative excluded paths.
     */
    private function load_excluded_paths(): array
    {
        $contents = file_get_contents($this->excluded_paths_file);
        if (!is_string($contents)) {
            throw new RuntimeException("Failed to read excluded paths: {$this->excluded_paths_file}");
        }
        try {
            $excluded_paths_b64 = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Failed to decode excluded paths: {$this->excluded_paths_file}", 0, $exception);
        }
        /** @var list<string> $excluded_paths_b64 */
        $excluded_paths = [];
        foreach ($excluded_paths_b64 as $excluded_path_b64) {
            $excluded_path = base64_decode($excluded_path_b64, true);
            if ($excluded_path === false) {
                throw new RuntimeException("Failed to decode an excluded path: {$this->excluded_paths_file}");
            }
            $excluded_paths[] = $excluded_path;
        }
        return $excluded_paths;
    }

}
