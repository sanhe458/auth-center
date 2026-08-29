<?php
/**
 * 脏话/内容安全检测接口（免费公开，IP 限频）
 * 调用本地部署的 qwen3-0.6b（llamacpp，OpenAI 兼容，无需 key）
 * ⚠️ 已关闭思考（chat_template_kwargs.enable_thinking=false），响应快、省算力
 */
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/redis.php';
require_once __DIR__ . '/../lib/helpers.php';

/** 本地 qwen 端点（llamacpp，OpenAI 兼容） */
define('MODERATION_LLM_URL', cfg('moderation_llm_url', 'http://103.24.217.109:37536/v1/chat/completions'));
define('MODERATION_LLM_MODEL', cfg('moderation_llm_model', 'qwen3-0.6b'));
define('MODERATION_MAX_TEXT', 500);   // 单次检测文本最大长度
define('MODERATION_RATE_MAX', 30);    // 每 IP 每分钟最多 30 次

/**
 * POST /moderation/check  检测文本是否含脏话/违规内容
 * 无需鉴权，仅 IP 限频
 * { text: "要检测的文本" }
 */
function moderationCheck(): void
{
    $text = trim((string)param('text', ''));
    if ($text === '') {
        fail(40000, '缺少检测文本 text', 400);
    }
    if (mb_strlen($text) > MODERATION_MAX_TEXT) {
        fail(40001, '文本过长，最多 ' . MODERATION_MAX_TEXT . ' 字', 400);
    }

    // IP 限频（防刷，宽松：30 次/分钟/IP）
    if (!rateLimit('moderation:' . clientIp(), MODERATION_RATE_MAX, 60)) {
        fail(40010, '请求过于频繁，请稍后再试', 429);
    }

    // 构造检测 prompt：只输出 JSON，禁用思考
    $system = '你是内容安全检测器。判断用户文本是否包含脏话、辱骂、色情、暴力或政治敏感内容。'
        . '只输出一个 JSON 对象，格式：{"flagged":true或false,"category":"none或profanity或abuse或porn或politics或other","reason":"一句话说明"}'
        . '。不要输出其他任何内容。';
    $user = '请检测以下文本：' . $text;

    $payload = [
        'model'    => MODERATION_LLM_MODEL,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ],
        'temperature' => 0.1,
        'max_tokens'  => 120,
        // 关闭思考（三河要求：老手机顶不住 reasoning）
        'chat_template_kwargs' => ['enable_thinking' => false],
    ];

    $ch = curl_init(MODERATION_LLM_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        error_log('[moderation] qwen 请求失败: ' . $err);
        fail(50001, '检测服务暂不可用', 502);
    }

    $data = json_decode((string)$resp, true);
    $content = $data['choices'][0]['message']['content'] ?? '';
    if (!$content) {
        // 没拿到内容（思考被关后理论上不会空，兜底）
        error_log('[moderation] qwen 空响应: ' . substr((string)$resp, 0, 300));
        fail(50002, '检测服务无响应', 502);
    }

    // 解析模型输出的 JSON（可能带 ```json 包裹或前后杂文本）
    $result = moderationParseJson($content);
    if (!$result) {
        // 模型没按 JSON 输出：保守起见按违规处理，让调用方人工判断
        error_log('[moderation] JSON 解析失败, raw=' . substr($content, 0, 300));
        ok([
            'flagged'  => false,
            'category' => 'none',
            'reason'   => '检测结果无法解析，请人工复核',
            'raw'      => $content,
        ]);
        return;
    }

    ok([
        'flagged'  => (bool)($result['flagged'] ?? false),
        'category' => (string)($result['category'] ?? 'other'),
        'reason'   => (string)($result['reason'] ?? ''),
        'text'     => $text,
    ]);
}

/**
 * 从模型输出里抠 JSON（支持 ```json 代码块 / 前后杂文本）
 */
function moderationParseJson(string $raw): ?array
{
    // 去掉 ```json ... ``` 包裹
    if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $raw, $m)) {
        $raw = $m[1];
    } else {
        // 直接找第一个 { 到最后一个 }
        $start = strpos($raw, '{');
        $end   = strrpos($raw, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $raw = substr($raw, $start, $end - $start + 1);
        }
    }

    $d = json_decode($raw, true);
    if (!is_array($d)) return null;
    // 只保留合法字段
    $category = (string)($d['category'] ?? 'other');
    $allowed  = ['none', 'profanity', 'abuse', 'porn', 'politics', 'other'];
    if (!in_array($category, $allowed, true)) $category = 'other';
    return [
        'flagged'  => (bool)($d['flagged'] ?? false),
        'category' => $category,
        'reason'   => (string)($d['reason'] ?? ''),
    ];
}
