# Developer & Handover Manual

This document is for anyone who has to **keep this project running** (including future you). It explains how the backend works, where the frontend pieces live, what was changed in the current round, how to extend it, and how to verify a change.

Companion documents: [README.md](README.md) / [README.en.md](README.en.md) (usage and deployment) and [CHANGELOG.en.md](CHANGELOG.en.md).

**中文版：** [DEVELOPMENT.md](DEVELOPMENT.md)

---

## 0. Quick orientation (six bullets)

1. **Two files do almost everything**: `index.html` (frontend) + `api.php` (backend). Everything else is assets, licence or deployment config.
2. **The source of truth is the server**, not the browser. The frontend keeps `state` and a localStorage copy; on login and sync the server record wins.
3. **The frontend `state` and the server `st` are the same JSON shape.** Add a field in one place and you must register it in `statePayload()` / `applyState()` / `saveRow` protection.
4. **Every write carries `ver`** (optimistic lock). Send the wrong version and the write is rejected as `conflict` — silently, without an error toast.
5. **Credentials never live in the repo.** DB password, secret and admin password are in `config.php` (not committed); `api.php` only holds placeholder defaults.
6. **`data/` is internal.** It holds PIN v2 data, rate-limit counters, tombstones and the audit log. It must be denied at the web-server level (`.htaccess` / `nginx.conf`).

---

## 1. How this differs from upstream

| Area | Upstream | This project |
|---|---|---|
| Storage | localStorage only | MySQL primary, `data/store.php` fallback, localStorage as cache |
| Accounts | Single local "user" | Multi-account (`provider:account` key), unique nickname site-wide |
| Auth | Frontend-only | Server tokens (`utk` user, admin token), server PIN verification, rate limiting |
| Money | Local arithmetic | Server-side booking, both sides of a transfer, receipts and notifications |
| Admin | Frontend gate | Server scope check (`scopeOf/scopeCan`), sub-admin bound to one account |
| Concurrency | None | `ver` optimistic lock + `conflict`, per-version snapshot in `data/bak/` |
| Deletion | Remove local record | Tombstone + frozen UID, `gone` to stale clients |
| Secrets | Hard-coded in HTML | `config.php` outside the repo, `?a=source` never exposes them |

What is intentionally kept from upstream: the single-file frontend style, the card art and layout language, the exchange/QR interaction, the offline-first feel.

---

## 2. Directory responsibilities

```
index.html            the entire frontend; no build step, no modules
api.php               the only endpoint; every action is a case in one switch
config.php            private config (git-ignored): CFG / SECRET / ADMIN_USER / ADMIN_PASS
data/                 runtime state, writable, never publicly reachable:
  store.php           file-based storage when MySQL is unavailable
  root.json           root admin credentials ({user,pass,ts}); overrides config.php when present
  admins.json         sub-admin list and their bound account keys
  pinv2.json          PBKDF2 PIN records (v2)
  rl.json             rate-limit counters (per action/key, date-stamped)
  tomb.json           delete tombstones
  frozen.json         frozen in-app UIDs (never reissued)
  audit.log           audit trail (JSON lines)
  db.json             last-resort key-value store
  rates.json          cached FX rates
  bak/                previous snapshot per account write (latest 20 kept)
img/                  brand, card art, sample balances, QQ icon
ocr/                  Tesseract.js 5.1.1 worker + core wasm + eng pack (offline OCR)
docs/                 README screenshots
LICENSES/             third-party full licence texts
```

---

## 3. Backend (`api.php`)

### 3.1 Entry and responses

- Every request is a single `switch` on `$_GET['a']` or the body's `act`.
- Input: JSON body (preferred) or form-encoded; output is always JSON.
- **HTTP status is always 200**; success/failure lives in `ok`. Codes you will see:

| Code | Meaning |
|---|---|
| `auth` | No/invalid token or insufficient scope |
| `bad_credentials` | Wrong admin username/password |
| `bad_pin` / `exhausted` | Wrong PIN; attempts used up |
| `locked` (`wait`) | Locked by rate limiting, seconds remaining |
| `gone` | Account was deleted (tombstone) |
| `conflict` | `ver` mismatch, write rejected |
| `pin_mismatch` | Legacy PIN digest no longer matches cloud |
| `no_user` / `not_found` | Account/card not found |
| `bad_pass` / `same_pass` | PIN change validation (length/cooldown or identical) |
| `need_current` / `bad_current` | Admin credential change requires the current password / it was wrong |
| `cfg_write_fail` | Credential change could not sync `config.php` (rolled back) |
| `limit` | Business limit exceeded (`limCheck`) |

