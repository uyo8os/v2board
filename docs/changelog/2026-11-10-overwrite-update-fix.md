# 覆盖更新脚本修复

更新日期：2026-11-10。

运行 `bash update.sh` 即获取当前分支的 upstream，并执行 `git reset --hard` 覆盖服务器程序代码及本地提交，不再因本地代码修改或分叉提交停止。`fix` 继续跟踪 `origin/fix`。

`.env`、`config/v2board.php` 和 `config/theme` 下的运行配置不在 Git 跟踪内，正常保留。脚本不执行 `git clean`；阻挡远端跟踪路径的未跟踪文件仍可能被 Git 覆盖。

`bash update.sh --local` 跳过全部 Git 操作。依赖仍按锁文件安装，数据库升级、平台和安全检查保持原有流程。维护模式及服务重启步骤见 [更新文档](../update.md)。

Linux 隔离 Git 回归通过：覆盖未暂存/已暂存修改、本地提交、同文件冲突及文件模式，保留 `fix` 跟踪关系与运行配置，重新加载远端新脚本；`--local`、无效参数和获取远端失败的处理均符合预期。专项 `docker/tests/update-overwrite.sh` 已接入 `docker/tests/run.sh`；部署步骤使用替身验证调用流程。
