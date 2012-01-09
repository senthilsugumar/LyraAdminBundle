<?php

/*
 * This file is part of the LyraAdminBundle package.
 *
 * Copyright 2011 Massimo Giagnoni <gimassimo@gmail.com>
 *
 * This source file is subject to the MIT license. Full copyright and license
 * information are in the LICENSE file distributed with this source code.
 */

namespace Lyra\AdminBundle\Controller;

use Symfony\Component\DependencyInjection\ContainerAware;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;


/**
 * Base controller to manage CRUD actions.
 */
class AdminController extends ContainerAware
{
    /**
     * Displays a list of objects.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function indexAction()
    {
        $config = $this->getConfiguration();

        $listRenderer = $this->getListRenderer();
        $sort = $this->getSort();
        $listRenderer->setSort($sort);

        $pager = $this->getPager();
        $pager->setMaxRows($config->getListOption('max_page_rows'));
        $pager->setPage($this->getCurrentPage());
        $pager->setQueryBuilder(
            $this->getModelManager()->buildQuery(
                $this->getFilterCriteria(),
                $sort
            )
        );

        return $this->container->get('templating')
            ->renderResponse($listRenderer->getTemplate(), array(
                'renderer' => $listRenderer,
                'filter' => $this->getFilterRenderer(),
                'pager' => $pager,
                'csrf' => $this->container->get('form.csrf_provider')->generateCsrfToken('list')
            ));
    }

    /**
     * Creates a new object.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function newAction()
    {
        $renderer = $this->getFormRenderer();
        $this->checkSecurity($renderer, 'new');
        $object = $this->getModelManager()->create();
        $form = $renderer->getForm($object);

        $request = $this->getRequest();
        if ('POST' == $request->getMethod()) {
            $form->bindRequest($request);
            if ($form->isValid() && $this->getModelManager()->save($object)) {
                return $this->getRedirectToListResponse();
            }
        }

        return $this->getRenderFormResponse($form);
    }

    /**
     * Edits an object.
     *
     * @param mixed $id object primary key
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function editAction($id)
    {
        $renderer = $this->getFormRenderer();
        $this->checkSecurity($renderer, 'edit');
        $object = $this->getModelManager()->find($id);
        $form = $renderer->getForm($object);

        $request = $this->getRequest();
        if ('POST' == $request->getMethod()) {
            $form->bindRequest($request);
            if ($form->isValid() && $this->getModelManager()->save($object)) {
                return $this->getRedirectToListResponse();
            }
        }

        return $this->getRenderFormResponse($form);
    }

    /**
     * Deletes an object.
     *
     * @param mixed $id object primary key
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function deleteAction($id)
    {
        $this->checkSecurity($this->getListRenderer(), 'delete');
        $object = $this->getModelManager()->find($id);
        $request = $this->getRequest();

        if ('POST' === $request->getMethod()) {
            if ($this->container->get('form.csrf_provider')->isCsrfTokenValid('delete', $request->get('_token'))) {
                $this->getModelManager()->remove($object);
                $this->setFlash('lyra_admin success', 'flash.delete.success');
            }

            return $this->getRedirectToListResponse();
        }

        $renderer = $this->getDialogRenderer();
        $renderer->setRouteParams(array('id' => $object->getId()));

        return $this->container->get('templating')
            ->renderResponse('LyraAdminBundle:Admin:delete.html.twig', array(
                'object' => $object,
                'csrf' => $this->container->get('form.csrf_provider')->generateCsrfToken('delete'),
                'renderer' => $renderer
            ));
    }

    /**
     * Action triggered by boolean switches and other list buttons.
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function objectAction()
    {
        $reqAction = $this->getRequest()->get('action');
        $action = key($reqAction);
        if(is_array($reqAction[$action])) {
            $id = key($reqAction[$action]);
        }
        $response = $colName = null;

        if ('batch' == $action) {
            $action = null;
            if (null === $id = $this->getRequest()->get('ids')) {
                // TODO setflash
            } else if ($action =  $this->getRequest()->get('batch_action')) {
                $this->checkSecurity($this->getListRenderer(), $action);
                $action =  'Batch'.$action;
            }
        } else if (false !== strpos($action, '_boolean')) {
            $parts = explode('_', $action);
            if (count($parts) > 2 && in_array($parts[2], array('on','off'))) {
                $action = 'Boolean';
                $colName = $parts[3];
                $colValue = 'on' === $parts[2];
            }
        }

        if ($action) {
            $method = 'execute'.$action;
            $response = $colName ? $this->$method($id, $colName, $colValue) : $this->$method($id);
        }

        if (null !== $response) {
            return $response;
        }

        return $this->getRedirectToListResponse();
    }

    public function filterAction($reset)
    {
        $request = $this->getRequest();
        if ($reset)  {
            $this->container->get('session')->set($this->getModelName().'.criteria', array());
        } else if ('POST' == $request->getMethod()) {
            $form = $this->getFilterRenderer()->getForm();
            $form->bindRequest($request);
            $this->container->get('session')->set($this->getModelName().'.criteria', $form->getData());
        }

        return $this->getRedirectToListResponse();
    }

    public function navigationAction()
    {
        $menu = $this->container->getParameter('lyra_admin.menu');

        return $this->container->get('templating')
            ->renderResponse('LyraAdminBundle:Admin:navigation.html.twig', array(
                'menu' => $menu
            ));
    }

    /**
     * Gets the Request service.
     *
     * @return \Symfony\Component\HttpFoundation\Request
     */
    public function getRequest()
    {
        return $this->container->get('request');
    }

    public function getSession()
    {
        return $this->container->get('session');
    }

    public function getPager()
    {
        return $this->container->get('lyra_admin.pager');
    }

