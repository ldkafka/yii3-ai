<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Creates `{{%ai_message}}` for DbConversationStore. Not registered automatically: add
 * `Ldkafka\Ai\Migration` to `params['yiisoft/db-migration']['sourceNamespaces']` to use it.
 */
final class M261012000000CreateAiMessageTable implements RevertibleMigrationInterface
{
    public const TABLE = '{{%ai_message}}';

    /**
     * Create the table and its index.
     */
    public function up(MigrationBuilder $b): void
    {
        $column = $b->columnBuilder();

        $b->createTable(self::TABLE, [
            'id' => $column::bigPrimaryKey(),
            'conversation_id' => $column::string(64)->notNull(),
            'role' => $column::string(16)->notNull(),
            'parts' => $column::json()->notNull(),
            'provider' => $column::string(32)->null(),
            'model' => $column::string(128)->null(),
            'stop_reason' => $column::string(32)->null(),
            'input_tokens' => $column::integer()->null(),
            'output_tokens' => $column::integer()->null(),
            'cache_read_tokens' => $column::integer()->null(),
            'cache_write_tokens' => $column::integer()->null(),
            'reasoning_tokens' => $column::integer()->null(),
            'cost_usd' => $column::decimal(16, 9)->null(),
            'created_at' => $column::datetime(3)->notNull(),
        ]);
        $b->createIndex(self::TABLE, 'idx-ai_message-conversation_id-id', ['conversation_id', 'id']);
    }

    /**
     * Drop the table.
     */
    public function down(MigrationBuilder $b): void
    {
        $b->dropTable(self::TABLE);
    }
}
