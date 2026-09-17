"""Small ctypes wrapper for the system libopus runtime.

The cloud provider uses PCM, while ESP32-P4 transports one raw Opus packet in
each WebSocket binary frame.  Keeping this wrapper dependency-free avoids a
native Python build on the small production server; Ubuntu only needs libopus0.
"""
from __future__ import annotations

import ctypes
import ctypes.util


OPUS_OK = 0
OPUS_APPLICATION_VOIP = 2048
OPUS_SET_BITRATE_REQUEST = 4002
OPUS_SET_VBR_REQUEST = 4006
OPUS_RESET_STATE = 4028


class OpusCodecError(RuntimeError):
    pass


def _load() -> ctypes.CDLL:
    name = ctypes.util.find_library("opus") or "libopus.so.0"
    try:
        return ctypes.CDLL(name)
    except OSError as error:
        raise OpusCodecError("libopus is unavailable; install the Ubuntu package libopus0") from error


LIB = _load()
LIB.opus_strerror.argtypes = [ctypes.c_int]
LIB.opus_strerror.restype = ctypes.c_char_p
LIB.opus_decoder_create.argtypes = [ctypes.c_int32, ctypes.c_int, ctypes.POINTER(ctypes.c_int)]
LIB.opus_decoder_create.restype = ctypes.c_void_p
LIB.opus_decoder_destroy.argtypes = [ctypes.c_void_p]
LIB.opus_decoder_destroy.restype = None
LIB.opus_decode.argtypes = [ctypes.c_void_p, ctypes.POINTER(ctypes.c_ubyte), ctypes.c_int32, ctypes.POINTER(ctypes.c_int16), ctypes.c_int, ctypes.c_int]
LIB.opus_decode.restype = ctypes.c_int
# opus_*_ctl are variadic C functions.  Declaring their fixed arguments is
# essential on 64-bit systems; otherwise ctypes may coerce the pointer-sized
# handle to a 32-bit int and terminate the gateway process inside libopus.
LIB.opus_decoder_ctl.argtypes = [ctypes.c_void_p, ctypes.c_int]
LIB.opus_decoder_ctl.restype = ctypes.c_int
LIB.opus_encoder_create.argtypes = [ctypes.c_int32, ctypes.c_int, ctypes.c_int, ctypes.POINTER(ctypes.c_int)]
LIB.opus_encoder_create.restype = ctypes.c_void_p
LIB.opus_encoder_destroy.argtypes = [ctypes.c_void_p]
LIB.opus_encoder_destroy.restype = None
LIB.opus_encoder_ctl.argtypes = [ctypes.c_void_p, ctypes.c_int]
LIB.opus_encoder_ctl.restype = ctypes.c_int
LIB.opus_encode.argtypes = [ctypes.c_void_p, ctypes.POINTER(ctypes.c_int16), ctypes.c_int, ctypes.POINTER(ctypes.c_ubyte), ctypes.c_int32]
LIB.opus_encode.restype = ctypes.c_int


def _error(code: int) -> OpusCodecError:
    text = LIB.opus_strerror(code)
    return OpusCodecError((text or b"unknown opus error").decode("utf-8", "replace"))


class OpusDecoder:
    def __init__(self, sample_rate: int, channels: int = 1):
        error = ctypes.c_int()
        self._handle = LIB.opus_decoder_create(sample_rate, channels, ctypes.byref(error))
        self.sample_rate = sample_rate
        self.channels = channels
        if not self._handle or error.value != OPUS_OK:
            raise _error(error.value)

    def decode(self, packet: bytes, maximum_samples_per_channel: int = 1920) -> bytes:
        if not packet or len(packet) > 1275:
            raise OpusCodecError("invalid raw Opus packet length")
        source = (ctypes.c_ubyte * len(packet)).from_buffer_copy(packet)
        output = (ctypes.c_int16 * (maximum_samples_per_channel * self.channels))()
        decoded = LIB.opus_decode(self._handle, source, len(packet), output, maximum_samples_per_channel, 0)
        if decoded < 0:
            raise _error(decoded)
        return ctypes.string_at(output, decoded * self.channels * ctypes.sizeof(ctypes.c_int16))

    def reset(self) -> None:
        result = LIB.opus_decoder_ctl(self._handle, OPUS_RESET_STATE)
        if result != OPUS_OK:
            raise _error(result)

    def close(self) -> None:
        if self._handle:
            LIB.opus_decoder_destroy(self._handle)
            self._handle = None


class OpusEncoder:
    def __init__(self, sample_rate: int, channels: int = 1, bitrate: int = 24000):
        error = ctypes.c_int()
        self._handle = LIB.opus_encoder_create(sample_rate, channels, OPUS_APPLICATION_VOIP, ctypes.byref(error))
        self.sample_rate = sample_rate
        self.channels = channels
        if not self._handle or error.value != OPUS_OK:
            raise _error(error.value)
        self._ctl(OPUS_SET_BITRATE_REQUEST, bitrate)
        self._ctl(OPUS_SET_VBR_REQUEST, 1)

    def _ctl(self, request: int, value: int) -> None:
        result = LIB.opus_encoder_ctl(self._handle, request, ctypes.c_int(value))
        if result != OPUS_OK:
            raise _error(result)

    def encode(self, pcm_s16le: bytes, samples_per_channel: int) -> bytes:
        expected = samples_per_channel * self.channels * ctypes.sizeof(ctypes.c_int16)
        if len(pcm_s16le) != expected:
            raise OpusCodecError("PCM frame length does not match Opus frame duration")
        source = (ctypes.c_int16 * (samples_per_channel * self.channels)).from_buffer_copy(pcm_s16le)
        output = (ctypes.c_ubyte * 1275)()
        encoded = LIB.opus_encode(self._handle, source, samples_per_channel, output, len(output))
        if encoded < 0:
            raise _error(encoded)
        return bytes(output[:encoded])

    def reset(self) -> None:
        """Clear inter-frame codec history before starting another voice turn."""
        result = LIB.opus_encoder_ctl(self._handle, OPUS_RESET_STATE)
        if result != OPUS_OK:
            raise _error(result)

    def close(self) -> None:
        if self._handle:
            LIB.opus_encoder_destroy(self._handle)
            self._handle = None
