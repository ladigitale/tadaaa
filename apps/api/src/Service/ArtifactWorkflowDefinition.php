<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Définition d'un workflow d'artefacts (classe pure, testable) :
 *
 *   {
 *     "title": "Évaluer puis féliciter",
 *     "description": "…",
 *     "input": {"schema": {…JSON Schema…}},
 *     "steps": [
 *       {"id": "quiz", "artifact": "quiz-capitales", "mode": "interactive"},
 *       {"id": "diplome", "artifact": "diplome", "mode": "headless",
 *        "if": "steps.quiz.output.good >= 3",
 *        "input": "{'nom': input.nom, 'score': steps.quiz.output.score}"}
 *     ],
 *     "output": "{'score': steps.quiz.output.score, 'diplome': steps.diplome.output}"
 *   }
 *
 * Les étapes s'exécutent dans l'ordre. Les expressions (`if`, `input`, `output`) sont du JSONata
 * évalué sur `{input, steps: {<id>: {status, output, files}}, previous}` (`previous` = sortie de la
 * dernière étape jouée). `input` absent : l'entrée du workflow pour la première étape, `previous`
 * ensuite. Une étape `optional` qui échoue n'arrête pas le workflow (sa sortie vaut null).
 */
final class ArtifactWorkflowDefinition
{
    public const MAX_STEPS = 20;
    public const MAX_EXPR = 4000;
    private const ID = '/^[a-zA-Z][a-zA-Z0-9_]{0,31}$/';
    private const SLUG = '/^[a-z0-9][a-z0-9-]{2,63}$/';

