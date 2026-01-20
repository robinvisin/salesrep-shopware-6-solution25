<?php

declare(strict_types=1);

namespace Salesrep\Core\Content\OrderClaimRequest;

use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ReferenceVersionField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\System\User\UserDefinition;

final class OrderClaimRequestDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'salesrep_order_claim_request';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return OrderClaimRequestEntity::class;
    }

    public function getCollectionClass(): string
    {
        return OrderClaimRequestCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),

            (new FkField('order_id', 'orderId', OrderDefinition::class))->addFlags(new Required()),
            (new ReferenceVersionField(OrderDefinition::class, 'order_version_id'))->addFlags(new Required()),

            (new FkField('requested_by_user_id', 'requestedByUserId', UserDefinition::class))->addFlags(new Required()),

            (new StringField('status', 'status'))->addFlags(new Required()),
            new LongTextField('reason', 'reason'),
            (new DateTimeField('requested_at', 'requestedAt'))->addFlags(new Required()),

            new FkField('decided_by_user_id', 'decidedByUserId', UserDefinition::class),
            new DateTimeField('decided_at', 'decidedAt'),
            new LongTextField('decision_note', 'decisionNote'),

            new CreatedAtField(),
            new UpdatedAtField(),

            // associations
            new ManyToOneAssociationField('order', 'order_id', OrderDefinition::class, 'id', false),
            new ManyToOneAssociationField('requestedBy', 'requested_by_user_id', UserDefinition::class, 'id', false),
            new ManyToOneAssociationField('decidedBy', 'decided_by_user_id', UserDefinition::class, 'id', false),
        ]);
    }
}
