<?php

declare(strict_types=1);

namespace App\Filament\Resources\LandingPageResource\Pages;

use App\Filament\Resources\LandingPageResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditLandingPage extends EditRecord
{
    protected static string $resource = LandingPageResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }

    /**
     * Security M3 / Reviewer B3 — the picks Repeater's identity fields
     * (`product_id`, `role`) are `dehydrated(false)` and `est_price_snapshot` isn't
     * declared as a form field at all, so NONE of them arrive in $data from the
     * browser. Force-restore all three from the STORED record here, matched by
     * array position (the repeater disallows add/delete/reorder, so stored and
     * submitted picks stay index-aligned) — this makes it structurally impossible
     * for a Filament save (even with a maliciously crafted Livewire payload) to
     * change WHICH product a pick points at, its role, or silently wipe the
     * price-drift baseline.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $stored = $this->record->picks ?? [];

        // NOTE: iterate `$data['picks']` directly (NOT `$data['picks'] ?? []`) — a
        // by-reference foreach over a `??` expression binds to a temporary copy, not
        // the real array element, so mutations via &$pick would silently never reach
        // $data.
        if (isset($data['picks']) && is_array($data['picks'])) {
            foreach ($data['picks'] as $i => &$pick) {
                $pick['product_id']         = $stored[$i]['product_id'] ?? null;
                $pick['role']               = $stored[$i]['role'] ?? null;
                $pick['est_price_snapshot'] = $stored[$i]['est_price_snapshot'] ?? null;

                // Carry product_name through too (Perf M3) — not user-editable, no
                // reason to drop it on a prose-only save.
                if (array_key_exists('product_name', $stored[$i] ?? [])) {
                    $pick['product_name'] = $stored[$i]['product_name'];
                }
            }
            unset($pick);
        }

        return $data;
    }
}
