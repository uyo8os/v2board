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

脚本会更新当前分支所跟踪的远程分支。使用本文的 `fix` 分支部署时，应跟踪 `origin/fix`。

### 从旧版本首次升级到 fix

旧版 `update.sh` 会强制重置到 `master`，首次升级不要直接运行旧脚本。

确认本次修复已经发布到本项目的 `origin/fix`，并保存好本地代码修改后，先同步新代码，再运行新的更新脚本：

```sh
cd /www/wwwroot/api.example.com
git remote set-url origin https://github.com/uyo8os/v2board.git
git fetch origin
git checkout fix
git merge --ff-only origin/fix
git branch --set-upstream-to=origin/fix fix
bash update.sh --local
```

以上命令会将 `origin` 设置为本项目仓库。任何一步报错都先停止，处理完成后再继续，不要强制覆盖自己的代码修改。

`--local` 用于代码已经同步完成的情况，只更新依赖和数据库，不再拉取代码。以后使用上一节的 `bash update.sh` 即可。

### 更新脚本会做什么

- 同步代码；使用 `--local` 时跳过这一步。
- 按 `composer.lock` 安装依赖，检查 PHP、扩展和依赖安全公告。
- 执行数据库更新。
- 重新生成配置缓存，并通知 Horizon 退出。

脚本已经处理依赖和配置缓存，不需要额外执行 `composer update` 或重复运行数据库更新命令。

升级使用原数据库，不需要重新导入用户。脚本会按 `database/update.sql` 补齐尚未应用的历史变更，包括风控和订阅审计相关表。

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

**Q：提示存在本地修改，无法更新**

A：在站点目录执行 `git status --short`，查看修改了哪些文件。先备份、提交或妥善保存自己的修改，再更新，不要直接强制重置。

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
