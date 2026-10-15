# 系统架构图源与导出

图源基准日期：2026-10-10。内容说明见 [系统架构](../system-architecture.md)；整改建议见 [优化报告](../system-improvement-review.md)。

| 文件名 | 内容 |
| --- | --- |
| `system-overview` | 整个系统与已实现/待接入边界 |
| `backend-boundaries` | 后端代码依赖与插件边界 |
| `commerce-data` | 核心订单与账务业务关系，非完整 DDL |
| `order-payment` | 余额付款与待接入履约流程 |
| `message-runtime` | 消息补投、发送结果和独立注册验证码 |
| `deployment-target` | 建议单节点生产拓扑，非当前已配置部署 |

每张图提供 `.mmd` 可编辑图源、`.svg` 矢量图与 `.png` 预览。先修改图源，再重新导出对应图；不要只改 PNG。架构和优化报告使用相对链接，整个目录可随仓库分享。

蓝色为已有实现，黄色为部分能力或外部待联调，灰色虚线为待建设；颜色不代表真实厂商验收或生产可靠性。付款、入队、受理、交付、已读是不同事实。

## 渲染

本次使用 `@mermaid-js/mermaid-cli@12.0.0`、本机 Chromium 和中文字体。CLI 安装在仓库之外的工具目录，没有修改宿主或插件依赖。

在仓库根目录，使用已经安装的 `mmdc` 执行：

```bash
mmdc -i docs/architecture/diagrams/system-overview.mmd \
  -o docs/architecture/diagrams/system-overview.svg \
  -c docs/architecture/diagrams/render-config.json \
  --size 2400 -b white --no-font-embed
```

PNG 导出将输出扩展名改为 `.png`。若使用自备 Chromium，通过 `-p /path/to/puppeteer-config.json` 传入本机 `executablePath`；本机浏览器路径不提交到仓库。

根级 `htmlLabels: false` 保持 SVG 为原生文字，避免部分图片渲染器忽略 `foreignObject` 而导致节点文字丢失。没有嵌入字体，查看 SVG 的设备应安装中文字体；跨设备直接分享 PNG 更稳妥。

## 核验

- 修改节点和连线前核对当前控制器、用例、模型和进程，不用规划文档推定已实现能力。
- 每张图需成功渲染，再目视检查中文、节点文字、连线和裁切。
- 核对 Markdown 相对链接和图源/导出的一致性。
- 文档改动无需机械执行业务全套测试；不得把图片渲染成功解释为业务已验收。
