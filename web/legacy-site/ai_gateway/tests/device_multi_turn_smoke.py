#!/usr/bin/env python3
"""Run five sequential ESP32-style voice turns over one authenticated WSS.

The one-time device ticket is read from CHIJING_DEVICE_AI_TICKET so it is not
placed in shell history or printed. The WAV must be PCM16, mono, 16 kHz.
"""
from __future__ import annotations

import argparse
import asyncio
import json
import os
import sys
import wave
from pathlib import Path
from urllib.parse import quote

from aiohttp import ClientSession, WSMsgType


sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from opus_codec import OpusEncoder


SAMPLE_RATE = 16000
FRAME_MS = 60
SAMPLES = SAMPLE_RATE * FRAME_MS // 1000
PCM_BYTES = SAMPLES * 2


def load_packets(path: Path) -> list[bytes]:
    with wave.open(str(path), "rb") as source:
        if (
            source.getnchannels() != 1
            or source.getsampwidth() != 2
            or source.getframerate() != SAMPLE_RATE
        ):
            raise ValueError("WAV must be PCM16 mono 16000 Hz")
        pcm = source.readframes(source.getnframes())
    if not pcm:
        raise ValueError("WAV contains no audio")
    encoder = OpusEncoder(SAMPLE_RATE, bitrate=24000)
    packets: list[bytes] = []
    try:
        for offset in range(0, len(pcm), PCM_BYTES):
            frame = pcm[offset:offset + PCM_BYTES]
            if len(frame) < PCM_BYTES:
                frame += b"\0" * (PCM_BYTES - len(frame))
            packets.append(encoder.encode(frame, SAMPLES))
    finally:
        encoder.close()
    return packets


async def receive_json(socket, expected: str) -> dict:
    while True:
        message = await socket.receive(timeout=180)
        if message.type == WSMsgType.TEXT:
            event = json.loads(message.data)
            if event.get("type") == "error":
                raise RuntimeError(f"gateway error: {event.get('code')}")
            if event.get("type") == expected:
                return event
        elif message.type in {WSMsgType.CLOSE, WSMsgType.CLOSED, WSMsgType.ERROR}:
            raise RuntimeError(f"WSS closed while waiting for {expected}")


async def run(url: str, ticket: str, packets: list[bytes], turns: int) -> None:
    separator = "&" if "?" in url else "?"
    target = f"{url}{separator}ticket={quote(ticket, safe='')}"
    async with ClientSession() as session:
        async with session.ws_connect(target, heartbeat=25, max_msg_size=8 * 1024 * 1024) as socket:
            ready = await receive_json(socket, "gateway.ready")
            if ready.get("protocol_version") != 2 or ready.get("connection_mode") != "multi_turn":
                raise RuntimeError("gateway does not advertise persistent WSS protocol v2")

            for index in range(1, turns + 1):
                turn_id = f"smoke-{index}"
                await socket.send_json({"type": "control.turn_start", "turn_id": turn_id})
                turn_ready = await receive_json(socket, "gateway.turn_ready")
                if turn_ready.get("turn_id") != turn_id:
                    raise RuntimeError("gateway.turn_ready turn_id mismatch")
                for packet in packets:
                    await socket.send_bytes(packet)
                await socket.send_json({"type": "control.commit", "turn_id": turn_id})

                counts = {"response.audio.start": 0, "response.audio.end": 0, "response.done": 0}
                binary_packets = 0
                while True:
                    message = await socket.receive(timeout=180)
                    if message.type == WSMsgType.BINARY:
                        binary_packets += 1
                        continue
                    if message.type != WSMsgType.TEXT:
                        raise RuntimeError(f"WSS ended in turn {index}")
                    event = json.loads(message.data)
                    event_type = str(event.get("type") or "")
                    if event_type == "error":
                        raise RuntimeError(f"turn {index} error: {event.get('code')}")
                    if event_type in counts:
                        counts[event_type] += 1
                    if event_type == "gateway.turn_done":
                        if event.get("turn_id") != turn_id or event.get("status") != "ok":
                            raise RuntimeError(f"turn {index} completion mismatch")
                        break
                if counts != {"response.audio.start": 1, "response.audio.end": 1, "response.done": 1}:
                    raise RuntimeError(f"turn {index} event counts invalid: {counts}")
                if binary_packets < 1:
                    raise RuntimeError(f"turn {index} returned no Opus audio")
                print(f"turn {index}/{turns}: ok, output_packets={binary_packets}", flush=True)

            await socket.send_json({"type": "control.close"})


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--url", default="wss://wwwxsh.cn/ai-gateway/device/")
    parser.add_argument("--wav", required=True, type=Path)
    parser.add_argument("--turns", type=int, default=5)
    args = parser.parse_args()
    ticket = os.environ.get("CHIJING_DEVICE_AI_TICKET", "").strip()
    if not ticket:
        raise SystemExit("Set CHIJING_DEVICE_AI_TICKET to a fresh device ticket")
    asyncio.run(run(args.url, ticket, load_packets(args.wav), max(1, min(20, args.turns))))


if __name__ == "__main__":
    main()
