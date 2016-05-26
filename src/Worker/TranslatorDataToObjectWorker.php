<?php


namespace EntityOperator\Worker;


use DeltaUtils\Object\Collection;
use EntityOperator\Command\AfterCommand;
use EntityOperator\Command\AfterCommandInterface;
use EntityOperator\Command\CommandInterface;
use EntityOperator\Command\CreateCommand;
use EntityOperator\Command\EntityOperatedCommandInterface;
use EntityOperator\Command\LoadCommand;
use EntityOperator\Worker\Exception\NotSupportedCommand;

class TranslatorDataToObjectWorker  implements WorkerInterface, DelegatingWorkerInterface
{
    const COMMAND_AFTER_FIND = AfterCommandInterface::PREFIX_COMMAND_AFTER . CommandInterface::COMMAND_FIND;

    use DelegatingWorkerTrait;

    public function execute(CommandInterface $command)
    {
        switch ($command->getName()) {
            case self::COMMAND_AFTER_FIND :
                return $this->translate($command);
                break;
            default:
                throw new NotSupportedCommand($command);
        }
    }

    public function translate(AfterCommandInterface $command)
    {
        $result = $command->extractResult();
        $class = $command->hasClass() ? $command->getClass() : EntityOperatedCommandInterface::DEFAULT_CLASS;
        if ($result instanceof Collection) {
            $items = clone $result;
            $items->map(function ($itemData) use ($class)  {
                $entity = $this->toEntity($itemData, $class);
                return $entity;
            });
            return $items;
        }
        return $this->toEntity($result, $class);
    }

    public function toEntity(array $entityData, $entityClass)
    {
        $createCommand = new CreateCommand($entityClass);
        $entity = $this->getOperator()->execute($createCommand);

        $loadCommand = new LoadCommand($entity, $entityData);
        $entity = $this->getOperator()->execute($loadCommand);
        return $entity;
    }
}