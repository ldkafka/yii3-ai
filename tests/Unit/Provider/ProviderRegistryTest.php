<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tests\Unit\Provider;

use Ldkafka\Ai\Exception\ConfigurationException;
use Ldkafka\Ai\Message\Message;
use Ldkafka\Ai\Model\ModelProfile;
use Ldkafka\Ai\Provider\ProviderRegistry;
use Ldkafka\Ai\Request;
use Ldkafka\Ai\Tests\Support\FakeProvider;
use PHPUnit\Framework\TestCase;

final class ProviderRegistryTest extends TestCase
{
    public function testDispatchesOnTheModelProvider(): void
    {
        $fake = new FakeProvider([FakeProvider::response(Message::assistant('ok'))]);
        $registry = new ProviderRegistry(['fake' => $fake]);

        $response = $registry->handle(new Request(), new ModelProfile('m', 'fake', 'm', [], 10, 10, 10));

        self::assertSame('ok', $response->text());
        self::assertSame($fake, $registry->get('fake'));
    }

    public function testUnknownProvider(): void
    {
        $this->expectException(ConfigurationException::class);
        (new ProviderRegistry([]))->handle(new Request(), new ModelProfile('m', 'none', 'm', [], 10, 10, 10));
    }
}
