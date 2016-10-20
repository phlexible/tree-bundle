<?php

/*
 * This file is part of the phlexible package.
 *
 * (c) Stephan Wentz <sw@brainbits.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Phlexible\Bundle\TreeBundle\Controller;

use Doctrine\DBAL\Connection;
use Phlexible\Bundle\TreeBundle\Model\TreeNodeInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Webmozart\Assert\Assert;

/**
 * Link controller
 *
 * @author Stephan Wentz <sw@brainbits.net>
 * @author Marcus Stöhr <mstoehr@brainbits.net>
 * @author Phillip Look <pl@brainbits.net>
 * @Route("/tree/link")
 */
class LinkController extends Controller
{
    const MODE_NOET_NOTARGET = 1;
    const MODE_NOET_TARGET = 2;
    const MODE_ET_NOTARGET = 3;
    const MODE_ET_TARGET = 4;

    /**
     * Return the Element data tree
     *
     * @param Request $request
     *
     * @return JsonResponse
     * @Route("", name="tree_link")
     */
    public function linkAction(Request $request)
    {
        $currentSiterootId = $request->get('siteroot_id');
        $id = $request->get('node', 'root');
        $language = $request->get('language');
        $recursive = (bool) $request->get('recursive');

        $treeManager = $this->get('phlexible_tree.tree_manager');
        $elementService = $this->get('phlexible_element.element_service');
        $iconResolver = $this->get('phlexible_element.icon_resolver');

        if (null === $language) {
            if ($id != 'root') {
                $tree = $treeManager->getByNodeID($id);
                $node = $tree->get($id);
            } else {
                $tree = $treeManager->getBySiteRootId($currentSiterootId);
                $node = $tree->getRoot();
            }
            $element = $elementService->findElement($node->getTypeId());
            $language = $element->getMasterLanguage();
        }

        if ($id === 'root') {
            $siterootManager = $this->get('phlexible_siteroot.siteroot_manager');
            $siteroots = $siterootManager->findAll();

            // move current siteroot to the beginning
            if ($currentSiterootId !== null) {
                foreach ($siteroots as $index => $siteroot) {
                    if ($siteroot->getId() === $currentSiterootId) {
                        array_unshift($siteroots, $siteroots[$index]);
                        unset($siteroots[$index]);
                    }
                }
            }

            $data = [];
            foreach ($siteroots as $siteroot) {
                $siterootId = $siteroot->getId();
                $tree = $treeManager->getBySiteRootID($siterootId);
                $rootNode = $tree->getRoot();

                $element = $elementService->findElement($rootNode->getTypeId());

                $data[] = [
                    'id'       => $rootNode->getId(),
                    'eid'      => (int) $rootNode->getTypeId(),
                    'text'     => $siteroot->getTitle(),
                    'icon'     => $iconResolver->resolveTreeNode($rootNode, $language),
                    // 'cls'      => 'siteroot-node',
                    // 'children' => $startNode->hasChildren() ? $this->_recurseNodes($startNode->getChildren(), $language) : array(),
                    'leaf'     => !$tree->hasChildren($rootNode),
                    'expanded' => $siterootId === $currentSiterootId,
                ];
            }
        } else {
            $tree = $treeManager->getByNodeID($id);
            $node = $tree->get($id);
            $nodes = $tree->getChildren($node);
            $data = $this->recurseLinkNodes($nodes, $language, $recursive);
        }

        return new JsonResponse($data);
    }

