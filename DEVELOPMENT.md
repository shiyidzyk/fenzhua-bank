# DEVELOPMENT.md · 粉爪银行 · 卡包（基于LING不道大佬的开源项目二次开发版 LING不道大佬B站主页https://b23.tv/z2IIXcU）

> 面向二次开发者：本文写清「相对上游改了什么、为什么改、怎么实现的、改的时候要注意什么」。
> 上游：<https://gitee.com/hiteex/haitecard-web3>（GPL-3.0，Copyright (C) 2026 Hiteex Team）
> 本仓库：<https://github.com/shiyidzyk/fenzhua-bank>
> 本文档描述的是本站 `https://fenzhua.yzz.me/` 的改造版，修改日期 2026-11-22。

---

## 0. 先看这 6 条（读完就能上手）

1. 前端仍是**单文件** `index.html`（HTML + 内联 CSS + 内联 JS + 内嵌 jsQR），无构建、无打包器。
2. 后端是**一个** `api.php`（PHP 8 + MySQL 优先，连不上库时自动落 `data/` 文件存储）。
3. 账号存档 = `pc_users` 表一行，`st` 字段是整份业务状态 JSON；前端 `state` 对象与它一一对应。
4. 所有写操作都走 `push`，靠 `ver` 做**乐观锁**；不传 `ver` 会被判 `conflict` 并静默不写。
5. 身份有三层：主管理员令牌（root）、二级管理员令牌（sub）、用户令牌（utk）。前端分两个仓库存放。
6. 改完先做语法自检（`node --check` 抽 JS、`php -l api.php`；开发机上没有 php 时用括号配平脚本代替），再上传 `api.php` → 验证 `ping` → 再上传 `index.html`。

---

## 1. 运行形态：和上游最大的不同

| 维度 | 上游 haitecard-web3 | 本站（托管改造版） |
|---|---|---|
| 部署形态 | 纯前端单文件，Toy 平台 / 本地静态服务器 | 静态前端 + PHP 后端 + MySQL（含文件存储兜底） |
| 数据落点 | Toy 云存储 / localStorage | `pc_users` 表（主）/ `data/store.php`（兜底）/ localStorage（本机缓存） |
| 账号体系 | 本机存档为主 | 服务端存档为唯一真相，本机只是缓存 |
| 图片 | 素材 base64 内嵌进 HTML | 外置 `img/*.webp`（7 张）+ `img/qq-logo.png`，`.htaccess` 给一年缓存 |
| OCR | 云端多模态 OCR 接口（需外部 Key） | 本地 Tesseract.js（`ocr/`，离线、无外部 Key） |
| 新增能力 | — | 一二类账户限额、卡号+户名转账、结售汇、后台管理、电子回单、站内信、删除墓碑、审计与限流 |

> 注意：本站**偏离**了上游 README 的「单文件约束」（约定，不是许可义务）。若要回流上游，需要把后端能力做成可选适配层，或只回流纯前端修复。

---

## 2. 目录与文件职责

```
index.html      前端全部代码（唯一入口；体积约 800 KB，未压缩）
api.php         后端唯一入口；所有动作走 POST JSON 或 GET ?a=xxx
bili-card.php   B 站公开资料代理（同源，避开 CORS）
oauth-callback.example.php  第三方登录回调示例（QQ/微信，需自填 AppSecret）
ocr/            Tesseract.js 5.1.1 + core 5.1.0 + eng 语言包（离线验资）
img/            品牌、卡面、示例余额、负责人立绘、QQ 图标（webp / png）
data/           服务端运行期数据（务必不可公网直读，见 §6.4）
  ├ store.php     文件存储模式下的全库（PHP 前缀保护）
  ├ tomb.json     删除墓碑：被删账号 key → 禁止被旧页面同步复活
  ├ frozen.json   站内 UID 冻结表：销号后 UID 永不复用
  ├ root.json     主管理员账密覆盖（存在即以它为准）
  ├ admins.json   二级管理员表（名字 → hash/bound）
  ├ pinv2.json    PIN 的 PBKDF2 记录（惰性升级后写入）
  ├ rl.json       登录失败限流与建档节流计数
  ├ audit.log     审计日志（只记失败/拒绝，>300 KB 自动截断）
  └ bak/          每次写库前的上一版快照（每账号保留 20 份）
.htaccess       Apache/LiteSpeed：MIME、压缩、缓存、404 兜底、data/ 拒绝访问
nginx.conf      Nginx 参考配置（.htaccess 对 Nginx 无效）
LICENSE.txt     GPL-3.0 全文（站点根可访问）
README-部署.md  部署与运维说明
```

