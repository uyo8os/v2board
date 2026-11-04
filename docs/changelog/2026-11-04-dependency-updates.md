# 依赖升级与支付兼容修复

**更新日期：** 2026-11-04

**更新范围：** Composer 依赖及锁文件、Stripe / BEasy 支付兼容、主题异常处理、回归测试和 Docker 验收。

## 依赖版本对照

以下对照本轮升级前后的 `composer.lock`，不代表旧生产服务器实际安装的版本。

| 依赖 | 升级前 | 升级后 |
| --- | --- | --- |
| `laravel/framework` | 13.39.0 | **13.40.0** |
| `stripe/stripe-php` | 14.10.1 | **22.1.0** |
| `guzzlehttp/guzzle` | 7.16.1 | **8.3.1** |
| `php-curl-class/php-curl-class` | 8.10.0 | **13.0.1** |
| `rybakit/msgpack` | 0.9.2 | **0.11.2** |
| `paragonie/sodium_compat` | 1.24.2 | **2.5.2** |
| `google/recaptcha` | 1.5.2 | **2.1.0** |
| `symfony/yaml` | 7.4.22 | **8.1.10** |
| `phpunit/phpunit` | 12.5.43 | **13.4.6** |

Horizon **5.50.1**、Tinker **3.0.2**、Firebase JWT **7.2.1**、Collision **8.9.5** 等依赖已为本轮核对时的最新稳定版，保持当前版本。Adapterman **0.7.1** 和 Workerman **5.2.3** 同样保持。

移除 Linfo **4.0.9**：其最新稳定版仍使用 PHP 8.5 已弃用的反引号语法。项目仅用它读取内存，现改为读取 Linux `/proc/meminfo`，保留 Horizon 原进程数计算公式及 `HORIZON_MAX_PROCESSES` 上限，读取失败时使用 1 个进程。

调整相应 Composer 约束并更新锁文件，继续按锁文件部署。保留 Composer 安全检查、平台要求检查及安全审计，没有忽略安全公告或跳过平台要求。

## PHP 要求

- 最低 PHP 版本由 `^8.4` 调整为 **`^8.4.1`**，适配新版 Symfony YAML 和 PHPUnit 的平台要求。
- 使用 **64 位 PHP**。PHP 8.5 在版本约束内，不要求固定使用 PHP 8.4。
- 同步安装、更新文档中的运行要求，并让 Docker 验收支持 PHP 8.4 / 8.5。

## 实际兼容修复

- 保留 `StripeALL`、`StripeCheckout`、`StripeAlipay`、`StripeCredit`、`StripeWepay` 五个支付渠道。升级 SDK 后显式保留原 **`2024-04-10`** API 版本，避免 SDK 默认 API 版本变化同时改变支付请求行为。Stripe SDK 主版本与默认 API 版本的关系见 [Stripe v22 迁移说明](https://github.com/stripe/stripe-php/wiki/Migration-guide-for-v22)。
- Stripe 回调缺少签名、签名无效或内容无效时返回 **400**；不跳过签名验证。
- Source 回调中的 `metadata` 使用 SDK 的 `toArray()` 转换，适配新版 SDK 参数校验。
- 为 Stripe 支付类及 BEasy 支付类声明配置属性，避免 PHP 动态属性弃用提示；修正支付错误分支中的 `throw new abort(...)` 和异常消息读取方式。
- 主题异常改用 Laravel 内置 `Illuminate\View\ViewException`，恢复原“主题渲染失败”提示，不重新引入已移除的 Ignition。
- 按 [PHP 8.5 弃用说明](https://www.php.net/manual/en/migration85.deprecated.php) 更新 MySQL PDO 常量，替换已无实际作用的 cURL / GD / OpenSSL 清理调用，并移除 `report_memleaks` CLI 配置。
- 补充支付兼容、主题异常及相关依赖回归测试。Guzzle 的主版本变更依据 [官方升级说明](https://github.com/guzzle/guzzle/blob/8.3/UPGRADING.md) 核对。
- 修正 Docker 验收脚本：独立 PHP 脚本发生异常时明确返回失败；升级快照按字段名排序后严格比较值和类型，避免历史 SQL 调整列顺序造成误报。PHPUnit 出现弃用提示或警告时也判定验收失败。

本轮保留原有业务流程，没有进行认证重构、数据库结构改造或支付渠道重写。

## 验证范围与限制

分别在全新的 Docker 项目中使用 **PHP 8.4.27** 和 **PHP 8.5.12**，搭配 Laravel **13.40.0**、Nginx **1.30.6**、MySQL **5.7.44**、Redis **7.4.8** 完成验收：

- 全新安装、Composer 依赖安装 / 更新、平台检查和安全审计均通过；没有安全公告，`composer outdated --direct` 无可更新的直接依赖。
- Git 更新、重复升级及失败路径验证均通过，原用户、余额、佣金、订单、节点、限制记录、`APP_KEY` 和站点配置保留。
- 两个 PHP 版本均通过 **101 项测试、604 条断言**，弃用提示或警告会判为失败。
- Nginx / PHP-FPM、Workerman、Horizon 均正常；登录、JWT、订阅、App 配置、节点接口、验证码、订单续费、流量、邮件日志传输及订阅审计通过，失败队列任务数为 0。
- 两组服务日志未发现 Warning、Deprecated、Fatal 或 Uncaught；两组 Composer 更新后的锁文件均与仓库一致。

支付验证使用离线模拟请求、响应和回调，没有使用真实支付密钥，也没有执行真实支付或退款。

旧 Stripe Sources API 的服务端停用或账户限制，不能通过升级 SDK 或指定旧 API 版本解决。相关渠道仍依赖 Stripe 对现有账户的实际支持，见 [Stripe 官方 Sources API 停用说明](https://support.stripe.com/questions/i-received-a-sources-api-error-how-should-i-handle-it?locale=en-GB)。本轮未将旧 Sources 渠道迁移至 Payment Intents。

详细环境和测试记录见 [Docker 验收记录](../../docker/ACCEPTANCE.md)。

返回 [分支变更记录](../branch-changes.md)。