    /**
     * Return the Element data tree
     *
     * @param Request $request
     *
     * @return JsonResponse
     * @Route("/internal", name="tree_link_internal")
     */
    public function linkInternalAction(Request $request)
    {
        $siterootIds = explode(',', $request->get('siteroot_id'));
        $siterootId = count($siterootIds) ? $siterootIds[0] : null;
        $id = $request->get('node', 'root');
        $language = $request->get('language');
        $targetTid = $request->get('value');
        $elementtypeIds = $request->get('element_type_ids');

        if ($elementtypeIds) {
            $elementtypeIds = explode(',', $elementtypeIds);
        } else {
            $elementtypeIds = [];
        }

        $treeManager = $this->get('phlexible_tree.tree_manager');
        $elementService = $this->get('phlexible_element.element_service');

        if (!$language) {
            if ($id != 'root') {
                $tree = $treeManager->getByNodeId($id);
                $node = $tree->get($id);
            } else {
                if (!$siterootId) {
                    return new JsonResponse();
                }
                $tree = $treeManager->getBySiteRootId($siterootId);
                $node = $tree->getRoot();
            }

            $element = $elementService->findElement($node->getTypeId());
            $language = $element->getMasterLanguage();
        }

        $tree = $treeManager->getBySiteRootID($siterootId);
        if ($id === 'root') {
            $startNode = $tree->getRoot();
        } else {
            $startNode = $tree->get($id);
        }

        $targetNode = null;
        if ($targetTid) {
            $targetTree = $treeManager->getByNodeId($targetTid);
            $targetNode = $targetTree->get($targetTid);
        }

        if (!count($elementtypeIds)) {
            $mode = !$targetTid ? self::MODE_NOET_NOTARGET : self::MODE_NOET_TARGET;

            if ($id === 'root') {
                $nodes = [$startNode];
            } else {
                $nodes = $tree->getChildren($startNode);
            }
            $data = $this->recurseLinkNodes($nodes, $language, $mode, $targetNode);
        } else {
            $mode = !$targetTid ? self::MODE_ET_NOTARGET : self::MODE_ET_TARGET;

            $data = $this->findLinkNodes($startNode->getTree()->getSiterootId(), $language, $elementtypeIds);

            if ($elementtypeIds) {
                $data = $this->recursiveTreeStrip($data);
            }
        }

        return new JsonResponse($data);
    }

    /**
     * @param Request $request
     *
     * @return JsonResponse
     * @Route("/intrasiteroot", name="tree_link_intrasiteroot")
     *
     * @throws \InvalidArgumentException if empty siteroot id given
     */
    public function linkIntrasiterootAction(Request $request)
    {
        $siterootIds = explode(',', $request->get('siteroot_id'));
        $id = $request->get('node', 'root');
        $language = $request->get('language');
        $elementtypeIds = $request->get('element_type_ids', []);
        $targetTid = $request->get('value');

        $treeManager = $this->get('phlexible_tree.tree_manager');
        $iconResolver = $this->get('phlexible_element.icon_resolver');

        // TODO: switch to master language of element
        $defaultLanguage = $this->container->getParameter('phlexible_cms.languages.default');

        if (!$language) {
            $language = $defaultLanguage;
        }

        if ($elementtypeIds) {
            $elementtypeIds = explode(',', $elementtypeIds);
        } else {
            $elementtypeIds = [];
        }

        $targetTree = null;
        $targetNode = null;
        if ($targetTid) {
            $targetTree = $treeManager->getByNodeId($targetTid);
            $targetNode = $targetTree->get($targetTid);
        }

        if ($id == 'root') {
            $siterootManager = $this->get('phlexible_siteroot.siteroot_manager');
            if (!$siterootIds) {
                $siterootIds = array();
                foreach ($siterootManager->findAll() as $siteroot) {
                    $siterootIds[] = $siteroot->getId();
                }
            }
            $data = [];
            foreach ($siterootIds as $siterootId) {
                Assert::notEmpty($siterootId, 'Empty siteroot id given');
                $siteroot = $siterootManager->find($siterootId);
                $tree = $treeManager->getBySiteRootId($siteroot->getId());
                $rootNode = $tree->getRoot();

                $children = false;
                if ($targetTree && $siteroot->getId() === $targetTree->getSiterootId()) {
                    if (!count($elementtypeIds)) {
                        $mode = !$targetTid ? self::MODE_NOET_NOTARGET : self::MODE_NOET_TARGET;
                        $nodes = $tree->getChildren($rootNode);
                        $children = $this->recurseLinkNodes($nodes, $language, $mode, $targetNode);
                    } else {
                        $children = $this->findLinkNodes($tree->getSiterootId(), $language, $elementtypeIds);

                        if ($elementtypeIds) {
                            $children = $this->recursiveTreeStrip($children);
                        }
                    }
                }

                $data[] = [
                    'id'       => $rootNode->getId(),
                    'eid'      => $rootNode->getTypeId(),
                    'text'     => $siteroot->getTitle(),
                    'icon'     => $iconResolver->resolveTreeNode($rootNode, $language),
                    'children' => $children,
                    'leaf'     => !$tree->hasChildren($rootNode),
                    'expanded' => false,
                ];
            }
        } else {
            $tree = $treeManager->getByNodeId($id);
            $startNode = $tree->get($id);

            if (!count($elementtypeIds)) {
                $mode = !$targetTid ? self::MODE_NOET_NOTARGET : self::MODE_NOET_TARGET;

                $nodes = $tree->getChildren($startNode);
                $data = $this->recurseLinkNodes($nodes, $language, $mode, $targetNode);
            } else {
                $data = $this->findLinkNodes($tree->getSiterootId(), $language, $elementtypeIds);

                if ($elementtypeIds) {
                    $data = $this->recursiveTreeStrip($data);
                }
            }
        }

        return new JsonResponse($data);
    }