### 3.2 Data model

One table, one row per account:

| Column | Meaning |
|---|---|
| `k` | Account key `provider:account` (primary key) |
| `p` | Provider (`guest` / `qq` / `bili`) |
| `a` | Platform account |
| `name` | Nickname, unique site-wide |
| `avatar` | Avatar (URL or data URI) |
| `pin` | Legacy PIN digest, kept only for compatibility |
| `st` | **Whole business state as JSON** |
| `ver` | Version for optimistic locking |
| `upd` | Last update timestamp (ms) |

Inside `st` the important blocks are: `user` (uid, nickname, avatar, real-name flag), `card` (state, number, holder, expiry, CVC, tier), `tx` (transactions), `notifs`, `receipts`, `lim` (per-day/month/year counters), `acct` (account type), `pcuid`.

Rules:

- The server **owns** `lim`, `cardState`, `pcuid`, and the limit counters; it rewrites them on save so a modified client cannot exceed limits.
- `tx` / `notifs` / `receipts` are trimmed server-side (`$RECEIPT_MAX=30`, `$NOTIF_MAX=60`).
- Everything else round-trips untouched.

### 3.3 Optimistic lock, snapshots, tombstones

```
push {k, st, ver}  ──►  row.ver !== ver  ──►  {ok:0, err:'conflict'}  (nothing written)
                    └►  match           ──►  write st, ver+1, snapshot old row to data/bak/
delete             ──►  tomb.json entry + frozen.json uid  ──►  later push → {err:'gone'}
```

- Snapshots are `data/bak/<key>.<ver>.json`, latest 20 per account, pruned automatically.
- `gone` also tells the client to wipe its local copy, so a deleted account cannot come back by reloading an old page.
- Frozen UIDs are permanent: a new account can never inherit a deleted account's in-app UID.

### 3.4 Three-layer authentication (`$SCOPE`)

| Layer | What it proves | Where |
|---|---|---|
| 1. Admin token | Caller is an authenticated admin, with `root` or `sub` scope | `scopeOf($tok)` against `SECRET`-signed token |
| 2. User token (`utk`) | Caller owns that account record | `userScopeOf($k,$utk)` |
| 3. PIN digest | Legacy compatibility path: body carries the PIN digest matching the cloud value | `push` only |

`scopeCan($k)` is the single decision point for "may this admin touch this account": root ⇒ all; sub ⇒ only keys in `bound`. Anything exported (JSON/CSV/backup/dump) is root-only.

> **Never** implement an authorisation check in the frontend only. The frontend gates are UX; `api.php` is the boundary. `?a=source` lets anyone read the code.

### 3.5 PIN evolution

```
v1: sha256(PIN)                      legacy, unsalted, enumerable offline
v2: PBKDF2-SHA256(PIN, salt, 100k)    data/pinv2.json, upgraded lazily on first successful verify
```

- Verification order: v2 record first, then legacy digest; on legacy success the v2 record is written immediately (lazy upgrade), so old accounts migrate without user action.
- 4-digit PINs are inherently weak (10,000 possibilities). Rate limiting is the real control: 5 failures ⇒ 600 s lock, and the failure counter is per account, date-stamped in `rl.json`.

### 3.6 Limits (`limCheck` / `limAdd`)

| Tier | Per transaction | Per day | Per year | Tx per day | FX purchase |
|---|---|---|---|---|---|
| Tier 2 (no card) | 10,000 | 10,000 | 200,000 | 10 | forbidden |
| Tier 1 (card issued) | 50,000 | 2,000,000 | — | — | allowed |

Counters live in `st.lim` with date stamps; `limCheck` rolls them over when the date changes, `limAdd` accumulates. Balance-type actions always run **check → do → add**, never add first.

### 3.7 Action list

