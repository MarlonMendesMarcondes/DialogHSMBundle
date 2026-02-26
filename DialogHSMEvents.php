<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle;

final class DialogHSMEvents
{
    public const ON_CAMPAIGN_TRIGGER_ACTION        = 'mautic.dialoghsm.on_campaign_trigger_action';
    public const ON_CAMPAIGN_TRIGGER_ACTION_QUEUE  = 'mautic.dialoghsm.on_campaign_trigger_action_queue';
    public const ON_CAMPAIGN_TRIGGER_CONSUME_QUEUE = 'mautic.dialoghsm.on_campaign_trigger_consume_queue';
}
