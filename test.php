<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
 require_once $_SERVER['DOCUMENT_ROOT'] . '/SF.php';

use \Bitrix\Highloadblock as HL;
use \Bitrix\Main\Loader;
use \Bitrix\Main\UserTable;

Loader::includeMOdule('highloadblock');
Loader::includeMOdule('iblock');

$exmp = \Bitrix\Main\FileTable::getEntity();
$exmp->addField([
    (new \Bitrix\Main\ORM\Fields\Relations\Reference()->configureJoinType(\Bitrix\Main\ORM\Query\Join::TYPE_LEFT))
]);

$entity = HL\HighloadBlockTable::compileEntity('ProductMarkingCodeGroup');
$query2 = new \Bitrix\Main\ORM\Query\Query($entity);
//dump($query2->addSelect('*')->addFilter('ID', 4)->exec()->fetchAll());

//create entity
$entit = \Bitrix\Main\ORM\Entity::compileEntity(
    'MyEntity',
    [
        (new Bitrix\Main\ORM\Fields\IntegerField('ID'))
        ->configurePrimary(),
        (new Bitrix\Main\ORM\Fields\StringField('LOGIN'))
    ],
    [
        'namespace' => 'MyNamespace',
        'table_name' => 'b_user',
    ]
);

$query = new \Bitrix\Main\ORM\Query\Query($entit);

$rs = $query->setSelect(['ID', 'LOGIN'])->exec();

//dump($rs->fetchAll());


//entity from iblock
$ent = \Bitrix\Iblock\IblockTable::compileEntity('cloth');

$query = new \Bitrix\Main\ORM\Query\Query($ent);

$rs1 = $query->where('ID', '=', 4)->setSelect(['ID', 'NAME', 'BRAND_' => 'BRAND_REF'])->exec();

$arProd = [];

while ($prod = $rs1->fetch()) {
    if (!isset($arProd[$prod['ID']])) {
        $arProd[$prod['ID']] = [
            'PRODUCT_NAME' => $prod['NAME'],
            'BRAND' => [$prod['BRAND_VALUE']],
        ];
    } else {
        $arProd[$prod['ID']]['BRAND'][] = $prod['BRAND_VALUE'];
    }
}

dump($arProd);