| `act` | Auth | Purpose |
|---|---|---|
| `ping` | none | Health check: php version, storage backend |
| `get` | none / owner / admin | Account metadata; full `rec` only for owner or admin |
| `auth` | admin credentials | Root admin login → admin token |
| `admauth` / `admlogin` | — | Sub-admin listing and login |
| `push` | utk / admin / PIN digest | Save state (requires `ver`) |
| `userauth` | PIN | Server-side PIN verify, returns `utk` |
| `create` | throttled | Create account record (200/hour) |
| `transfer` | utk | Card number + holder name transfer |
| `conv` | utk | FX buy/sell |
| `card` | utk | Issue / freeze / activate |
| `receipt` | utk | Fetch or re-render an e-receipt |
| `notif` | utk | Notification list / mark read |
| `list` | admin | Account list scoped by admin |
| `force` | admin | Force push a state (admin edit) |
| `delete` | root | Delete account (tombstone + freeze UID) |
| `dump` | root | Full backup JSON |
| `prune` | root | Remove local-only leftovers |
| `rootset` | root + current password | Change root admin credentials (writes `root.json`, syncs `config.php`) |
| `adminset` | root | Manage sub-admins and their bound account |
| `source` | none | Serve `index.html` source for the licence panel download |

### 3.8 Changing the root credentials (hardened design)

> Status: **live on the deployed instance, not yet merged into this repository.** The `rootset` in the `api.php` shipped here does not require the current password, accepts 6-character passwords, writes `root.json` only, and does not reject an unchanged password; `index.html` still embeds `ADMIN_HASH` and the offline fallback. This section is the target design to port against.

#### Credential precedence

```
config.php: ADMIN_USER / ADMIN_PASS    ← deployment seed (fallback)
data/root.json: user / pass / ts       ← when present it overrides config (effective)
```

Both are read at startup; an unreadable or empty `root.json` simply does not override. The old "change it and forget it" failure came from updating one file while the other kept the previous value, with the frontend judging by a third artefact (`ADMIN_HASH`) — so nobody could get in.

#### Contract

```
POST api.php
{
  "act": "rootset",
  "t":   "<admin token>",   // must carry the root role
  "op":  "<current password>",  // required, second confirmation
  "u":   "<new username>",   // optional
  "p":   "<new password>"    // optional
}
→ { ok:1, user:"…", pass_changed:true, msg:"…" }
```

#### Validation matrix (in order)

| # | Check | Failure |
|---|---|---|
| 1 | `SCOPE['role'] === 'root'` | `auth` |
| 2 | `rlLocked('!admincred')` (5 attempts / 600 s) | `locked` + `wait` |
| 3 | `op` present | `need_current` |
| 4 | `hash_equals($ADMIN_PASS, $op)` | `bad_current` + `left`/`wait`, plus `rlFail` and an audit entry |
| 5 | at least one of `u` / `p` changed | `nothing` |
| 6 | username matches `^[A-Za-z0-9_.-]{3,20}$`, not colliding with `subAll()` | `bad_name` / `name_taken` |
| 7 | new password 8–64 characters | `bad_pass` |
| 8 | new password differs from the current one | `same_pass` (rejects a fake change so the old value is really retired) |
| 9 | `rootSave()` writes `data/root.json` | `write_fail` |
| 10 | `cfgAdminSave()` syncs `config.php` | `cfg_write_fail` (with `root.json` rolled back) |

#### Write path (`cfgAdminSave`)

```
read config.php
  → preg_replace_callback replaces exactly the two lines ('ADMIN_USER' => '…' / 'ADMIN_PASS' => '…'),
    returning the value from the callback so $ and backslashes in the password are not interpreted
  → back up the old file to data/bak/config.prev.php
  → write a temp file data/cfg.<rand>.tmp and check its length
  → rename() atomically into place
  → read back and confirm both lines hold the new values; otherwise why=verify
```

Every failure carries a `why` (`missing` / `read` / `pattern` / `tmp` / `rename` / `verify`) and appends `rootset fail cfg_<why>` to the audit log. On any failure `rootset` first calls `rootSave(oldUser, oldPass)` and then returns `cfg_write_fail` — **the old credentials stay valid** and no split state ("server has the new value, file has the old one") is left behind.

#### Why the old token and old password die

```
admToken() = hash_hmac('sha256', 'admin|v1|' . ADMIN_USER . '|' . hash('sha256', ADMIN_PASS), SECRET)
```

Any credential change breaks the signature, so the old token receives `auth` on every `$SCOPE` action, and the old password fails both `auth` (console login) and `rootset` (current-password check). On success the frontend calls `adminClose()` (clearing the `sessionStorage` admin keys) and returns to login, so no stale-but-still-on-screen admin session remains.

#### Audit

