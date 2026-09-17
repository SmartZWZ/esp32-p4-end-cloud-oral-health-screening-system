# ESP32-P4 家庭成员双向同步

网页端和 ESP32-P4 均可增、改、删成员；云端是最终数据源。设备应保存本地成员缓存和待同步操作队列，联网后先推送队列，再用服务端返回的完整成员列表覆盖本地缓存。

> 本地“删除”同步到云端后是归档：成员不再出现在网页和设备的可选列表中，但已有图片、检测结果继续保留。

## 1. 本地应保存的数据

每个成员至少保存：

```text
public_id       云端成员 ID；新建前可为空
name            显示名称
relationship    关系
gender          unknown / male / female
birth_date      YYYY-MM-DD 或空
sync_version    云端版本号
```

本地每一次新增、编辑、删除还应写入一个待同步操作：

```text
operation_id    ESP32 生成的 UUID 或随机字符串，重试时必须不变
type            create / update / delete
member_id       云端 public_id；新增时为空
base_version    编辑、删除前看到的 sync_version
```

设备断网时允许修改本地缓存并追加队列；网络恢复后再发送。不要因为重连而丢弃队列。

## 2. 拉取云端成员列表

```http
GET /api/members.php?action=device_list HTTP/1.1
Host: 8.138.230.100:667
X-Device-Token: <NVS 中保存的永久设备令牌>
```

返回的每一项都包含 `sync_version` 和 `updated_source`。设备正常开机、进入成员选择页、一次同步完成后都应拉取或采用同步接口返回的最新列表。

```json
{
  "ok": true,
  "items": [
    {
      "public_id": "202607211530001a2b3c4d5e",
      "name": "小明",
      "relationship": "孩子",
      "gender": "male",
      "birth_date": "2018-05-01",
      "is_default": false,
      "sync_version": 4,
      "updated_source": "web",
      "updated_at": "2026-07-21 15:30:00"
    }
  ]
}
```

## 3. 推送设备端本地修改

统一使用一个批量接口；每次最多 20 个操作：

```http
POST /api/members.php?action=device_sync HTTP/1.1
Host: 8.138.230.100:667
Content-Type: application/json
X-Device-Token: <永久设备令牌>
```

新增成员示例：

```json
{
  "operations": [
    {
      "operation_id": "op_7xN2kR91LmQ4",
      "type": "create",
      "client_member_id": "local_001",
      "name": "小明",
      "relationship": "孩子",
      "gender": "male",
      "birth_date": "2018-05-01"
    }
  ]
}
```

编辑成员示例：

```json
{
  "operations": [
    {
      "operation_id": "op_9kP3sM62QxA8",
      "type": "update",
      "member_id": "202607211530001a2b3c4d5e",
      "base_version": 4,
      "name": "小明",
      "relationship": "孩子",
      "gender": "male",
      "birth_date": "2018-05-01"
    }
  ]
}
```

删除成员示例：

```json
{
  "operations": [
    {
      "operation_id": "op_3fR8vT52NaL6",
      "type": "delete",
      "member_id": "202607211530001a2b3c4d5e",
      "base_version": 4
    }
  ]
}
```

服务端返回：

```json
{
  "ok": true,
  "results": [
    {
      "operation_id": "op_9kP3sM62QxA8",
      "type": "update",
      "status": "applied",
      "member": {
        "public_id": "202607211530001a2b3c4d5e",
        "sync_version": 5
      }
    }
  ],
  "items": ["云端当前全部有效成员"]
}
```

收到 `applied` 后，从队列移除该操作；收到响应中的 `items` 后，用其完整替换本地成员缓存。

## 4. 冲突与重试规则

| 返回状态 | 设备处理 |
|---|---|
| `applied` | 删除该待同步操作，使用返回的完整列表更新缓存。 |
| `conflict` | 网页或另一台设备已修改该成员。保留本地草稿供用户确认，但不能自动覆盖云端；使用云端 `member` 和 `items` 刷新缓存。 |
| `rejected` | 数据无效、成员不存在或试图删除最后一位成员；提示用户并移除或修正操作。 |
| 网络失败 | 不改变队列；使用相同 `operation_id` 重试。 |

`operation_id` 在重试时必须保持不变。服务端会记住同一设备已处理过的操作，避免网络超时导致重复创建成员。

## 5. 上传时指定成员

成员缓存同步完成后，用户在屏幕选择成员；上传 JPEG 时带上：

```http
X-Device-Token: <永久设备令牌>
X-Member-Id: <成员 public_id>
```

若服务端返回 `422`，说明该成员已在云端删除或不再属于当前账户：设备应立即拉取成员列表并要求重新选择。
