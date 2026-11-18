<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ArtifactDocumentValidator;
use App\Service\ArtifactScriptsCatalog;
use App\Service\ArtifactUiRules;
use PHPUnit\Framework\TestCase;

final class ArtifactUiRulesTest extends TestCase
{
    private const CONFIG = __DIR__.'/../../config/artifacts';

    private ArtifactDocumentValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ArtifactDocumentValidator(
            self::CONFIG.'/catalog.json',
            self::CONFIG.'/sdui.schema.json',
            new ArtifactScriptsCatalog(self::CONFIG.'/scripts-catalog.json'),
        );
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @param array<string, mixed>       $stores
     *
     * @return array<string, mixed>
     */
    private static function doc(array $nodes, array $stores = []): array
    {
        $doc = [
            'schema' => 'artifacts/1',
            'title' => 'Test',
            'defaultView' => 'home',
            'views' => [['id' => 'home', 'title' => 'Accueil', 'root' => ['nodes' => $nodes]]],
        ];
        if ($stores !== []) {
            $doc['data'] = ['stores' => $stores];
        }

        return $doc;
    }

    /** @return list<string> */
    private function errors(array $doc): array
    {
        return array_map(static fn (array $e): string => $e['message'], $this->validator->validate($doc)['errors']);
    }

    /** @return list<string> */
    private function warnings(array $doc): array
    {
        return array_map(static fn (array $w): string => $w['message'], $this->validator->lint($doc));
    }

    public function testEveryRecipeIsValidAndClean(): void
    {
        $recipes = $this->validator->recipes();
        self::assertSame(['segments', 'selecteur', 'onglets', 'formulaire'], array_keys($recipes));
        foreach ($recipes as $name => $recipe) {
            self::assertNotSame('', $recipe['title'], $name);
            self::assertNotSame('', $recipe['when'], $name);
            $doc = self::doc([$recipe['node']], $recipe['stores'] ?? []);
            $result = $this->validator->validate($doc);
            self::assertSame([], $result['errors'], 'recette '.$name);
            self::assertSame([], $this->validator->lint($doc), 'recette '.$name);
        }
    }

    public function testFormRecipeIsCleanInsideAModal(): void
    {
        $recipe = $this->validator->recipes()['formulaire'];
        $doc = self::doc([['tagName' => 'sonic-modal', 'nodes' => [['tagName' => 'sonic-modal-content', 'nodes' => [$recipe['node']]]]]], $recipe['stores']);
        self::assertSame([], $this->errors($doc));
        self::assertSame([], $this->warnings($doc));
    }

    public function testSonicSelectIsRefusedWithTheRecipe(): void
    {
        $errors = $this->errors(self::doc([['tagName' => 'sonic-select', 'attributes' => ['name' => 'lieu']]]));
        self::assertCount(1, $errors);
        self::assertStringContainsString('sonic-select est refusé', $errors[0]);
        self::assertStringContainsString('« selecteur »', $errors[0]);
        self::assertNotContains('sonic-select', $this->validator->allowedTags());
    }

    public function testNativeControlsPointToTheirReplacement(): void
    {
        foreach (['select' => 'selecteur', 'button' => 'sonic-button', 'input' => 'sonic-input', 'textarea' => 'sonic-textarea', 'form' => 'formulaire'] as $tag => $hint) {
            $errors = $this->errors(self::doc([['tagName' => $tag]]));
            self::assertCount(1, $errors, $tag);
            self::assertStringContainsString($hint, $errors[0], $tag);
            self::assertStringNotContainsString('hors liste blanche', $errors[0], $tag);
        }
    }

    public function testUnknownTagKeepsTheWhitelistMessage(): void
    {
        self::assertSame(['Composant hors liste blanche : evil-unknown'], $this->errors(self::doc([['tagName' => 'evil-unknown']])));
    }

    public function testCatalogHidesRefusedComponentsAndListsRecipes(): void
    {
        $payload = $this->validator->mcpCatalogPayload(true, null, null, false);
        $names = array_column($payload['catalog']['components'], 'name');
        self::assertNotContains('sonic-select', $names);
        self::assertContains('sonic-pop', $names);
        self::assertStringContainsString('selecteur', $payload['rules']['recettes']);
        self::assertArrayNotHasKey('recipes', $payload);

        foreach (['recettes', 'design', 'outil'] as $rule) {
            $asked = $this->validator->mcpCatalogPayload(true, ['__none__'], [$rule], false);
            self::assertSame(['segments', 'selecteur', 'onglets', 'formulaire'], array_keys($asked['recipes']), $rule);
        }
        self::assertArrayNotHasKey('recipes', $this->validator->mcpCatalogPayload(true, ['__none__'], ['son'], false));
        self::assertStringContainsString('recettes', $this->validator->catalogIndex());
        self::assertSame(1, preg_match('/^- UI : (.+)$/m', $this->validator->catalogIndex(), $ui));
        self::assertNotContains('sonic-select', explode(', ', $ui[1]));
        self::assertContains('sonic-pop', explode(', ', $ui[1]));
    }