```
rootset fail bad_current
rootset fail cfg_<why>
rootset ok user=<new user> pass_changed=<0|1>
```

`data/audit.log` and `data/bak/config.prev.php` both live under `data/`, denied to the public by `.htaccess` (`RewriteRule ^data/ - [F,L]`) and `nginx.conf` (`location ~ ^/data/ { deny all; }`).

#### The only recovery when the password is forgotten

The flow needs the current password and has no self-service reset. Edit `data/root.json` through the hosting panel / FTP to `{"user":"…","pass":"…","ts":…}`; saving takes effect immediately (overriding `config.php`) and invalidates the old token. Then log in and run one normal change so `config.php` is realigned. **Never** put a recovery password into this repository or this document.

---

## 4. Frontend (`index.html`)

### 4.1 Sections

| Section | Contents |
|---|---|
| `<style>` | All CSS: design tokens, components, responsive `clamp()`, motion |
| View HTML | `view-login / onboard / review / appeal / home / card / managers / exchange / transfer / receive / apply / admin` |
| jsQR v1.4.0 | Embedded QR library (Apache-2.0), works offline |
| Main `<script>` | State and persistence, view switching, login, business modules, admin, compliance panels |

### 4.2 State and persistence (read before changing fields)

`const state` is the single source of truth on the client. Persistence trio:

```
statePayload()   decides what goes to the cloud / local disk — new fields must be registered
applyState(raw)  deserialise + validate + defaults — new fields must have a fallback
saveState()      write localStorage (STATE_KEY)
localSave(p)     write the payload locally
```

Storage keys: `STATE_KEY=haitecard.state.v1`, `ACCT_KEY=haitecard.accounts.v1` (local account table: `p/a/pass/pin/name/avatar`), `LOGIN_KEY`, `UNLOCK_KEY=haitecard.unlock.v1` (sessionStorage, skip PIN on refresh), `API_T_KEY=haitecard.api.t` (user token), `API_ADM_KEY=haitecard.api.adm` (admin token, sessionStorage).

### 4.3 View switching

`views` + `VIEW_ORDER` + `go(name)` / `activate(name)`. The level number decides slide direction (forward from the right, back from the left). **New standalone page**: add `<section class="view" id="view-xxx">`, register in `views`, give a level in `VIEW_ORDER`, call `go('xxx')` from the entry point.

New **modal / sheet**: use `openModal(html, onMount)`; `data-close` inside the template is bound automatically.

### 4.4 Login and PIN

```
lgOAuthSubmit()  third-party login (async)
  ├ local record exists → verify local password digest → PIN verify if present, else setup
  └ no local record    → GET get first: cloud found && hasPin ⇒ device switch (verify)
                         else treat as new: nickname/avatar → setup → commitAcct → lgAfterAuth
lgShowPin(mode, after)  mode = setup | verify
  ├ setup: state.pin = lgHashPin(PIN) → commitAcct → after()
  └ verify: POST userauth{k, pin} →
        gone     → delete local record, notify
        bad_pin  → "N attempts left"
        locked   → show remaining seconds
        ok       → store srv.t (apiSetToken) → applyState(srv.rec.st) → register account → lgAfterAuth()
        unreachable → fall back to local digest comparison (offline)
lgAfterAuth()  set session → apiPullAccount (server wins) → only if absent use cache and create
```

### 4.5 Cloud sync (the easiest place to break things)

```
apiCall(act, body)   one POST helper, attaches t (admin token first, then user token)
apiSyncNow()         push current state: {k,p,a,name,avatar,pin,st,ver}
  response routing (order matters):
    gone          → wipe local state + log out
    auth          → toast once "PIN verification required"
    pin_mismatch  → overwrite local with server rec (released by server)
    !ok           → return
    conflict      → overwrite local with d.rec, update apiVer
    d.t           → accept the returned user token
    d.ver         → update apiVer
apiPullAccount(key)  GET get → applyState only when rec exists
apiForcePush(key,st) admin force push (force=1, admin token)
```

### 4.6 Home statistics (fixed this round)

- `recordTx(entry)` records all flow (direction `dir=in/out`, currency `cur`, status `st`).
- `spendStat(days)` counts only `dir==='out'` entries whose status is not failed/reversed/returned/rejected; HHS spending is converted to HFS at the `sell` quote (≈6.7167).
- Home shows `spendStat(0)`, `spendStat(30)`, `state.cashback`. **Do not** hard-code `todaySpend/monthSpend` defaults again.

