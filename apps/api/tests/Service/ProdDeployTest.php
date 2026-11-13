<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Service\ProdDeploy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class ProdDeployTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/ops-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    private function admin(string $email = 'boss@example.com'): User
    {
        return (new User($email))->setRoles(['ROLE_ADMIN']);
    }

    public function testOnlyListedAdminsAreAllowed(): void
    {
        $deploy = new ProdDeploy($this->dir, ' Boss@Example.com , other@example.com');
        self::assertTrue($deploy->isAllowed($this->admin()));
        self::assertFalse($deploy->isAllowed(new User('boss@example.com')), 'pas ROLE_ADMIN');
        self::assertFalse($deploy->isAllowed($this->admin('admin2@example.com')), 'pas dans la liste');
        self::assertFalse((new ProdDeploy($this->dir, ''))->isAllowed($this->admin()), 'liste vide = personne');

        $this->expectException(AccessDeniedHttpException::class);
        $deploy->trigger($this->admin('admin2@example.com'));
    }

    public function testDisabledWithoutOpsDir(): void
    {
        $this->expectException(ServiceUnavailableHttpException::class);
        (new ProdDeploy('', 'boss@example.com'))->trigger($this->admin());
    }

    public function testTriggerQueuesOnceThenReadsRunnerState(): void
    {
        $deploy = new ProdDeploy($this->dir, 'boss@example.com');
        $run = $deploy->trigger($this->admin(), '  ship it ');
        self::assertSame('queued', $run['status']);
        self::assertSame('ship it', $run['reason']);
        self::assertFileExists($this->dir.'/requests/'.$run['id'].'.json');

        try {
            $deploy->trigger($this->admin());
            self::fail('Deuxième déclenchement accepté');
        } catch (ConflictHttpException) {
        }

        $status = $deploy->status($this->admin());
        self::assertSame($run['id'], $status['run']['id']);
        self::assertSame('queued', $status['run']['status']);

        // Le runner hôte prend la demande, écrit l'état et le journal.
        $id = $run['id'];
        rename($this->dir."/requests/$id.json", $this->dir."/runs/$id.request.json");
        file_put_contents($this->dir."/runs/$id.json", json_encode([
            'status' => 'running', 'startedAt' => gmdate('c'), 'fromSha' => 'aaa',
        ]));
        file_put_contents($this->dir."/runs/$id.log", "\e[0;36m→\e[0m git pull --ff-only…\nAlready up to date.\n\e[0;36m→\e[0m Building front…\nyarn install\n");

        $status = $deploy->status($this->admin(), $id, 2);
        self::assertSame('running', $status['run']['status']);
        self::assertSame('boss@example.com', $status['run']['requestedBy']);
        self::assertSame('→ Building front…', $status['run']['lastStep']);
        self::assertSame(['→ Building front…', 'yarn install'], $status['run']['log']);

        file_put_contents($this->dir."/runs/$id.json", json_encode([
            'status' => 'succeeded', 'startedAt' => gmdate('c'), 'finishedAt' => gmdate('c'),
            'fromSha' => 'aaa', 'toSha' => 'bbb', 'exitCode' => 0, 'health' => 'ok',
        ]));
        self::assertSame('succeeded', $deploy->status($this->admin(), $id)['run']['status']);
        self::assertSame('queued', $deploy->trigger($this->admin())['status'], 'nouveau run possible');
    }

    public function testStaleRunDoesNotBlock(): void
    {
        $deploy = new ProdDeploy($this->dir, 'boss@example.com');
        mkdir($this->dir.'/runs');
        file_put_contents($this->dir.'/runs/20260101-000000-abcdef.json', json_encode([
            'status' => 'running', 'startedAt' => gmdate('c', time() - ProdDeploy::STALE_AFTER_SECONDS - 60),
        ]));
        self::assertSame('stale', $deploy->status($this->admin(), '20260101-000000-abcdef')['run']['status']);
        self::assertSame('queued', $deploy->trigger($this->admin())['status']);
    }
}
