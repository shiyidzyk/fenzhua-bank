# 粉爪银行 · 卡包（Fenzhua Bank）

一个「虚构银行」演示站：把银行卡、钱包、结售汇、转账、电子回单、站内信这些真实银行 App 的交互形态，做成打开浏览器就能玩的小沙盘。站内余额、卡号、流水、回单全部是演示数据，不涉及任何真实资金与真实账户。

- 演示站：<https://fenzhua.yzz.me/>
- 上游项目（本项目的起点）：<https://gitee.com/hiteex/haitecard-web3>（GPL-3.0，Copyright (C) 2026 Hiteex Team）
- 二次开发说明（改了哪里、怎么实现的）：[DEVELOPMENT.md](DEVELOPMENT.md)

## 这是什么 / 不是什么

是：一个单页面前端 + 一个 PHP 接口 + 一张 MySQL 表组成的完整演示应用，可以注册、建 PIN、开卡、转账、买卖外汇、看回单，管理后台还能改余额、管卡片、导全库备份。

不是：支付工具、金融产品、真实银行系统。没有真实资金通道，不具备任何金融资质，与游戏厂商和支付机构均无关联。

## 功能

**用户侧**

- 登录：游客 / QQ / 哔哩哔哩三种入口，登录后设置 4 位 PIN，刷新免重输、关标签页需重验
- 开户：上传游戏内账户截图，本地 OCR 识别 UID 与余额（Tesseract.js，离线、不调外部接口）
- 卡片：开卡 / 冻结 / 激活，卡号 + CVC + 有效期由服务端派生；一二类账户限额
- 钱包：方斯（HFS）与环石（HHS）双币余额、收支流水、首页今日/近 30 日消费统计
- 转账：卡号 + 户名双匹配（Luhn 校验），双边落账 + 双方回单 + 双方站内信
- 结售汇：按牌价买入/卖出，Ⅱ类账户禁购汇，成交记汇率与出入金额
- 电子回单：带印章样式的回单，可在通知中心查看
- 关于 / 鸣谢 / 开源许可：许可全文、源码下载、反馈入口

**管理侧**

- 主管理员（root）：全部账户、改余额、改卡片状态、导 JSON / CSV / 全库备份、删除账号（写墓碑防止旧页面复活）
- 二级管理员（sub）：只能管绑定给自己的那一个账户；导出与全库备份对其隐藏
- 服务端权限判定（`scopeCan`），前端只负责入口；令牌分仓（用户 localStorage / 管理员 sessionStorage）

## 技术栈

- 前端：单个 `index.html`，内联 CSS + JS，无构建、无打包器；jsQR 内嵌（扫码）
- 后端：单个 `api.php`，PHP 8；MySQL 优先，连不上库时自动落 `data/store.php` 文件存储
- 存储：`pc_users` 表（一行一个账号，`st` 列存整份业务状态 JSON，`ver` 做乐观锁）
- OCR：`ocr/` 下 Tesseract.js 5.1.1 + core + eng 语言包（离线）

## 目录

```
index.html                   前端全部代码
api.php                      后端唯一入口（POST JSON / GET ?a=xxx）
config.example.php           私有配置模板（复制成 config.php 再填）
bili-card.php                B 站公开资料同源代理
oauth-callback.example.php   第三方登录回调示例
.htaccess                    Apache/LiteSpeed 规则（含 data/ 拒绝访问）
nginx.conf                   Nginx 参考配置
img/                         品牌、卡面、示例余额、立绘、QQ 图标
ocr/                         Tesseract.js 与语言包
LICENSE.txt                  GPL-3.0 全文
NOTICE.txt                   上游归属与第三方组件声明
DEVELOPMENT.md               开发说明（架构、接口、改动清单、二次开发指南）
CHANGELOG.md                 变更日志
```

## 部署

1. 把仓库文件放到站点根目录（`index.html`、`api.php`、`img/`、`ocr/`、`.htaccess` 必须同目录）。
2. 复制 `config.example.php` 为 `config.php`，填数据库与 `SECRET`、`ADMIN_USER`、`ADMIN_PASS`。
   `config.php` 被 `.gitignore` 排除，不会进仓库；**不要把凭据写回 `api.php`**。
3. 建表（在 `config.php` 指定的库里执行）：

```sql
CREATE TABLE pc_users (
  k      VARCHAR(160) PRIMARY KEY,
  p      VARCHAR(16),
  a      VARCHAR(160),
  name   VARCHAR(64),
  avatar TEXT,
  pin    VARCHAR(128),
  st     LONGTEXT,
  ver    INT,
  upd    BIGINT
);
```

4. 建 `data/` 目录并给写权限（`0705`）；它会被 `.htaccess` / `nginx.conf` 拒绝对外访问。
   Nginx 用户必须显式加 `location ~ ^/data/ { deny all; }`，`.htaccess` 对 Nginx 无效。
5. 健康检查：`GET api.php?a=ping` 返回 `{"ok":1,"php":"8.x","store":"mysql"}`。
6. 前端管理员入口校验用的是摘要常量：`index.html` 里的 `ADMIN_HASH`。
   换了自己的主管理员账密后，要同步改成
   `sha256Hex('pinkclaw::admin::' + 你的用户名 + '::' + 你的密码)` 的小写 16 进制值
   （只是本机入口闸门，真正的权限判定在服务端；不换会导致后台入口进不去）。

一台机器上没有 PHP 也能先看前端，但登录、存档、转账这些功能需要 `api.php` 才能跨设备。

## 开发

新增接口、新增状态字段、新增页面的步骤，以及 13 条上线前回归用例，都在 [DEVELOPMENT.md](DEVELOPMENT.md)。改完记得：

- `api.php` 先上传并打 `ping`，再传 `index.html`（顺序反了会短时间登录失败）
- `push` 请求必须带 `ver`，否则被判 `conflict` 且静默不写

## 许可

本项目是上游 `haitecard-web3` 的改造版，依 **GNU General Public License v3.0** 分发：

- 保留原作者版权声明（Copyright (C) 2026 Hiteex Team）
- 标注修改内容与修改日期（2026-11-22 起）
- 提供可修改的对应源码（即本仓库）
- 二次分发同样须以 GPL-3.0 开放对应源码

第三方组件（Tesseract.js、jsQR 等）的许可见 [NOTICE.txt](NOTICE.txt)。

## 反馈

- GitHub Issues：<https://github.com/shiyidzyk/fenzhua-bank/issues>
- 不熟悉 GitHub 的话，直接发邮件到 <shiyi363@qq.com>（问题、复现步骤、截图、留言都可以）
- Pull Request 欢迎，改动大建议先开 Issue 聊一下方向

## 免责声明

本项目为虚构银行同人演示，与游戏、厂商、支付机构无关，不构成任何金融服务。页面中的第三方名称、角色与素材，其权利归各自权利人。
