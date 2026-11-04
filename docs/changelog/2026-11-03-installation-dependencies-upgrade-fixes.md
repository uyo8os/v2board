# 更新日志

## 2026-11-03：安装、依赖更新与升级修复

**更新日期：** 2026-11-03

**更新范围：** `fix` 分支的安装与更新脚本、Composer 依赖、Laravel / PHP 兼容代码、数据库 SQL 导入、Workerman / Horizon 运行配置及 Docker 验收。

**更新目的：** 解决项目在目标服务器环境中的安装、依赖更新、升级和运行问题，保留原有业务功能。

### 一、框架与依赖调整

- 将 PHP 版本约束调整为 `^8.4`，框架从 Laravel `^8.0` 调整为 `^13.30`，本次实际锁定 **Laravel 13.39.0**。
- 调整 Symfony YAML、Firebase JWT、Tinker、Collision 和 PHPUnit 的依赖约束，使其与新框架兼容；测试工具升级至 PHPUnit 12。
- 将 Adapterman 和 Workerman 明确写入项目依赖，替代安装、更新过程中临时执行 `composer require` 的方式。
- 移除旧版 `fideloper/proxy`、`facade/ignition` 依赖，代理处理改用框架内置中间件。
- 新增 `composer.lock` 并取消其 Git 忽略规则，部署按锁文件安装生产依赖；依赖开发更新与服务器部署分开执行。
- 明确声明所需 PHP 扩展，将最低稳定性从 `dev` 调整为 `stable`。
- 删除原有 Composer 安全公告忽略配置，保留安全阻断、平台要求检查及安全审计。

本次升级框架的直接原因是：在 PHP 8.4 容器中保留 Composer 安全检查时，原 Laravel 8 与 Symfony YAML 4 的依赖约束被安全公告阻止解析。最终采用 Laravel 13，并实际验证其与 MySQL 5.7、Redis 7.4 的运行组合。

对照升级前的 `composer.json` 和本次新增的 `composer.lock`，依赖调整如下：

| 依赖 | 本次处理 | 本次锁定版本 |
|---|---|---|
| `laravel/horizon` | 保留 `^5.9.6` 约束，重新解析并锁定 | **5.50.1** |
| `laravel/tinker` | `^2.5` → `^3.0` | **3.0.2** |
| `stripe/stripe-php` | 保留 `^v14.9.0` 约束 | **14.10.1** |
| `guzzlehttp/guzzle` | 保留 `^7.4.3` 约束 | **7.16.1** |
| `php-curl-class/php-curl-class` | 保留 `^8.6` 约束 | **8.10.0** |
| `rybakit/msgpack` | 保留 `^0.9.1` 约束 | **0.9.2** |
| `paragonie/sodium_compat` | 保留 `^1.20` 约束 | **1.24.2** |
| `symfony/yaml` | `^4.3` → `^7.4` | **7.4.22** |
| `firebase/php-jwt` | `^6.3@stable \|\| ^7.0` → `^7.0` | **7.2.1** |
| `google/recaptcha` | 保留 `^1.2` 约束 | **1.5.2** |
| `fideloper/proxy` | 删除 `^4.4` 依赖，改用 Laravel 内置可信代理中间件 | — |
| `facade/ignition` | 删除 `^2.3.6` 依赖 | — |
| `nunomaduro/collision` | `^4.3` → `^8.0` | **8.9.5** |
| `phpunit/phpunit` | `^9.0` → `^12.0` | **12.5.43** |

以上版本取自 2026-11-03 提交的锁文件。升级前的仓库没有提交 `composer.lock`，因此无法确认旧服务器实际安装的版本，不能把“保留约束”直接理解为“没有升级”。后续依赖版本变更见 [2026-11-04 依赖升级日志](2026-11-04-dependency-updates.md)。

当时遗留的 `Facade\Ignition\Exceptions\ViewException` 引用已在 2026-11-04 的依赖升级中改为 Laravel 内置视图异常，恢复原有“主题渲染失败”提示。

### 二、全新安装修复

- 重写 `init.sh`，固定从项目目录执行，并在命令失败时立即停止，避免依赖安装失败后继续导入数据库。
- 新增公共 Composer 脚本，依次执行锁文件校验、生产依赖安装、平台检查和安全审计；系统没有 Composer 时，通过校验官方安装器摘要的方式安装 Composer 2。
- 修复“只要存在 `.env` 就拒绝安装”的判断，允许预先填写配置后安装；实际导入前检查数据库，非空数据库拒绝覆盖。
- 支持数据库端口配置、密码隐藏输入及 `--no-interaction --email=...` 自动安装方式，并校验管理员邮箱。
- 修复 `.env` 写入时的引号、反斜杠、美元符号及换行转义，保留已配置的 `APP_KEY`。
- 安装异常返回非零退出状态，不再吞掉数据库导入异常或输出安装成功。

### 三、项目更新脚本修复

- 移除强制重置到 `origin/master` 的行为，改为跟随当前分支的 upstream，通过快进合并更新；在 `fix` 分支跟踪 `origin/fix` 时继续更新 `fix`。
- 更新前检查跟踪文件的本地修改；存在修改时停止，不覆盖工作区内容。
- 不再删除锁文件或每次重新下载 Composer，也不再在部署过程中直接执行不确定的 `composer update`。
- 新增 `sh update.sh --local`，用于已经通过其他方式同步代码的部署。
- 在安装新依赖前清理旧配置、服务和包发现缓存，处理旧 Ignition provider 等缓存导致的启动失败。
- 缩小宝塔环境下的递归属主调整范围至 `storage` 和 `bootstrap/cache`。
- 明确 Horizon 退出信号与进程守护的关系；更新后提示重新加载 PHP-FPM、重启 Workerman，不再将“发出退出信号”描述为服务已经重启。

