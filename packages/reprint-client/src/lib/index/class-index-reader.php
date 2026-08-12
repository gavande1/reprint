<?php

use function WordPress\Reprint\Exporter\assert_valid_path;
use function WordPress\Reprint\Exporter\assert_valid_relative_path;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Index failures are CLI filesystem paths and values, never HTML output.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Importer classes use unprefixed domain names.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Importer classes place braces on the following line.

/**
 * Reads one path-sorted JSONL file index through a retained file handle.
 *
 * The path may be remote absolute or local relative. Its coordinates come
 * from the index being read; this class does not change them. Paths are
 * base64-encoded on disk because Unix path bytes are not necessarily valid
 * UTF-8. For example, this entry describes `/srv/site/wp-content/index.php`:
 *
 *     {"path":"L3Nydi9zaXRlL3dwLWNvbnRlbnQvaW5kZXgucGhw","ctime":1722864000,"size":1234,"type":"file"}
 *
 * next_entry() validates and decodes the path, casts the scalar fields, and
 * returns:
 *
 *     [
 *         "path"  => "/srv/site/wp-content/index.php",
 *         "ctime" => 1722864000,
 *         "size"  => 1234,
 *         "type"  => "file",
 *     ]
 *
 * Extra fields remain in the returned entry. The reader decodes `path` and an
 * optional `remote_absolute_path`, casts `ctime` and `size`, and leaves other
 * values unchanged. This lets the same reader handle remote indexes, local
 * indexes, and mapped next local indexes without another line parser.
 *
 * ## Lifecycle and resume
 *
 * Store the byte offset only after the returned entry has been processed:
 *
 *     $reader = new IndexReader($index_path);
 *     try {
 *         $reader->open();
 *         $reader->seek_to_byte_offset($processed_byte_offset);
 *         while (($entry = $reader->next_entry()) !== null) {
 *             apply_index_entry($entry);
 *             $processed_byte_offset = $reader->byte_offset();
 *             save_processed_byte_offset($processed_byte_offset);
 *         }
 *     } finally {
 *         $reader->close();
 *     }
 *
 * If the process stops inside apply_index_entry(), the stored offset
 * still precedes that entry. A new reader therefore selects it again:
 *
 *     $reader = new IndexReader($index_path);
 *     try {
 *         $reader->open();
 *         $reader->seek_to_byte_offset(load_processed_byte_offset());
 *         $entry = $reader->next_entry(); // The first unprocessed entry.
 *     } finally {
 *         $reader->close();
 *     }
 *
 * A missing file behaves like an empty index, as it does during the first
 * pull:
 *
 *     $reader = new IndexReader($missing_index_path);
 *     try {
 *         $reader->open();
 *         $entry = $reader->next_entry(); // null.
 *         $byte_offset = $reader->byte_offset(); // 0.
 *     } finally {
 *         $reader->close();
 *     }
 *
 * Blank lines are skipped. A malformed non-blank line is consumed before
 * next_entry() throws, so a caller which accepts rejected records can continue
 * with the following line:
 *
 *     $reader = new IndexReader($index_path);
 *     try {
 *         $reader->open();
 *         try {
 *             $reader->next_entry();
 *         } catch (RuntimeException $exception) {
 *             $rejected_line_end = $reader->byte_offset();
 *         }
 *         $following_entry = $reader->next_entry();
 *     } finally {
 *         $reader->close();
 *     }
 *
 * The reader assumes the file is already sorted and never sorts or writes it.
 */
class IndexReader
{
    /** @var string Index file read by this object. */
    private string $index_path;

    /** @var resource|null Open index handle, or null for a missing index. */
    private $index_file_handle = null;

    /**
     * Configures the index path without opening it.
     *
     * @param string $index_path Path to one JSONL file index.
     */
    public function __construct(string $index_path)
    {
        $this->index_path = $index_path;
    }

    /**
     * Opens the index, treating a missing file as an empty index.
     *
     * Repeated calls retain the current handle and byte offset.
     *
     * @throws RuntimeException When the path exists but cannot be opened.
     */
    public function open(): void
    {
        if (is_resource($this->index_file_handle)) {
            return;
        }
        if (!file_exists($this->index_path)) {
            return;
        }
        $index_file_handle = fopen($this->index_path, "r");
        if (!is_resource($index_file_handle)) {
            throw new RuntimeException(
                "Failed to open the index file: {$this->index_path}"
            );
        }
        $this->index_file_handle = $index_file_handle;
    }

