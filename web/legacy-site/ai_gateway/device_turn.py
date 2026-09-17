"""Per-turn state for the persistent ESP32 voice WebSocket protocol."""
from __future__ import annotations

from dataclasses import dataclass, field
import re


TURN_ID_PATTERN = re.compile(r"[A-Za-z0-9][A-Za-z0-9_.:-]{0,39}")


@dataclass
class DeviceTurnState:
    """Mutable state that must never leak from one voice turn to the next."""

    turn_index: int = 0
    turn_id: str = ""
    legacy: bool = False
    phase: str = "ready"
    active: bool = False
    commit_received: bool = False
    cancel_requested: bool = False
    response_done_received: bool = False
    user_message_persisted: bool = False
    assistant_message_persisted: bool = False
    assistant_pending_transcript: str = ""
    input_packet_count: int = 0
    input_opus_bytes: int = 0
    input_pcm_bytes: int = 0
    output_started: bool = False
    output_end_reported: bool = False
    output_audio_delta_count: int = 0
    output_pcm_received_bytes: int = 0
    output_opus_packet_count: int = 0
    output_opus_bytes: int = 0
    output_pcm: bytearray = field(default_factory=bytearray)
    tool_call_count: int = 0
    tool_followup_pending: bool = False

    def begin(self, turn_id: str, *, legacy: bool = False) -> None:
        self.turn_index += 1
        self.turn_id = turn_id
        self.legacy = legacy
        self.phase = "receiving"
        self.active = True
        self.commit_received = False
        self.cancel_requested = False
        self.response_done_received = False
        self.user_message_persisted = False
        self.assistant_message_persisted = False
        self.assistant_pending_transcript = ""
        self.input_packet_count = 0
        self.input_opus_bytes = 0
        self.input_pcm_bytes = 0
        self.output_started = False
        self.output_end_reported = False
        self.output_audio_delta_count = 0
        self.output_pcm_received_bytes = 0
        self.output_opus_packet_count = 0
        self.output_opus_bytes = 0
        self.output_pcm.clear()
        self.tool_call_count = 0
        self.tool_followup_pending = False

    def commit(self) -> None:
        self.commit_received = True
        self.phase = "committed"

    def responding(self) -> None:
        if self.active:
            self.phase = "responding"

    def finish(self) -> None:
        self.active = False
        self.phase = "ready"
        self.response_done_received = True

    def cancel(self) -> None:
        self.active = False
        self.phase = "ready"
        self.commit_received = False
        self.cancel_requested = False
        self.output_pcm.clear()


def turn_start_error(state: DeviceTurnState, turn_id: str) -> str:
    if state.active:
        return "turn_in_progress"
    if not TURN_ID_PATTERN.fullmatch(turn_id):
        return "invalid_turn_id"
    return ""


def turn_commit_error(state: DeviceTurnState, supplied_turn_id: str = "") -> str:
    if not state.active:
        return "no_active_turn"
    if supplied_turn_id and supplied_turn_id != state.turn_id:
        return "turn_mismatch"
    if state.commit_received:
        return "duplicate_commit"
    if state.phase != "receiving":
        return "turn_not_ready"
    if state.input_packet_count < 1:
        return "no_speech"
    return ""