    /**
     * @param string $siteRootId
     * @param string $language
     * @param array  $elementtypeIds
     *
     * @return array
     */
    private function findLinkNodes($siteRootId, $language, array $elementtypeIds)
    {
        $treeManager = $this->get('phlexible_tree.tree_manager');
        $elementService = $this->get('phlexible_element.element_service');

        $iconResolver = $this->get('phlexible_element.icon_resolver');
        $db = $this->get('doctrine.dbal.default_connection');

        $qb = $db->createQueryBuilder();

        $qbElementtypeIds = array_map(function($id) use ($qb) {
            return $qb->expr()->literal($id);
        }, $elementtypeIds);

        $qb
            ->select('t.id')
            ->from('tree', 't')
            ->join('t', 'element', 'e', 't.type_id = e.eid')
            ->where($qb->expr()->eq('t.siteroot_id', $qb->expr()->literal($siteRootId)))
            ->andWhere($qb->expr()->in('e.elementtype_id', $qbElementtypeIds))
            ->orderBy('t.sort', 'ASC');

        $treeIds = array_column($db->fetchAll($qb->getSQL()), 'id');

        $data = [];

        $rootTreeId = null;

        foreach ($treeIds as $treeId) {
            $tree = $treeManager->getByNodeId($treeId);
            $node = $tree->get($treeId);

            $element = $elementService->findelement($node->getTypeId());
            $elementVersion = $elementService->findLatestElementVersion($element);

            if (!isset($data[$treeId])) {
                $data[$node->getId()] = [
                    'id'       => $node->getId(),
                    'eid'      => $node->getTypeId(),
                    'text'     => $elementVersion->getBackendTitle($language, $element->getMasterLanguage()) . ' [' . $node->getId() . ']',
                    'icon'     => $iconResolver->resolveElement($element),
                    'children' => [],
                    'leaf'     => true,
                    'expanded' => false,
                    'disabled' => !in_array($element->getElementtypeId(), $elementtypeIds),
                ];
            }

            do {
                $parentNode = $tree->getParent($node);

                if (!$parentNode) {
                    $rootTreeId = $node->getId();
                    break;
                }

                if (!isset($data[$parentNode->getId()])) {
                    $element = $elementService->findElement($parentNode->getTypeId());
                    $elementVersion = $elementService->findLatestElementVersion($element);

                    $data[$parentNode->getId()] = [
                        'id'       => $parentNode->getId(),
                        'eid'      => $parentNode->getTypeId(),
                        'text'     => $elementVersion->getBackendTitle($language, $element->getMasterLanguage()) . ' [' . $parentNode->getId() . ']',
                        'icon'     => $iconResolver->resolveTreeNode($parentNode, $language),
                        'children' => [],
                        'leaf'     => false,
                        'expanded' => false,
                        'disabled' => !in_array($element->getElementtypeId(), $elementtypeIds),
                    ];
                } else {
                    $data[$parentNode->getId()]['leaf'] = false;
                }

                $data[$parentNode->getId()]['children'][$node->getId()] =& $data[$node->getId()];

                $node = $parentNode;
            } while ($parentNode);
        }

        if (!count($data)) {
            return [];
        }

        $data = $this->stripLinkNodeKeys($data[$rootTreeId], $db);

        return $data['children'];
    }

