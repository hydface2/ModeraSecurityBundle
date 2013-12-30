<?php

namespace Modera\AdminGeneratorBundle\Controller;

use Modera\AdminGeneratorBundle\DataMapping\DataMapperInterface;
use Modera\AdminGeneratorBundle\EntityFactory\EntityFactoryInterface;
use Modera\AdminGeneratorBundle\ExceptionHandling\ExceptionHandlerInterface;
use Modera\AdminGeneratorBundle\Exceptions\BadRequestException;
use Modera\AdminGeneratorBundle\Hydration\HydrationService;
use Modera\AdminGeneratorBundle\Persistence\ModelManagerInterface;
use Modera\AdminGeneratorBundle\Persistence\OperationResult;
use Modera\AdminGeneratorBundle\Persistence\PersistenceHandlerInterface;
use Modera\AdminGeneratorBundle\Validation\EntityValidator;
use Modera\AdminGeneratorBundle\Validation\ValidationResult;
use Modera\FoundationBundle\Controller\AbstractBaseController;
use Neton\DirectBundle\Annotation\Remote;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @author    Sergei Lissovski <sergei.lissovski@modera.org>
 * @copyright 2013 Modera Foundation
 */
abstract class AbstractCrudController extends AbstractBaseController
{
    /**
     * @return array
     */
    abstract public function getConfig();

    /**
     * @return array
     */
    public function getPreparedConfig()
    {
        $defaultConfig = array(
            'create_entity' => function(array $params, array $config, EntityFactoryInterface $defaultFactory, ContainerInterface $container) {
                return $defaultFactory->create($params, $config);
            },
            'map_data_on_create' => function(array $params, $entity, DataMapperInterface $defaultMapper, ContainerInterface $container) {
                $defaultMapper->mapData($params, $entity);
            },
            'map_data_on_update' => function(array $params, $entity, DataMapperInterface $defaultMapper, ContainerInterface $container) {
                $defaultMapper->mapData($params, $entity);
            },
            'new_record_validator' => function(array $params, $mappedEntity, EntityValidator $defaultValidator, array $config, ContainerInterface $container) {
                return $defaultValidator->validate($mappedEntity, $config);
            },
            'updated_record_validator' => function(array $params, $mappedEntity, EntityValidator $defaultValidator, array $config, ContainerInterface $container) {
                return $defaultValidator->validate($mappedEntity, $config);
            },
            'save_entity_handler' => function($entity, array $params, PersistenceHandlerInterface $defaultHandler, ContainerInterface $container) {
                return $defaultHandler->save($entity);
            },
            'update_entity_handler' => function($entity, array $params, PersistenceHandlerInterface $defaultHandler, ContainerInterface $container) {
                return $defaultHandler->update($entity);
            },
            'exception_handler' => function(\Exception $e, $operation, ExceptionHandlerInterface $defaultHandler, ContainerInterface $container) {
                return $defaultHandler->createResponse($e, $operation);
            },
            // optional
            'ignore_standard_validator' => false,
            // optional
            'entity_validation_method' => 'validate'
        );

        $config = array_merge($defaultConfig, $this->getConfig());

        if (!isset($config['entity'])) {
            throw new \RuntimeException("'entity' configuration property is not defined.");
        }
        if (!isset($config['hydration'])) {
            throw new \RuntimeException("'hydration' configuration property is not defined.");
        }

        return $config;
    }

    /**
     * @param array $params
     *
     * @return object
     */
    private function createEntity(array $params)
    {
        $config = $this->getPreparedConfig();

        $defaultFactory = $this->getEntityFactory();

        $entity = call_user_func_array($config['create_entity'], array($params, $config, $defaultFactory, $this->container));
        if (!$entity) {
            throw new \RuntimeException("Configured factory didn't create an object.");
        }

        return $entity;
    }

    /**
     * @return PersistenceHandlerInterface
     */
    private function getPersistenceHandler()
    {
        return $this->get('modera_admin_generator.persistence.default_handler');
    }

    /**
     * @return ModelManagerInterface
     */
    private function getModelManager()
    {
        return $this->container->get('modera_admin_generator.persistence.model_manager');
    }

    /**
     * @return EntityValidator
     */
    private function getEntityValidator()
    {
        return $this->container->get('modera_admin_generator.validation.entity_validator_service');
    }

    /**
     * @return \Modera\AdminGeneratorBundle\DataMapping\DataMapperInterface
     */
    private function getDataMapper()
    {
        return $this->container->get('modera_admin_generator.data_mapping.default_data_mapper');
    }

    /**
     * @return EntityFactoryInterface
     */
    private function getEntityFactory()
    {
        return $this->container->get('modera_admin_generator.entity_factory.default_entity_factory');
    }

    /**
     * @return ExceptionHandlerInterface
     */
    private function getExceptionHandler()
    {
        return $this->container->get('');
    }

    private function checkAccess($operation)
    {

    }

