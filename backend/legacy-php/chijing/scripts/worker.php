<?php
declare(strict_types=1);

/**
 * 每次处理一个或多个 queued 任务。宝塔可设置每分钟运行一次：
 * * * * * /usr/bin/php /www/wwwroot/chijing/scripts/worker.php 5 >> /www/wwwroot/chijing/storage/logs/worker.log 2>&1
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}
require_once dirname(__DIR__) . '/app/bootstrap.php';

$limit = min(max((int)($argv[1] ?? 5), 1), 50);
$pdo = database();

function fail_detection(PDO $pdo, int $detectionId, string $code, string $message): void
{
    $stmt = $pdo->prepare("UPDATE detections SET status = 'failed', failure_code = ?, failure_message = ?, completed_at = NOW() WHERE id = ?");
    $stmt->execute([$code, mb_substr($message, 0, 500), $detectionId]);
}

function claim_detection(PDO $pdo): ?array
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->query("SELECT id FROM detections WHERE status = 'queued' ORDER BY id ASC LIMIT 1 FOR UPDATE");
        $row = $stmt->fetch();
        if (!$row) {
            $pdo->commit();
            return null;
        }
        $update = $pdo->prepare("UPDATE detections SET status = 'processing', started_at = NOW() WHERE id = ? AND status = 'queued'");
        $update->execute([(int)$row['id']]);
        $pdo->commit();
        $details = $pdo->prepare("SELECT d.id, d.public_id, d.user_id, di.storage_key FROM detections d INNER JOIN detection_images di ON di.detection_id = d.id AND di.image_kind = 'original' WHERE d.id = ? LIMIT 1");
        $details->execute([(int)$row['id']]);
        return $details->fetch() ?: null;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}

function analyze_image(string $file, string $detectionPublicId): array
{
    $baseUrl = rtrim((string)(env('AI_SERVICE_URL', '') ?? ''), '/');
    if ($baseUrl === '') throw new RuntimeException('AI_SERVICE_URL 未配置。');
    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL 扩展未启用。');
    $curl = curl_init($baseUrl . '/v1/analyze');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['image' => new CURLFile($file, 'image/jpeg', 'oral.jpg'), 'detection_id' => $detectionPublicId],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 90,
    ]);
    $body = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if ($body === false || $status < 200 || $status >= 300) throw new RuntimeException($error !== '' ? $error : "模型服务响应异常（HTTP {$status}）。");
    $data = json_decode($body, true);
    if (!is_array($data) || !is_array($data['result'] ?? null) || !is_array($data['report'] ?? null)) throw new RuntimeException('模型服务返回格式错误。');
    return $data;
}

$handled = 0;
while ($handled < $limit && ($job = claim_detection($pdo))) {
    $handled++;
    $file = storage_path((string)$job['storage_key']);
    if (!is_file($file)) {
        fail_detection($pdo, (int)$job['id'], 'IMAGE_NOT_FOUND', '原始影像文件不存在。');
        continue;
    }
    try {
        $result = analyze_image($file, (string)$job['public_id']);
        $model = is_array($result['model'] ?? null) ? $result['model'] : [];
        $report = $result['report'];
        $report['disclaimer'] = $report['disclaimer'] ?? '本报告仅用于口腔影像辅助筛查，不能替代专业口腔诊断。';
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO model_results (detection_id, model_name, model_version, result_json, quality_score, inference_ms) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([(int)$job['id'], (string)($model['name'] ?? 'unknown'), (string)($model['version'] ?? 'unknown'), json_encode($result['result'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $model['quality_score'] ?? null, $model['inference_ms'] ?? null]);
        $stmt = $pdo->prepare("INSERT INTO ai_reports (detection_id, provider, model_name, prompt_version, report_json, status) VALUES (?, ?, ?, ?, ?, 'completed') ON DUPLICATE KEY UPDATE provider = VALUES(provider), model_name = VALUES(model_name), prompt_version = VALUES(prompt_version), report_json = VALUES(report_json), status = 'completed', failure_message = NULL");
        $stmt->execute([(int)$job['id'], (string)($result['provider'] ?? 'local'), (string)($result['llm_model'] ?? 'not-configured'), (string)($result['prompt_version'] ?? 'v1'), json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        $pdo->prepare("UPDATE detections SET status = 'completed', completed_at = NOW(), failure_code = NULL, failure_message = NULL WHERE id = ?")->execute([(int)$job['id']]);
        $pdo->commit();
        echo "completed {$job['public_id']}\n";
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fail_detection($pdo, (int)$job['id'], 'AI_SERVICE_ERROR', $exception->getMessage());
        fwrite(STDERR, "failed {$job['public_id']}: {$exception->getMessage()}\n");
    }
}
echo "processed {$handled} task(s)\n";
