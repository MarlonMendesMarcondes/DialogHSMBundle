<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<MessageLog>
 */
class MessageLogRepository extends CommonRepository
{
    public function getTableAlias(): string
    {
        return 'dhml';
    }
}
