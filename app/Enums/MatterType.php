<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The contract's matter types (api-contract section 3). The wire keys are the
 * enum values; the Schedule is DERIVED from the type, never sent by a client.
 */
enum MatterType: string
{
    case Conveyance = 'conveyance';
    case Lease = 'lease';
    case CompanyFormation = 'company_formation';
    case TradeMark = 'trade_mark';
    case GeneralBusiness = 'general_business';
    case HighCourtLitigation = 'high_court_litigation';
    case SubordinateCourtLitigation = 'subordinate_court_litigation';
    case LandlordTenantTribunal = 'landlord_tenant_tribunal';
    case RentRestrictionTribunal = 'rent_restriction_tribunal';
    case ProbateAdministration = 'probate_administration';
    case OtherTribunal = 'other_tribunal';
    case PatentDesign = 'patent_design';

    public function label(): string
    {
        return match ($this) {
            self::Conveyance => 'Conveyance (sale, purchase, mortgage, charge)',
            self::Lease => 'Lease or tenancy agreement',
            self::CompanyFormation => 'Company formation',
            self::TradeMark => 'Trade mark',
            self::GeneralBusiness => 'General or unprescribed business',
            self::HighCourtLitigation => 'High Court litigation',
            self::SubordinateCourtLitigation => 'Subordinate court litigation',
            self::LandlordTenantTribunal => 'Business Premises Rent Tribunal (Cap. 301)',
            self::RentRestrictionTribunal => 'Rent Restriction Tribunal (Cap. 296)',
            self::ProbateAdministration => 'Probate and administration',
            self::OtherTribunal => 'Other tribunal',
            self::PatentDesign => 'Patent, utility model or industrial design',
        };
    }

    /** The Schedule of the Order that governs this type (1-12). */
    public function schedule(): int
    {
        return match ($this) {
            self::Conveyance => 1,
            self::Lease => 2,
            self::CompanyFormation => 3,
            self::TradeMark => 4,
            self::GeneralBusiness => 5,
            self::HighCourtLitigation => 6,
            self::SubordinateCourtLitigation => 7,
            self::LandlordTenantTribunal => 8,
            self::RentRestrictionTribunal => 9,
            self::ProbateAdministration => 10,
            self::OtherTribunal => 11,
            self::PatentDesign => 12,
        };
    }

    /** `schedule5_hourly` for Schedule 5 (no scale), else `scale`. */
    public function defaultBasis(): string
    {
        return $this === self::GeneralBusiness ? 'schedule5_hourly' : 'scale';
    }

    /** The legacy court level the existing engine and classification still read. */
    public function courtLevel(): CourtLevel
    {
        return match ($this) {
            self::HighCourtLitigation => CourtLevel::HighCourt,
            self::SubordinateCourtLitigation => CourtLevel::Subordinate,
            self::LandlordTenantTribunal => CourtLevel::TribunalLandlordTenant,
            self::RentRestrictionTribunal => CourtLevel::TribunalRentRestriction,
            self::OtherTribunal => CourtLevel::TribunalOther,
            default => CourtLevel::None,
        };
    }

    /** The type implied by a legacy court level (used when a matter is created without one). */
    public static function fromCourtLevel(CourtLevel $level): self
    {
        return match ($level) {
            CourtLevel::HighCourt => self::HighCourtLitigation,
            CourtLevel::Subordinate => self::SubordinateCourtLitigation,
            CourtLevel::TribunalLandlordTenant => self::LandlordTenantTribunal,
            CourtLevel::TribunalRentRestriction => self::RentRestrictionTribunal,
            CourtLevel::TribunalOther => self::OtherTribunal,
            CourtLevel::None => self::GeneralBusiness,
        };
    }

    /**
     * The one amount the Order's scale is read from (api-contract section 4).
     *
     * @return list<array{key: string, label: string, kind: string, required: bool, help?: string, default?: int}>
     */
    public function inputDefinitions(): array
    {
        $money = fn (string $key, string $label, string $help): array => ['key' => $key, 'label' => $label, 'kind' => 'money', 'required' => true, 'help' => $help];

        return match ($this) {
            self::Conveyance => [$money('consideration', 'Consideration', 'Purchase price or amount secured, in KES.')],
            self::Lease => [$money('annual_rent', 'Annual rent', 'The band is chosen by the rent; bands are not cumulative.')],
            self::CompanyFormation => [$money('nominal_capital', 'Nominal capital', 'Authorised share capital, in KES.')],
            self::TradeMark => [['key' => 'classes', 'label' => 'Number of classes', 'kind' => 'integer', 'required' => true, 'default' => 1]],
            self::GeneralBusiness => [],
            self::HighCourtLitigation, self::SubordinateCourtLitigation => [$money('sum_claimed', 'Sum claimed', 'Attendances are entered as litigation items.')],
            self::LandlordTenantTribunal, self::RentRestrictionTribunal, self::OtherTribunal => [$money('amount_in_dispute', 'Amount in dispute', 'In KES.')],
            self::ProbateAdministration => [$money('estate_value', 'Estate value', 'Gross value of the estate, in KES.')],
            self::PatentDesign => [['key' => 'applications', 'label' => 'Number of applications', 'kind' => 'integer', 'required' => true, 'default' => 1]],
        };
    }
}