<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Dataset;
use App\Entity\User;
use App\Entity\UserStatus;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:user:create',
    description: 'Crée un compte utilisateur (bootstrap admin / secours)',
)]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED)
            ->addArgument('password', InputArgument::REQUIRED)
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Attribue ROLE_ADMIN')
            ->addOption('active', null, InputOption::VALUE_NONE, 'Compte immédiatement actif (défaut)')
            ->addOption('pending', null, InputOption::VALUE_NONE, 'Compte en attente de validation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = strtolower(trim((string) $input->getArgument('email')));
        $password = (string) $input->getArgument('password');

        if ($password === '') {
            $io->error('Le mot de passe ne peut pas être vide.');

            return Command::FAILURE;
        }

        if ($this->users->findOneBy(['email' => $email]) !== null) {
            $io->error(sprintf('Un compte existe déjà pour %s.', $email));

            return Command::FAILURE;
        }

        $status = $input->getOption('pending') ? UserStatus::Pending : UserStatus::Active;
        $user = new User($email, $status);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        if ($input->getOption('admin')) {
            $user->setRoles(['ROLE_ADMIN']);
        }

        $dataset = new Dataset('Mon jeu');
        $user->addDataset($dataset);
        $user->setActiveDataset($dataset);

        $this->entityManager->persist($dataset);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf(
            'Compte %s créé (status=%s%s).',
            $email,
            $status->value,
            $input->getOption('admin') ? ', ROLE_ADMIN' : '',
        ));

        return Command::SUCCESS;
    }
}
