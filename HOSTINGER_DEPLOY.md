# Hostinger 上线部署说明

## 目标结构

- 公开首页：`WZZT.html`
- 开奖 iframe：`index.html`
- 挑码助手：`TMZS.html`
- 后台目录：`admin/`
- 可写数据：`data/gallery.json`、`data/lottery.json`

后台已经移动到 `admin/`。上线后必须在 Hostinger hPanel 给 `admin` 目录加服务器级密码保护，不能只靠页面里的密钥。

## 推荐上线方式：Git 部署

1. 本地确认文件无误后提交并推送到 GitHub。
2. 打开 Hostinger hPanel：`Websites` -> 你的站点 -> `Manage`。
3. 搜索并打开 `Git`。
4. 填写 GitHub 仓库地址和分支。
5. `Install Path` 留空，让站点部署到 `/public_html`。
6. 如果提示目录必须为空，先用 File Manager 清空 `public_html` 里的旧站点文件，再部署。

官方文档：<https://support.hostinger.com/en/articles/1583302-how-to-deploy-a-git-repository-in-hostinger>

## 备用上线方式：File Manager 上传 zip

1. 打开 hPanel：`Websites` -> 你的站点 -> `Manage` -> `File Manager`。
2. 进入当前域名的 `public_html`。
3. 上传 `hostinger-upload-20260430.zip`。
4. 在 `public_html` 内解压。
5. 确认 `WZZT.html`、`.htaccess`、`admin/`、`data/` 都在 `public_html` 第一层。

官方文档：<https://support.hostinger.com/en/articles/4548688-basic-actions-in-the-file-manager>

## 必做安全设置

1. 在 hPanel 搜索 `Password Protect Directories`。
2. 选择 `admin` 目录。
3. 设置一个后台服务器账号和强密码。
4. 保存后访问 `https://你的域名/admin/HTadmin.html`，浏览器应先弹出服务器登录框。

官方文档：<https://support.hostinger.com/en/articles/1583470-how-to-password-protect-a-website>

注意：`robots.txt` 只是告诉搜索引擎不要索引后台，不是安全保护。真正的保护必须用 Hostinger 的目录密码。

## HTTPS

Hostinger Web/Cloud Hosting 安装 SSL 后通常默认强制 HTTPS。上线后请在 hPanel 的 `SSL` 页面确认 HTTPS 处于强制状态。

官方文档：<https://support.hostinger.com/en/articles/1583201-how-to-enable-or-disable-https-for-your-website-at-hostinger>

## 上线后测试

1. 打开 `https://你的域名/`，应进入首页。
2. 打开 `https://你的域名/admin/HTadmin.html`，应先出现服务器密码框，再进入后台页面。
3. 在开奖后台保存一期测试数据。
4. 打开 `https://你的域名/data/lottery.json`，确认内容已更新。
5. 回到首页，确认开奖区域显示新数据。
6. 打开 `https://你的域名/admin/gallery-admin.html`，点“同步服务器”，确认 `data/gallery.json` 可更新。

如果同步服务器失败：

- 先确认 `admin` 目录已经用 Hostinger 加密码保护。
- 确认 `data` 文件夹和里面的 JSON 文件可写。
- 仍失败时，用后台的“下载开奖数据”或图库后台的“下载 gallery.json”手动上传覆盖。