### 4.7 Admin console

```
adminRole()   only explicit sessionStorage 'root' is root; everything else is sub   ← privilege fix, do not revert to || 'root'
adminOpen(role,name,bound)  writes only with a valid role; otherwise keeps existing
enterAdmin()  not logged in → openAdminLogin(); else → go('admin')
adminClose()  clears admin sessionStorage keys + apiSetAdminToken('') (no lingering root credential)
renderAdmin() filters by scope: sub sees bound accounts only; root sees all
admRows()     overlays the "local only" badge (SERVER_KEYS vs server list)
#adm-prune    root-only: clean up records that exist only locally
#adm-export / #adm-csv / #adm-dump   export JSON / CSV / full backup
```

Admin write operations (balance edits etc.) go through `admPersist` → `apiForcePush` (`force:1`); a sub-admin's `force` only applies to its bound account (server `$force` check).

### 4.8 About / Credits / Licence

- Entry: home "More" sheet (`#more-about` / `#more-thanks`) and the "open source" row (`#open-license`).
- `ABOUT_LINKS = { repo, issues, prs }`: **fill the three URLs after open-sourcing**; while empty they render as grey "to be filled" placeholders and become clickable once set (`aboutRowHtml`).
- `openLicensePanel()`: copyright / modification notice / download this page's source / `api.php?a=source` / LICENSE.txt / NOTICE / data-processing note.

---

## 5. Changes in this round (vs upstream and vs the previous version)

| # | Change | Previous problem | Implementation (key function / location) |
|---|---|---|---|
| 1 | Home spending was always 3.18 | `todaySpend/monthSpend` hard-coded, never updated | Default 0; new `spendStat(days)` + `txHfsValue()` computed from `state.tx`; `renderState()` calls them |
| 2 | First launch added a "+11 cash deposit" without changing the balance | `initApp()` seeded a fake transaction that never booked | Seed removed; historical bad records filtered on load (`name==='现金账户存入' && amt===11 && cur==='HFS'`) |
| 3 | A sub-admin logged in as "root admin" | `adminRole()` defaulted to `'root'`; `enterAdmin()` called `adminOpen()` with no args | Role is explicit root only; `adminOpen` no longer overwrites by default; `enterAdmin` only navigates; lists filtered by `bound`, export/backup hidden for sub |
| 4 | Illusion of "5 more accounts in the cloud" | Old backend merged without deleting; local leftovers looked like cloud data | `SERVER_KEYS` comparison → "local only" badge; `#adm-prune` self-service cleanup |
| 5 | Anyone could read a record (including the PIN digest) | `get` returned the whole `rec` unauthenticated | `get` narrowed: full `rec` only for owner (`utk` or admin scope), otherwise metadata only |
| 6 | Anyone could write a record / create accounts | `push` was open | Three-channel auth + creation throttle + `err:'auth'` audit entries |
| 7 | 4-digit PIN could be brute-forced online and enumerated offline | Unsalted sha256, unlimited attempts | `userauth` server check + 5 attempts / 600 s + lazy PBKDF2 upgrade (`pinV2*`) |
| 8 | Admin credential stayed in the browser | One token mixed for both roles | Token separation (localStorage / sessionStorage); `adminClose` clears the admin token; `apiToken` prefers admin |
| 9 | Switching devices created a duplicate record | Old flow treated "cloud already has it" as a new account | `lgOAuthSubmit` probes `get{found,hasPin}` → verify; `commitAcct()` on success |
| 10 | Sync error handling was dead code | `gone`/`auth` checks sat after `!d.ok` return | Routing order rearranged; `pin_mismatch` moved inside `!d.ok` |
| 11 | `/data/` was publicly downloadable | No access control | `.htaccess` part 5: `RewriteRule ^data/ - [F,L]` + `FilesMatch` deny `.json/.log/.py/.md/.conf/.sql/.bak/.ini` |
| 12 | New "About / Credits" pages | Did not exist | Two rows in the "More" sheet → `openAboutSheet()` / `openThanksSheet()`, reusing `.more-row` and `.about-txt` |

---

## 6. How to extend

### 6.1 Add a server action