### 四、数据库安装与升级修复

- 新增 `App\Utils\SqlFile`，正确解析 SQL 注释、引号内分号、存储过程和 `DELIMITER`，替代原来移除换行后按分号直接拆分的方式。
- 安装、升级改用字符串 SQL 执行方式，修复旧 `DB::select(DB::raw(...))` 写法与新版框架的兼容问题。
- 升级只针对累计脚本中预期的历史 DDL 错误进行兼容处理；真实 SQL 错误返回失败并显示语句编号，不再忽略全部异常。
- 移除升级脚本中两处无条件清空 `v2_speed_limit_record` 的语句，防止重复升级丢失限速记录。
- 用测试数据验证升级与重复升级后，用户、余额、佣金、套餐、订单、节点、限制记录、限速记录、`APP_KEY` 和站点配置保持一致。

本次没有新增业务数据表或进行数据库结构改造，继续沿用项目原有安装 SQL 和累计升级 SQL。

### 五、PHP 8.4 与运行兼容修复

- 更新可信代理请求头配置及维护模式中间件，使其适配新框架。
- 订阅用户模型通过请求的用户解析器传递，修复新版 Symfony 不允许把模型对象写入请求参数的问题，并同步调整普通订阅、App 配置和订阅审计中的读取方式。
- 将 MySQL 日志处理器适配至 Monolog 3 的 `LogRecord`，修复日志记录签名和日期时间读取方式。
- 为 Sing-box、旧版 Sing-box 和 v2RayTun 的可空参数补充显式类型，消除 PHP 8.4 的隐式可空参数弃用提示。
- Workerman 支持通过 `WEBMAN_LISTEN`、`WEBMAN_WORKERS` 配置监听地址和进程数，便于容器运行；默认仍使用本地监听地址。
- CLI 配置不再重复硬编码加载 Redis / igbinary 扩展，也不再尝试向配置中写入 `pcntl.so`，扩展由服务器常规配置加载。
- 补齐 Horizon 在 production 等环境下的原业务队列消费者，确保订单、流量、限速、邮件等队列与审计队列共同运行。

上述订阅请求调整属于框架兼容修改；原 JWT 签发、Redis 会话认证规则和业务权限逻辑未重构。

### 六、测试与文档补充

- 新增 PHP 8.4 Dockerfile、独立 Compose 验收环境、Nginx 测试配置及可重复执行的安装 / 升级 / 运行测试脚本。
- 验证默认 Git 更新路径、重复升级、拒绝覆盖本地修改、拒绝向非空库重装，以及 Composer / SQL 失败时停止更新。
- 适配 PHPUnit 12 的配置、引导和数据提供器，并新增 SQL 解析回归测试。
- 补充 [安装与升级说明](../../docker/README.md)、[Docker 验收记录](../../docker/ACCEPTANCE.md)，并更新项目 README 的运行要求。
- 补充 [宝塔安装文档](../install.md)和 [更新文档](../update.md)，说明从 `master` 首次迁移到 `fix`、刷新配置和队列、重启服务及恢复网站的步骤。

### 七、本次实际验证结果

| 验证项目 | 结果 |
| --- | --- |
| 全新安装、Composer 依赖安装与依赖更新 | 通过 |
| 项目升级、重复升级及所测业务数据保留 | 通过 |
| Laravel 启动、包发现及配置缓存 | 通过 |
| Nginx、PHP-FPM、Workerman、Horizon | 通过 |
| MySQL 5.7.44、Redis 7.4.8 | 通过 |
| Composer 平台检查及安全审计 | 通过，未发现安全公告 |
| PHPUnit 回归测试 | **77 项测试、408 条断言通过** |
| 应用与类库 PHP 语法检查 | 通过，未发现 PHP 8.4 弃用提示 |

运行验收通过 Nginx 分别请求 PHP-FPM 和 Workerman，检查了原首页、登录 / JWT、连续请求的用户隔离、套餐和订单读取、多种订阅格式、风控假入口、App 配置、验证码及节点用户接口。Horizon 实际完成了余额支付续费订单、流量任务、邮件日志传输和订阅审计写入，未出现失败任务。

以上为本地 Docker 中目标版本组合的测试结果。升级数据测试使用原数据库结构的夹具及旧包缓存夹具，未安装已被安全阻断的 Laravel 8 依赖；未连接真实服务器，未执行外部支付网关、真实 SMTP 或 Telegram 验证，也尚未部署到真实服务器。

### 八、更新后的使用方式

```sh
# 全新安装
sh init.sh

# 已有站点：跟随当前分支的 upstream 更新
sh update.sh

# 已手动同步代码：安装锁定依赖并执行项目升级
sh update.sh --local

# 在独立环境复现完整 Docker 验收
sh docker/tests/run.sh
```

服务器部署前应准备 PHP 8.4 或兼容的更高 8.x 版本，并备份数据库、`.env`、站点配置和自定义主题；成功更新后重新加载 PHP-FPM，并由进程守护拉起 Horizon、重启 Workerman。本次完整运行验收使用 PHP 8.4。具体步骤见 [安装与升级说明](../../docker/README.md)。
