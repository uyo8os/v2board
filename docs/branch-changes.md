# 分支变更记录

按日期简要记录本分支的变更，详细更新日志放在 [changelog](changelog/) 目录。

## 2026 年 11 月 04 日

修复安装、Composer 依赖安装与更新、数据库升级和运行兼容问题，升级至 Laravel 13 并适配 PHP 8.4，保留原有业务功能。完成 Docker 安装、升级及运行验收，补充宝塔安装和从 `master` 迁移到 `fix` 的更新文档。

本次具体修改见 [更新日志](changelog/2026-11-03-installation-dependencies-upgrade-fixes.md)。

修复 `route:list` 查看路由时触发 HTTP 请求校验而报错的问题，详见 [路由命令修复日志](changelog/2026-11-04-route-list-compatibility-fix.md)。

升级 Stripe、Guzzle、Curl、MessagePack、Sodium、reCAPTCHA、YAML 和 PHPUnit 等依赖，补齐支付及主题异常兼容修复，详见 [依赖升级日志](changelog/2026-11-04-dependency-updates.md)。

修复启用 Webman 后风控中心记录的用户 IP 为 `127.0.0.1` 的问题，保留普通 PHP-FPM 的原有处理，详见 [Webman 用户 IP 修复日志](changelog/2026-11-04-webman-client-ip-fix.md)。

修复安全审计 A01：每次请求重新验证 JWT、有效会话和用户当前权限，删除会话、降权或禁用后旧凭据立即失去相应权限，拒绝将普通缓存键当作登录凭据。详见 [认证与会话授权修复日志](changelog/2026-11-04-auth-session-authorization-fix.md)。

修复安全审计 A03：按上游最小补丁补齐 V2node 用户输出的证书私钥、DNS 凭据和服务端字段过滤，其余 TLS 字段与节点端配置保持原样。详见 [节点敏感字段修复日志](changelog/2026-11-04-node-client-secrets-fix.md)。

## 2026 年 11 月 03 日

新增风控中心和订阅与 App 配置的逐次审计，支持筛选、统计和自动清理；修复风控 UA 字典缺失时未能及时补齐的问题。

本次具体修改见 [更新日志](changelog/2026-11-03-subscription-audit-and-ua-dictionary-fixes.md)。
