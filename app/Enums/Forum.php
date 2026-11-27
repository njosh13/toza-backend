<?php

declare(strict_types=1);

namespace App\Enums;

/** Where a matter is heard (api-contract section 3). OPEN Q8: an advocate confirms the list. */
enum Forum: string
{
    case NonContentious = 'non_contentious';
    case HighCourt = 'high_court';
    case MagistratesCourt = 'magistrates_court';
    case EnvironmentAndLandCourt = 'environment_and_land_court';
    case EmploymentAndLabourCourt = 'employment_and_labour_court';
    case CourtOfAppeal = 'court_of_appeal';
    case SupremeCourt = 'supreme_court';
    case Tribunal = 'tribunal';

    /** The forum implied by a legacy court level. */
    public static function fromCourtLevel(CourtLevel $level): self
    {
        return match ($level) {
            CourtLevel::None => self::NonContentious,
            CourtLevel::HighCourt => self::HighCourt,
            CourtLevel::Subordinate => self::MagistratesCourt,
            CourtLevel::TribunalLandlordTenant, CourtLevel::TribunalRentRestriction, CourtLevel::TribunalOther => self::Tribunal,
        };
    }
}