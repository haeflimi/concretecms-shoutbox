<?php

namespace Shoutbox\Install;

use Concrete\Core\Attribute\Category\CategoryService;
use Concrete\Core\Entity\Attribute\Set as AttributeSet;
use Concrete\Core\Entity\Package as PackageEntity;
use Doctrine\ORM\EntityManagerInterface;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Idempotent install steps: the page attribute "has_shoutbox" that switches the floating panel on for a page.
 */
class Installer
{
    public const ATTRIBUTE = 'has_shoutbox';
    public const SET = 'shoutbox';

    /** @var CategoryService */
    protected $categories;

    /** @var EntityManagerInterface */
    protected $em;

    public function __construct(CategoryService $categories, EntityManagerInterface $em)
    {
        $this->categories = $categories;
        $this->em = $em;
    }

    public function install(PackageEntity $pkg): void
    {
        $this->ensurePageAttribute($pkg);
    }

    protected function ensurePageAttribute(PackageEntity $pkg): void
    {
        $controller = $this->categories->getByHandle('collection')->getController();
        $set = $this->em->getRepository(AttributeSet::class)->findOneBy([
            'asHandle' => self::SET,
            'category' => $controller->getCategoryEntity(),
        ]);
        if ($set === null) {
            $set = $controller->getSetManager()->addSet(self::SET, t('Shoutbox'), $pkg);
        }
        $key = $controller->getAttributeKeyByHandle(self::ATTRIBUTE);
        if ($key === null) {
            $key = $controller->add('boolean', [
                'akHandle' => self::ATTRIBUTE,
                'akName' => t('Shoutbox'),
                'asID' => $set->getAttributeSetID(),
            ], null, $pkg);
            $key->setIsAttributeKeySearchable(true);
            $this->em->persist($key);
            $this->em->flush();

            return;
        }
        // an existing key (older version, created by hand): make sure it sits in our set
        foreach ($set->getAttributeKeys() as $existing) {
            if ($existing->getAttributeKeyID() == $key->getAttributeKeyID()) {
                return;
            }
        }
        if (count($key->getAttributeSets()) === 0) {
            $controller->getSetManager()->addKey($set, $key);
        }
    }
}
