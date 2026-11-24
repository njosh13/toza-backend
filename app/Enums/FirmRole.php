<?php

declare(strict_types=1);

namespace App\Enums;

use App\Auth\Action;

/** The four roles of the API contract. The string values are the wire values. */
enum FirmRole: string
{
    case Admin = 'admin';
    case Partner = 'partner';
    case Associate = 'associate';
    case Accounts = 'accounts';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Partner => 'Partner',
            self::Associate => 'Associate',
            self::Accounts => 'Accounts',
        };
    }

    /**
     * The role matrix (api-contract section 2). The single source of truth:
     * every policy and gate reads it through allows().
     *
     * @return list<Action>
     */
    public function actions(): array
    {
        return match ($this) {
            self::Partner => [
                Action::EstimateWrite, Action::BillWrite, Action::BillReview, Action::BillIssue,
                Action::PaymentRecord, Action::PaymentVoid, Action::BillWriteOff, Action::FloorOverride,
                Action::EngagementWrite, Action::TaxationWrite, Action::ReportsFirm, Action::DataFirm,
            ],
            self::Associate => [
                Action::EstimateWrite, Action::BillWrite, Action::PaymentRecord,
                Action::EngagementWrite, Action::TaxationWrite, Action::ReportsFirm,
            ],
            self::Accounts => [Action::PaymentRecord, Action::ReportsFirm, Action::DataFirm],
            self::Admin => [Action::ReportsFirm, Action::DataFirm, Action::SettingsManage, Action::TeamManage, Action::AuditView],
        };
    }

    public function allows(Action $action): bool
    {
        return in_array($action, $this->actions(), true);
    }

    /** Firm profile, wording, ARO review and publishing. */
    public function canManageFirm(): bool
    {
        return $this->allows(Action::SettingsManage);
    }

    public function canBill(): bool
    {
        return $this->allows(Action::BillWrite);
    }

    public function canRecordPayments(): bool
    {
        return $this->allows(Action::PaymentRecord);
    }

    /** Roles that see only their own work (the ownership scope applies). */
    public function seesOnlyOwnWork(): bool
    {
        return ! $this->allows(Action::DataFirm);
    }
}