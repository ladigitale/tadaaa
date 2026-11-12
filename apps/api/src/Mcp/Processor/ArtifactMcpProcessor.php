<?php

declare(strict_types=1);

namespace App\Mcp\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\AuditLog;
use App\Entity\User;
use App\Mcp\RawToolArguments;
use App\Service\JsonShape;
use App\Mcp\Tool\BuildArtifactFromKitTool;
use App\Mcp\Tool\CloseArtifactIntakeTool;
use App\Mcp\Tool\FindIconsTool;
use App\Mcp\Tool\DeleteArtifactAssetTool;
use App\Mcp\Tool\DeleteArtifactTool;
use App\Mcp\Tool\GetArtifactCatalogTool;
use App\Mcp\Tool\GetArtifactTool;
use App\Mcp\Tool\DeleteResourceTool;
use App\Mcp\Tool\GetResourceTool;
use App\Mcp\Tool\ListArtifactAssetsTool;
use App\Mcp\Tool\SaveResourceTool;
use App\Mcp\Tool\SearchResourcesTool;
use App\Mcp\Tool\ListArtifactsTool;
use App\Mcp\Tool\OpenArtifactIntakeTool;
use App\Mcp\Tool\PromoteArtifactTool;
use App\Mcp\Tool\PublishArtifactTool;
use App\Mcp\Tool\ReadArtifactDataTool;
use App\Mcp\Tool\RenderArtifactTool;
use App\Mcp\Tool\SimulateStoreTool;
use App\Mcp\Tool\RunArtifactTool;
use App\Mcp\Tool\GetArtifactRunTool;
use App\Mcp\Tool\CancelArtifactRunTool;
use App\Mcp\Tool\ListArtifactToolsTool;
use App\Mcp\Tool\UpdateArtifactTool;
use App\Mcp\Tool\UploadArtifactAssetTool;
use App\Mcp\Tool\ValidateArtifactTool;
use App\Mcp\Tool\WriteArtifactDataTool;
use App\Service\ArtifactAssets;
use App\Service\ArtifactDataService;
use App\Service\ArtifactDocumentValidator;
use App\Service\ArtifactFileFetcher;
use App\Service\ArtifactFiles;
use App\Service\ArtifactIcons;
use App\Service\ArtifactKits;
use App\Service\ArtifactRenderer;
use App\Service\ArtifactRunService;
use App\Service\ArtifactService;
use App\Service\AuditLogger;
use App\Service\DatasetAccessService;
use App\Service\JsonPatch;
use App\Service\ResourceContent;
use App\Service\Resources;
use App\Service\StoreSimulator;
use App\Service\UsageMeter;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * @implements ProcessorInterface<object, CallToolResult>
 */
final class ArtifactMcpProcessor implements ProcessorInterface
{
    private const MAX_INLINE_BYTES = 262144;

