# Typecho MCP

一个运行在 Typecho 1.3 内部的原生 MCP（Model Context Protocol）服务插件。
它不需要 Node.js、Docker 或额外的常驻进程，可让支持 Streamable HTTP 的
AI 客户端安全地读取和管理博客内容。

## 功能

- 查看站点信息、文章列表和分类
- 搜索并读取文章正文、分类、标签和附件
- 创建草稿或文章
- 修改正文、状态、分类、标签和评论设置
- 查看单个附件的详细信息
- 上传图片与普通附件
- 永久删除指定附件（需要显式确认）
- 读取和修改 Handsome 主题的头图、摘要等文章字段
- Bearer Token 鉴权
- 文件类型沿用 Typecho 全局附件白名单
- 不提供文章删除工具

## 环境要求

- Typecho 1.3.0+
- PHP 8.0+
- HTTPS（远程连接时强烈建议）

## 安装

1. 下载本仓库，将 `TypechoMcp` 目录复制到 Typecho 的 `usr/plugins/`。
2. 登录 Typecho 后台，在插件管理中启用 **Typecho MCP**。
3. 打开插件设置，保存并妥善保管自动生成的访问令牌。
4. MCP 地址为：

   ```text
   https://你的域名/action/typecho-mcp
   ```

5. 客户端请求头：

   ```text
   Authorization: Bearer <插件设置中的访问令牌>
   ```

## MCP 客户端配置示例

不同客户端的配置格式可能不同，核心信息如下：

```json
{
  "name": "typecho",
  "url": "https://example.com/action/typecho-mcp",
  "transport": "streamable-http",
  "headers": {
    "Authorization": "Bearer YOUR_TOKEN"
  }
}
```

## 工具

| 工具 | 作用 |
| --- | --- |
| `get_site_info` | 查看 Typecho 版本、附件白名单和数量统计 |
| `list_posts` | 列出或搜索文章 |
| `get_post` | 读取完整文章、主题字段和关联附件 |
| `list_categories` | 查看文章分类 |
| `create_post` | 创建草稿或文章，默认保存为草稿 |
| `update_post` | 修改文章内容和设置 |
| `list_attachments` | 查看附件 |
| `get_attachment` | 按 cid 查看单个附件详情 |
| `upload_file` | 通过 Base64 上传图片或文件 |
| `delete_attachment` | 永久删除附件文件及数据库记录，要求 `confirm=true` |

## Handsome 主题文章字段

`get_post` 会在 `theme` 中返回以下设置，`create_post` 和 `update_post`
也接受同名参数：

- `custom_summary`：手动摘要
- `hero_display`：`default` / `both` / `index_only` / `post_only` / `none`
- `hero_image`：大头图 URL
- `hero_image_small`：小头图 URL
- `hero_image_credit`：头图版权说明
- `hero_style`：`default` / `large` / `small` / `picture`
- `badge_style`：无头图时的个性化标徽
- `badge_emoji`：无头图时的 Emoji
- `outdated_notice`：文章过时提醒
- `reprint_rule`：转载规则
- `mathjax`：单篇 MathJax 设置
- `markdown_parser`：前台 Markdown 解析器

典型的头图流程是先调用 `upload_file`，再把返回的 `url` 写入
`hero_image` 或 `hero_image_small`。

## 安全说明

- 访问令牌只存储在 Typecho 插件配置中，请勿写入公开仓库或分享给他人。
- 远程使用时应启用 HTTPS。
- 默认单文件上传上限为 10 MB，可在插件设置中调整，最高 50 MB。
- 插件不提供文章删除工具。
- 删除附件时必须同时传入附件 `cid` 和 `confirm=true`；该操作不可撤销。
- `create_post` 默认创建草稿，是否发布应由用户明确决定。

## 许可证

本插件遵循仓库根目录的 MIT License。
