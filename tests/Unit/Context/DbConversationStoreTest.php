<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tests\Unit\Context;

use Ldkafka\Ai\Context\DbConversationStore;
use Ldkafka\Ai\Cost;
use Ldkafka\Ai\Message\Message;
use Ldkafka\Ai\Message\Thinking;
use Ldkafka\Ai\Message\ToolCall;
use Ldkafka\Ai\Message\ToolResult;
use Ldkafka\Ai\Migration\M261020000000CreateAiMessageTable;
use Ldkafka\Ai\Response;
use Ldkafka\Ai\StopReason;
use Ldkafka\Ai\Usage;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Yiisoft\Cache\ArrayCache;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Migration\Informer\NullMigrationInformer;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Sqlite\Connection;
use Yiisoft\Db\Sqlite\Driver;

/**
 * DbConversationStore on in-memory SQLite with the real migration.
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class DbConversationStoreTest extends TestCase
{
    private Connection $db;

    private MigrationBuilder $builder;

    protected function setUp(): void
    {
        $this->db = new Connection(new Driver('sqlite::memory:'), new SchemaCache(new ArrayCache()));
        $this->builder = new MigrationBuilder($this->db, new NullMigrationInformer());
        (new M261020000000CreateAiMessageTable())->up($this->builder);
    }

    public function testRoundTripKeepsPartsOrderAndAccounting(): void
    {
        $store = new DbConversationStore($this->db);
        $user = Message::user('Where is booking 1234?');
        $assistant = Message::assistant(
            new Thinking('', 'anthropic', ['signature' => 'sig']),
            new ToolCall('toolu_1', 'lookup', '{"id":1234,"filters":{}}'),
        );
        $results = Message::user(ToolResult::text('toolu_1', 'lookup', 'Confirmed.'));
        $response = new Response(
            $assistant,
            StopReason::ToolUse,
            'tool_use',
            new Usage(100, 20, 300, 400, 5),
            'anthropic',
            'claude-haiku-5-5',
            cost: new Cost(0.000001, 0.00001, 0.000003, 0.00005),
        );

        $store->append('ticket-42', $user);
        $store->append('ticket-42', $assistant, $response);
        $store->append('ticket-42', $results);
        $store->append('other', Message::user('x'));

        $loaded = $store->load('ticket-42');
        self::assertEquals([$user, $assistant, $results], $loaded);
        self::assertSame('{"id":1234,"filters":{}}', $loaded[1]->toolCalls()[0]->argumentsJson);

        $row = $this->db->createQuery()->from('ai_message')->where(['role' => 'assistant'])->one();
        self::assertIsArray($row);
        self::assertSame('ticket-42', $row['conversation_id']);
        self::assertSame('anthropic', $row['provider']);
        self::assertSame('claude-haiku-5-5', $row['model']);
        self::assertSame('tool_use', $row['stop_reason']);
        self::assertEquals(300, $row['cache_read_tokens']);
        self::assertEquals(5, $row['reasoning_tokens']);
        self::assertEqualsWithDelta(0.000064, (float) $row['cost_usd'], 1e-12);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\.\d{3}$/', (string) $row['created_at']);

        $userRow = $this->db->createQuery()->from('ai_message')->where(['role' => 'user'])->one();
        self::assertIsArray($userRow);
        self::assertNull($userRow['model']);
        self::assertNull($userRow['cost_usd']);
    }

    public function testDeleteAndUnknownConversation(): void
    {
        $store = new DbConversationStore($this->db);
        $store->append('a', Message::user('1'));
        $store->append('b', Message::user('2'));

        $store->delete('a');

        self::assertSame([], $store->load('a'));
        self::assertCount(1, $store->load('b'));
        self::assertSame([], $store->load('never'));
    }

    public function testMigrationDownDropsTheTable(): void
    {
        self::assertNotNull($this->db->getTableSchema('ai_message', true));

        (new M261020000000CreateAiMessageTable())->down($this->builder);

        self::assertNull($this->db->getTableSchema('ai_message', true));
    }
}