    public function testPointerdownWarnsOnInterfaceButtonsOnly(): void
    {
        $action = ['tagName' => 'sonic-action', 'attributes' => ['target' => 'etat', 'type' => 'save', 'trigger' => 'pointerdown'], 'nodes' => [['tagName' => 'sonic-button', 'textContent' => 'OK']]];
        $stores = ['etat' => ['initial' => ['n' => 0], 'reducer' => '$state']];

        $warnings = $this->warnings(self::doc([$action], $stores));
        self::assertCount(1, $warnings);
        self::assertStringContainsString('pointerdown', $warnings[0]);

        // Jeu ou instrument : l'appui immédiat est voulu.
        $game = self::doc([$action, ['tagName' => 'sonic-ticker', 'attributes' => ['target' => 'etat', 'type' => 'tick']]], $stores);
        self::assertSame([], $this->warnings($game));

        // hold-repeat implique pointerdown.
        $action['attributes']['hold-repeat'] = '120';
        self::assertSame([], $this->warnings(self::doc([$action], $stores)));

        // Départ au clic : rien à dire.
        unset($action['attributes']['trigger'], $action['attributes']['hold-repeat']);
        self::assertSame([], $this->warnings(self::doc([$action], $stores)));
    }

    public function testTabActionSuggestsTheTabsRecipe(): void
    {
        $stores = ['etat' => ['initial' => ['onglet' => 'a'], 'reducer' => '$state']];
        foreach (['tab', 'setTab', 'set-onglet', 'onglet'] as $type) {
            $warnings = $this->warnings(self::doc([['tagName' => 'sonic-action', 'attributes' => ['target' => 'etat', 'type' => $type]]], $stores));
            self::assertCount(1, $warnings, $type);
            self::assertStringContainsString('« onglets »', $warnings[0], $type);
        }
        foreach (['select', 'table', 'stab', 'save'] as $type) {
            self::assertSame([], $this->warnings(self::doc([['tagName' => 'sonic-action', 'attributes' => ['target' => 'etat', 'type' => $type]]], $stores)), $type);
        }
    }

    public function testLooseFieldsInModalOrPopSuggestTheFormRecipe(): void
    {
        $fields = [
            ['tagName' => 'sonic-input', 'attributes' => ['formDataProvider' => 'fiche', 'name' => 'nom', 'label' => 'Nom']],
            ['tagName' => 'div', 'nodes' => [['tagName' => 'sonic-textarea', 'attributes' => ['formDataProvider' => 'fiche', 'name' => 'note', 'label' => 'Note']]]],
        ];
        $stores = ['fiche' => ['initial' => ['nom' => '', 'note' => ''], 'reducer' => '$state']];
        foreach (['sonic-modal', 'sonic-pop'] as $tag) {
            $warnings = $this->warnings(self::doc([['tagName' => $tag, 'nodes' => $fields]], $stores));
            self::assertCount(1, $warnings, $tag);
            self::assertStringContainsString('« formulaire »', $warnings[0], $tag);
        }
        // Un seul champ (recherche dans un pop), ou champs déjà rangés : rien.
        self::assertSame([], $this->warnings(self::doc([['tagName' => 'sonic-pop', 'nodes' => [$fields[0]]]], $stores)));
        self::assertSame([], $this->warnings(self::doc([['tagName' => 'sonic-modal', 'nodes' => [['tagName' => 'sonic-form-layout', 'nodes' => $fields]]]], $stores)));
        // Hors modal / pop : pas d'avis.
        self::assertSame([], $this->warnings(self::doc($fields, $stores)));
    }

    public function testReplacedTagLookupIsCaseInsensitive(): void
    {
        self::assertNotNull(ArtifactUiRules::replacedTagMessage('SONIC-SELECT'));
        self::assertNull(ArtifactUiRules::replacedTagMessage('sonic-pop'));
    }
}
