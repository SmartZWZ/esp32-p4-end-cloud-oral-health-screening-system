from __future__ import annotations

import sys
import unittest
from pathlib import Path


sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from device_turn import DeviceTurnState, turn_commit_error, turn_start_error


class DeviceTurnStateTests(unittest.TestCase):
    def test_five_turns_reset_all_per_turn_fields(self) -> None:
        state = DeviceTurnState()
        for index in range(1, 6):
            state.begin(f"turn-{index}")
            self.assertEqual(index, state.turn_index)
            self.assertEqual(f"turn-{index}", state.turn_id)
            self.assertTrue(state.active)
            self.assertEqual("receiving", state.phase)
            self.assertEqual(0, state.input_packet_count)
            self.assertEqual(0, state.output_opus_packet_count)
            self.assertFalse(state.user_message_persisted)
            self.assertFalse(state.assistant_message_persisted)
            self.assertFalse(state.output_end_reported)
            self.assertEqual(0, state.tool_call_count)

            state.input_packet_count = 20 + index
            state.input_opus_bytes = 1000 + index
            state.output_opus_packet_count = 30 + index
            state.output_opus_bytes = 2000 + index
            state.output_pcm.extend(b"residual")
            state.user_message_persisted = True
            state.assistant_message_persisted = True
            state.output_end_reported = True
            state.tool_call_count = 3
            state.commit()
            state.responding()
            state.finish()

            self.assertFalse(state.active)
            self.assertEqual("ready", state.phase)
            self.assertTrue(state.response_done_received)

    def test_legacy_turn_gets_same_isolated_state(self) -> None:
        state = DeviceTurnState()
        state.begin("legacy-1", legacy=True)
        self.assertTrue(state.legacy)
        state.input_packet_count = 3
        state.commit()
        state.finish()

        state.begin("new-turn", legacy=False)
        self.assertFalse(state.legacy)
        self.assertEqual(0, state.input_packet_count)
        self.assertFalse(state.commit_received)
        self.assertFalse(state.response_done_received)

    def test_cancel_returns_connection_to_ready(self) -> None:
        state = DeviceTurnState()
        state.begin("cancel-me")
        state.output_pcm.extend(b"audio")
        state.commit()
        state.cancel_requested = True
        state.cancel()
        self.assertFalse(state.active)
        self.assertFalse(state.cancel_requested)
        self.assertEqual("ready", state.phase)
        self.assertEqual(b"", bytes(state.output_pcm))

    def test_turn_start_validation(self) -> None:
        state = DeviceTurnState()
        self.assertEqual("invalid_turn_id", turn_start_error(state, ""))
        self.assertEqual("invalid_turn_id", turn_start_error(state, "bad id"))
        self.assertEqual("", turn_start_error(state, "turn-01"))
        state.begin("turn-01")
        self.assertEqual("turn_in_progress", turn_start_error(state, "turn-02"))

    def test_commit_validation_and_idempotency(self) -> None:
        state = DeviceTurnState()
        self.assertEqual("no_active_turn", turn_commit_error(state))
        state.begin("turn-01")
        self.assertEqual("no_speech", turn_commit_error(state, "turn-01"))
        state.input_packet_count = 1
        self.assertEqual("turn_mismatch", turn_commit_error(state, "turn-02"))
        self.assertEqual("", turn_commit_error(state, "turn-01"))
        state.commit()
        self.assertEqual("duplicate_commit", turn_commit_error(state, "turn-01"))


if __name__ == "__main__":
    unittest.main()
