<?php

namespace App\View\Components;

use App\Services\Sponsorship\SponsorDisplay;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * E30 - شريط «برعاية» المنظَّم على صفحات الموسم/الحملة/المنافسة/المنصة. بيانات منظَّمة (اسم/شعار/رابط آمن) من SponsorDisplay؛ لا علاقة بمحرّك إعلانات E14.
 * معزول الفشل: أي استثناء ⇒ لا شريط (صفحات الهدف الرسمية لا تتعطل بالرعاية). بلا رعاة ⇒ صفر إخراج.
 */
class SponsorStrip extends Component
{
    /** @var array{main: ?array<string, mixed>, supporting: list<array<string, mixed>>, any: bool} */
    public array $sponsors;

    public function __construct(public string $type, public ?int $targetId = null)
    {
        try {
            $this->sponsors = app(SponsorDisplay::class)->forTarget($type, $targetId);
        } catch (\Throwable $e) {
            report($e);
            $this->sponsors = ['main' => null, 'supporting' => [], 'any' => false];
        }
    }

    public function render(): View
    {
        return view('components.sponsor-strip');
    }
}