    /**
     * @return HydrationService
     */
    private function getHydrator()
    {
        return $this->get('modera_admin_generator.hydration.hydration_service');
    }

    /**
     * @param object $entity
     * @param array  $params
     * @param string $defaultProfile
     *
     * @return array
     */
    private function hydrate($entity, array $params)
    {
        if (!isset($params['hydration']['profile'])) {
            $e = new BadRequestException('Hydration profile is not specified.');
            $e->setPath('/hydration/profile');
            $e->setParams($params);

            throw $e;
        }

        $profile = $params['hydration']['profile'];
        $groups = isset($params['hydration']['group']) ? $params['hydration']['group'] : null;

        $config = $this->getPreparedConfig();
        $hydrationConfig = $config['hydration'];

        return $this->getHydrator()->hydrate($entity, $hydrationConfig, $profile, $groups);
    }

    private function validateEntity(array $params, $entity)
    {
        $config = $this->getPreparedConfig();

        $validator = $config['new_record_validator'];
        if ($validator) {
            /* @var ValidationResult $validationResult */
            $validationResult = $validator($params, $entity, $this->getEntityValidator(), $config, $this->container);
            if ($validationResult->hasErrors()) {
                return array_merge($validationResult->toArray(), array(
                    'success' => false
                ));
            }
        }

        return true;
    }

    /**
     * @Remote
     */
    public function createAction(array $params)
    {
        $config = $this->getPreparedConfig();

        try {
            $this->checkAccess('create');

            if (!isset($params['record'])) {
                $e = new BadRequestException("'/record' is not provided");
                $e->setParams($params);
                $e->setPath('/record');

                throw $e;
            }

            $entity = $this->createEntity($params);

            $dataMapper = $config['map_data_on_create'];
            if ($dataMapper) {
                $dataMapper($params['record'], $entity, $this->getDataMapper(), $this->container);
            }

            if (true !== $validationResult = $this->validateEntity($params, $entity)) {
                return $validationResult;
            }

            $saveHandler = $config['save_entity_handler'];

            /* @var OperationResult $operationResult */
            $operationResult = $saveHandler($entity, $params, $this->getPersistenceHandler(), $this->container);

            $response = array(
                'success' => true
            );

            $response = array_merge($response, $operationResult->toArray($this->getModelManager()));

            if (isset($params['hydration'])) {
                $response = array_merge(
                    $response, array('result' => $this->hydrate($entity, $params))
                );
            }

            return $response;
        } catch (\Exception $e) {
            throw $e;

            $exceptionHandler = $config['exception_handler'];

            return $exceptionHandler($e, 'create', $this->getExceptionHandler(), $this->container);
        }
    }

    private function validateResultHasOneEntity(array $entities, array $params)
    {
        if (count($entities) > 1) {
            $e = new BadRequestException(sprintf(
                'Query must return exactly one result, but %d were returned', count($entities)
            ));
            $e->setPath('/filter');
            $e->setParams($params);

            throw $e;
        }
    }

    /**
     * @Remote
     */
    public function getAction(array $params)
    {
        $config = $this->getPreparedConfig();

        try {
            $entities = $this->getPersistenceHandler()->query($config['entity'], $params);

            $this->validateResultHasOneEntity($entities, $params);

            $hydratedEntity = $this->hydrate($entities[0], $params);

            return array(
                'success' => true,
                'result' => $hydratedEntity
            );
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * @Remote
     *
     * @param array $params
     */
    public function listAction(array $params)
    {
        $config = $this->getPreparedConfig();

        try {
            $total = $this->getPersistenceHandler()->getCount($config['entity'], $params);

            $hydratedItems = array();
            foreach ($this->getPersistenceHandler()->query($config['entity'], $params) as $entity) {
                $hydratedItems[] = $this->hydrate($entity, $params);
            }

            return array(
                'success' => true,
                'items' => $hydratedItems,
                'total' => $total
            );
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * @Remote
     *
     * @param array $params
     */
    public function removeAction(array $params)
    {
        $config = $this->getPreparedConfig();

        try {
            $operationResult = $this->getPersistenceHandler()->remove($config['entity'], $params);

            return array_merge(
                array('success' => true),
                $operationResult->toArray($this->getModelManager())
            );
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * @Remote
     */
    public function getNewRecordValuesAction(array $params)
    {
        return array(
            'firstname' => '?'
        );
    }

    /**
     * @Remote
     */
    public function updateAction(array $params)
    {
        $config = $this->getPreparedConfig();

        try {
            $entities = $this->getPersistenceHandler()->query($config['entity'], $params);

            $this->validateResultHasOneEntity($entities, $params);

            $entity = $entities[0];

            $dataMapper = $config['map_data_on_update'];
            $dataMapper($params['record'], $entity, $this->getDataMapper(), $this->container);



        } catch (\Exception $e) {
            throw $e;
        }
    }
}