---

## 3. 后端 api.php

### 3.1 启动与配置

```php
$CFG   = ['host','port','user','pass','db'(留空自动探测同名库),'timeout'];  // 默认值仅占位
$SECRET = 'change-me-...';   // 所有令牌的 HMAC 密钥；换掉它 = 全部令牌失效
$ADMIN_USER / $ADMIN_PASS;   // 出厂主管理员；data/root.json 存在时被覆盖
$RECEIPT_MAX = 30;           // 每账号保留回单数
$NOTIF_MAX   = 60;           // 每账号保留站内信数
$CARD_BIN    = '888888';     // 卡号 BIN（固定，便于识别演示卡）
$LIMITS      = [1 => Ⅰ类规则, 2 => Ⅱ类规则];
```

**凭据外置**：真实库口令与密钥写在同目录 `config.php`（返回数组，`.gitignore` 已排除），
`api.php` 启动时 `include` 它并覆盖 `$CFG` / `SECRET` / `ADMIN_USER` / `ADMIN_PASS`：

```php
return [
  'CFG' => ['host' => '127.0.0.1', 'user' => 'u', 'pass' => 'p', 'db' => 'db'],
  'SECRET' => '...', 'ADMIN_USER' => '...', 'ADMIN_PASS' => '...',
];
```

为什么这么做：`api.php` 是能被公网下载源码的（`?a=source`），凭据硬编码等于把它们一起公开。
没有 `config.php` 时仍可运行，只是退回文件存储并用占位密钥 —— 生产环境必须配。

存储选择：连库成功 → `$MODE='mysql'`；失败 → `$MODE='file'`，用 `data/store.php`（`storeTx()` 带文件锁）。

请求约定：

- 动作名 `$a`：`?a=` 优先，其次 body 的 `act`（注意 `body.a` 是账号名，不是动作）。
- 请求体：`Content-Type: application/json`，解析进 `$in`。
- 响应：统一 `jout()` 输出 JSON 并 `exit`；HTTP 恒为 200，成败看 `ok`。
- 常用错误码：`auth` / `bad_pin` / `locked` / `limit` / `insufficient` / `frozen` / `risk` / `gone` / `conflict` / `too_many` / `bad_params`。

### 3.2 数据模型

表结构（MySQL）：

```sql
CREATE TABLE pc_users (
  k      VARCHAR(160) PRIMARY KEY,   -- 账号键：provider:account（guest:guest-xxxx / qq:123456 / bili:uid）
  p      VARCHAR(16),                -- provider
  a      VARCHAR(160),               -- 平台内账号
  name   VARCHAR(64),                -- 昵称（全站唯一，撞名由服务端重新分配）
  avatar TEXT,                       -- 头像 URL
  pin    VARCHAR(128),               -- 登录 PIN 的 sha256 摘要（旧格式，兼容用）
  st     LONGTEXT,                   -- 业务状态 JSON（见 §4.2）
  ver    INT,                        -- 版本号（乐观锁）
  upd    BIGINT                      -- 更新时间（毫秒）
);
```

`st` 里由**服务端**保护的权威字段（`push` 时会强制对齐，客户端改不动）：

