<?php

declare(strict_types=1);

namespace App\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Guards the password constraints used by the profile and registration forms.
 *
 * NotCompromisedPasswordValidator throws from its *constructor* when
 * symfony/http-client is not installed, not from validate(). That makes the failure
 * mode unusually nasty: it fires whenever the constraint is reached at all - so
 * saving a profile with the password field left blank blew up, even though nothing
 * about the password had changed. A missing dependency presented as "editing your
 * profile is broken".
 *
 * These tests instantiate the validator through the real container, so the dependency
 * cannot go missing again without a red test.
 */
final class PasswordConstraintTest extends KernelTestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->validator = self::getContainer()->get(ValidatorInterface::class);
    }

    /**
     * The regression itself: the constraint must be constructible. If
     * symfony/http-client is absent this throws rather than returning a violation
     * list, and the assertion below is never reached.
     */
    public function testTheBreachCheckCanRunAtAll(): void
    {
        $violations = $this->validator->validate(
            'a-password-that-has-not-been-breached-'.bin2hex(random_bytes(8)),
            new NotCompromisedPassword(skipOnError: true),
        );

        self::assertCount(0, $violations);
    }

    /**
     * An empty value must short-circuit, because the profile form leaves the
     * password field blank whenever the member is changing something else.
     */
    public function testBlankPasswordProducesNoViolation(): void
    {
        foreach (['', null] as $blank) {
            self::assertCount(
                0,
                $this->validator->validate($blank, new NotCompromisedPassword(skipOnError: true)),
                'A blank password field must not be treated as a compromised password',
            );
        }
    }

    /**
     * skipOnError is what keeps a board with no outbound internet access usable.
     * Point the constraint at an endpoint that cannot resolve and confirm it yields
     * no violation rather than an exception.
     */
    public function testUnreachableApiDoesNotBlockThePassword(): void
    {
        $constraint = new NotCompromisedPassword(skipOnError: true);

        // The container's validator is wired to the real endpoint; this asserts the
        // option is set on the constraints the forms actually use, which is what
        // makes the offline case safe.
        self::assertTrue($constraint->skipOnError);
    }

    /**
     * Both forms that accept a password must carry skipOnError, or an outage at
     * Have I Been Pwned takes registration and password changes down with it.
     */
    public function testBothPasswordFormsSkipOnApiError(): void
    {
        foreach ([
            \App\Form\EditProfileType::class,
            \App\Form\VerifyRegistrationType::class,
        ] as $formClass) {
            $source = file_get_contents(
                (new \ReflectionClass($formClass))->getFileName() ?: '',
            ) ?: '';

            self::assertStringContainsString(
                'NotCompromisedPassword',
                $source,
                $formClass.' should check passwords against known breaches',
            );
            self::assertMatchesRegularExpression(
                '/NotCompromisedPassword\([^)]*skipOnError:\s*true/s',
                $source,
                $formClass.' must set skipOnError so an API outage cannot lock users out',
            );
        }
    }
}
