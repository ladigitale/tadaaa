<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * Compteurs de validation des artefacts : combien de documents passent, et quelles règles
 * sont enfreintes, par jour et par origine. Sert à mesurer où la documentation destinée
 * aux agents échoue (get_artifact_validation_stats).
 *
 * Aucun document, aucun utilisateur n'est enregistré : seulement des compteurs.
 * Une règle compte une fois par document, quel que soit le nombre d'occurrences.
 */
final class ArtifactValidationStats
{
    /** validate_artifact (MCP). */
    public const SOURCE_VALIDATE = 'validate';
    /** Création ou mise à jour d'un artefact (MCP, atelier, API). */
    public const SOURCE_SAVE = 'save';
    /** Aperçu de l'atelier (preview_artifact). */
    public const SOURCE_ATELIER = 'atelier';

    public const SOURCES = [self::SOURCE_VALIDATE, self::SOURCE_SAVE, self::SOURCE_ATELIER];

    private const MAX_RULES_PER_DOCUMENT = 24;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Enregistre une validation. Ne lève jamais : la mesure ne doit pas faire échouer l'opération mesurée.
     *
     * @param array{valid?: bool, errors?: list<array<string, mixed>>} $result   Résultat de ArtifactDocumentValidator::validate
     * @param list<array<string, mixed>>                               $warnings Avis (lint), pour un document valide
     */
    public function record(string $source, array $result, array $warnings = []): void
    {
        try {
            // Dans une transaction ouverte, une erreur SQL l'invaliderait tout entière : on s'abstient.
            if ($this->connection->isTransactionActive()) {
                return;
            }
            $valid = ($result['valid'] ?? false) === true;
            $rows = [['total', '']];
            if ($valid) {
                $rows[] = ['valid', ''];
            }
            foreach (self::rules($result['errors'] ?? []) as $rule) {
                $rows[] = ['error', $rule];
            }
            foreach (self::rules($warnings) as $rule) {
                $rows[] = ['warning', $rule];
            }
            $day = gmdate('Y-m-d');
            foreach ($rows as [$kind, $rule]) {
                $this->connection->executeStatement(
                    <<<'SQL'
                        INSERT INTO artifact_validation_stats (day, source, kind, rule, hits)
                        VALUES (:day, :source, :kind, :rule, 1)
                        ON CONFLICT (day, source, kind, rule) DO UPDATE SET hits = artifact_validation_stats.hits + 1
                    SQL,
                    ['day' => $day, 'source' => $source, 'kind' => $kind, 'rule' => $rule],
                );
            }
        } catch (\Throwable) {
            // Table absente, base indisponible : la validation continue sans mesure.
        }
    }

    /**
     * Règles distinctes d'une liste d'erreurs ou d'avis.
     *
     * @param list<array<string, mixed>> $issues
     *
     * @return list<string>
     */
    public static function rules(array $issues): array
    {
        $rules = [];
        foreach ($issues as $issue) {
            if (!\is_array($issue)) {
                continue;
            }
            $rule = \is_string($issue['rule'] ?? null) && $issue['rule'] !== ''
                ? $issue['rule']
                : self::ruleOf(\is_string($issue['message'] ?? null) ? $issue['message'] : '');
            $rules[mb_substr($rule, 0, 120)] = true;
            if (\count($rules) >= self::MAX_RULES_PER_DOCUMENT) {
                break;
            }
        }

        return array_keys($rules);
    }

    /**
     * Nom stable d'une règle à partir de son message : les valeurs propres au document
     * (noms entre guillemets, nombres, détail après « : ») sont retirées.
     */
    public static function ruleOf(string $message): string
    {
        $message = trim($message);
        if (preg_match('/^(sonic-select|select|option|button|input|textarea|form)\b[^.]*\brefusé/u', $message, $m) === 1) {
            return 'refus:'.$m[1];
        }
        if (preg_match('/^Composant hors liste blanche : (\S{1,60})/u', $message, $m) === 1) {
            return 'hors-liste:'.strtolower($m[1]);
        }
        $rule = preg_replace(
            ['/«[^»]*»/u', '/"[^"]*"/u', '/\'[^\']*\'/u', '/`[^`]*`/u', '/\d+(?:[.,]\d+)?/u', '/\s+/u'],
            ['«…»', '"…"', '\'…\'', '`…`', 'N', ' '],
            $message,
        ) ?? $message;
        $cut = mb_strpos($rule, ' : ');
        if ($cut !== false && $cut >= 12) {
            $rule = mb_substr($rule, 0, $cut);
        }
        $rule = rtrim(mb_substr($rule, 0, 90), ' .');

        return $rule === '' ? 'autre' : $rule;
    }

    /**
     * @return array{days: int, since: string, sources: array<string, array<string, mixed>>, note: string}
     */
    public function stats(int $days = 7, ?string $source = null): array
    {
        $days = max(1, min(90, $days));
        $since = gmdate('Y-m-d', time() - ($days - 1) * 86400);
        $sql = 'SELECT day, source, kind, rule, hits FROM artifact_validation_stats WHERE day >= :since';
        $params = ['since' => $since];
        if ($source !== null && $source !== '') {
            $sql .= ' AND source = :source';
            $params['source'] = $source;
        }
        $sources = [];
        foreach ($this->connection->fetchAllAssociative($sql.' ORDER BY day', $params) as $row) {
            $src = (string) $row['source'];
            $day = substr((string) $row['day'], 0, 10);
            $hits = (int) $row['hits'];
            $sources[$src] ??= ['total' => 0, 'valid' => 0, 'validRate' => null, 'errors' => [], 'warnings' => [], 'byDay' => []];
            $sources[$src]['byDay'][$day] ??= ['total' => 0, 'valid' => 0];
            switch ($row['kind']) {
                case 'total':
                case 'valid':
                    $sources[$src][$row['kind']] += $hits;
                    $sources[$src]['byDay'][$day][$row['kind']] += $hits;
                    break;
                case 'error':
                case 'warning':
                    $key = $row['kind'].'s';
                    $sources[$src][$key][(string) $row['rule']] = ($sources[$src][$key][(string) $row['rule']] ?? 0) + $hits;
                    break;
            }
        }
        foreach ($sources as &$entry) {
            $entry['validRate'] = $entry['total'] > 0 ? round($entry['valid'] / $entry['total'], 3) : null;
            foreach (['errors', 'warnings'] as $key) {
                $counts = $entry[$key];
                // Les plus fréquentes d'abord ; à égalité, ordre alphabétique (résultat stable).
                uksort($counts, static fn (string $x, string $y): int => ($counts[$y] <=> $counts[$x]) ?: strcmp($x, $y));
                // Objet JSON même vide (sinon `[]`), pour une forme constante côté client.
                $entry[$key] = $counts === [] ? new \stdClass() : \array_slice($counts, 0, 40, true);
            }
        }
        unset($entry);
        ksort($sources);

        return [
            'days' => $days,
            'since' => $since,
            'sources' => $sources,
            'note' => 'Compteurs par document : une règle compte une fois par document validé. validate = validate_artifact (MCP) ; save = création ou mise à jour d’un artefact ; atelier = aperçu de l’atelier. errors : documents refusés pour cette règle ; warnings : documents valides avec cet avis.',
        ];
    }
}