- `lim`：限额计数，客户端存档里没有时沿用库里旧值 —— 否则一次前台同步就会把「今日/本年已用额度」清零。
- `cardState`：卡片状态；另有兜底规则「`cardOpened=true` 而 `cardState` 仍是 `inactive` ⇒ 就地改正为 `active`」（历史上开卡只写 `cardOpened` 造成的前后台不一致）。
- `pcuid`：站内 UID，已有则一律回填旧值；没有则由服务端 `newUid()` 分配。

### 3.3 并发：pcPut 乐观锁 + 每版备份

```
pcPut(k, row, force, baseVer):
  cur = pcGet(k)
  pcBackup(cur, k)                        # 落 data/bak/，每账号保留最近 20 版
  if cur and !force and baseVer != cur.ver:  return {ok:1, conflict:1, rec:cur}
  ver = cur ? cur.ver+1 : 1
  写库（UPDATE 或 INSERT）
```

要点：`ver` 由客户端带上（`apiVer`，登录/拉取时同步）。**不传 ver ⇒ baseVer=-1 ⇒ 必冲突 ⇒ 不写**，这是「存档不会被旧页面盖掉」的关键。

### 3.4 删除墓碑与 UID 冻结

- `del`（仅 root）：删行 → `tombSet(k, ver)` → 冻结该账号的 `pcuid`。
- `undel`（仅 root）：清墓碑，允许重新注册。
- `push` 命中墓碑直接返回 `err:'gone'`，前端据此清本机存档并退出。
- `frozen.json`：销号后 UID 永久冻结，`newUid()` 跳过。

### 3.5 三层鉴权

| 身份 | 令牌格式 | 生成 | 作用域 |
|---|---|---|---|
| root | `hmac_sha256("admin/v1/" + user + "/" + sha256(pass), SECRET)` | `auth` 校验账密 | `scopeCan()` 全通 |
| sub | `sub.<name>.<hmac_sha256("admin/sub/" + name, SECRET)>` | `subreg` / `auth` | 仅 `bound` 指向的那一个账号 |
| user | `utk.<b64url(k)>.<hmac_sha256("user/" + k, SECRET)>` | `userauth` / 首次 `push` 回发 | 仅本人 key |

三个判定函数是安全核心：

- `scopeOf($t)` → `null | {role:root} | {role:sub,name,bound}`；
- `userScopeOf($t)` → `{role:user,k}`，用 `hash_equals` 重算 HMAC 校验；
- `scopeCan($scope,$k)` → root 全通、sub 仅 bound。

`push` 的三通道（任一通过即可写）：

1. `$okUser`：utk 令牌里的 k 与请求 k 一致；
2. `$okAdmin`：管理员令牌且 `scopeCan` 通过；
3. `$okPin`：提交的 64 位 sha256 与库里 `pin` 相等（兼容旧客户端）；库里没有 PIN（重置后）时，任何 64 位 hex 摘要都算「首次设 PIN」。

`get` 收窄：只有 owner（本人 utk 或管理员范围）才拿整条 `rec`；匿名只回 `{found,gone,hasPin,name}` —— 这样「知道账号 key」不再等于「拥有账号」。

建档节流：非令牌、无旧记录的 `push`，按 `!create:YmdH` 计数，每小时超过 200 条返回 `too_many`。

### 3.6 PIN 存储演进（legacy → PBKDF2，惰性升级）

- 旧：`pin` 字段 = 客户端算的 `sha256('pinkclaw::login::pin::'+PIN)`（无盐，4 位 PIN 可离线枚举）。
- 新：`data/pinv2.json` 存 `{v:1,s:salt,i:100000,h:pbkdf2_sha256(pin,salt,100000,32)}`。
- `userauth` 校验顺序：v2 命中 → 1；否则旧摘要命中 → 2；都不中 → 0。
- 成功登录后 `pinUpgrade()` 把提交上来的摘要升级成 v2，用户无感；改过 PIN 的老账号仍走旧摘要，下次登录再升级。
- 为什么摘要而不是明文：客户端从不上送明文 PIN；PBKDF2 加盐让「拿到 pinv2.json」也无法低成本枚举。

