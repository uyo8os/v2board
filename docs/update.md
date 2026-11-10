# 使用 aaPanel 更新 V2Board

宝塔面板（aaPanel / bt.cn）更新指南，适用于本项目 `fix` 分支。下面以 `/www/wwwroot/api.example.com` 为站点目录，请替换成自己的实际路径。

## 1. 备份数据

更新前，在宝塔面板备份数据库和站点文件，确认备份中包含：

- `.env`
- `config/v2board.php`
- `config/theme` 下的主题设置
- 自定义主题及自己修改的文件

保留原来的数据库连接和 `APP_KEY`，不要重新运行 `init.sh`。

## 2. 进入维护模式并检查环境

先用旧站当前能够正常运行的 PHP 进入维护模式：

```sh
cd /www/wwwroot/api.example.com
php artisan down
```

然后在宝塔面板暂停网站和该站点的计划任务，并在 Supervisor 中停止 Horizon 和已启用的 Webman。如果旧站已经无法执行 Artisan，直接在面板暂停网站及这些进程。

当前代码要求 64 位 PHP 8.4.1 起的兼容 8.x 版本，可以选择 PHP 8.5。

如果原站点使用旧版 PHP，先在宝塔安装新版 PHP，按照 [安装文档](install.md)安装扩展、解除必要的禁用函数。站点和「PHP CLI」都要切换到新版。

通过 SSH 检查命令行版本：

```sh
php -v
```

计划任务和 Supervisor 使用了 PHP 完整路径的，也要改为新版本，例如 PHP 8.5 使用 `/www/server/php/85/bin/php`。

更新前先确认 `.env` 中 Redis 连接正确，缓存和队列均使用 Redis，具体配置见第 4 节。不要清空生产 Redis，也不要批量重置用户 token。完成更新后再恢复网站和服务。

## 3. 执行更新

### 已经使用新版更新脚本

通过 SSH 进入站点目录，执行：

```sh
cd /www/wwwroot/api.example.com
bash update.sh
```

运行 `bash update.sh` 即按当前分支的 upstream 覆盖服务器程序代码，包括已跟踪文件的本地修改和本地提交。本文的 `fix` 应跟踪 `origin/fix`。

`.env`、`config/v2board.php` 和 `config/theme` 下的运行配置不在 Git 跟踪内，正常保留。脚本不执行 `git clean`；但未跟踪文件若阻挡远端跟踪路径，仍可能被 Git 覆盖。

### 从旧版本首次升级到 fix

旧版 `update.sh` 会强制重置到 `master`，首次升级不要直接运行旧脚本。

确认本次修复已经发布到本项目的 `origin/fix`，先覆盖为远端代码，再运行新的更新脚本：

```sh
cd /www/wwwroot/api.example.com
git remote set-url origin https://github.com/uyo8os/v2board.git
git fetch origin
git checkout -f -B fix origin/fix
git branch --set-upstream-to=origin/fix fix
```

成功后检查：

```
git branch --show-current
grep -nE '"laravel/framework"|"symfony/yaml"' composer.json
head -n 5 update.sh
```

应当看到：

- 分支为 `fix`
- Laravel 为 `^13.40`
- YAML 为 `^8.1`
- 更新脚本开头包含 `set -eu`

确认一致后，再执行：

```
bash update.sh --local
```

更新成功后，在宝塔重启 PHP，并启动 Supervisor 中的 Horizon 和已启用的 Webman。执行：

```
php artisan --version
php artisan horizon:status
```

确认 Laravel 为 **13.40.0**、Horizon 显示 **running**，再执行 `php artisan up`，恢复网站和计划任务。

以上命令会将 `origin` 设置为本项目仓库，并用 `origin/fix` 覆盖本地程序修改和提交。任何一步报错都先停止，处理完成后再继续。

`--local` 用于代码已经同步完成的情况，只更新依赖和数据库，不再拉取代码。以后使用上一节的 `bash update.sh` 即可。

### 更新脚本会做什么

- 获取当前分支的远端更新，执行 `git reset --hard` 覆盖程序代码；使用 `--local` 时跳过全部 Git 操作。
- 按 `composer.lock` 安装依赖，检查 PHP、扩展和依赖安全公告。
- 自动检查历史邮箱规范化冲突；有冲突则停止，不执行后续数据库变更。
- 执行数据库更新，并规范化历史邮箱、更新邮箱列排序规则。
- 重新生成配置缓存，并通知 Horizon 退出。

脚本已经处理依赖和配置缓存，不需要额外执行 `composer update` 或重复运行数据库更新命令。

升级使用原数据库，不需要重新导入用户。脚本会按 `database/update.sql` 补齐尚未应用的历史变更，包括风控和订阅审计相关表。

更新还会在维护模式下统一历史邮箱的首尾空格和 ASCII 大小写，并将 MySQL 邮箱列改为 `utf8mb4_bin`，防止数据库把不同重音字符当成同一邮箱。密码、订阅 token 和 UUID 不变。请按第 2 节暂停所有写入进程；未进入维护模式时更新命令会拒绝执行。

