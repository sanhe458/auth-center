# 余额支付（易支付兼容收款）

Auth Center 内置一套**易支付（彩虹易支付 V1）兼容的收款能力**。任何已经支持易支付的系统（发卡网、商城、各类对接程序），只要把收款接口地址、商户ID、MD5密钥改成我们的，**无需修改任何代码**即可完成对接。

> 底层逻辑：付款人用 **Auth Center 余额**付款，平台扣付款人余额、给商户入账。全程余额流转、不动真钱，所以必须走我们自研的统一支付页完成付款。

## 一、申请商户

在开发者控制台打开你的应用（如 [SDK演示站](https://auth.sanhe.com.mp/developer/apps.php)），在「易支付收款」区块点 **开通收款商户**，即可获得：

- **商户ID（pid）**：数字，如 `352888`
- **MD5密钥（key）**：32 位字符串，明文可复制

把它填到你的支付程序里即可。

## 二、对接地址

| 用途 | 地址 |
|------|------|
| API 下单 | `https://auth.sanhe.com.mp/mapi.php` |
| 页面跳转下单 | `https://auth.sanhe.com.mp/submit.php` |
| 异步回调接收 | 用你自己站的 `notify_url` |

## 三、下单参数（易支付 V1 协议）

以 `API 下单`（`POST /mapi.php`）为例：

| 参数 | 必填 | 说明 |
|------|------|------|
| `pid` | 是 | 商户ID |
| `type` | 是 | 支付方式，**随意填**（平台会忽略渠道） |
| `out_trade_no` | 是 | 你的商户订单号（唯一） |
| `notify_url` | 是 | 异步回调地址（https） |
| `return_url` | 是 | 同步跳转地址（https） |
| `name` | 是 | 商品名称 |
| `money` | 是 | 金额（元，字符串） |
| `sitename` | 否 | 网站名称 |
| `sign` | 是 | MD5 签名 |
| `sign_type` | 否 | 固定 `MD5` |

### 签名算法

参数按 **ASCII 升序**排序，去掉 `sign` / `sign_type` 和**空值**，拼成 `k=v&k=v...`，末尾拼接商户密钥再取 **小写 MD5**：

```
sign = md5( 按 ASCII 排序并去掉 sign/sign_type/空值后的 "k=v&..." 拼接串 + key )
```

### 下单响应

```json
{
  "code": 200,
  "msg": "下单成功",
  "money": "5.00",
  "type": "alipay",
  "trade_no": "P20260818101852924b69e851",
  "out_trade_no": "你的订单号",
  "payurl": "https://auth.sanhe.com.mp/pay/index.php?order_no=P2026...",
  "qrcode": "https://auth.sanhe.com.mp/pay/index.php?order_no=P2026..."
}
```

- `code=200` 下单成功，否则失败（见 `msg`）
- `payurl` 是**统一支付页地址**，跳转过去让用户用余额付款

## 四、支付流程

```
你的系统                    Auth Center                      付款用户
   │  用 pid+key 调 mapi.php   │                               │
   │──────────────────────────▶│ 校验签名、落单               │
   │◀────── 返回 trade_no/payurl│                               │
   │  跳转到统一支付页          ───────────────────────────────▶│
   │                           │  登录（未登录引导）            │
   │                           │  确认余额付款（扣付款人、入账商户）
   │◀── 异步回调 notify_url ────│  同步跳转 return_url ─────────▶│
   │  验签、处理业务            │                               │
```

## 五、异步回调（notify_url）

付款成功后，平台向你的 `notify_url` 发送标准易支付异步通知：

| 参数 | 说明 |
|------|------|
| `pid` | 商户ID |
| `trade_no` | 平台订单号 |
| `out_trade_no` | 你的商户订单号 |
| `type` | 支付方式 |
| `name` | 商品名称 |
| `money` | 金额（元） |
| `trade_status` | 固定 `TRADE_SUCCESS` |
| `sign` | MD5 签名（用你的 key 验） |

**处理要点：**
1. 用商户 MD5 密钥验签，失败返回 `fail`
2. 校验 `money` 与订单金额一致
3. `trade_status=TRADE_SUCCESS` 才处理业务
4. 按 `out_trade_no` **幂等**处理（通知可能重复发送）
5. 处理成功返回纯文本 `success`，否则平台会重试

## 六、示例代码

完整可跑示例见 SDK：[sdk/php/examples/pay.php](https://auth.sanhe.com.mp/sdk/php/examples/pay.php)（下单）和 [pay_notify.php](https://auth.sanhe.com.mp/sdk/php/examples/pay_notify.php)（回调验签）。

在线体验：[演示站 → 余额支付演示](https://demo.sanhe.com.mp/pay.php)
