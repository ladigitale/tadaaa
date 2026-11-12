<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ArtifactWorkflowRunRepository;
use App\Service\ArtifactRunService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ménage des runs d'artefacts : runs en attente expirés → `expired` (webhook artifact.run.failed),
 * runs terminés (et runs de workflow terminés) plus vieux que la rétention (90 jours) supprimés. Chaque création de run en traite
 * déjà quelques-uns ; à lancer en cron (ex. toutes les 10 minutes) pour des webhooks à l'heure.
 */
#[AsCommand(
    name: 'app:artifact-runs:sweep',
    description: 'Expire les runs d’artefacts en attente échus et purge les anciens runs',
)]
final class ArtifactRunsSweepCommand extends Command
{
    public function __construct(
        private readonly ArtifactRunService $runs,
        private readonly ArtifactWorkflowRunRepository $workflowRuns,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Runs expirés traités au plus', '1000')
            ->addOption('no-purge', null, InputOption::VALUE_NONE, 'Ne supprime pas les anciens runs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $purge = !$input->getOption('no-purge');
        $result = $this->runs->sweep(max(1, (int) $input->getOption('limit')), $purge);
        $workflows = $purge ? $this->workflowRuns->purgeFinishedBefore((new \DateTimeImmutable())->sub(new \DateInterval(ArtifactRunService::RETENTION))) : 0;
        (new SymfonyStyle($input, $output))->success(sprintf('%d run(s) expiré(s), %d run(s) et %d run(s) de workflow supprimé(s).', $result['expired'], $result['purged'], $workflows));

        return Command::SUCCESS;
    }
}
