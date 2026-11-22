# Changelog

**English** | [中文](CHANGELOG.md)

## 2026-11-22 · First public release

Open-sourced on GitHub under GPL-3.0 for the first time (this repository is the corresponding source of <https://fenzhua.yzz.me/>).

**For users**

- Home spending statistics now computed live from transactions (today / last 30 days); removed the hard-coded 3.18 and the fake first-launch transaction
- Two-currency wallet, transactions, card issue / freeze / activate, tier-1 and tier-2 limits
- Transfer matched on card number **and** holder name, both sides booked, receipts and in-app notifications for both parties
- Fons / Huanshi FX buy and sell; FX purchase disabled for tier-2 accounts
- E-receipts, notification centre, About / Credits / open-source licence panels
- Device switch: probe the cloud for an existing account first, then verify the PIN instead of creating a duplicate record

**Administration and security**

- Added the user token `utk` and server-side PIN verification (`userauth`); PIN digests upgraded to PBKDF2 (lazily)
- `get` narrowed: only the owner or an authorised admin receives the full record
- `push` protected by three channels (user token / admin scope / PIN digest matching the cloud) plus account-creation throttling
- Login rate limiting (5 failures / 600 s) and an audit log
- Fixed sub-admins being escalated to root admin; added the "local only" badge and the root cleanup entry
- Token separation: user token in localStorage, admin token in sessionStorage, cleared when leaving the console
- `.htaccess` blocks public reads of `data/` and internal files such as `.json/.log/.py/.md`
- Credentials externalised to `config.php` (not in the repository); `api.php` keeps placeholder defaults only

**Documentation**

- Added `DEVELOPMENT.md` (architecture, API, change list, extension guide, regression cases)
- Added `NOTICE.txt` and `LICENSES/Apache-2.0.txt`

## 2026-11-23 · English documentation

- Added `README.en.md` — full English README: positioning, upstream relation, change tables, feature list, screenshots, usage, eight deployment steps, repository layout, stack and data flow, FAQ, licence
- Added `DEVELOPMENT.en.md` — English developer and handover manual covering the backend data model, optimistic lock, three-layer auth, PIN evolution, limits, action list, frontend architecture, extension templates, the 13-case regression set and known limitations
- Added `CHANGELOG.en.md` — this file
- Every document now carries a language switch link at the top; `README.md`, `DEVELOPMENT.md` and `CHANGELOG.md` gained the English counterpart link
- Documented the known drift between the live instance's hardened admin-credential flow and the code currently in this repository