    public function getConfiguration($name = null)
    {
        return $this->container->get(sprintf('lyra_admin.%s.configuration', $name ?: $this->getModelName()));
    }

    /**
     * Gets a list renderer service.
     *
     * @param string $name model name
     *
     * @return \Lyra\AdminBundle\Renderer\ListRenderer
     */
    public function getListRenderer($name = null)
    {
        return $this->container->get(sprintf('lyra_admin.%s.list_renderer', $name ?: $this->getModelName()));
    }

    /**
     * Gets a form renderer service.
     *
     * @param string $name model name
     *
     * @return \Lyra\AdminBundle\Renderer\FormRenderer
     */
    public function getFormRenderer($name = null)
    {
        $renderer = $this->container->get(sprintf('lyra_admin.%s.form_renderer', $name ?: $this->getModelName()));
        $renderer->setAction($this->getRequest()->get('lyra_admin_action'));

        return $renderer;
    }

    /**
     * Gets a dialog renderer service.
     *
     * @param string $name model name
     *
     * @return \Lyra\AdminBundle\Renderer\DialogRenderer
     */
    public function getDialogRenderer($name = null)
    {
        $renderer = $this->container->get(sprintf('lyra_admin.%s.dialog_renderer', $name ?: $this->getModelName()));
        $renderer->setAction($this->getRequest()->get('lyra_admin_action'));

        return $renderer;
    }

    /**
     * Gets a filter renderer service.
     *
     * @param string $name model name
     *
     * @return \Lyra\AdminBundle\Renderer\FilterRenderer
     */
    public function getFilterRenderer($name = null)
    {
        return $this->container->get(sprintf('lyra_admin.%s.filter_renderer', $name ?: $this->getModelName()));
    }

    /**
     * Gets a model manager service.
     *
     * @param string $name model name
     *
     * @return \Lyra\AdminBundle\Model\ModelManager
     */
    public function getModelManager($name = null)
    {
        return $this->container->get(sprintf('lyra_admin.%s.model_manager', $name ?: $this->getModelName()));
    }

    /**
     * Returns the response to redirect to the list of objects.
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    protected function getRedirectToListResponse()
    {
        $renderer = $this->getListrenderer();

        return new RedirectResponse(
            $this->container->get('router')->generate($renderer->getRoutePrefix().'_index')
        );
    }

    /**
     * Returns the response to render the form.
     *
     * @param \Symfony\Component\Form\Form $form
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function getRenderFormResponse($form)
    {
        $object = $form->getData();
        $renderer = $this->getFormRenderer();

        if ('edit' == $renderer->getAction()) {
            $renderer->setRouteParams(array('id' => $object->getId()));
        }

        return $this->container->get('templating')
            ->renderResponse($renderer->getTemplate(), array(
                'renderer' => $renderer,
            ));
    }

    protected function setFlash($action, $value)
    {
        $this->container->get('session')->setFlash($action, $value);
    }

    protected function executeBatchDelete($ids)
    {
        if ($this->getRequest()->get('batch_confirm')) {
            if ($this->container->get('form.csrf_provider')->isCsrfTokenValid('batch_delete', $this->getRequest()->get('_token'))) {
                $this->setFlash('lyra_admin success', 'flash.batch_delete.success');
                $this->getModelManager()->removeByIds($ids);
            }

            return $this->getRedirectToListResponse();
        }

        $renderer = $this->getDialogRenderer();
        $renderer->setAction('delete');

        return $this->container->get('templating')
            ->renderResponse('LyraAdminBundle:Admin:batch_dialog.html.twig', array(
                'ids' => $ids,
                'renderer' => $renderer,
                'csrf' => $this->container->get('form.csrf_provider')->generateCsrfToken('batch_delete'),
            ));
    }

    protected function executeBoolean($id, $colName, $colValue)
    {
        if ($this->getListRenderer()->hasBooleanActions($colName)) {
            if ($this->container->get('form.csrf_provider')->isCsrfTokenValid('list', $this->getRequest()->get('_token'))) {
                $object = $this->getModelManager()->find($id);
                $method = 'set'.ucfirst($colName);
                $object->$method($colValue);
                $this->getModelManager()->save($object);
            }
        }
    }

    protected function getFilterCriteria()
    {
        return $this->container->get('session')->get($this->getModelName().'.criteria', array());
    }

    protected function getSort()
    {
        $config = $this->getConfiguration();
        $default = $config->getListOption('default_sort');

        if ($column = $this->getRequest()->get('column')) {
            $this->getSession()->set($this->getModelName().'.sort.column', $column);
            $this->getSession()->set($this->getModelName().'.sort.order', $this->getRequest()->get('order'));
        }

        $sort = array('column' => $this->getSession()->get($this->getModelName().'.sort.column', $default['column']), 'order' => $this->getSession()->get($this->getModelName().'.sort.order', $default['order']));

        if (null !== $sort['column']) {
            $sort['field'] = $config->getListColumnOption($sort['column'], 'field');
        } else {
            $sort['field'] = $default['field'];
        }

        return $sort;
    }

    protected function getCurrentPage()
    {
        if ($page = $this->getRequest()->get('page')) {
            $this->getSession()->set($this->getModelName().'.page', $page);
        }

        return $this->getSession()->get($this->getModelName().'.page', 1);
    }

    protected function getModelName()
    {
        if (null === $name = $this->getRequest()->get('lyra_admin_model')) {
           throw new \InvalidArgumentException('Unspecified model name, lyra_admin_model parameter not present in Request');
        }

        return $name;
    }

    protected function checkSecurity($renderer, $action)
    {
        if (!$renderer->isActionAllowed($action)) {
            throw new AccessDeniedException();
        }
    }
}