    /**
     * @param array      $data
     * @param Connection $connection
     *
     * @return array
     */
    private function stripLinkNodeKeys($data, Connection $connection)
    {
        if (is_array($data['children']) && count($data['children'])) {
            /*
            $qb = $connection->createQueryBuilder();
            $qb
                ->select(array('id', 'sort'))
                ->from('tree', 't')
                ->where($qb->expr()->eq('t.parent_id', $data['id']))
                ->where($qb->expr()->in('t.id',array_keys($data['children'])))
                ->orderBy('t.sort');

            $sortTids = $connection->fetchAll($qb->getSQL());
            $sortedTids = [];
            foreach (array_keys($data['children']) as $tid) {
                $sortedTids[$tid] = $sortTids[$tid];
            }

            array_multisort($sortedTids, $data['children']);
            */

            $data['children'] = array_values($data['children']);

            foreach ($data['children'] as $key => $item) {
                $data['children'][$key] = $this->stripLinkNodeKeys($item, $connection);
            }
        }

        return $data;
    }

    /**
     * Recurse over tree nodes
     *
     * @param array             $nodes
     * @param string            $language
     * @param int               $mode
     * @param TreeNodeInterface $targetNode
     *
     * @return array
     */
    private function recurseLinkNodes(array $nodes, $language, $mode, TreeNodeInterface $targetNode = null)
    {
        $elementService = $this->get('phlexible_element.element_service');
        $iconResolver = $this->get('phlexible_element.icon_resolver');

        $data = [];

        foreach ($nodes as $node) {
            /* @var $node \Phlexible\Bundle\TreeBundle\Model\TreeNodeInterface */

            $element = $elementService->findElement($node->getTypeId());
            $elementVersion = $elementService->findLatestElementVersion($element);
            $elementtype = $elementService->findElementtype($element);

            $tid = $node->getId();
            $tree = $node->getTree();
            $children = $tree->getChildren($node);

            $dataNode = [
                'id'       => $node->getId(),
                'eid'      => $node->getTypeId(),
                'text'     => $elementVersion->getBackendTitle($language, $element->getMasterLanguage()) . ' [' . $tid . ']',
                'icon'     => $iconResolver->resolveTreeNode($node, $language),
                'children' => !$tree->hasChildren($node)
                    ? []
                    : $mode == self::MODE_NOET_TARGET && $tree->isParentOf($node, $targetNode)
                        ? $this->recurseLinkNodes($children, $language, $mode, $targetNode)
                        : false,
                'leaf'     => !$tree->hasChildren($node),
                'expanded' => false,
            ];

            /*
            $leafCount = 0;
            if (is_array($dataNode['children']))
            {
                foreach($dataNode['children'] as $child)
                {
                    $leafCount += $child['leafCount'];
                    if (!isset($child['disabled']) || !$child['disabled'])
                    {
                        ++$leafCount;
                    }
                }
            }
            $dataNode['leafCount'] = $leafCount;
            */

            $data[] = $dataNode;
        }

        return $data;
    }

    /**
     * Strip all disabled nodes recursivly
     *
     * @param array $data
     *
     * @return array
     */
    private function recursiveTreeStrip(array $data)
    {
        if (count($data) === 1 && !empty($data[0]['children'])) {
            return $this->recursiveTreeStrip($data[0]['children']);
        }

        return $data;
    }
}
