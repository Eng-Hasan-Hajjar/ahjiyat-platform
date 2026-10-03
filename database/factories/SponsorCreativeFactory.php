<?php

namespace Database\Factories;

use App\Models\SponsorCampaign;
use App\Models\SponsorCreative;
use Illuminate\Database\Eloquent\Factories\Factory;

class SponsorCreativeFactory extends Factory
{
    protected $model = SponsorCreative::class;

    public function definition(): array
    {
        return [
            'sponsor_campaign_id' => SponsorCampaign::factory(),
            'title' => 'عنوان راعٍ تجريبي',
            'body' => 'نص قصير تجريبي.',
            'cta_label' => 'اكتشف المزيد',
            'destination_url' => 'https://example.com/sponsor',
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
