<?php
/**
 * AJ-Captcha 验证码接口（滑动拼图）
 * GET  /api/captcha/get    → 出题：返回背景图+拼图块+token+secretKey
 * POST /api/captcha/check  → 校验：token + AES加密的pointJson
 *
 * 返回格式沿用 AJ-Captcha 惯例：{ error, repCode, repData, repMsg, success }
 * 答案坐标与 secretKey 存 Redis（capthca/src/RedisCache），前端只拿到加密所需的 secretKey。
 */
require_once __DIR__ . '/../../ajcaptcha/vendor/autoload.php';

use Fastknife\Service\BlockPuzzleCaptchaService;

function captchaGet(): void
{
    $config = require __DIR__ . '/../../ajcaptcha/src/config.php';
    try {
        $service = new BlockPuzzleCaptchaService($config);
        $data = $service->get();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => false,
            'repCode' => '0000',
            'repData' => $data,
            'repMsg' => null,
            'success' => true,
        ]);
    } catch (\Throwable $e) {
        fail(40000, '验证码生成失败：' . $e->getMessage(), 500);
    }
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