限流（`data/rl.json`）：连续 5 次失败锁 600 秒，成功即清零；锁定期间连正确 PIN 也返回 `err:'locked', wait:秒`。

审计（`data/audit.log`）：记录 `push auth reject`、`userauth fail/locked`、建档节流等，超过 307,200 B 截到最近 2000 行。

### 3.7 账户等级与限额

```
tierOf(st)   = cardOpened ? 1(Ⅰ类) : 2(Ⅱ类)
limOf(st)    = 按日/月/年自动重置的计数器 {d,dAmt,dCnt,m,mAmt,mCnt,y,yAmt}
limCheck(st, amt, kind) → 超限返回中文原因，否则 null
limAdd(st, amt)          → 成交后累加（金额按 HFS 记价）
```

规则（`$LIMITS`，单位 HFS）：Ⅱ类 单笔 1 万 / 日 1 万 / 年 20 万 / 日 10 笔，且**不可购汇**；Ⅰ类 单笔 50 万 / 日 200 万，年限与笔数不限。

### 3.8 业务动作要点

- **p2p（卡号+户名转账）**：校验 `pin` → Luhn 卡号 + 卡号/户名双匹配（`findByCard`）→ 禁自转 → 余额/冻结/风控 → `limCheck` → 双向落账 → 出/入两张回单（`sealOf` 盖章）+ 两条流水 + 两条站内信 → 双方各自 `saveRow(force=true)`。
- **conv（购汇/结汇）**：`side=buy` 用现汇卖出价、`sell` 用现汇买入价；Ⅱ类禁购汇；购汇走 `limCheck`，结汇不计限额；回单记 `rate/outAmt/inAmt`，流水一出一进。
- **card（卡片状态）**：`active/frozen/inactive`；激活时若无卡号则发卡（`genCardNo` + 5 年有效期 + `cvcOf` 派生 CVC）；同时纠正 `cardOpened`、`cardState`，给用户投递变更通知。
- **op（账户操作）**：冻结/解冻/升降级/销户等后台动作，二次确认后生成回单并通知。
- **limit**：查当前等级规则与已用额度（后台展示用）。
- **rates**：内置牌价文件 + 在线抓取兜底，返回 `{mid,buy,sell,cash,date,src,ts}`。
- **管理动作**：`auth`（换 root/sub 令牌）、`rootset`（改主管理员账密，改完旧令牌全废）、`subreg`/`subdel`、`adminedit`（改用户昵称/账号/PIN 或改二级管理员）、`list`（按 scope 过滤）、`dump`（root 全库备份，含 `rates`/`gone`）、`del`/`undel`。
- **其它**：`nick`（昵称是否被占）、`cardchk`（卡号是否被占，撞了返回随机可用号）、`findcard`（卡号→户名核对）、`reset`（忘记密码：清空该账号并存档，需 PIN 或 root）、`source`（输出本文件源码，凭据打码）、`ping`（健康检查）。

---

## 4. 前端 index.html

### 4.1 分区

| 区块 | 说明 |
|---|---|
| `<style>` | 全部 CSS：设计变量、组件、响应式（clamp）、动效 |
| 视图 HTML | `view-login / onboard / review / appeal / home / card / managers / exchange / transfer / receive / apply / admin` |
| jsQR v1.4.0 | 内嵌扫码库（Apache-2.0），离线可用 |
| 主逻辑 `<script>` | 状态与存档、视图切换、登录、业务模块、后台、合规面板 |

### 4.2 状态与存档（改字段必看）

`const state` 是唯一状态源（默认值见文件 `state = {...}`）。持久化三件套：

```
statePayload()   决定哪些字段上云/落本机 —— 新增字段必须登记
applyState(raw)  反序列化 + 字段校验 + 缺失兜底 —— 新增字段必须在这里给默认值
saveState()      本机写 localStorage（STATE_KEY）
localSave(p)     按 payload 写本机
```

