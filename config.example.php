<?php
/* 复制本文件为 config.php 并填写真实值；config.php 已在 .gitignore 中，不会被提交。
   不填也能跑，但会退化为文件存储（data/store.php），且管理令牌使用占位密钥。 */
return [
  'CFG' => [
    'host'    => '127.0.0.1',
    'port'    => 3306,
    'user'    => 'db_user',
    'pass'    => 'db_password',
    'db'      => 'db_name',      // 留空则自动探测与用户名同名的库
    'timeout' => 6,
  ],
  // 所有令牌（用户 utk / 二级管理员 / 主管理员）的 HMAC 密钥；泄露等于令牌可伪造
  'SECRET'     => 'CHANGE-ME-random-32chars-please',
  // 默认主管理员（开箱可用）；对外公开前请替换，并同步 index.html 的 ADMIN_HASH
  'ADMIN_USER' => 'xiaozhi888',
  'ADMIN_PASS' => 'xz668866',
];
