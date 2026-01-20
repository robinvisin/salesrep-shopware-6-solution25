<?php

declare(strict_types=1);

namespace Salesrep\Core\Content\AgentPayoutHistory;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FloatField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\System\User\UserDefinition;

class AgentPayoutHistoryDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'agent_payout_history';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return AgentPayoutHistoryEntity::class;
    }

    public function getCollectionClass(): string
    {
        return AgentPayoutHistoryCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required(), new ApiAware()),
            (new FkField('salesrep_id', 'salesrepId', UserDefinition::class))->addFlags(new Required(), new ApiAware()),
            (new DateTimeField('payout_date', 'payoutDate'))->addFlags(new Required(), new ApiAware()),
            (new StringField('payment_method', 'paymentMethod'))->addFlags(new Required(), new ApiAware()),
            (new FloatField('outstanding_balance', 'outstandingBalance'))->addFlags(new Required(), new ApiAware()),
            (new FloatField('payout_amount', 'payoutAmount'))->addFlags(new ApiAware()),
            (new LongTextField('note', 'note'))->addFlags(new ApiAware()),
            (new ManyToOneAssociationField('Salesrep', 'salesrep_id', UserDefinition::class, 'id', false))->addFlags(new ApiAware()),
        ]);
    }
}