存储键：`STATE_KEY=haitecard.state.v1`、`ACCT_KEY=haitecard.accounts.v1`（本机账号表：p/a/pass/pin/name/avatar）、`LOGIN_KEY`、`UNLOCK_KEY=haitecard.unlock.v1`（sessionStorage，刷新免 PIN）、`API_T_KEY=haitecard.api.t`（用户令牌）、`API_ADM_KEY=haitecard.api.adm`（管理员令牌，sessionStorage）。

### 4.3 视图切换

`views` + `VIEW_ORDER` + `go(name)` / `activate(name)`。层级数字决定滑入方向（前进从右、返回从左）。**新增独立页面**：加 `<section class="view" id="view-xxx">`、在 `views` 注册、在 `VIEW_ORDER` 给层级、入口调 `go('xxx')`。

新增**弹层**（本站「关于 / 鸣谢 / 开源许可」就是这种）：直接用 `openModal(html, onMount)`，模板里的 `data-close` 会被自动绑定关闭。

### 4.4 登录与 PIN

```
lgOAuthSubmit()  第三方账号登录（async）
  ├ 本机有该账号记录 → 校验本机密码摘要 → 有 pin 走 verify、无 pin 走 setup
  └ 本机无记录 → 先 GET get 探测：云端 found && hasPin ⇒ 换设备登录（走 verify）
               否则按新账号：填昵称头像 → setup → commitAcct → lgAfterAuth
lgShowPin(mode, after)  mode = setup | verify
  ├ setup：state.pin = lgHashPin(PIN) → commitAcct → after()
  └ verify：POST userauth{k, pin} →
        gone     → 删本机记录并提示
        bad_pin  → 提示「还可试 N 次」
        locked   → 提示等待秒数
        ok       → 收下 srv.t（apiSetToken）→ applyState(srv.rec.st) → 补登记账户 → lgAfterAuth()
        服务端不可达 → 退回本机摘要比对（离线可用）
lgAfterAuth()  置登录态 → apiPullAccount 拉云端（唯一真相）→ 拉不到才用本机缓存并建档上云
```

### 4.5 上云同步（最容易踩坑的一段）

```
apiCall(act, body)   统一 POST，自动带 t（优先管理员令牌、其次用户令牌）
apiSyncNow()         推当前存档：{k,p,a,name,avatar,pin,st,ver}
  返回分流顺序（顺序很重要）：
    gone          → 清本机存档 + 退出
    auth          → toast 一次「需要重新验证 PIN」
    pin_mismatch  → 以服务端 rec 覆盖本机（released by server）
    !ok           → 直接返回
    conflict      → 用 d.rec 覆盖本机并更新 apiVer
    d.t           → 收下服务端回发的用户令牌
    d.ver         → 更新 apiVer
apiPullAccount(key)  GET get → 有 rec 才 applyState；只有元数据时返回 false
apiForcePush(key,st) 后台强推（force=1，管理员令牌）
```

### 4.6 首页统计（本轮修复）

- `recordTx(entry)` 统一记流水（含方向 `dir=in/out`、币种 `cur`、状态 `st`）。
- `spendStat(days)`：只统计 `dir==='out'`、状态不含「失败/撤销/退回/拒绝」的条目；`HHS` 支出按牌价 `sell`（约 6.7167）折算成 HFS 计。
- 首页三行 = `spendStat(0)`、`spendStat(30)`、`state.cashback`。**不要**再写死 `todaySpend/monthSpend` 默认值。

### 4.7 后台

```
adminRole()   仅 sessionStorage 显式 'root' 才是 root，其余一律 sub   ← 修过越权，别再改回 || 'root'
adminOpen(role,name,bound)  只在传了合法 role 时写入，缺省沿用已有
enterAdmin()  未登录 → openAdminLogin()；已登录 → go('admin')
adminClose()  清 sessionStorage 管理员键 + apiSetAdminToken('')（root 凭据不驻留）
renderAdmin() 按 scope 过滤：sub 只看 bound 账户；root 全量
admRows()     叠加「仅本机」角标（SERVER_KEYS 与服务端 list 对比）
#adm-prune    root 专属：清理只存在于本机的孤立记录
#adm-export / #adm-csv / #adm-dump  导出 JSON / CSV / 全库备份
```

