# 节点用户输出中的私钥与 DNS 凭据修复

**更新日期：** 2026-11-04

**更新范围：** 安全审计 A03、用户节点列表、订阅的节点配置输入，以及对应隔离回归测试。

## 问题与修复

V2node 的 `remote` 证书模式会把证书私钥存入 `tls_settings.tls_key`。原先用户输出只删除 `private_key`、`ech_key`，所属节点组的普通有效用户可通过 `/api/v1/user/server/fetch` 取得 `tls_key`，以及已配置的 `dns_env` 等服务端字段。问题与 [V3Board 的修复提交](https://github.com/codeman857/v3board/commit/3d47607c9b8e10b74b8a437ccbca8c6bcafdda2d)相同。

业务代码与上述上游补丁一致，仅修改 `ServerService::getAvailableV2node()` 中的 TLS 字段过滤列表一行：

- 原有 `private_key`、`ech_key` 过滤保持，补充过滤 `tls_key`、`dns_env`、`provider`、`cert_file`、`key_file`。
- 其余 TLS 字段原样保留，包括 `cert_mode`、客户端公钥、ECH 配置、公开证书 `tls_cert`、证书指纹及自定义扩展字段。
- 原有 `encryption_settings.private_key` 过滤保持，其余加密配置原样保留。
- 其他协议的配置生成与输出保持原有实现。

用户列表、订阅和 App 控制器都使用上述用户节点服务。当前 App 配置生成器仅支持 Shadowsocks、VMess、Trojan，仍跳过 V2node；本次未新增 App 的 V2node 协议支持。

过滤只作用于 V2node 用户输出，不修改节点模型或数据库。管理端和通过节点认证的 V2 配置接口继续取得原始私钥与 DNS 配置，保证节点能够加载证书和签发配置。

## 部署与历史凭据

按[更新文档](../update.md)同步代码并重启 PHP 服务；启用 Webman 时也重启对应进程，让所有实例使用新代码。此项修复没有新增数据库字段或数据迁移。

如果受影响节点曾向普通用户返回证书私钥，应重新生成证书和私钥、更新客户端证书指纹，并同步到对应节点。若曾下发 DNS 凭据，应在对应 DNS 服务商处撤销并更换凭据，再更新节点配置。代码修复无法收回用户已经下载的旧数据；历史轮换与部署验收需按实际配置记录。

## 验证范围

隔离回归使用合成私钥、DNS 哨兵值、内存 SQLite 和 array 缓存，不读取站点 `.env`、连接真实节点或调用 DNS 服务。检查真实用户认证与节点列表响应中的敏感字段排除、客户端字段保留，以及原始配置和节点端输出的兼容性。

运行命令：

```sh
php -d extension=pdo_sqlite vendor/bin/phpunit --filter V2nodeClientSecurityTest --display-all-issues
php -d extension=pdo_sqlite vendor/bin/phpunit --testsuite Unit --display-all-issues
```

定向回归通过 **7 项测试、81 条断言**，无警告。预载修复前的 `ServerService` 执行同组测试时，用户列表用例因返回私钥和 DNS 凭据而失败，验证了测试能检出原漏洞。完整 Unit 套件通过 **195 项测试、1450 条断言，0 失败、0 错误**；1 项需要 Linux `/proc/meminfo` 的测试跳过，13 个弃用提示来自原有本地 Curl 依赖。

上述结果为 Windows / PHP 8.4.27 下的接口级隔离回归，没有连接真实节点执行证书握手。

本地已安装依赖与锁文件版本的差异仍见[安全审计报告](../security/2026-11-04-full-chain-security-audit.md)；与部署环境一致的验收及历史密钥轮换应单独确认。

返回[分支变更记录](../branch-changes.md)。
