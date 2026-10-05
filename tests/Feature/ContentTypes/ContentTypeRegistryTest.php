<?php

use App\ContentTypes\ContentTypeRegistry;
use App\ContentTypes\Definitions\NewsArticleType;
use App\ContentTypes\Definitions\SchreckNetThreadType;

test('the registry resolves the news article definition by its stable key', function () {
    $definition = app(ContentTypeRegistry::class)->get('news_article');

    expect($definition)->toBeInstanceOf(NewsArticleType::class)
        ->and($definition->key())->toBe('news_article')
        ->and($definition->outputSchema()['required'])->toBe(['headline', 'publication', 'published_at', 'body']);
});

test('the registry rejects unknown content type keys', function () {
    expect(fn () => app(ContentTypeRegistry::class)->get('unknown_type'))
        ->toThrow(InvalidArgumentException::class, 'Content type key [unknown_type] is not registered.');
});

test('the registry rejects duplicate content type keys', function () {
    expect(fn () => new ContentTypeRegistry(new NewsArticleType, new NewsArticleType))
        ->toThrow(InvalidArgumentException::class, 'Content type key [news_article] is already registered.');
});

test('the registry includes SchreckNet Thread as a separate content type', function () {
    $definition = app(ContentTypeRegistry::class)->get('schrecknet_thread');

    expect($definition)->toBeInstanceOf(SchreckNetThreadType::class)
        ->and($definition->key())->toBe('schrecknet_thread')
        ->and($definition->label())->toBe('SchreckNet Thread')
        ->and(array_keys(app(ContentTypeRegistry::class)->all()))->toBe(['news_article', 'forum_thread', 'schrecknet_thread']);
});
