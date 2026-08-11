<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\PowerLevel;
use App\Repository\UserRepository;
use App\Service\RegistrationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates an administrator account.
 *
 * The password is read from a hidden prompt rather than an argument, so it does not
 * end up in the shell history or in the process list.
 */
#[AsCommand(
    name: 'app:board:create-admin',
    description: 'Creates an administrator account.',
)]
final class CreateAdminCommand extends Command
{
    public function __construct(
        private readonly RegistrationService $registration,
        private readonly UserRepository $users,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'The account name')
            ->addArgument('email', InputArgument::OPTIONAL, 'Contact address')
            ->setHelp(
                "Prompts for the password so it does not reach your shell history or\n"
                ."the process list.\n\n"
                ."For unattended provisioning, put the password in the ADMIN_PASSWORD\n"
                ."environment variable instead:\n\n"
                ."    ADMIN_PASSWORD=... php bin/console app:board:create-admin name\n"
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $username = (string) $input->getArgument('username');

        if ($this->users->usernameExists($username)) {
            $io->error(\sprintf('"%s" is already taken.', $username));

            return Command::FAILURE;
        }

        // Provisioning path: an env var keeps the password out of argv, where it
        // would be visible to every other process on the machine.
        $password = (string) getenv('ADMIN_PASSWORD');

        if ('' === $password) {
            $question = (new Question('Password: '))->setHidden(true)->setHiddenFallback(false);
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

        $user = $this->registration->createUser(
            $username,
            $input->getArgument('email'),
            $password,
            PowerLevel::Administrator,
        );

        $io->success(\sprintf('Administrator "%s" created (id %d).', $user->getUsername(), $user->getId()));

        return Command::SUCCESS;
    }
}
