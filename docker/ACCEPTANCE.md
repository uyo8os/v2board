# 安装、依赖与升级验收记录

日期：2026-11-03（Asia/Shanghai）。工作分支：`fix`。最终完整复验使用全新的 Compose 项目 `v2board-fix-final`，代码复制到独立 Linux 数据卷；未连接或修改真实服务器数据。

## 结论与版本选择

目标环境的全新安装、依赖安装 / 更新、项目升级和服务运行均通过。保留现有应用结构、JWT / Redis 会话、业务表和前端；仅修改框架兼容、部署脚本及原有升级过程中的错误。

在 PHP 8.4 容器中，移除原有公告忽略配置后，对原始依赖执行 `composer update --dry-run --no-scripts --no-interaction`，Laravel `^8.0` 和 Symfony YAML `^4.3` 均被安全公告阻止解析。框架报告包括 `PKSA-b41t-wbzk-9q51`、`PKSA-d5tc-s1qs-h781` 等；YAML 报告包括 `PKSA-xz3r-9gp4-4m1z` 等。因而不能在保持安全检查的前提下继续这组约束。

最终使用 Laravel 13.39.0。Laravel 13 官方数据库支持范围包含 MySQL 5.7，目标 PHP 8.4 也符合要求，参考 [数据库支持](https://laravel.com/docs/13.x/database)、[支持周期](https://laravel.com/docs/13.x/releases) 和 [框架安全公告](https://packagist.org/packages/laravel/framework/advisories)。没有保留公告忽略规则，没有关闭安全阻断，没有跳过平台要求。

## 实测环境

| 组件 | 实际版本 |
| --- | --- |
| PHP CLI / FPM | 8.4.27 |
| Composer | 2.11.1 |
| Laravel | 13.39.0 |
| Horizon | 5.50.1 |
| Adapterman / Workerman | 0.7.1 / 5.2.3 |
| Nginx | 1.30.6 |
| MySQL | 5.7.44 |
| Redis server / phpredis | 7.4.8 / 6.3.0 |

锁文件 SHA-256：`673f3c82ac13fbcf68286635a181847db92fe7f46a8063281ad9b0b6094f6af8`。容器内完整 `composer update` 后的锁文件与工作区一致。

## 验收结果

| 项目 | 结果与证据 |
| --- | --- |
| 全新安装 | 空应用卷、空 MySQL 库运行 `init.sh --no-interaction --email=admin@example.test` 成功，导入原 SQL，创建一个管理员并生成 APP_KEY |
| Composer install | 包含开发依赖及 `--no-dev` 生产安装均成功；包自动发现正常 |
| Composer update | 完整依赖更新成功，锁文件无漂移 |
| 平台 / 安全检查 | `validate --strict`、`check-platform-reqs`、`audit` 成功；无安全公告 |
| Git 升级 | 隔离副本建立真实本地 `origin/fix`，推送新提交后运行默认 `update.sh`，正确快进并保留 fix 分支 |
| 已有数据升级 | 按本分支原数据库结构注入用户、余额、佣金、套餐、订单、节点、限制和限速记录；升级及重复升级前后逐行快照一致，APP_KEY 和站点配置一致 |
| 旧缓存兼容 | 注入旧 Ignition provider manifest 后，更新脚本清理旧缓存并成功完成 Composer 包发现及升级 |
| 失败路径 | 非空数据库拒绝重装；跟踪文件修改拒绝更新且保留修改；Composer 失败、非法 SQL 都返回非零状态 |
| Laravel 启动 | Artisan、包发现、配置缓存、原首页和 API 正常 |
| Nginx + FPM | 真实 HTTP 请求通过；`nginx -t`、`php-fpm -t` 成功 |
| Workerman | Adapterman 初始化及两个 worker 正常；通过 Nginx 请求原接口成功 |
| Horizon | production 环境正常运行；实际完成余额支付续费订单、流量任务、邮件任务及两类订阅审计任务；failed_jobs 为 0 |
| 业务兼容 | 两种 HTTP 入口分别验证密码登录、JWT、交替用户请求隔离、未登录拒绝、套餐 / 订单读取、Clash / Sing-box / Surge / V2rayN 订阅、假入口限制、App 配置、PNG 验证码、节点用户接口 |
| 数据库日志 | Monolog 3 实际写入原 v2_log 表 |
| 调度 | `schedule:list` 成功，流量更新、限速解除和订阅审计清理等原任务正常注册 |
| PHPUnit | **77 tests, 408 assertions，全部通过** |
| PHP 8.4 检查 | app / library 全部 PHP 文件语法检查成功，无弃用提示；最终 FPM / Workerman / Horizon 日志未发现 Warning、Deprecated、Fatal 或失败日志 |

## 复现与证据

从仓库根目录在 POSIX shell / Git Bash 执行：

```sh
COMPOSE_PROJECT_NAME=v2board-fix-final \
V2BOARD_TEST_HTTP_PORT=18882 V2BOARD_TEST_WORKER_PORT=18883 \
sh docker/tests/run.sh
```

本次测试资源保留在该 Compose 项目中；再次从零复现应更换项目名和端口，避免使用已有安装卷。宿主原始输出保存在未跟踪的 `.docker-test/final-acceptance.log`、`.docker-test/final-services.log`，详细阶段日志保存在隔离应用卷的 `storage/logs`。完整脚本最终输出：

```text
OK (77 tests, 408 assertions)
ALL RUNTIME ACCEPTANCE CHECKS PASSED
ALL INSTALLATION, UPGRADE AND RUNTIME CHECKS PASSED
```

本地验收使用现有数据库结构的测试数据及旧包缓存夹具，没有安装已被 Composer 安全阻断的 Laravel 8 依赖。邮件使用日志传输；外部支付网关、真实 SMTP / Telegram、真实服务器配置及真实数据备份恢复不在本次本地验收范围内。实际部署按 [安装与升级说明](README.md) 备份、升级并重启长驻服务。
