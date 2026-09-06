<?php
/**
 * OIDC 支持（2026-09-06 新增）
 * ----------------------------------------------------------------
 * 让 Auth Center 对外提供标准 OIDC 服务，供 OpenList / Grafana /
 * Nextcloud 等任意支持 OIDC 的客户端接入：
 *
 *   GET /.well-known/openid-configuration   → OIDC Discovery
 *   GET /.well-known/jwks.json              → JWKS（RS256 公钥）
 *   POST /api/oauth/token                   → 标准响应 + id_token
 *   GET  /api/oauth/userinfo                → Bearer access_token 换用户信息
 *
 * RSA 密钥对懒加载：首次请求时生成，持久化到 settings 表
 * （oidc_private_key / oidc_public_key / oidc_kid），零第三方依赖，
 * 签名走 PHP openssl 扩展（RS256）。
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/redis.php';
require_once __DIR__ . '/helpers.php';

/** 确保 RSA 密钥对存在（懒加载），返回 [私钥PEM, 公钥PEM, kid] */
function oidcEnsureKeys(): array
{
    $priv = cfg('oidc_private_key');
    if ($priv) {
        return [$priv, cfg('oidc_public_key'), cfg('oidc_kid', 'oidc-rs256')];
    }

    $res = openssl_pkey_new([
        'digest_alg'       => 'sha256',
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    if (!$res) {
        fail(50001, 'OIDC RSA 密钥生成失败', 500);
    }
    openssl_pkey_export($res, $privPem);
    $details = openssl_pkey_get_details($res);
    $pubPem  = $details['key'];
    $kid     = 'oidc-' . bin2hex(random_bytes(4));

    $db = db();
    $st = $db->prepare('INSERT INTO settings (skey, svalue, sdesc) VALUES (?,?,?)
                        ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
    $st->execute(['oidc_private_key', $privPem, 'OIDC 私钥（RS256，签发 id_token）']);
    $st->execute(['oidc_public_key',  $pubPem,  'OIDC 公钥（JWKS 对外发布）']);
    $st->execute(['oidc_kid',         $kid,     'OIDC 密钥 ID（JWKS kid）']);
    cfgClear();

    return [$privPem, $pubPem, $kid];
}

/** base64url 编码（JWT / JWKS 用） */
function oidcB64(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

/** 签发 RS256 JWT */
function oidcSignJwt(array $claims): string
{
    [$privPem, , $kid] = oidcEnsureKeys();
    $header = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $kid];
    $data = oidcB64(json_encode($header, JSON_UNESCAPED_SLASHES)) . '.' .
            oidcB64(json_encode($claims, JSON_UNESCAPED_SLASHES));
    if (!openssl_sign($data, $sig, $privPem, OPENSSL_ALGO_SHA256)) {
        fail(50002, 'id_token 签名失败', 500);
    }
    return $data . '.' . oidcB64($sig);
}

/** 统一 id_token 的 claims 构造（issueTokens 与 userinfo 共用） */
function oidcClaims(array $u, string $aud, int $now, int $ttl): array
{
    $nickname = $u['nickname'] !== '' ? $u['nickname'] : $u['uid'];
    return [
        'iss'               => APP_BASE,
        'sub'               => $u['uid'],
        'aud'               => $aud,
        'exp'               => $now + $ttl,
        'iat'               => $now,
        'auth_time'         => $now,
        'email'             => $u['email'],
        'email_verified'    => true,
        'preferred_username'=> $nickname,
        'name'              => $nickname,
        'nickname'          => $nickname,
    ];
}

/** GET /.well-known/openid-configuration */
function oidcDiscovery(): void
{
    $base = APP_BASE;
    jsonOut([
        'issuer'                                => $base,
        'authorization_endpoint'                => $base . '/api/oauth/authorize',
        'token_endpoint'                        => $base . '/api/oauth/token',
        'userinfo_endpoint'                     => $base . '/api/oauth/userinfo',
        'jwks_uri'                              => $base . '/.well-known/jwks.json',
        'response_types_supported'              => ['code'],
        'response_modes_supported'              => ['query'],
        'subject_types_supported'               => ['public'],
        'id_token_signing_alg_values_supported' => ['RS256'],
        'scopes_supported'                      => ['openid', 'profile', 'email', 'basic', 'notify'],
        'grant_types_supported'                 => ['authorization_code', 'refresh_token'],
        'token_endpoint_auth_methods_supported' => ['client_secret_post'],
        'claims_supported'                      => ['sub', 'iss', 'aud', 'exp', 'iat', 'auth_time',
                                                     'email', 'email_verified', 'preferred_username',
                                                     'name', 'nickname'],
    ]);
}

/** GET /.well-known/jwks.json */
function oidcJwks(): void
{
    [, $pubPem, $kid] = oidcEnsureKeys();
    $pub = openssl_pkey_get_public($pubPem);
    if (!$pub) {
        fail(50003, 'OIDC 公钥解析失败', 500);
    }
    $d = openssl_pkey_get_details($pub);
    jsonOut(['keys' => [[
        'kty' => 'RSA',
        'use' => 'sig',
        'alg' => 'RS256',
        'kid' => $kid,
        'n'   => oidcB64($d['rsa']['n']),
        'e'   => oidcB64($d['rsa']['e']),
    ]]]);
}
