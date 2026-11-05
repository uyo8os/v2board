# 安装、依赖与升级验收记录

## 2026-11-05：A11 与安全依赖完整复验

更新四个 Symfony 组件后，在新隔离 Compose 项目 `v2board-a11-verified-20261105` 运行 `docker/tests/run.sh`，脚本退出码 **0**。测试使用 PHP **8.4.27**、Laravel **13.40.0**、MySQL **5.7.44**、Redis **7.4.8**、Nginx **1.30.6**；FPM / Workerman 端口为 19110 / 19111。完整输出保存在未跟踪的 `.docker-test/a11-verified-20261105.log`。

- 全新安装、Git 更新、重复升级、既有数据保留和失败路径通过；新增确认未进入维护模式时数据库升级被拒绝且数据保留。
- Composer 完整 update 和安全审计通过后，脚本恢复交付的锁文件并重新 install；平台检查、再次 audit 和后续测试针对交付版本。`/app/composer.lock` 与源文件 SHA-256 一致：`b66055f78a44221fbd4875e954be15aaca29944b0531813fb403db286ed7ad7b`，交付锁文件 audit 无公告。
- PHPUnit 通过 **201 项测试、1496 条断言**；A11 真实 MySQL 临时表和文件日志专项 **12 项检查**通过。
- Nginx / PHP-FPM、Workerman 和 Horizon 运行正常；登录、JWT、订阅、App、节点、订单续费、流量、邮件测试传输、订阅审计及客户端 IP 检查通过，失败队列为 0。脚本输出 `ALL RUNTIME ACCEPTANCE CHECKS PASSED` 和 `ALL INSTALLATION, UPGRADE AND RUNTIME CHECKS PASSED`。

此前复验还发现验收脚本未正确进入和退出维护模式，导致 Git 升级或测试阶段失败；已调整验收脚本顺序并重新执行上述完整流程，生产升级的维护模式要求没有放宽。测试使用隔离数据与邮件日志传输，未连接生产服务器或真实支付、SMTP、Telegram。依赖变更见 [Symfony 安全依赖更新日志](../docs/changelog/2026-11-05-symfony-security-updates.md)，日志修复边界见 [A11 修复日志](../docs/changelog/2026-11-05-system-log-security-fix.md)。

## 2026-11-05：A11 系统日志修复验证

隔离 Compose 项目 `v2board-a11-20261105` 使用 PHP **8.4.27** 与 MySQL **5.7.44** 验证当时的代码与依赖。完整 PHPUnit 通过 **201 项测试、1496 条断言**，包括真实 Laravel 异常处理器向数据库及文件写日志的敏感哨兵回归。单独运行 `php /source/docker/tests/security-log.php`，12 项检查通过：真实 MySQL 连接级临时表与单文件日志均不包含 POST 查询串、嵌套密码/密钥、JWT、验证码、异常消息或上下文哨兵，同时保留操作标识、主体/目标 ID 和异常元数据。测试使用合成值，没有连接生产数据。该脚本已加入 `docker/tests/run.sh`，安排在升级阶段之后。

本轮运行 `docker/tests/run.sh` 在 `install.sh` / `prepare.sh` 的 Composer audit 阶段失败，原因是锁文件的 4 个包命中 7 条安全公告：`symfony/http-foundation` 4 条、`symfony/http-kernel`、`symfony/routing`、`symfony/yaml` 各 1 条。该次初始化、升级和运行验收阶段尚未开始；上述 PHPUnit 和专项脚本是当时在同一隔离项目中单独执行的结果。更新依赖后的完整复验结果见上一节。

---

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

## 2026-11-04：路由查看命令补充回归

在同一个隔离 Compose 项目中复现 `route:list` 的 `HttpException`，调用栈指向 Telegram 控制器构造函数的请求校验。加入命令兼容修复后：

- 普通列表和 JSON 输出成功，验收环境中列出 220 条路由，并保留 Horizon 认证中间件信息。
- PHPUnit 全部通过：**80 tests, 419 assertions**，其中新增 3 项路由命令回归测试。
- PHP-FPM 与 Workerman 的 Telegram Webhook 无效令牌请求均返回 401，未向 Telegram 发起外部请求。
- 更新后的运行验收脚本通过，原登录、JWT、订阅、订单、流量、邮件日志传输及审计任务均正常，失败任务数为 0。

此轮同步修复文件和测试至已有验收卷，未重新执行全新安装、Composer 安装 / 更新或数据库升级。修复范围与命令显示限制见 [更新日志](../docs/changelog/2026-11-04-route-list-compatibility-fix.md)。

## 2026-11-04：依赖升级与 PHP 8.5 完整验收

使用最终代码及锁文件，在两个全新的 Compose 项目中重新执行 `docker/tests/run.sh`：

| 项目 | PHP | PHP-FPM / Workerman 入口端口 |
| --- | --- | --- |
| `v2board-dependency-verified84` | 8.4.27 | 18894 / 18895 |
| `v2board-dependency-verified85` | 8.5.12 | 18896 / 18897 |

两组均使用 Laravel **13.40.0**、Composer **2.11.1**、Nginx **1.30.6**、MySQL **5.7.44**、Redis **7.4.8**、phpredis **6.3.0**。PHP 8.5 通过 `V2BOARD_TEST_PHP_VERSION=8.5` 选择；复现方式见 [安装与升级说明](README.md)。

