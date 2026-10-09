<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Artifact;
use App\Entity\ArtifactVersion;
use App\Entity\Dataset;
use App\Entity\User;
use App\Mcp\RawToolArguments;
use App\Service\JsonShape;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class JsonShapeTest extends TestCase
{
    private const DOC = '{"schema":"artifacts/1","data":{"stores":{"quiz":{"initial":{"score":0,"answered":{},"list":[]}}}},'
        .'"views":[{"id":"q","a2ui":[{"updateDataModel":{"path":"/","value":{}}}]}],"odd/key~":{}}';

    public function testFindsEmptyObjectsOnly(): void
    {
        self::assertSame(
            ['/data/stores/quiz/initial/answered', '/views/0/a2ui/0/updateDataModel/value', '/odd~1key~0'],
            JsonShape::emptyObjectPaths(json_decode(self::DOC, false)),
        );
        self::assertSame([''], JsonShape::emptyObjectPaths(new \stdClass()));
        self::assertSame([], JsonShape::emptyObjectPaths(json_decode('[[], {"a": 1}]', false)));
    }

    public function testRoundTripRestoresObjectsAndKeepsArrays(): void
    {
        $assoc = json_decode(self::DOC, true);
        $paths = JsonShapeTest::paths();
        self::assertSame([], $assoc['data']['stores']['quiz']['initial']['answered']);
        self::assertSame(self::DOC, json_encode(JsonShape::restore($assoc, $paths), \JSON_UNESCAPED_SLASHES));
        // Valeur d'origine intacte (copie).
        self::assertSame([], $assoc['data']['stores']['quiz']['initial']['answered']);
    }

    public function testUntrustedPathsAreFiltered(): void
    {
        $assoc = json_decode(self::DOC, true);
        $kept = JsonShape::filter($assoc, [
            '/data/stores/quiz/initial/answered',
            '/data/stores/quiz/initial/score',  // pas un []
            '/data/stores/missing',             // absent
            '/data',                            // non vide
            '/data/stores/quiz/initial/answered', // doublon
        ]);
        self::assertSame(['/data/stores/quiz/initial/answered'], $kept);

        // Un pointeur faux ne change rien.
        $restored = JsonShape::restore($assoc, ['/data/stores/quiz/initial/score', '/nope/0']);
        self::assertSame(json_encode($assoc), json_encode($restored));
    }

    public function testPathsFromRawJsonAtSubPath(): void
    {
        $body = '{"title":"T","document":'.self::DOC.'}';
        self::assertSame(self::paths(), JsonShape::emptyObjectPathsInJson($body, '/document'));
        self::assertSame([], JsonShape::emptyObjectPathsInJson('{"title":"T"}', '/document'));
        self::assertSame([], JsonShape::emptyObjectPathsInJson('pas du json', '/document'));
    }

    public function testRawMcpArgumentsFromRequestBody(): void
    {
        $call = static fn (int $id, string $tool, string $doc): string => '{"jsonrpc":"2.0","id":'.$id.',"method":"tools/call","params":{"name":"'.$tool.'","arguments":{"title":"T","document":'.$doc.'}}}';
        $raw = self::raw($call(1, 'publish_artifact', self::DOC));
        self::assertSame(self::paths(), $raw->emptyObjects([], 'publish_artifact', 'document'));
        self::assertSame([], $raw->emptyObjects([], 'update_artifact', 'document'));

        // Lot : l'id de la requête MCP départage deux appels du même outil.
        $batch = self::raw('['.$call(1, 'publish_artifact', '{"a":{}}').','.$call(2, 'publish_artifact', '{"b":{}}').']');
        self::assertSame([], $batch->emptyObjects([], 'publish_artifact', 'document'));
        self::assertSame(['/b'], $batch->emptyObjects(['mcp_request' => new FakeMcpRequest(2)], 'publish_artifact', 'document'));

        // Arguments bruts fournis par l'agent : prioritaires sur la requête HTTP.
        $agent = ['raw_arguments' => json_decode('{"document":{"x":{}}}', false)];
        self::assertSame(['/x'], $raw->emptyObjects($agent, 'publish_artifact', 'document'));
        self::assertSame([], (new RawToolArguments(new RequestStack()))->emptyObjects([], 'publish_artifact', 'document'));
    }

    public function testVersionKeepsEmptyObjects(): void
    {
        $user = new User();
        $user->setEmail('a@example.org');
        $dataset = new Dataset('Artefacts');
        $dataset->setOwner($user);
        $artifact = new Artifact($dataset, $user, 'quiz', 'Quiz');
        $assoc = json_decode(self::DOC, true);

        $version = new ArtifactVersion($artifact, 1, $assoc, $user, null, self::paths());
        self::assertSame(self::paths(), $version->getEmptyObjects());
        self::assertSame(self::DOC, json_encode($version->getWireDocument(), \JSON_UNESCAPED_SLASHES));
        self::assertSame($assoc, $version->getDocument());

        $legacy = new ArtifactVersion($artifact, 2, $assoc, $user);
        self::assertSame([], $legacy->getEmptyObjects());
        self::assertSame(json_encode($assoc), json_encode($legacy->getWireDocument()));
    }

    /** @return list<string> */
    private static function paths(): array
    {
        return JsonShape::emptyObjectPaths(json_decode(self::DOC, false));
    }

    private static function raw(string $body): RawToolArguments
    {
        $stack = new RequestStack();
        $stack->push(Request::create('/mcp', 'POST', [], [], [], [], $body));

        return new RawToolArguments($stack);
    }
}

final class FakeMcpRequest
{
    public function __construct(private readonly int $id)
    {
    }

    public function getId(): int
    {
        return $this->id;
    }
}
