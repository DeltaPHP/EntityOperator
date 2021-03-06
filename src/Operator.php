<?php


namespace DeltaPhp\Operator;


use DeltaUtils\ArrayUtils;
use DeltaPhp\Operator\Command\AfterCommand;
use DeltaPhp\Operator\Command\CommandChainElementInterface;
use DeltaPhp\Operator\Command\CommandFinallyInterface;
use DeltaPhp\Operator\Command\PreAfterCommandInterface;
use DeltaPhp\Operator\Command\PreCommand;
use DeltaPhp\Operator\Worker\Exception\BreakException;
use DeltaPhp\Operator\Worker\WorkerInterface;
use DeltaPhp\Operator\Command\CommandInterface;
use Pimple\Container;
use DeltaPhp\Operator\Worker\Exception\TryNextException;

class Operator implements OperatorInterface
{
    /** @var  Container */
    protected $workers;
    protected $actionMap = [];
    protected $workersParams = [];
    protected $workerTables = [];

    /** @var  Container */
    protected $dependencies;

    /**
     * @return Container
     */
    public function getWorkers()
    {
        if (null === $this->workers) {
            $this->workers = new WorkersContainer();
           // $this->workers["operator"] = $this;
            $this->workers->setOperator($this);
        }
        return $this->workers;
    }

    public function addWorker($name, Callable $worker)
    {
        $this->getWorkers()[$name] = $worker;
    }

    /**
     * @return Container
     */
    public function getDependencies()
    {
        return $this->dependencies;
    }

    /**
     * @param Container $dependencies
     */
    public function setDependencies(Container $dependencies)
    {
        $this->dependencies = $dependencies;
    }

    public function getDependency($name)
    {
        return $this->getDependencies()[$name];
    }

    /**
     * @return array
     */
    public function getActionMap()
    {
        return $this->actionMap;
    }

    public function addAction($action, $workerName, $class = null, $order = 0)
    {
        $path = $class !== null ? [$action, $class] : [$action, ""];
        $this->actionMap = ArrayUtils::add($this->actionMap, $path, ["name" => $workerName, "order" => $order]);
    }

    public function setWorkerTable($tableId, $workerName)
    {
        $this->workerTables[$tableId] = $workerName;
    }

    public function getWorkerByTable($tableId)
    {
        if (!isset($this->workerTables[$tableId])) {
            return null;
        }
        $workerName = $this->workerTables[$tableId];
        return $this->getWorker($workerName);
    }

    public function getWorkerParams($workerName = null, $paramName = null)
    {
        if (null === $workerName) {
            return $this->workersParams;
        }
        $params = isset($this->workersParams[$workerName]) ? $this->workersParams[$workerName] : [];
        if (null === $paramName) {
            return $params;
        }
        $value = isset($params[$paramName]) ? $params[$paramName] : null;
        return $value;
    }

    public function setWorkerParams($workerName, array $workersParams)
    {
        $this->workersParams[$workerName] = $workersParams;
    }

    public function getWorker($workerName)
    {
        return $this->getWorkers()[$workerName];
    }

    /**
     * @param CommandInterface $command
     * @return WorkerInterface[]
     */
    public function getCommandWorkers(CommandInterface $command)
    {
        $class = (string)$command->getClass();
        $action = $command->getName();

        do {
            if (false === $class) {
                $class = "";
            }
            $path = [$action, $class];
            if (ArrayUtils::issetByPath($this->actionMap, $path)) {
                $workers = ArrayUtils::get($this->actionMap, $path);
                $workers = ArrayUtils::sortByKey($workers);
                //may be cache like: $this->actionMap = ArrayUtils::set($this->actionMap, $path, $workers);
                foreach ($workers as $worker) {
                    yield $this->getWorker($worker["name"]);
                }
            }
            if ("" !== $class) {
                $class = get_parent_class($class);
            }
        } while ("" !== $class);
    }

    public function preExecute(CommandInterface $command)
    {
        if (!$command instanceof PreAfterCommandInterface) {
            $preCommand = new PreCommand($command);
            $this->execute($preCommand);
            $command = $preCommand->extractParentCommand();
        }
        return  $command;
    }

    public function afterExecute(CommandInterface $command, $result)
    {
        if (!$command instanceof PreAfterCommandInterface) {
            if (!$result instanceof \SplStack) {
                $stack = new \SplStack();
                $stack->push($result);
                $result = $stack;
            }
            $afterCommand = new AfterCommand($command, $result);
            $this->execute($afterCommand);
            $result = $afterCommand->extractResult();
        }
        return  $result;
    }


    public function execute(CommandInterface $command)
    {
        //prepare action
        $command = $this->preExecute($command);

        $result = null;
        $break = !($command instanceof CommandChainElementInterface) || ($command instanceof CommandFinallyInterface);
        foreach ($this->getCommandWorkers($command) as $worker) {
            try {
                $result = $worker->execute($command);
            } catch (TryNextException $e) {
                $break = false;
            } catch (BreakException $e) {
                $break = true;
            }
            if ($break) {
                break;
            }
        }
        //after execute
        $result = $this->afterExecute($command, $result);
        return $result;
    }
}
