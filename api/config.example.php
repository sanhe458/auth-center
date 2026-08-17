<?php
/**
 * Auth Center 配置模板（开源用）
 * 复制为 config.php 并按需填写。
 * 敏感配置（OAuth 密钥、易支付、图床等）已迁移到数据库 settings 表，
 * 可在后台「系统设置」中配置，无需在此填写。
 */
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'auth_center');
define('DB_USER', 'auth_center');
define('DB_PASS', 'CHANGE_ME');

define('REDIS_HOST', '127.0.0.1');
define('REDIS_PORT', 6379);
define('REDIS_PREFIX', 'ac:');

define('APP_BASE', 'https://CHANGE_ME.example.com');
define('ACCESS_TOKEN_TTL', 7200);
define('REFRESH_TOKEN_TTL', 2592000);
define('AUTH_CODE_TTL', 600);
