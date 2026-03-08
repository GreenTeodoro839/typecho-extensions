## 简介

本项目是一个基于 Typecho 和 **Handsome** 主题 的插件，支持以下功能：

- 在编辑器自定义摘要旁边增加 AI 生成摘要的按钮
- 在文章正文开头显示 AI 摘要（支持引用样式和自定义样式）来自 https://idealclover.top/archives/636/
- 后台管理面板：查看所有文章的摘要状态，支持单篇/批量生成

## 使用方法

1. 下载插件并将其放置在 Typecho 安装目录下的 `usr/plugins/` 文件夹中。

2. 将插件文件夹重命名为 `AISummary`。

   确保文件结构如下：

   ```
   Typecho 插件目录
   └── AISummary
       ├── Action.php
       ├── Plugin.php
       └── manage-summaries.php
   ```

3. 登录 Typecho 后台，激活插件并在设置中填写 API 地址和 Key。

4. 在 **管理 → 摘要** 中查看和批量生成文章摘要。

5. 如需在正文中显示摘要，在插件设置中将「正文摘要显示样式」改为引用或自定义样式。

## 打字机形式摘要输出

选择使用自定义摘要样式

正文摘要模板

```
<div class="summary-header"><strong>AI 摘要：</strong><span class="summary-text" id="aisummary-typewriter"></span><span class="summary-full" style="display:none">{{text}}</span><span class="toggle-text" onclick="toggleSummary()">展开</span></div><script>(function(){var el=document.getElementById('aisummary-typewriter');if(!el)return;var src=document.querySelector('.summary-full');if(!src)return;var full=src.textContent,container=document.querySelector('.aisummary'),tog=document.querySelector('.toggle-text'),i=0;container.classList.add('expanded');el.style.whiteSpace='normal';if(tog)tog.style.display='none';el.classList.add('typing');var t=setInterval(function(){if(i<full.length){el.textContent+=full.charAt(i);i++}else{clearInterval(t);el.classList.remove('typing');setTimeout(function(){container.classList.remove('expanded');el.style.whiteSpace='';if(tog){tog.style.display='';tog.textContent='展开'}},800)}},50)})();</script>
```

自定义样式

```
<style>
.aisummary {
    background-color: #2C3E50;
    color: white;
    padding: 20px;
    border-radius: 10px;
    margin-bottom: 1rem;
    position: relative;
    overflow: hidden;
}
.summary-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
}
.summary-text {
    flex: 1;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-right: 10px;
}
.summary-text.typing {
    animation: blink-caret 0.6s step-end infinite;
}
.summary-text.typing::after {
    content: '';
    display: inline-block;
    width: 2px;
    height: 1em;
    background-color: #4CAF50;
    margin-left: 2px;
    vertical-align: text-bottom;
    animation: blink-caret 0.6s step-end infinite;
}
@keyframes blink-caret {
    50% { background-color: transparent; }
}
.toggle-text {
    cursor: pointer;
    color: #4CAF50;
    font-weight: bold;
    white-space: nowrap;
}
.aisummary.expanded .summary-header { flex-wrap: nowrap; }
.aisummary.expanded .summary-text {
    white-space: normal;
    overflow: visible;
    text-overflow: clip;
    max-width: none;
}
.aisummary.expanded .toggle-text {
    position: absolute;
    bottom: 10px;
    right: 20px;
}
.aisummary.expanded { padding-bottom: 40px; }
</style>
<script>
function toggleSummary() {
    var c = document.querySelector('.aisummary');
    var s = document.querySelector('.summary-text');
    var t = document.querySelector('.toggle-text');
    if (c.classList.contains('expanded')) {
        c.classList.remove('expanded');
        t.textContent = '展开';
        s.style.whiteSpace = 'nowrap';
    } else {
        c.classList.add('expanded');
        t.textContent = '收起';
        s.style.whiteSpace = 'normal';
    }
}
</script>
```

## 摘要存储

摘要存储在 Handsome 主题的 `customSummary` 自定义字段中（`table.fields`），与主题自定义摘要功能兼容。

## 依赖

* PHP 7.4 或更高版本
* Typecho 1.2 或更高版本

## 许可证

本项目遵循 MIT 许可证。详情请参阅 LICENSE 文件。
