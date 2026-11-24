<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * The 15 permission keys of the API contract (section 2). The frontend matrix
 * (`src/lib/permissions.ts`) uses the same strings; each is also a Gate
 * ability, so `Gate::authorize('bill.issue')` and `$user->can('bill.issue')`
 * both work. Which role holds which key lives in FirmRole::actions().
 */
enum Action: string
{
    case EstimateWrite = 'estimate.write';
    case BillWrite = 'bill.write';
    case BillReview = 'bill.review';
    case BillIssue = 'bill.issue';
    case PaymentRecord = 'payment.record';
    case PaymentVoid = 'payment.void';
    case BillWriteOff = 'bill.write_off';
    case FloorOverride = 'floor.override';
    case EngagementWrite = 'engagement.write';
    case TaxationWrite = 'taxation.write';
    case ReportsFirm = 'reports.firm';
    /** See the whole firm's work (and filter by advocate); without it a user sees only their own. */
    case DataFirm = 'data.firm';
    case SettingsManage = 'settings.manage';
    case TeamManage = 'team.manage';
    case AuditView = 'audit.view';
}