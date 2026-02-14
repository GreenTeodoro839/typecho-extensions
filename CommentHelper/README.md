## 描述
CommentHelper 是一个为 Typecho 博客系统设计的插件，旨在增强评论管理体验。它提供以下功能：

1. **ServerChan 通知**：通过 ServerChan 实现新评论的微信实时通知。
2. **AI 评论审核**：利用 OpenAI 兼容的 API 对评论内容进行分析和审核，检测垃圾评论或不当内容。

## 功能特点
- **实时通知**：新评论发布后立即通过微信通知博主。
- **AI 审核**：自动审核评论内容，提升博客评论质量。
- **自定义设置**：可在 Typecho 后台直接配置 ServerChan 和 AI 审核相关设置。

## 安装步骤
1. 下载插件并将其放置在 Typecho 安装目录下的 `usr/plugins/` 文件夹中。
2. 将插件文件夹重命名为 `CommentHelper`。
3. 登录 Typecho 后台，激活插件。

## 配置方法
1. **ServerChan 设置**：
   - 前往 [ServerChan](https://sct.ftqq.com/) 或 [ServerChan³](https://sc3.ft07.com/) 获取您的 SCKEY。
   - 在插件设置中填写 SCKEY。

2. **AI 审核设置**：
   - 从 OpenAI 兼容的服务商处获取 API 密钥。
   - 在插件设置中填写 API 密钥，并配置审核阈值。

## 使用说明
- 插件激活后，将自动为新评论发送通知，并使用 AI 审核评论内容。
- 您可以在 Typecho 后台的 "CommentHelper" 设置页面中管理插件配置。

## 系统要求
- Typecho 版本 1.2 或更高。
- ServerChan 账号（用于通知）。
- OpenAI 兼容的 API 密钥（用于 AI 评论审核）。

## 支持
如果您在使用过程中遇到问题或有功能需求，请通过插件的 GitHub 仓库提交 Issue，或直接联系开发者。

## 许可证
本插件基于 MIT 许可证开源。详情请参阅 LICENSE 文件。