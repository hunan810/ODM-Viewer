# ODM-Viewer — 航拍实景三维模型在线查看器

一个部署在普通 PHP 虚拟主机或 Docker 上的**实景三维（摄影测量）模型查看系统**。
把 ODM（OpenDroneMap）/大疆智图等软件导出的三维成果（GLB/GLTF + DDS 纹理）上传后，
团队就能在浏览器里浏览、测量、做日照分析——手机也能用。

## 主要功能
<img width="1912" height="914" alt="image" src="https://github.com/user-attachments/assets/a6484555-5722-4530-8ada-271f565470b6" />
<img width="1912" height="914" alt="image" src="https://github.com/user-attachments/assets/1dae307a-4f90-4e8b-bb10-51d1c88db0a7" />

- **项目管理**：浏览器直接上传 GLB （或OBJ+mtl+jpg）模型包（支持大文件分片上传），自动生成项目卡片
- **三维浏览**：拖拽旋转 / 双指缩放 / 剖切盒 / 上方向轴自动检测
- **测量工具**：距离、面积、坐标打点（WGS84 经纬度）
- **日照分析**：按日期时间模拟太阳方位，显示阴影（内置 SunCalc）
- **渲染调参**：亮度 / 对比度 / 饱和度 / 环境光 / 阴影 / 像素比等高级参数
- **截图导出**：当前视图一键导出 PNG
- **系统设置**：站点名称、LOGO、管理密码全部在网页里改，不用碰代码

## 部署方式一：任意 PHP 虚拟主机（推荐入门）

要求：PHP 7.4+（建议 8.x）、支持 `.htaccess` 的 Apache 主机（西部数码、阿里云虚拟主机等均可）。

1. 把本目录全部文件上传到网站根目录
2. 浏览器打开站点，即出现项目首页
3. 点右上角「管理项目」，输入默认密码 `admin123`
4. **进去后第一件事**：点「系统设置」→ 修改密码、填站点名称、上传自己的 LOGO

> 首次使用时 `settings.json` 和 LOGO 文件都不存在，这是正常的——保存一次系统设置后会自动生成。
> `settings.json` 已被 `.htaccess` 拦截，无法从外网下载，密码哈希不会泄露。

## 部署方式二：Docker（NAS / 服务器）

```bash
cd ODM-Viewer
docker compose up -d
```

浏览器访问 `http://服务器IP:33338/` 即可。数据（上传的模型）都在项目目录的 `files/` 里，
容器删了数据也在。

## 目录结构

```
ODM-Viewer/
├── index.html            # 前端主程序（单文件，含全部 UI 和逻辑）
├── *.php                 # 后端：上传 / 删除 / 编辑 / 保存参数 / 系统设置
├── vendor/               # 前端依赖（three.js、SunCalc、BVH 加速等，离线可用）
├── draco/                # Draco 模型解码器
├── docker/               # Docker 用的 php.ini 与 Apache 配置
├── docker-compose.yml    # Docker 一键部署
└── .htaccess             # 关目录索引 / 拦截 settings.json
```

## 模型数据放哪

上传的项目都存在 `files/` 目录下（每个项目一个文件夹）。
也可以不经过网页上传，直接把 ODM 导出的 GLB 包手工放进 `files/`，
再通过首页「管理项目 → 编辑」补全名称、封面和 GPS 基准。

## 安全说明

- 所有写操作（上传 / 删除 / 保存）都要求管理密码，密码以 SHA-256 哈希存储
- 上传有扩展名白名单 + MIME 校验，路径参数做了防目录穿越处理
- `settings.json` 已通过 Apache 规则禁止外网访问

## 许可证

MIT License — 可自由使用、修改、商用，请保留 LICENSE 文件。
