<?php
declare(strict_types=1);
namespace Packvium\Commerce\Catalog;

enum ExclusionScope: string
{
    case ITEM_CARTON = 'item_carton';
    case ITEM_PALLET = 'item_pallet';
}
