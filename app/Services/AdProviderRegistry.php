<?php

namespace App\Services\Advertising;

use App\Services\Advertising\Providers\AdProviderContract;
use App\Services\Advertising\Providers\DirectSponsorProvider;
use App\Services\Advertising\Providers\GoogleAdSenseProvider;

/** E14 (بند 589): سجلّ أصناف صريح بالكود - لا اسم صنف يُقرَأ من قاعدة البيانات إطلاقًا. */
class AdProviderRegistry
{
    public function __construct(
        protected DirectSponsorProvider $direct,
        protected GoogleAdSenseProvider $google,
    ) {}

    public function direct(): AdProviderContract
    {
        return $this->direct;
    }

    public function external(): AdProviderContract
    {
        return $this->google;
    }
}
