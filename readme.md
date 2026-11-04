<img src="https://avatars.githubusercontent.com/u/56885001?s=200&v=4" alt="logo" width="130" height="130" align="right"/>

[![](https://img.shields.io/badge/TgChat-@UnOfficialV2board讨论-blue.svg)](https://t.me/unofficialV2board)

## 本分支支持的后端
 - [修改版V2bX](https://github.com/wyx2685/V2bX)
 - [v2node](https://github.com/wyx2685/v2node)

## 风控中心（InsiderGuard）

独立管理端「用户 → 风控中心」用于排查订阅泄露和异常账号。实现参考 [codeman857/v3board](https://github.com/codeman857/v3board) 及其管理端；本分支不包含 TCP 健康探测和相关 Telegram 告警。

- 记录密码登录成功、普通订阅及 App 配置拉取的来源 IP、UA 和次数。同一用户、事件类型、IP、UA 按小时合并，UA 单独去重存储；快捷登录不计为密码登录。
- 普通订阅记录请求时的**可下发节点范围**，具体客户端格式可能只输出其中一部分；App 配置记录实际输出子集。每人每节点保留首次、最近记录和去重次数，相同节点集合 6 小时内只记一次，不是逐次下发历史。
- 风险列表支持 1、8、24、72、168 小时窗口；详情固定统计最近 1 小时。所选窗口订阅次数超过 20 加 25 分，登录与订阅合计来源 IP 数超过 8 加 25 分，分数为 0 / 25 / 50。高风险阈值可设 1～50，默认 50；按小时合并会多计窗口边界所在小时的部分活动。达到阈值仅提示人工复核。
- 人工登记节点异常后，可查看异常前 24 小时的候选账号和前 24 小时至后 6 小时的关联范围。查询根据首次 / 最近时间估算，并为 6 小时去重放宽下界，可能多列账号，只能作为线索。审核动作只记录意见，不改变评分；限制与解除需单独操作。
- 限制不封号：后续订阅和 App 配置中的地址、SNI、Host、obfs host 替换为假入口。默认按用户生成固定 IPv4，也可设置自己的域名或 IP；解除后后续配置恢复。已缓存的真实配置和已有连接不会失效，登录后的用户面板仍返回原节点列表。限制状态无法查询时，本次配置返回 HTTP 503；审计写入失败只记日志，不阻断正常业务。
- 风控设置可配置阈值、假入口和保留天数（1～365，默认 3）。每小时按最近活动时间清理过期活动及不活跃的节点范围；UA 字典额外保留一天。持续活跃的节点范围保留原首次时间和累计次数，审核记录、异常登记不自动清理，现存用户的限制状态不因保留期到期而解除。
- 「订阅审计」参考 [hajidishu/shuv2b](https://github.com/hajidishu/shuv2b)，独立记录已通过订阅鉴权的普通订阅和 App 配置请求，包括账号不可用、处理异常及假入口配置；不包含无效 token、鉴权前拦截或未回源的请求，也不包含 App 版本查询。记录时间、用户、IP、UA、实际格式、结果、HTTP 状态及处理耗时，HTTP 200 不一定表示账号可用。
- 逐次明细通过 Redis 的 `insider_guard_audit` 队列异步写入，查询可能稍有延迟。详情仅保存方法、脱敏路径、白名单格式参数和假入口标记等安全元数据，不保存实际 token、请求头或响应正文；「当前订阅链接」在查看详情时按现有订阅模式生成，不是历史请求链接，也不写入审计表。
- 订阅审计支持时间、用户、邮箱、IP、UA、格式、结果、来源及 HTTP 状态筛选。IP / UA / 用户前十名覆盖全部筛选结果，独立缓存最多 60 秒，手动刷新可强制重算；原风控概览的近 24 小时事件仍缓存 120 秒。逐次明细不参与原有小时记录的评分和节点线索，也不作为用户终身累计次数。
- 逐次审计默认开启，独立保留 **3 天、最多 100,000 条**，可在「风控设置」调整为 1～365 天、1～1,000,000 条。每分钟按请求时间和 ID 分批淘汰旧记录，调低上限或并发写入后可能需要后续清理轮次收敛；关闭采集后仍清理已有明细。逐次记录的数据量不能按小时合并记录的行数估算。

正常全新安装执行 `sh init.sh`，已有站点升级执行 `sh update.sh`；脚本会分别调用 `php artisan v2board:install` / `php artisan v2board:update`，自动导入包含 7 张风控表的 SQL，无需额外重复执行 Artisan 命令。只有手动同步后端代码、未运行更新脚本时，才需要单独执行 `php artisan v2board:update`。独立管理端需同步构建发布；本仓库原有后台静态资源不会自动增加风控页面。清理已加入现有 Laravel 调度，无需额外添加 crontab，可用 `php artisan insider-guard:cleanup` 清理原小时活动，或用 `php artisan subscribe-audit:prune` 清理逐次订阅明细。Horizon 已增量配置独立审计消费者，原有限速等队列保持不变；需保持现有 Horizon 守护及调度正常运行。

审计去重和列表聚合缓存沿用 Redis。来源地址使用 Laravel 的请求 IP；反向代理 / CDN 部署需按实际代理配置 `TrustProxies`，否则可能记录到代理地址。记录包含 IP 与 UA，请按实际需要设置保留期；有大量活跃用户时建议保持较短保留期。

## 原版迁移步骤

从原来的 `master` 升级到 `fix`，请按 [更新文档](docs/update.md)完成备份、进入维护模式和 PHP 环境切换，再同步 `origin/fix`，执行新版 `bash update.sh --local`。不要继续使用会强制重置到 `master` 的旧更新脚本。

保留原数据库、`.env` 和 `APP_KEY`，使用 Redis 缓存与队列。更新成功后重启 PHP，启动 Horizon 和已启用的 Webman；确认 `php artisan horizon:status` 正常后执行 `php artisan up`，恢复网站和计划任务，再进入后台保存原主题。自带主题为 `default`。

## 安装、更新与 Docker 验收

本分支使用 PHP 8.4、Laravel 13 和配套的 `composer.lock`。Laravel 8 与 YAML 4 的依赖已被 Composer 安全公告阻断；本次保留现有应用结构、JWT / 会话认证、业务数据表及前端，完成必要的框架兼容修复。

全新安装使用 `sh init.sh`，已有站点使用 `sh update.sh`，更新会跟随**当前分支的 upstream**（包括 `fix`），拒绝覆盖有修改的跟踪文件。已经手动同步代码时使用 `sh update.sh --local`。部署脚本安装锁文件中的生产依赖，任何 Composer / 平台 / 安全检查失败都会停止；开发时的依赖更新使用 `composer update` 并提交对应锁文件。

本次具体修改见 [更新日志](docs/changelog/2026-11-03-installation-dependencies-upgrade-fixes.md)。aaPanel / 宝塔部署见 [安装文档](docs/install.md)和 [更新文档](docs/update.md)，Docker 测试命令见 [安装与升级说明](docker/README.md)，实测结果见 [验收记录](docker/ACCEPTANCE.md)。

# **V2Board**

- PHP 8.4（Linux，CLI 和 PHP-FPM 使用相同扩展）
- Composer 2.11（安全检查保持默认开启）
- MySQL 5.7+
- Redis 7.4
- Laravel 13

## Document
[Click](https://v2board.com)

## Sponsors
Thanks to the open source project license provided by [Jetbrains](https://www.jetbrains.com/)

## Community
🔔Telegram Group: [@unofficialV2board](https://t.me/unofficialV2board)  

## How to Feedback
Follow the template in the issue to submit your question correctly, and we will have someone follow up with you.
