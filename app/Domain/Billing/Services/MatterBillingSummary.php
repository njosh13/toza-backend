<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Enums\BillStatus;
use App\Models\Matter;

/**
 * GET /matters/{id}/billing-summary (api-contract section 6).
 *
 * Built on the CURRENT bill model (issued/delivered/disputed/partially_paid/
 * paid). Phase 6 moves bills to the contract lifecycle and this class follows:
 * billed = issued, agreed, disputed, taxed; collected = non-voided payments.
 * `estimated_total` waits for estimates (phase 5) and `still_chargeable` is
 * engine-derived (phase 4), empty in the contract's mock too.
 */
final class MatterBillingSummary
{
    /** @return array{matter_id: string, estimated_total: int|null, billed_total: int, collected_total: int, outstanding_total: int, still_chargeable: list<array<string, mixed>>} */
    public function for(Matter $matter): array
    {
        $billed = [BillStatus::Issued, BillStatus::Delivered, BillStatus::Disputed, BillStatus::PartiallyPaid, BillStatus::Paid];

        $bills = $matter->bills()->whereIn('status', array_map(fn (BillStatus $s) => $s->value, $billed))->get(['total_cents', 'paid_cents']);

        $billedTotal = (int) $bills->sum('total_cents');
        $collected = (int) $bills->sum('paid_cents');

        return [
            'matter_id' => $matter->id,
            'estimated_total' => null,
            'billed_total' => $billedTotal,
            'collected_total' => $collected,
            'outstanding_total' => max(0, $billedTotal - $collected),
            'still_chargeable' => [],
        ];
    }
}