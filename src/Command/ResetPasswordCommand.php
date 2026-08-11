<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Resets a member's password from the console.
 *
 * The board has no "forgot my password" flow - neither did the original - so this is
 * how an administrator helps someone who is locked out, and how you recover the owner
 * account on a fresh install.
 *
 * As with app:board:create-admin, the password is prompted for rather than passed as
 * an argument, so it stays out of shell history and out of the process list. Set
 * NEW_PASSWORD in the environment for unattended use.
 */
#[AsCommand(
    name: 'app:board:reset-password',
    description: "Sets a member's password.",
)]
final class ResetPasswordCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'The account to reset')
            ->setHelp(
                "Prompts for the new password.\n\n"
                ."For unattended use:\n\n"
                ."    NEW_PASSWORD=... php bin/console app:board:reset-password name\n"
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $username = (string) $input->getArgument('username');

        $user = $this->users->findOneByUsername($username);
        if (!$user instanceof User) {
            $io->error(\sprintf('No account named "%s".', $username));

            return Command::FAILURE;
        }

        $password = (string) getenv('NEW_PASSWORD');

        if ('' === $password) {
            $question = (new Question('New password: '))->setHidden(true)->setHiddenFallback(false);
            $password = (string) $io->askQuestion($question);

            $confirm = (new Question('Repeat password: '))->setHidden(true)->setHiddenFallback(false);
            if ($password !== (string) $io->askQuestion($confirm)) {
                $io->error('The passwords do not match.');

                return Command::FAILURE;
            }
        }

        if (\strlen($password) < 8) {
            $io->error('Passwords must be at least 8 characters.');

            return Command::FAILURE;
        }

        $user->setPassword($this->hasher->hashPassword($user, $password));
        // Any imported md5 hash is retired the moment a real password is set.
        $user->setPasswordLegacyMd5(false);
        $this->em->flush();

        $io->success(\sprintf('Password updated for "%s".', $user->getUsername()));

        return Command::SUCCESS;
    }
}
