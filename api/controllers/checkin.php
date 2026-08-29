<?php
/**
 * 签到接口：每日签到，7 天循环奖励
 * 奖励规则：连续 1→10, 2→12, 3→14, 4→16, 5→18, 6→20, 7+→30
 * 漏一天连续重置，第 8 天起重新从 10 开始
 */
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../controllers/points.php';

/**
 * 7 天循环奖励表
 */
function checkinReward(int $streak): int
{
    $map = [1 => 10, 2 => 12, 3 => 14, 4 => 16, 5 => 18, 6 => 20, 7 => 30];
    // 超过 7 天回到第一天
    $day = (($streak - 1) % 7) + 1;
    return $map[$day];
}

/**
 * POST /checkin  签到
 * 每天一次，唯一索引防重复
 */
function checkinDo(): void
{
    session_start();
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) fail(41007, '未登录', 401);

    // 限频：每用户 5 秒 1 次
    if (!rateLimit('checkin:' . $userId, 1, 5)) {
        fail(40010, '操作过于频繁', 429);
    }

    $today = date('Y-m-d');
    $db = db();
    $db->beginTransaction();
    try {
        // 查上次签到记录
        $st = $db->prepare('SELECT checkin_date, streak FROM checkins WHERE user_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $st->execute([$userId]);
        $last = $st->fetch();

        // 计算连续天数
        $streak = 1;
        if ($last) {
            $lastDate = $last['checkin_date'];
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            if ($lastDate === $yesterday) {
                // 连续：昨天签到了
                $streak = (int)$last['streak'] + 1;
            } elseif ($lastDate === $today) {
                // 今天已经签到了
                $db->rollBack();
                fail(42020, '今天已签到', 400);
            }
            // 其他情况：漏签了，连续重置为 1
        }

        // 计算奖励积分
        $award = checkinReward($streak);

        // 插入签到记录（唯一索引兜底防并发）
        $st = $db->prepare('INSERT INTO checkins (user_id, checkin_date, streak, points) VALUES (?,?,?,?)');
        $st->execute([$userId, $today, $streak, $award]);

        // 加积分
        $pointsAfter = pointsChangeInTxn($userId, 'reward', $award, 'checkin:' . $today, '每日签到');

        $db->commit();

        // 明天奖励预告
        $nextStreak = $streak + 1;
        $nextAward = checkinReward($nextStreak);

        ok([
            'streak'       => $streak,
            'award'        => $award,
            'points_after' => $pointsAfter,
            'next_award'   => $nextAward,
            'today'        => $today,
        ]);
    } catch (Throwable $e) {
        $db->rollBack();
        // 唯一索引冲突 → 今天已签到
        if (str_contains($e->getMessage() ?? '', 'Duplicate entry')) {
            fail(42020, '今天已签到', 400);
        }
        // 积分不足（理论上不会发生）
        if (str_contains($e->getMessage() ?? '', '积分不足')) {
            fail(42021, '签到失败：积分不足', 500);
        }
        error_log('[checkin] ' . $e->getMessage());
        fail(50000, '签到失败', 500);
    }
}

/**
 * GET /checkin/status  签到状态
 * 返回：今日是否已签、连续天数、本月签到列表、当前连续奖励
 */
function checkinStatus(): void
{
    session_start();
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) fail(41007, '未登录', 401);

    $today = date('Y-m-d');
    $db = db();

    // 今日是否已签
    $st = $db->prepare('SELECT streak, points FROM checkins WHERE user_id = ? AND checkin_date = ? LIMIT 1');
    $st->execute([$userId, $today]);
    $todayRow = $st->fetch();

    // 本月签到日期列表
    $monthStart = date('Y-m-01');
    $monthEnd = date('Y-m-t');
    $st = $db->prepare('SELECT checkin_date, points FROM checkins WHERE user_id = ? AND checkin_date >= ? AND checkin_date <= ? ORDER BY checkin_date');
    $st->execute([$userId, $monthStart, $monthEnd]);
    $monthCheckins = $st->fetchAll();

    // 当前连续天数（今天未签时查最新一次）
    $streak = 0;
    $nextAward = 10;
    if ($todayRow) {
        // 已签
        $streak = (int)$todayRow['streak'];
        $nextStreak = $streak + 1;
        $nextAward = checkinReward($nextStreak);
    } else {
        // 未签，查上次签到
        $st = $db->prepare('SELECT checkin_date, streak FROM checkins WHERE user_id = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$userId]);
        $last = $st->fetch();
        if ($last) {
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            if ($last['checkin_date'] === $yesterday) {
                $streak = (int)$last['streak'] + 1;
            } else {
                $streak = 1; // 断签重置
            }
        } else {
            $streak = 1; // 从未签到
        }
        $nextAward = checkinReward($streak);
    }

    ok([
        'checked_in'     => (bool)$todayRow,
        'streak'         => $streak,
        'next_award'     => $nextAward,
        'month_checkins' => $monthCheckins,
        'today'          => $today,
    ]);
}