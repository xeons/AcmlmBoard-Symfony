<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use App\Enum\PowerLevel;
use PHPUnit\Framework\TestCase;

/**
 * The User entity's own logic - power levels, timezones and birthdays.
 *
 * None of this needs a database, and all of it is consulted on nearly every request,
 * so it is worth pinning precisely.
 */
final class UserTest extends TestCase
{
    // ------------------------------------------------------------------
    // Power levels
    // ------------------------------------------------------------------

    public function testStaffPredicatesFollowTheLadder(): void
    {
        $expected = [
            // level                      banned staff  mod    admin
            PowerLevel::Banned->name => [true, false, false, false],
            PowerLevel::Member->name => [false, false, false, false],
            PowerLevel::LocalModerator->name => [false, true, false, false],
            PowerLevel::Moderator->name => [false, true, true, false],
            PowerLevel::Administrator->name => [false, true, true, true],
            PowerLevel::Owner->name => [false, true, true, true],
            PowerLevel::System->name => [false, true, true, true],
        ];

        foreach (PowerLevel::cases() as $level) {
            $user = $this->member();
            $user->setPowerLevel($level);

            [$banned, $staff, $moderator, $admin] = $expected[$level->name];

            self::assertSame($banned, $user->isBanned(), $level->name.' isBanned');
            self::assertSame($staff, $user->isStaff(), $level->name.' isStaff');
            self::assertSame($moderator, $user->isModerator(), $level->name.' isModerator');
            self::assertSame($admin, $user->isAdmin(), $level->name.' isAdmin');
        }
    }

    /**
     * Banned is -1. Left as-is it would clear any forum whose threshold was also
     * negative, so a ban would *grant* access - which is why it is floored at zero.
     */
    public function testABannedAccountHasNoEffectivePowerRatherThanNegativePower(): void
    {
        $user = $this->member();
        $user->setPowerLevel(PowerLevel::Banned);

        self::assertSame(0, $user->effectivePower());
    }

    public function testEffectivePowerMatchesTheLevelOtherwise(): void
    {
        foreach ([PowerLevel::Member, PowerLevel::Moderator, PowerLevel::Owner] as $level) {
            $user = $this->member();
            $user->setPowerLevel($level);

            self::assertSame($level->value, $user->effectivePower());
        }
    }

    public function testRolesAreDerivedFromThePowerLevel(): void
    {
        $user = $this->member();
        $user->setPowerLevel(PowerLevel::Administrator);
        self::assertContains('ROLE_ADMIN', $user->getRoles());

        // A banned member keeps ROLE_USER so they can still read and appeal.
        $user->setPowerLevel(PowerLevel::Banned);
        self::assertContains('ROLE_USER', $user->getRoles());
        self::assertContains('ROLE_BANNED', $user->getRoles());
        self::assertNotContains('ROLE_ADMIN', $user->getRoles());
    }

    // ------------------------------------------------------------------
    // Timezones
    // ------------------------------------------------------------------

    public function testANewAccountDefaultsToUtc(): void
    {
        self::assertSame('UTC', $this->member()->getTimezone());
    }

    public function testLocalTimeUsesTheMembersOwnZone(): void
    {
        $user = $this->member();
        $user->setTimezone('Asia/Tokyo');

        $instant = new \DateTimeImmutable('2026-06-15 12:00:00', new \DateTimeZone('UTC'));

        self::assertSame('21:00', $user->toLocalTime($instant)->format('H:i'));
    }

    /**
     * The whole reason for named zones rather than a stored offset: the same member,
     * in the same city, is at a different offset in January and in July, and nobody
     * should have to remember to change a setting twice a year.
     */
    public function testDaylightSavingIsHandledWithoutTheMemberDoingAnything(): void
    {
        $user = $this->member();
        $user->setTimezone('America/Chicago');

        $winter = new \DateTimeImmutable('2026-01-15 18:00:00', new \DateTimeZone('UTC'));
        $summer = new \DateTimeImmutable('2026-07-15 18:00:00', new \DateTimeZone('UTC'));

        self::assertSame('12:00', $user->toLocalTime($winter)->format('H:i'), 'CST is UTC-6');
        self::assertSame('13:00', $user->toLocalTime($summer)->format('H:i'), 'CDT is UTC-5');

        self::assertSame(-360, $user->getCurrentUtcOffsetMinutes($winter));
        self::assertSame(-300, $user->getCurrentUtcOffsetMinutes($summer));
    }

    public function testZonesEastAndWestOfUtcGetTheRightSign(): void
    {
        $cases = [
            'Europe/London' => 0,      // in January
            'Europe/Berlin' => 60,
            'Asia/Kolkata' => 330,     // the half-hour offsets are the awkward ones
            'Asia/Tokyo' => 540,
            'America/New_York' => -300,
            'Pacific/Auckland' => 780, // southern hemisphere: summer time in January
        ];

        $january = new \DateTimeImmutable('2026-01-15 12:00:00', new \DateTimeZone('UTC'));

        foreach ($cases as $zone => $offset) {
            $user = $this->member();
            $user->setTimezone($zone);

            self::assertSame($offset, $user->getCurrentUtcOffsetMinutes($january), $zone);
        }
    }

