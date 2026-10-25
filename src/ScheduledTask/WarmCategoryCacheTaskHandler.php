<?php declare(strict_types=1);

namespace Ulber\FahrzeugSchnellauswahl\ScheduledTask;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Ulber\FahrzeugSchnellauswahl\Service\CategoryVehicleFilter;
use Ulber\FahrzeugSchnellauswahl\Service\VehicleSwitcherConfig;

/**
 * Rechnet je Verkaufskanal mit „Leere Kategorien im Menü ausblenden“ die Kategorien mit Produkten für jedes
 * Fahrzeug vor – so bleibt auch der erste Aufruf nach einem Fahrzeugwechsel schnell.
 */
#[AsMessageHandler(handles: WarmCategoryCacheTask::class)]
class WarmCategoryCacheTaskHandler extends ScheduledTaskHandler
{
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $logger,
        private readonly EntityRepository $salesChannelRepository,
        private readonly EntityRepository $propertyGroupOptionRepository,
        private readonly VehicleSwitcherConfig $config,
        private readonly CategoryVehicleFilter $categoryFilter
    ) {
        parent::__construct($scheduledTaskRepository, $logger);
    }

    public function run(): void
    {
        $context = Context::createDefaultContext();

        /** @var list<string> $salesChannelIds */
        $salesChannelIds = $this->salesChannelRepository->searchIds(new Criteria(), $context)->getIds();

        foreach ($salesChannelIds as $salesChannelId) {
            if (!$this->config->isActive($salesChannelId) || !$this->config->hideEmptyCategories($salesChannelId)) {
                continue;
            }

            $groupIds = $this->config->getPropertyGroupIds($salesChannelId);
            if ($groupIds === []) {
                continue;
            }

            $this->categoryFilter->warmUp($salesChannelId, null);

            $criteria = new Criteria();
            $criteria->addFilter(new EqualsAnyFilter('groupId', $groupIds));

            /** @var list<string> $optionIds */
            $optionIds = $this->propertyGroupOptionRepository->searchIds($criteria, $context)->getIds();

            foreach ($optionIds as $optionId) {
                $this->categoryFilter->warmUp($salesChannelId, $this->config->getFilterOptionIds($optionId, $salesChannelId));
            }
        }
    }
}
