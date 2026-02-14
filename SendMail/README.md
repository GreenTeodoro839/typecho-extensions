# SendMail - Typecho 评论回复邮件提醒插件

当用户的评论被回复时，自动通过 SMTP 发送邮件通知。

## 功能特性

- 📧 评论被回复时自动发送邮件通知
- 🏷️ 博主回复时显示特殊标识
- 🎨 支持自定义 HTML 邮件模板
- 🔒 支持 SSL / TLS 加密连接
- 📝 调试模式，方便排查问题
- 🚫 纯 PHP socket 实现，不依赖第三方库
- 🔄 与 CommentHelper AI 审核插件无缝协作（可选）

## 工作原理

### 评论审核流程

```
用户评论 / 回复
  ↓
Typecho 核心处理 + 可选的 CommentHelper AI 审核
  ↓
评论状态确定（approved / waiting / spam）
  ↓
SendMail 异步检查状态
  └─ approved → 发送邮件 ✅
  └─ waiting/spam → 跳过（留给管理员处理）
```

### 核心特点：不依赖 CommentHelper

SendMail **完全独立运作**，CommentHelper 是可选的：

| 是否装 CommentHelper | 新评论状态来源 | 发邮件条件 |
|------------------|------------|---------|
| ❌ 否 | Typecho 博客设置（是否需要审核） | status = 'approved' |
| ✅ 是 | CommentHelper AI 审核结果 | status = 'approved' |

无论哪种情况，**只要评论最终审核通过（approved），邮件就会发送**。

### 发送规则

| 场景 | 是否发送 |
|------|---------|
| 用户评论被其他用户回复 | ✅ 发送 |
| 用户评论被博主回复 | ✅ 发送（标注博主标签） |
| 博主评论被回复 | ❌ 不发送 |
| 直接留言（非回复） | ❌ 不发送 |
| 用户未填写邮箱 | ❌ 不发送 |
| 自己回复自己 | ❌ 不发送 |
| 评论状态为 `waiting`（待审核） | ❌ 不发送 |
| 评论状态为 `spam`（垃圾） | ❌ 不发送 |

## 与 CommentHelper 的协作

## 场景 1：仅使用 SendMail（无 AI 审核）

```
新评论 → Typecho 根据设置自动分配 approved/waiting
        ↓
        SendMail 异步检查，符合条件则发邮件
        ↓
        管理员手动审核 waiting 评论 → SendMail 补发邮件
```

**适合：** 小流量博客，不需要 AI 自动审核

## 场景 2：同时使用 SendMail + CommentHelper

```
新评论 → CommentHelper AI 审核（自动判断 approved/spam）
        ↓
        SendMail 异步检查，符合条件则发邮件
        ↓
        如果 AI 审核失败（评论卡 waiting）
        → 管理员手动通过 → SendMail 补发邮件
```

**优势：**
- CommentHelper 自动拦截大部分垃圾评论
- SendMail 在 AI 审核失败时补救（发邮件）
- 两个插件解耦，互不干扰

## 提醒：waiting 评论不会发邮件

如果评论：
- AI 审核拒绝 → status = `spam` → 不发邮件 ✓
- AI 审核故障 → status = `waiting` → 不发邮件 ✓
- 管理员标记为垃圾 → status = `spam` → 不补发 ✓

**只有以下情况会发邮件：**
1. 新评论直接通过审核（无需人工）
2. 管理员手动将 `waiting` 状态改为 `approved`

## 安装

1. 将插件文件夹重命名为 `SendMail`
2. 上传到 Typecho 的 `usr/plugins/` 目录
3. 在后台「控制台 → 插件」中启用 SendMail
4. 点击「设置」配置 SMTP 信息

目录结构：
```
usr/plugins/SendMail/
├── Plugin.php        # 主插件文件
├── Smtp.php          # SMTP 发送类
├── template.html     # 默认邮件模板
└── README.md         # 说明文档
```

## 配置说明

### SMTP 服务器设置

| 配置项 | 说明 | 示例 |
|--------|------|------|
| SMTP 服务器地址 | 邮件服务商的 SMTP 地址 | `smtp.qq.com` |
| SMTP 端口 | 服务器端口号 | `465`（SSL）/ `587`（TLS） |
| 加密方式 | SSL / TLS / 无加密 | SSL |
| SMTP 用户名 | 登录用户名（通常是邮箱） | `example@qq.com` |
| SMTP 密码 | 密码或授权码 | QQ邮箱需使用授权码 |

### 常见邮箱配置

**QQ 邮箱**
- 服务器：`smtp.qq.com`，端口：`465`，加密：`SSL`
- 需要在 QQ 邮箱设置中开启 SMTP 并获取授权码

**163 邮箱**
- 服务器：`smtp.163.com`，端口：`465`，加密：`SSL`
- 需要开启 SMTP 并设置客户端授权码

**Gmail**
- 服务器：`smtp.gmail.com`，端口：`587`，加密：`TLS`
- 需要开启"应用专用密码"

### 邮件模板变量

在邮件标题和正文模板中可使用以下变量：

| 变量 | 说明 |
|------|------|
| `{blogName}` | 博客名称 |
| `{blogUrl}` | 博客地址 |
| `{postTitle}` | 文章标题 |
| `{postUrl}` | 文章链接 |
| `{author}` | 回复者昵称 |
| `{authorTag}` | 博主标签（非博主为空） |
| `{replyContent}` | 回复内容 |
| `{originalAuthor}` | 原评论者昵称 |
| `{originalContent}` | 原评论内容 |
| `{year}` | 当前年份 |

## 调试

开启插件设置中的「调试模式」后，日志将记录到：
```
usr/plugins/SendMail/logs/sendmail.log
```

日志包含：
- 邮件是否发送成功
- 评论状态不符合条件时的原因
- SMTP 连接和认证错误

## 常见问题

### Q: 为什么评论通过了但没收到邮件？

**可能原因：**
1. SMTP 配置错误 → 查看日志
2. 被回复的评论作者没填邮箱
3. 被回复的评论作者是博主
4. 自己回复自己
5. 评论是直接留言（不是回复）

### Q: 为什么 waiting 评论被通过后没有邮件？

如果您需要补发邮件，需要将状态从 `waiting` **改为** `approved`，不是保持 `approved`（避免重复发送）。

### Q: 能和其他评论审核插件一起用吗？

可以，只要这些插件最终修改的是 `status` 字段即可。SendMail 只关心最终状态，不关心审核来源。

## 异步发送原理

SendMail **不使用消息队列或数据库任务表**，而是利用 PHP 的 `register_shutdown_function()` 实现异步：

1. 用户提交评论 → 立即返回响应
2. HTTP 响应发送给用户
3. PHP shutdown 阶段 → 后台发送邮件
4. 用户完全感受不到延迟

优点：
- 低开销，无需额外基础设施
- 评论和邮件发送逻辑紧耦合，无重复/遗漏
- 重启服务器无影响（没有待发送队列）

## 要求

- Typecho 1.2+
- PHP 5.4+（需开启 `fsockopen`）
- 支持 SSL/TLS（通常默认开启）

## License

MIT License
