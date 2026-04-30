# Dokploy 上线部署说明

## 构建方式

- Provider/Build Type：Dockerfile
- Dockerfile：`Dockerfile`
- Build Context：`.`
- 应用端口：`3000`

## 必填环境变量

在 Dokploy 的 Environment 里添加：

```env
ADMIN_AUTH_USER=你的后台用户名
ADMIN_AUTH_PASSWORD=你的强密码
```

这两个变量会在容器启动时生成服务器级 Basic Auth，保护 `/admin/` 和后台写入 API。

## 持久化存储

必须给容器挂载持久化目录：

```text
/app/data
```

`gallery.json` 和 `lottery.json` 都写在这里。没有持久化存储时，重新部署后数据可能回到镜像里的初始空数据。

## 上线后检查

1. 打开 `https://你的域名/`，应显示 `WZZT.html` 首页。
2. 首页开奖区域应正常加载 `index.html`，不是 404。
3. 打开 `/admin/HTadmin.html`，浏览器应先弹出服务器登录框。
4. 保存一期开奖数据后，打开 `/data/lottery.json` 确认内容更新。
5. 打开 `/admin/gallery-admin.html`，点“同步服务器”，再打开 `/data/gallery.json` 确认内容更新。

## 常见问题

- 后台提示写入失败：检查 `ADMIN_AUTH_USER`、`ADMIN_AUTH_PASSWORD` 是否设置，并确认 Dokploy 已挂载 `/app/data`。
- `/admin/` 返回 503：说明没有设置 `ADMIN_AUTH_USER` 或 `ADMIN_AUTH_PASSWORD`。
- 首页没有开奖内容：先确认 `/data/lottery.json` 不是空数据，再检查后台是否保存成功。
- 图库为空：先确认 `/data/gallery.json` 是数组格式，例如 `[]`。
