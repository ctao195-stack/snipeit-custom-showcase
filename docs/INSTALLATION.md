# 安装与验证

## 兼容范围

`overlay/` 和 `patches/` 只以官方 Snipe-IT `v8.1.18`、build `18876` 为基线。
其他版本必须逐文件移植，不能直接覆盖。

## 测试环境安装

```bash
git clone https://github.com/grokability/snipe-it.git
cd snipe-it
git checkout v8.1.18
git apply /path/to/snipeit-custom-showcase/patches/snipe-it-v8.1.18-custom.patch
composer install --no-dev --prefer-dist
php artisan migrate --force
php artisan optimize:clear
```

也可以把 `overlay/` 内容按相对路径复制到相同版本的测试源码，但应用补丁更容易
先查看冲突和差异。

## 上线前检查

1. 备份数据库、`.env`、上传目录和当前源码。
2. 在独立数据库执行迁移并记录迁移结果。
3. 验证借出、归还、延期、取消、移交、交换、代管和退租。
4. 使用测试 Webhook 验证通知，确认不会重复发送。
5. 验证图片凭证的格式、大小、权限和历史访问。
6. 运行计划任务的 `--dry-run` 模式，再启用真实提醒。
7. 检查队列、计划任务、PHP 日志和 Web 服务器错误日志。

## 自动化配置

```powershell
Copy-Item automation\.env.example automation\.env
# 编辑 automation\.env，并把真实 Token 仅保存在服务器本地。
py -3.11 -m pip install -r automation\公共配置\requirements-snipeit-excel.txt
```

不同脚本仍保留 Windows 示例路径，部署时应统一调整到自己的自动化根目录。

## 回滚原则

- 上线前保存源码和数据库的同时间点备份。
- 代码回滚与数据库迁移回滚分开评估。
- 已产生的待归还、代管和附件记录不可只靠删除代码处理。
- 出现 500 错误时先保留日志和现场，再回滚到已验证版本。
