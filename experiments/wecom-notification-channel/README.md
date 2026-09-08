# 企业微信 Notification Channel 实验

该实验把企业微信 Markdown 转换抽象为 Laravel Notification Channel，让借出、归还、
配件、许可证和申请类通知复用同一发送入口。

它与稳定覆盖层中的 `ActionlogWechatObserver` 属于两种实现路径。评估时只能选择一条
发送链路，否则同一次操作可能出现重复通知。

配置只接受环境变量中的 Webhook，不要在代码、数据库导出或截图中公开真实地址。