    public function __construct(
        private readonly ArtifactService $artifacts,
        private readonly ArtifactDocumentValidator $validator,
        private readonly ArtifactDataService $data,
        private readonly DatasetAccessService $datasetAccess,
        private readonly AuditLogger $audit,
        private readonly UsageMeter $usage,
        private readonly Security $security,
        private readonly RawToolArguments $rawArguments,
        private readonly ArtifactKits $kits,
        private readonly ArtifactIcons $icons,
        private readonly StoreSimulator $simulator,
        private readonly ArtifactRenderer $renderer,
        private readonly ArtifactFileFetcher $fetcher,
        private readonly Resources $resources,
        private readonly ArtifactRunService $runs,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        $user = $this->requireUser();
        $toolName = $this->toolName($data);
        $active = $user->getActiveDataset();

        try {
            $this->audit->log($user, AuditLog::CATEGORY_ARTIFACT, 'mcp.artifact_tool', [
                'tool' => $toolName,
                'datasetId' => $active?->getId()->toRfc4122(),
            ]);
            $this->usage->increment($user, $active, UsageMeter::MCP_CALLS);
        } catch (\Throwable) {
        }

        $png = null;
        $payload = match (true) {
            $data instanceof GetArtifactCatalogTool => [
                ...$this->validator->mcpCatalogPayload($data->compact, $data->components, $data->rules, $data->examples),
                'kits' => $this->kits->summary(),
            ],
            $data instanceof BuildArtifactFromKitTool => $this->buildFromKit($data, $context),
            $data instanceof FindIconsTool => [
                'library' => $data->library,
                'names' => $this->icons->search($data->query, $data->library !== '' ? $data->library : 'iconoir', 40),
            ],
            $data instanceof SimulateStoreTool => $this->simulate($user, $data, $context),
            $data instanceof RenderArtifactTool => $this->render($user, $data, $png, $context),
            $data instanceof RunArtifactTool => $this->runArtifact($user, $data, $context),
            $data instanceof GetArtifactRunTool => $this->getRun($user, $data),
            $data instanceof CancelArtifactRunTool => $this->runs->cancel($user, $this->emptyToNull($data->runId) ?? throw new BadRequestHttpException('runId requis.')),
            $data instanceof ListArtifactToolsTool => ['tools' => $this->runs->listTools($user, $this->emptyToNull($data->datasetId))],
            $data instanceof ValidateArtifactTool => $this->validateWithLint($data->document ?? []),
            $data instanceof PublishArtifactTool => $this->publish($user, $data, $context),
            $data instanceof UpdateArtifactTool => $this->update($user, $data, $context),
            $data instanceof PromoteArtifactTool => $this->promote($user, $data),
            $data instanceof GetArtifactTool => $this->get($user, $data),
            $data instanceof ListArtifactsTool => [
                'artifacts' => $this->artifacts->listForUser($user, $this->emptyToNull($data->datasetId)),
            ],
            $data instanceof DeleteArtifactTool => $this->delete($user, $data),
            $data instanceof ReadArtifactDataTool => [
                'records' => $this->data->readForUser($user, $data->slug, $data->collection, $this->emptyToNull($data->sort), $data->limit),
            ],
            $data instanceof WriteArtifactDataTool => $this->writeData($user, $data),
            $data instanceof UploadArtifactAssetTool => $this->uploadAsset($user, $data),
            $data instanceof ListArtifactAssetsTool => ['assets' => $this->artifacts->listAssets($user, $this->artifactRef($data->id, $data->slug))],
            $data instanceof DeleteArtifactAssetTool => $this->deleteAsset($user, $data),
            $data instanceof SearchResourcesTool => ['resources' => $this->resources->search($user, (string) $data->query, $this->emptyToNull($data->kind), $this->emptyToNull($data->tag), $data->includeBase, $data->limit ?? 50, $data->offset ?? 0)],
            $data instanceof GetResourceTool => $this->getResource($user, $data),
            $data instanceof SaveResourceTool => $this->saveResource($user, $data),
            $data instanceof DeleteResourceTool => $this->deleteResource($user, $data),
            $data instanceof OpenArtifactIntakeTool => $this->openIntake($user, $data),
            $data instanceof CloseArtifactIntakeTool => $this->closeIntake($user, $data),
            default => throw new \InvalidArgumentException(sprintf(
                'Payload MCP artefact non supporté : %s',
                get_debug_type($data),
            )),
        };

        // JSON compact, accents et « / » non échappés : TextContent encoderait un tableau en JSON indenté
        // avec \u00e9 et \/ (le catalogue passait de ~97 Ko à ~195 Ko, autant de jetons pour l'agent).
        $text = \is_string($payload)
            ? $payload
            : (string) json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE);
        $content = [new TextContent($text)];
        // Captures : intermédiaires ({"shot": "nom"}) puis finale.
        $images = \is_array($png) ? $png : (\is_string($png) ? [$png] : []);
        if ($images !== [] && class_exists(ImageContent::class)) {
            try {
                foreach ($images as $image) {
                    $content[] = new ImageContent($image, 'image/png');
                }
            } catch (\Throwable) {
                // Version du SDK MCP sans ce constructeur : le rapport texte suffit.
            }
        }

