<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>خطأ @yield('code') - أحجيات</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { height: 100%; }
        body {
            font-family: 'Cairo', ui-sans-serif, system-ui, sans-serif;
            background-color: #060a17;
            background-image:
                radial-gradient(700px 400px at 90% -5%, rgba(139, 92, 246, .25), transparent 60%),
                radial-gradient(800px 450px at 5% 5%, rgba(56, 189, 248, .15), transparent 60%),
                radial-gradient(1000px 600px at 50% 110%, rgba(217, 70, 239, .12), transparent 60%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            color: #cbd5e1;
        }
        .error-card {
            background: rgba(255, 255, 255, .04);
            border: 1px solid rgba(255, 255, 255, .08);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-radius: 24px;
            box-shadow: 0 25px 70px rgba(0, 0, 0, .35);
            max-width: 460px;
            width: 100%;
            padding: 40px 32px 32px;
            text-align: center;
        }
        .brand {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, .05);
            border: 1px solid rgba(255, 255, 255, .1);
            color: #cbd5e1;
            border-radius: 999px;
            padding: 6px 16px;
            font-weight: 700;
            font-size: .85rem;
            margin-bottom: 26px;
        }
        .brand span {
            background: linear-gradient(135deg, #c4b5fd 0%, #f0abfc 45%, #fcd34d 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            font-weight: 900;
        }
        .error-icon-wrap {
            width: 84px; height: 84px;
            clip-path: polygon(50% 0%, 100% 25%, 100% 75%, 50% 100%, 0% 75%, 0% 25%);
            display: flex; align-items: center; justify-content: center;
            font-size: 2.3rem;
            margin: 0 auto 20px;
        }
        .error-code {
            font-weight: 900;
            font-size: 2.75rem;
            line-height: 1;
            margin-bottom: 10px;
            background: linear-gradient(135deg, #c4b5fd 0%, #f0abfc 45%, #fcd34d 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        .error-title {
            font-weight: 800;
            font-size: 1.2rem;
            color: #fff;
            margin-bottom: 12px;
        }
        .error-desc {
            color: #94a3b8;
            font-size: .92rem;
            line-height: 1.85;
            margin-bottom: 28px;
        }
        .error-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .btn-e {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 12px 22px;
            border-radius: 14px;
            font-weight: 800;
            font-size: .88rem;
            text-decoration: none;
            transition: transform .18s ease, box-shadow .18s ease;
            border: none;
            cursor: pointer;
            font-family: inherit;
        }
        .btn-e:hover { transform: translateY(-2px); }
        .btn-e-primary {
            background: linear-gradient(135deg, #7c3aed 0%, #d946ef 55%, #f59e0b 100%);
            color: #fff;
            box-shadow: 0 8px 30px -10px rgba(217, 70, 239, .5);
        }
        .btn-e-outline {
            background: rgba(255, 255, 255, .05);
            color: #cbd5e1;
            border: 1px solid rgba(255, 255, 255, .12);
        }
        .btn-e-outline:hover { border-color: rgba(167, 139, 250, .6); color: #fff; }
        .error-footer {
            margin-top: 24px;
            padding-top: 18px;
            border-top: 1px solid rgba(255, 255, 255, .08);
            font-size: .76rem;
            color: #64748b;
        }
        @media (max-width: 400px) {
            .error-card { padding: 32px 22px 26px; }
            .error-code { font-size: 2.3rem; }
        }
    </style>
</head>
<body>
    <div class="error-card">
        <div class="brand">✦ <span>أحجيات</span></div>
        <div class="error-icon-wrap" style="background: @yield('iconBg', 'linear-gradient(135deg, rgba(139,92,246,.35), rgba(217,70,239,.25))');">@yield('icon')</div>
        <div class="error-code">@yield('code')</div>
        <div class="error-title">@yield('title')</div>
        <div class="error-desc">@yield('desc')</div>
        <div class="error-actions">
            @yield('actions')
        </div>
        <div class="error-footer">منصة أحجيات · ألغاز وتحديات ذهنية</div>
    </div>
</body>
</html>