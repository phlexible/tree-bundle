<?php

/*
 * This file is part of the phlexible package.
 *
 * (c) Stephan Wentz <sw@brainbits.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Phlexible\Bundle\TreeBundle\Event;

use Phlexible\Bundle\TreeBundle\Model\TreeNodeInterface;

/**
 * Publish node event.
 *
 * @author Stephan Wentz <sw@brainbits.net>
 */
class PublishNodeEvent extends NodeEvent
{
    /**
     * @var string
     */
    private $language;

    /**
     * @var int
     */
    private $version;

    /**
     * @var bool
     */
    private $isRecursive = false;

    /**
     * @param TreeNodeInterface $node
     * @param string            $language
     * @param int               $version
     * @param bool              $isRecursive
     */
    public function __construct(TreeNodeInterface $node, $language, $version, $isRecursive = false)
    {
        parent::__construct($node);

        $this->language = $language;
        $this->version = $version;
        $this->isRecursive = (bool) $isRecursive;
    }

    /**
     * @return string
     */
    public function getLanguage()
    {
        return $this->language;
    }

    /**
     * @return int
     */
    public function getVersion()
    {
        return $this->version;
    }

    /**
     * @return bool
     */
    public function isRecursive()
    {
        return $this->isRecursive;
    }
}
