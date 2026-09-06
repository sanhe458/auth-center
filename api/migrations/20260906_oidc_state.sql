-- 2026-09-06 OIDC 支持配套：oauth_codes.state 扩容
-- OpenList 绑定流程（get_sso_id）的 state 是 HS256 JWT（约 400+ 字符），
-- 原 VARCHAR(128) 会触发 SQLSTATE[22001] Data too long（1406）。
ALTER TABLE `oauth_codes`
    MODIFY `state` VARCHAR(1024) DEFAULT NULL COMMENT 'OAuth state（OIDC 可为 JWT，最长 1024）';
