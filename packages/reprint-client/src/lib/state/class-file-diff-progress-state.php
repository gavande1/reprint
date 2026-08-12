<?php
declare(strict_types=1);

namespace Reprint\Importer\State;

class FileDiffProgressState {

    /** @var array{old_index_byte_offset:int,new_index_byte_offset:int,preceding_new_index_entry_path_b64:string|null} FileIndexDiffProcessor cursor. */
    public array $index_diff_cursor = [
        'old_index_byte_offset' => 0,
        'new_index_byte_offset' => 0,
        'preceding_new_index_entry_path_b64' => null,
    ];

    /** @var int Confirmed byte offset in the fetch list. */
    public int $fetch_list_byte_offset = 0;

    /** @var int Byte offset in remote paths changed locally. */
    public int $local_drift_remote_paths_byte_offset = 0;

    /** @var int Confirmed byte offset in locally added paths found remotely. */
    public int $matched_added_local_paths_byte_offset = 0;

    public static function from_array(array $data): self
    {
        $state = new self();
        \reprint_assert_state_keys($data, array_keys($state->to_array()), self::class);
        $state->index_diff_cursor = $data['index_diff_cursor'];
        $state->fetch_list_byte_offset = $data['fetch_list_byte_offset'];
        $state->local_drift_remote_paths_byte_offset =
            $data['local_drift_remote_paths_byte_offset'];
        $state->matched_added_local_paths_byte_offset =
            $data['matched_added_local_paths_byte_offset'];
        return $state;
    }

    public function to_array(): array
    {
        return [
            'index_diff_cursor' => $this->index_diff_cursor,
            'fetch_list_byte_offset' => $this->fetch_list_byte_offset,
            'local_drift_remote_paths_byte_offset' =>
                $this->local_drift_remote_paths_byte_offset,
            'matched_added_local_paths_byte_offset' =>
                $this->matched_added_local_paths_byte_offset,
        ];
    }
}
