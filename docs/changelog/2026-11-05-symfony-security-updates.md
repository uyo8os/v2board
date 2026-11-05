# Symfony 安全依赖更新

**更新日期：** 2026-11-05

在 A11 Docker 验收准备阶段，Composer audit 检出锁文件中 4 个 Symfony 组件合计 7 条安全公告，阻断了完整验收。保留 Composer 安全检查，按现有约束更新锁文件中的组件：

原公告包括 `http-foundation` 的 `PKSA-24ph-xkrh-y79j`、`PKSA-pr6q-2w5s-kzzf`、`PKSA-nxjs-s6hn-vb6m`、`PKSA-b2gg-vk1g-6d5q`，`http-kernel` 的 `PKSA-3gxv-7xhq-mgmz`，`routing` 的 `PKSA-y8c5-1b7f-r1gj`，以及 `yaml` 的 `PKSA-nktx-f1n1-b4yd`。

| 组件 | 更新前 | 更新后 |
| --- | --- | --- |
| `symfony/http-foundation` | 8.1.10 | 8.1.11 |
| `symfony/http-kernel` | 8.1.10 | 8.1.11 |
| `symfony/routing` | 8.1.10 | 8.1.11 |
| `symfony/yaml` | 8.1.10 | 8.1.11 |

`composer.json` 和平台要求未变化，Laravel 继续使用 13.40.0。新的 `composer.lock` SHA-256 为 `b66055f78a44221fbd4875e954be15aaca29944b0531813fb403db286ed7ad7b`。`composer audit --locked` 未报告安全公告，`composer validate --strict --no-check-publish` 通过。审计结果只代表执行时已知的公告。

在新隔离项目 `v2board-a11-verified-20261105` 运行完整 `docker/tests/run.sh`，退出码为 0；使用 PHP 8.4.27、Laravel 13.40.0、MySQL 5.7.44、Redis 7.4.8、Nginx 1.30.6，完成安装、升级和运行验收。PHPUnit 为 **201 项测试、1496 条断言**，A11 MySQL 和文件日志专项 **12 项检查**通过。完整 Composer update 与 audit 后，验收脚本恢复交付锁文件并重新 install，再进行平台检查、audit、测试与运行验收；容器内交付锁文件的 SHA-256 与上方一致。

验收过程中发现旧测试脚本未正确进入和退出维护模式，现已按真实升级要求调整，并验证非维护模式下数据库升级拒绝且保留数据；生产升级的安全要求未放宽。详细结果、历史失败和测试边界见 [Docker 验收记录](../../docker/ACCEPTANCE.md)。

返回[分支变更记录](../branch-changes.md)。
