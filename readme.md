<img src="https://avatars.githubusercontent.com/u/56885001?s=200&v=4" alt="logo" width="130" height="130" align="right"/>

[![](https://img.shields.io/badge/TgChat-@UnOfficialV2board讨论-blue.svg)](https://t.me/unofficialV2board)

## 本分支支持的后端
 - [修改版V2bX](https://github.com/wyx2685/V2bX)
 - [v2node](https://github.com/wyx2685/v2node)

## 原版迁移步骤

从原来的 `master` 升级到 `fix`，请按 [更新文档](docs/update.md)完成备份、进入维护模式和 PHP 环境切换，再同步 `origin/fix`，执行新版 `bash update.sh --local`。不要继续使用会强制重置到 `master` 的旧更新脚本。

保留原数据库、`.env` 和 `APP_KEY`，使用 Redis 缓存与队列。更新成功后重启 PHP，启动 Horizon 和已启用的 Webman；确认 `php artisan horizon:status` 正常后执行 `php artisan up`，恢复网站和计划任务，再进入后台保存原主题。自带主题为 `default`。

## 安装、更新与 Docker 验收

本分支使用 PHP 8.4、Laravel 13 和配套的 `composer.lock`。Laravel 8 与 YAML 4 的依赖已被 Composer 安全公告阻断；本次保留现有应用结构、JWT / 会话认证、业务数据表及前端，完成必要的框架兼容修复。

全新安装使用 `sh init.sh`，已有站点使用 `sh update.sh`，更新会跟随**当前分支的 upstream**（包括 `fix`），拒绝覆盖有修改的跟踪文件。已经手动同步代码时使用 `sh update.sh --local`。部署脚本安装锁文件中的生产依赖，任何 Composer / 平台 / 安全检查失败都会停止；开发时的依赖更新使用 `composer update` 并提交对应锁文件。

分支变更概览见 [分支变更记录](docs/branch-changes.md)，详细内容见 [更新日志目录](docs/changelog/)。aaPanel / 宝塔部署见 [安装文档](docs/install.md)和 [更新文档](docs/update.md)，Docker 测试命令见 [安装与升级说明](docker/README.md)，实测结果见 [验收记录](docker/ACCEPTANCE.md)。

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
