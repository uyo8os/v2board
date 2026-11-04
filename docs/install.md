# 使用 aaPanel 手动部署

宝塔面板（aaPanel / bt.cn）安装部署指南。按照 [V2Board 官方文档](https://v2board.com/deploy/aapanel.html)的步骤，适配本项目 `fix` 分支。

## 1. 安装宝塔面板

安装完成后登录宝塔面板，在「软件商店」中安装以下软件：

☑️ Nginx（稳定版） ☑️ MySQL 5.7+ ☑️ PHP 8.5 ☑️ Redis 7.4

> 本项目要求 PHP 8.4 起的兼容 8.x 版本，不支持 PHP 8.2/8.3 及以下版本。本文以 PHP 8.5 为例，也可以使用 PHP 8.4，对应修改面板选项和命令中的 PHP 路径。

在「网站 → PHP CLI」中，将命令行 PHP 也设置为所选版本。通过 SSH 执行 `php -v` 确认版本正确。

## 2. 安装 PHP 扩展

宝塔面板 → 软件商店 → 找到 PHP 8.5 点击「设置」→「安装扩展」，确认以下扩展已经安装，缺少时再安装：

- `redis`
- `fileinfo`

`bcmath`、`pcntl`、`posix`、`gd`、`zip` 等扩展可能已经随 PHP 编译安装，不一定在面板中提供单独的安装按钮。通过 SSH 检查宝塔 PHP 8.5 已加载的扩展：

```sh
/www/server/php/85/bin/php -m
```

如果使用 PHP 8.4，将路径中的 `85` 改为 `84`。

列表中已经出现的扩展无需重复安装，也可以在面板的 PHP 信息中查看。[aaPanel 扩展检查说明](https://www.aapanel.com/forum/d/3359-can-you-add-php-extensions)

- `bcmath`、`pcntl`、`posix`：本项目必需，确认出现在列表中即可。
- `gd`：可选，用于 PNG 验证码；缺少时当前代码会使用 SVG 验证码。
- `zip`：可选，用于解压依赖；没有 PHP `zip` 扩展时，可以使用系统 `unzip`。

`pdo_mysql`、`openssl`、`curl`、`mbstring`、`dom`、`xml` 通常也已包含。确实缺少必需扩展时，先确认命令行使用的是所选 PHP 版本，再为该版本补齐扩展。

确认 Redis 服务已经启动；PHP 的 `redis` 扩展和 Redis 服务需要分别安装。

## 3. 解除被禁止的函数

宝塔面板 → 软件商店 → 找到 PHP 8.5 点击「设置」→「禁用函数」，将以下函数从列表中**删除**：

- `putenv`
- `proc_open`
- `proc_get_status`
- `proc_close`
- `proc_terminate`
- `pcntl_alarm`
- `pcntl_signal`
- `pcntl_async_signals`
- `posix_kill`

没有出现在禁用列表中的函数无需处理。修改后重启 PHP 服务。

## 4. 添加站点

宝塔面板 →「网站」→「添加站点」：

- **域名**：填入你指向服务器的域名（如 `api.example.com`）
- **数据库**：选择 MySQL，创建空数据库，记下数据库名、用户名、密码
- **PHP 版本**：选择 PHP 8.5

## 5. 安装 V2Board

通过 SSH 登录服务器，进入站点目录：

```sh
cd /www/wwwroot/api.example.com
```

以下所有命令都在站点目录下执行，请将示例域名替换成自己的域名。

全新站点先删除宝塔自动生成的默认文件；已有站点不要执行这一步：

```sh
chattr -i .user.ini 2>/dev/null
rm -f .htaccess 404.html 502.html index.html .user.ini
```

确认目录为空后，从 GitHub 克隆本项目的 `fix` 分支：

```sh
git clone -b fix https://github.com/uyo8os/v2board.git ./
```

执行安装脚本：

```sh
sh init.sh
```

根据提示填写数据库信息和管理员邮箱，完成安装。脚本会自动安装依赖、生成配置、导入数据库，并显示管理员密码和后台地址，请保存好。

安装脚本已经生成自动加载文件和配置缓存，无需再手动执行 `composer dump-autoload` 或 `php artisan config:cache`。

## 6. 配置站点目录及伪静态

编辑添加的站点 →「网站目录」→「运行目录」选择 `/public` 保存。

编辑添加的站点 →「伪静态」，填入以下内容：

```nginx
location /downloads {
}

location / {
    try_files $uri $uri/ /index.php$is_args$query_string;
}

location ~* \.(js|css)$ {
    expires 1h;
    error_log off;
    access_log /dev/null;
}
```

## 7. 配置定时任务

宝塔面板 →「计划任务」：

- **任务类型**：Shell 脚本
- **任务名称**：v2board
- **执行周期**：每 1 分钟
- **脚本内容**：

```sh
php /www/wwwroot/api.example.com/artisan schedule:run
```

点击「添加任务」即可。

## 8. 启动队列服务

V2Board 的系统强依赖队列服务，正常使用必须启动队列服务。

在宝塔面板「软件商店」→「系统工具」中找到 **Supervisor** 进行安装。

安装完成后点击「设置」→「添加守护进程」，按如下填写：

- **名称**：v2board
- **运行用户**：www
- **运行目录**：你的站点目录（如 `/www/wwwroot/api.example.com`）
- **启动命令**：`php artisan horizon`
- **进程数量**：1

开启自动启动，并将自动重启设置为始终重启（`autorestart=true`）。点击「确定」添加后，确认进程正常运行。

## 9. 后续更新

更新前先备份数据库、`.env`、`config/v2board.php` 和自定义主题，再执行：

```sh
cd /www/wwwroot/api.example.com
bash update.sh
```

`update.sh` 会更新当前分支的代码、安装锁定依赖、运行数据库更新，并通知 Horizon 退出，由 Supervisor 自动重新启动队列。

更新成功后，在宝塔面板重启对应的 PHP 服务；启用了 Webman 时，也要重启它的守护进程。如果脚本提示存在本地代码修改，先保存自己的修改再更新。

完整更新步骤见 [更新文档](update.md)。

## 开启 Webman（可选）

不启用 Webman 也可以正常使用项目。需要启用时，把「伪静态」改成以下内容：

```nginx
location /downloads {
}

location / {
    try_files $uri $uri/ @backend;
}

location ~ (/config/|/manage/|/webhook|/payment|/order|/theme/) {
    try_files $uri $uri/ /index.php$is_args$query_string;
}

location @backend {
    proxy_set_header Host              $http_host;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_pass http://127.0.0.1:6600;
}

location ~* \.(js|css)$ {
    expires 1h;
    error_log off;
    access_log /dev/null;
}
```

先在站点目录复制所选 PHP 的配置。以下以 PHP 8.5 为例：

```sh
cp /www/server/php/85/etc/php.ini webman-php.ini
```

在面板文件管理器中打开 `webman-php.ini`，将其中的 `disable_functions` 那一行替换为项目 `cli-php.ini` 中的同名配置行。这样可以保留宝塔的扩展配置，同时使用 Webman 所需的函数设置。不要修改 PHP 服务本身的配置。

确保站点目录允许 `www` 写入，Webman 需要在这里创建 PID 和日志文件；权限设置见下方常见问题。

在 **Supervisor** 按如下填写：

- **名称**：webman
- **运行用户**：www
- **运行目录**：你的站点目录（如 `/www/wwwroot/api.example.com`）
- **启动命令**：`php -c webman-php.ini webman.php start`
- **进程数量**：1

同样开启自动启动，并设置 `autorestart=true`。Horizon 队列进程仍需保留。

### 如果开启 Webman 后订阅地址显示为 127.0.0.1

检查 Nginx 的 `location @backend` 中是否保留了以下配置，保存后重载 Nginx：

```nginx
proxy_set_header Host $http_host;
```

### 注意

启用 Webman 后，修改代码需要在 Supervisor 中重启 Webman 才会生效。

## 常见问题

**Q：500 错误**

A：先检查 Redis 服务是否启动、PHP 扩展是否安装，以及数据库和 Redis 连接配置是否正确。Redis 设置了密码时，需要在 `.env` 中填写 `REDIS_PASSWORD`，保存后执行 `php artisan config:cache`，并重启 Horizon 和已启用的 Webman。

如果提示目录不可写，在站点目录执行：

```sh
chown -R www:www storage bootstrap/cache config public/theme
```

如果 Webman 提示无法创建 PID 或日志文件，再让 `www` 用户组可以写入站点根目录：

```sh
chgrp www .
chmod g+w .
```

检查日志时，查看宝塔的 PHP / Nginx 错误日志和项目的 `storage/logs` 目录。当前应用日志默认写入数据库，数据库日志写入失败时会回退到按日期生成的文件，不一定是固定的 `laravel.log`。

检查 PHP 扩展：

```sh
/www/server/php/85/bin/php -m | grep -iE 'redis|fileinfo|bcmath|pcntl|posix'
```

**Q：页面空白**

A：先查看 PHP / Nginx 错误日志。配置或视图缓存异常时，在站点目录执行：

```sh
php artisan config:clear
php artisan view:clear
php artisan config:cache
```

然后重启 PHP 服务和 Horizon；启用了 Webman 时，也要重启它。

**Q：队列不消费**

A：检查 Supervisor 中 v2board 进程是否在运行，查看进程日志。需要重新启动时执行：

```sh
php artisan horizon:terminate
```

然后确认 Supervisor 已自动拉起进程，或在面板中手动启动。

**Q：更新代码后功能异常**

A：在宝塔面板中重启 PHP 服务，并确认 Horizon 已重新启动；启用了 Webman 时，也要重启它。命令行执行 `opcache_reset()` 不能清除 PHP-FPM 的 OPcache。

**Q：PHP 版本不对**

A：本项目要求 PHP 8.4 起的兼容 8.x 版本。安装 PHP 8.5 后，需要同时切换站点版本和「PHP CLI」版本。计划任务或 Supervisor 仍使用旧版本时，将启动命令中的 `php` 换成所选版本的完整路径，例如 `/www/server/php/85/bin/php`。

> 本次完整运行验收使用 PHP 8.4、MySQL 5.7 和 Redis 7.4。PHP 8.5 已核对依赖版本约束，尚未完成同样的运行测试。
