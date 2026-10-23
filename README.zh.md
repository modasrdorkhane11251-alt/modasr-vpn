<div align="center">

# MODASR Bot
### 自动化 VPN 服务销售平台 —— 从支付到配置交付

**一套销售引擎，三大即时通讯平台，一个数据库**

[![Telegram](https://img.shields.io/badge/Telegram-@modasrpanel-2CA5E0?style=flat-square&logo=telegram&logoColor=white)](https://t.me/modasrpanel)
[![Rubika](https://img.shields.io/badge/Rubika-@modasrpanel-FF6B35?style=flat-square)](https://rubika.ir/joing/BBIHBDHFD0NAYIDLGBSQYPOZBROKCTBQ)
[![Bale](https://img.shields.io/badge/Bale-@modasrpanel-1F8A70?style=flat-square)](https://ble.ir/modasrpanel)
[![PHP](https://img.shields.io/badge/PHP-8.1+-777BB4?style=flat-square&logo=php&logoColor=white)]()

[فارسی](README.md) · [English](README.en.md) · [Русский](README.ru.md) · [中文](README.zh.md)

</div>

---

## 概述

MODASR Bot 是面向面板型订阅服务（Marzban 及兼容面板）的端到端销售引擎。完整的销售闭环——浏览套餐、选择节点位置、支付、在面板上自动开户、交付配置——全程无需人工干预。

架构原则：

1. **一个数据库，任意多个前端** —— Rubika 用户与 Telegram 用户共享同一套表；在一个平台购买的服务，可在其他平台管理。
2. **彻底的传输层抽象** —— 业务逻辑从不直接调用通讯平台 API；`platform/` 层负责翻译每一个请求。
3. **真正的可运维性** —— 监控定时任务、自动备份、结构化报表，以及一条命令完成的安装器。

## 架构

```
   入站 Webhook（index.php?platform=telegram|bale|rubika）
                        │
      归一化层（platform/）→ 标准 Telegram Update 结构
                        │
        业务核心（index.php / admin.php）
                        │
      ┌─────────────────┼──────────────────┐
      │                 │                  │
  MySQL（共享）    面板驱动           报表引擎
                  （Marzban 等）    （Telegram 话题）
```

出站消息按用户来源平台路由；管理报表无论事件发生在哪个平台，都统一发送到 Telegram。

## 功能特性

- **销售** —— 分类目录、节点位置、流量/时长套餐、试用账号、一键续费、订阅链接、分级定价（用户 / 代理 / 高级代理）、折扣码
- **财务** —— 跨平台共享的内置钱包；卡转账配收据审核队列（收据照片甚至可从 Rubika 转发给 Telegram 管理员）；在线支付网关（ZarinPal 等）；代理佣金结算
- **运维** —— Telegram 话题式报表群：**自动创建 10 个话题**，每个事件附带平台标签；面板健康监控；到期提醒；数据库 nightly 备份；Web 管理面板；Telegram 小程序

## 环境要求

| 组件 | 最低 | 推荐 |
|---|---|---|
| 服务器 | 1 核 / 1 GB | 2 核 / 4 GB |
| 操作系统 | Ubuntu 20.04 | Ubuntu 24.04 |
| PHP | 8.1 | 8.2+ |
| 数据库 | MySQL 5.7 / MariaDB 10.3 | MySQL 8 |
| 域名 | A 记录指向服务器 IP | — |

## 安装

```bash
bash <(curl -Ls https://raw.githubusercontent.com/modasrdorkhane11251-alt/modasr-vpn/main/install.sh)
```

安装器以交互方式询问域名、机器人 Token（Telegram 必填；Bale/Rubika 可选）和管理员 ID，随后自动签发 TLS 证书、注册并校验全部 Webhook（包括 Rubika 的两个 endpoint），并安装服务器管理命令 `modasr`：

```bash
modasr install    # 安装 / 重新安装
modasr backup     # 数据库备份并发送给管理员
modasr update     # 从仓库更新
```

## 配置

`config.php` 仅保存在服务器上；仓库中均为占位符：

```php
$APIKEY = '...';                 // 主机器人 Token
$PLATFORM_TOKENS = [
    'telegram' => '...',
    'bale'     => '...',
    'rubika'   => '...',
];
$REPORTS_BOT_TOKEN = '...';      // 报表机器人（默认使用 Telegram Token）
```

## 多平台层

每个入站 Update 在到达核心之前都会被归一化为标准 Telegram 结构：

- **Bale** —— 与 Telegram 兼容的 API；直通。
- **Rubika** —— 完整翻译：文本消息、内联键盘（button_id → callback_data）、附件（`file` → photo/document，并解析 CDN 下载链接）、GUID 映射（`u0…` → `b0…`，持久缓存）、可靠的消息编辑（删除 + 重发，以绕过 Rubika 客户端的键盘缓存）。

每个用户的 `platform` 字段决定回复与定时通知的路由。

## 话题式报表群

1. 创建一个启用**话题（Topics）**的 Telegram 超级群，并将机器人设为管理员。
2. 在机器人中：`/panel` →「📣 报表」→「选择报表群」→ 发送群 ID。
3. 机器人自动创建 10 个话题：购买、支付、错误、试用账号、佣金、夜间报表、定时任务、备份等。

每条报表都带有来源标签：`📱 平台：Telegram | Bale | Rubika`

## 项目结构

```
modasr-vpn/
├── index.php            # 机器人核心 —— 用户流程
├── admin.php            # 机器人内管理面板
├── botapi.php           # API 层 + 出站平台路由
├── platform/            # 多平台抽象层
├── panels/              # 服务面板驱动
├── cronbot/             # 定时任务调度与作业
├── lang/                # fa · en · ru · zh
├── panel/               # Web 管理面板
├── app/                 # Telegram 小程序
└── install.sh           # 安装器 + 服务器管理工具
```

## 安全

- Token 仅保存在服务器端的 `config.php`；仓库不含任何密钥。
- Telegram Webhook IP 校验，支持 Cloudflare（`CF-Connecting-IP`）及 Webhook 密钥。
- 若任何 Token 泄露：立即吊销，然后更新 `config.php`。

## 故障排查

| 症状 | 原因 | 解决方案 |
|---|---|---|
| Rubika 无响应 | endpoint 未注册 | 重新运行 `modasr install` |
| Rubika 按钮过期不更新 | 客户端缓存 | 内置删除/重发机制——保持代码为最新 |
| 下单流程缓慢 | 服务面板离线 | 检查面板健康状态 |
| Telegram Webhook 被拒 | IP/代理 | 检查 `getWebhookInfo` 与 Cloudflare 配置 |

---

<div align="center">
<b>MODASR</b> · 官方频道：
<a href="https://t.me/modasrpanel">Telegram</a> ·
<a href="https://ble.ir/modasrpanel">Bale</a> ·
<a href="https://rubika.ir/joing/BBIHBDHFD0NAYIDLGBSQYPOZBROKCTBQ">Rubika</a>
</div>