| 验证项目 | PHP 8.4 | PHP 8.5 |
| --- | --- | --- |
| 全新安装及管理员生成 | 通过 | 通过 |
| Composer install / update / 平台检查 / 安全审计 | 通过 | 通过 |
| Git 分支更新、重复升级 | 通过 | 通过 |
| 用户、余额、佣金、订单、节点、限制记录、APP_KEY、站点配置保留 | 通过 | 通过 |
| 非空库拒绝重装、本地修改阻断、SQL / Composer 失败路径 | 通过 | 通过 |
| PHPUnit，遇到弃用或警告判失败 | 101 tests / 604 assertions | 101 tests / 604 assertions |
| Nginx / PHP-FPM / Workerman / Horizon | 通过 | 通过 |
| 登录、JWT、用户隔离、订阅、App 配置、节点接口、PNG 验证码 | 通过 | 通过 |
| 下单扣余额、队列续费、流量、邮件日志传输、审计任务 | 通过 | 通过 |
| 失败队列任务 | 0 | 0 |
| 服务日志中的 Warning / Deprecated / Fatal / Uncaught | 无 | 无 |

两组 `composer outdated --direct --format=json` 均返回空列表，没有可更新的直接依赖。Composer 更新后的锁文件与仓库相同，SHA-256 为 `b4fb32148ff02a8f8653d64da233382deb108d4fe7899d0bb1e30cd43364696b`。

本轮同时修正了验收脚本的两个问题：独立 PHP 脚本抛出异常后明确返回非零状态；快照比较先按字段名排序，再严格比较字段值和类型，避免历史 SQL 调整列顺序造成误报。两组均额外验证未知夹具阶段返回状态 **1**。

原始完整输出保存在未跟踪的 `.docker-test/dependencies-verified84.log`、`.docker-test/dependencies-verified85.log`；安装及升级阶段日志位于各自隔离应用卷的 `storage/logs`。两个完整脚本均返回 **0**，并输出：

```text
OK (101 tests, 604 assertions)
ALL RUNTIME ACCEPTANCE CHECKS PASSED
ALL INSTALLATION, UPGRADE AND RUNTIME CHECKS PASSED
```

支付新增回归使用离线模拟：覆盖五个 Stripe 渠道的请求及验签、Curl 支付请求与 Telegram 参数，不联系真实网关。未执行真实支付、退款、SMTP 或 Telegram 发送；保留旧 Stripe Sources 渠道不代表解除其服务端停用或账户限制。完整版本对照和兼容修复见 [依赖升级日志](../docs/changelog/2026-11-04-dependency-updates.md)。

## 2026-11-04：Webman 风控 IP 修复验收

使用 Laravel 13.40.0、MySQL 5.7.44、Redis 7.4.8，在以下隔离项目验证两种 HTTP 入口：

| 项目 | PHP | PHP-FPM / Webman 端口 | 最终单元测试 |
| --- | --- | --- | --- |
| `v2board-webman-ip-84` | 8.4.27 | 18900 / 18901 | 114 tests / 624 assertions |
| `v2board-webman-ip-final85` | 8.5.12 | 18902 / 18903 | 114 tests / 624 assertions |

- 两组均完成全新安装、Composer 安装 / 更新 / 平台检查 / 安全审计、Git 升级及数据保留检查，原登录、订阅、订单、流量和队列运行验收通过。
- PHP 8.5 的完整 `docker/tests/run.sh` 返回 0；PHP 8.4 在安装、升级及运行阶段通过后，补齐新增探针并单独运行成功。最终空代理列表边界修复同步到两组测试卷后，重新运行全部单元测试、重启服务并检查风控 IP，均返回 0。
- `docker/tests/proxy-ip.php` 分别通过 PHP-FPM、Webman 发起真实登录和订阅请求，直接检查 `v2_insider_guard_activity` 中实际客户端 IP 对应的活动计数增加，四项均通过。
- 客户端同时伪造 XFF、CF-Connecting-IP、X-Real-IP 时，两种入口仍记录实际客户端 IP。异步订阅审计同样没有写入伪造 IP。
- 单元测试覆盖明确 IP / 网段、IPv6、多层代理、空列表、连续请求、转发 Host / Port 及 PHP-FPM 原配置兼容。两组均启用弃用和警告判失败。
- 最终 `nginx -t`、`php-fpm -t` 通过，Horizon 状态为 running。

Docker 中 Nginx 使用实际容器 IP，因此验收脚本只将该具体 IP 加入 Webman 可信列表，不信任整个容器网段。脚本同时在应用容器重启后重载 Nginx，刷新上游容器地址。

安装及运行原始输出位于未跟踪的 `.docker-test/webman-ip-84.log`、`.docker-test/webman-ip-final85.log`；最终补充回归输出见本次执行记录。未连接真实 Cloudflare / CDN，链路兼容通过代理链单元测试和本地 Nginx 验证，服务器仍需保留原有真实 IP 配置。修复范围见 [更新日志](../docs/changelog/2026-11-04-webman-client-ip-fix.md)。
