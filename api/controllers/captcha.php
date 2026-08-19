<?php
/**
 * AJ-Captcha 验证码接口（滑动拼图）
 * GET  /api/captcha/get          → 出题：返回图片 URL + token + secretKey
 * GET  /api/captcha/img          → 取图：?token=xxx&t=o|j（分离图片，规避 data URL 大图限制,利于缓存）
 * POST /api/captcha/check        → 校验：token + AES加密的pointJson
 *
 * 返回格式沿用 AJ-Captcha 惯例：{ error, repCode, repData, repMsg, success }
 * 答案坐标 + secretKey 存 Redis(captcha:<token>)，图片存 Redis(captcha:img:<token>)。
 * 前端 pass 让 <img> 走真实 URL 加载，任何浏览器都稳。
 */
require_once __DIR__ . '/../../ajcaptcha/vendor/autoload.php';

use Fastknife\Service\BlockPuzzleCaptchaService;

/** 生成/取题，返回带图片 URL 的 repData */
function captchaIssueData(): array
{
    $config = require __DIR__ . '/../../ajcaptcha/src/config.php';
    $r = redis();
    // 优先从预生成池取（秒回）
    $cached = $r->lpop(rk('captcha:pool'));
    if ($cached) {
        $d = json_decode($cached, true);
        if (!empty($d['token'])
            && $r->exists(rk('captcha:' . $d['token']))          // 答案在
            && $r->exists(rk('captcha:img:' . $d['token']))) {   // 图在
            return $d;                                            // 已是 URL 形式
        }
    }
    // 空池/失效 → 现场生成
    $service = new BlockPuzzleCaptchaService($config);
    $d = $service->get();
    // 图片单独存 Redis，返回 URL 让浏览器加载
    $r->set(rk('captcha:img:' . $d['token']), json_encode([
        'o' => $d['originalImageBase64'],
        'j' => $d['jigsawImageBase64'],
    ]), 600);
    return [
        'token'        => $d['token'],
        'secretKey'    => $d['secretKey'],
        'originalImage' => '/api/captcha/img?token=' . urlencode($d['token']) . '&t=o',
        'jigsawImage'   => '/api/captcha/img?token=' . urlencode($d['token']) . '&t=j',
    ];
}

function captchaGet(): void
{
    try {
        $data = captchaIssueData();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => false, 'repCode' => '0000', 'repData' => $data,
            'repMsg' => null, 'success' => true,
        ]);
    } catch (\Throwable $e) {
        fail(40000, '验证码生成失败：' . $e->getMessage(), 500);
    }
}

/** 取图端点：从 Redis 取出对应 base64 返回 PNG */
function captchaImg(): void
{
    $token = $_GET['token'] ?? '';
    $t     = ($_GET['t'] ?? 'o') === 'j' ? 'j' : 'o';
    if ($token === '') { http_response_code(400); exit; }
    $raw = redis()->get(rk('captcha:img:' . $token));
    $img = $raw ? json_decode($raw, true) : null;
    $b64 = $img[$t] ?? null;
    if (!$b64) { http_response_code(404); exit; }
    $png = base64_decode($b64);
    if ($png === false) { http_response_code(502); exit; }
    header('Content-Type: image/png');
    header('Content-Length: ' . strlen($png));
    // 题目图片一次性，不强缓存（token 绑定，过期即失效）
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo $png;
}

function captchaCheck(): void
{
    $config = require __DIR__ . '/../../ajcaptcha/src/config.php';
    $token = $_REQUEST['token'] ?? '';
    $pointJson = $_REQUEST['pointJson'] ?? '';
    if ($token === '' || $pointJson === '') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => true, 'repCode' => '6111', 'repData' => null, 'repMsg' => '参数错误', 'success' => false]);
        return;
    }
    try {
        $service = new BlockPuzzleCaptchaService($config);
        $service->check($token, $pointJson);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => false, 'repCode' => '0000', 'repData' => null, 'repMsg' => null, 'success' => true]);
    } catch (\Throwable $e) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => true, 'repCode' => '6111', 'repData' => null, 'repMsg' => '验证失败', 'success' => false]);
    }
}