```php
case 'demo': {
  if (!$SCOPE) jout(['ok' => 0, 'err' => 'auth']);          // check scope if admin-only
  $k = (string)($in['k'] ?? '');
  $row = pcGet($k);
  if (!$row) jout(['ok' => 0, 'err' => 'no_user']);
  $st = stOf($row);
  // ... mutate $st ...
  $r = saveRow($row, $st, true, -1);                        // force=1 ignores ver
  jout(['ok' => 1, 'st' => $st, 'ver' => $r['ver'] ?? 0]);
}
```

Checklist: ① authenticate first (`$SCOPE` / `userScopeOf` / `$okPin`); ② balance actions run `limCheck` before `limAdd`; ③ emit receipts/notifications with `pushRcp` / `pushNotif` / `pushTx`; ④ persist with `saveRow`; pass the real `ver` instead of `-1` when concurrency matters.

### 6.2 Add a frontend page

1. Add `<section class="view" id="view-demo">…</section>` in the views area;
2. `views.demo = document.getElementById('view-demo')`;
3. `VIEW_ORDER.demo = 1` (1 = secondary, 2 = tertiary; controls slide direction);
4. Navigate with `go('demo')`; the back button calls `go('home')`.

For display-only content, the cheaper route is `openModal(...)`, as in `openAboutSheet()`.

### 6.3 Add a state field

1. Add it to the `state` defaults with a comment on semantics;
2. Add it to `statePayload()` (otherwise it is lost on refresh and on sync);
3. Add validation and fallback in `applyState()` (old data without the field must not break);
4. If the server is authoritative (limits, card state, UID), register it in the `push` "authoritative fields" array so an old client cannot overwrite it.

### 6.4 Deploy and operate

- Upload: `index.html`, `api.php`, `config.php` (private; safe to upload last, but before `api.php` is safer), `bili-card.php`, `.htaccess`, `img/`, `ocr/`, `LICENSE.txt`; `data/` must be writable (`0705`).
- Tables: DDL in §3.2; the database name may be left empty for auto-detection.
- Credentials: `$CFG` / `SECRET` / `ADMIN_USER` / `ADMIN_PASS` in `config.php` (same directory, never in the repo). Changing `SECRET` invalidates every token (all users must log in again). **Never** write passwords back into `api.php`.
- The frontend `ADMIN_HASH` in `index.html` must equal `sha256Hex('pinkclaw::admin::' + user + '::' + pass)`, otherwise the admin entry rejects valid credentials.
- `data/` must never be publicly readable (bundled `.htaccess`; for Nginx use `location ~ ^/data/ { deny all; }`).
- Health check: `GET api.php?a=ping` → `{"ok":1,"php":"8.4.x","store":"mysql"}`. A 500 means PHP syntax/extension problems — roll back the previous version first.
- Backup: the console's "full backup JSON" (`dump`) contains PIN digests and balances; **do not share it publicly**.

> **Known drift (as of 2026-11-23):** the live instance `fenzhua.yzz.me` runs a hardened credential flow that is **not yet in this repository** — the root password change requires the current password, writes `data/root.json` and syncs `config.php` atomically (rollback on failure), and `index.html` no longer carries `ADMIN_HASH` (no offline fallback login). The design is written down in §3.8; until the code here is updated, follow the deployment notes above for this repository, and see §7 item 6. The demo credentials in this repository remain `xiaozhi888` / `xz668866` regardless.

### 6.5 Minimal regression set after any change

| # | Case | Expected |
|---|---|---|
| 1 | `ping` | `ok:1`, `store` matches reality |
| 2 | New account: login → PIN → create record | Record created, 96-character `utk` returned |
| 3 | Existing account, correct PIN | `userauth` → `ok:1` + token |
| 4 | 5 wrong PINs in a row | `left` decreases then `wait:600`; correct PIN still `locked` |
| 5 | Anonymous `get` on an existing account | Only `found/gone/hasPin/name`, no `rec` |
| 6 | `push` without a token | `err:'auth'` |
| 7 | `push` with a token | `ok:1`, `ver` +1; replaying the old `ver` → `conflict:1` |
| 8 | Transfer (card number + holder name, wrong holder) | `not_found` for the wrong holder; correct case books both sides + receipts |
| 9 | FX buy/sell | Direction correct (buy uses the sell quote); tier 2 cannot buy FX |
| 10 | Root edits a balance in the console | Frontend sees the same balance after reload |
| 11 | Sub-admin | Sees only bound accounts; `dump` → `err:auth` |
| 12 | Delete an account, then sync from the old page | `err:'gone'`, local copy wiped |
| 13 | Direct link to `data/pinv2.json` | 403 |

