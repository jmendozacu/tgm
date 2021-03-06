<?php
/**
 * Mirasvit
 *
 * This source file is subject to the Mirasvit Software License, which is available at http://mirasvit.com/license/.
 * Do not edit or add to this file if you wish to upgrade the to newer versions in the future.
 * If you wish to customize this module for your needs
 * Please refer to http://www.magentocommerce.com for more information.
 *
 * @category  Mirasvit
 * @package   Advanced Product Feeds
 * @version   1.1.2
 * @revision  268
 * @copyright Copyright (C) 2014 Mirasvit (http://mirasvit.com/)
 */


class Mirasvit_FeedExport_Model_Feed_Generator extends Varien_Object
{
    protected $_feed  = null;
    protected $_state = null;

    public function init()
    {
        $this->_feed  = $this->getFeed();
    }

    public function getState()
    {
        if ($this->_state == null) {
            $this->_state = Mage::getModel('feedexport/feed_generator_state')
                ->setKey($this->_feed->getId())
                ->load();
        }

        return $this->_state;
    }

    public function process()
    {
        if ($this->getState()->isReady()) {
            $this->getState()->reset();
            $this->getState()->setStatus(Mirasvit_FeedExport_Model_Feed_Generator_State::STATUS_PROCESSING);
            $this->makeChain();

            Mage::helper('feedexport')->addToHistory($this->_feed, __('Processing'), $this->getState()->__toString());
        }

        foreach ($this->getState()->getChain() as $chainKey => $chainItem) {
            if ($chainItem['status'] == Mirasvit_FeedExport_Model_Feed_Generator_State::CHAIN_STATUS_FINISH) {
                continue;
            }

            try {
                $actionModel = $this->getActionModel($chainItem['action']);
                $actionModel->setData($chainItem)
                    ->setFeed($this->getFeed())
                    ->process();
            } catch (Exception $e) {
                Mage::dispatchEvent('feedexport_generation_fail', array('feed' => $this->_feed, 'error' => $e->getMessage()));
                $this->getState()->setError($e->getMessage())
                    ->setStatus(Mirasvit_FeedExport_Model_Feed_Generator_State::STATUS_ERROR);

                return;
            }

            Mage::helper('feedexport')->addToHistory($this->_feed, __('Processing'), $this->getState()->__toString());

            break;
        }
    }

    public function makeChain()
    {
        $this->getState()->addChainItem(array(
            'key'    => 'init',
            'action' => 'init',
            'title'  => __('Initialization'),
        ));

        if (Mage::app()->getRequest()->getParam('skip') != 'rules') {
            foreach ($this->_feed->getRuleIds() as $ruleId) {
                $rule = Mage::getModel('feedexport/rule')->load($ruleId);

                $this->getState()->addChainItem(array(
                    'key'    => 'iterator_rule_'.$ruleId,
                    'action' => 'iterator',
                    'type'   => 'rule',
                    'id'     => $ruleId,
                    'title'  => sprintf(__('Applying filter "%s"', $rule->getName())),
                ));
            }
            $this->getState()->addChainItem(array(
                'key'    => 'mergeRules',
                'action' => 'mergeRules',
                'title'  => __('Assembling products'),
            ));
        }

        $this->getState()->addChainItem(array(
            'key'    => 'iterator_product',
            'action' => 'iterator',
            'type'   => 'product',
            'title'  => __('Exporting products'),
        ))->addChainItem(array(
            'key'    => 'iterator_category',
            'action' => 'iterator',
            'type'   => 'category',
            'title'  => __('Exporting categories'),
        ))->addChainItem(array(
            'key'    => 'mergeFiles',
            'action' => 'mergeFiles',
            'title'  => __('Assembling the feed file'),
        ))->addChainItem(array(
            'key'    => 'finish',
            'action' => 'finish',
            'title'  => 'Finalization'
        ));

        return $this;
    }

    public function getActionModel($class)
    {
        return Mage::getModel('feedexport/feed_generator_action_'.$class);
    }
}