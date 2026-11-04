# 安装与升级

运行目标为 64 位 Linux、PHP 8.4.1+（兼容 8.x）、Nginx、MySQL 5.7、Redis 7.4。Horizon / Workerman 需要 CLI 的 `pcntl`、`posix` 扩展；同时需要 `bcmath`、`curl`、`mbstring`、`pdo_mysql`、`redis`，以及框架依赖要求的 DOM / XML 等扩展。Dockerfile 包含完整测试环境；CLI 和 FPM 的 PHP 版本及扩展应一致。

## 全新安装

准备空数据库及可用 Redis，执行 `sh init.sh`，按提示输入数据库连接和管理员邮箱。也可预先复制、填写 `.env.example` 为 `.env` 后执行：

```sh
sh init.sh --no-interaction --email=admin@example.com
```

脚本使用 Composer 安装锁文件中的生产依赖，检查平台及安全公告，再导入数据库。数据库非空时会拒绝安装；复制过 `.env` 不再被误判为已经安装。数据库地址、端口、密码会写入 `.env`，管理员密码随机生成并输出，请妥善保存。失败返回非零状态，数据库部分导入失败时应检查报错并恢复空库后重试。

确保运行 PHP 的用户能写入 `storage`、`bootstrap/cache` 和 `config/theme`；站点后台保存配置还需要写入 `config/v2board.php`。Nginx 根目录指向 `public`，PHP 请求交给 PHP-FPM。配置定时任务每分钟执行 `php artisan schedule:run`，进程守护负责启动 `php artisan horizon`。

Workerman 可使用 `php -c cli-php.ini webman.php start`。该配置仅用于 Workerman；PHP-FPM 和 Horizon 使用常规 PHP 配置。扩展由系统的 `conf.d` 加载，不再硬编码 `redis.so`、`igbinary.so` 或尝试写入不存在的 `pcntl.so`。默认监听 `127.0.0.1:6600`，容器中通过 `WEBMAN_LISTEN` 设置内部监听地址，`WEBMAN_WORKERS` 可限制进程数。

## 已有站点升级

升级前备份数据库、`.env`、`config/v2board.php` 及自定义主题，先确保 CLI / FPM 满足 PHP 8.4.1+ 的要求，并使用 Composer 2.11。

```sh
git branch --show-current
git rev-parse --abbrev-ref --symbolic-full-name '@{upstream}'
sh update.sh
```

当前 `fix` 应跟踪 `origin/fix`；不要切回 `master`。脚本只允许快进合并，存在跟踪文件修改、没有 upstream、依赖失败或真实 SQL 错误都会停止。已由其他方式同步到目标代码的部署使用 `sh update.sh --local`。不删除锁文件，不在部署时执行不确定的依赖更新，不关闭安全检查。

升级保留 `APP_KEY`、原认证逻辑及 Redis 前缀。旧 Laravel 的配置 / 包发现缓存会在加载新依赖前清除；SQL 导入支持注释、引号、存储过程及 `DELIMITER`，只跳过累计脚本中已应用的历史 DDL，不再吞掉全部错误。重复升级不会清空限速记录。本次未增加业务表或进行认证、数据库结构改造。

成功后 Horizon 收到退出信号，由现有 Supervisor / systemd / PM2 等守护程序拉起；需重新加载 PHP-FPM 并重启 Workerman，让长驻进程加载新代码。脚本不会声称队列已经重启。生产环境也会消费订单、流量、限速、邮件等原业务队列和独立审计队列。

## 依赖开发更新

在同样的 Linux / PHP 8.4 扩展环境执行：

```sh
composer update
composer validate --strict
composer check-platform-reqs
composer audit
vendor/bin/phpunit
```

将 `composer.json` 和 `composer.lock` 一起提交。部署继续使用 `composer install --no-dev`。本分支没有安全公告忽略规则，也没有关闭安全阻断或跳过平台要求的配置。

## 可重复 Docker 验收

从仓库根目录运行，需要 Docker Compose：

```sh
sh docker/tests/run.sh
```

脚本在独立 Compose 数据卷中复制源代码，不修改宿主工作区、宿主 `.env` 或 Git 历史。它依次构建官方 PHP 镜像，启动 MySQL 5.7 / Redis 7.4，执行全新安装、真实本地 Git upstream 升级、重复升级、失败路径、Composer install / update / audit、PHPUnit，最后启动 Nginx / FPM / Workerman / Horizon 执行业务请求。

默认项目名为 `v2board-fix-acceptance`。重复全新验收时使用新的项目名；如端口已占用，一起更换测试端口：

```sh
COMPOSE_PROJECT_NAME=v2board-fix-check2 \
V2BOARD_TEST_HTTP_PORT=18882 V2BOARD_TEST_WORKER_PORT=18883 \
sh docker/tests/run.sh
```

Windows 可在 Git Bash 执行上述命令。脚本保留测试容器和日志，日志位于应用数据卷的 `storage/logs`；测试管理员密码只保留在隔离卷的安装日志中。默认 FPM 入口为 `http://127.0.0.1:18880`，Workerman 入口为 `http://127.0.0.1:18881`。邮件使用日志传输，不联系外部邮箱；外部支付网关、真实 SMTP 和 Telegram 不在本地测试中发送请求。

默认测试 PHP 8.4。测试 PHP 8.5 时，设置 `V2BOARD_TEST_PHP_VERSION=8.5`，并使用新的项目名和端口；例如在 Git Bash 执行：

```sh
V2BOARD_TEST_PHP_VERSION=8.5 COMPOSE_PROJECT_NAME=v2board-check85 \
V2BOARD_TEST_HTTP_PORT=18886 V2BOARD_TEST_WORKER_PORT=18887 \
sh docker/tests/run.sh
```
