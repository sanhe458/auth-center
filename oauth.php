<?php
/**
 * OAuth 授权页兜底：/api/oauth/authorize 已动态生成授权确认页，
 * 直接访问本页时跳转去发起授权。
 */
header('Location: /api/oauth/authorize?' . http_build_query($_GET));
exit;