正常执行 `bash update.sh` 时，脚本会先获取新代码、安装对应依赖，再自动检查邮箱冲突，最后才执行数据库变更。旧版本尚无 `v2board:normalize-email` 命令，无需在获取新代码前手动运行。

若多个账号规范化后相同，更新会在导入 `database/update.sql` 和写入邮箱数据前停止，并报告冲突用户 ID。此时代码和依赖已更新，数据库变更尚未开始；请保持维护模式，核实账号归属并分别修改邮箱，再执行 `bash update.sh --local` 重试，不会自动合并或覆盖账号。

`php artisan v2board:normalize-email` 用于已同步本次新代码、安装对应依赖后单独进行只读检查，不是首次更新的前置步骤。如需手动检查，可先完成代码同步和依赖安装，运行该命令后再执行 `bash update.sh --local`；正式更新仍会重新检查冲突。已有账号的历史大小写和空格在应用查询中仍有兼容处理。需要单独升级邮箱列时，在维护窗口执行 `php artisan v2board:normalize-email --apply`。

## 4. 刷新配置和队列

确认 `.env` 中 Redis 连接正确，并使用：

```dotenv
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
```

确认更新成功后，在站点目录执行：

```sh
php artisan config:clear
php artisan config:cache
php artisan view:clear
php artisan horizon:terminate
```

新版更新脚本已经执行了 `config:cache` 和 `horizon:terminate`，这里保留完整的手动刷新步骤。如果更新后又修改了 `.env`，也按这一步重新刷新。

`horizon:terminate` 只通知队列进程退出，需要 Supervisor 自动拉起或手动启动，才能加载新的配置。它不会重启 PHP-FPM 或 Webman。

## 5. 重启服务并恢复网站

如果启用了 Webman 且切换了 PHP 版本，先按 [安装文档](install.md)中的 Webman 步骤重新准备独立 PHP 配置，避免继续加载旧版 PHP 的扩展。

完成配置刷新后：

1. 在宝塔面板重启站点对应的 PHP 服务。
2. 在 Supervisor 中启动 Horizon，确认进程正常运行，并设置始终自动重启（`autorestart=true`）。
3. 启用了 Webman 的，启动它的守护进程。

在站点目录检查 Horizon：

```sh
php artisan horizon:status
```

确认显示 `running` 后，再退出维护模式：

```sh
php artisan up
```

最后恢复面板中暂停的网站和每分钟执行一次的计划任务。

更新脚本不会代替面板重启 PHP 或 Webman。Horizon 收到退出信号后，也需要 Supervisor 或手动操作将它重新拉起。

## 6.手动验证（如 update.sh 失败）

```
# 安装依赖
composer install --no-dev --optimize-autoloader

# 清除缓存
php artisan config:clear
php artisan cache:clear
php artisan view:clear
php artisan route:clear

# 重建缓存
php artisan config:cache

# 重启服务
php artisan horizon:terminate
systemctl restart php-fpm-85
systemctl restart nginx
```

## 7. 保存主题并检查更新结果

恢复网站后，进入后台「主题配置 → 选择原主题 → 主题设置 → 保存」。使用项目自带主题时选择 `default`；自定义主题继续选择原主题，并确认它与新版兼容。

访问网站，检查登录、后台配置、订阅获取等功能是否正常，并确认订单、邮件等队列任务可以处理。

## 8. 基础验证

```
# 验证 Laravel 版本
php artisan --version
# 预期输出：Laravel Framework 13.x.x

# 验证路由数量
php artisan route:list | wc -l
# 预期：200+ 行
# 如果不正常可执行一下命令查看错误
/www/server/php/85/bin/php artisan route:list

# 验证队列状态
php artisan horizon:status
# 预期：running
```

## 常见问题

**Q：更新会保留服务器上的程序修改吗？**

A：不会。`bash update.sh` 直接覆盖到当前分支的远端版本，本地修改和本地提交都会被替换，不需要先合并代码。

**Q：提示没有跟踪分支**

A：确认当前就是 `fix` 分支，并且 `origin` 指向本项目仓库后，执行：

```sh
git fetch origin
git branch --set-upstream-to=origin/fix fix
bash update.sh
```

**Q：依赖安装失败**

A：先根据报错检查 PHP 版本、缺少的扩展、网络或依赖安全公告。不要删除锁文件、关闭安全检查或跳过平台要求。解决问题后，代码已经同步完成的可执行 `bash update.sh --local`。

**Q：数据库更新失败**

A：保留报错，检查数据库连接、权限和具体 SQL 错误。修复问题后再执行 `bash update.sh --local`。不要重新安装，也不要在报错后继续按更新成功处理。

**Q：更新后仍显示旧内容或功能异常**

A：确认 PHP、Horizon 和已启用的 Webman 都已经重新启动，再查看宝塔的 PHP / Nginx 错误日志、Supervisor 日志和项目 `storage/logs` 目录。

如果更新完成后仍显示维护页面，确认已经执行 `php artisan up`。更新失败时先处理错误，不要提前恢复网站。

需要回退时，恢复同一次备份中的代码、配置和数据库，再按备份时的 PHP 环境启动服务；不要只切回旧代码。