    /**
     * Reads and decodes the next index entry, skipping blank lines.
     *
     * A malformed line has already advanced the handle when this method
     * throws. Calling next_entry() again therefore starts at the following
     * line rather than retrying the rejected bytes.
     *
     * @return array|null {
     *     Decoded index entry, or null for a missing index or at EOF.
     *
     *     @type string $path  Decoded path in the index's coordinates.
     *     @type int    $ctime Change time reported by the exporter.
     *     @type int    $size  Size in bytes.
     *     @type string $type  `file`, `dir`, or `link`.
     *     @type string $remote_absolute_path Decoded source path when present.
     * }
     * @throws RuntimeException When a non-blank line is not a decodable index
     *                          entry.
     * @throws InvalidArgumentException When a decoded path is invalid.
     */
    public function next_entry(): ?array
    {
        if (!is_resource($this->index_file_handle)) {
            return null;
        }
        while (true) {
            $index_json_line = fgets($this->index_file_handle);
            if ($index_json_line === false) {
                break;
            }
            $index_entry = $this->parse_index_line($index_json_line);
            if ($index_entry !== null) {
                return $index_entry;
            }
        }
        return null;
    }

    /**
     * Returns the byte offset after the input consumed by next_entry().
     *
     * Returns zero for a missing file or before the first read. Blank and
     * malformed lines consumed by next_entry() count toward the offset.
     *
     * @throws RuntimeException When the open handle cannot report its offset.
     */
    public function byte_offset(): int
    {
        if (!is_resource($this->index_file_handle)) {
            return 0;
        }
        $byte_offset = ftell($this->index_file_handle);
        if ($byte_offset === false) {
            throw new RuntimeException(
                "Failed to read the index byte offset: {$this->index_path}"
            );
        }
        return $byte_offset;
    }

    /**
     * Positions the open index at a previously stored byte offset.
     *
     * Use offsets returned by byte_offset(); an arbitrary offset may point
     * into the middle of a JSONL record. A missing-file reader remains empty
     * and treats the seek as a no-op.
     *
     * @param int $byte_offset Byte offset at the start of the next record.
     * @throws RuntimeException When the open handle cannot seek to the offset.
     */
    public function seek_to_byte_offset(int $byte_offset): void
    {
        if (!is_resource($this->index_file_handle)) {
            return;
        }
        if (fseek($this->index_file_handle, $byte_offset) !== 0) {
            throw new RuntimeException(
                "Failed to seek the index to byte offset {$byte_offset}: {$this->index_path}"
            );
        }
    }

    /**
     * Closes the retained index handle.
     *
     * Repeated calls have no effect.
     */
    public function close(): void
    {
        if (!is_resource($this->index_file_handle)) {
            return;
        }
        fclose($this->index_file_handle);
        $this->index_file_handle = null;
    }

    /**
     * Parses one JSON index line into a validated entry.
     *
     * Missing ctime and size values become zero, and a missing type becomes
     * `file`, preserving the historical index parsing contract.
     *
     * @param string $line One JSONL line from an index file.
     * @return array|null {
     *     Decoded index entry, or null for an empty line.
     *
     *     @type string $path  Decoded path in the index's coordinates.
     *     @type int    $ctime Change time reported by the exporter.
     *     @type int    $size  Size in bytes.
     *     @type string $type  `file`, `dir`, or `link`.
     *     @type string $remote_absolute_path Decoded source path when present.
     * }
     * @throws RuntimeException When the line or base64 path is malformed.
     * @throws InvalidArgumentException When a decoded path is invalid.
     */
    private function parse_index_line(string $line): ?array
    {
        $line = trim($line);
        if ($line === "") {
            return null;
        }
        $data = json_decode($line, true);
        if (!is_array($data)) {
            throw new RuntimeException("Invalid index line format");
        }
        $path_encoded = $data["path"] ?? "";
        if (!is_string($path_encoded) || $path_encoded === "") {
            throw new RuntimeException("Invalid index path");
        }
        $path = base64_decode($path_encoded, true);
        if ($path === "" || $path === false) {
            throw new RuntimeException("Invalid index path (base64 decode failed)");
        }
        if ($path[0] === "/") {
            assert_valid_path($path, "index path");
        } else {
            assert_valid_relative_path($path, "Index path");
        }
        $data["path"] = $path;
        $data["ctime"] = (int) ( $data["ctime"] ?? 0 );
        $data["size"] = (int) ( $data["size"] ?? 0 );
        $data["type"] = (string) ( $data["type"] ?? "file" );
        if (array_key_exists("remote_absolute_path", $data)) {
            if (!is_string($data["remote_absolute_path"])) {
                throw new RuntimeException(
                    "Invalid remote absolute path in index entry"
                );
            }
            $remote_absolute_path = base64_decode(
                $data["remote_absolute_path"],
                true
            );
            if (
                $remote_absolute_path === false
                || $remote_absolute_path === ""
            ) {
                throw new RuntimeException(
                    "Invalid remote absolute path in index entry"
                );
            }
            assert_valid_path(
                $remote_absolute_path,
                "remote absolute path in index entry"
            );
            $data["remote_absolute_path"] = $remote_absolute_path;
        }
        return $data;
    }
}
