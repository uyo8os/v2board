-- 已部署实例可单独执行本文件，只新增每日模式开关，保留现有规则与限速记录。
ALTER TABLE `v2_speed_limit_rule`
ADD `daily_enabled` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否按每日统计并于次日0点解除限速' AFTER `name`;
