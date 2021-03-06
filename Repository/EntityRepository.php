<?php

/*
 * This file is part of the Atechnologies package.
 * 
 * (c) www.atechnologies.com.ve
 * 
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Atechnologies\PaginatorBundle\Repository;

use Doctrine\ORM\QueryBuilder;
use Pagerfanta\Adapter\DoctrineORMAdapter;
use Atechnologies\PaginatorBundle\Model\Paginator;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Security\Core\SecurityContext;
use Pagerfanta\Adapter\ArrayAdapter;
use Doctrine\ORM\EntityRepository as Base;

/**
 * Doctrine ORM driver entity repository.
 *
 * @author Paweł Jędrzejewski <pjedrzejewski@diweb.pl>
 */
class EntityRepository extends Base implements ContainerAwareInterface
{
    /**
     * @var SecurityContext
     */
    protected $securityContext;
    
    protected $container;
    /**
     * 
     * @param QueryBuilder $queryBuilder
     * @return Paginator
     */
    public function getPaginator(QueryBuilder $queryBuilder)
    {   
        $this->preSetContainer();

        $request = $this->container->get('request_stack')->getCurrentRequest();
        if($request){
            $columns = $request->get("columns");
            if($this->getFormatPaginator() == Paginator::FORMAT_ARRAY_DATA_TABLES && $columns !== null){
                $orx = $queryBuilder->expr()->andX();
                foreach ($columns as $column) {
                    $data = $column['name'];
                    $value = $column['search']['value'];
                    if($data != "" && $value != ""){
                        $field = sprintf("%s.%s",  $this->getAlies(),$data);
                        $orx->add($queryBuilder->expr()->like($field, $queryBuilder->expr()->literal("%".$value."%")));
                    }

                }
                if($orx->count() > 0){
                    $queryBuilder->andWhere($orx);
                }
            }
        }
        
        $pagerfanta = new Paginator(new DoctrineORMAdapter($queryBuilder));
        $pagerfanta->setDefaultFormat($this->getFormatPaginator());
        $pagerfanta->setContainer($this->container);
        if($request){
            $pagerfanta->setRequest($request);
        }
        return $pagerfanta;
    }

    /**
     * Retorna un paginador con valores escalares (Sin hidratar)
     * @param \Doctrine\ORM\QueryBuilder $queryBuilder
     * @return \
     */
    public function getPaginatorScalar(\Doctrine\ORM\QueryBuilder $queryBuilder)
    {
        $this->preSetContainer();

        $request = $this->container->get('request_stack')->getCurrentRequest();        
        $pagerfanta = new Paginator(new ArrayAdapter($queryBuilder->getQuery()->getScalarResult()));
        $pagerfanta->setDefaultFormat($this->getFormatPaginator());
        $pagerfanta->setContainer($this->container);
        $pagerfanta->setRequest($request);
        return $pagerfanta;
    }
    
    /**
     * @author Máximo Sojo maxsojo13@gmail.com <maxtoan at atechnologies>
     * @return [type]
     */
    public function findAllPaginated()
    {
        return $this->getPaginator($this->getQueryBuilder());
    }
    
    /**
     * @author Máximo Sojo maxsojo13@gmail.com <maxtoan at atechnologies>
     * @param  SecurityContext
     */
    public function setSecurityContext(SecurityContext $securityContext) {
        $this->securityContext = $securityContext;
    }
    
    /**
     * @author Máximo Sojo maxsojo13@gmail.com <maxtoan at atechnologies>
     * @return [type]
     */
    public function preSetContainer()
    {
        global $kernel;
        if ($kernel instanceOf \AppCache) {
            $kernel = $kernel->getKernel();
        }
        $container = $kernel->getContainer();
        $this->setContainer($container);
    }
    /**
     * Set container
     * @author Máximo Sojo maxsojo13@gmail.com <maxtoan at atechnologies>
     * @param  ContainerInterface|null
     */
    public function setContainer(ContainerInterface $container = null) {
        $this->container = $container;
    }
    
    /**
     * Get a user from the Security Context
     *
     * @return mixed
     *
     * @throws \LogicException If SecurityBundle is not available
     *
     * @see Symfony\Component\Security\Core\Authentication\Token\TokenInterface::getUser()
     */
    public function getUser()
    {
        if (!$this->container->has('security.context')) {
            throw new \LogicException('The SecurityBundle is not registered in your application.');
        }

        if (null === $token = $this->container->get('security.context')->getToken()) {
            return null;
        }

        if (!is_object($user = $token->getUser())) {
            return null;
        }

        return $user;
    }
    
    public function getSecurityContext()
    {
        if (!$this->container->has('security.context')) {
            throw new \LogicException('The SecurityBundle is not registered in your application.');
        }

        return $this->container->get('security.context');
    }
    
    /**
     * Carga de formato de respuesta
     * @author Máximo Sojo maxsojo13@gmail.com <maxtoan at atechnologies>
     * @return [type]
     */
    public function getFormatPaginator()
    {
        return $this->container->getParameter('format_array');
    }
    
    /**
     * @param type $qb
     * @param type $criteria
     * @return \
     */
    protected function createSearchQueryBuilder($qb, $criteria,array $orderBy = []) 
    {
        return new Query\SearchQueryBuilder($qb, $criteria, $this->getAlies(),$orderBy);
    }
    
    /**
     * @param QueryBuilder $queryBuilder
     *
     * @param array $criteria
     */
    protected function applyCriteria(QueryBuilder $queryBuilder, array $criteria = null)
    {
        if (null === $criteria) {
            return;
        }

        foreach ($criteria as $property => $value) {
            if (null === $value) {
                $queryBuilder
                    ->andWhere($queryBuilder->expr()->isNull($this->getPropertyName($property)));
            } elseif (!is_array($value)) {
                $queryBuilder
                    ->andWhere($queryBuilder->expr()->eq($this->getPropertyName($property), ':' . $property))
                    ->setParameter($property, $value);
            } else {
                $queryBuilder->andWhere($queryBuilder->expr()->in($this->getPropertyName($property), $value));
            }
        }
    }
    
    /**
     * 
     * @param array $criteria
     * @return \Doctrine\Common\Collections\ArrayCollection
     */
    protected function parseCriteria(array $criteria) {
        return new \Doctrine\Common\Collections\ArrayCollection($criteria);
    }
    
    /**
     * @param QueryBuilder $queryBuilder
     *
     * @param array $sorting
     */
    protected function applySorting(QueryBuilder $queryBuilder, array $sorting = null)
    {
        if (null === $sorting) {
            return;
        }

        foreach ($sorting as $property => $order) {
            if (!empty($order)) {
                $queryBuilder->orderBy($this->getPropertyName($property), $order);
            }
        }
    }
    
    /**
     * @param string $name
     *
     * @return string
     */
    protected function getPropertyName($name)
    {
        if (false === strpos($name, '.')) {
            return $this->getAlies().'.'.$name;
        }

        return $name;
    }
    
    /**
     * @return QueryBuilder
     */
    protected function getQueryBuilder()
    {
        return $this->createQueryBuilder($this->getAlies());
    }
    
    public function getAlies()
    {
        return "e";
    }
}