后台改余额等写操作走 `admPersist` → `apiForcePush`（带 `force:1`）；子管理员 `force` 只对自己 bound 的账户生效（服务端 `$force` 判定）。

### 4.8 关于 / 鸣谢 / 开源许可

- 入口：首页「更多」面板（`#more-about` / `#more-thanks`）+ 首页「开源地址」行（`#open-license`）。
- `ABOUT_LINKS = { repo, issues, prs }`：**开源后回填三个 URL**，空串时渲染成灰底「待开源后回填」占位，填入即自动变可点外链（`aboutRowHtml` 统一处理）。
- `openLicensePanel()`：版权 / 修改声明 / 下载本页源码 / `api.php?a=source` / LICENSE.txt / NOTICE / 数据处理说明。

---

## 5. 本轮改动清单（相对上游 + 相对上一版）

| # | 改动点 | 原问题 | 实现方式（关键函数/位置） |
|---|---|---|---|
| 1 | 首页消费统计恒为 3.18 | `todaySpend/monthSpend` 写死且无人更新 | 默认改 0；新增 `spendStat(days)` + `txHfsValue()`，从 `state.tx` 实时算；`renderState()` 改调用 |
| 2 | 首开送一条「现金账户存入 +11」但余额不变 | `initApp()` 预置假流水，未入账 | 取消预置；加载时按签名过滤历史脏记录（`name==='现金账户存入' && amt===11 && cur==='HFS'`） |
| 3 | 二级管理员登录后变「主管理员」 | `adminRole()` 缺省回退成 `'root'`、`enterAdmin()` 调无参 `adminOpen()` | 角色判定改为显式 root；`adminOpen` 缺省不覆写；`enterAdmin` 只负责跳转；列表按 bound 过滤 + 导出/备份对 sub 隐藏 |
| 4 | 「云端还有 5 个账号」的错觉 | 旧后端只合并不删，本地残留被当云端 | 新增 `SERVER_KEYS` 对比 → 「仅本机」角标；`#adm-prune` 自助清理 |
| 5 | 任何人可读存档（含 PIN 摘要） | `get` 无鉴权返回整条 rec | `get` 收窄：owner（本人 utk 或管理员 scope）才回 rec，匿名只回元数据 |
| 6 | 任何人可写存档 / 凭空造号 | `push` 裸奔 | 三通道鉴权 + 建档节流 + `err:'auth'` 审计 |
| 7 | 4 位 PIN 可无限爆破、摘要可离线枚举 | 无盐 sha256、无限试 | `userauth` 服务端校验 + 5 次/600 秒限流 + PBKDF2 惰性升级（`pinV2*`） |
| 8 | 管理员凭据长期留在浏览器 | 单令牌混用 | 令牌分仓（localStorage / sessionStorage）；`adminClose` 清管理员令牌；`apiToken` 优先管理员 |
| 9 | 换设备登录会误建新档 | 旧流程把「云端已有」当新账号 | `lgOAuthSubmit` 先探测 `get{found,hasPin}` → 走 verify；成功后 `commitAcct()` 补登记 |
| 10 | 同步错误处理死代码 | `gone`/`auth` 判断在 `!d.ok` 返回之后 | 重排分流顺序；`pin_mismatch` 分支移到 `!d.ok` 内 |
| 11 | `/data/` 可公网下载 | 无访问控制 | `.htaccess` 第 5 段：`RewriteRule ^data/ - [F,L]` + `FilesMatch` 拒绝 `.json/.log/.py/.md/.conf/.sql/.bak/.ini` |
| 12 | 新增「关于 / 鸣谢」页 | 无 | 「更多」面板加两行 → `openAboutSheet()` / `openThanksSheet()`，沿用 `.more-row` 与 `.about-txt` 样式 |

---

## 6. 二次开发指南

### 6.1 新增一个服务端动作

