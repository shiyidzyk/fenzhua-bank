本地 OCR 资源（开户验资用）
================================

本目录是 index.html 验资功能所需的离线 OCR 引擎与语言包（同一个 worker 也用于开户时
名片卡截图里的 UID 比对），表单/流程逻辑不变，
只把「调用 AI 模型识别余额截图」换成本地 Tesseract.js 识别。

文件
----
tesseract.min.js                  Tesseract.js 5.1.1 主库（浏览器端按需注入）
worker.min.js                     Tesseract.js worker
tesseract-core-simd-lstm.wasm.js  tesseract.js-core 5.1.0，LSTM + SIMD 版本（新浏览器走这条）
tesseract-core-simd-lstm.wasm     同上，wasm 二进制
tesseract-core-lstm.wasm.js       tesseract.js-core 5.1.0，LSTM 无 SIMD（老浏览器兜底）
tesseract-core-lstm.wasm          同上，wasm 二进制
lang/eng.traineddata.gz           英文语言包（tessdata_fast 4.0.0，已 gzip）
                                  验资只识别数字，用 eng 包即可，无需 chi_sim（省 15MB+）

部署要求
--------
1. 整个 ocr/ 目录必须随站点一起上传，路径保持为「与 index.html 同级」。
2. .wasm 建议以 application/wasm 返回；.gz 不要被服务器强行加 Content-Encoding: gzip
   （nginx 参考配置已包含 location /ocr/ 处理）。
3. 首次点「验资」才加载（约 15MB），不影响首屏；之后同一页面内复用 worker。

版本与来源
---------
- Tesseract.js 5.1.1、tesseract.js-core 5.1.0：https://github.com/naptha/tesseract.js （Apache-2.0）
- eng.traineddata.gz：https://tessdata.projectnaptha.com/4.0.0_fast/eng.traineddata.gz （tessdata，Apache-2.0）

升级/替换方法
-------------
从 npm 包 dist/ 取出同名文件覆盖即可：
  npm pack tesseract.js@5.1.1        → dist/tesseract.min.js、dist/worker.min.js
  npm pack tesseract.js-core@5.1.0   → tesseract-core-*-lstm.wasm(.js)
语言包直接替换 lang/eng.traineddata.gz（保持 gzip 格式）。
