<?php

return [

    'pdf' => [
        'enabled' => true,

        // ✅ ضع المسار بين اقتباس
      //  'binary' => '"C:\Program Files\wkhtmltopdf\bin\wkhtmltopdf.exe"',
'binary' => env('WKHTML_PDF_BINARY','/usr/bin/wkhtmltopdf'),
        'timeout' => false,
        'options' => [
            'encoding' => 'UTF-8',
            'page-size' => 'A4',
            'orientation' => 'Landscape',
            'margin-top' => 8,
            'margin-right' => 8,
            'margin-bottom' => 8,
            'margin-left' => 8,
            'enable-local-file-access' => true,
            // ✅ يساعد العربية أحياناً
            'disable-smart-shrinking' => true,
            'print-media-type' => true,
        ],
        'env' => [],
    ],

    'image' => [
        'enabled' => true,
       // 'binary'  => '"C:\Program Files\wkhtmltopdf\bin\wkhtmltoimage.exe"',
       'binary' => env('WKHTML_PDF_BINARY','/usr/bin/wkhtmltopdf'),
        'timeout' => false,
        'options' => [],
        'env' => [],
    ],
];


