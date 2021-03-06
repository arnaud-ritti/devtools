<?php

declare(strict_types=1);

namespace App\Schedule;

use Zenstruck\ScheduleBundle\Schedule;
use Zenstruck\ScheduleBundle\Schedule\ScheduleBuilder;

class Scheduler implements ScheduleBuilder
{
    public function buildSchedule(Schedule $schedule): void
    {
        $schedule
            ->timezone('UTC')
            ->environments('dev', 'prod')
        ;

        $schedule->addCommand('doctrine:fixtures:load --no-interaction')
            ->cron('0 */2 * * *')
        ;
        $schedule->addCommand('buckets:remove-expired --no-interaction')
            ->cron('0 * * * *')
        ;
    }
}