```php
case 'demo': {
  if (!$SCOPE) jout(['ok' => 0, 'err' => 'auth']);          // 需要管理员就判 scope
  $k = (string)($in['k'] ?? '');
  $row = pcGet($k);
  if (!$row) jout(['ok' => 0, 'err' => 'no_user']);
  $st = stOf($row);
  // ... 改 $st ...
  $r = saveRow($row, $st, true, -1);                        // force=1 表示无视 ver
  jout(['ok' => 1, 'st' => $st, 'ver' => $r['ver'] ?? 0]);
}
```

要点：① 先判身份（`$SCOPE` / `userScopeOf` / `$okPin`）；② 余额类操作先 `limCheck` 再 `limAdd`；③ 产出回单/通知用 `pushRcp` / `pushNotif` / `pushTx`；④ 落库用 `saveRow`，要并发保护就传真实 `ver` 而不是 `-1`。

### 6.2 新增一个前端页面

1. 在视图区加 `<section class="view" id="view-demo">…</section>`；
2. `views.demo = document.getElementById('view-demo')`；
3. `VIEW_ORDER.demo = 1`（1=二级页，2=三级页，决定滑入方向）；
4. 入口调 `go('demo')`；返回按钮调 `go('home')`。

只做信息展示（无状态）时更省事的做法：`openModal(...)`，参照 `openAboutSheet()`。

### 6.3 新增一个状态字段

1. `state` 默认值加字段（带注释写清语义）；
2. `statePayload()` 加字段（否则刷新/上云就丢）；
3. `applyState()` 加校验与默认兜底（老数据没有该字段时不能崩）；
4. 若是服务端权威字段（限额、卡状态、UID），在 `push` 的「权威字段保护」数组里登记，避免被旧客户端覆盖。

### 6.4 部署与运维

- 上传：`index.html`、`api.php`、`config.php`（私有，最后才传也行，但先于 `api.php` 更稳）、`bili-card.php`、`.htaccess`、`img/`、`ocr/`、`LICENSE.txt`；`data/` 需可写（`0705`）。
- 建表：见 §3.2 的 DDL；库名可留空让 `api.php` 自动探测。
- 凭据：`config.php`（同目录、不入库）里配 `$CFG` / `SECRET` / `ADMIN_USER` / `ADMIN_PASS`；改 `SECRET` 会让所有令牌失效（用户需重登）。**不要**把口令写回 `api.php`。前端 `index.html` 的 `ADMIN_HASH` 需同步改为 `sha256Hex('pinkclaw::admin::' + 用户名 + '::' + 口令)`，否则后台入口进不去。
- `data/` 绝不可公网可读（本仓库 `.htaccess` 已拦；Nginx 用 `nginx.conf` 的 `location ~ ^/data/ { deny all; }`）。
- 健康检查：`GET api.php?a=ping` 应返回 `{"ok":1,"php":"8.4.x","store":"mysql"}`。返回 500 说明 PHP 语法/扩展问题，先回滚上一版。
- 备份：后台「备份全库 JSON」（`dump`）导出的文件含 PIN 摘要与余额，**不要公开分享**。

### 6.5 改完怎么测（最小回归集）

| # | 用例 | 期望 |
|---|---|---|
| 1 | `ping` | `ok:1`，`store` 与实际一致 |
| 2 | 新账号：登录→建 PIN→建档 | 建档成功且拿到 96 字符 `utk` |
| 3 | 老账号正确 PIN | `userauth` → `ok:1` + 令牌 |
| 4 | 错 PIN 连 5 次 | `left` 递减后 `wait:600`；锁后正确也 `locked` |
| 5 | 匿名 `get` 已有账号 | 只有 `found/gone/hasPin/name`，无 `rec` |
| 6 | 无令牌 `push` | `err:'auth'` |
| 7 | 令牌 `push` | `ok:1`，`ver` +1；旧 `ver` 重放 → `conflict:1` |
| 8 | 转账（卡号+户名+错户名） | 错户名 `not_found`；正确则双方余额与回单同步 |
| 9 | 购汇/结汇 | 汇率方向正确（buy 用 sell 价）；Ⅱ类禁购汇 |
| 10 | 后台 root 改余额 | 前台重载后余额一致 |
| 11 | 二级管理员 | 只见 bound 账户；`dump` → `err:auth` |
| 12 | 删除账号后旧页面同步 | `err:'gone'`，本机存档被清 |
| 13 | `data/pinv2.json` 直链 | 403 |

