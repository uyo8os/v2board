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

正常全新安装执行 `sh init.sh`，已有站点升级执行 `sh update.sh`；脚本会分别调用 `php artisan v2board:install` / `php artisan v2board:update`，自动导入包含 6 张风控表的 SQL，无需额外重复执行 Artisan 命令。只有手动同步后端代码、未运行更新脚本时，才需要单独执行 `php artisan v2board:update`。独立管理端需同步构建发布；本仓库原有后台静态资源不会自动增加风控页面。清理已加入现有 Laravel 调度，无需额外添加 crontab，可用 `php artisan insider-guard:cleanup` 手动执行。

审计去重和列表聚合缓存沿用 Redis。来源地址使用 Laravel 的请求 IP；反向代理 / CDN 部署需按实际代理配置 `TrustProxies`，否则可能记录到代理地址。记录包含 IP 与 UA，请按实际需要设置保留期；有大量活跃用户时建议保持较短保留期。

## 原版迁移步骤

按以下步骤进行面板代码文件迁移：

    git remote set-url origin https://github.com/uyo8os/v2board  
    git checkout master  
    ./update.sh  


按以下步骤配置缓存驱动为redis，然后刷新设置缓存，重启队列:

    sed -i 's/^CACHE_DRIVER=.*/CACHE_DRIVER=redis/' .env
    php artisan config:clear
    php artisan config:cache
    php artisan horizon:terminate

最后进入后台重新保存主题： 主题配置-选择default主题-主题设置-确定保存

# **V2Board**

- PHP7.3+
- Composer
- MySQL5.5+
- Redis
- Laravel

## Document
[Click](https://v2board.com)

## Sponsors
Thanks to the open source project license provided by [Jetbrains](https://www.jetbrains.com/)

## Community
🔔Telegram Group: [@unofficialV2board](https://t.me/unofficialV2board)  

## How to Feedback
Follow the template in the issue to submit your question correctly, and we will have someone follow up with you.