        return new CallToolResult(
            $content,
            false,
            $payload,
        );
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function buildFromKit(BuildArtifactFromKitTool $tool, array $context): array
    {
        // Paramètres bruts (objets vides gardés), sinon la forme tableaux convertie.
        $raw = $this->rawArguments->rawArgument($context, 'build_artifact_from_kit', 'params');
        $params = $raw ?? json_decode((string) json_encode($tool->params ?? new \stdClass()), false);
        $built = $this->kits->build($tool->kit, $params);
        if ($built['document'] === null) {
            return ['valid' => false, 'errors' => array_map(static fn (string $m): array => ['path' => 'params', 'message' => $m], $built['errors'])];
        }
        $result = $this->validator->validate(json_decode((string) json_encode($built['document']), true));

        return ['valid' => $result['valid'], 'errors' => $result['errors'], 'document' => $built['document']];
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function publish(User $user, PublishArtifactTool $tool, array $context): array
    {
        $document = $tool->document;
        if (!\is_array($document) || $document === []) {
            throw new BadRequestHttpException('document requis (objet JSON SDUI).');
        }

        $datasetId = $this->emptyToNull($tool->datasetId);
        if ($datasetId === null) {
            $datasetId = $this->artifacts->artifactStoreFor($user)->getId()->toRfc4122();
        }

        $created = $this->artifacts->create(
            $user,
            $datasetId,
            $tool->title,
            $document,
            $this->emptyToNull($tool->slug),
            $tool->visibility !== '' ? $tool->visibility : 'private',
            $this->emptyToNull($tool->description),
            null,
            $this->rawArguments->emptyObjects($context, 'publish_artifact', 'document'),
        );

        return [
            'id' => $created['id'],
            'slug' => $created['slug'],
            'url' => $created['url'],
            'version' => $created['version'] ?? $created['currentVersion'] ?? 1,
        ];
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function update(User $user, UpdateArtifactTool $tool, array $context): array
    {
        $idOrSlug = $this->resolveIdOrSlug($tool->id, $tool->slug);
        if ($idOrSlug === null) {
            throw new BadRequestHttpException('id ou slug requis.');
        }

        $result = null;
        if ($tool->title !== null || $tool->visibility !== null || $tool->description !== null) {
            $patch = [];
            if ($tool->title !== null) {
                $patch['title'] = $tool->title;
            }
            if ($tool->visibility !== null) {
                $patch['visibility'] = $tool->visibility;
            }
            if ($tool->description !== null) {
                $patch['description'] = $tool->description;
            }
            $result = $this->artifacts->patch($user, $idOrSlug, $patch);
        }

        if (\is_array($tool->document) && $tool->document !== []) {
            $result = $this->artifacts->putDocument(
                $user,
                $idOrSlug,
                $tool->document,
                $this->emptyToNull($tool->note),
                null,
                $this->rawArguments->emptyObjects($context, 'update_artifact', 'document'),
                $tool->draft === true,
            );
        }

        $hasOps = \is_array($tool->ops) && $tool->ops !== [];
        $hasEdits = \is_array($tool->edits) && $tool->edits !== [];
        $setFiles = $this->fetchFiles($tool->fetch);
        if ($hasOps || $hasEdits || $setFiles !== []) {
            if (\is_array($tool->document) && $tool->document !== []) {
                throw new BadRequestHttpException('document est exclusif avec ops/edits.');
            }
            // Versions brutes (objets vides conservés) si le client les fournit.
            $ops = $this->rawArguments->rawArgument($context, 'update_artifact', 'ops') ?? json_decode((string) json_encode($tool->ops ?? []), false);
            $edits = $this->rawArguments->rawArgument($context, 'update_artifact', 'edits') ?? json_decode((string) json_encode($tool->edits ?? []), false);
            $result = $this->artifacts->patchDocument(
                $user,
                $idOrSlug,
                \is_array($ops) ? $ops : [],
                \is_array($edits) ? $edits : [],
                $this->emptyToNull($tool->note),
                null,
                $tool->draft === true,
                $setFiles,
            );
        }

        if ($result === null) {
            throw new BadRequestHttpException('Rien à mettre à jour (document, ops, edits, fetch, title, visibility ou description).');
        }

        $out = [
            'id' => $result['id'],
            'slug' => $result['slug'],
            'url' => $result['url'],
            'version' => $result['version'] ?? $result['currentVersion'],
        ];
        if ($tool->draft === true && (\is_array($tool->document) || $hasOps || $hasEdits || $setFiles !== [])) {
            $out['draft'] = true;
            $out['note'] = 'Brouillon : non publié. Vérifier avec render_artifact / simulate_store, puis promote_artifact.';
        }
        if (\is_array($result['document'] ?? null)) {
            $expanded = ArtifactFiles::expand(json_decode((string) json_encode($result['document']), true));
            $warnings = [...$this->validator->lint($expanded), ...$this->simulator->checkJsonata($expanded)];
            if ($warnings !== []) {
                $out['warnings'] = $warnings;
            }
        }

        return $out;
    }

    /**
     * @param list<mixed>|null $fetch [{name, url, encoding?, sha256?}]
     *
     * @return array<string, string> nom => contenu
     */
    private function fetchFiles(?array $fetch): array
    {
        $files = [];
        foreach ($fetch ?? [] as $i => $item) {
            $item = \is_array($item) ? $item : (array) $item;
            $name = $item['name'] ?? null;
            $url = $item['url'] ?? null;
            if (!\is_string($name) || !preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $name) || !\is_string($url)) {
                throw new BadRequestHttpException(sprintf('fetch[%d] : {name, url} attendus (name : a-z, 0-9, . _ -).', $i));
            }
            $encoding = \is_string($item['encoding'] ?? null) ? $item['encoding'] : 'text';
            $sha = \is_string($item['sha256'] ?? null) ? $item['sha256'] : null;
            $files[$name] = $this->fetcher->fetch($url, $encoding, $sha);
        }

        return $files;
    }

    /** @return array<string, mixed> */
    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function validateWithLint(array $document): array
    {
        $result = $this->validator->validate($document);
        $expanded = ArtifactFiles::expand($document);
        $result['warnings'] = $result['valid'] ? [...$this->validator->lint($expanded), ...$this->simulator->checkJsonata($expanded)] : [];

        return $result;
    }

    /** @return array<string, mixed> */
    private function promote(User $user, PromoteArtifactTool $tool): array
    {
        $idOrSlug = $this->resolveIdOrSlug($tool->id, $tool->slug);
        if ($idOrSlug === null) {
            throw new BadRequestHttpException('id ou slug requis.');
        }
        $r = $this->artifacts->promoteDraft($user, $idOrSlug);

        return [
            'id' => $r['id'],
            'slug' => $r['slug'],
            'url' => $r['url'],
            'version' => $r['currentVersion'],
            'liveVersion' => $r['liveVersion'],
            'hasDraft' => $r['hasDraft'],
        ];
    }

    /**
     * @param string|list<string>|null $png     captures en base64 (intermédiaires puis finale)
     * @param array<string, mixed>     $context
     *
     * @return array<string, mixed>
     */
    private function render(User $user, RenderArtifactTool $tool, string|array|null &$png, array $context = []): array
    {
        $document = $tool->document;
        $emptyObjects = $this->draftEmptyObjects($document, $context, 'render_artifact');
        if (!\is_array($document) || $document === []) {
            $idOrSlug = $this->resolveIdOrSlug($tool->id, $tool->slug);
            if ($idOrSlug === null) {
                throw new BadRequestHttpException('document, id ou slug requis.');
            }
            $document = $this->artifacts->getForUser($user, $idOrSlug)['document'];
        }
        $validation = $this->validator->validate($document);
        $document = ArtifactFiles::expand(json_decode((string) json_encode(JsonShape::restore($document, $emptyObjects)), false));
        if (!$validation['valid']) {
            return ['ok' => false, 'errors' => array_map(static fn (array $e): string => $e['path'].' : '.$e['message'], $validation['errors'])];
        }
        $rendered = $this->renderer->render(array_filter([
            'document' => $document,
            'view' => $tool->view,
            'viewport' => $tool->viewport,
            'steps' => self::decodeItems($tool->steps),
            'settleMs' => $tool->settleMs,
            'fps' => $tool->fps,
            'fullPage' => $tool->fullPage,
        ], static fn (mixed $v): bool => $v !== null));
        $png = [...($rendered['shots'] ?? []), ...(\is_string($rendered['png']) ? [$rendered['png']] : [])];

        return $rendered['result'];
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function simulate(User $user, SimulateStoreTool $tool, array $context = []): array
    {
        $document = $tool->document;
        $emptyObjects = $this->draftEmptyObjects($document, $context, 'simulate_store');
        if (!\is_array($document) || $document === []) {
            $idOrSlug = $this->resolveIdOrSlug($tool->id, $tool->slug);
            if ($idOrSlug === null) {
                throw new BadRequestHttpException('document, id ou slug requis.');
            }
            $document = $this->artifacts->getForUser($user, $idOrSlug)['document'];
        }
        $document = ArtifactFiles::expand(json_decode((string) json_encode(JsonShape::restore($document, $emptyObjects)), false));
        if (!\is_string($tool->store) || $tool->store === '') {
            throw new BadRequestHttpException('store requis.');
        }
        $actions = self::decodeItems($tool->actions);
        if (!\is_array($actions) || $actions === []) {
            throw new BadRequestHttpException('actions requis (liste non vide).');
        }

        return $this->simulator->simulate(array_filter([
            'document' => $document,
            'store' => $tool->store,
            'actions' => $actions,
            'until' => $tool->until,
            'maxSteps' => $tool->maxSteps,
            'snapshots' => $tool->snapshots,
            'tickMs' => $tool->tickMs,
        ], static fn (mixed $v): bool => $v !== null));
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function runArtifact(User $user, RunArtifactTool $tool, array $context): array
    {
        // Entrée brute : les objets vides restent des objets (le SDK MCP les décode en []).
        $rawInput = $this->rawArguments->rawArgument($context, 'run_artifact', 'input');
        if ($tool->document !== null) {
            $document = $this->rawArguments->rawArgument($context, 'run_artifact', 'document')
                ?? json_decode((string) json_encode($tool->document), false);

            return $this->runs->dryRun($document, $rawInput ?? json_decode((string) json_encode($tool->input ?? new \stdClass()), false));
        }
        $idOrSlug = $this->resolveIdOrSlug($tool->id, $tool->slug);
        if ($idOrSlug === null) {
            throw new BadRequestHttpException('id ou slug requis (ou document pour essayer un brouillon).');
        }
        // Entrée brute : les objets vides restent des objets (le SDK MCP les décode en []).
        $input = $this->rawArguments->rawArgument($context, 'run_artifact', 'input')
            ?? json_decode((string) json_encode($tool->input ?? new \stdClass()), false);

        return $this->runs->run($user, $idOrSlug, $input, $this->emptyToNull($tool->mode));
    }

    /** @return array<string, mixed> */
    private function getRun(User $user, GetArtifactRunTool $tool): array
    {
        $runId = $this->emptyToNull($tool->runId);
        $file = $this->emptyToNull($tool->file);
        if ($runId !== null && $file !== null) {
            $content = $this->runs->readFile($user, $runId, $file);
            $meta = ['name' => $file, 'contentType' => $content['contentType'], 'sizeBytes' => $content['sizeBytes'], 'sha256' => $content['sha256']];

            return $content['sizeBytes'] <= self::MAX_INLINE_BYTES
                ? $meta + ['contentBase64' => base64_encode($content['data'])]
                : $meta + ['note' => 'Trop volumineux pour être renvoyé ici : télécharger avec GET /api/artifacts/{slug}/runs/{runId}/files/{name}.'];
        }
        if ($runId !== null) {
            return $this->runs->get($user, $runId);
        }
        $idOrSlug = $this->resolveIdOrSlug($tool->id, $tool->slug);
        if ($idOrSlug === null) {
            throw new BadRequestHttpException('runId, id ou slug requis.');
        }

        return ['runs' => $this->runs->listRuns($user, $idOrSlug, $tool->limit ?? 20)];
    }

    /**
     * Objets vides (`{}`) du brouillon passé en argument, à rétablir avant le rendu : le SDK MCP
     * les décode en `[]`, ce qui cassait les `$merge` des réducteurs (`"play": {}` → `[]`).
     * Un document enregistré revient déjà rétabli par le service.
     *
     * @param array<string, mixed>|null $document
     * @param array<string, mixed>      $context
     *
     * @return list<string>
     */
    private function draftEmptyObjects(?array $document, array $context, string $toolName): array
    {
        if (!\is_array($document) || $document === []) {
            return [];
        }

        return $this->rawArguments->emptyObjects($context, $toolName, 'document');
    }

    /**
     * Étapes / actions : objets attendus ; un élément envoyé comme chaîne JSON
     * (`"{\"press\":\"Enter\"}"`) est décodé au lieu d'être ignoré en silence.
     *
     * @param list<mixed>|null $items
     *
     * @return list<mixed>|null
     */
    private static function decodeItems(?array $items): ?array
    {
        if ($items === null) {
            return null;
        }
        $out = [];
        foreach ($items as $item) {
            if (\is_string($item)) {
                $trimmed = trim($item);
                if ($trimmed !== '' && $trimmed[0] === '{') {
                    try {
                        $item = json_decode($trimmed, true, 64, \JSON_THROW_ON_ERROR);
                    } catch (\JsonException) {
                        throw new BadRequestHttpException(sprintf('Élément JSON invalide : %s', mb_substr($trimmed, 0, 80)));
                    }
                } else {
                    throw new BadRequestHttpException(sprintf('Élément attendu sous forme d\'objet, reçu la chaîne « %s ».', mb_substr($trimmed, 0, 80)));
                }
            }
            $out[] = $item;
        }

        return $out;
    }

    private function get(User $user, GetArtifactTool $tool): array
    {
        $idOrSlug = $this->resolveIdOrSlug($tool->id, $tool->slug);
        if ($idOrSlug === null) {
            throw new BadRequestHttpException('id ou slug requis.');
        }

        $detail = $this->artifacts->getForUser($user, $idOrSlug, $tool->version);
        if ($tool->path === null || $tool->path === '') {
            return $detail;
        }
        $doc = json_decode((string) json_encode($detail['document']), false);
        try {
            $value = $tool->path === '/' ? $doc : JsonPatch::get($doc, $tool->path);
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }
        $out = [
            'id' => $detail['id'],
            'slug' => $detail['slug'],
            'version' => $detail['version'],
            'path' => $tool->path,
        ];
        $keys = $value instanceof \stdClass ? array_keys(get_object_vars($value)) : (\is_array($value) ? range(0, max(0, \count($value) - 1)) : null);
        if ($tool->keysOnly === true) {
            $out['keys'] = $value === [] ? [] : $keys;
        } else {
            $out['value'] = $value;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function delete(User $user, DeleteArtifactTool $tool): array
    {
        $idOrSlug = $this->resolveIdOrSlug($tool->id, $tool->slug);
        if ($idOrSlug === null) {
            throw new BadRequestHttpException('id ou slug requis.');
        }
        $this->artifacts->delete($user, $idOrSlug);

        return ['ok' => true, 'idOrSlug' => $idOrSlug];
    }

    /** @return array<string, mixed> */
    private function writeData(User $user, WriteArtifactDataTool $tool): array
    {
        if ($tool->slug === '' || $tool->collection === '') {
            throw new BadRequestHttpException('slug et collection requis.');
        }
        if (!\is_array($tool->data)) {
            throw new BadRequestHttpException('data requis (objet JSON).');
        }

        if ($tool->recordId !== null && $tool->recordId !== '') {
            $updated = $this->data->updateRecord($user, $tool->slug, $tool->collection, $tool->recordId, $tool->data);

            return ['action' => 'updated', 'record' => $updated];
        }

        $created = $this->data->createRecord($user, $tool->slug, $tool->collection, $tool->data);

        return ['action' => 'created', 'record' => $created];
    }

    private function artifactRef(?string $id, ?string $slug): string
    {
        $ref = $this->emptyToNull($slug) ?? $this->emptyToNull($id);
        if ($ref === null) {
            throw new BadRequestHttpException('slug ou id requis.');
        }

        return $ref;
    }

    /** @return array<string, mixed> */
    private function uploadAsset(User $user, UploadArtifactAssetTool $tool): array
    {
        $ref = $this->artifactRef($tool->id, $tool->slug);
        $hasB64 = $tool->contentBase64 !== null && $tool->contentBase64 !== '';
        $hasFetch = \is_array($tool->fetch) && $tool->fetch !== [];
        if ($hasB64 === $hasFetch) {
            throw new BadRequestHttpException('Fournir exactement une source : contentBase64 ou fetch {url, sha256?}.');
        }
        if ($hasFetch) {
            $fetch = (array) $tool->fetch;
            $url = $fetch['url'] ?? null;
            if (!\is_string($url)) {
                throw new BadRequestHttpException('fetch.url requis.');
            }
            $sha = \is_string($fetch['sha256'] ?? null) ? $fetch['sha256'] : null;
            $bytes = $this->fetcher->fetchBytes($url, ArtifactAssets::MAX_FILE_BYTES, $sha);
        } else {
            $bytes = base64_decode((string) $tool->contentBase64, true);
            if ($bytes === false) {
                throw new BadRequestHttpException('contentBase64 invalide.');
            }
        }

        return $this->artifacts->putAsset($user, $ref, $tool->name, $bytes);
    }

    /** @return array<string, mixed> */
    private function getResource(User $user, GetResourceTool $tool): array
    {
        $res = $this->resources->read($user, $tool->ref, $tool->version);
        $meta = $res['meta'];
        $out = ['resource' => $meta];
        if (ResourceContent::isText((string) $meta['kind'], (string) ($meta['contentType'] ?? 'text/x-glsl')) || ($meta['source'] ?? '') === 'base') {
            $out['content'] = $res['data'];
        } elseif (\strlen($res['data']) <= self::MAX_INLINE_BYTES) {
            $out['contentBase64'] = base64_encode($res['data']);
        } else {
            $out['note'] = 'Contenu trop volumineux pour être renvoyé ici (256 Ko max) : fiche seule.';
        }
        if ($tool->versions && ($meta['source'] ?? '') === 'mine') {
            $out['versions'] = $this->resources->versions($user, (string) $meta['slug']);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function saveResource(User $user, SaveResourceTool $tool): array
    {
        $hasText = $tool->content !== null && $tool->content !== '';
        $hasB64 = $tool->contentBase64 !== null && $tool->contentBase64 !== '';
        if ($hasText === $hasB64) {
            throw new BadRequestHttpException('Fournir exactement une source : content (texte) ou contentBase64.');
        }
        $bytes = $hasText ? (string) $tool->content : base64_decode((string) $tool->contentBase64, true);
        if ($bytes === false) {
            throw new BadRequestHttpException('contentBase64 invalide.');
        }

        return $this->resources->save($user, [
            'kind' => $tool->kind,
            'name' => $tool->name,
            'slug' => $tool->slug,
            'description' => $tool->description,
            'tags' => $tool->tags,
            'prompt' => $tool->prompt,
            'from' => $tool->from,
            'filename' => $tool->filename,
            'bytes' => $bytes,
        ]);
    }

    /** @return array<string, mixed> */
    private function deleteResource(User $user, DeleteResourceTool $tool): array
    {
        $this->resources->delete($user, $tool->slug);

        return ['ok' => true, 'slug' => $tool->slug];
    }

    /** @return array<string, mixed> */
    private function deleteAsset(User $user, DeleteArtifactAssetTool $tool): array
    {
        $this->artifacts->deleteAsset($user, $this->artifactRef($tool->id, $tool->slug), $tool->name);

        return ['ok' => true, 'name' => $tool->name];
    }

    /** @return array<string, mixed> */
    private function openIntake(User $user, OpenArtifactIntakeTool $tool): array
    {
        if ($tool->slug === '' || $tool->collection === '') {
            throw new BadRequestHttpException('slug et collection requis.');
        }

        return ['collection' => $this->data->openIntake($user, $tool->slug, $tool->collection, $tool->minutes ?? 60, $tool->maxRecords)];
    }

    /** @return array<string, mixed> */
    private function closeIntake(User $user, CloseArtifactIntakeTool $tool): array
    {
        if ($tool->slug === '' || $tool->collection === '') {
            throw new BadRequestHttpException('slug et collection requis.');
        }
        $state = $this->data->closeIntake($user, $tool->slug, $tool->collection, $tool->purge);
        if ($tool->rotateReadToken) {
            $state = $this->data->rotateReadToken($user, $tool->slug, $tool->collection);
        }

        return ['collection' => $state];
    }

    private function resolveIdOrSlug(?string $id, ?string $slug): ?string
    {
        $id = $this->emptyToNull($id);
        $slug = $this->emptyToNull($slug);
        if ($id !== null) {
            return $id;
        }

        return $slug;
    }

    private function emptyToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function requireUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Authentification requise.');
        }

        return $user;
    }

    private function toolName(mixed $data): string
    {
        return match (true) {
            $data instanceof GetArtifactCatalogTool => 'get_artifact_catalog',
            $data instanceof BuildArtifactFromKitTool => 'build_artifact_from_kit',
            $data instanceof FindIconsTool => 'find_icons',
            $data instanceof ValidateArtifactTool => 'validate_artifact',
            $data instanceof SimulateStoreTool => 'simulate_store',
            $data instanceof RunArtifactTool => 'run_artifact',
            $data instanceof GetArtifactRunTool => 'get_artifact_run',
            $data instanceof CancelArtifactRunTool => 'cancel_artifact_run',
            $data instanceof ListArtifactToolsTool => 'list_artifact_tools',
            $data instanceof RenderArtifactTool => 'render_artifact',
            $data instanceof PublishArtifactTool => 'publish_artifact',
            $data instanceof UpdateArtifactTool => 'update_artifact',
            $data instanceof PromoteArtifactTool => 'promote_artifact',
            $data instanceof GetArtifactTool => 'get_artifact',
            $data instanceof ListArtifactsTool => 'list_artifacts',
            $data instanceof DeleteArtifactTool => 'delete_artifact',
            $data instanceof ReadArtifactDataTool => 'read_artifact_data',
            $data instanceof WriteArtifactDataTool => 'write_artifact_data',
            $data instanceof UploadArtifactAssetTool => 'upload_artifact_asset',
            $data instanceof ListArtifactAssetsTool => 'list_artifact_assets',
            $data instanceof DeleteArtifactAssetTool => 'delete_artifact_asset',
            $data instanceof SearchResourcesTool => 'search_resources',
            $data instanceof GetResourceTool => 'get_resource',
            $data instanceof SaveResourceTool => 'save_resource',
            $data instanceof DeleteResourceTool => 'delete_resource',
            $data instanceof OpenArtifactIntakeTool => 'open_artifact_intake',
            $data instanceof CloseArtifactIntakeTool => 'close_artifact_intake',
            default => get_debug_type($data),
        };
    }
}