### 6.6 常见坑

- **函数在 IIFE 里**：控制台/自动化脚本取不到 `openAboutSheet` 这类内部函数，只能走 UI 点击，或临时把函数挂到 `window` 调试。
- **PHP 语法先本地 lint**：有 php 就用 `php -l api.php`；开发机上没有 php 时，退化为「括号/引号配平脚本 + 上传后立刻打 `ping`」，出 500 立即回滚上一版。
- **push 必须带 ver**：漏传会被判 `conflict` 且**静默不写**，表现为「操作成功但刷新后回退」。
- **下载类验证**：部分内置浏览器禁止下载（`BU_DOWNLOAD_BLOCKED`），只能用 toast / 网络请求证明。
- **严格区分 `p`（provider）与 `a`（账号）和 `act`（动作）**：`?a=` 是动作名，`body.a` 是账号名，写错会静默走错分支。
- **代码里仍有「中行式买卖价」注释**（`conv` 上方），仅注释、不面向用户；如要开源，建议一并改成中性表述。

---

## 7. 已知限制 / 待办

1. 4 位 PIN 的强度上限（1 万种）：限流把在线爆破压到约 720 次/天/账号，但强口令仍是方向。
2. 兼容通道：`push` 仍接受「与云端一致的 PIN 摘要」，等旧缓存页面淘汰后可只留令牌。
3. 合规待办（详见 `合规检查报告-上游要求对照-20261122.md`）：许可面板「不用于商业用途」与 GPL §10 冲突；NOTICE 漏 jsQR；缺 Apache-2.0 全文与 `source.zip`；品牌/素材授权待确认。
4. `img/*.webp` 与上游 base64 素材的对应关系未逐张核对。
5. 后台主管理员账密仍以常量形式存在（可被 `data/root.json` 覆盖），建议开源前改为环境变量或安装向导。

---

## 8. 变更日志

### 2026-11-22

- 修复：首页消费统计恒 3.18；首开假「现金账户存入 +11」。
- 修复：二级管理员被提权为 root；后台「仅本机」记录标记与一键清理。
- 安全：新增用户令牌 `utk` 与 `userauth`；`get` 收窄；`push` 三通道鉴权 + 建档节流；PIN 改 PBKDF2 惰性升级；登录失败限流；审计日志。
- 安全：令牌分仓（用户 localStorage / 管理员 sessionStorage）；退出后台即清管理员令牌。
- 体验：换设备登录走 PIN 验证；同步错误分流顺序修正。
- 新增：「关于 / 鸣谢」页与「开源许可与源码」面板；`ABOUT_LINKS` 回填位。
- 安全：`.htaccess` 禁止公网读取 `data/` 及 `.json/.log/.py/.md` 等内部文件。
- 安全：数据库与主管理员凭据外置到 `config.php`（`.gitignore` 排除、不入库），`api.php` 源码中只保留占位值；前端删除明文口令注释，仅保留摘要常量。
- 开源：仓库公开于 GitHub，关于页 / 首页「开源地址」/ 许可面板的链接与源码下载均指向本站仓库，并保留上游归属。
- 文档：新增本 `DEVELOPMENT.md`。
- 文档：重写 `README.md`（定位、上游关系、相对上游的改动、功能、使用、部署与构建、FAQ）。
- 策略：默认主管理员保留**开箱可用**的演示账密（见 README），方便他人登录自己部署的实例；本站正式对外宣发前再整体替换（`config.php` / `data/root.json` / `ADMIN_HASH` 三处同步）。
