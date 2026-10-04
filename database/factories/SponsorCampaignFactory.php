<?php

namespace Database\Factories;

use App\Models\SponsorCampaign;
use Illuminate\Database\Eloquent\Factories\Factory;

class SponsorCampaignFactory extends Factory
{
    protected $model = SponsorCampaign::class;

    public function definition(): array
    {
        $name = 'حملة '.$this->faker->unique()->word();

        return [
            'internal_key' => 'test_campaign_'.str()->slug($name).'_'.$this->faker->unique()->numberBetween(1000, 9999),
            'sponsor_name' => 'راعٍ تجريبي',
            'campaign_name' => $name,
            'status' => SponsorCampaign::STATUS_DRAFT,
            'priority' => 0,
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => SponsorCampaign::STATUS_DRAFT]);
    }

    public function approved(): static
    {
        return $this->state(['status' => SponsorCampaign::STATUS_APPROVED, 'approved_at' => now()]);
    }

    public function pendingReview(): static
    {
        return $this->state(['status' => SponsorCampaign::STATUS_PENDING_REVIEW]);
    }

    public function paused(): static
    {
        return $this->state(['status' => SponsorCampaign::STATUS_PAUSED]);
    }

    public function rejected(): static
    {
        return $this->state(['status' => SponsorCampaign::STATUS_REJECTED]);
    }

    public function withSchedule(?string $startsAt = null, ?string $endsAt = null): static
    {
        return $this->state(['starts_at' => $startsAt, 'ends_at' => $endsAt]);
    }
}