    /**
     * @return list<array{path: string, message: string}>
     */
    public static function validate(mixed $definition): array
    {
        if (!\is_array($definition) || array_is_list($definition)) {
            return [['path' => '/', 'message' => 'Définition : objet {title?, description?, input?, steps, output?}.']];
        }
        $errors = [];
        foreach (array_keys($definition) as $k) {
            if (!\in_array($k, ['title', 'description', 'input', 'steps', 'output'], true)) {
                $errors[] = ['path' => '/'.$k, 'message' => 'Clé non autorisée (title, description, input, steps, output).'];
            }
        }
        foreach (['title' => 200, 'description' => 1000] as $k => $max) {
            if (\array_key_exists($k, $definition) && (!\is_string($definition[$k]) || trim($definition[$k]) === '' || mb_strlen($definition[$k]) > $max)) {
                $errors[] = ['path' => '/'.$k, 'message' => sprintf('%s : texte de 1 à %d caractères.', $k, $max)];
            }
        }
        if (\array_key_exists('input', $definition)) {
            $input = $definition['input'];
            if (!\is_array($input) || array_is_list($input) || array_diff(array_keys($input), ['schema']) !== []) {
                $errors[] = ['path' => '/input', 'message' => 'input : {schema}.'];
            } else {
                $errors = [...$errors, ...ArtifactIo::checkSchema($input['schema'] ?? null, '/input/schema')];
                if (\is_array($input['schema'] ?? null) && ($input['schema']['type'] ?? null) !== 'object') {
                    $errors[] = ['path' => '/input/schema/type', 'message' => 'L’entrée d’un workflow est un objet (type "object").'];
                }
            }
        }
        $steps = $definition['steps'] ?? null;
        if (!\is_array($steps) || !array_is_list($steps) || $steps === [] || \count($steps) > self::MAX_STEPS) {
            $errors[] = ['path' => '/steps', 'message' => sprintf('steps : liste de 1 à %d étapes.', self::MAX_STEPS)];
            $steps = [];
        }
        $ids = [];
        foreach ($steps as $i => $step) {
            $p = '/steps/'.$i;
            if (!\is_array($step) || array_is_list($step)) {
                $errors[] = ['path' => $p, 'message' => 'Étape : {id, artifact, mode?, input?, if?, optional?}.'];
                continue;
            }
            foreach (array_keys($step) as $k) {
                if (!\in_array($k, ['id', 'artifact', 'mode', 'input', 'if', 'optional', 'title'], true)) {
                    $errors[] = ['path' => $p.'/'.$k, 'message' => 'Clé non autorisée (id, artifact, mode, input, if, optional, title).'];
                }
            }
            $id = $step['id'] ?? null;
            if (!\is_string($id) || !preg_match(self::ID, $id)) {
                $errors[] = ['path' => $p.'/id', 'message' => 'id : lettre puis lettres, chiffres ou _ (32 car. max).'];
            } elseif (isset($ids[$id])) {
                $errors[] = ['path' => $p.'/id', 'message' => sprintf('id « %s » en double.', $id)];
            } elseif (\in_array($id, ['input', 'previous', 'steps'], true)) {
                $errors[] = ['path' => $p.'/id', 'message' => 'id réservé (input, previous, steps).'];
            } else {
                $ids[$id] = true;
            }
            if (!\is_string($step['artifact'] ?? null) || !preg_match(self::SLUG, $step['artifact'])) {
                $errors[] = ['path' => $p.'/artifact', 'message' => 'artifact : slug d’un artefact qui déclare un contrat io.'];
            }
            if (\array_key_exists('mode', $step) && !\in_array($step['mode'], ArtifactIo::MODES, true)) {
                $errors[] = ['path' => $p.'/mode', 'message' => 'mode : '.implode(', ', ArtifactIo::MODES).'.'];
            }
            if (\array_key_exists('optional', $step) && !\is_bool($step['optional'])) {
                $errors[] = ['path' => $p.'/optional', 'message' => 'optional : booléen.'];
            }
            if (\array_key_exists('title', $step) && (!\is_string($step['title']) || mb_strlen($step['title']) > 120)) {
                $errors[] = ['path' => $p.'/title', 'message' => 'title : texte (120 car. max).'];
            }
            foreach (['input', 'if'] as $k) {
                if (\array_key_exists($k, $step) && (!\is_string($step[$k]) || trim($step[$k]) === '' || \strlen($step[$k]) > self::MAX_EXPR)) {
                    $errors[] = ['path' => $p.'/'.$k, 'message' => sprintf('%s : expression JSONata (%d car. max).', $k, self::MAX_EXPR)];
                }
            }
        }
        if (\array_key_exists('output', $definition) && (!\is_string($definition['output']) || trim($definition['output']) === '' || \strlen($definition['output']) > self::MAX_EXPR)) {
            $errors[] = ['path' => '/output', 'message' => sprintf('output : expression JSONata (%d car. max).', self::MAX_EXPR)];
        }

        return $errors;
    }

    /**
     * Expressions JSONata de la définition, par pointeur (contrôle de syntaxe).
     *
     * @param array<string, mixed> $definition
     *
     * @return array<string, string>
     */
    public static function expressions(array $definition): array
    {
        $out = [];
        foreach (\is_array($definition['steps'] ?? null) ? $definition['steps'] : [] as $i => $step) {
            foreach (['input', 'if'] as $k) {
                if (\is_array($step) && \is_string($step[$k] ?? null)) {
                    $out['/steps/'.$i.'/'.$k] = $step[$k];
                }
            }
        }
        if (\is_string($definition['output'] ?? null)) {
            $out['/output'] = $definition['output'];
        }

        return $out;
    }

    /**
     * Donnée des expressions : {input, steps: {id: {status, output, files}}, previous}.
     *
     * @param array<string, array<string, mixed>> $steps état des étapes (ordre d'exécution)
     */
    public static function context(mixed $input, array $steps): \stdClass
    {
        $view = new \stdClass();
        $previous = null;
        foreach ($steps as $id => $state) {
            $entry = ['status' => $state['status'] ?? null, 'output' => $state['output'] ?? null];
            if (isset($state['files'])) {
                $entry['files'] = $state['files'];
            }
            $view->{$id} = (object) $entry;
            if (($state['status'] ?? null) === 'completed') {
                $previous = $state['output'] ?? null;
            }
        }

        return (object) ['input' => $input ?? new \stdClass(), 'steps' => $view, 'previous' => $previous];
    }
}
