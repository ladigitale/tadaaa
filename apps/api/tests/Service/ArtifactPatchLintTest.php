<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ArtifactPatchLint;
use PHPUnit\Framework\TestCase;

final class ArtifactPatchLintTest extends TestCase
{
    private static function lint(): ArtifactPatchLint
    {
        return new ArtifactPatchLint(__DIR__.'/../../config/artifacts/audio-patch.json');
    }

    /** @param array<string, mixed> $attrs */
    private static function n(string $tag, array $attrs = [], array $nodes = []): array
    {
        return ['tagName' => $tag, 'attributes' => $attrs, 'nodes' => $nodes];
    }

    private static function voice(array ...$children): array
    {
        return self::n('sonic-voice', [], $children);
    }

    /** @param list<array<string, mixed>> $nodes */
    private static function patch(array $nodes): array
    {
        return self::n('sonic-patch', ['id' => 'p'], $nodes);
    }

    public function testValidSynthWithNamedCablesPasses(): void
    {
        $patch = self::patch([
            self::voice(
                self::n('sonic-osc', ['name' => 'o1']),
                self::n('sonic-ladder', ['name' => 'f', 'in' => 'o1']),
                self::n('sonic-env', ['name' => 'e']),
                self::n('sonic-lfo', ['name' => 'l', 'rate-hz' => '5']),
                self::n('sonic-mod', ['name' => 'me', 'from' => 'e', 'to' => 'f.freq-hz', 'amount' => '0', 'curve' => 'exp']),
                self::n('sonic-mod', ['name' => 'vib', 'from' => 'l', 'to' => 'o1.detune', 'amount' => '0']),
                self::n('sonic-mod', ['from' => 'voice.vel', 'to' => 'f.res', 'amount' => '0.2']),
            ),
            ['libraryKey' => 'P', 'attributes' => ['source' => 'x.env', 'to' => 'me.amount']],
            ['libraryKey' => 'P', 'attributes' => ['source' => 'x.vib', 'to' => 'vib.amount']],
            ['libraryKey' => 'P', 'attributes' => ['source' => 'x.cut', 'to' => 'f.freq-hz']],
            ['libraryKey' => 'P', 'attributes' => ['source' => 'x.lr', 'to' => 'l.rate-hz']],
        ]);
        $library = ['P' => self::n('sonic-param', ['ramp-s' => '0.03'])];
        self::assertSame([], self::lint()->check($patch, $library));
    }

    public function testCompilerErrorsAreReportedWithTheFix(): void
    {
        $voice = self::voice(
            self::n('sonic-osc', ['name' => 'o']),
            self::n('sonic-filter', ['name' => 'f']),
            self::n('sonic-lfo', ['name' => 'l']),
            self::n('sonic-pan', ['name' => 'p']),
            self::n('sonic-mod', ['from' => 'l', 'to' => 'f.freq-hz', 'amount' => '500']),
        );
        $cases = [
            // La profondeur d'un LFO n'est pas un paramètre : c'est l'amount du câble (nommé).
            [self::n('sonic-param', ['to' => 'l.amount', 'source' => 'x.v']), 'n\'a pas de paramètre « amount »'],
            // Module de câble inconnu / câble non nommé.
            [self::n('sonic-param', ['to' => 'vib.amount', 'source' => 'x.v']), 'module « vib » inconnu'],
            // amount dynamique.
            [self::n('sonic-mod', ['from' => 'l', 'to' => 'o.detune', 'amount' => 'x.vib']), 'amount doit être un nombre'],
            // exp vers un module sans detune.
            [self::n('sonic-mod', ['from' => 'l', 'to' => 'p.pan', 'amount' => '1', 'curve' => 'exp']), 'curve="exp" seulement vers freq-hz de'],
            // paramètre non modulable (wave).
            [self::n('sonic-mod', ['from' => 'l', 'to' => 'o.wave', 'amount' => '1']), 'n\'est pas modulable'],
            // source inconnue, cible mal formée, rien à piloter.
            [self::n('sonic-mod', ['from' => 'zz', 'to' => 'o.detune', 'amount' => '1']), 'source « zz » inconnue'],
            [self::n('sonic-param', ['to' => 'o', 'value' => '1']), 'module.paramètre'],
            [self::n('sonic-param', ['to' => 'o.level']), 'sans effet'],
        ];
        foreach ($cases as [$extra, $needle]) {
            $errors = self::lint()->check(self::patch([$voice, $extra]));
            self::assertStringContainsString($needle, implode(' | ', $errors), $needle);
        }
        // Nom de câble égal à un nom de module ; paramètre autre qu'amount sur un câble nommé.
        $dup = self::lint()->check(self::patch([self::voice(self::n('sonic-osc', ['name' => 'o']), self::n('sonic-lfo', ['name' => 'l']), self::n('sonic-mod', ['name' => 'o', 'from' => 'l', 'to' => 'o.detune']))]));
        self::assertStringContainsString('invalide ou déjà pris', implode(' | ', $dup));
        $depth = self::lint()->check(self::patch([self::voice(self::n('sonic-osc', ['name' => 'o']), self::n('sonic-lfo', ['name' => 'l']), self::n('sonic-mod', ['name' => 'vib', 'from' => 'l', 'to' => 'o.detune'])), self::n('sonic-param', ['to' => 'vib.depth', 'value' => '1'])]));
        self::assertStringContainsString('que le paramètre « amount »', implode(' | ', $depth));
    }

    public function testAutomaticModuleNamesMatchTheCompiler(): void
    {
        // Sans name, le compilateur nomme `osc1`, `lfo2`… (compteur sur les modules sans name).
        $patch = self::patch([self::voice(self::n('sonic-osc'), self::n('sonic-lfo'), self::n('sonic-mod', ['from' => 'lfo2', 'to' => 'osc1.detune', 'amount' => '10']))]);
        self::assertSame([], self::lint()->check($patch));
    }
}
