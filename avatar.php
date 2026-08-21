<?php
/**
 * 占位头像生成器：SVG 动态生成
 * 用法：/avatar.php?n=昵称&s=用户ID
 * 按 seed 稳定选色，同一用户永远同一头像
 */
$name = $_GET['n'] ?? '?';
$seed = $_GET['s'] ?? $name;
$size = (int)($_GET['size'] ?? 200);
$size = max(40, min(512, $size));

// 首字母（取第一个可见字符，中文取第一个字）
$char = mb_substr(trim($name), 0, 1, 'UTF-8');
if ($char === '') $char = '?';
$char = htmlspecialchars($char, ENT_QUOTES, 'UTF-8');

// 稳定 hash 选渐变（品牌橙 + 搭配色系）
$h = crc32($seed);
$gradients = [
    ['#ffb74d', '#ff7043'], // 琥珀橙（品牌主色）
    ['#2dd4bf', '#0ea5e9'], // 青碧
    ['#a78bfa', '#8b5cf6'], // 紫
    ['#34d399', '#10b981'], // 翡翠
    ['#f472b6', '#ec4899'], // 粉
    ['#60a5fa', '#3b82f6'], // 蓝
    ['#fbbf24', '#f59e0b'], // 金
    ['#4ade80', '#22c55e'], // 绿
];
[$c1, $c2] = $gradients[$h % count($gradients)];

// 字体大小按尺寸比例
$fontSize = (int)($size * 0.45);

header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: public, max-age=86400'); // 缓存 1 天，同一用户不变
echo <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="$size" height="$size" viewBox="0 0 $size $size">
  <defs>
    <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0%" stop-color="$c1"/>
      <stop offset="100%" stop-color="$c2"/>
    </linearGradient>
  </defs>
  <rect width="$size" height="$size" fill="url(#g)"/>
  <text x="50%" y="50%" dy="0.36em" text-anchor="middle"
        font-family="system-ui, -apple-system, 'PingFang SC', 'Microsoft YaHei', sans-serif"
        font-size="$fontSize" font-weight="700" fill="rgba(255,255,255,0.95)">$char</text>
</svg>
SVG;
