<?php

use App\Filament\Resources\SponsorCampaignResource\Pages\EditSponsorCampaign;
use App\Filament\Resources\SponsorCampaignResource\RelationManagers\CreativesRelationManager;
use App\Models\AdClick;
use App\Models\AdImpression;
use App\Models\AdPlacement;
use App\Models\SponsorCampaign;
use App\Models\SponsorCreative;
use App\Models\User;
use App\Services\Advertising\AdPlacementRegistry;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $admin = User::factory()->create();
    $admin->assignRole('super-admin');
    $this->actingAs($admin);

    $placement = AdPlacement::factory()->known(AdPlacementRegistry::HOME_INLINE_PRIMARY)->create();
    $this->campaign = SponsorCampaign::factory()->approved()->create();
    $this->campaign->placements()->attach($placement->id);
    $this->existing = SponsorCreative::factory()->for($this->campaign, 'campaign')->createQuietly(['title' => 'الأصلية']);
    $this->placement = $placement;
});

function e141fManager($campaign)
{
    return Livewire::test(CreativesRelationManager::class, ['ownerRecord' => $campaign, 'pageClass' => EditSponsorCampaign::class]);
}

test('E14.1-8: the Filament Create action goes through the domain path - an approved campaign returns to pending review', function () {
    e141fManager($this->campaign)
        ->callTableAction('create', data: ['title' => 'من Filament', 'destination_url' => 'https://f.example.com', 'is_active' => true])
        ->assertHasNoTableActionErrors();

    expect(SponsorCreative::where('title', 'من Filament')->exists())->toBeTrue()
        ->and($this->campaign->fresh()->status)->toBe(SponsorCampaign::STATUS_PENDING_REVIEW)
        ->and($this->campaign->fresh()->approved_at)->toBeNull();
});

test('E14.1-9/10/51: the Filament form rejects a non-https URL before saving, with a validation error', function () {
    e141fManager($this->campaign)
        ->callTableAction('create', data: ['title' => 'رابط غير آمن', 'destination_url' => 'http://insecure.example.com', 'is_active' => true])
        ->assertHasTableActionErrors(['destination_url']);

    expect(SponsorCreative::where('title', 'رابط غير آمن')->exists())->toBeFalse()
        ->and($this->campaign->fresh()->status)->toBe(SponsorCampaign::STATUS_APPROVED);
});

test('E14.1-18: the delete action is hidden for a creative with analytics history and visible for one without', function () {
    AdImpression::create(['ad_placement_id' => $this->placement->id, 'sponsor_campaign_id' => $this->campaign->id, 'sponsor_creative_id' => $this->existing->id, 'rendered_at' => now()]);
    AdClick::create(['ad_placement_id' => $this->placement->id, 'sponsor_campaign_id' => $this->campaign->id, 'sponsor_creative_id' => $this->existing->id, 'clicked_at' => now()]);
    $clean = SponsorCreative::factory()->for($this->campaign, 'campaign')->createQuietly(['title' => 'بلا تاريخ']);

    e141fManager($this->campaign)
        ->assertTableActionHidden('delete', $this->existing)
        ->assertTableActionVisible('delete', $clean);
});

test('deleting a clean creative through Filament goes through the service and resets review', function () {
    $clean = SponsorCreative::factory()->for($this->campaign, 'campaign')->createQuietly(['title' => 'للحذف']);

    e141fManager($this->campaign)->callTableAction('delete', $clean);

    expect(SponsorCreative::find($clean->id))->toBeNull()
        ->and($this->campaign->fresh()->status)->toBe(SponsorCampaign::STATUS_PENDING_REVIEW);
});
