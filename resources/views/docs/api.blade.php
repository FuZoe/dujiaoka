<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Shop API v1 文档</title>
    <style>
        :root {
            color-scheme: light;
            --ink: #18202a;
            --muted: #5f6b78;
            --line: #dce2e8;
            --wash: #f5f7f9;
            --accent: #0f6b57;
            --code: #17212b;
        }
        * { box-sizing: border-box; }
        html { background: var(--wash); }
        body {
            margin: 0;
            color: var(--ink);
            font: 15px/1.7 -apple-system, BlinkMacSystemFont, "Segoe UI",
                "PingFang SC", "Microsoft YaHei", sans-serif;
        }
        .doc-header {
            border-bottom: 1px solid var(--line);
            background: #fff;
        }
        .doc-header-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            max-width: 1040px;
            margin: 0 auto;
            padding: 22px 24px;
        }
        .doc-brand {
            margin: 0;
            font-size: 18px;
            letter-spacing: 0;
        }
        .doc-brand span {
            display: block;
            color: var(--muted);
            font-size: 12px;
            font-weight: 400;
        }
        .doc-link {
            color: var(--accent);
            font-size: 13px;
            text-decoration: none;
            white-space: nowrap;
        }
        .doc-link:hover { text-decoration: underline; }
        .doc-main {
            max-width: 1040px;
            margin: 0 auto;
            padding: 34px 24px 72px;
        }
        .markdown-body { min-width: 0; padding: 0 8px; }
        .markdown-body h1,
        .markdown-body h2,
        .markdown-body h3 {
            color: var(--ink);
            line-height: 1.3;
            letter-spacing: 0;
        }
        .markdown-body h1 {
            margin: 0 0 22px;
            font-size: clamp(28px, 5vw, 42px);
        }
        .markdown-body h2 {
            margin: 46px 0 16px;
            padding-bottom: 9px;
            border-bottom: 1px solid var(--line);
            font-size: 24px;
        }
        .markdown-body h3 { margin: 30px 0 12px; font-size: 18px; }
        .markdown-body p,
        .markdown-body ul,
        .markdown-body ol { margin: 0 0 16px; }
        .markdown-body a {
            color: var(--accent);
            overflow-wrap: anywhere;
        }
        .markdown-body code {
            padding: 2px 5px;
            border: 1px solid #e0e6eb;
            border-radius: 4px;
            background: #eef2f5;
            color: #244052;
            font: 0.92em/1.4 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        }
        .markdown-body pre {
            overflow-x: auto;
            margin: 18px 0 22px;
            padding: 17px 18px;
            border-radius: 6px;
            background: var(--code);
            color: #f4f7f9;
            box-shadow: 0 5px 18px rgba(23, 33, 43, .12);
        }
        .markdown-body pre code {
            padding: 0;
            border: 0;
            background: transparent;
            color: inherit;
            font-size: 13px;
            white-space: pre;
        }
        .markdown-body blockquote {
            margin: 18px 0;
            padding: 8px 18px;
            border-left: 3px solid var(--accent);
            background: #edf5f2;
            color: var(--muted);
        }
        .markdown-body table {
            display: block;
            width: 100%;
            overflow-x: auto;
            margin: 18px 0 24px;
            border-collapse: collapse;
            background: #fff;
        }
        .markdown-body th,
        .markdown-body td {
            min-width: 120px;
            padding: 10px 13px;
            border: 1px solid var(--line);
            text-align: left;
            vertical-align: top;
        }
        .markdown-body th { background: #eef2f4; font-weight: 650; }
        .markdown-body hr {
            margin: 32px 0;
            border: 0;
            border-top: 1px solid var(--line);
        }
        .markdown-body img { max-width: 100%; height: auto; }
        @media (max-width: 640px) {
            .doc-header-inner { padding: 18px 16px; }
            .doc-main { padding: 24px 16px 54px; }
            .markdown-body { padding: 0; }
            .markdown-body h2 { margin-top: 36px; }
        }
    </style>
</head>
<body>
<header class="doc-header">
    <div class="doc-header-inner">
        <h1 class="doc-brand">Shop API <span>接口参考文档 · v1</span></h1>
        <a class="doc-link" href="/docs/api.md">查看原始 Markdown</a>
    </div>
</header>
<main class="doc-main">
    <article class="markdown-body">
        {!! $content !!}
    </article>
</main>
</body>
</html>
