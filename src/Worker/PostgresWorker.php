<?php


namespace DeltaPhp\Operator\Worker;


use DeltaDb\Adapter\PgsqlAdapter;
use DeltaUtils\Object\Collection;
use DeltaUtils\Object\Prototype\StringableInterface;
use DeltaPhp\Operator\Command\CommandInterface;
use DeltaPhp\Operator\Command\GenerateIdCommandInterface;
use DeltaPhp\Operator\Entity\EntityInterface;
use DeltaPhp\Operator\LoaderInterface;

class PostgresWorker implements WorkerInterface, ConfigurableInterface, KeeperInterface, FinderInterface, LoaderInterface, ReserveInterface, GenerateIdWorkerInterface
{
    use ConfigurableTrait;

    /** @var  PgsqlAdapter */
    protected $adapter;

    protected $table = "entities";
    /** @var array */
    protected $fields = [
        "id",
        "created",
        "changed",
        "owner",
    ];

    /**
     * @return PgsqlAdapter
     */
    public function getAdapter()
    {
        return $this->adapter;
    }

    /**
     * @param PgsqlAdapter $adapter
     */
    public function setAdapter(PgsqlAdapter $adapter)
    {
        $this->adapter = $adapter;
    }

    /**
     * @return string
     */
    public function getTable()
    {
        return $this->table;
    }

    /**
     * @param string $table
     */
    public function setTable($table)
    {
        $this->table = $table;
    }

    /**
     * @return array
     */
    public function getFields()
    {
        return $this->fields;
    }

    /**
     * @param array $fields
     */
    public function setFields(array $fields)
    {
        $this->fields = $fields;
    }

    public function addField($field)
    {
        if (!array_search($field, $this->fields)) {
            $this->fields[] = $field;
        }
    }

    public function addFields(array $fields)
    {
        foreach ($fields as $field) {
            $this->addField($field);
        }
    }

    public function execute(CommandInterface $command)
    {
        switch ($command->getName()) {
            case CommandInterface::COMMAND_FIND : {
                $criteria = $command->getParams("criteria", []);
                $limit = $command->getParams("limit", null);
                $offset = $command->getParams("offset", null);
                $order = $command->getParams("order", null);
                return $this->find($criteria, $limit, $offset, $order);
            }
            case CommandInterface::COMMAND_GET: {
                $id = $command->getParams("id");
                return $this->get($id);
            }
            case CommandInterface::COMMAND_COUNT: {
                $criteria = $command->getParams("criteria", []);
                return $this->count($criteria);
            }
            case CommandInterface::COMMAND_SAVE: {
                /** @var EntityInterface $entity */
                $entity = $command->getParams("entity");
                $isExisting = $entity->isExistingEntity();
                $data = $command->getParams("data");
                return $this->save($data, $isExisting);
            }
            case CommandInterface::COMMAND_DELETE: {
                $id = $command->getParams("id");
                return $this->delete($id);
            }
            case CommandInterface::COMMAND_LOAD: {
                return $this->load($command->getParams("entity"), $command->getParams("data"));
            }
            case CommandInterface::COMMAND_RESERVE: {
                return $this->reserve($command->getParams("entity"));
            }
            case GenerateIdCommandInterface::COMMAND_GENERATE_ID : {
                return $this->genId($command->getParams("tableId"));
            }
            default:
                throw new \InvalidArgumentException("Command type \" {$command->getName()} not supported");
        }
    }

    public function find($criteria, $limit = null, $offset = null, $orderBy = null)
    {
        $adapter = $this->getAdapter();
        $table = $this->getTable();
        $data = $adapter->selectBy($table, $criteria, $limit, $offset, $orderBy);
        $data = new Collection($data);
        return $data;
    }

    public function findOne($criteria)
    {
        $adapter = $this->getAdapter();
        $table = $this->getTable();
        $data = $adapter->selectBy($table, $criteria, 1);
        if (empty($data)) {
            return null;
        }
        return reset($data);
    }

    public function count($criteria)
    {
        $adapter = $this->getAdapter();
        $table = $this->getTable();
        return $adapter->count($table, $criteria);
    }

    public function get($id)
    {
        if (null === $id) {
            return null;
        }
        return $this->findOne(["id" => $id]);
    }

    public function save(array $data, $isExisting)
    {
        $adapter = $this->getAdapter();
        $table = $this->getTable();

        if (!$isExisting) {
            return $adapter->insert($table, $data);
        } else {
            $id = $data["id"];
            unset($data["id"]);
            return $adapter->update($table, $data, ["id" => $id]);
        }
    }

    public function delete($id)
    {
        $adapter = $this->getAdapter();
        $table = $this->getTable();
        return $adapter->delete($table, ["id" => $id]);
    }

    public function load(EntityInterface $entity, array $data)
    {
        $fields = $this->getFields();
        foreach ($fields as $field) {
            if (isset($data[$field])) {
                $method = "set" . ucfirst($field);
                if (method_exists($entity, $method)) {
                    $value = $data[$field];
                    $entity->{$method}($value);
                }
            }
        }
        return $entity;
    }

    public function reserve(EntityInterface $entity)
    {
        $fields = $this->getFields();
        $data = [];
        foreach ($fields as $field) {
            $getMethod = "get" . ucfirst($field);
            if (method_exists($entity, $getMethod) && is_callable([$entity, $getMethod])) {
                $fieldValue = $entity->$getMethod();
                $fieldValue = $this->filterFieldOnReserve($entity, $field, $fieldValue);
                $data[$field] = $fieldValue;
            } else {
                $isMethod = "is" . ucfirst($field);
                if (method_exists($entity, $isMethod) && is_callable([$entity, $isMethod])) {
                    $fieldValue = $entity->$isMethod();
                    $fieldValue = $this->filterFieldOnReserve($entity, $field, $fieldValue);
                    $data[$field] = $fieldValue;
                }
            }
        }
        return $data;
    }

    public function filterFieldOnReserve(EntityInterface $entity, $fieldName, $value)
    {
        if ($value instanceof EntityInterface) {
            $value = (string)$value->getId();
        } elseif ($value instanceof \DateTime) {
            $value = $value->format("Y-m-d H:i:s");
        } elseif (is_bool($value)) {
            $value = $value ? 't' : 'f';
        } elseif ($value instanceof StringableInterface) {
            $value = (string)$value;
        }
        return $value;
    }

    public function genId()
    {
        $tableIdRaw = $this->getConfig(WorkerInterface::PARAM_TABLEID);
        $tableId = filter_var($tableIdRaw, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1, "max_range" => 255]]);
        if (false === $tableId) {
            throw  new \InvalidArgumentException("Table id {$tableIdRaw} not in range");
        }
        $sql = "select uuid_short_complex_tables({$tableId})";
        $adapter = $this->getAdapter();
        $result = $adapter->selectCell($sql);
        return $result;
    }

}
