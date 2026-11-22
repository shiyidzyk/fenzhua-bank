#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""粉爪银行 · 卡包 —— 本地静态服务器（零依赖，仅需 Python 3）

许可与来源
    许可：GNU General Public License v3.0（GPL-3.0），全文见同目录 LICENSE.txt
    上游：https://gitee.com/hiteex/haitecard-web3  Copyright (C) 2026 Hiteex Team
    修改声明：本文件为改造版（modified），修改日期 2026-11-22

用法：
    python3 serve.py                # 默认 http://localhost:8777
    python3 serve.py 8080           # 指定端口
    PORT=8080 python3 serve.py      # 环境变量指定端口

说明：
- 单文件 H5 应用，无需构建；此脚本只负责把当前目录当静态站点发布。
- 已关闭缓存，改完 index.html 刷新即可看到最新版本。
- 额外提供 /bili-card.php?mid=<UID>：本地没有 PHP 时，由本脚本代取 B 站公开资料，
  使「哔哩哔哩账号同步」在本地也能用（线上由同目录的 bili-card.php 承担同一职责）。
"""

import json
import os
import sys
import urllib.parse
import urllib.request
from functools import partial
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer

ROOT = os.path.dirname(os.path.abspath(__file__))
UA = ("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
      "(KHTML, like Gecko) Chrome/124.0 Safari/537.36")


def bili_card(mid: str):
    """转发到 B 站公开接口，返回 (状态码, JSON 文本)。"""
    url = "https://api.bilibili.com/x/web-interface/card?mid=" + mid
    req = urllib.request.Request(url, headers={
        "User-Agent": UA,
        "Referer": "https://www.bilibili.com/",
        "Accept": "application/json",
    })
    try:
        with urllib.request.urlopen(req, timeout=8) as r:
            body = r.read()
        data = json.loads(body.decode("utf-8", "replace"))
        if not isinstance(data, dict) or "code" not in data:
            return 502, json.dumps({"code": -2, "message": "bad upstream response"})
        return 200, json.dumps(data, ensure_ascii=False)
    except Exception as e:                     # 网络不可用时明确报错，不静默
        return 502, json.dumps({"code": -2, "message": "upstream unavailable: %s" % e})


class Handler(SimpleHTTPRequestHandler):
    extensions_map = {
        **SimpleHTTPRequestHandler.extensions_map,
        ".html": "text/html; charset=utf-8",
        ".js": "application/javascript; charset=utf-8",
        ".json": "application/json; charset=utf-8",
        ".php": "application/json; charset=utf-8",
        ".svg": "image/svg+xml",
    }

    def end_headers(self):
        self.send_header("Cache-Control", "no-store, must-revalidate")
        self.send_header("X-Content-Type-Options", "nosniff")
        super().end_headers()

    def log_message(self, fmt, *args):
        sys.stderr.write("[%s] %s\n" % (self.log_date_time_string(), fmt % args))

    def do_GET(self):
        path = urllib.parse.urlparse(self.path).path
        if path in ("/bili-card.php", "/bili-card.json"):
            qs = urllib.parse.parse_qs(urllib.parse.urlparse(self.path).query)
            mid = "".join(ch for ch in (qs.get("mid", [""])[0]) if ch.isdigit())[:12]
            if not mid:
                self._send_json(400, json.dumps({"code": -1, "message": "bad mid"}))
                return
            code, text = bili_card(mid)
            self._send_json(code, text)
            return
        super().do_GET()

    def _send_json(self, code, text):
        raw = text.encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)


def main():
    port = 8777
    if len(sys.argv) > 1 and sys.argv[1].isdigit():
        port = int(sys.argv[1])
    elif os.environ.get("PORT", "").isdigit():
        port = int(os.environ["PORT"])

    handler = partial(Handler, directory=ROOT)
    httpd = ThreadingHTTPServer(("0.0.0.0", port), handler)
    print("粉爪银行 · 卡包 已启动：")
    print("  本机访问  http://localhost:%d/" % port)
    print("  局域网访问 http://<本机IP>:%d/" % port)
    print("  按 Ctrl+C 停止")
    try:
        httpd.serve_forever()
    except KeyboardInterrupt:
        print("\n已停止")
    finally:
        httpd.server_close()


if __name__ == "__main__":
    main()
