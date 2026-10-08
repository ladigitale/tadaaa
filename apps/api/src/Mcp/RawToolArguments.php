<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Service\JsonShape;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Le SDK MCP décode les arguments d'outil en tableaux PHP : un `{}` y devient `[]`.
 * On relit le JSON brut de l'appel pour retrouver les objets vides d'un argument.
 *
 * Sources, dans l'ordre :
 * - `$context['raw_arguments']` : arguments décodés en objets (agent intégré) ;
 * - le corps HTTP de la requête MCP : message JSON-RPC `tools/call` de cet outil
 *   (dans un lot, celui dont l'id correspond à `$context['mcp_request']`).
 */
final class RawToolArguments
{
    public function __construct(private readonly RequestStack $requests)
    {
    }

    /**
     * @param array<string, mixed> $context contexte du processor API Platform
     *
     * @return list<string> pointeurs (relatifs à l'argument) des objets vides
     */
    public function emptyObjects(array $context, string $toolName, string $argument): array
    {
        if (\array_key_exists('raw_arguments', $context)) {
            $raw = $context['raw_arguments'];
            $node = $raw instanceof \stdClass && property_exists($raw, $argument) ? $raw->{$argument} : null;

            return $node === null ? [] : JsonShape::emptyObjectPaths($node);
        }

        $request = $context['request'] ?? null;
        if (!$request instanceof Request) {
            $request = $this->requests->getCurrentRequest();
        }
        if ($request === null) {
            return [];
        }
        $content = $request->getContent();
        if ($content === '' || !str_contains($content, '{}')) {
            return [];
        }
        try {
            $decoded = json_decode($content, false, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        $wantedId = $this->requestId($context['mcp_request'] ?? null);
        $candidates = [];
        foreach (\is_array($decoded) ? $decoded : [$decoded] as $message) {
            if (!$message instanceof \stdClass || ($message->method ?? null) !== 'tools/call') {
                continue;
            }
            $params = $message->params ?? null;
            if (!$params instanceof \stdClass || ($params->name ?? null) !== $toolName) {
                continue;
            }
            if ($wantedId !== null && property_exists($message, 'id') && $message->id !== $wantedId) {
                continue;
            }
            $candidates[] = $params->arguments ?? null;
        }
        if (\count($candidates) !== 1 || !$candidates[0] instanceof \stdClass) {
            return [];
        }
        $node = $candidates[0]->{$argument} ?? null;

        return $node === null ? [] : JsonShape::emptyObjectPaths($node);
    }

    private function requestId(mixed $mcpRequest): string|int|null
    {
        if (\is_object($mcpRequest) && method_exists($mcpRequest, 'getId')) {
            $id = $mcpRequest->getId();

            return \is_string($id) || \is_int($id) ? $id : null;
        }

        return null;
    }
}
