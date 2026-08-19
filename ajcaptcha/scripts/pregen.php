<?php
/**
 * AJ-Captcha 预生成题目池脚本（跑 cron 定时补货）
 * 用法：php ajcaptcha/scripts/pregen.php
 * 逻辑：若池中题目 < TARGET，则现场生成补到 TARGET 个；
 *       答案(secretKey+point)已由 ->get() 写入 Redis(captcha:<token>)，
 *       返回数据(含 base64 图) json 入 LIST rk('captcha:pool')，get 接口秒回。
 * 说明：生成单题约 0.9-1.2s，后台跑不阻塞用户。
 */
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../../api/lib/redis.php';

use Fastknife\Service\BlockPuzzleCaptchaService;

$TARGET = 8;          // 池目标数量
$MAX    = 14;         // 上限（防脚本异常堆爆）
$config = require __DIR__ . '/../src/config.php';
$r = redis();

$pooled = (int)$r->llen(rk('captcha:pool'));
if ($pooled >= $TARGET) {
    echo "pool已满($pooled/{$TARGET})，跳过\n";
    exit(0);
}

$svc = new BlockPuzzleCaptchaService($config);
$made = 0;
while ((int)$r->llen(rk('captcha:pool')) < $TARGET && (int)$r->llen(rk('captcha:pool')) < $MAX) {
    try {
        $d = $svc->get();               // 答案已存 captcha:<token>
        $r->rpush(rk('captcha:pool'), json_encode($d));
        $made++;
    } catch (\Throwable $e) {
        fwrite(STDERR, "生成失败: " . $e->getMessage() . "\n");
        // 避免死循环
        if ($made === 0) break;
    }
}
echo "本次补充 {$made} 题，池当前 {$r->llen(rk('captcha:pool'))} 题\n";
