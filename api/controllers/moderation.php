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
 * POST/GET /moderation/check  检测文本是否含违规内容
 * 无需鉴权，仅 IP 限频
 * { text: "要检测的文本", mode?: "lenient"|"strict" }
 *   lenient（默认）：只拦性和恐怖内容，普通脏话不拦
 *   strict：性和恐怖 + 脏话辱骂都拦
 * 省 token：通过时只回 {"flagged":false}，不输出理由（老手机 Token 慢）
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

    // 模式：lenient（宽松，默认） / strict（严格）
    $mode = (string)param('mode', 'lenient');
    if (!in_array($mode, ['lenient', 'strict'], true)) {
        $mode = 'lenient';
    }

    // IP 限频（防刷，宽松：30 次/分钟/IP）
    if (!rateLimit('moderation:' . clientIp(), MODERATION_RATE_MAX, 60)) {
        fail(40010, '请求过于频繁，请稍后再试', 429);
    }

    // ① 关键词表先行：命中直接返回（快、准、不调模型）；宽松模式跳过脏话
    $kw = moderationKeywordCheck($text, $mode);
    if ($kw) {
        $kw['mode'] = $mode;
        $kw['text'] = $text;
        ok($kw);
        return;
    }

    // ② 关键词未命中 → 调模型单标签分类兜底

    // 构造检测 prompt：0.6b 学不会 JSON 输出，改成只输出单个类别词（最快最稳）
    // 输出：porn / horror / profanity / pass，后端映射到模式规则并生成 reason
    $modeNote = $mode === 'strict'
        ? '脏话(profanity)也算违规'
        : '脏话不算违规，不要报 profanity';
    $system = '你是内容安全检测器。判断文本类别，只输出一个英文词，不要输出其他任何内容：\n'
        . '- 性内容(色情、性行为)→porn\n'
        . '- 恐怖内容(杀人、血腥、鬼、灵异)→horror\n'
        . '- 脏话辱骂→profanity\n'
        . '- 无违规→pass\n'
        . '注意：' . $modeNote;
    $user = '文本：' . $text;
    $payload = [
        'model'    => MODERATION_LLM_MODEL,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ],
        'temperature' => 0.1,
        'max_tokens'  => 16,
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

    // 模型只输出一个类别词，提取并映射
    $label = moderationExtractLabel($content);
    $result = moderationLabelToResult($label, $mode);
    if (!$result) {
        // 模型输出了无法识别的词：保守放行 + 标记人工复核（不输出 reason）
        error_log('[moderation] 无法识别输出, raw=' . substr($content, 0, 200));
        ok([
            'flagged'  => false,
            'category' => 'none',
            'mode'     => $mode,
            'text'     => $text,
        ]);
        return;
    }

    $out = [
        'flagged'  => $result['flagged'],
        'category' => $result['category'],
        'mode'     => $mode,
        'text'     => $text,
    ];
    // 通过时不带 reason，只有拦截时才输出理由（省 token）
    if ($result['flagged']) {
        $out['reason'] = $result['reason'];
    }
    ok($out);
}

/**
 * 关键词表（本地命中，不调模型）：确定性违禁词直接拦截
 * 返回 null 表示未命中，需交给模型
 */
function moderationKeywordCheck(string $text, string $mode): ?array
{
    $porn = ['做爱', '色情', '性交', '性爱', '裸体', '自慰', '手淫', '鸡巴', '阴道', '阴茎', '卖淫', '嫖娼', '荡妇', '约炮', '三级片'];
    $horror = ['杀人', '血腥', '尸体', '鬼', '灵异', '凶杀', '肢解', '碎尸', '割喉', '上吊', '灭门', '食人'];
    $profanity = ['傻逼', '妈逼', '操你妈', '操你', '日你', '妈的', '混蛋', '王八蛋', '贱人', '卧槽', '去死', '草泥马', '狗日的', '婊子'];

    $hit = null;
    foreach ($porn as $w) { if (str_contains($text, $w)) { $hit = 'porn'; break; } }
    if (!$hit) { foreach ($horror as $w) { if (str_contains($text, $w)) { $hit = 'horror'; break; } } }
    // 脏话仅在严格模式下算违规；宽松模式普通脏话放行
    if (!$hit && $mode === 'strict') { foreach ($profanity as $w) { if (str_contains($text, $w)) { $hit = 'profanity'; break; } } }

    if (!$hit) return null;
    $reasonMap = [
        'porn'      => '包含色情内容',
        'horror'    => '包含恐怖暴力内容',
        'profanity' => '包含脏话辱骂',
    ];
    return ['flagged' => true, 'category' => $hit, 'reason' => $reasonMap[$hit]];
}

/**
 * 从模型输出里提取类别标签（容忍换行/空格/小写/中文兜底）
 */
function moderationExtractLabel(string $content): string
{
    $content = strtolower(trim($content));
    // 取第一个词/行
    if (preg_match('/\b(porn|horror|profanity|pass)\b/', $content, $m)) {
        return $m[1];
    }
    // 中文兜底
    $zh = ['色情' => 'porn', '性' => 'porn', '恐怖' => 'horror', '血腥' => 'horror', '脏话' => 'profanity', '辱骂' => 'profanity', '正常' => 'pass', '通过' => 'pass', '无' => 'pass'];
    foreach ($zh as $k => $v) {
        if (str_contains($content, $k)) return $v;
    }
    return '';
}

/**
 * 标签 + 模式 → 检测结果；宽松模式下 profanity 不算违规
 */
function moderationLabelToResult(string $label, string $mode): ?array
{
    $reasonMap = [
        'porn'      => '包含色情内容',
        'horror'    => '包含恐怖暴力内容',
        'profanity' => '包含脏话辱骂',
    ];
    if ($label === 'pass') {
        return ['flagged' => false, 'category' => 'none', 'reason' => ''];
    }
    if ($label === 'profanity' && $mode === 'lenient') {
        // 宽松模式：普通脏话放行
        return ['flagged' => false, 'category' => 'none', 'reason' => ''];
    }
    if (isset($reasonMap[$label])) {
        return ['flagged' => true, 'category' => $label, 'reason' => $reasonMap[$label]];
    }
    return null;
}
