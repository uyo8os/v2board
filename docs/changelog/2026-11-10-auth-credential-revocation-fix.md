# 认证凭据撤销与快捷登录修复

**更新日期：** 2026-11-10

**更新范围：** 安全审计 A02，用户密码及权限变更、JWT 会话认证、快捷登录码、数据库安装与更新及隔离回归。

## 问题与修复

原后台改密只保存新密码哈希，旧登录会话仍可继续使用；找回密码虽清理会话，但重置前签发的快捷码只记录用户 ID，在其 60 秒有效期内仍能建立新会话。仅清理会话目录也不能把所有并发签发与凭据变更统一到同一个失效条件。

- `v2_user` 新增 `auth_version char(32) NOT NULL DEFAULT ''`，安装 SQL 和更新 SQL 同步补齐。用户模型在 `performUpdate()` 中检测 `password`、`password_algo`、`password_salt`、`banned`、`is_admin`、`is_staff` 的变化，生成 16 字节随机值的十六进制版本，与敏感字段通过同一条数据库更新语句保存。绕过模型保存的批量禁用路径显式轮换版本。
- 会话元数据保存签发时的认证版本；每次请求从主库读取当前用户状态、角色及认证版本，版本不一致即拒绝。会话目录仍使用原有用户级缓存锁。即使旧会话未能从缓存物理删除，或并发写回旧元数据，也不能绕过数据库中的最新版本。
- 两个快捷码签发入口统一使用认证服务，码内容绑定用户 ID 和当前认证版本，有效期仍为 60 秒。消费在缓存锁内完成，成功使用后删除，重复消费、旧整数格式 `TEMP_TOKEN`、版本失配和无效结构均拒绝。签发时从主库核对用户，拒绝凭旧 `User` 对象签发当前有效的新凭据。
- 用户改密、后台重置、员工更新、找回密码和命令行重置密码等路径统一清理遗留的 `password_salt`，防止新密码哈希与旧盐混用。密码及权限实际变化才轮换版本，无关资料更新不使用户退出登录。

本次没有添加数据库行锁或 `SELECT FOR UPDATE`；敏感字段与随机认证版本由同一条 `UPDATE` 保存，缓存锁负责快捷码消费及会话目录操作的互斥。

认证失效不依赖全量清除缓存，也不依赖旧 JWT 授权缓存的 TTL。即时生效指敏感字段与新版本成功提交后开始认证的新请求；已通过认证的在途请求不在此处追溯取消。

历史会话没有 `auth_version` 元数据时，仅当用户数据库中的版本仍为默认空字符串时继续接受。用户首次完成上述任一敏感变更后，旧格式与旧版本会话都被拒绝。旧整数格式快捷码在新代码中立即拒绝；需要重新获取快捷码。

本次没有修改订阅 `token`、用户 `uuid`、JWT 签名算法或 API AES 加密协议，也没有把代理服务的加解密逻辑移入 PHP。主题与 GeneralEncryptionMiddleware 的密钥和加密配置无需为本次修复调整。

## 部署说明

必须按 [更新文档](../update.md)先进入维护模式并暂停网站、计划任务、Horizon 和 Webman，再同步代码、安装锁定依赖并通过更新脚本执行数据库升级。更新 SQL 为已有用户补齐默认空字符串版本；不要在 `auth_version` 列尚未升级时恢复新代码服务。

数据库升级成功后，重启全部站点 PHP 服务、Horizon 和已启用的 Webman，再恢复网站与计划任务。多实例部署必须完成所有实例切换；仍运行旧代码的实例无法执行版本校验和版本轮换。`horizon:terminate` 不会重启 PHP-FPM 或 Webman。

无需全量清空 Redis、应用缓存或批量轮换订阅凭据。仍未发生敏感字段变化的既有会话可继续使用；全量清理会话缓存会额外使所有用户退出登录。多实例继续使用共享 Redis 及共享缓存锁，快捷码的一次消费与会话目录互斥依赖该锁能力。锁保障以缓存读写在租期内完成为前提；本次隔离环境中的 Redis 多进程并发已通过，生产服务切换与部署验收尚未执行。

部署后用隔离账户核对：后台改密、用户改密、找回密码、禁用与重新启用、角色变更后，之前的 JWT 和快捷码均不能重新取得会话；重新登录后新凭据正常使用；同一新快捷码只能成功消费一次。确认登录、后台权限及快捷登录兼容后再结束维护窗口。

## 验证范围

