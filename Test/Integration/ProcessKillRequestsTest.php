<?php

declare(strict_types=1);

namespace EthanYehuda\CronjobManager\Test\Integration;

use EthanYehuda\CronjobManager\Api\Data\ScheduleInterface;
use EthanYehuda\CronjobManager\Api\ScheduleManagementInterface;
use EthanYehuda\CronjobManager\Model\ClockInterface;
use EthanYehuda\CronjobManager\Model\ProcessManagement;
use EthanYehuda\CronjobManager\Test\Util\FakeClock;
use Magento\Cron\Model\Schedule;
use Magento\Framework\Event;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * @magentoAppArea crontab
 * @magentoAppIsolation enabled
 */
class ProcessKillRequestsTest extends TestCase
{
    protected const NOW = '2019-02-09 18:33:00';
    protected const REMOTE_HOSTNAME = 'hostname.example.net';
    protected const SIGKILL = 9;

    /**
     * @var int
     */
    private $childPid = 0;

    /** @var ObjectManagerInterface */
    private $objectManager;

    /**
     * @var Event\ManagerInterface
     */
    private $eventManager;

    /**
     * @var ScheduleManagementInterface
     */
    private $scheduleManagement;

    /** @var \Magento\Cron\Model\ResourceModel\Schedule */
    private $scheduleResource;

    /**
     * @var ProcessManagement
     */
    private $processManagement;

    /**
     * @var FakeClock
     */
    private $clock;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
        $this->objectManager->configure(['preferences' => [ClockInterface::class => FakeClock::class]]);
        $this->clock = $this->objectManager->get(ClockInterface::class);
        $this->clock->setTimestamp(strtotime(self::NOW));
        $this->eventManager = $this->objectManager->get(Event\ManagerInterface::class);
        $this->scheduleManagement = $this->objectManager->get(ScheduleManagementInterface::class);
        $this->processManagement = $this->objectManager->get(ProcessManagement::class);
        $this->scheduleResource = $this->objectManager->get(\Magento\Cron\Model\ResourceModel\Schedule::class);
    }

    protected function tearDown(): void
    {
        /*
         * Take care of children that we failed to kill
         */
        if ($this->childPid) {
            \posix_kill($this->childPid, self::SIGKILL);
        }
    }

    public function testRunningJobsMarkedForDeadOnThisHostAreCleaned()
    {
        $this->givenRunningScheduleWithKillRequest($schedule, $this->timeStampInThePast());
        $this->givenScheduleIsRunningOnHost($schedule, \gethostname());
        $this->whenEventIsDispatched('process_cron_queue_before');
        $this->thenScheduleHasStatus($schedule, ScheduleInterface::STATUS_KILLED);
        $this->andScheduleHasMessage($schedule, 'Process was killed at ' . self::NOW);
        $this->andProcessIsKilled($schedule);
    }

    public function testRunningJobsMarkedForDeadOnAnotherHostAreNotCleaned()
    {
        $this->givenRunningScheduleWithKillRequest($schedule, $this->timeStampInThePast());
        $this->givenScheduleIsRunningOnHost($schedule, self::REMOTE_HOSTNAME);
        $this->whenEventIsDispatched('process_cron_queue_before');
        $this->thenScheduleHasStatus($schedule, Schedule::STATUS_RUNNING);
    }

    private function givenRunningScheduleWithKillRequest(&$schedule, int $timestamp)
    {
        /** @var Schedule $schedule */
        $schedule = $this->objectManager->create(Schedule::class);
        $schedule->setStatus(Schedule::STATUS_RUNNING);
        $schedule->save();
        $this->createProcessToKillForSchedule($schedule);
        $this->scheduleManagement->kill((int)$schedule->getId(), $timestamp);
    }

    private function givenScheduleIsRunningOnHost(Schedule &$schedule, string $hostname): void
    {
        $schedule->setData('hostname', $hostname);
        $schedule->save();
    }

    private function whenEventIsDispatched($eventName)
    {
        $this->eventManager->dispatch($eventName);
    }

    private function thenScheduleHasStatus(Schedule $schedule, $expectedStatus)
    {
        $this->reloadScheduleFromDatabase($schedule);
        $this->assertEquals($expectedStatus, $schedule->getStatus(), 'Schedule should have expected status');
    }

    private function andScheduleHasMessage(Schedule $schedule, $expectedMessage)
    {
        $this->reloadScheduleFromDatabase($schedule);
        $this->assertEquals($expectedMessage, $schedule->getMessages(), 'Schedule should have expected message');
    }

    private function timeStampInThePast(): int
    {
        return $this->clock->now() - 1;
    }

    private function createProcessToKillForSchedule(Schedule $schedule): int
    {
        $pid = \pcntl_fork();
        if ($pid === -1) {
            $this->fail('Could not fork process to test killing');
            return 0;
        }

        if (!$pid) {
            // We are the child.
            // Now we fork again so that we can be attached init instead of the test (so we get reaped as expected).
            $cpid = pcntl_fork();
            if ($cpid === -1) {
                // phpcs:ignore Magento2.Security.LanguageConstruct.ExitUsage
                die('Could not fork again in child process');
            }

            if (!$cpid) {
                // We are the grandchild. It's our job to wait to be killed.
                while (true) {
                    sleep(1);
                }
            } else {
                // We are the intermediary process. It's our job to pass up the grandchild process ID.
                $schedule->setData('pid', $cpid);
                $schedule->save();

                // Kill this process forcefully to prevent any shut-down side effects when we terminate
                $this->processManagement->killPid(\getmypid(), \gethostname());

                // Reap grandchild process. We probably won't get this far.
                \pcntl_wait($status);

                // phpcs:ignore Magento2.Security.LanguageConstruct.ExitUsage
                exit(0);
            }
        }

        // We are the main process, where the test is running.

        // Reap intermediary process
        \pcntl_wait($status);

        // Ensure we got the grandchild PID out
        $this->reloadScheduleFromDatabase($schedule);
        $this->childPid = (int) $schedule->getPid();

        $this->assertGreaterThan(0, $this->childPid, 'Precondition: child process ID unknown');
        $this->assertTrue(
            $this->processManagement->isPidAlive($this->childPid),
            'Precondition: child is alive'
        );

        return $this->childPid;
    }

    private function andProcessIsKilled(Schedule $schedule)
    {
        \pcntl_wait($status); // killed children are zombies until we wait for them
        $pid = (int)$schedule->getData('pid');
        $this->assertFalse($this->processManagement->isPidAlive($pid), "Child with PID {$pid} should be killed");
    }

    private function reloadScheduleFromDatabase($schedule): void
    {
        $this->scheduleResource->load($schedule, $schedule->getId());
    }
}