### 6.6 Common pitfalls

- **Functions live inside an IIFE**: console scripts and automation cannot reach `openAboutSheet` and friends; drive the UI, or temporarily expose the function on `window` while debugging.
- **Lint PHP locally first**: `php -l api.php` when PHP is available. Without PHP on the dev machine, fall back to a bracket/quote balance check plus an immediate `ping` after upload; on 500 roll back at once.
- **`push` must carry `ver`**: omit it and the write is judged `conflict` and **silently dropped** — the symptom is "the action succeeded but reverted after refresh".
- **Download checks**: some embedded browsers block downloads; prove the result with a toast or a network request instead.
- **Keep `p` (provider), `a` (account) and `act` (action) apart**: `?a=` is the action, `body.a` is the account name; mixing them silently takes the wrong branch.
- **The source still contains a "bank-style bid/ask" comment** (above `conv`). It is a comment only and not user-facing, but a neutral wording is preferable for open source.

---

## 7. Known limitations / TODO

1. 4-digit PIN strength ceiling (10,000 combinations): rate limiting reduces online brute force to roughly 720 attempts/day per account, but stronger credentials remain the right direction.
2. Compatibility path: `push` still accepts a PIN digest matching the cloud; once stale cached pages are gone this can be reduced to tokens only.
3. Compliance TODOs (see `合规检查报告-上游要求对照-20261122.md`): the licence panel's "non-commercial use" wording conflicts with GPL §10; NOTICE omits jsQR; the Apache-2.0 full text and `source.zip` were missing; brand/asset authorisation unconfirmed.
4. The mapping between `img/*.webp` and upstream base64 assets has not been checked image by image.
5. Root admin credentials still exist as constants (overridable by `data/root.json`); an environment variable or install wizard is recommended before going public.
6. Port the live hardening to this repository (design in §3.8): a current-password confirmation and the `!admincred` limiter in `rootset`, an 8–64 character new password that must differ from the old one, `cfgAdminSave()` syncing `config.php` atomically with a `root.json` rollback on failure, removal of `ADMIN_HASH` and the offline fallback login, and clearing the admin session on success. Before porting, confirm the repository's demo credentials `xiaozhi888` / `xz668866` stay unchanged.

---

## 8. Changelog

### 2026-11-22

- Fix: home spending always 3.18; fake "+11 cash deposit" on first launch.
- Fix: sub-admin escalated to root; "local only" badge and one-click cleanup in the console.
- Security: user token `utk` and `userauth`; `get` narrowed; `push` three-channel auth + creation throttle; PIN lazily upgraded to PBKDF2; login rate limiting; audit log.
- Security: token separation (user localStorage / admin sessionStorage); leaving the console clears the admin token.
- UX: device-switch login goes through PIN verification; sync error routing order fixed.
- New: "About / Credits" pages and the "open-source licence and source" panel; `ABOUT_LINKS` placeholders.
- Security: `.htaccess` blocks public reads of `data/` and `.json/.log/.py/.md` and similar internal files.
- Security: database and root credentials moved to `config.php` (`.gitignore`d, not in the repo); `api.php` keeps placeholders only; the frontend's plaintext password comment removed, digest constant kept.
- Open source: repository published on GitHub; About page, home "source" row and licence panel all point at this repository, upstream attribution preserved.
- Docs: this `DEVELOPMENT.md`.
- Docs: rewritten `README.md` (positioning, upstream relation, changes, features, usage, deployment, FAQ).
- Policy: the default root admin remains **usable out of the box** (see README) so others can log into their own deployment; replace it before public promotion (`config.php` / `data/root.json` / `ADMIN_HASH` in sync).

### 2026-11-23

- Docs: added `README.en.md`, `DEVELOPMENT.en.md` and `CHANGELOG.en.md`; language switch links at the top of every document.
- Docs: added §3.8 "Changing the root credentials (hardened design)" with credential precedence, the `rootset` contract, the ten-step validation matrix, the `cfgAdminSave` write and rollback path, token invalidation, audit entries and the single recovery route; the same section exists in the Chinese manual.
- Docs: both READMEs gained a "root credential change (hardened flow)" section and two FAQ entries; the repository's demo credentials are explicitly documented as unchanged.
