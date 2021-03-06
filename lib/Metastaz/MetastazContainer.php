<?php

namespace Metastaz;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Metastaz\Bundle\MetastazTemplateBundle\MetastazTemplateBundle;

/**
 * MetastazContainer manage Metastaz (MetastazStore, MetastazTemplate)
 * 
 * @author:  Gabriel BONDAZ <gabriel.bondaz@idci-consulting.fr>
 * @author:  Mirsal ENNAIME <mirsal@mirsal.fr>
 * @licence: GPL
 */
class MetastazContainer
{
    /**
     * Parameters
     */
    protected $parameters = array();

    /**
     * Template
     */
    static protected $template = null;

    /**
     * Store
     */
    static protected $store = null;

    /**
     * Constructor
     *
     * @param array $parameters
     */
    public function __construct(array $parameters = array())
    {
        $configParams = MetastazTemplateBundle::getContainer()->getParameter('metastaz.parameters');
        $this->setParameters(array_merge($configParams, $parameters));
    }

    /**
     * Set parameters
     *
     * @param array $parameters
     */
    public function setParameters($parameters)
    {
        $this->parameters = $parameters;
    }

    /**
     * Get parameters
     *
     * @return parameters
     */
    public function getParameters()
    {
        return $this->parameters;
    }

    /**
     * Has parameter
     *
     * @param string $name
     * @return boolean
     */
    public function hasParameter($name)
    {
        return isset($this->parameters[$name]);
    }

    /**
     * Get parameter
     *
     * @param string $name
     * @throw Exception
     * @return mixed
     */
    public function getParameter($name)
    {
        if (!$this->hasParameter($name))
            throw new NotFoundHttpException(sprintf('Missing %s parameter', $name));
        return $this->parameters[$name];
    }

    /**
     * Is instance pooling enable
     *
     * @return boolean
     */
    public function isInstancePoolingEnabled()
    {
        if ($this->hasParameter('container'))
        {
            $container = $this->getParameter('container');
            return isset($container['instance_pooling']) && $container['instance_pooling'];
        }
        return false;
    }

    /**
     * Is templating enable
     *
     * @return boolean
     */
    public function isTemplatingEnabled()
    {
        if ($this->hasParameter('container'))
        {
            $container = $this->getParameter('container');
            return isset($container['use_template']) && $container['use_template'];
        }
        return true;
    }

    /**
     * Get Metastaz Object Dimension
     *
     * @return string
     */
    public function getMetastazDimension()
    {
        $obj = $this->getParameter('object');
        return get_class($obj).'\\'.$obj->getMetastazDimensionId();
    }

    /**
     * Get MetastazTemplate
     * If the templating is not enable, this function return a null object
     *
     * @throw NotFoundHttpException
     * @return MetastazTemplate
     */
    public function getMetastazTemplate()
    {
        if (!$this->isTemplatingEnabled())
        {
            return null;
        }

        if (!self::$template && !self::$template[$this->getMetastazDimension()])
        {
            $obj = $this->getParameter('object');
            // Retrieve MetastazTemplate by its name
            $em = MetastazTemplateBundle::getContainer()->get('doctrine')->getEntityManager('metastaz_template');
            $re = $em->getRepository('MetastazTemplateBundle:MetastazTemplate');
            $template = $re->findOneByName($obj->getMetastazTemplateName());

            if ($template) {
                self::$template[$this->getMetastazDimension()] = $template;
            }
            else {
                throw new NotFoundHttpException(
                    sprintf('Unable to find the following MetastazTemplate: %s.', $obj->getMetastazTemplateName())
                );
            }
        }

        return self::$template[$this->getMetastazDimension()];
    }

    /**
     * Get Store
     *
     * @throw NotFoundHttpException
     * @return MetastazStore
     */
    public function getMetastazStoreService()
    {
        if (!self::$store && !self::$store[$this->getMetastazDimension()])
        {
            $store = $this->getParameter('store');
            $class = $store['class'];
            $_class = 'Metastaz\\Stores\\'.$class;
            if(class_exists($_class)) {
                $store = new $_class($this);
                self::$store[$this->getMetastazDimension()] = $store;
            }
            else {
                throw new NotFoundHttpException(
                    sprintf('Unable to find the following MetastazStore: %s.', $_class)
                );
            }
        }
        
        return self::$store[$this->getMetastazDimension()];
    }

    /**
     * To get a Metastaz value for a specified Metastaz namespace and key
     *
     * @param string $namespace
     * @param string $key
     * @param string $culture
     * @return mixed
     */
    public function get($namespace, $key, $culture = null)
    {
        if ($this->isInstancePoolingEnabled()) {
            return $this->getMetastazStoreService()->getFromPool(
                $this->getMetastazDimension(),
                $namespace,
                $key,
                $culture
            );
        }

        return $this->getMetastazStoreService()->get(
            $this->getMetastazDimension(),
            $namespace,
            $key,
            $culture
        );
    }

    /**
     * To put a Metastaz value for a specified Metastaz namespace and key
     *
     * @throw NotFoundHttpException
     * @param string $namespace
     * @param string $key
     * @param string $value
     * @param string $culture
     */
    public function put($namespace, $key, $value, $culture = null)
    {
        $template = $this->getMetastazTemplate();

        if($template && !$template->hasField($namespace, $key))
        {
            throw new NotFoundHttpException(
                sprintf('The MetastazTemplate "%s" doesn\'t contain the following field {namespace: "%s", key: "%s"}.',
                    $template->getName(),
                    $namespace,
                    $key
                )
            );
        }

        if ($this->isInstancePoolingEnabled()) {
            $this->getMetastazStoreService()->putInPool(
                $this->getMetastazDimension(),
                $namespace,
                $key,
                $value,
                $culture
            );
        } else {
            $this->getMetastazStoreService()->put(
                $this->getMetastazDimension(),
                $namespace,
                $key,
                $value,
                $culture
            );
        }
    }

    /**
     * To get all Metastaz value group by namespaces for a metastazed object
     *
     * @return array
     */
    public function getAll()
    {
        if ($this->isInstancePoolingEnabled()) {
            return $this->getMetastazStoreService()->getAllFromPool(
                $this->getMetastazDimension()
            );
        }

        return $this->getMetastazStoreService()->getAll(
            $this->getMetastazDimension()
        );
    }

    /**
     * Delete a Metastaz for a specified metastaz namespace and key
     *
     * @param string $namespace
     * @param string $key
     */
    public function delete($namespace, $key)
    {
        if ($this->isInstancePoolingEnabled()) {
            return $this->getMetastazStoreService()->deleteFromPool(
                $this->getMetastazDimension(),
                $namespace,
                $key
            );
        } else {
            $this->getMetastazStoreService()->delete(
                $this->getMetastazDimension(),
                $namespace,
                $key
            );
        }
    }

    /**
     * Delete all Metastaz for a specified Metastaz dimension
     */
    public function deleteAll()
    {
        if ($this->isInstancePoolingEnabled()) {
             $this->getMetastazStoreService()->deleteAllFromPool(
                $this->getMetastazDimension()
            );
        } else {
            $this->getMetastazStoreService()->deleteAll(
                $this->getMetastazDimension()
            );
        }
    }

    /**
     * Flush Metastaz from the pool
     */
    public function flush()
    {
        $this->getMetastazStoreService()->flush(
            $this->getMetastazDimension()
        );
    }
}