Windows 隔离回归使用 PHP **8.4.27**、本地已安装的 Laravel **12.70.2**、PHPUnit **11.5.57**，以合成用户、内存 SQLite 和 array 缓存运行，不加载站点 `.env` 或访问外部服务。覆盖敏感字段与版本一同保存、各改密入口、禁用与角色变更、批量禁用、旧格式会话兼容、版本不匹配拒绝、旧对象及已撤销来源 JWT 签发拒绝，以及快捷码单次消费和锁超时。

```powershell
php -d extension=pdo_sqlite vendor/bin/phpunit --filter AuthCredentialSecurityTest --do-not-cache-result
php -d extension=pdo_sqlite vendor/bin/phpunit --testsuite Unit --do-not-cache-result
php -d extension=pdo_sqlite docs/security/auth-audit-proof.php
php -d extension=pdo_sqlite docs/security/input-audit-proof.php
```

- A02 专项：**24 项测试、138 条断言通过**。
- 完整 Unit：**234 项测试、1679 条断言，0 失败、0 错误**；保留 13 个既有 Curl 弃用提示及 1 项需 Linux 的跳过。
- Windows 全部套件：**304 项测试、2179 条断言，1 项失败**。`ConsumptionRestrictionOrderTest::test_new_orders_are_rejected_before_balance_or_order_changes` 期待“当前账号已限制消费”，实际已有控制器返回“暂时无法处理”；基线代码与基线断言已不一致，本次没有改动该业务文案。
- 两个离线脚本分别 **12 / 8 项布尔检查全部为 true**；认证脚本的 A02 项已改为旧会话与旧快捷码被拒绝的安全断言。

本地 vendor 与锁文件的主版本差异保留记录；本次未修改依赖或锁文件。另在独立 Compose 项目 `v2board-a02-20261110` 完成锁定依赖的 Linux 验证：PHP **8.4.27**、Laravel **13.40.0**、PHPUnit **13.4.6**、MySQL **5.7.44**、Redis **7.4.8**、phpredis **6.3.0**。`composer install`、平台检查和安全审计通过；全新安装、模拟缺少 `auth_version` 的旧结构升级、重复升级及原夹具数据保留通过。

[`docker/tests/security-auth.php`](../../docker/tests/security-auth.php) 使用真实 MySQL、Redis 和独立 PHP 进程，全部检查通过：

- 后台、员工、用户、找回及命令行五个改密入口撤销旧会话和快捷码，新密码与新码可正常登录；恢复旧缓存目录仍不能使旧凭据生效。
- 禁用与角色变化后恢复原状态，旧凭据不能复活；管理端按过滤条件批量禁用会轮换版本，并保留未选中用户。
- 8 个独立 PHP 进程经 Redis 屏障同时兑换同一个快捷码，仅 1 次成功，其余 7 次返回 500；恰好保留 1 个有效会话，快捷码已删除。
- 旧密码已验证但尚未保存会话，以及快捷码已完成初次版本检查并删除但尚未保存会话这两个确定性交错中，主进程提交改密后，子进程均返回 403 且未留下会话；未使用数据库行锁。
- 合成用户、活动记录及 Redis 键按本次登记范围精确清理通过。

Linux 认证 A01 + A02 定向回归 **54 项测试、317 条断言通过**。Linux 完整 PHPUnit 为 **304 项测试、2181 条断言，1 项同样的既有 Feature 文案失败**。首次命令另有 1 项 PHPUnit 弃用提示，仅由 `--do-not-cache-result` 引起；改用 PHPUnit 13 的 `--do-not-record-test-run-history`，仅排除上述已知失败用例并启用弃用/警告失败后，**其余 303 项测试、2179 条断言通过，退出码 0、无弃用或警告**。不能将完整 304 项套件描述为全绿。

```powershell
docker compose -p v2board-a02-20261110 -f docker/compose.test.yaml run --rm test vendor/bin/phpunit --filter '/^(?!.*test_new_orders_are_rejected_before_balance_or_order_changes)/' --no-progress --colors=never --do-not-record-test-run-history --fail-on-deprecation --fail-on-warning
```

A02 专项与真实 MySQL/Redis 多进程检查通过。完整执行边界见 [Docker 验收记录](../../docker/ACCEPTANCE.md)；本次隔离验证不代表已执行生产部署。

完整审计状态见 [安全审计报告](../security/2026-11-04-full-chain-security-audit.md)。A02 本地修复后，原始 18 项中剩余 14 项待修复（7 项 P1、7 项 P2），A14 仍为策略观察；身份与凭据生命周期的第 1 批仍有 A17 待修复。

返回 [分支变更记录](../branch-changes.md)。
