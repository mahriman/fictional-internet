<?php

test('JSON structure comparison ignores object key order while preserving nested values and list order', function () {
    $expected = [
        'content' => [
            'headline' => 'A signal at the harbor',
            'body' => 'The lights appeared at dusk.',
        ],
        'references' => [
            ['title' => 'First source', 'content' => ['body' => 'First body.']],
            ['title' => 'Second source', 'content' => ['body' => 'Second body.']],
        ],
    ];
    $sameStructureDifferentObjectOrder = [
        'references' => [
            ['content' => ['body' => 'First body.'], 'title' => 'First source'],
            ['content' => ['body' => 'Second body.'], 'title' => 'Second source'],
        ],
        'content' => [
            'body' => 'The lights appeared at dusk.',
            'headline' => 'A signal at the harbor',
        ],
    ];
    $missingNestedField = $sameStructureDifferentObjectOrder;
    unset($missingNestedField['references'][0]['content']['body']);
    $changedNestedValue = $sameStructureDifferentObjectOrder;
    $changedNestedValue['content']['body'] = 'A different body.';
    $reorderedList = $sameStructureDifferentObjectOrder;
    $reorderedList['references'] = array_reverse($reorderedList['references']);

    expect(canonicalizeJsonStructure($sameStructureDifferentObjectOrder))
        ->toBe(canonicalizeJsonStructure($expected))
        ->and(canonicalizeJsonStructure($missingNestedField))
        ->not->toBe(canonicalizeJsonStructure($expected))
        ->and(canonicalizeJsonStructure($changedNestedValue))
        ->not->toBe(canonicalizeJsonStructure($expected))
        ->and(canonicalizeJsonStructure($reorderedList))
        ->not->toBe(canonicalizeJsonStructure($expected));
});
