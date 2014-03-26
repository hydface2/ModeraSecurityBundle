<?php

namespace Modera\SecurityAwareJSRuntimeBundle\Contributions;

use Modera\SecurityBundle\Model\PermissionCategory;
use Modera\TranslationsBundle\Helper\T;
use Sli\ExpanderBundle\Ext\ContributorInterface;

/**
 * @author    Sergei Lissovski <sergei.lissovski@modera.org>
 * @copyright 2014 Modera Foundation
 */
class PermissionCategoriesProvider implements ContributorInterface
{
    private $items;

    /**
     * @inheritDoc
     */
    public function getItems()
    {
        if (!$this->items) {
            $this->items = [
                new PermissionCategory(
                    T::trans('Site'),
                    'site'
                )
            ];
        }

        return $this->items;
    }
} 