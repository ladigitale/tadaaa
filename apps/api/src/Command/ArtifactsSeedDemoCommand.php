<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\DatasetRepository;
use App\Repository\UserRepository;
use App\Service\ArtifactService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:artifacts:seed-demo',
    description: 'Publie un artefact de démo (multi-vues, jsonata, collection votes) sur le premier dataset d’un utilisateur.',
)]
final class ArtifactsSeedDemoCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly DatasetRepository $datasets,
        private readonly ArtifactService $artifacts,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('email', null, InputOption::VALUE_REQUIRED, 'Email du propriétaire / writer');
        $this->addOption('slug', null, InputOption::VALUE_OPTIONAL, 'Slug', 'demo-artefact');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getOption('email');
        if ($email === '') {
            $io->error('Option --email requise.');

            return Command::FAILURE;
        }

        $user = $this->users->findOneBy(['email' => $email]);
        if (!$user instanceof User) {
            $io->error('Utilisateur introuvable.');

            return Command::FAILURE;
        }

        $dataset = $user->getActiveDataset();
        if ($dataset === null) {
            $dataset = $this->datasets->findOneBy(['owner' => $user]);
        }
        if ($dataset === null) {
            $io->error('Aucun dataset pour cet utilisateur.');

            return Command::FAILURE;
        }

        $path = \dirname(__DIR__, 2).'/config/artifacts/examples/dashboard-minimal.json';
        if (!is_readable($path)) {
            $io->error('Exemple dashboard-minimal.json introuvable.');

            return Command::FAILURE;
        }
        /** @var mixed $raw */
        $raw = json_decode((string) file_get_contents($path), true);
        if (!\is_array($raw)) {
            $io->error('JSON exemple invalide.');

            return Command::FAILURE;
        }

        $slug = (string) $input->getOption('slug');
        $created = $this->artifacts->create(
            $user,
            $dataset->getId()->toRfc4122(),
            'Artefact démo',
            $raw,
            $slug,
            'public',
            'Seed app:artifacts:seed-demo',
        );

        $io->success(sprintf('Artefact démo publié : %s', $created['url'] ?? $slug));

        return Command::SUCCESS;
    }
}