    /** A stored zone that no longer exists must not take the whole page down. */
    public function testAnUnknownZoneFallsBackRatherThanThrowing(): void
    {
        $user = $this->member();
        $user->setTimezone('Mars/Olympus_Mons');

        $instant = new \DateTimeImmutable('2026-06-15 12:00:00', new \DateTimeZone('UTC'));

        self::assertSame('12:00', $user->toLocalTime($instant)->format('H:i'));
    }

    /**
     * The account default has to be a zone the rest of the system accepts.
     *
     * Assert\Timezone validates against DateTimeZone::ALL, and the selector has to
     * offer the same set - otherwise a member whose zone is missing gets a select
     * with nothing chosen, and saving the profile moves them somewhere else without
     * telling them. UTC is the case that mattered: it is every new account's
     * default, and the intl catalogue the form used to draw on does not list it.
     */
    public function testTheDefaultTimezoneIsOneTheValidatorAndTheFormBothKnow(): void
    {
        $listed = \DateTimeZone::listIdentifiers(\DateTimeZone::ALL);

        self::assertContains(
            $this->member()->getTimezone(),
            $listed,
            'New accounts default to a zone the form cannot offer.',
        );

        foreach (['UTC', 'America/Chicago', 'Europe/London', 'Asia/Tokyo'] as $zone) {
            self::assertContains($zone, $listed, $zone.' is not a zone the board can offer or validate.');
        }
    }

    // ------------------------------------------------------------------
    // Birthdays
    // ------------------------------------------------------------------

    /**
     * The month/day integer exists so "whose birthday is today" is an indexed
     * comparison. The original used DATE_FORMAT on every row, which DQL cannot
     * express and which no index can help.
     */
    public function testTheBirthdayMonthDayIsKeptInStepWithTheDate(): void
    {
        $user = $this->member();

        $user->setBirthday(new \DateTimeImmutable('1985-03-07'));
        self::assertSame(307, $user->getBirthdayMonthDay());

        $user->setBirthday(new \DateTimeImmutable('1990-12-25'));
        self::assertSame(1225, $user->getBirthdayMonthDay());

        // A single-digit day in a double-digit month must not collide.
        $user->setBirthday(new \DateTimeImmutable('1990-10-01'));
        self::assertSame(1001, $user->getBirthdayMonthDay());

        $user->setBirthday(null);
        self::assertNull($user->getBirthdayMonthDay());
    }

    public function testAgeIsCountedInWholeYears(): void
    {
        $user = $this->member();
        $user->setBirthday(new \DateTimeImmutable('2000-06-15'));

        self::assertSame(25, $user->getAgeOn(new \DateTimeImmutable('2026-06-14')), 'The day before counts as 25.');
        self::assertSame(26, $user->getAgeOn(new \DateTimeImmutable('2026-06-15')));
        self::assertSame(26, $user->getAgeOn(new \DateTimeImmutable('2026-06-16')));
    }

    public function testAgeIsUnknownWithoutABirthday(): void
    {
        self::assertNull($this->member()->getAgeOn(new \DateTimeImmutable('2026-06-15')));
    }

    // ------------------------------------------------------------------
    // Presence and account age
    // ------------------------------------------------------------------

    public function testOnlineIsDecidedAgainstAThreshold(): void
    {
        $user = $this->member();
        $user->setLastActivityAt(new \DateTimeImmutable('-2 minutes'));

        self::assertTrue($user->isOnlineAt(new \DateTimeImmutable('-5 minutes')));
        self::assertFalse($user->isOnlineAt(new \DateTimeImmutable('-1 minute')));
    }

    public function testAnAccountThatHasNeverBeenActiveIsNotOnline(): void
    {
        self::assertFalse($this->member()->isOnlineAt(new \DateTimeImmutable('-5 minutes')));
    }

    public function testAccountAgeIsNeverNegative(): void
    {
        $user = $this->member();
        // Clock skew, or an imported row with a future date.
        $user->setRegisteredAt(new \DateTimeImmutable('+10 days'));

        self::assertSame(0.0, $user->daysRegistered());
    }

    public function testAccountAgeIsMeasuredInDays(): void
    {
        $user = $this->member();
        $now = new \DateTimeImmutable('2026-01-11 00:00:00');
        $user->setRegisteredAt(new \DateTimeImmutable('2026-01-01 00:00:00'));

        self::assertSame(10.0, $user->daysRegistered($now));
    }

    // ------------------------------------------------------------------
    // Identity
    // ------------------------------------------------------------------

    /**
     * The canonical name carries the unique index. The original looked for
     * duplicates by loading every row and comparing in PHP.
     */
    public function testTheCanonicalNameIsCaseInsensitive(): void
    {
        $user = $this->member();

        $user->setUsername('AcMlM');
        $canonical = $user->getUsernameCanonical();

        $user->setUsername('acmlm');
        self::assertSame($canonical, $user->getUsernameCanonical());

        $user->setUsername('ACMLM');
        self::assertSame($canonical, $user->getUsernameCanonical());
    }

    public function testTheDisplayedNameKeepsItsOriginalCasing(): void
    {
        $user = $this->member();
        $user->setUsername('AcMlM');

        self::assertSame('AcMlM', $user->getUsername());
        self::assertSame('AcMlM', $user->getUserIdentifier());
    }

    private function member(): User
    {
        $user = new User();
        $user->setUsername('Tester');

        return $user;
    }
}
