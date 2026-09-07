<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>服务器错误 — ERP</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #0f172a;
            color: #e2e8f0;
            font-family: ui-sans-serif, system-ui, sans-serif;
            padding: 24px;
        }
        .card {
            max-width: 480px;
            width: 100%;
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 12px;
            padding: 40px 32px;
            text-align: center;
        }
        .icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: #7f1d1d;
            font-size: 24px;
            margin-bottom: 20px;
        }
        h1 {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 8px;
            color: #f1f5f9;
        }
        p {
            font-size: 14px;
            color: #94a3b8;
            line-height: 1.6;
            margin-bottom: 24px;
        }
        .error-id {
            display: inline-block;
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 6px;
            padding: 8px 16px;
            font-family: ui-monospace, monospace;
            font-size: 12px;
            color: #64748b;
            word-break: break-all;
            margin-bottom: 28px;
        }
        .back-link {
            display: inline-block;
            font-size: 14px;
            color: #60a5fa;
            text-decoration: none;
        }
        .back-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon" aria-hidden="true">!</div>
        <h1>服务器内部错误</h1>
        <p>系统遇到了意外问题，请稍后重试。如需联系支持，请提供以下错误 ID。</p>
        <div class="error-id" title="错误 ID">{{ $error_id }}</div>
        <br>
        <a href="javascript:history.back()" class="back-link">← 返回上一页</a>
    </div>
</body>
</html>
