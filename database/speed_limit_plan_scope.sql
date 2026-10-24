-- 已部署实例可单独执行本文件，保留现有规则与限速记录。
ALTER TABLE `v2_speed_limit_rule`
ADD `plan_limit_enabled` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否仅对所选订阅生效' AFTER `name`;

ALTER TABLE `v2_speed_limit_rule`
ADD `plan_ids` text DEFAULT NULL COMMENT '适用订阅ID列表，JSON数组，开关关闭时忽略' AFTER `plan_limit_enabled`;
