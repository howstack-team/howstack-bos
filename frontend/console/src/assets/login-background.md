# 登录背景资源

- 当前资源：`login-cloud-scene-v2.webp`。
- 生成方式：内置 image_gen，重新生成插画，没有使用 CLI/API 后备方案。
- 实际原始尺寸：1536 × 1024（3:2）；提示词请求更高尺寸，但生成工具实际返回此尺寸，不将其描述为 3K 或 4K。
- 输出转换：`cwebp -lossless -m 6`，无损 WebP，约 883 KiB；未进行人工放大或图像内容修改。
- 使用方式：背景定位在登录页容器内，从 60px header 下方延伸至页面底部，与表单和页脚一起随页面滚动。左侧展示插画与品牌文案，右侧保留登录卡片空间。
- 页脚：“服务协议”和“隐私政策”地址暂为空，等待提供正式 URL。

## 最终生成提示词

Generate a brand-new high-resolution raster background asset for a premium Chinese cloud computing console login page. This is an original background illustration, NOT a UI mockup. Requested size 3072 by 2048 pixels, landscape 3:2, use the highest available native resolution. Art direction: exceptionally crisp, precise architectural 3D product illustration, clean matte porcelain white and vivid cobalt/azure blue, solid well-defined geometric edges, clean tiny highlights, no blur, no haze, no frosted glass, no heavy reflections. A restrained cluster of approximately 8 clean white and blue modular computing cubes arranged on a white stepped rectangular platform in the LOWER LEFT quadrant, with one large blue upright square tile bearing a simple white cloud silhouette, and one smaller blue tile bearing simple white vertical bars. Very sparse composition with fewer, larger purposeful shapes; no chaotic floating tiny cubes or orbit loops. Sophisticated white architectural environment with fine sharp vertical grooves close to the center, pale blue soft daylight as illumination but clear edge separation and moderate contrast. The TOP LEFT third must be clean near-white negative space to place a headline. The ENTIRE RIGHT 45 percent must be a calm nearly white pale blue background to place a login form card. Main object centered around x=29 percent y=62 percent, stays inside x=10..46 percent and y=38..82 percent, avoid overlap with headline at y=15..28 percent. White planar floor, no mirror floor, no circular pedestal, no windows to clouds. Overall minimal, balanced, visually polished, accurate geometry, render-like fine clarity, premium technology editorial art. No text, no letters, no logo, no watermark, no UI, no dark areas.
