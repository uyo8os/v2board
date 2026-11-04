# Webman 用户 IP 修复

**更新日期：** 2026-11-04

**更新范围：** Webman 代理 IP 识别、风控中心 IP 记录及对应测试和部署说明。

## 修复内容

开启 Webman 后，Nginx 会把请求转发到本机 Webman。此前 Laravel 没有信任这个代理，风控中心通过 `$request->ip()` 获取的是连接来源 `127.0.0.1`，导致登录和订阅活动的 IP 记录不正确。

- 仅在 Webman 进程中使用独立的可信代理配置，默认信任 `127.0.0.1` 和 `::1`，从 Nginx 转发的 `X-Forwarded-For` 中解析客户端 IP。
- 普通 PHP-FPM 继续使用原有代理处理及配置，不受 Webman 配置影响。
- 增加 `WEBMAN_TRUSTED_PROXIES`，支持部署者明确指定代理 IP 或网段；空列表不会回退到其他代理配置。
- Webman 只信任配置内代理传来的 IP 和协议头，继续使用 Nginx 直接传入的 `Host`。
- 保留框架对每个请求的代理状态重置，防止常驻进程沿用上一个请求的信任状态。
- 补充安装文档和两种 HTTP 入口的风控 IP 实测。

本次没有修改认证、会话、工单、数据库结构及风控业务规则。

## 部署说明

按照[更新文档](../update.md)更新代码并刷新配置缓存，然后在 Supervisor 中重启 Webman。默认宝塔本机部署无需增加环境变量。

Nginx 需保留[安装文档](../install.md)中的 `X-Forwarded-For` 转发配置。使用 Cloudflare 或其他 CDN 时，继续使用原来让 PHP-FPM 正常获取客户端 IP 的 Nginx 真实 IP 配置；跨机器部署则明确配置实际可信代理。不要将全部来源设为可信代理。

历史 `127.0.0.1` 记录无法从现有数据中自动恢复，修复对之后的新请求生效。

## 验证范围

通过 Docker 中的 Nginx 分别向 PHP-FPM 和 Webman 发送真实登录、订阅请求，直接检查风控中心使用的 `v2_insider_guard_activity`：两种入口均记录实际客户端 IP，且不采信客户端伪造的 `X-Forwarded-For`、`CF-Connecting-IP` 和 `X-Real-IP`。

单元测试覆盖本机代理、IPv6、明确 IP / 网段、多层可信代理、伪造转发链、连续请求、空配置及 PHP-FPM 原行为。完整安装、升级及业务回归结果见 [Docker 验收记录](../../docker/ACCEPTANCE.md)。测试未连接用户的实际 Cloudflare / CDN 站点。
