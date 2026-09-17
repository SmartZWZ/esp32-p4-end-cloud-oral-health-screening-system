"""Tool definitions and private callback execution for the Chijing AI gateway."""
from __future__ import annotations

import asyncio
import json
import time
from typing import Any

from aiohttp import ClientSession, ClientTimeout


def _device_id_property() -> dict[str, Any]:
    return {
        "type": "string",
        "description": "目标设备的 device_id。只有账号绑定多台设备时才需要填写；设备语音助手不得指定其他设备。",
    }


AI_TOOLS: list[dict[str, Any]] = [
    {
        "type": "function",
        "function": {
            "name": "get_server_time",
            "description": "读取齿镜服务器当前时间。只在用户询问当前时间或验证工具调用时使用。",
            "parameters": {"type": "object", "properties": {}},
        },
    },
    {
        "type": "function",
        "function": {
            "name": "list_family_members",
            "description": "列出当前齿镜账号下仍有效的家庭成员。需要确定用户所说的成员是谁时先调用。",
            "parameters": {"type": "object", "properties": {}},
        },
    },
    {
        "type": "function",
        "function": {
            "name": "get_recent_detections",
            "description": "读取指定家庭成员最近的云端/本地模型分析记录及模型结论。只有用户明确说云端模型分析、模型检测、龋齿模型、牙结石模型或全部模型联合分析时才调用；泛指检查报告、检测报告或口腔情况时不要调用。",
            "parameters": {
                "type": "object",
                "properties": {
                    "member_id": {
                        "type": "string",
                        "description": "优先填写 list_family_members 返回的 member_id；也可填写账号内成员的完整姓名（不区分大小写）。",
                    },
                    "limit": {
                        "type": "integer",
                        "description": "返回条数，1 到 10，默认 5。",
                    },
                },
                "required": ["member_id"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "get_detection_detail",
            "description": "读取一条检测记录的详细模型结果。仅在用户明确询问模型结果时调用，detection_id 必须来自模型检测或图片查询结果。",
            "parameters": {
                "type": "object",
                "properties": {
                    "detection_id": {
                        "type": "string",
                        "description": "检测记录的 detection_id。",
                    }
                },
                "required": ["detection_id"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "get_recent_images",
            "description": "读取指定成员最近上传的图片记录元数据，不返回图片文件本身。用户询问“最近上传的图片”时调用；如只给了成员姓名，可把完整姓名填写到 member_id。",
            "parameters": {
                "type": "object",
                "properties": {
                    "member_id": {
                        "type": "string",
                        "description": "优先填写 list_family_members 返回的 member_id；也可填写账号内成员的完整姓名（不区分大小写）。",
                    },
                    "upload_mode": {
                        "type": "string",
                        "enum": ["all", "archive", "detect"],
                        "description": "all=全部，archive=仅上传保存，detect=上传并检测；默认 all。",
                    },
                    "limit": {
                        "type": "integer",
                        "description": "返回条数，1 到 10，默认 5。",
                    },
                },
                "required": ["member_id"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "analyze_saved_image_with_all_models",
            "description": "仅供网页助手使用：把当前账号已有的一张口腔图片加入“全部模型联合分析”队列，依次运行四个本地模型流水线。必须先通过 get_recent_images 确定 detection_id，只有用户明确要求联合分析时调用。",
            "parameters": {
                "type": "object",
                "properties": {
                    "detection_id": {
                        "type": "string",
                        "description": "get_recent_images 返回的 detection_id。",
                    }
                },
                "required": ["detection_id"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "summarize_detection_history",
            "description": "读取指定成员一段时间内的模型检测统计和最近模型报告。仅在用户明确要求分析模型检测历史或模型结果趋势时调用，不作医学诊断。",
            "parameters": {
                "type": "object",
                "properties": {
                    "member_id": {
                        "type": "string",
                        "description": "优先填写 list_family_members 返回的 member_id；也可填写账号内成员的完整姓名（不区分大小写）。",
                    },
                    "days": {
                        "type": "integer",
                        "description": "统计最近多少天，1 到 365，默认 90。",
                    },
                },
                "required": ["member_id"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "get_recent_family_reports",
            "description": "读取指定成员最近生成的“口腔综合报告”。用户泛指检查报告、检测报告、最近报告或口腔情况时，应优先调用本工具；成员不明确时先调用 list_family_members 并询问用户，不得猜测或使用默认成员。最新报告处理中时，应说明进度并询问是否查看返回的上一份已完成报告，不得直接切换。",
            "parameters": {
                "type": "object",
                "properties": {
                    "member_id": {
                        "type": "string",
                        "description": "必填。成员 member_id 或完整姓名。",
                    },
                    "limit": {"type": "integer", "minimum": 1, "maximum": 10},
                    "include_model_results": {
                        "type": "boolean",
                        "description": "仅当用户明确说云端模型分析、模型检测、龋齿模型、牙结石模型或全部模型联合分析时设为 true；其他情况必须为 false。",
                    },
                },
                "required": ["member_id"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "get_family_report_detail",
            "description": "读取一份“口腔综合报告”的 AI 独立观察汇总和逐图摘要。report_id 必须来自 get_recent_family_reports。普通报告查询不得读取模型附录。",
            "parameters": {
                "type": "object",
                "properties": {
                    "report_id": {"type": "string", "description": "口腔综合报告 report_id。"},
                    "include_model_results": {
                        "type": "boolean",
                        "description": "仅在用户明确要求查看模型结果时设为 true，默认 false。",
                    },
                },
                "required": ["report_id"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "get_recent_ai_dentist_reports",
            "description": "读取指定成员最近的旧版 AI 牙医报告。普通报告查询必须先调用 get_recent_family_reports；只有该成员没有可用的口腔综合报告时，才调用本工具作为第二级回退。不得用它替代已存在的综合报告。",
            "parameters": {
                "type": "object",
                "properties": {
                    "member_id": {
                        "type": "string",
                        "description": "必填。成员 member_id 或完整姓名。",
                    },
                    "limit": {"type": "integer", "minimum": 1, "maximum": 5},
                },
                "required": ["member_id"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "get_devices_status",
            "description": "读取当前账号已绑定设备的在线状态、能力和最近上报的运行状态。",
            "parameters": {"type": "object", "properties": {}},
        },
    },
    {
        "type": "function",
        "function": {
            "name": "select_active_member",
            "description": "让齿镜设备切换当前家庭成员。仅当用户明确要求切换成员时调用；成员不明确时先查询成员列表。",
            "parameters": {
                "type": "object",
                "properties": {
                    "member_id": {"type": "string", "description": "目标家庭成员的 member_id。"},
                    "device_id": _device_id_property(),
                },
                "required": ["member_id"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "capture_and_archive",
            "description": "命令齿镜设备拍摄一张照片并上传保存，不运行模型。仅在用户明确要求拍照或上传保存时调用。",
            "parameters": {
                "type": "object",
                "properties": {
                    "member_id": {"type": "string", "description": "照片所属家庭成员的 member_id。"},
                    "device_id": _device_id_property(),
                },
                "required": ["member_id"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "capture_and_analyze",
            "description": "命令齿镜设备拍照、上传并进入云端分析队列。仅在用户明确要求云端分析时调用。",
            "parameters": {
                "type": "object",
                "properties": {
                    "member_id": {"type": "string", "description": "照片所属家庭成员的 member_id。"},
                    "model_pipeline": {
                        "type": "string",
                        "enum": ["caries", "both", "dental_seg", "calculus_seg"],
                        "description": "分析模型：caries=龋齿候选检测，both=龋齿与修复体综合检测，dental_seg=龋齿/窝洞/裂纹/牙齿四类分割，calculus_seg=牙结石语义分割。",
                    },
                    "device_id": _device_id_property(),
                },
                "required": ["member_id"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "capture_and_local_analyze",
            "description": "命令齿镜设备拍照并在 ESP32-P4 本机运行轻量模型，不上传到云端。仅在用户明确要求本地分析时调用。",
            "parameters": {
                "type": "object",
                "properties": {
                    "member_id": {"type": "string", "description": "本次本地分析对应的家庭成员 member_id。"},
                    "local_model": {
                        "type": "string",
                        "enum": ["dental", "risk"],
                        "description": "设备端模型，默认 dental；risk 为本地风险模型。",
                    },
                    "device_id": _device_id_property(),
                },
                "required": ["member_id"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "set_speaker_volume",
            "description": "设置齿镜设备扬声器音量。仅在用户明确要求调节音量时调用。",
            "parameters": {
                "type": "object",
                "properties": {
                    "level": {"type": "integer", "minimum": 0, "maximum": 100, "description": "目标音量百分比，0 到 100。"},
                    "device_id": _device_id_property(),
                },
                "required": ["level"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "set_screen_brightness",
            "description": "设置齿镜设备屏幕亮度。仅在用户明确要求调节亮度时调用。",
            "parameters": {
                "type": "object",
                "properties": {
                    "level": {"type": "integer", "minimum": 10, "maximum": 100, "description": "目标亮度百分比，10 到 100。"},
                    "device_id": _device_id_property(),
                },
                "required": ["level"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "set_light_power",
            "description": "设置齿镜设备摄像头补光灯的开关状态。仅在用户当前轮明确要求开灯或关灯时调用；“灯怎么了”“调一下灯”“亮一点”等含义不清时必须先询问，不能把补光灯与屏幕亮度混淆，也不能因拍照请求自动开灯。",
            "parameters": {
                "type": "object",
                "properties": {
                    "enabled": {
                        "type": "boolean",
                        "description": "true 表示强制打开摄像头补光灯，false 表示强制关闭。",
                    },
                    "device_id": _device_id_property(),
                },
                "required": ["enabled"],
                "additionalProperties": False,
            },
        },
    },
]

AI_TOOL_NAMES = {item["function"]["name"] for item in AI_TOOLS}


WEB_ONLY_TOOLS = {"analyze_saved_image_with_all_models"}


def tool_definitions(client_kind: str = "web") -> list[dict[str, Any]]:
    if client_kind == "web":
        return AI_TOOLS
    return [
        item for item in AI_TOOLS
        if item["function"]["name"] not in WEB_ONLY_TOOLS
    ]


def _safe_arguments(raw: Any) -> dict[str, Any]:
    if isinstance(raw, dict):
        return raw
    if not isinstance(raw, str) or not raw.strip():
        return {}
    parsed = json.loads(raw)
    if not isinstance(parsed, dict):
        raise ValueError("工具参数必须是 JSON 对象。")
    return parsed


def _bounded_output(value: Any, max_chars: int = 24000) -> str:
    output = json.dumps(value, ensure_ascii=False, separators=(",", ":"))
    if len(output) <= max_chars:
        return output
    return json.dumps(
        {"ok": False, "error": "工具结果过长，已拒绝发送给模型。请缩小查询范围。"},
        ensure_ascii=False,
        separators=(",", ":"),
    )


async def execute_tool(
    session: ClientSession,
    config: dict[str, str],
    ticket_payload: dict[str, Any],
    name: str,
    raw_arguments: Any,
    call_id: str,
) -> str:
    """Execute one allow-listed tool through the private PHP callback."""
    started = time.monotonic()
    if name not in AI_TOOL_NAMES:
        return _bounded_output({"ok": False, "error": "该工具未获授权。"})
    try:
        arguments = _safe_arguments(raw_arguments)
    except (ValueError, json.JSONDecodeError) as error:
        return _bounded_output({"ok": False, "error": f"工具参数格式错误：{error}"})

    callback_url = config.get("AI_GATEWAY_TOOL_CALLBACK_URL", "").strip()
    if not callback_url:
        return _bounded_output({"ok": False, "error": "服务器尚未配置齿镜工具接口。"})

    request_body = {
        "tool": name,
        "arguments": arguments,
        "call_id": str(call_id)[:160],
        "client_kind": str(ticket_payload.get("kind") or "web"),
        "user_ref": str(ticket_payload.get("sub") or ""),
        "device_public_id": str(ticket_payload.get("device_public_id") or ""),
        "conversation_id": str(ticket_payload.get("conversation_id") or ""),
    }
    timeout_seconds = 12 if name == "set_light_power" else 8
    try:
        async with session.post(
            callback_url,
            headers={"X-AI-Gateway-Secret": config["AI_GATEWAY_SECRET"]},
            json=request_body,
            timeout=ClientTimeout(total=timeout_seconds),
        ) as response:
            response_text = await response.text()
            if response.status != 200:
                try:
                    error_payload = json.loads(response_text)
                    error_message = str(error_payload.get("error") or "").strip()
                except (json.JSONDecodeError, AttributeError):
                    error_message = ""
                return _bounded_output(
                    {
                        "ok": False,
                        "error": error_message or "齿镜工具接口调用失败。",
                        "status": response.status,
                    }
                )
            try:
                payload = json.loads(response_text)
            except json.JSONDecodeError:
                return _bounded_output({"ok": False, "error": "齿镜工具接口返回了无效响应。"})
            return _bounded_output(payload)
    except asyncio.TimeoutError:
        return _bounded_output({"ok": False, "error": f"工具调用超过 {timeout_seconds} 秒，请稍后重试。"})
    except Exception as error:
        return _bounded_output(
            {"ok": False, "error": "暂时无法连接齿镜工具接口。", "error_type": type(error).__name__}
        )
    finally:
        elapsed_ms = int((time.monotonic() - started) * 1000)
        print(
            "AI tool finished:"
            f" name={name}"
            f" call_id={str(call_id)[:40]}"
            f" elapsed_ms={elapsed_ms}",
            flush=True,
        )
