<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Artifact;
use App\Entity\ArtifactCollection;
use App\Entity\ArtifactRecord;
use App\Service\ArtifactCollectionLive;
use App\Service\MercureFeature;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Jwt\TokenProviderInterface;
use Symfony\Component\Mercure\Update;

final class ArtifactCollectionLiveTest extends TestCase
{
    private static function collection(string $name = 'journal'): ArtifactCollection
    {
        $artifact = (new \ReflectionClass(Artifact::class))->newInstanceWithoutConstructor();

        return new ArtifactCollection($artifact, $name);
    }

    /** @return HubInterface&object{updates: list<Update>} */
    private static function hub(): HubInterface
    {
        return new class implements HubInterface {
            /** @var list<Update> */
            public array $updates = [];

            public function getUrl(): string
            {
                return 'http://hub';
            }

            public function getPublicUrl(): string
            {
                return 'https://hub.example/.well-known/mercure';
            }

            public function getProvider(): TokenProviderInterface
            {
                throw new \LogicException();
            }

            public function getFactory(): ?TokenFactoryInterface
            {
                return new class implements TokenFactoryInterface {
                    public function create(?array $subscribe = [], ?array $publish = [], array $additionalClaims = []): string
                    {
                        return 'jwt:'.implode(',', $subscribe ?? []);
                    }
                };
            }

            public function publish(Update $update): string
            {
                $this->updates[] = $update;

                return 'id';
            }
        };
    }

    public function testTopicIsSecretStableAndPerCollection(): void
    {
        $live = new ArtifactCollectionLive(self::hub(), new MercureFeature(true), new NullLogger(), 's3cret');
        $a = self::collection();
        $t = $live->topic($a);
        self::assertSame($t, $live->topic($a));
        self::assertStringStartsWith('https://tadaaa.app/collections/'.$a->getId()->toRfc4122().'/', $t);
        self::assertNotSame($t, $live->topic(self::collection()), 'propre à chaque collection');
        self::assertNotSame($t, (new ArtifactCollectionLive(self::hub(), new MercureFeature(true), new NullLogger(), 'autre'))->topic($a), 'dépend du secret');
    }

    public function testCredentialsAndPrivateUpdates(): void
    {
        $hub = self::hub();
        $live = new ArtifactCollectionLive($hub, new MercureFeature(true), new NullLogger(), 's3cret');
        $c = self::collection();
        $creds = $live->credentials($c);
        self::assertNotNull($creds);
        self::assertSame($live->topic($c), $creds['topic']);
        self::assertSame('jwt:'.$live->topic($c), $creds['token']);

        $record = new ArtifactRecord($c, ['type' => 'note', 'texte' => 'é']);
        $live->publish($c, [$record], ['gone']);
        $live->publish($c); // rien à dire : pas de message
        self::assertCount(1, $hub->updates);
        $u = $hub->updates[0];
        self::assertTrue($u->isPrivate());
        self::assertSame([$live->topic($c)], $u->getTopics());
        $body = json_decode($u->getData(), true);
        self::assertSame('journal', $body['collection']);
        self::assertSame($record->getId()->toRfc4122(), $body['upsert'][0]['id']);
        self::assertSame(['type' => 'note', 'texte' => 'é'], $body['upsert'][0]['data']);
        self::assertSame(['gone'], $body['remove']);
    }

    public function testWithoutHubNothingIsSent(): void
    {
        $hub = self::hub();
        $live = new ArtifactCollectionLive($hub, new MercureFeature(false), new NullLogger(), 's3cret');
        $c = self::collection();
        self::assertNull($live->credentials($c));
        $live->publish($c, [new ArtifactRecord($c, [])]);
        $live->publishReset($c);
        self::assertSame([], $hub->updates);
    }
}
