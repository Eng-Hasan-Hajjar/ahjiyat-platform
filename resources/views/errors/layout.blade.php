<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>خطأ @yield('code') - منصة إعمار</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;900&family=Cairo:wght@600;700;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary:      #2E7D4F;
            --primary-light:#4CAF72;
            --primary-pale: #E8F5EE;
            --accent:       #F4A024;
            --accent-light: #FFF3DC;
            --danger:       #E53935;
            --warning:      #FB8C00;
            --text-dark:    #1A2332;
            --text-mid:     #4A5568;
            --text-light:   #718096;
            --border:       #E2E8F0;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        html, body { height:100%; }
        body {
            font-family: 'Tajawal', 'Cairo', sans-serif;
            background: linear-gradient(135deg, #f0faf4 0%, #e8f5ee 40%, #fff8ed 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            color: var(--text-dark);
            position: relative;
            overflow: hidden;
        }
        body::before {
            content: '';
            position: absolute;
            top: -120px; right: -120px;
            width: 460px; height: 460px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(46,125,79,.10) 0%, transparent 70%);
        }
        body::after {
            content: '';
            position: absolute;
            bottom: -100px; left: -100px;
            width: 380px; height: 380px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(244,160,36,.09) 0%, transparent 70%);
        }
        .error-card {
            background: #fff;
            border-radius: 24px;
            box-shadow: 0 25px 70px rgba(0,0,0,.12);
            max-width: 480px;
            width: 100%;
            padding: 44px 40px 36px;
            text-align: center;
            position: relative;
            z-index: 1;
        }
        .brand {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--primary-pale);
            color: var(--primary);
            border-radius: 25px;
            padding: 6px 16px;
            font-family: 'Cairo', sans-serif;
            font-weight: 700;
            font-size: .85rem;
            margin-bottom: 28px;
        }
        .error-icon-wrap {
            width: 92px; height: 92px;
            border-radius: 24px;
            display: flex; align-items: center; justify-content: center;
            font-size: 2.6rem;
            margin: 0 auto 22px;
        }
        .error-code {
            font-family: 'Cairo', sans-serif;
            font-weight: 900;
            font-size: 3rem;
            color: var(--primary);
            line-height: 1;
            margin-bottom: 10px;
        }
        .error-title {
            font-family: 'Cairo', sans-serif;
            font-weight: 700;
            font-size: 1.3rem;
            color: var(--text-dark);
            margin-bottom: 12px;
        }
        .error-desc {
            color: var(--text-mid);
            font-size: .93rem;
            line-height: 1.85;
            margin-bottom: 30px;
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
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 700;
            font-size: .9rem;
            text-decoration: none;
            transition: transform .18s, box-shadow .18s;
            border: none;
            cursor: pointer;
            font-family: 'Tajawal', sans-serif;
        }
        .btn-e:hover { transform: translateY(-3px); }
        .btn-e-primary { background: var(--primary); color: #fff; box-shadow: 0 8px 20px rgba(46,125,79,.25); }
        .btn-e-outline { background: #fff; color: var(--primary); border: 2px solid var(--primary-pale); }
        .error-footer {
            margin-top: 26px;
            padding-top: 20px;
            border-top: 1px solid var(--border);
            font-size: .78rem;
            color: var(--text-light);
        }
    </style>
</head>
<body>
    <div class="error-card">
        <div class="brand">🏗️ منصة إعمار</div>
        <div class="error-icon-wrap" style="background: @yield('iconBg', '#E8F5EE');">@yield('icon')</div>
        <div class="error-code">@yield('code')</div>
        <div class="error-title">@yield('title')</div>
        <div class="error-desc">@yield('desc')</div>
        <div class="error-actions">
            @yield('actions')
        </div>
        <div class="error-footer">منصة إعادة الإعمار وربط المتطوعين</div>
    </div>
</body>
</html>